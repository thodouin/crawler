from fastapi import FastAPI, HTTPException, UploadFile, File, Form
from pydantic import BaseModel, Field # Field is used, BaseModel can be kept for future use
import asyncio
import os
import json
import re
from urllib.parse import urljoin, urlparse
from crawl4ai import AsyncWebCrawler, CrawlerRunConfig
from crawl4ai.markdown_generation_strategy import DefaultMarkdownGenerator
from typing import List, Dict, Any, Tuple
import csv
import io
from concurrent.futures import ThreadPoolExecutor
import uvicorn
import traceback # For detailed error logging

# FastAPI app initialization
app = FastAPI(
    title="Web Crawler Service",
    description="API for crawling websites and saving content as Markdown.",
    version="1.0.0"
)

# Define the exclusion keywords for filenames (case-insensitive check will be used)
EXCLUDE_KEYWORDS = ['pdf', 'jpeg', 'jpg', 'png', 'webp']

# Removed CrawlCSVRequest as it's not directly used for endpoint definition
# but parameters are taken from Form directly. Kept original parameters.

def clean_markdown(md_text: str) -> str:
    """
    Cleans Markdown content by removing or modifying specific elements.
    """
    md_text = re.sub(r'!\[([^\]]*)\]\((http[s]?://[^\)]+)\)', '', md_text)
    md_text = re.sub(r'\[([^\]]+)\]\((http[s]?://[^\)]+)\)', r'\1', md_text)
    md_text = re.sub(r'(?<!\]\()https?://\S+', '', md_text)
    md_text = re.sub(r'\[\^?\d+\]', '', md_text)
    md_text = re.sub(r'^\[\^?\d+\]:\s?.*$', '', md_text, flags=re.MULTILINE)
    md_text = re.sub(r'^\s{0,3}>\s?', '', md_text, flags=re.MULTILINE)
    md_text = re.sub(r'(\*\*|__)(.*?)\1', r'\2', md_text)
    md_text = re.sub(r'(\*|_)(.*?)\1', r'\2', md_text)
    md_text = re.sub(r'^\s*#+\s*$', '', md_text, flags=re.MULTILINE)
    md_text = re.sub(r'\(\)', '', md_text)
    md_text = re.sub(r'\n\s*\n+', '\n\n', md_text)
    md_text = re.sub(r'[ \t]+', ' ', md_text)
    return md_text.strip()

def sanitize_filename(url: str) -> str:
    """Sanitizes a URL to create a safe and shorter filename."""
    try:
        parsed = urlparse(url)
        netloc = parsed.netloc.replace(".", "_")
        path = parsed.path.strip("/").replace("/", "_").replace(".", "_")
        if not path:
            path = "index"

        query = parsed.query
        if query:
            query = query[:50] # Limit query part length for filename
            query = query.replace("=", "-").replace("&", "_")
            filename = f"{netloc}_{path}_{query}"
        else:
            filename = f"{netloc}_{path}"

        filename = re.sub(r'[<>:"/\\|?*]', '_', filename)
        filename = re.sub(r'[\s\._-]+', '_', filename)
        filename = re.sub(r'^_+', '', filename)
        filename = re.sub(r'_+$', '', filename)

        if not filename:
            filename = f"url_{abs(hash(url))}"

        # Ensure filename (without extension) doesn't exceed a reasonable limit
        # OS limits are usually around 255 CHARACTERS for a filename component.
        # 150 is a very safe limit.
        max_len_without_suffix = 240 - 3 # Leave space for ".md" and some buffer
        filename = filename[:max_len_without_suffix] + ".md"
        return filename
    except Exception as e:
        print(f"Error sanitizing URL filename for {url}: {e}")
        return f"error_parsing_{abs(hash(url))}.md"

def sanitize_dirname(url: str) -> str:
    """Sanitizes a URL's domain to create a safe directory name."""
    try:
        parsed = urlparse(url)
        dirname = parsed.netloc.replace(".", "_")
        dirname = re.sub(r'[<>:"/\\|?*]', '_', dirname)
        dirname = re.sub(r'[\s\._-]+', '_', dirname)
        dirname = re.sub(r'^_+', '', dirname)
        dirname = re.sub(r'_+$', '', dirname)

        if not dirname:
            dirname = f"domain_{abs(hash(url))}"
        # Limit dirname length to avoid OS path length issues
        return dirname[:150]
    except Exception as e:
        print(f"Error sanitizing URL directory name for {url}: {e}")
        return f"domain_error_{abs(hash(url))}"

