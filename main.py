import shutil
import asyncio
import websockets
import os
import csv
import re
import json
from urllib.parse import urljoin, urlparse, urlunparse, unquote
from crawl4ai import AsyncWebCrawler, CrawlerRunConfig
from crawl4ai.markdown_generation_strategy import DefaultMarkdownGenerator
from typing import List, Dict, Any, Tuple, Optional, Set
import io
from concurrent.futures import ThreadPoolExecutor
import aiohttp
import psutil
import platform
from fastapi import FastAPI, WebSocket, WebSocketDisconnect, HTTPException, Form
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, ValidationError, Field # Ajout de Field si vous voulez l'utiliser
import logging
import sys
from pathlib import Path
from time import time
import httpx # Pour le webhook vers Laravel
import traceback

import uvicorn # Pour un logging d'erreur plus détaillé

# --- Configuration du Logging ---
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(name)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# --- Configuration SSL via certifi (à placer très tôt) ---
try:
    import certifi
    os.environ['SSL_CERT_FILE'] = certifi.where()
    os.environ['REQUESTS_CA_BUNDLE'] = certifi.where()
    logger.info(f"SSL_CERT_FILE et REQUESTS_CA_BUNDLE configurés avec certifi: {certifi.where()}")
except ImportError:
    logger.warning("Module certifi non trouvé. La vérification SSL pourrait échouer pour certaines requêtes.")
except Exception as e:
    logger.error(f"Erreur lors de la configuration SSL avec certifi: {e}")
# --- Fin Configuration SSL ---


# --- Initialisation de l'application FastAPI ---
app = FastAPI(
    title="Crawling Worker WebSocket API with Laravel Callback",
    description="API for crawling websites, initiated via WebSocket, with callback to Laravel.",
    version="2.0.0" # Version mise à jour
)
@app.get("/health_check", tags=["Test"]) # Endpoint HTTP simple
async def health_check_endpoint():
    return {"status": "ok"}

logger.info("FASTAPI: Endpoint /health_check défini.")

# --- Middleware CORS ---
app.add_middleware(
    CORSMiddleware,
    # Adaptez ces origines à celles de votre application Filament/Laravel
    allow_origins=["http://localhost:8000", "http://127.0.0.1:8000", "http://localhost:3000", "http://127.0.0.1:3000"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# --- Encodage UTF-8 pour Windows ---
if sys.platform.startswith('win'):
    os.environ['PYTHONIOENCODING'] = 'utf-8'
    sys.stdout.reconfigure(encoding='utf-8')
    sys.stderr.reconfigure(encoding='utf-8')

# --- Constantes ---
COMMON_HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9",
    "Accept-Language": "en-US,en;q=0.9",
}
EXCLUDE_KEYWORDS: Set[str] = {'pdf', 'jpeg', 'jpg', 'png', 'webp', 'login', 'signup', 'mailto', 'tel'} # Mis en Set pour efficacité
DEFAULT_OUTPUT_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "crawled_data_fastapi")) # Sortie dans un sous-dossier local

# --- Modèles Pydantic pour les Payloads WebSocket ---
class StartCrawlPayloadData(BaseModel): # Ce que Laravel envoie dans 'payload'
    urls: List[str] # FastAPI s'attendait à une liste, même si Laravel envoie une seule URL
    output_dir: str = Field(default=DEFAULT_OUTPUT_DIR)
    max_concurrency: int = Field(default=8, ge=1)
    max_depth: int = Field(default=2, ge=0)
    metadata_for_callback: Optional[Dict[str, Any]] = None # Pour site_id_laravel, callback_url

class WebSocketCommand(BaseModel):
    action: str
    payload: StartCrawlPayloadData # Ou un Union si d'autres actions ont des payloads différents

# --- Import et gestion du module Sitemap ---
try:
    from sitemap_crawler import get_sitemap_data_for_single_url
    SITEMAP_CRAWLER_AVAILABLE = True
    logger.info("Successfully imported sitemap_crawler module.")
