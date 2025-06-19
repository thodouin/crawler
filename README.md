<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Documentation de l'API pour les Workers de Crawl

Ce document décrit les endpoints de l'API que les applications "worker" (clients) doivent utiliser pour s'enregistrer, signaler leur état et récupérer des tâches à exécuter. Le système est basé sur un modèle "pull" : le worker demande activement du travail quand il est disponible.

Flux de Vie d'un Worker
Démarrage : Le worker s'enregistre auprès de l'API.
Activité : Le worker envoie des "heartbeats" (signaux de vie) périodiquement pour indiquer qu'il est toujours en ligne.
Recherche de Tâche : Quand le worker est libre, il appelle l'API pour demander une nouvelle tâche.
Exécution : Le worker exécute la tâche reçue.
Mise à jour : Une fois la tâche terminée (succès ou échec), le worker notifie l'API du résultat.
Retour à l'étape 3.

Endpoints de l'API
L'URL de base de l'API est : http://<votre-domaine-worker>/api

1. Enregistrement et Mise à Jour du Worker
Cet endpoint doit être appelé au démarrage du worker. S'il existe déjà, ses informations seront mises à jour. Le worker est automatiquement mis au statut ONLINE_IDLE (En ligne, Libre).
Endpoint : POST /worker/register
Description : Enregistre un nouveau worker ou met à jour un worker existant en se basant sur son worker_identifier.
Données envoyées (JSON)

~~
{
  "worker_identifier": "unique_id_for_this_worker_instance_123",
  "name": "Worker Electron sur Machine-Dev",
  "ws_protocol": "ws",
  "system_info": {
    "platform": "darwin",
    "os_type": "macOS",
    "cpu_cores": 8,
    "total_memory_gb": 16.0
  }
}
~~~

Champ	Type	Obligatoire	Description
worker_identifier	string	Oui	Un identifiant unique et persistant pour cette instance de worker.
name	string	Oui	Un nom lisible pour l'identifier dans l'interface.
ws_protocol	string	Non	Protocole WebSocket (ws ou wss). Défaut: ws.
system_info	object	Non	Informations diverses sur le système où tourne le worker.
Réponse reçue (200/201 OK - JSON)

~~~
{
  "message": "Worker enregistré",
  "laravel_worker_id": 12
}
~~~~

2. Signal de Vie (Heartbeat)
Le worker doit appeler cet endpoint périodiquement (ex: toutes les 30 secondes) pour signaler qu'il est toujours actif.
Endpoint : POST /worker/heartbeat
Description : Met à jour le champ last_heartbeat_at du worker. Si le worker était marqué OFFLINE, il est remis en ONLINE_IDLE.

~~
{
  "worker_identifier": "unique_id_for_this_worker_instance_123",
  "system_info": {
    "free_memory_gb": 8.5
  }
}
~~~

Champ	Type	Obligatoire	Description
worker_identifier	string	Oui	L'identifiant unique du worker.
system_info	object	Non	Permet de mettre à jour les informations système.
Réponse reçue (200 OK - JSON)

~~
{
  "message": "Heartbeat reçu"
}
~~~

3. Récupération d'une Tâche
Quand le worker est libre (ONLINE_IDLE), il doit appeler cet endpoint pour demander du travail. L'API lui renverra la tâche la plus prioritaire qui lui est assignée.
Endpoint : GET /v1/worker/get-task
Description : Endpoint unique et dynamique pour récupérer n'importe quel type de tâche disponible.
Données envoyées (JSON dans le corps de la requête GET)

~~
{
  "worker_identifier": "unique_id_for_this_worker_instance_123"
}
~~~

Réponse reçue (200 OK - JSON)
Si une tâche est trouvée :
~~Generated json~~
{
  "data": [
    {
      "id": 42,
      "url": "http://example.com",
      "priority": "normal",
      "fastapi_job_id": "task_60d5e1c2a3b4c",
      "task_type": "crawl"
    }
  ]
}
~~~

Champ	Type	Description
id	integer	L'ID du site à traiter dans la base de données Laravel. À renvoyer lors de la mise à jour de la tâche.
url	string	L'URL cible de la tâche.
priority	string	La priorité du site (urgent, normal, low).
task_type	string	Le slug de la tâche à exécuter (crawl, check_existence, etc.). Le worker doit l'utiliser pour savoir quelle logique exécuter.
<br>
Si aucune tâche n'est trouvée :
~~Generated json~~
{
  "data": []
}
~~~

4. Mise à Jour du Statut d'une Tâche
Une fois la tâche terminée (succès ou échec), le worker doit notifier l'API du résultat. Cela libère le worker pour une nouvelle tâche et met à jour le statut du site.
Endpoint : POST /worker/task-update
Description : Met à jour le statut d'une tâche terminée.
Données envoyées (JSON)

~~
{
  "worker_identifier": "unique_id_for_this_worker_instance_123",
  "site_id_laravel": 42,
  "task_type": "crawl",
  "crawl_outcome": "completed_successfully",
  "message": "Crawl terminé. 15 pages trouvées.",
  "details": {
    "pages_found": 15,
    "errors_count": 0
  }
}
~~~

Champ	Type	Obligatoire	Description
worker_identifier	string	Oui	L'identifiant unique du worker.
site_id_laravel	integer	Oui	L'ID du site qui a été traité (reçu via get-task).
task_type	string	Oui	Le slug de la tâche qui a été exécutée (crawl ou check_existence).
crawl_outcome	string	Si task_type est crawl	Résultat du crawl. Valeurs possibles: completed_successfully, failed_during_crawl, error_before_start.
existence_result	string	Si task_type est check_existence	Résultat de la vérification. Valeurs possibles: exists, not_found, error.
message	string	Non	Un message lisible résumant le résultat.
details	object	Non	Un objet JSON pour des détails structurés (erreurs, stats, etc.).
Réponse reçue (200 OK - JSON)

~~
{
  "message": "Statut de la tâche mis à jour avec succès"
}
~~~

Cette documentation devrait fournir toutes les informations nécessaires à un développeur pour interagir correctement avec votre backend.