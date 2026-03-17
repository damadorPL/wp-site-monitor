"""
push_indexing.py — Push unvisited WordPress URLs to Google Indexing API.

Works with the WP Site Monitor plugin. Fetches unvisited posts via the plugin's
REST API, pushes them to Google Indexing API, and logs results back to WordPress.

Configuration:
    Copy config.example.json to config.json and fill in your values.
    Alternatively, set environment variables (see below).

Usage:
    python push_indexing.py              # run as daemon (loops every N hours)
    python push_indexing.py --once       # single run, then exit
    python push_indexing.py --dry-run    # fetch URLs but don't push to Google
    python push_indexing.py --config /path/to/config.json

Environment variables (override config.json):
    WPSM_WP_BASE_URL           — WordPress site URL (e.g. https://example.com)
    WPSM_SECRET_KEY            — REST API secret key (from plugin settings)
    WPSM_GOOGLE_SA_FILE        — Path to Google service account JSON key file
    WPSM_MAX_URLS              — Max URLs per cycle (default: 200)
    WPSM_CYCLE_HOURS           — Hours between cycles in daemon mode (default: 25)
"""

import json
import os
import sys
import time
import datetime
import argparse
import logging

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except (AttributeError, OSError):
    pass

import requests
from google.oauth2 import service_account
from google.auth.transport.requests import Request as GoogleRequest

# ============================================================
# CONFIG
# ============================================================

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))

INDEXING_API_URL = "https://indexing.googleapis.com/v3/urlNotifications:publish"
INDEXING_API_SCOPES = ["https://www.googleapis.com/auth/indexing"]


def load_config(config_path=None):
    """Load configuration from JSON file, then override with environment variables."""
    config = {
        "wp_base_url": "",
        "wpsm_secret_key": "",
        "google_service_account_file": "",
        "max_urls_per_cycle": 200,
        "cycle_interval_hours": 25,
    }

    # Load from config file
    if config_path is None:
        config_path = os.path.join(SCRIPT_DIR, "config.json")

    if os.path.exists(config_path):
        with open(config_path, "r", encoding="utf-8") as f:
            file_config = json.load(f)
            config.update(file_config)

    # Environment variables override config file
    if os.environ.get("WPSM_WP_BASE_URL"):
        config["wp_base_url"] = os.environ["WPSM_WP_BASE_URL"]
    if os.environ.get("WPSM_SECRET_KEY"):
        config["wpsm_secret_key"] = os.environ["WPSM_SECRET_KEY"]
    if os.environ.get("WPSM_GOOGLE_SA_FILE"):
        config["google_service_account_file"] = os.environ["WPSM_GOOGLE_SA_FILE"]
    if os.environ.get("WPSM_MAX_URLS"):
        config["max_urls_per_cycle"] = int(os.environ["WPSM_MAX_URLS"])
    if os.environ.get("WPSM_CYCLE_HOURS"):
        config["cycle_interval_hours"] = int(os.environ["WPSM_CYCLE_HOURS"])

    # Resolve relative service account path
    sa_file = config["google_service_account_file"]
    if sa_file and not os.path.isabs(sa_file):
        config["google_service_account_file"] = os.path.join(SCRIPT_DIR, sa_file)

    return config


def validate_config(config):
    """Validate that required configuration values are present."""
    errors = []
    if not config["wp_base_url"]:
        errors.append("wp_base_url is required (your WordPress site URL)")
    if not config["wpsm_secret_key"]:
        errors.append("wpsm_secret_key is required (from WP Site Monitor > Settings)")
    if not config["google_service_account_file"]:
        errors.append("google_service_account_file is required (Google Cloud service account JSON key)")
    elif not os.path.exists(config["google_service_account_file"]):
        errors.append(f"Service account file not found: {config['google_service_account_file']}")
    return errors


# ============================================================
# LOGGING
# ============================================================

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler(os.path.join(SCRIPT_DIR, "push_indexing.log"), encoding="utf-8"),
    ],
)
log = logging.getLogger("push_indexing")

# ============================================================
# GOOGLE AUTH
# ============================================================

_credentials = None


def get_google_credentials(sa_file):
    global _credentials
    if _credentials and _credentials.valid:
        return _credentials
    _credentials = service_account.Credentials.from_service_account_file(
        sa_file, scopes=INDEXING_API_SCOPES
    )
    _credentials.refresh(GoogleRequest())
    return _credentials


def google_headers(sa_file):
    creds = get_google_credentials(sa_file)
    return {
        "Authorization": f"Bearer {creds.token}",
        "Content-Type": "application/json",
    }


# ============================================================
# WP PLUGIN API
# ============================================================


def fetch_unvisited(config, limit=200):
    """Fetch unvisited post URLs from WP plugin REST endpoint."""
    url = f"{config['wp_base_url'].rstrip('/')}/wp-json/wpsm/v1/unvisited"
    params = {"key": config["wpsm_secret_key"], "limit": limit, "bot": "Googlebot"}
    try:
        r = requests.get(url, params=params, timeout=30)
        r.raise_for_status()
        data = r.json()
        log.info(
            "Fetched %d unvisited URLs (total unvisited: %d)",
            data.get("returned", 0),
            data.get("total_unvisited", 0),
        )
        return data.get("posts", [])
    except Exception as e:
        log.error("Failed to fetch unvisited: %s", e)
        return []


def log_push_results(config, results):
    """Send push results back to WP plugin for dashboard display."""
    if not results:
        return
    url = f"{config['wp_base_url'].rstrip('/')}/wp-json/wpsm/v1/log-push?key={config['wpsm_secret_key']}"
    try:
        r = requests.post(url, json=results, timeout=30)
        r.raise_for_status()
        data = r.json()
        log.info("Logged %d results to WP plugin", data.get("logged", 0))
    except Exception as e:
        log.error("Failed to log results to WP: %s", e)