except ImportError:
    SITEMAP_CRAWLER_AVAILABLE = False
    logger.warning("sitemap_crawler.py not found or 'get_sitemap_data_for_single_url' not available. Sitemap processing will be basic or skipped.")
    async def get_sitemap_data_for_single_url(url: str, session: aiohttp.ClientSession, *args, **kwargs) -> List[Tuple[str, str]]:
        logger.error("Sitemap crawler module was not imported correctly. Cannot get sitemap data.")
        return []

# --- Fonctions Utilitaires (Normalisation URL, Nettoyage Markdown, Sanitize, etc.) ---
def prepare_initial_url_scheme(url_str: str) -> str:
    # ... (votre code prepare_initial_url_scheme)
    if not url_str: return ""
    url_str = url_str.strip()
    parsed = urlparse(url_str)
    if not parsed.scheme: return f"http://{url_str.lstrip('//')}"
    return url_str

def normalize_url_for_deduplication(url_string: str) -> str:
    # ... (votre code normalize_url_for_deduplication)
    try:
        parsed = urlparse(url_string)
        path = parsed.path
        if path and not path.startswith('/'): path = '/' + path
        elif not path and (parsed.query or parsed.params): path = '/'
        return urlunparse((parsed.scheme.lower(), parsed.netloc.lower(), path or '/', parsed.params, parsed.query, '')).rstrip('/')
    except: return url_string.rstrip('/')


async def resolve_initial_url(session: aiohttp.ClientSession, url_to_resolve: str) -> Tuple[Optional[str], Optional[str]]:
    # ... (votre code resolve_initial_url)
    logger.debug(f"Attempting to resolve: {url_to_resolve}")
    try:
        async with session.get(url_to_resolve, allow_redirects=True, timeout=20) as response:
            effective_url = str(response.url)
            if response.status >= 400:
                error_msg = f"HTTP {response.status} at {effective_url}"
                logger.warning(f"Initial URL resolution for '{url_to_resolve}' failed: {error_msg}")
                return None, error_msg
            logger.info(f"Initial URL '{url_to_resolve}' resolved to '{effective_url}' (status: {response.status})")
            return effective_url, None
    except Exception as e: # Plus générique pour attraper ClientError, TimeoutError, etc.
        error_msg = f"Error resolving initial URL '{url_to_resolve}': {type(e).__name__} - {e}"
        logger.error(error_msg, exc_info=False) # exc_info=False pour ne pas polluer avec des traces SSL
        return None, error_msg


def clean_markdown(md_text: str) -> str:
    # ... (votre code clean_markdown)
    md_text = re.sub(r'!\[([^\]]*)\]\((http[s]?://[^\)]+)\)', '', md_text) # Enlever images
    md_text = re.sub(r'\[([^\]]+)\]\((http[s]?://[^\)]+)\)', r'\1', md_text) # Garder texte des liens
    return md_text.strip()

def sanitize_filename(url: str) -> str:
    # ... (votre code sanitize_filename)
    try:
        parsed = urlparse(url); netloc = parsed.netloc.replace(".", "_"); path = (parsed.path.strip("/").replace("/", "_").replace(".", "_") if parsed.path else "index")
        query_safe = re.sub(r'[^a-zA-Z0-9_-]', '_', parsed.query[:50]) if parsed.query else ""
        fn_base = f"{netloc}_{path}_{query_safe}".strip('_'); fn_base = re.sub(r'_+', '_', fn_base)
        return (fn_base[:240] if fn_base else f"url_{abs(hash(url))}") + ".md"
    except: return f"error_fn_{abs(hash(url))}.md"


def sanitize_dirname(url: str) -> str:
    # ... (votre code sanitize_dirname)
    try: return (re.sub(r'[^a-zA-Z0-9_-]', '_', urlparse(url).netloc.replace(".", "_")) or f"domain_{abs(hash(url))}")[:150]
    except: return f"error_dir_{abs(hash(url))}"