CrawlQueueItem = Tuple[str, int, str, str] # url, depth, start_domain, site_output_path

def process_markdown_and_save(url: str, markdown_content: str, output_path: str) -> Dict[str, Any]:
    """Process Markdown content and save it to a file (executed in a thread)."""
    try:
        cleaned_markdown = clean_markdown(markdown_content)
        # Ensure directory exists (should be pre-created by crawl_website_single_site)
        # but a check doesn't hurt if this function were to be used elsewhere.
        os.makedirs(os.path.dirname(output_path), exist_ok=True)

        if not os.access(os.path.dirname(output_path), os.W_OK):
            raise OSError(f"No write permission for directory: {os.path.dirname(output_path)}")
        with open(output_path, "w", encoding="utf-8") as f:
            f.write(f"# Original URL: {url}\n\n{cleaned_markdown}\n") # Added original URL as a comment
        if os.path.exists(output_path):
            print(f"Saved cleaned Markdown to: {output_path}")
            return {"status": "success", "url": url, "path": output_path}
        else:
            raise IOError(f"File was not created: {output_path}")
    except Exception as e:
        print(f"Error processing/saving {url}: {e}")
        return {"status": "failed", "url": url, "error": str(e)}

async def crawl_website_single_site(
    start_url: str,
    output_dir: str, # This is the base output dir like "./crawl_output_csv"
    max_concurrency: int,
    max_depth: int
) -> Dict[str, Any]:
    """
    Crawl a single website deeply and save each page as a cleaned Markdown file
    in a site-specific subdirectory, with parallelization.
    Returns a dictionary with crawl results including the site-specific output path.
    """
    site_output_path_specific: str | None = None # Path for this specific site's content
    results: Dict[str, Any] = {
        "success": [],  # List of successfully processed URL strings
        "failed": [],   # List of dicts: {"url": str, "error": str}
        "skipped_by_filter": [], # List of URLs skipped by filename filter
        "initial_url": start_url,
        "output_path_for_site": None # Will hold the absolute path to the site's output dir
    }

    try:
        parsed_start_url = urlparse(start_url)
        start_domain = parsed_start_url.netloc
        if not start_domain:
            error_msg = f"Could not extract domain from start URL: {start_url}"
            results["failed"].append({"url": start_url, "error": error_msg})
            print(f"Error: {error_msg}")
            return results # output_path_for_site remains None

        site_subdir_name = sanitize_dirname(start_url)
        # site_output_path_specific is the directory for THIS site's crawl content
        site_output_path_specific = os.path.join(output_dir, site_subdir_name)
        site_output_path_specific = os.path.abspath(site_output_path_specific)
        results["output_path_for_site"] = site_output_path_specific # Set it once determined

        print(f"Crawl target domain: {start_domain}")
        print(f"Saving files for this site in: {site_output_path_specific}")

        try:
            # os.makedirs will create parent directories if they don't exist (like output_dir itself)
            os.makedirs(site_output_path_specific, exist_ok=True)
            if not os.path.isdir(site_output_path_specific): # More robust check
                raise OSError(f"Failed to create or access directory: {site_output_path_specific}")
        except Exception as e:
            error_msg = f"Cannot create/access output directory '{site_output_path_specific}': {e}"
            results["failed"].append({"url": start_url, "error": error_msg})
            print(f"Error: {error_msg}")
            return results # output_path_for_site is set to the attempted path

    except Exception as e:
        error_msg = f"Error parsing start URL or determining output path: {e}"
        results["failed"].append({"url": start_url, "error": error_msg})
        print(f"Error processing start URL {start_url}: {error_msg}")
        return results

    crawled_urls = set()
    queued_urls = set()
    crawl_queue: asyncio.Queue[CrawlQueueItem] = asyncio.Queue()
    semaphore = asyncio.Semaphore(max_concurrency)

    # Add start_url to queue with its designated site_output_path_specific
    crawl_queue.put_nowait((start_url, 0, start_domain, site_output_path_specific))
    queued_urls.add(start_url)

    print(f"Starting crawl for: {start_url} with max_depth={max_depth}, max_concurrency={max_concurrency}")

    md_generator = DefaultMarkdownGenerator(
        options={"ignore_links": True, "escape_html": True, "body_width": 0}
    )
    config = CrawlerRunConfig(
        markdown_generator=md_generator,
        cache_mode="BYPASS",
        exclude_social_media_links=True,
    )

    with ThreadPoolExecutor(max_workers=max_concurrency * 2) as executor: # More workers for I/O bound tasks
        async def crawl_page_worker():
            while not crawl_queue.empty():
                try:
                    current_url, current_depth, crawl_start_domain, current_site_specific_output_path = await crawl_queue.get()

                    if current_url in crawled_urls:
                        crawl_queue.task_done()
                        continue

                    try:
                        current_parsed_url = urlparse(current_url)
                        current_domain = current_parsed_url.netloc
                        # Ensure scheme is http or https, otherwise skip (e.g. mailto:, tel:)
                        if current_parsed_url.scheme not in ('http', 'https'):
                            print(f"Skipping non-HTTP/HTTPS URL: {current_url}")
                            crawled_urls.add(current_url) # Mark as processed to avoid re-queueing
                            crawl_queue.task_done()
                            continue
                        if current_domain != crawl_start_domain:
                            print(f"Skipping external URL: {current_url} (Domain: {current_domain}, Expected: {crawl_start_domain})")
                            crawled_urls.add(current_url)
                            crawl_queue.task_done()
                            continue
                    except Exception as e:
                        print(f"Error parsing domain for URL {current_url}: {e}. Skipping.")
                        results["failed"].append({"url": current_url, "error": f"URL parsing error: {e}"})
                        crawled_urls.add(current_url)
                        crawl_queue.task_done()
                        continue

                    crawled_urls.add(current_url)
                    print(f"Crawling ({len(crawled_urls)}): {current_url} (Depth: {current_depth})")

                    filename = sanitize_filename(current_url)
                    output_path = os.path.join(current_site_specific_output_path, filename)

                    # Check EXCLUDE_KEYWORDS against the original URL path as well, more robustly
                    original_path_lower = current_parsed_url.path.lower()
                    if any(keyword in filename.lower() or f".{keyword}" in original_path_lower for keyword in EXCLUDE_KEYWORDS):
                        print(f"Skipping save for {current_url} due to filename/URL path filter: {filename}")
                        results["skipped_by_filter"].append(current_url)
                        # Still try to find links if not max depth, even if page content is skipped
                        if current_depth < max_depth:
                            async with semaphore: # Use semaphore for crawler.arun
                                async with AsyncWebCrawler(verbose=False) as crawler:
                                    # Only fetch links, not full content if skipping save (can be optimized in crawl4ai if supported)
                                    page_data = await crawler.arun(url=current_url, config=config)
                            if page_data.success and page_data.links:
                                internal_links = page_data.links.get("internal", [])
                                for link_info in internal_links:
                                    href = link_info.get("href")
                                    if not href: continue
                                    try:
                                        absolute_url = urljoin(current_url, href)
                                        parsed_absolute_url = urlparse(absolute_url)
                                        if parsed_absolute_url.scheme in ('http', 'https') and parsed_absolute_url.netloc == crawl_start_domain:
                                            if absolute_url not in crawled_urls and absolute_url not in queued_urls:
                                                crawl_queue.put_nowait((absolute_url, current_depth + 1, crawl_start_domain, current_site_specific_output_path))
                                                queued_urls.add(absolute_url)
                                    except Exception as link_e:
                                        print(f"Error processing link '{href}' from {current_url}: {link_e}")
                            elif not page_data.success:
                                print(f"Link extraction failed for {current_url} (skipped save): {page_data.error_message}")
                        crawl_queue.task_done()
                        continue

                    async with semaphore: # Use semaphore for crawler.arun
                        async with AsyncWebCrawler(verbose=False) as crawler:
                            page_data = await crawler.arun(url=current_url, config=config)

                    if page_data.success and page_data.markdown:
                        loop = asyncio.get_running_loop()
                        process_result = await loop.run_in_executor(
                            executor,
                            process_markdown_and_save,
                            current_url,
                            page_data.markdown.raw_markdown,
                            output_path
                        )
                        if process_result["status"] == "success":
                            results["success"].append(current_url) # Store URL for success count
                        else:
                            results["failed"].append({"url": current_url, "error": process_result["error"]})

                        if current_depth < max_depth and page_data.links:
                            internal_links = page_data.links.get("internal", [])
                            for link_info in internal_links:
                                href = link_info.get("href")
                                if not href: continue
                                try:
                                    absolute_url = urljoin(current_url, href)
                                    parsed_absolute_url = urlparse(absolute_url)
                                    if parsed_absolute_url.scheme in ('http', 'https') and parsed_absolute_url.netloc == crawl_start_domain:
                                        if absolute_url not in crawled_urls and absolute_url not in queued_urls:
                                            crawl_queue.put_nowait((absolute_url, current_depth + 1, crawl_start_domain, current_site_specific_output_path))
                                            queued_urls.add(absolute_url)
                                except Exception as link_e:
                                    print(f"Error processing link '{href}' from {current_url}: {link_e}")
                    elif not page_data.success:
                        print(f"Failed to crawl {current_url}: {page_data.error_message} (Status: {page_data.status_code})")
                        results["failed"].append({
                            "url": current_url,
                            "error": page_data.error_message or "Unknown crawl error",
                            "status_code": page_data.status_code
                        })
                    elif not page_data.markdown:
                        print(f"Crawled {current_url} successfully (Status: {page_data.status_code}) but no Markdown content was generated.")
                        results["failed"].append({
                            "url": current_url,
                            "error": "No Markdown content generated",
                            "status_code": page_data.status_code
                        })


                    crawl_queue.task_done()
                except asyncio.CancelledError:
                    print("Crawl page worker cancelled.")
                    break # Exit loop if cancelled
                except Exception as e:
                    # Log error for the current_url if available, otherwise general worker error
                    url_in_error = "unknown URL"
                    try: url_in_error = current_url # current_url might not be defined if error is early
                    except NameError: pass
                    print(f"Error in crawl_page_worker for {url_in_error}: {e}")
                    traceback.print_exc()
                    if url_in_error != "unknown URL":
                         results["failed"].append({"url": url_in_error, "error": f"Worker exception: {e}"})
                    if not crawl_queue.empty(): # Ensure task_done is called if an error occurs after get()
                        crawl_queue.task_done()


        worker_tasks = [asyncio.create_task(crawl_page_worker()) for _ in range(max_concurrency)]
        await crawl_queue.join() # Wait for queue to be empty

        for task in worker_tasks: # Cancel any running workers
            task.cancel()
        await asyncio.gather(*worker_tasks, return_exceptions=True) # Wait for cancellations

    print(f"Finished crawl processing for: {start_url}")
    return results