# ============================================================
# GOOGLE INDEXING API
# ============================================================


def push_url_to_google(config, page_url):
    """Push a single URL to Google Indexing API. Returns (status, code, body)."""
    payload = {"url": page_url, "type": "URL_UPDATED"}
    try:
        r = requests.post(
            INDEXING_API_URL,
            headers=google_headers(config["google_service_account_file"]),
            json=payload,
            timeout=30,
        )
        body = r.text[:500]
        if r.status_code == 200:
            return "ok", r.status_code, body
        else:
            return "error", r.status_code, body
    except Exception as e:
        return "error", 0, str(e)[:500]


# ============================================================
# MAIN CYCLE
# ============================================================


def run_cycle(config, dry_run=False):
    """Run one indexing cycle: fetch unvisited, push to Google, log results."""
    log.info("=" * 60)
    log.info("Starting indexing cycle at %s", datetime.datetime.now().isoformat())
    log.info("Site: %s", config["wp_base_url"])

    posts = fetch_unvisited(config, limit=config["max_urls_per_cycle"])
    if not posts:
        log.info("No unvisited URLs to push. Done.")
        return 0

    log.info(
        "Will push %d URLs to Google Indexing API%s",
        len(posts),
        " (DRY RUN)" if dry_run else "",
    )

    results = []
    ok_count = 0
    err_count = 0

    for i, post in enumerate(posts, 1):
        url = post["url"]
        post_id = post["post_id"]
        title = post.get("title", "")[:60]

        if dry_run:
            log.info("[%d/%d] DRY RUN: %s (%s)", i, len(posts), url, title)
            results.append({
                "post_id": post_id,
                "url": url,
                "status": "dry_run",
                "response_code": 0,
                "response_body": "dry run",
            })
            continue

        status, code, body = push_url_to_google(config, url)

        if status == "ok":
            ok_count += 1
            log.info("[%d/%d] OK %s", i, len(posts), url)
        else:
            err_count += 1
            log.warning("[%d/%d] ERROR %d %s — %s", i, len(posts), code, url, body[:100])

            # If quota exceeded or auth error, stop
            if code in (403, 429):
                log.error("Quota/auth error (HTTP %d). Stopping cycle early.", code)
                results.append({
                    "post_id": post_id,
                    "url": url,
                    "status": "error",
                    "response_code": code,
                    "response_body": body,
                })
                break

        results.append({
            "post_id": post_id,
            "url": url,
            "status": status,
            "response_code": code,
            "response_body": body,
        })

        # Small delay between requests
        if i < len(posts):
            time.sleep(0.5)

    log.info("Cycle done: %d OK, %d errors out of %d", ok_count, err_count, len(results))

    # Log to WP
    if not dry_run:
        log_push_results(config, results)

    # Also save locally
    save_local_log(results)

    return len(results)


def save_local_log(results):
    """Append results to local JSON log file."""
    log_file = os.path.join(SCRIPT_DIR, "push_indexing_log.json")
    existing = []
    if os.path.exists(log_file):
        try:
            with open(log_file, "r", encoding="utf-8") as f:
                existing = json.load(f)
        except (json.JSONDecodeError, OSError):
            existing = []

    entry = {
        "timestamp": datetime.datetime.now().isoformat(),
        "count": len(results),
        "ok": sum(1 for r in results if r["status"] == "ok"),
        "errors": sum(1 for r in results if r["status"] == "error"),
        "urls": [r["url"] for r in results[:10]],
    }
    existing.append(entry)
    existing = existing[-100:]

    with open(log_file, "w", encoding="utf-8") as f:
        json.dump(existing, f, indent=2, ensure_ascii=False)


# ============================================================
# DAEMON LOOP
# ============================================================


def main():
    parser = argparse.ArgumentParser(
        description="Push unvisited WordPress URLs to Google Indexing API"
    )
    parser.add_argument("--once", action="store_true", help="Run once and exit")
    parser.add_argument("--dry-run", action="store_true", help="Fetch URLs but don't push")
    parser.add_argument("--config", type=str, default=None, help="Path to config.json")
    args = parser.parse_args()

    config = load_config(args.config)

    # Validate
    errors = validate_config(config)
    if errors:
        for err in errors:
            log.error("Config error: %s", err)
        log.error("Please fix config.json or set environment variables. See README for details.")
        sys.exit(1)

    log.info("push_indexing.py started (once=%s, dry_run=%s)", args.once, args.dry_run)
    log.info("Site: %s", config["wp_base_url"])
    log.info("SA key: %s", config["google_service_account_file"])
    log.info("Max URLs per cycle: %d", config["max_urls_per_cycle"])

    if args.once:
        run_cycle(config, dry_run=args.dry_run)
        return

    # Daemon mode
    cycle_hours = config["cycle_interval_hours"]
    while True:
        try:
            run_cycle(config, dry_run=args.dry_run)
        except Exception as e:
            log.error("Cycle failed with exception: %s", e, exc_info=True)

        next_run = datetime.datetime.now() + datetime.timedelta(hours=cycle_hours)
        log.info("Next cycle at %s (in %dh)", next_run.strftime("%Y-%m-%d %H:%M"), cycle_hours)

        sleep_seconds = cycle_hours * 3600
        slept = 0
        while slept < sleep_seconds:
            time.sleep(min(60, sleep_seconds - slept))
            slept += 60


if __name__ == "__main__":
    main()
