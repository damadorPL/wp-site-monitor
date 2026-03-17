<?php
/**
 * Plugin Name: WP Site Monitor
 * Plugin URI: https://github.com/your-username/wp-site-monitor
 * Description: Bot tracking, 404 error monitoring, per-post crawl stats, Google Indexing API integration & admin dashboard.
 * Version: 2.0
 * Author: Dawid Przekot Walczyk
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-site-monitor
 */

if (!defined('ABSPATH')) exit;

define('WPSM_VERSION', '2.0');
define('WPSM_SLUG', 'wp-site-monitor');

// ============================================================
// SETTINGS PAGE
// ============================================================

add_action('admin_init', function() {
    register_setting('wpsm_settings', 'wpsm_api_secret_key', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => '',
    ));
    register_setting('wpsm_settings', 'wpsm_bot_log_limit', array(
        'type' => 'integer',
        'sanitize_callback' => 'absint',
        'default' => 10000,
    ));
    register_setting('wpsm_settings', 'wpsm_error_log_limit', array(
        'type' => 'integer',
        'sanitize_callback' => 'absint',
        'default' => 5000,
    ));
    register_setting('wpsm_settings', 'wpsm_indexing_log_limit', array(
        'type' => 'integer',
        'sanitize_callback' => 'absint',
        'default' => 50000,
    ));
});

function wpsm_get_secret_key() {
    return get_option('wpsm_api_secret_key', '');
}

// ============================================================
// DATABASE TABLES
// ============================================================

register_activation_hook(__FILE__, 'wpsm_create_tables');

function wpsm_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpsm_bot_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        visit_time DATETIME NOT NULL,
        bot VARCHAR(50) NOT NULL,
        url VARCHAR(500) NOT NULL,
        post_id BIGINT UNSIGNED DEFAULT 0,
        status_code SMALLINT DEFAULT 200,
        ip VARCHAR(45) DEFAULT '',
        ua VARCHAR(500) DEFAULT '',
        INDEX idx_bot (bot),
        INDEX idx_time (visit_time),
        INDEX idx_post (post_id),
        INDEX idx_url (url(191))
    ) $charset");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpsm_error_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        error_time DATETIME NOT NULL,
        url VARCHAR(500) NOT NULL,
        status_code SMALLINT NOT NULL,
        referer VARCHAR(500) DEFAULT '',
        ua VARCHAR(500) DEFAULT '',
        is_bot TINYINT(1) DEFAULT 0,
        bot_name VARCHAR(50) DEFAULT '',
        INDEX idx_time (error_time),
        INDEX idx_url (url(191)),
        INDEX idx_status (status_code)
    ) $charset");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpsm_post_stats (
        post_id BIGINT UNSIGNED NOT NULL,
        bot VARCHAR(50) NOT NULL,
        visit_count INT UNSIGNED DEFAULT 0,
        first_visit DATETIME DEFAULT NULL,
        last_visit DATETIME DEFAULT NULL,
        PRIMARY KEY (post_id, bot),
        INDEX idx_last (last_visit)
    ) $charset");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpsm_health (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        check_time DATETIME NOT NULL,
        report LONGTEXT,
        issues_count SMALLINT DEFAULT 0,
        INDEX idx_time (check_time)
    ) $charset");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpsm_indexing_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        push_time DATETIME NOT NULL,
        post_id BIGINT UNSIGNED NOT NULL,
        url VARCHAR(500) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'sent',
        response_code SMALLINT DEFAULT 0,
        response_body TEXT DEFAULT '',
        INDEX idx_time (push_time),
        INDEX idx_post (post_id),
        INDEX idx_status (status)
    ) $charset");

    update_option('wpsm_db_version', WPSM_VERSION);
}

// Auto-create tables if version mismatch
add_action('admin_init', function() {
    if (get_option('wpsm_db_version') !== WPSM_VERSION) {
        wpsm_create_tables();
    }
});

// Cleanup on uninstall
register_uninstall_hook(__FILE__, 'wpsm_uninstall');

function wpsm_uninstall() {
    global $wpdb;
    $tables = array('wpsm_bot_log', 'wpsm_error_log', 'wpsm_post_stats', 'wpsm_health', 'wpsm_indexing_log');
    foreach ($tables as $t) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$t}");
    }
    delete_option('wpsm_db_version');
    delete_option('wpsm_api_secret_key');
    delete_option('wpsm_bot_log_limit');
    delete_option('wpsm_error_log_limit');
    delete_option('wpsm_indexing_log_limit');
}

// ============================================================
// BOT DETECTION
// ============================================================

function wpsm_detect_bot($ua) {
    $bots = array(
        'Googlebot-Image' => 'Googlebot-Image',
        'Googlebot-News' => 'Googlebot-News',
        'Googlebot-Video' => 'Googlebot-Video',
        'Storebot-Google' => 'Storebot-Google',
        'Google-InspectionTool' => 'Google-InspectionTool',
        'GoogleOther' => 'GoogleOther',
        'AdsBot-Google' => 'AdsBot-Google',
        'Mediapartners-Google' => 'Mediapartners-Google',
        'FeedFetcher-Google' => 'FeedFetcher-Google',
        'APIs-Google' => 'APIs-Google',
        'Googlebot' => 'Googlebot',
        'bingbot' => 'bingbot',
        'msnbot' => 'msnbot',
        'BingPreview' => 'BingPreview',
        'YandexBot' => 'YandexBot',
        'Baiduspider' => 'Baiduspider',
        'DuckDuckBot' => 'DuckDuckBot',
        'Slurp' => 'Yahoo',
        'facebookexternalhit' => 'Facebook',
        'Twitterbot' => 'Twitter',
        'LinkedInBot' => 'LinkedIn',
        'AhrefsBot' => 'AhrefsBot',
        'SemrushBot' => 'SemrushBot',
        'MJ12bot' => 'MajesticBot',
        'DotBot' => 'DotBot',
        'PetalBot' => 'PetalBot',
        'Applebot' => 'Applebot',
        'GPTBot' => 'GPTBot',
        'ClaudeBot' => 'ClaudeBot',
        'CCBot' => 'CCBot',
        'DataForSeoBot' => 'DataForSeoBot',
        'archive.org_bot' => 'ArchiveBot',
        'UptimeRobot' => 'UptimeRobot',
    );
    foreach ($bots as $pattern => $name) {
        if (stripos($ua, $pattern) !== false) {
            return $name;
        }
    }
    return false;
}

// ============================================================
// TRACKING HOOKS
// ============================================================

