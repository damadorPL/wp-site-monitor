# WP Site Monitor + Google Indexing API Pusher

A WordPress plugin that tracks search engine bot visits, monitors 404 errors, and shows per-post crawl statistics — paired with a Python script that automatically pushes uncrawled URLs to the Google Indexing API.

## What's Included

| Component | Description |
|-----------|-------------|
| `plugin/wp-site-monitor.php` | WordPress plugin — bot tracking, 404 monitoring, admin dashboard |
| `indexing-script/push_indexing.py` | Python script — pushes unvisited URLs to Google Indexing API |
| `indexing-script/config.example.json` | Example configuration for the Python script |
| `indexing-script/requirements.txt` | Python dependencies |

## Features

### WordPress Plugin
- **Bot detection** — tracks 30+ bots (Googlebot, Bingbot, YandexBot, AhrefsBot, GPTBot, ClaudeBot, etc.)
- **Per-post crawl stats** — see which posts have been crawled and when
- **404 error tracking** — monitors all 404 errors with bot/user identification
- **Unvisited posts** — find posts that haven't been crawled by Google yet
- **Indexing API dashboard** — view push history, daily stats, success/error rates
- **REST API** — external access for the indexing script (key-protected)
- **Settings page** — configure API key and log retention limits from WP admin
- **Auto-cleanup** — old log entries are automatically pruned

### Python Indexing Script
- **Automatic pushing** — fetches unvisited URLs from the plugin and pushes to Google
- **Daemon mode** — runs continuously with configurable interval (default: 25 hours)
- **Dry-run mode** — test without actually pushing to Google
- **Quota handling** — stops gracefully on 403/429 errors
- **Dual logging** — logs to WP dashboard + local files
- **Configurable** — via JSON config file or environment variables

---

## Part 1: WordPress Plugin Setup

### Installation

1. **Download** `plugin/wp-site-monitor.php`

2. **Create a ZIP file** (required for WP upload):
   - Create a folder named `wp-site-monitor`
   - Put `wp-site-monitor.php` inside it
   - Zip the folder → `wp-site-monitor.zip`

3. **Upload to WordPress**:
   - Go to **Plugins → Add New → Upload Plugin**
   - Choose `wp-site-monitor.zip`
   - Click **Install Now**, then **Activate**

4. **Alternative (FTP/SSH)**:
   - Upload `wp-site-monitor.php` to `/wp-content/plugins/wp-site-monitor/`
   - Activate from **Plugins** page

### Configuration

1. Go to **Site Monitor → Settings** in WP admin
2. Set a **REST API Secret Key** — the plugin generates a random suggestion for you
3. Optionally adjust log retention limits
4. Click **Save Changes**

### Dashboard Tabs

| Tab | What it shows |
|-----|---------------|
| **Overview** | Summary cards (bot visits, errors, crawl coverage) + recent activity |
| **Bots** | Detailed bot visit log with filtering by bot type |
| **404 Errors** | Most frequent 404s + chronological error timeline |
| **Posts vs Bots** | Which posts were crawled by which bot, with visit counts |
| **Unvisited** | Posts not yet crawled by Google/Bing/Yandex with coverage % |
| **Indexing API** | Push history, daily breakdown, success/error stats |

### REST API Endpoints

All endpoints require either:
- Query parameter `?key=YOUR_SECRET_KEY`, or
- WordPress admin authentication (cookie-based)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/wp-json/wpsm/v1/stats` | Bot & error statistics (24h, 7d) |
| `GET` | `/wp-json/wpsm/v1/unvisited?limit=200&bot=Googlebot` | List of uncrawled posts |
| `POST` | `/wp-json/wpsm/v1/log-push` | Log indexing push results (JSON array body) |

### Uninstallation

Deactivating the plugin keeps all data. **Deleting** the plugin (via Plugins page) removes all database tables and settings cleanly.

---

## Part 2: Google Indexing API Script Setup

### Prerequisites

1. **Python 3.8+** installed on your server/VPS
2. **Google Cloud project** with the Indexing API enabled
3. **Service account** with Indexing API permissions
4. **WP Site Monitor plugin** installed and configured (Part 1)

### Step 1: Google Cloud Setup

This is the most involved step. Follow these instructions carefully:

#### 1.1 Create a Google Cloud Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Click **Select a project** → **New Project**
3. Name it (e.g., "My Site Indexing") → **Create**
4. Make sure the new project is selected

#### 1.2 Enable the Indexing API

1. Go to **APIs & Services → Library**
2. Search for **"Web Search Indexing API"** (or "Indexing API")
3. Click on it → **Enable**

#### 1.3 Create a Service Account

1. Go to **APIs & Services → Credentials**
2. Click **Create Credentials → Service Account**
3. Name: e.g., "indexing-bot"
4. Click **Create and Continue**
5. Role: skip (no role needed) → **Continue** → **Done**
6. Click on the newly created service account
7. Go to **Keys** tab → **Add Key → Create new key**
8. Choose **JSON** → **Create**
9. A `.json` file will be downloaded — **this is your service account key file**

#### 1.4 Add the Service Account to Google Search Console

1. Open [Google Search Console](https://search.google.com/search-console)
2. Select your property (your website)
3. Go to **Settings → Users and permissions**
4. Click **Add user**
5. Enter the service account email (looks like `name@project-id.iam.gserviceaccount.com` — you can find it in the downloaded JSON under `client_email`)
6. Set permission to **Owner**
7. Click **Add**

> **Important**: Without this step, the Indexing API will return 403 errors!

### Step 2: Install Python Dependencies

```bash
cd indexing-script
pip install -r requirements.txt
```

### Step 3: Configure the Script

#### Option A: Config file (recommended)

```bash
cp config.example.json config.json
```

Edit `config.json`:

```json
{
    "wp_base_url": "https://your-site.com",
    "wpsm_secret_key": "paste-key-from-wp-site-monitor-settings",
    "google_service_account_file": "your-service-account-key.json",
    "max_urls_per_cycle": 200,
    "cycle_interval_hours": 25
}
```

- `wp_base_url` — your WordPress site URL (no trailing slash)
- `wpsm_secret_key` — the key you set in **Site Monitor → Settings**
- `google_service_account_file` — path to the Google JSON key file (relative to script directory, or absolute)
- `max_urls_per_cycle` — how many URLs to push per cycle (Google's daily limit is 200 for most sites)
- `cycle_interval_hours` — hours between daemon cycles

Place the Google service account `.json` key file in the `indexing-script/` directory (or specify an absolute path).

#### Option B: Environment variables

```bash
export WPSM_WP_BASE_URL="https://your-site.com"
export WPSM_SECRET_KEY="your-secret-key"
export WPSM_GOOGLE_SA_FILE="/path/to/service-account.json"
export WPSM_MAX_URLS=200
export WPSM_CYCLE_HOURS=25
```

Environment variables override config.json values.

### Step 4: Test the Script

```bash
# Dry run — fetches URLs but doesn't push to Google
python push_indexing.py --dry-run --once