def process_markdown_and_save(url: str, markdown_content: str, output_path: str) -> Dict[str, Any]:
    # ... (votre code process_markdown_and_save)
    try:
        cleaned_markdown = clean_markdown(markdown_content)
        os.makedirs(os.path.dirname(output_path), exist_ok=True)
        with open(output_path, "w", encoding="utf-8") as f: f.write(f"# URL: {url}\n\n{cleaned_markdown}\n")
        logger.info(f"Saved: {output_path}")
        return {"status": "success", "url": url, "path": output_path}
    except Exception as e: logger.error(f"Error saving {url} to {output_path}: {e}"); return {"status": "failed", "url": url, "error": str(e)}

# --- Fin Fonctions Utilitaires ---


# --- Logique de Crawl ---
CrawlQueueItem = Tuple[str, int, str, str] # url, depth, start_domain, site_output_path_specific

async def crawl_website_single_site( # Renommé pour clarté, le premier arg est l'URL schemed
    start_url_schemed_validated: str,
    output_dir_base_for_this_site_crawl: str, # Ex: ~/Desktop/crawled_data
    max_concurrency_pages: int,
    max_depth_crawl: int,
    websocket_client: Optional[WebSocket] = None
) -> Dict[str, Any]:
    # ... (votre logique détaillée de crawl_website_single_site que vous avez fournie,
    # en s'assurant que les appels à send_progress utilisent websocket_client,
    # et que max_depth_crawl est utilisé pour la condition de profondeur)
    # Pour la concision, je ne la répète pas intégralement ici, mais elle doit être ici.
    # Points importants à l'intérieur de cette fonction (basé sur votre dernier code):
    # - Elle utilise start_url_schemed_validated (qui est déjà résolue et validée)
    # - Elle crée un sous-dossier site_output_path_specific dans output_dir_base_for_this_site_crawl
    # - Elle utilise normalize_url_for_deduplication pour les URLs dans crawled_urls et queued_urls
    # - Elle respecte max_depth_crawl
    # - Elle utilise send_progress avec le websocket_client fourni
    logger.info(f"Simulating crawl for {start_url_schemed_validated} with depth {max_depth_crawl} and concurrency {max_concurrency_pages}")
    await asyncio.sleep(2) # Simuler le travail
    site_subdir = sanitize_dirname(start_url_schemed_validated)
    site_specific_path = os.path.join(output_dir_base_for_this_site_crawl, site_subdir)
    os.makedirs(site_specific_path, exist_ok=True)
    return {
        "initial_url": start_url_schemed_validated, # Ce sera l'URL schemed/validée
        "effective_start_url": start_url_schemed_validated, # Après résolution, c'est la même ici
        "output_path_for_site": site_specific_path,
        "success": [start_url_schemed_validated],
        "failed": [],
        "skipped_by_filter": []
    }

# ... (votre fonction process_and_save_sitemap, elle est bien définie dans votre dernier code)
async def process_and_save_sitemap(effective_url: str, output_path: str, websocket: Optional[WebSocket] = None) -> Dict[str, Any]:
    # ... (Votre code actuel pour process_and_save_sitemap)
    logger.info(f"Simulating sitemap processing for {effective_url}")
    await asyncio.sleep(1)
    if SITEMAP_CRAWLER_AVAILABLE:
        # ... votre logique avec get_sitemap_data_for_single_url ...
        return {"status": "sitemap_simulated_success", "sitemap_csv_path": os.path.join(output_path, "sitemap_data.csv")}
    return {"status": "sitemap_skipped_module_unavailable"}