add_action('wp', function() {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    $bot = wpsm_detect_bot($ua);
    if (!$bot) return;

    global $wpdb;
    $url = $_SERVER['REQUEST_URI'];
    $post_id = 0;

    if (is_singular()) {
        $post_id = get_the_ID();
    }

    $wpdb->insert($wpdb->prefix . 'wpsm_bot_log', array(
        'visit_time' => current_time('mysql'),
        'bot' => $bot,
        'url' => substr($url, 0, 500),
        'post_id' => $post_id,
        'status_code' => http_response_code(),
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
        'ua' => substr($ua, 0, 500),
    ));

    if ($post_id > 0) {
        $now = current_time('mysql');
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT visit_count FROM {$wpdb->prefix}wpsm_post_stats WHERE post_id=%d AND bot=%s",
            $post_id, $bot
        ));
        if ($exists !== null) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}wpsm_post_stats SET visit_count=visit_count+1, last_visit=%s WHERE post_id=%d AND bot=%s",
                $now, $post_id, $bot
            ));
        } else {
            $wpdb->insert($wpdb->prefix . 'wpsm_post_stats', array(
                'post_id' => $post_id,
                'bot' => $bot,
                'visit_count' => 1,
                'first_visit' => $now,
                'last_visit' => $now,
            ));
        }
    }

    // Cleanup old entries
    $limit = (int) get_option('wpsm_bot_log_limit', 10000);
    $threshold = (int) ($limit * 1.2);
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpsm_bot_log");
    if ($count > $threshold) {
        $cutoff_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wpsm_bot_log ORDER BY id DESC LIMIT 1 OFFSET %d", $limit
        ));
        if ($cutoff_id) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wpsm_bot_log WHERE id < %d", $cutoff_id));
        }
    }
}, 1);

// Track 404 errors
add_action('template_redirect', function() {
    if (!is_404()) return;

    global $wpdb;
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
    $bot = wpsm_detect_bot($ua);

    $wpdb->insert($wpdb->prefix . 'wpsm_error_log', array(
        'error_time' => current_time('mysql'),
        'url' => substr($_SERVER['REQUEST_URI'], 0, 500),
        'status_code' => 404,
        'referer' => isset($_SERVER['HTTP_REFERER']) ? substr($_SERVER['HTTP_REFERER'], 0, 500) : '',
        'ua' => $ua,
        'is_bot' => $bot ? 1 : 0,
        'bot_name' => $bot ?: '',
    ));

    // Cleanup
    $limit = (int) get_option('wpsm_error_log_limit', 5000);
    $threshold = (int) ($limit * 1.2);
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpsm_error_log");
    if ($count > $threshold) {
        $cutoff_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wpsm_error_log ORDER BY id DESC LIMIT 1 OFFSET %d", $limit
        ));
        if ($cutoff_id) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wpsm_error_log WHERE id < %d", $cutoff_id));
        }
    }
}, 999);

// ============================================================
// ADMIN MENU & DASHBOARD
// ============================================================

add_action('admin_menu', function() {
    add_menu_page(
        'Site Monitor',
        'Site Monitor',
        'manage_options',
        WPSM_SLUG,
        'wpsm_dashboard_page',
        'dashicons-visibility',
        30
    );
    add_submenu_page(
        WPSM_SLUG,
        'Site Monitor Settings',
        'Settings',
        'manage_options',
        WPSM_SLUG . '-settings',
        'wpsm_settings_page'
    );
});

