<?php
/**
 * WP Archiver Cache Management System - Fixed Version
 */

if (!defined('ABSPATH')) {
    exit;
}

class Archiver_Cache {

    private $table_name;
    private $cache_durations = [
        'hot' => HOUR_IN_SECONDS,
        'warm' => DAY_IN_SECONDS,
        'cold' => WEEK_IN_SECONDS,
        'frozen' => MONTH_IN_SECONDS
    ];

    // Service-specific cache prefix
    private $cache_prefix = 'archiver_';

    // Batch processing optimization
    private $batch_size = 10;
    private $max_retries = 3;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'archiver_cache';
        $this->batch_size = get_option('archiver_batch_size', 10);
    }

    /**
     * Get available services with fallback
     */
    private function get_available_services() {
        if (defined('ARCHIVER_SERVICES') && is_array(ARCHIVER_SERVICES)) {
            return ARCHIVER_SERVICES;
        }

        // Fallback services if constant is not defined
        return array(
            'wenpai' => array(
                'name' => 'WenPai Archive',
                'save_url' => 'https://web.wenpai.net/save/',
                'fetch_url' => 'https://web.wenpai.net/cdx/',
                'view_url' => 'https://web.wenpai.net/web/',
                'enabled' => true
            ),
            'wayback' => array(
                'name' => 'Internet Archive',
                'save_url' => 'https://web.archive.org/save/',
                'fetch_url' => 'https://web.archive.org/cdx/search/cdx',
                'view_url' => 'https://web.archive.org/web/',
                'enabled' => true
            ),
            'archive_today' => array(
                'name' => 'Archive.today',
                'save_url' => 'https://archive.today/?run=1&url=',
                'fetch_url' => 'https://archive.today/',
                'view_url' => 'https://archive.today/',
                'enabled' => false
            )
        );
    }

    /**
     * Get snapshot data (supports multiple services)
     */
    public function get_snapshots($url, $service = null, $force_refresh = false) {
        if (!get_option('archiver_cache_enabled', true)) {
            return $this->fetch_from_archive_service($url, $service);
        }

        // If no service specified, use primary service
        if (!$service) {
            $service = get_option('archiver_primary_service', 'wenpai');
        }

        $url_hash = md5($url . '_' . $service);

        // 1. Check memory cache
        if (!$force_refresh) {
            $memory_key = $this->cache_prefix . 'snap_' . $url_hash;
            $cached_data = wp_cache_get($memory_key, 'archiver');

            if ($cached_data !== false) {
                $this->update_access_stats($url_hash, $service);
                return $cached_data;
            }
        }

        // 2. Check database cache
        if (!$force_refresh) {
            $db_cache = $this->get_from_database($url_hash, $service);
            if ($db_cache !== false) {
                $memory_key = $this->cache_prefix . 'snap_' . $url_hash;
                wp_cache_set($memory_key, $db_cache, 'archiver', HOUR_IN_SECONDS);
                return $db_cache;
            }
        }

        // 3. Add to background queue (with service info)
        $this->queue_for_update($url, $service, false);

        // 4. Return stale data or empty array
        return $this->get_stale_data($url_hash, $service) ?: [];
    }

    /**
     * Get cache from database
     */
    private function get_from_database($url_hash, $service = 'wenpai') {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
            WHERE url_hash = %s AND service = %s AND expires_at > NOW() AND status = 'active'",
            $url_hash, $service
        ));

        if (!$row) {
            return false;
        }

        $this->update_access_stats($url_hash, $service);
        $data = maybe_unserialize($row->snapshot_data);

        // Promote cache level based on access frequency
        if ($row->api_calls_saved > 10 && $row->cache_type !== 'hot') {
            $this->promote_cache($url_hash, $service, 'hot');
        }

        return $data;
    }

    /**
     * Get stale data
     */
    private function get_stale_data($url_hash, $service = 'wenpai') {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT snapshot_data FROM {$this->table_name}
            WHERE url_hash = %s AND service = %s
            ORDER BY created_at DESC
            LIMIT 1",
            $url_hash, $service
        ));

        return $row ? maybe_unserialize($row->snapshot_data) : false;
    }

    /**
     * Save snapshot data
     */
    public function save_snapshots($url, $snapshots, $service = 'wenpai') {
        global $wpdb;

        $url_hash = md5($url . '_' . $service);
        $cache_type = $this->determine_cache_type($url);
        $cache_duration = get_option('archiver_cache_duration', $this->cache_durations[$cache_type]);
        $expires_at = date('Y-m-d H:i:s', time() + $cache_duration);

        $data = [
            'url' => $url,
            'url_hash' => $url_hash,
            'service' => $service,
            'snapshot_data' => maybe_serialize($snapshots),
            'snapshot_count' => is_array($snapshots) ? count($snapshots) : 0,
            'cache_type' => $cache_type,
            'expires_at' => $expires_at,
            'last_accessed' => current_time('mysql'),
            'created_at' => current_time('mysql'),
            'status' => 'active'
        ];

        $result = $wpdb->replace($this->table_name, $data);

        if ($result !== false) {
            // Update memory cache
            $memory_key = $this->cache_prefix . 'snap_' . $url_hash;
            wp_cache_set($memory_key, $snapshots, 'archiver', HOUR_IN_SECONDS);

            // Update statistics
            $this->increment_archived_count();
        }

        return $result !== false;
    }

    /**
     * Determine cache type
     */
    private function determine_cache_type($url) {
        // Homepage: hot data
        if ($url === home_url() || $url === home_url('/')) {
            return 'hot';
        }

        $post_id = url_to_postid($url);
        if ($post_id) {
            $post = get_post($post_id);
            if ($post) {
                $days_old = (time() - strtotime($post->post_date)) / DAY_IN_SECONDS;

                if ($days_old < 7) return 'hot';
                elseif ($days_old < 30) return 'warm';
                elseif ($days_old < 365) return 'cold';
            }
        }

        return 'frozen';
    }

    /**
     * Add to update queue (supports multiple services)
     */
    public function queue_for_update($url, $service = null, $priority = false) {
        $queue = get_option('archiver_background_queue', []);

        // If no service specified, use all enabled services
        if (!$service) {
            $enabled_services = get_option('archiver_services', array('wenpai' => true));
            foreach ($enabled_services as $service_id => $enabled) {
                if ($enabled) {
                    $this->add_to_queue($queue, $url, $service_id, $priority);
                }
            }
        } else {
            $this->add_to_queue($queue, $url, $service, $priority);
        }

        // Limit queue size
        $max_queue_size = get_option('archiver_max_queue_size', 500);
        $queue = array_slice($queue, 0, $max_queue_size);

        update_option('archiver_background_queue', $queue);

        // Trigger background processing
        if (!wp_next_scheduled('archiver_process_background_queue')) {
            wp_schedule_single_event(time() + 10, 'archiver_process_background_queue');
        }
    }

    /**
     * Add single item to queue
     */
    private function add_to_queue(&$queue, $url, $service, $priority) {
        $item = array('url' => $url, 'service' => $service, 'retries' => 0);

        // Check if already exists
        foreach ($queue as $existing) {
            if (is_array($existing) && $existing['url'] === $url && $existing['service'] === $service) {
                return;
            }
        }

        if ($priority) {
            array_unshift($queue, $item);
        } else {
            $queue[] = $item;
        }
    }

    /**
     * Process background queue
     */
    public function process_background_queue() {
        $queue = get_option('archiver_background_queue', []);

        if (empty($queue)) {
            return;
        }

        // Process batch URLs each time
        $batch = array_splice($queue, 0, $this->batch_size);
        $failed_items = array();

        foreach ($batch as $item) {
            if (!is_array($item)) {
                // Compatible with old format
                $item = array('url' => $item, 'service' => 'wenpai', 'retries' => 0);
            }

            $success = $this->fetch_and_cache_snapshots($item['url'], $item['service']);

            if (!$success) {
                $item['retries'] = (isset($item['retries']) ? $item['retries'] : 0) + 1;
                if ($item['retries'] < $this->max_retries) {
                    $failed_items[] = $item;
                } else {
                    $this->increment_failed_count();
                }
            }
        }

        // Re-add failed items to end of queue
        $queue = array_merge($queue, $failed_items);

        // Update queue
        update_option('archiver_background_queue', $queue);

        // Continue scheduling if more items pending
        if (!empty($queue)) {
            wp_schedule_single_event(time() + 30, 'archiver_process_background_queue');
        }
    }

    /**
     * Fetch and cache snapshots from archive service
     */
    public function fetch_and_cache_snapshots($url, $service = 'wenpai') {
        $snapshots = $this->fetch_from_archive_service($url, $service);

        if ($snapshots !== false && !empty($snapshots)) {
            $this->save_snapshots($url, $snapshots, $service);
            return true;
        }

        return false;
    }

    /**
     * Fetch data from archive service
     */
    private function fetch_from_archive_service($url, $service = null) {
        if (!$service) {
            $service = get_option('archiver_primary_service', 'wenpai');
        }

        $services = $this->get_available_services();
        if (!isset($services[$service])) {
            return false;
        }

        switch ($service) {
            case 'wayback':
                return $this->fetch_from_wayback($url);

            case 'wenpai':
                return $this->fetch_from_wenpai($url);

            case 'archive_today':
                return $this->fetch_from_archive_today($url);

            default:
                return false;
        }
    }

    /**
     * Fetch data from Wayback Machine
     */
    private function fetch_from_wayback($url) {
        $services = $this->get_available_services();

        $api_url = add_query_arg([
            'url' => $url,
            'output' => 'json',
            'limit' => 20,
            'fl' => 'timestamp,original,statuscode,mimetype,length'
        ], $services['wayback']['fetch_url']);

        $response = wp_remote_get($api_url, [
            'timeout' => 30,
            'sslverify' => true,
            'headers' => [
                'User-Agent' => 'WP-Archiver/' . ARCHIVER_VERSION . ' (WordPress/' . get_bloginfo('version') . ')'
            ]
        ]);

        if (is_wp_error($response)) {
            if (function_exists('archiver_handle_error')) {
                archiver_handle_error('Wayback API error: ' . $response->get_error_message());
            }
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            if (function_exists('archiver_handle_error')) {
                archiver_handle_error('Wayback API returned status: ' . $response_code);
            }
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return false;
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (function_exists('archiver_handle_error')) {
                archiver_handle_error('Wayback API JSON decode error: ' . json_last_error_msg());
            }
            return false;
        }

        if (empty($data) || !is_array($data)) {
            return array(); // Return empty array instead of false
        }

        return $this->process_wayback_response($data);
    }

    /**
     * Fetch data from WenPai Archive
     */
    private function fetch_from_wenpai($url) {
        $services = $this->get_available_services();

        $api_url = add_query_arg([
            'url' => $url,
            'output' => 'json',
            'limit' => 20,
            'fl' => 'timestamp,original,statuscode'
        ], $services['wenpai']['fetch_url']);

        $response = wp_remote_get($api_url, [
            'timeout' => 30,
            'sslverify' => false, // WenPai might use self-signed certificate
            'headers' => [
                'User-Agent' => 'WP-Archiver/' . ARCHIVER_VERSION . ' (WordPress/' . get_bloginfo('version') . ')'
            ]
        ]);

        if (is_wp_error($response)) {
            if (function_exists('archiver_handle_error')) {
                archiver_handle_error('WenPai API error: ' . $response->get_error_message());
            }
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            if (function_exists('archiver_handle_error')) {
                archiver_handle_error('WenPai API returned status: ' . $response_code);
            }
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return array(); // Return empty array instead of false
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try to parse as plain text (CDX format)
            return $this->parse_cdx_response($body);
        }

        if (empty($data) || !is_array($data)) {
            return array();
        }

        return $this->process_wayback_response($data); // Use same processing method
    }

    /**
     * Parse CDX format response
     */
    private function parse_cdx_response($body) {
        $lines = explode("\n", trim($body));
        if (empty($lines)) {
            return array();
        }

        $snapshots = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 3) {
                $snapshots[] = array(
                    'timestamp' => $parts[1],
                    'original' => $parts[2],
                    'statuscode' => isset($parts[4]) ? $parts[4] : '200'
                );
            }
        }

        return array_reverse(array_slice($snapshots, -20)); // Return latest 20
    }

    /**
     * Process Wayback Machine response
     */
    private function process_wayback_response($data) {
        if (count($data) < 2) {
            return array();
        }

        $headers = array_shift($data);
        $snapshots = array();

        // Only take latest 20 entries
        $data = array_slice($data, -20);

        foreach ($data as $row) {
            $snapshot = array();
            foreach ($row as $i => $value) {
                if (isset($headers[$i])) {
                    $snapshot[$headers[$i]] = $value;
                }
            }

            // Filter out failed snapshots
            if (isset($snapshot['statuscode']) && $snapshot['statuscode'] === '200') {
                $snapshots[] = $snapshot;
            } elseif (!isset($snapshot['statuscode'])) {
                // Include if no status code (assume success)
                $snapshots[] = $snapshot;
            }
        }

        return array_reverse($snapshots);
    }

    /**
     * Fetch data from Archive.today
     */
    private function fetch_from_archive_today($url) {
        // Archive.today doesn't have official API, return empty array
        // But can still trigger save
        return array();
    }

    /**
     * Update access statistics
     */
    private function update_access_stats($url_hash, $service = 'wenpai') {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name}
            SET last_accessed = NOW(),
                api_calls_saved = api_calls_saved + 1
            WHERE url_hash = %s AND service = %s",
            $url_hash, $service
        ));
    }

    /**
     * Promote cache level
     */
    private function promote_cache($url_hash, $service, $new_type) {
        global $wpdb;

        $cache_duration = get_option('archiver_cache_duration', $this->cache_durations[$new_type]);
        $new_expires = date('Y-m-d H:i:s', time() + $cache_duration);

        $wpdb->update(
            $this->table_name,
            [
                'cache_type' => $new_type,
                'expires_at' => $new_expires
            ],
            ['url_hash' => $url_hash, 'service' => $service]
        );
    }

    /**
     * Clean expired cache
     */
    public function cleanup_expired_cache() {
        global $wpdb;

        // Keep data from last 30 days, even if expired
        $cutoff_date = date('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name}
            WHERE expires_at < %s AND created_at < %s",
            current_time('mysql'), $cutoff_date
        ));

        // Clean orphaned memory cache
        wp_cache_flush_group('archiver');

        return $deleted;
    }

    /**
     * Get cache statistics
     */
    public function get_cache_stats() {
        global $wpdb;

        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total_entries,
                SUM(CASE WHEN api_calls_saved IS NOT NULL THEN api_calls_saved ELSE 0 END) as total_api_saves,
                SUM(CASE WHEN cache_type = 'hot' THEN 1 ELSE 0 END) as hot_entries,
                SUM(CASE WHEN cache_type = 'warm' THEN 1 ELSE 0 END) as warm_entries,
                SUM(CASE WHEN cache_type = 'cold' THEN 1 ELSE 0 END) as cold_entries,
                SUM(CASE WHEN cache_type = 'frozen' THEN 1 ELSE 0 END) as frozen_entries,
                COUNT(DISTINCT service) as services_used
            FROM {$this->table_name}
            WHERE status = 'active'"
        );

        // Add service-level statistics
        if ($stats) {
            $stats->service_stats = $wpdb->get_results(
                "SELECT service, COUNT(*) as count, SUM(CASE WHEN api_calls_saved IS NOT NULL THEN api_calls_saved ELSE 0 END) as saves
                FROM {$this->table_name}
                WHERE status = 'active'
                GROUP BY service"
            );
        }

        return $stats;
    }

    /**
     * Preheat cache
     */
    public function preheat_cache($post_types = null) {
        if (!$post_types) {
            $post_types = get_option('archiver_post_types', ['post', 'page']);
        }

        $posts = get_posts([
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'date_query' => [
                'after' => '30 days ago'
            ],
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids'
        ]);

        $enabled_services = get_option('archiver_services', array('wenpai' => true));
        $count = 0;

        foreach ($posts as $post_id) {
            $url = get_permalink($post_id);
            if ($url) {
                foreach ($enabled_services as $service_id => $enabled) {
                    if ($enabled) {
                        $this->queue_for_update($url, $service_id);
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Get cache size
     */
    public function get_cache_size() {
        global $wpdb;

        $result = $wpdb->get_var(
            "SELECT ROUND(((data_length + index_length)), 2) as size
            FROM information_schema.TABLES
            WHERE table_schema = '" . DB_NAME . "'
            AND table_name = '" . $this->table_name . "'"
        );

        return $result ? $result : 0;
    }

    /**
     * Optimize cache table
     */
    public function optimize_cache_table() {
        global $wpdb;

        // Optimize table
        $wpdb->query("OPTIMIZE TABLE {$this->table_name}");

        // Update statistics
        $wpdb->query("ANALYZE TABLE {$this->table_name}");
    }

    /**
     * Increment archived count
     */
    private function increment_archived_count() {
        $count = get_option('archiver_total_archived', 0);
        update_option('archiver_total_archived', $count + 1);
    }

    /**
     * Increment failed count
     */
    private function increment_failed_count() {
        $count = get_option('archiver_failed_snapshots', 0);
        update_option('archiver_failed_snapshots', $count + 1);
    }

    /**
     * Get service health status
     */
    public function get_service_health($service = null) {
        global $wpdb;

        $where = $service ? $wpdb->prepare(" AND service = %s", $service) : "";

        $result = $wpdb->get_row(
            "SELECT
                COUNT(*) as total_attempts,
                SUM(CASE WHEN snapshot_count > 0 THEN 1 ELSE 0 END) as successful,
                AVG(CASE WHEN api_calls_saved IS NOT NULL THEN api_calls_saved ELSE 0 END) as avg_saves
            FROM {$this->table_name}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)" . $where
        );

        if ($result && $result->total_attempts > 0) {
            $result->success_rate = round(($result->successful / $result->total_attempts) * 100, 2);
        }

        return $result;
    }
}

// Register cleanup task
add_action('archiver_cleanup_cache', function() {
    if (class_exists('Archiver_Cache')) {
        $cache = new Archiver_Cache();
        $cleaned = $cache->cleanup_expired_cache();

        if ($cleaned > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Archiver] Cleaned ' . $cleaned . ' expired cache entries');
        }

        // Optimize table once a week
        if (date('w') == 0) { // Sunday
            $cache->optimize_cache_table();
        }
    }
});