# Single real run
python push_indexing.py --once
```

Check the output:
- `Fetched X unvisited URLs` — plugin connection works
- `OK https://...` — Google accepted the URL
- `ERROR 403` — check service account permissions in Search Console
- `ERROR 429` — quota exceeded, wait and try again tomorrow

### Step 5: Run as a Daemon

#### Linux (systemd)

Create `/etc/systemd/system/wp-indexing.service`:

```ini
[Unit]
Description=WP Site Monitor - Google Indexing API Pusher
After=network.target

[Service]
Type=simple
User=your-username
WorkingDirectory=/path/to/indexing-script
ExecStart=/usr/bin/python3 push_indexing.py
Restart=always
RestartSec=60

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable wp-indexing
sudo systemctl start wp-indexing
sudo systemctl status wp-indexing    # check status
journalctl -u wp-indexing -f         # view logs
```

#### Windows (Task Scheduler)

1. Open **Task Scheduler**
2. **Create Basic Task** → Name: "WP Indexing Pusher"
3. Trigger: **When the computer starts** (or daily)
4. Action: **Start a program**
   - Program: `python` (or full path to python.exe)
   - Arguments: `push_indexing.py`
   - Start in: `C:\path\to\indexing-script`
5. Check "Run whether user is logged on or not"

Or use the `--once` flag with a scheduled task that runs daily:
- Arguments: `push_indexing.py --once`

#### Docker

```dockerfile
FROM python:3.11-slim
WORKDIR /app
COPY indexing-script/ .
RUN pip install --no-cache-dir -r requirements.txt
CMD ["python", "push_indexing.py"]
```

```bash
docker build -t wp-indexing .
docker run -d --name wp-indexing \
  -v /path/to/config.json:/app/config.json \
  -v /path/to/service-account.json:/app/service-account.json \
  wp-indexing
```

---

## Monitoring

### In WordPress

Go to **Site Monitor → Indexing API** to see:
- How many URLs were pushed today/this month/total
- Success vs error rates
- Daily breakdown with visual charts
- Detailed push log

### Local Logs

The Python script creates two log files in its directory:
- `push_indexing.log` — detailed text log
- `push_indexing_log.json` — JSON summary (last 100 cycles)

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Plugin shows no bot data | Wait for bots to visit. Check that the plugin is activated. |
| REST API returns 401/403 | Check that the secret key in config.json matches the one in WP Settings |
| Script can't connect to WP | Verify `wp_base_url` is correct. Check if the site has HTTP Basic Auth (you may need to add auth to requests). |
| Google returns 403 | Service account not added as Owner in Search Console. See Step 1.4. |
| Google returns 429 | Daily quota exceeded. Default is 200/day. Try again tomorrow. |
| Google returns 400 | URL format issue. Make sure your site URLs are valid and accessible. |
| Script crashes on start | Run `python push_indexing.py --dry-run --once` to test. Check config.json for typos. |
| No "unvisited" posts found | All posts have been crawled by Googlebot already! Check the Unvisited tab in WP. |

---

## Google Indexing API Limits

- **Daily quota**: ~200 URL notifications per day (default for most sites)
- **Per-minute limit**: ~600 requests/minute
- **Eligible content**: The Indexing API officially supports `JobPosting` and `BroadcastEvent` structured data, but in practice it works for notifying Google about any URL
- **Not a guarantee**: Pushing a URL tells Google to re-crawl it, but doesn't guarantee indexing

---

## Security Notes

- The REST API secret key should be **long and random** (the plugin generates suggestions)
- Never commit `config.json` or service account keys to Git — add them to `.gitignore`
- The plugin only exposes read-only data + push logging via the REST API
- All admin dashboard pages require `manage_options` capability (WordPress admin only)
- The plugin cleans up old data automatically to prevent database bloat

---

## .gitignore

If publishing to GitHub, create a `.gitignore`:

```
config.json
*.json
!config.example.json
!requirements.txt
push_indexing.log
push_indexing_log.json
```

---

## License

GPL v2 or later (WordPress plugin) / MIT (Python script)