// ---- SETTINGS PAGE ----
function wpsm_settings_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
        <h1>Site Monitor &mdash; Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('wpsm_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wpsm_api_secret_key">REST API Secret Key</label></th>
                    <td>
                        <input type="text" id="wpsm_api_secret_key" name="wpsm_api_secret_key"
                               value="<?php echo esc_attr(get_option('wpsm_api_secret_key', '')); ?>"
                               class="regular-text" autocomplete="off" />
                        <p class="description">
                            Secret key for external REST API access (e.g. from the indexing script).<br>
                            Leave empty to disable key-based access (admin-only access via cookies will still work).<br>
                            <strong>Tip:</strong> Generate a random key, e.g.: <code><?php echo wp_generate_password(16, false); ?></code>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpsm_bot_log_limit">Bot log limit</label></th>
                    <td>
                        <input type="number" id="wpsm_bot_log_limit" name="wpsm_bot_log_limit"
                               value="<?php echo esc_attr(get_option('wpsm_bot_log_limit', 10000)); ?>"
                               min="1000" max="500000" class="small-text" />
                        <p class="description">Maximum number of bot visit entries to keep (default: 10,000).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpsm_error_log_limit">Error log limit</label></th>
                    <td>
                        <input type="number" id="wpsm_error_log_limit" name="wpsm_error_log_limit"
                               value="<?php echo esc_attr(get_option('wpsm_error_log_limit', 5000)); ?>"
                               min="500" max="100000" class="small-text" />
                        <p class="description">Maximum number of 404 error entries to keep (default: 5,000).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpsm_indexing_log_limit">Indexing log limit</label></th>
                    <td>
                        <input type="number" id="wpsm_indexing_log_limit" name="wpsm_indexing_log_limit"
                               value="<?php echo esc_attr(get_option('wpsm_indexing_log_limit', 50000)); ?>"
                               min="1000" max="500000" class="small-text" />
                        <p class="description">Maximum number of Indexing API push entries to keep (default: 50,000).</p>
                    </td>
                </tr>
            </table>

            <h2>REST API Endpoints</h2>
            <p>The plugin exposes these REST API endpoints (all require the secret key or admin authentication):</p>
            <table class="widefat" style="max-width:800px;">
                <thead><tr><th>Method</th><th>Endpoint</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>GET</code></td><td><code>/wp-json/wpsm/v1/stats?key=YOUR_KEY</code></td><td>Bot & error statistics (24h, 7d)</td></tr>
                    <tr><td><code>GET</code></td><td><code>/wp-json/wpsm/v1/unvisited?key=YOUR_KEY&limit=200&bot=Googlebot</code></td><td>Unvisited posts (for indexing script)</td></tr>
                    <tr><td><code>POST</code></td><td><code>/wp-json/wpsm/v1/log-push?key=YOUR_KEY</code></td><td>Log indexing push results (JSON array body)</td></tr>
                </tbody>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// ---- MAIN DASHBOARD PAGE ----
function wpsm_dashboard_page() {
    global $wpdb;
    $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';
    $prefix = $wpdb->prefix;

    echo '<div class="wrap">';
    echo '<h1>Site Monitor</h1>';

    $tabs = array(
        'overview' => 'Overview',
        'bots' => 'Bots',
        'errors' => '404 Errors',
        'posts' => 'Posts vs Bots',
        'unvisited' => 'Unvisited',
        'indexing' => 'Indexing API',
    );
    echo '<nav class="nav-tab-wrapper">';
    foreach ($tabs as $slug => $label) {
        $class = ($tab === $slug) ? 'nav-tab nav-tab-active' : 'nav-tab';
        echo '<a href="?page=' . WPSM_SLUG . '&tab=' . $slug . '" class="' . $class . '">' . $label . '</a>';
    }
    $export_url = wp_nonce_url(admin_url('admin-post.php?action=wpsm_export_xlsx'), 'wpsm_export');
    echo '<a href="' . esc_url($export_url) . '" class="button button-primary" style="float:right;margin-top:5px;"><span class="dashicons dashicons-download" style="margin-top:3px;"></span> Export to XLSX</a>';
    echo '</nav>';

    // Copy-to-clipboard inline script
    echo '<script>function wpsmCopy(t){navigator.clipboard.writeText(t).then(function(){},function(){var a=document.createElement("textarea");a.value=t;document.body.appendChild(a);a.select();document.execCommand("copy");document.body.removeChild(a);});}</script>';
    echo '<style>.wpsm-copy{cursor:pointer;opacity:0.5;margin-left:4px;vertical-align:middle;}.wpsm-copy:hover{opacity:1;}</style>';

    echo '<div style="margin-top:20px;">';

    switch ($tab) {
        case 'overview':   wpsm_tab_overview(); break;
        case 'bots':       wpsm_tab_bots(); break;
        case 'errors':     wpsm_tab_errors(); break;
        case 'posts':      wpsm_tab_posts(); break;
        case 'unvisited':  wpsm_tab_unvisited(); break;
        case 'indexing':   wpsm_tab_indexing(); break;
    }

    echo '</div></div>';
}

// ---- OVERVIEW TAB ----
function wpsm_tab_overview() {
    global $wpdb;
    $prefix = $wpdb->prefix;
    $yesterday = date('Y-m-d H:i:s', strtotime('-24 hours'));
    $week_ago = date('Y-m-d H:i:s', strtotime('-7 days'));

    $bot_24h = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_bot_log WHERE visit_time >= '$yesterday'");
    $bot_7d = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_bot_log WHERE visit_time >= '$week_ago'");
    $errors_24h = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_error_log WHERE error_time >= '$yesterday'");
    $errors_7d = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_error_log WHERE error_time >= '$week_ago'");
    $total_posts = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='post'");
    $crawled_posts = (int)$wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$prefix}wpsm_post_stats WHERE bot LIKE 'Google%'");
    $uncrawled = $total_posts - $crawled_posts;

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:30px;">';
    $cards = array(
        array('Bot visits (24h)', $bot_24h, '#2271b1'),
        array('Bot visits (7d)', $bot_7d, '#2271b1'),
        array('404 errors (24h)', $errors_24h, $errors_24h > 0 ? '#d63638' : '#00a32a'),
        array('404 errors (7d)', $errors_7d, $errors_7d > 0 ? '#d63638' : '#00a32a'),
        array('Published posts', $total_posts, '#8c8f94'),
        array('Crawled (Google)', $crawled_posts, '#00a32a'),
        array('Uncrawled (Google)', $uncrawled, $uncrawled > 50 ? '#dba617' : '#00a32a'),
    );
    foreach ($cards as $card) {
        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid ' . $card[2] . ';padding:15px;border-radius:3px;">';
        echo '<div style="font-size:28px;font-weight:700;color:' . $card[2] . ';">' . number_format($card[1]) . '</div>';
        echo '<div style="color:#646970;margin-top:5px;">' . esc_html($card[0]) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    // Bot activity by type (24h)
    echo '<h2>Bot activity (last 24h)</h2>';
    $bot_stats = $wpdb->get_results("SELECT bot, COUNT(*) as cnt FROM {$prefix}wpsm_bot_log WHERE visit_time >= '$yesterday' GROUP BY bot ORDER BY cnt DESC");
    if ($bot_stats) {
        echo '<table class="widefat striped"><thead><tr><th>Bot</th><th>Visits</th><th>Share</th></tr></thead><tbody>';
        $total = array_sum(array_column($bot_stats, 'cnt'));
        foreach ($bot_stats as $s) {
            $pct = $total > 0 ? round($s->cnt / $total * 100, 1) : 0;
            $bar = '<div style="background:#2271b1;height:12px;width:' . $pct . '%;border-radius:2px;display:inline-block;"></div>';
            echo "<tr><td><strong>" . esc_html($s->bot) . "</strong></td><td>{$s->cnt}</td><td>{$bar} {$pct}%</td></tr>";
        }
        echo '</tbody></table>';
    } else {
        echo '<p>No data from the last 24 hours.</p>';
    }

    // Recent errors
    echo '<h2 style="margin-top:30px;">Recent 404 errors</h2>';
    $errors = $wpdb->get_results("SELECT url, COUNT(*) as cnt, MAX(error_time) as last_seen,
        SUM(is_bot) as bot_hits FROM {$prefix}wpsm_error_log
        GROUP BY url ORDER BY cnt DESC LIMIT 15");
    if ($errors) {
        echo '<table class="widefat striped"><thead><tr><th>URL</th><th>Hits</th><th>Bots</th><th>Last seen</th></tr></thead><tbody>';
        foreach ($errors as $e) {
            $url_short = strlen($e->url) > 60 ? substr($e->url, 0, 60) . '...' : $e->url;
            $url_escaped = esc_attr($e->url);
            $copy_btn = '<span class="wpsm-copy dashicons dashicons-clipboard" title="Copy full URL" onclick="wpsmCopy(\'' . $url_escaped . '\')"></span>';
            $bot_badge = $e->bot_hits > 0 ? '<span style="background:#d63638;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;">' . $e->bot_hits . ' bot</span>' : '';
            echo "<tr><td><code>" . esc_html($url_short) . "</code>{$copy_btn}</td><td>{$e->cnt}</td><td>{$bot_badge}</td><td>{$e->last_seen}</td></tr>";
        }
        echo '</tbody></table>';
    } else {
        echo '<p style="color:#00a32a;font-weight:600;">No 404 errors!</p>';
    }
}

// ---- BOTS TAB ----
function wpsm_tab_bots() {
    global $wpdb;
    $prefix = $wpdb->prefix;

    $filter_bot = isset($_GET['bot']) ? sanitize_text_field($_GET['bot']) : '';
    $page_num = max(1, intval($_GET['paged'] ?? 1));
    $per_page = 50;
    $offset = ($page_num - 1) * $per_page;

    $all_bots = $wpdb->get_col("SELECT DISTINCT bot FROM {$prefix}wpsm_bot_log ORDER BY bot");
    echo '<div style="margin-bottom:15px;">';
    echo '<a href="?page=' . WPSM_SLUG . '&tab=bots" class="button ' . (!$filter_bot ? 'button-primary' : '') . '">All</a> ';
    foreach ($all_bots as $b) {
        $active = ($filter_bot === $b) ? 'button-primary' : '';
        echo '<a href="?page=' . WPSM_SLUG . '&tab=bots&bot=' . urlencode($b) . '" class="button ' . $active . '">' . esc_html($b) . '</a> ';
    }
    echo '</div>';

    $where = $filter_bot ? $wpdb->prepare("WHERE bot=%s", $filter_bot) : '';
    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_bot_log $where");
    $rows = $wpdb->get_results("SELECT * FROM {$prefix}wpsm_bot_log $where ORDER BY visit_time DESC LIMIT $per_page OFFSET $offset");

    echo "<p>Total: <strong>{$total}</strong> entries</p>";
    echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Bot</th><th>URL</th><th>Status</th><th>IP</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $url_short = strlen($r->url) > 70 ? substr($r->url, 0, 70) . '...' : $r->url;
        $status_color = $r->status_code == 200 ? '#00a32a' : '#d63638';
        echo "<tr>";
        echo "<td style='white-space:nowrap;'>{$r->visit_time}</td>";
        echo "<td><strong>" . esc_html($r->bot) . "</strong></td>";
        echo "<td><code>" . esc_html($url_short) . "</code></td>";
        echo "<td style='color:{$status_color};font-weight:600;'>{$r->status_code}</td>";
        echo "<td style='font-size:12px;'>" . esc_html($r->ip) . "</td>";
        echo "</tr>";
    }
    echo '</tbody></table>';

    wpsm_pagination($total, $per_page, $page_num, 'bots', $filter_bot ? '&bot=' . urlencode($filter_bot) : '');
}

// ---- ERRORS TAB ----
function wpsm_tab_errors() {
    global $wpdb;
    $prefix = $wpdb->prefix;
    $page_num = max(1, intval($_GET['paged'] ?? 1));
    $per_page = 50;
    $offset = ($page_num - 1) * $per_page;

    echo '<h2>Top 404 errors</h2>';
    $grouped = $wpdb->get_results("SELECT url, COUNT(*) as cnt, MAX(error_time) as last_seen,
        SUM(is_bot) as bot_hits, GROUP_CONCAT(DISTINCT bot_name) as bots
        FROM {$prefix}wpsm_error_log GROUP BY url ORDER BY cnt DESC LIMIT 50");

    if ($grouped) {
        echo '<table class="widefat striped"><thead><tr><th>URL</th><th>Hits</th><th>Bots</th><th>Bot names</th><th>Last seen</th></tr></thead><tbody>';
        foreach ($grouped as $g) {
            $url_short = strlen($g->url) > 60 ? substr($g->url, 0, 60) . '...' : $g->url;
            $url_escaped = esc_attr($g->url);
            $copy_btn = '<span class="wpsm-copy dashicons dashicons-clipboard" title="Copy full URL" onclick="wpsmCopy(\'' . $url_escaped . '\')"></span>';
            $bots = $g->bots ? '<span style="font-size:11px;color:#d63638;">' . esc_html($g->bots) . '</span>' : '-';
            echo "<tr><td><code>" . esc_html($url_short) . "</code>{$copy_btn}</td><td><strong>{$g->cnt}</strong></td><td>{$g->bot_hits}</td><td>{$bots}</td><td>{$g->last_seen}</td></tr>";
        }
        echo '</tbody></table>';
    } else {
        echo '<p style="color:#00a32a;font-weight:600;">No 404 errors!</p>';
    }

    echo '<h2 style="margin-top:30px;">Recent errors (chronological)</h2>';
    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_error_log");
    $recent = $wpdb->get_results("SELECT * FROM {$prefix}wpsm_error_log ORDER BY error_time DESC LIMIT $per_page OFFSET $offset");

    echo "<p>Total: <strong>{$total}</strong> errors</p>";
    if ($recent) {
        echo '<table class="widefat striped"><thead><tr><th>Time</th><th>URL</th><th>Referer</th><th>Bot</th></tr></thead><tbody>';
        foreach ($recent as $e) {
            $url_short = strlen($e->url) > 50 ? substr($e->url, 0, 50) . '...' : $e->url;
            $url_escaped = esc_attr($e->url);
            $copy_btn = '<span class="wpsm-copy dashicons dashicons-clipboard" title="Copy full URL" onclick="wpsmCopy(\'' . $url_escaped . '\')"></span>';
            $ref_short = $e->referer ? (strlen($e->referer) > 40 ? substr($e->referer, 0, 40) . '...' : $e->referer) : '-';
            $bot_label = $e->bot_name ?: ($e->is_bot ? 'bot' : 'user');
            $bot_style = $e->is_bot ? 'color:#d63638;font-weight:600;' : 'color:#8c8f94;';
            echo "<tr><td style='white-space:nowrap;'>{$e->error_time}</td><td><code>" . esc_html($url_short) . "</code>{$copy_btn}</td><td style='font-size:12px;'>" . esc_html($ref_short) . "</td><td style='{$bot_style}'>" . esc_html($bot_label) . "</td></tr>";
        }
        echo '</tbody></table>';
    }

    wpsm_pagination($total, $per_page, $page_num, 'errors');
}

// ---- POSTS VS BOTS TAB ----
function wpsm_tab_posts() {
    global $wpdb;
    $prefix = $wpdb->prefix;
    $page_num = max(1, intval($_GET['paged'] ?? 1));
    $per_page = 50;
    $offset = ($page_num - 1) * $per_page;
    $filter_bot = isset($_GET['bot']) ? sanitize_text_field($_GET['bot']) : 'Googlebot';

    $bot_types = $wpdb->get_col("SELECT DISTINCT bot FROM {$prefix}wpsm_post_stats ORDER BY bot");
    echo '<div style="margin-bottom:15px;">';
    foreach ($bot_types as $b) {
        $active = ($filter_bot === $b) ? 'button-primary' : '';
        echo '<a href="?page=' . WPSM_SLUG . '&tab=posts&bot=' . urlencode($b) . '" class="button ' . $active . '">' . esc_html($b) . '</a> ';
    }
    echo '</div>';

    $total = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}wpsm_post_stats WHERE bot=%s", $filter_bot
    ));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT s.post_id, s.visit_count, s.first_visit, s.last_visit, p.post_title, p.post_name
        FROM {$prefix}wpsm_post_stats s
        JOIN {$wpdb->posts} p ON s.post_id = p.ID
        WHERE s.bot=%s
        ORDER BY s.last_visit DESC
        LIMIT %d OFFSET %d",
        $filter_bot, $per_page, $offset
    ));

    echo "<p><strong>" . esc_html($filter_bot) . "</strong> visited <strong>{$total}</strong> posts</p>";
    echo '<table class="widefat striped"><thead><tr><th>Post</th><th>Visits</th><th>First visit</th><th>Last visit</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $title = mb_strlen($r->post_title) > 50 ? mb_substr($r->post_title, 0, 50) . '...' : $r->post_title;
        $days_ago = floor((time() - strtotime($r->last_visit)) / 86400);
        $freshness = $days_ago < 1 ? '<span style="color:#00a32a;">today</span>' :
                    ($days_ago < 7 ? "<span style='color:#2271b1;'>{$days_ago}d ago</span>" :
                    ($days_ago < 30 ? "<span style='color:#dba617;'>{$days_ago}d ago</span>" :
                    "<span style='color:#d63638;'>{$days_ago}d ago</span>"));
        $edit = '<a href="' . get_edit_post_link($r->post_id) . '" target="_blank">Edit</a>';
        $view = '<a href="' . get_permalink($r->post_id) . '" target="_blank">View</a>';
        echo "<tr><td><strong>" . esc_html($title) . "</strong></td><td>{$r->visit_count}</td><td>{$r->first_visit}</td><td>{$freshness}</td><td>{$edit} | {$view}</td></tr>";
    }
    echo '</tbody></table>';

    wpsm_pagination($total, $per_page, $page_num, 'posts', '&bot=' . urlencode($filter_bot));
}