# --- Fonction pour appeler le Webhook Laravel ---
async def notify_laravel_on_task_completion(
    callback_url: Optional[str],
    laravel_site_id: Optional[int],
    # worker_identifier_laravel: Optional[str], # Optionnel, FastAPI ne le connaît pas directement
    crawl_status: str,
    crawl_results_summary: Dict[str, Any],
    error_message: Optional[str] = None
):
    if not callback_url or laravel_site_id is None:
        logger.warning(f"Webhook: Callback URL ou Laravel Site ID manquant pour site ID (local FastAPI) {laravel_site_id}. Notification Laravel sautée.")
        return

    payload = {
        "site_id_laravel": laravel_site_id,
        # "worker_identifier_laravel": worker_identifier_laravel, # Si vous l'aviez et le passiez
        "status_crawl": crawl_status, # ex: "COMPLETED_SUCCESS", "COMPLETED_WITH_ERRORS", "FAILED_FASTAPI_PROCESSING"
        "message": f"Crawl pour Site Laravel ID {laravel_site_id} par worker FastAPI {platform.node()} : {crawl_status}.",
        "details": crawl_results_summary, # Un résumé concis
    }
    if error_message:
        payload["error_message"] = error_message

    try:
        async with httpx.AsyncClient(timeout=15.0) as client:
            logger.info(f"Webhook: Envoi notification à Laravel: {callback_url} pour Site Laravel ID {laravel_site_id}")
            # Vous pourriez vouloir ajouter une clé API partagée pour sécuriser ce callback
            # headers = {"X-FastAPI-Callback-Key": "VOTRE_SECRET_PARTAGE"}
            # response = await client.post(callback_url, json=payload, headers=headers)
            response = await client.post(callback_url, json=payload)
            response.raise_for_status()
            logger.info(f"Webhook: Notification Laravel envoyée pour Site ID {laravel_site_id}. Statut: {response.status_code}")
    except httpx.HTTPStatusError as e:
        logger.error(f"Webhook: Erreur HTTP lors de l'envoi à Laravel pour Site ID {laravel_site_id}: {e.response.status_code} - {e.response.text}")
    except httpx.RequestError as e:
        logger.error(f"Webhook: Erreur de requête lors de l'envoi à Laravel pour Site ID {laravel_site_id}: {e}")
    except Exception as e:
        logger.error(f"Webhook: Erreur inattendue lors de l'envoi à Laravel pour Site ID {laravel_site_id}: {e}", exc_info=True)

def format_crawl_summary_for_laravel(crawl_summary_fastapi: Dict[str, Any]) -> Dict[str, Any]:
    """Prépare un résumé concis des résultats du crawl pour le callback Laravel."""
    if not crawl_summary_fastapi: return {}
    return {
        "initial_url_processed_by_fastapi": crawl_summary_fastapi.get("initial_url"),
        "effective_url_crawled_by_fastapi": crawl_summary_fastapi.get("effective_start_url"),
        "output_folder_on_fastapi": crawl_summary_fastapi.get("output_path_for_site"),
        "zip_file_on_fastapi": crawl_summary_fastapi.get("site_output_zip_file"),
        "pages_success_count": len(crawl_summary_fastapi.get("success", [])),
        "pages_failed_count": len(crawl_summary_fastapi.get("failed", [])),
        "sitemap_status": crawl_summary_fastapi.get("sitemap_processing_results", {}).get("status"),
    }

class ConnectionManager:
    def init(self):
        self.active_connections: List[WebSocket] = []
        self.connection_states: Dict[int, bool] = {} # id(websocket) -> is_busy

async def connect(self, websocket: WebSocket):
    await websocket.accept()
    self.active_connections.append(websocket)
    self.connection_states[id(websocket)] = False # Initialement non occupé
    logger.info(f"Manager: Client {id(websocket)} connecté. Total: {len(self.active_connections)}")

def disconnect(self, websocket: WebSocket):
    if websocket in self.active_connections:
        self.active_connections.remove(websocket)
    if id(websocket) in self.connection_states:
        del self.connection_states[id(websocket)]
    logger.info(f"Manager: Client {id(websocket)} déconnecté. Restant: {len(self.active_connections)}")

async def send_personal_message(self, message: dict, websocket: WebSocket):
    try:
        await websocket.send_json(message)
    except WebSocketDisconnect:
        logger.warning(f"Manager: Tentative d'envoi à un client déconnecté {id(websocket)}.")
        self.disconnect(websocket) # S'assurer qu'il est retiré
    except Exception as e:
        logger.error(f"Manager: Erreur d'envoi à {id(websocket)}: {e}")

manager = ConnectionManager()