@app.post("/crawl_single_url/", summary="Crawl a single URL")
async def crawl_single_url_endpoint(
    url: str = Form(..., description="The single URL to crawl (must start with http:// or https://)."),
    output_dir: str = Form("./crawl_output_single", description="Base directory for output. A subdirectory will be created for the site."),
    max_concurrency: int = Form(default=8, ge=1, description="Maximum concurrent requests for this site."),
    max_depth: int = Form(default=2, ge=0, description="Maximum depth to crawl from the starting URL.")
):
    """
    Crawls a single website based on the provided URL and parameters.
    Saves cleaned Markdown content for each crawled page.
    Returns a detailed JSON response about the crawl operation.
    """
    if not (url.startswith("http://") or url.startswith("https://")):
        raise HTTPException(status_code=400, detail="Invalid URL. Must start with http:// or https://.")

    try:
        abs_base_output_dir = os.path.abspath(output_dir)
        # The actual site-specific directory (abs_base_output_dir/sanitized_domain/)
        # will be created by crawl_website_single_site.
        # os.makedirs(abs_base_output_dir, exist_ok=True) is implicitly handled by
        # os.makedirs(site_output_path_specific, exist_ok=True) in crawl_website_single_site
        # if site_output_path_specific is a child of abs_base_output_dir.

        print(f"--- Starting single URL crawl for: {url} ---")
        site_results = await crawl_website_single_site(
            start_url=url,
            output_dir=abs_base_output_dir, # Pass the base output directory
            max_concurrency=max_concurrency,
            max_depth=max_depth
        )

        # Prepare the detailed response structure
        crawl_summary = {
            "initial_url": site_results.get("initial_url", url),
            "output_folder": site_results.get("output_path_for_site"),
            "total_pages_crawled_successfully": len(site_results.get("success", [])),
            "total_pages_failed": len(site_results.get("failed", [])),
            "failed_pages_details": site_results.get("failed", []),
            "total_pages_skipped_by_filter": len(site_results.get("skipped_by_filter", []))
        }

        status_message = "Crawl process initiated and results gathered."
        initial_url_failed_entry = next((item for item in crawl_summary["failed_pages_details"] if item.get("url") == url), None)

        if initial_url_failed_entry:
            if "Cannot create/access output directory" in initial_url_failed_entry.get("error", "") or \
               "Could not extract domain" in initial_url_failed_entry.get("error", "") or \
               "Error parsing start URL" in initial_url_failed_entry.get("error", ""):
                status_message = f"Critical setup error for URL: {initial_url_failed_entry.get('error')}"
            elif crawl_summary["total_pages_crawled_successfully"] == 0:
                 status_message = "Crawl attempted, but no pages were successfully processed. Check failed_pages_details."
        elif crawl_summary["total_pages_crawled_successfully"] > 0:
            status_message = "Crawl completed. Some pages may have failed, check details."
        elif crawl_summary["total_pages_failed"] == 0 and crawl_summary["total_pages_skipped_by_filter"] > 0 and crawl_summary["total_pages_crawled_successfully"] == 0 :
             status_message = "Crawl finished; all discovered pages were skipped by filter."
        elif crawl_summary["total_pages_crawled_successfully"] == 0 and crawl_summary["total_pages_failed"] == 0 and crawl_summary["total_pages_skipped_by_filter"] == 0 :
            status_message = "Crawl finished; no pages were processed (e.g., max_depth 0 on initial URL, or no links found)."


        # Save metadata for this single crawl if the output folder was successfully determined/created
        if crawl_summary["output_folder"] and os.path.isdir(crawl_summary["output_folder"]):
            metadata_path = os.path.join(crawl_summary["output_folder"], "crawl_metadata.json")
            try:
                with open(metadata_path, "w", encoding="utf-8") as f:
                    json.dump({
                        "crawl_parameters": {
                            "requested_url": url,
                            "base_output_directory_target": abs_base_output_dir,
                            "max_concurrency": max_concurrency,
                            "max_depth": max_depth
                        },
                        "crawl_summary": crawl_summary
                    }, f, indent=2)
                crawl_summary["metadata_file_path"] = metadata_path # Add path to summary
                print(f"Metadata for this single URL crawl saved to: {metadata_path}")
            except Exception as e:
                print(f"Error saving metadata for single URL crawl {url}: {e}")
                crawl_summary["metadata_save_error"] = str(e)
        elif crawl_summary["output_folder"]:
             print(f"Skipping metadata save for {url} as output folder '{crawl_summary['output_folder']}' does not exist or is not a directory.")
             crawl_summary["metadata_save_error"] = f"Output folder '{crawl_summary['output_folder']}' not created/accessible."


        print(f"--- Finished single URL crawl for: {url} ---")
        return {
            "request_status": "completed", # Indicates the API request itself was handled
            "message": status_message,
            "details": crawl_summary
        }

    except HTTPException: # Re-raise HTTPExceptions
        raise
    except Exception as e:
        print(f"Critical error in crawl_single_url_endpoint for {url}: {e}")
        traceback.print_exc()
        raise HTTPException(status_code=500, detail=f"An internal server error occurred during processing: {str(e)}")


if __name__ == "__main__":
    print("Starting FastAPI application...")
    print("Navigate to http://127.0.0.1:8000/docs for interactive API documentation (Swagger UI).")
    uvicorn.run(app, host="0.0.0.0", port=8001)