// ---- UNVISITED POSTS TAB ----
function wpsm_tab_unvisited() {
    global $wpdb;
    $prefix = $wpdb->prefix;
    $page_num = max(1, intval($_GET['paged'] ?? 1));
    $per_page = 50;
    $offset = ($page_num - 1) * $per_page;
    $filter_bot = isset($_GET['bot']) ? sanitize_text_field($_GET['bot']) : 'Googlebot';

    echo '<div style="margin-bottom:15px;">';
    foreach (array('Googlebot', 'bingbot', 'YandexBot') as $b) {
        $active = ($filter_bot === $b) ? 'button-primary' : '';
        echo '<a href="?page=' . WPSM_SLUG . '&tab=unvisited&bot=' . urlencode($b) . '" class="button ' . $active . '">' . $b . '</a> ';
    }
    echo '</div>';

    $total = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} p
        WHERE p.post_status='publish' AND p.post_type='post'
        AND p.ID NOT IN (SELECT post_id FROM {$prefix}wpsm_post_stats WHERE bot=%s)",
        $filter_bot
    ));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_date, p.post_name
        FROM {$wpdb->posts} p
        WHERE p.post_status='publish' AND p.post_type='post'
        AND p.ID NOT IN (SELECT post_id FROM {$prefix}wpsm_post_stats WHERE bot=%s)
        ORDER BY p.post_date DESC
        LIMIT %d OFFSET %d",
        $filter_bot, $per_page, $offset
    ));

    $total_posts = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='post'");
    $pct = $total_posts > 0 ? round(($total_posts - $total) / $total_posts * 100, 1) : 100;

    echo "<div style='background:#fff;border:1px solid #c3c4c7;padding:15px;margin-bottom:20px;border-radius:3px;'>";
    echo "<strong>" . esc_html($filter_bot) . "</strong> has not visited <strong style='color:#d63638;'>{$total}</strong> of {$total_posts} posts ";
    echo "({$pct}% coverage)";
    echo "<div style='background:#dcdcde;height:20px;border-radius:10px;margin-top:10px;'>";
    echo "<div style='background:#00a32a;height:20px;width:{$pct}%;border-radius:10px;text-align:center;color:#fff;font-size:12px;line-height:20px;'>{$pct}%</div>";
    echo "</div></div>";

    echo '<table class="widefat striped"><thead><tr><th>Post</th><th>Published</th><th>Days since published</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $title = mb_strlen($r->post_title) > 60 ? mb_substr($r->post_title, 0, 60) . '...' : $r->post_title;
        $days = floor((time() - strtotime($r->post_date)) / 86400);
        $urgency = $days > 30 ? 'color:#d63638;font-weight:600;' : ($days > 7 ? 'color:#dba617;' : 'color:#00a32a;');
        $view = '<a href="' . get_permalink($r->ID) . '" target="_blank">View</a>';
        echo "<tr><td><strong>" . esc_html($title) . "</strong></td><td>{$r->post_date}</td><td style='{$urgency}'>{$days}d</td><td>{$view}</td></tr>";
    }
    echo '</tbody></table>';

    wpsm_pagination($total, $per_page, $page_num, 'unvisited', '&bot=' . urlencode($filter_bot));
}