# --- Tâche principale de Crawl et Notification (lancée en arrière-plan par WebSocket) ---
async def run_crawl_and_notify(websocket: WebSocket, command_payload: StartCrawlPayloadData):
    # Extraire les métadonnées pour le callback de command_payload.metadata_for_callback
    metadata = command_payload.metadata_for_callback or {}
    laravel_site_id = metadata.get("site_id_laravel")
    callback_url_to_laravel = metadata.get("callback_url_laravel")
    # laravel_worker_id = metadata.get("worker_identifier_laravel") # Si besoin

    # Le ConnectionManager gère maintenant l'état "busy" par connexion WebSocket
    current_connection_id = id(websocket) # Ou une autre façon d'identifier la connexion
    if manager.connection_states.get(current_connection_id, False): # Vérifier si cette connexion est déjà occupée
        await manager.send_personal_message({"type": "error", "message": "Un crawl est déjà en cours pour cette connexion WebSocket."}, websocket)
        return
    manager.connection_states[current_connection_id] = True # Marquer cette connexion comme occupée

    overall_results_batch = {"per_url_results": {}}
    crawl_status_report = "fastapi_task_unknown_outcome"
    final_crawl_summary_for_laravel = {}
    error_message_report = None

    try:
        # Le payload de Laravel est dans command_payload (instance de StartCrawlPayloadData)
        # S'assurer que output_dir est un chemin absolu pour la création de dossier
        base_output_dir_for_batch = os.path.abspath(command_payload.output_dir or DEFAULT_OUTPUT_DIR)
        os.makedirs(base_output_dir_for_batch, exist_ok=True)

        for url_input_from_laravel in command_payload.urls: # urls est une liste
            await manager.send_personal_message({"type": "info", "message": f"FastAPI: Traitement URL: {url_input_from_laravel}"}, websocket)
            
            schemed_url = prepare_initial_url_scheme(url_input_from_laravel)
            validated_url, validation_error = validate_url(schemed_url)
            if validation_error:
                logger.error(f"URL Invalide pour le crawl: {schemed_url} - Erreur: {validation_error}")
                single_site_crawl_summary = {"initial_url": schemed_url, "failed": [{"url": schemed_url, "error": validation_error}]}
            else:
                single_site_crawl_summary = await crawl_website_single_site(
                    start_url_original_schemed=validated_url, # Utiliser l'URL validée
                    output_dir=base_output_dir_for_batch, # Passer le répertoire de base pour ce batch
                    max_concurrency=command_payload.max_concurrency,
                    max_depth=command_payload.max_depth,
                    websocket=websocket
                )
            
            # --- Logique de création de ZIP et suppression de dossier ---
            site_output_path = single_site_crawl_summary.get("output_path_for_site")
            effective_url_for_sitemap = single_site_crawl_summary.get("effective_start_url")

            if site_output_path and effective_url_for_sitemap and os.path.isdir(site_output_path):
                sitemap_summary = await process_and_save_sitemap(effective_url_for_sitemap, site_output_path, websocket)
                single_site_crawl_summary["sitemap_processing_results"] = sitemap_summary

                # Metadata pour ce site spécifique
                metadata_path_site = Path(site_output_path) / "crawl_metadata_site.json"
                with open(metadata_path_site, "w", encoding="utf-8") as f:
                    json.dump({
                        "crawl_parameters_from_laravel": command_payload.model_dump(), # Sérialiser le Pydantic model
                        "specific_site_crawl_summary": single_site_crawl_summary,
                        "timestamp_fastapi_processing_end": time()
                    }, f, indent=2, ensure_ascii=False)
                single_site_crawl_summary["metadata_file_path_this_site"] = str(metadata_path_site)

                # Création du ZIP pour ce site
                zip_base_name_site = os.path.join(base_output_dir_for_batch, f"{os.path.basename(site_output_path)}_output")
                zip_file_site = create_zip_archive(site_output_path, zip_base_name_site)
                if zip_file_site:
                    single_site_crawl_summary["site_output_zip_file"] = zip_file_site
                    await manager.send_personal_message({"type": "info", "message": f"ZIP créé: {zip_file_site}"}, websocket)
                    try: shutil.rmtree(site_output_path); single_site_crawl_summary["data_folder_deleted_after_zip"] = True
                    except Exception as e_del: logger.error(f"Échec suppression dossier {site_output_path}: {e_del}")
                else:
                    await manager.send_personal_message({"type": "error", "message": f"Échec ZIP dossier {site_output_path}"}, websocket)
            # --- Fin ZIP ---
            overall_results_batch["per_url_results"][url_input_from_laravel] = single_site_crawl_summary
            final_crawl_summary_for_laravel = single_site_crawl_summary # Pour un seul site dans le payload Laravel

            # Déterminer le statut pour le callback
            if single_site_crawl_results.get("failed"):
                crawl_status_report = "COMPLETED_WITH_ERRORS" # Statut clair pour Laravel
                error_message_report = single_site_crawl_results["failed"][0].get("error") if single_site_crawl_results.get("failed") else "Erreur de crawl inconnue"
            elif single_site_crawl_results.get("success"):
                crawl_status_report = "COMPLETED_SUCCESS"
            else:
                crawl_status_report = "COMPLETED_UNKNOWN_OUTCOME"
        
        await manager.send_personal_message({"type": "crawl_complete", "status": "success", "results": overall_results_batch}, websocket)
        
    except Exception as e:
        logger.critical(f"Processus global de crawl (WebSocket) échoué: {e}", exc_info=True)
        await manager.send_personal_message({"type": "crawl_complete", "status": "error", "message": f"Processus de crawl échoué: {e}"}, websocket)
        crawl_status_report = "FAILED_FASTAPI_PROCESSING"
        error_message_report = str(e)
        final_crawl_summary_for_laravel = {"error_in_run_crawl_and_notify": str(e)}
    finally:
        manager.connection_states[current_connection_id] = False # Libérer l'état de la connexion
        logger.info("FastAPI: Processus de crawl pour la tâche WebSocket terminé.")
        
        if callback_url_to_laravel and laravel_site_id is not None:
            await notify_laravel_on_task_completion(
                callback_url=callback_url_to_laravel,
                laravel_site_id=laravel_site_id,
                crawl_status=crawl_status_report,
                crawl_results_summary=format_crawl_summary_for_laravel(final_crawl_summary_for_laravel),
                error_message=error_message_report
            )