// ---- INDEXING TAB ----
function wpsm_tab_indexing() {
    global $wpdb;
    $prefix = $wpdb->prefix;

    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$prefix}wpsm_indexing_log'");
    if (!$table_exists) {
        echo '<div class="notice notice-warning"><p>Indexing log table does not exist. It will be created now.</p></div>';
        wpsm_create_tables();
    }

    $today = date('Y-m-d');
    $month_start = date('Y-m-01');
    $page_num = max(1, intval($_GET['paged'] ?? 1));
    $per_page = 50;
    $offset = ($page_num - 1) * $per_page;
    $filter_month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');

    $sent_today = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_indexing_log WHERE push_time >= '$today 00:00:00'");
    $ok_today = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_indexing_log WHERE push_time >= '$today 00:00:00' AND status='ok'");
    $err_today = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_indexing_log WHERE push_time >= '$today 00:00:00' AND status='error'");
    $sent_month = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_indexing_log WHERE push_time >= '$month_start 00:00:00'");
    $ok_month = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_indexing_log WHERE push_time >= '$month_start 00:00:00' AND status='ok'");
    $total_ever = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_indexing_log");
    $unique_posts = (int)$wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$prefix}wpsm_indexing_log WHERE status='ok'");

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:15px;margin-bottom:30px;">';
    $cards = array(
        array('Sent today', $sent_today, '#2271b1'),
        array('OK today', $ok_today, '#00a32a'),
        array('Errors today', $err_today, $err_today > 0 ? '#d63638' : '#00a32a'),
        array('Sent this month', $sent_month, '#2271b1'),
        array('OK this month', $ok_month, '#00a32a'),
        array('Total sent', $total_ever, '#8c8f94'),
        array('Unique posts (OK)', $unique_posts, '#00a32a'),
    );
    foreach ($cards as $c) {
        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid ' . $c[2] . ';padding:15px;border-radius:3px;">';
        echo '<div style="font-size:28px;font-weight:700;color:' . $c[2] . ';">' . number_format($c[1]) . '</div>';
        echo '<div style="color:#646970;margin-top:5px;">' . esc_html($c[0]) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    // Daily breakdown
    $available_months = $wpdb->get_col("SELECT DISTINCT DATE_FORMAT(push_time, '%Y-%m') as m FROM {$prefix}wpsm_indexing_log ORDER BY m DESC LIMIT 12");

    echo '<h2>Daily summary</h2>';
    if ($available_months) {
        echo '<div style="margin-bottom:15px;">';
        foreach ($available_months as $m) {
            $active = ($filter_month === $m) ? 'button-primary' : '';
            echo '<a href="?page=' . WPSM_SLUG . '&tab=indexing&month=' . $m . '" class="button ' . $active . '">' . $m . '</a> ';
        }
        echo '</div>';
    }

    $daily = $wpdb->get_results("SELECT DATE(push_time) as day,
        COUNT(*) as total,
        SUM(CASE WHEN status='ok' THEN 1 ELSE 0 END) as ok_cnt,
        SUM(CASE WHEN status='error' THEN 1 ELSE 0 END) as err_cnt
        FROM {$prefix}wpsm_indexing_log
        WHERE push_time >= '{$filter_month}-01' AND push_time < DATE_ADD('{$filter_month}-01', INTERVAL 1 MONTH)
        GROUP BY DATE(push_time) ORDER BY day DESC");

    if ($daily) {
        echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Sent</th><th>OK</th><th>Errors</th><th>Chart</th></tr></thead><tbody>';
        foreach ($daily as $d) {
            $bar_ok = '<div style="background:#00a32a;height:14px;width:' . min($d->ok_cnt, 200) . 'px;display:inline-block;border-radius:2px;"></div>';
            $bar_err = $d->err_cnt > 0 ? '<div style="background:#d63638;height:14px;width:' . min($d->err_cnt * 2, 100) . 'px;display:inline-block;border-radius:2px;margin-left:2px;"></div>' : '';
            echo "<tr><td><strong>{$d->day}</strong></td><td>{$d->total}</td><td style='color:#00a32a;'>{$d->ok_cnt}</td><td style='color:#d63638;'>{$d->err_cnt}</td><td>{$bar_ok}{$bar_err}</td></tr>";
        }
        echo '</tbody></table>';
    } else {
        echo '<p>No data for ' . esc_html($filter_month) . '</p>';
    }

    // Recent pushes
    echo '<h2 style="margin-top:30px;">Recent pushes</h2>';
    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_indexing_log");
    $rows = $wpdb->get_results("SELECT l.*, p.post_title FROM {$prefix}wpsm_indexing_log l
        LEFT JOIN {$wpdb->posts} p ON l.post_id = p.ID
        ORDER BY l.push_time DESC LIMIT $per_page OFFSET $offset");

    echo "<p>Total: <strong>{$total}</strong> entries</p>";
    if ($rows) {
        echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Post</th><th>URL</th><th>Status</th><th>HTTP</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $title = $r->post_title ? (mb_strlen($r->post_title) > 40 ? mb_substr($r->post_title, 0, 40) . '...' : $r->post_title) : '#' . $r->post_id;
            $url_short = strlen($r->url) > 50 ? substr($r->url, 0, 50) . '...' : $r->url;
            $status_style = $r->status === 'ok' ? 'color:#00a32a;font-weight:600;' : 'color:#d63638;font-weight:600;';
            $http_style = ($r->response_code >= 200 && $r->response_code < 300) ? 'color:#00a32a;' : 'color:#d63638;';
            echo "<tr>";
            echo "<td style='white-space:nowrap;'>{$r->push_time}</td>";
            echo "<td><strong>" . esc_html($title) . "</strong></td>";
            echo "<td><code>" . esc_html($url_short) . "</code></td>";
            echo "<td style='{$status_style}'>" . esc_html($r->status) . "</td>";
            echo "<td style='{$http_style}'>{$r->response_code}</td>";
            echo "</tr>";
        }
        echo '</tbody></table>';
    }

    wpsm_pagination($total, $per_page, $page_num, 'indexing');
}

// ---- PAGINATION HELPER ----
function wpsm_pagination($total, $per_page, $current_page, $tab, $extra_params = '') {
    $total_pages = ceil($total / $per_page);
    if ($total_pages <= 1) return;

    echo '<div style="margin-top:15px;">';
    for ($p = 1; $p <= min($total_pages, 20); $p++) {
        $class = ($p == $current_page) ? 'button-primary' : '';
        echo '<a href="?page=' . WPSM_SLUG . '&tab=' . $tab . '&paged=' . $p . $extra_params . '" class="button ' . $class . '">' . $p . '</a> ';
    }
    if ($total_pages > 20) {
        echo '<span style="color:#646970;"> ... ' . $total_pages . ' pages total</span>';
    }
    echo '</div>';
}

// ============================================================
// REST API
// ============================================================

add_action('rest_api_init', function() {
    $auth_check = function($request) {
        $secret = wpsm_get_secret_key();
        if (!empty($secret)) {
            $key = $request->get_param('key');
            if ($key === $secret) return true;
        }
        return current_user_can('manage_options');
    };

    // Stats endpoint
    register_rest_route('wpsm/v1', '/stats', array(
        'methods' => 'GET',
        'callback' => function($request) {
            global $wpdb;
            $prefix = $wpdb->prefix;
            $yesterday = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $week_ago = date('Y-m-d H:i:s', strtotime('-7 days'));

            return rest_ensure_response(array(
                'bots_24h' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_bot_log WHERE visit_time >= '$yesterday'"),
                'bots_7d' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_bot_log WHERE visit_time >= '$week_ago'"),
                'errors_24h' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_error_log WHERE error_time >= '$yesterday'"),
                'errors_7d' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpsm_error_log WHERE error_time >= '$week_ago'"),
                'bot_breakdown_24h' => $wpdb->get_results("SELECT bot, COUNT(*) as cnt FROM {$prefix}wpsm_bot_log WHERE visit_time >= '$yesterday' GROUP BY bot ORDER BY cnt DESC"),
                'top_errors' => $wpdb->get_results("SELECT url, COUNT(*) as cnt, MAX(error_time) as last_seen FROM {$prefix}wpsm_error_log GROUP BY url ORDER BY cnt DESC LIMIT 20"),
            ));
        },
        'permission_callback' => $auth_check,
    ));

    // Unvisited posts endpoint
    register_rest_route('wpsm/v1', '/unvisited', array(
        'methods' => 'GET',
        'callback' => function($request) {
            global $wpdb;
            $prefix = $wpdb->prefix;
            $limit = min(500, max(1, intval($request->get_param('limit') ?: 200)));
            $bot = sanitize_text_field($request->get_param('bot') ?: 'Googlebot');

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID as post_id, p.post_title, p.post_name, p.post_date
                FROM {$wpdb->posts} p
                WHERE p.post_status='publish' AND p.post_type='post'
                AND p.ID NOT IN (SELECT post_id FROM {$prefix}wpsm_post_stats WHERE bot=%s)
                ORDER BY p.post_date ASC
                LIMIT %d",
                $bot, $limit
            ));

            $results = array();
            foreach ($rows as $r) {
                $results[] = array(
                    'post_id' => (int)$r->post_id,
                    'url' => get_permalink($r->post_id),
                    'title' => $r->post_title,
                    'slug' => $r->post_name,
                    'published' => $r->post_date,
                    'days_old' => floor((time() - strtotime($r->post_date)) / 86400),
                );
            }

            $total_unvisited = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                WHERE p.post_status='publish' AND p.post_type='post'
                AND p.ID NOT IN (SELECT post_id FROM {$prefix}wpsm_post_stats WHERE bot=%s)",
                $bot
            ));

            return rest_ensure_response(array(
                'total_unvisited' => $total_unvisited,
                'returned' => count($results),
                'bot' => $bot,
                'posts' => $results,
            ));
        },
        'permission_callback' => $auth_check,
    ));

    // Log indexing push results
    register_rest_route('wpsm/v1', '/log-push', array(
        'methods' => 'POST',
        'callback' => function($request) {
            global $wpdb;
            $items = $request->get_json_params();

            if (!is_array($items) || empty($items)) {
                return new WP_Error('invalid_data', 'Expected JSON array of push results', array('status' => 400));
            }

            $inserted = 0;
            foreach ($items as $item) {
                if (empty($item['post_id']) || empty($item['url'])) continue;
                $wpdb->insert($wpdb->prefix . 'wpsm_indexing_log', array(
                    'push_time' => current_time('mysql'),
                    'post_id' => intval($item['post_id']),
                    'url' => substr($item['url'], 0, 500),
                    'status' => sanitize_text_field($item['status'] ?? 'sent'),
                    'response_code' => intval($item['response_code'] ?? 0),
                    'response_body' => substr($item['response_body'] ?? '', 0, 1000),
                ));
                $inserted++;
            }

            // Cleanup
            $limit = (int) get_option('wpsm_indexing_log_limit', 50000);
            $threshold = (int) ($limit * 1.1);
            $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpsm_indexing_log");
            if ($count > $threshold) {
                $cutoff_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}wpsm_indexing_log ORDER BY id DESC LIMIT 1 OFFSET %d", $limit
                ));
                if ($cutoff_id) {
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wpsm_indexing_log WHERE id < %d", $cutoff_id));
                }
            }

            return rest_ensure_response(array('logged' => $inserted));
        },
        'permission_callback' => $auth_check,
    ));
});

// ============================================================
// XLSX EXPORT
// ============================================================

add_action('admin_post_wpsm_export_xlsx', 'wpsm_handle_export_xlsx');

function wpsm_handle_export_xlsx() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    check_admin_referer('wpsm_export');

    global $wpdb;
    $prefix = $wpdb->prefix;

    // Gather all data
    $sheets = array();

    // 1. Bot Log
    $bot_rows = $wpdb->get_results("SELECT visit_time, bot, url, post_id, status_code, ip, ua FROM {$prefix}wpsm_bot_log ORDER BY visit_time DESC", ARRAY_A);
    $sheets[] = array(
        'name' => 'Bot Log',
        'headers' => array('Visit Time', 'Bot', 'URL', 'Post ID', 'Status Code', 'IP', 'User Agent'),
        'widths' => array(20, 18, 60, 10, 12, 16, 50),
        'rows' => $bot_rows,
    );

    // 2. Error Log (404s)
    $err_rows = $wpdb->get_results("SELECT error_time, url, status_code, referer, ua, is_bot, bot_name FROM {$prefix}wpsm_error_log ORDER BY error_time DESC", ARRAY_A);
    $sheets[] = array(
        'name' => '404 Errors',
        'headers' => array('Time', 'URL', 'Status', 'Referer', 'User Agent', 'Is Bot', 'Bot Name'),
        'widths' => array(20, 60, 8, 50, 50, 8, 18),
        'rows' => $err_rows,
    );

    // 3. Post Stats (with post titles)
    $stats_rows = $wpdb->get_results("SELECT s.post_id, p.post_title, s.bot, s.visit_count, s.first_visit, s.last_visit
        FROM {$prefix}wpsm_post_stats s
        LEFT JOIN {$wpdb->posts} p ON s.post_id = p.ID
        ORDER BY s.last_visit DESC", ARRAY_A);
    $sheets[] = array(
        'name' => 'Post Stats',
        'headers' => array('Post ID', 'Post Title', 'Bot', 'Visit Count', 'First Visit', 'Last Visit'),
        'widths' => array(10, 50, 18, 12, 20, 20),
        'rows' => $stats_rows,
    );

    // 4. Unvisited Posts (by Googlebot)
    $unvisited_rows = $wpdb->get_results("SELECT p.ID as post_id, p.post_title, p.post_name as slug, p.post_date,
        DATEDIFF(NOW(), p.post_date) as days_since_published
        FROM {$wpdb->posts} p
        WHERE p.post_status='publish' AND p.post_type='post'
        AND p.ID NOT IN (SELECT post_id FROM {$prefix}wpsm_post_stats WHERE bot='Googlebot')
        ORDER BY p.post_date DESC", ARRAY_A);
    $sheets[] = array(
        'name' => 'Unvisited (Google)',
        'headers' => array('Post ID', 'Post Title', 'Slug', 'Published', 'Days Since Published'),
        'widths' => array(10, 50, 40, 20, 20),
        'rows' => $unvisited_rows,
    );

    // 5. Indexing API Log
    $idx_rows = $wpdb->get_results("SELECT l.push_time, l.post_id, p.post_title, l.url, l.status, l.response_code, l.response_body
        FROM {$prefix}wpsm_indexing_log l
        LEFT JOIN {$wpdb->posts} p ON l.post_id = p.ID
        ORDER BY l.push_time DESC", ARRAY_A);
    $sheets[] = array(
        'name' => 'Indexing API',
        'headers' => array('Push Time', 'Post ID', 'Post Title', 'URL', 'Status', 'HTTP Code', 'Response'),
        'widths' => array(20, 10, 40, 60, 10, 10, 40),
        'rows' => $idx_rows,
    );

    // 6. Summary
    $total_posts = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='post'");
    $crawled_google = (int)$wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$prefix}wpsm_post_stats WHERE bot LIKE 'Google%'");
    $summary_rows = array(
        array('metric' => 'Export Date', 'value' => current_time('Y-m-d H:i:s')),
        array('metric' => 'Site URL', 'value' => home_url('/')),
        array('metric' => 'Published Posts', 'value' => $total_posts),
        array('metric' => 'Crawled by Google', 'value' => $crawled_google),
        array('metric' => 'Uncrawled by Google', 'value' => $total_posts - $crawled_google),
        array('metric' => 'Coverage %', 'value' => $total_posts > 0 ? round($crawled_google / $total_posts * 100, 1) . '%' : 'N/A'),
        array('metric' => 'Bot Log Entries', 'value' => count($bot_rows)),
        array('metric' => '404 Error Entries', 'value' => count($err_rows)),
        array('metric' => 'Indexing API Pushes', 'value' => count($idx_rows)),
    );
    array_unshift($sheets, array(
        'name' => 'Summary',
        'headers' => array('Metric', 'Value'),
        'widths' => array(25, 40),
        'rows' => $summary_rows,
    ));

    // Generate XLSX
    $filename = 'site-monitor-' . date('Y-m-d') . '.xlsx';
    $xlsx = wpsm_generate_xlsx($sheets);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($xlsx));
    header('Cache-Control: max-age=0');
    echo $xlsx;
    exit;
}