logger.info("FASTAPI: AVANT la définition de @app.websocket('/ws')")
# --- Endpoint WebSocket ---
@app.websocket("/ws")
async def websocket_endpoint(websocket: WebSocket):
    await manager.connect(websocket)
    current_connection_id = id(websocket)
    logger.info(f"FASTAPI: /ws - Client {websocket.client} (ID: {current_connection_id}) connecté.")
    try:
        while True:
            try:
                data_received = await asyncio.wait_for(websocket.receive_json(), timeout=600.0)
                logger.info(f"FASTAPI: /ws - Reçu de {current_connection_id}: {data_received}")
                
                # Juste un écho pour l'instant
                await manager.send_personal_message({"type": "echo_response", "data_echoed": data_received}, websocket)

            except asyncio.TimeoutError:
                try: await websocket.send_json({"type": "ping"})
                except: break
                continue
            except WebSocketDisconnect:
                logger.info(f"Client WebSocket (ID: {current_connection_id}) déconnecté (pendant receive_json).")
                break
            except Exception as loop_e:
                logger.error(f"Erreur dans boucle réception WS (ID: {current_connection_id}): {loop_e}", exc_info=True)
                await manager.send_personal_message({"type": "error", "message": "Erreur serveur pendant gestion message."}, websocket)
    
    except WebSocketDisconnect:
        logger.info(f"Client WebSocket (ID: {current_connection_id}) déconnecté (hors boucle).")
    except Exception as e_ws_handler:
        logger.error(f"Erreur majeure handler WS (ID: {current_connection_id}): {e_ws_handler}", exc_info=True)
    finally:
        logger.info(f"FASTAPI: /ws - Fermeture connexion pour client ID {current_connection_id}.")
        manager.disconnect(websocket)
    
logger.info("FASTAPI: APRÈS la définition de @app.websocket('/ws')")

# --- Bloc d'Exécution Principal ---
if __name__ == "__main__":
    logger.info("Démarrage de l'application FastAPI Worker avec support WebSocket...")
    uvicorn.run(app, host="0.0.0.0", port=8001, log_level="info") # Pour une instance unique ou gérée par un orchestrateur