function wpsm_generate_xlsx($sheets) {
    $tmp = tempnam(sys_get_temp_dir(), 'wpsm');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    // [Content_Types].xml
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $ct .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $ct .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $ct .= '<Default Extension="xml" ContentType="application/xml"/>';
    $ct .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $ct .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $ct .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
    foreach ($sheets as $i => $s) {
        $ct .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $ct .= '</Types>';
    $zip->addFromString('[Content_Types].xml', $ct);

    // _rels/.rels
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
    $rels .= '</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);

    // xl/_rels/workbook.xml.rels
    $wbrels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $wbrels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheets as $i => $s) {
        $wbrels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
    }
    $wbrels .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $wbrels .= '<Relationship Id="rId' . (count($sheets) + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
    $wbrels .= '</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbrels);

    // Collect all shared strings
    $strings = array();
    $string_index = array();
    $get_si = function($val) use (&$strings, &$string_index) {
        $val = (string)$val;
        if (!isset($string_index[$val])) {
            $string_index[$val] = count($strings);
            $strings[] = $val;
        }
        return $string_index[$val];
    };

    // Pre-collect strings
    foreach ($sheets as $sheet) {
        foreach ($sheet['headers'] as $h) $get_si($h);
        foreach ($sheet['rows'] as $row) {
            foreach ($row as $v) $get_si((string)$v);
        }
    }

    // xl/sharedStrings.xml
    $ss = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $ss .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
    foreach ($strings as $s) {
        $ss .= '<si><t>' . htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
    }
    $ss .= '</sst>';
    $zip->addFromString('xl/sharedStrings.xml', $ss);

    // xl/styles.xml (header bold + auto-filter friendly)
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $styles .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $styles .= '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>';
    $styles .= '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF4472C4"/></patternFill></fill></fills>';
    $styles .= '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>';
    $styles .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
    $styles .= '<cellXfs count="3">';
    $styles .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
    $styles .= '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>';
    $styles .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1"/></xf>';
    $styles .= '</cellXfs>';
    $styles .= '</styleSheet>';
    $zip->addFromString('xl/styles.xml', $styles);

    // Column letter helper
    $col_letter = function($idx) {
        $l = '';
        $idx++;
        while ($idx > 0) {
            $idx--;
            $l = chr(65 + ($idx % 26)) . $l;
            $idx = intval($idx / 26);
        }
        return $l;
    };

    // Worksheets
    foreach ($sheets as $si => $sheet) {
        $num_cols = count($sheet['headers']);
        $num_rows = count($sheet['rows']) + 1; // +1 for header
        $last_col = $col_letter($num_cols - 1);

        $ws = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $ws .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // 1. sheetViews (must come first)
        $ws .= '<sheetViews><sheetView tabSelected="' . ($si === 0 ? '1' : '0') . '" workbookViewId="0">';
        $ws .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>';
        $ws .= '</sheetView></sheetViews>';

        // 2. cols
        $ws .= '<cols>';
        foreach ($sheet['widths'] as $ci => $w) {
            $ws .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $ws .= '</cols>';

        // 3. sheetData
        $ws .= '<sheetData>';

        // Header row (style=1 = bold white on blue)
        $ws .= '<row r="1">';
        foreach ($sheet['headers'] as $ci => $h) {
            $ref = $col_letter($ci) . '1';
            $ws .= '<c r="' . $ref . '" t="s" s="1"><v>' . $get_si($h) . '</v></c>';
        }
        $ws .= '</row>';

        // Data rows
        foreach ($sheet['rows'] as $ri => $row) {
            $row_num = $ri + 2;
            $ws .= '<row r="' . $row_num . '">';
            $ci = 0;
            foreach ($row as $val) {
                $ref = $col_letter($ci) . $row_num;
                $val = (string)$val;
                if (is_numeric($val) && strlen($val) < 15 && strpos($val, 'E') === false) {
                    $ws .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
                } else {
                    $ws .= '<c r="' . $ref . '" t="s"><v>' . $get_si($val) . '</v></c>';
                }
                $ci++;
            }
            $ws .= '</row>';
        }
        $ws .= '</sheetData>';

        // 4. autoFilter (must come after sheetData)
        $ws .= '<autoFilter ref="A1:' . $last_col . $num_rows . '"/>';

        $ws .= '</worksheet>';
        $zip->addFromString('xl/worksheets/sheet' . ($si + 1) . '.xml', $ws);
    }

    // xl/workbook.xml
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $wb .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $wb .= '<sheets>';
    foreach ($sheets as $i => $s) {
        $wb .= '<sheet name="' . htmlspecialchars($s['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
    }
    $wb .= '</sheets></workbook>';
    $zip->addFromString('xl/workbook.xml', $wb);

    $zip->close();
    $data = file_get_contents($tmp);
    unlink($tmp);
    return $data;
}
