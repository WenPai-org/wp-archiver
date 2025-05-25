<?php
/**
 * WP Archiver 缓存管理系统
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

    // 添加服务特定的缓存前缀
    private $cache_prefix = 'archiver_';

    // 批处理优化
    private $batch_size = 3;
    private $max_retries = 3;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'archiver_cache';
        $this->batch_size = apply_filters('archiver_batch_size', 3);
    }

    /**
     * 获取快照数据（支持多服务）
     */
    public function get_snapshots($url, $service = null, $force_refresh = false) {
        if (!get_option('archiver_cache_enabled', true)) {
            return $this->fetch_from_archive_service($url, $service);
        }

        // 如果没有指定服务，使用主服务
        if (!$service) {
            $service = get_option('archiver_primary_service', 'wenpai');
        }

        $url_hash = md5($url . '_' . $service);

        // 1. 检查内存缓存
        if (!$force_refresh) {
            $memory_key = $this->cache_prefix . 'snap_' . $url_hash;
            $cached_data = wp_cache_get($memory_key, 'archiver');

            if ($cached_data !== false) {
                $this->update_access_stats($url_hash, $service);
                return $cached_data;
            }
        }

        // 2. 检查数据库缓存
        if (!$force_refresh) {
            $db_cache = $this->get_from_database($url_hash, $service);
            if ($db_cache !== false) {
                wp_cache_set($memory_key, $db_cache, 'archiver', HOUR_IN_SECONDS);
                return $db_cache;
            }
        }

        // 3. 添加到后台队列（带服务信息）
        $this->queue_for_update($url, $service, false);

        // 4. 返回过期数据或空数组
        return $this->get_stale_data($url_hash, $service) ?: [];
    }

    /**
     * 从数据库获取缓存
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

        // 根据访问频率提升缓存级别
        if ($row->api_calls_saved > 10 && $row->cache_type !== 'hot') {
            $this->promote_cache($url_hash, $service, 'hot');
        }

        return $data;
    }

    /**
     * 获取过期数据
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
     * 保存快照数据
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

        $wpdb->replace($this->table_name, $data);

        // 更新内存缓存
        $memory_key = $this->cache_prefix . 'snap_' . $url_hash;
        wp_cache_set($memory_key, $snapshots, 'archiver', HOUR_IN_SECONDS);

        // 更新统计
        $this->increment_archived_count();

        return true;
    }

    /**
     * 确定缓存类型
     */
    private function determine_cache_type($url) {
        // 首页：热数据
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
     * 添加到更新队列（支持多服务）
     */
    public function queue_for_update($url, $service = null, $priority = false) {
        $queue = get_option('archiver_background_queue', []);

        // 如果没有指定服务，使用所有启用的服务
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

        // 限制队列大小
        $max_queue_size = apply_filters('archiver_queue_limit', 100);
        $queue = array_slice($queue, 0, $max_queue_size);

        update_option('archiver_background_queue', $queue);

        // 触发后台处理
        if (!wp_next_scheduled('archiver_process_background_queue')) {
            wp_schedule_single_event(time() + 10, 'archiver_process_background_queue');
        }
    }

    /**
     * 添加单个项目到队列
     */
    private function add_to_queue(&$queue, $url, $service, $priority) {
        $item = array('url' => $url, 'service' => $service, 'retries' => 0);

        // 检查是否已存在
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
     * 处理后台队列
     */
    public function process_background_queue() {
        $queue = get_option('archiver_background_queue', []);

        if (empty($queue)) {
            return;
        }

        // 每次处理批量URL
        $batch = array_splice($queue, 0, $this->batch_size);
        $failed_items = array();

        foreach ($batch as $item) {
            if (!is_array($item)) {
                // 兼容旧格式
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

        // 将失败的项目重新加入队列末尾
        $queue = array_merge($queue, $failed_items);

        // 更新队列
        update_option('archiver_background_queue', $queue);

        // 如果还有待处理项，继续安排
        if (!empty($queue)) {
            wp_schedule_single_event(time() + 30, 'archiver_process_background_queue');
        }
    }

    /**
     * 从存档服务获取并缓存数据
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
     * 从存档服务获取数据
     */
    private function fetch_from_archive_service($url, $service = null) {
        if (!$service) {
            $service = get_option('archiver_primary_service', 'wenpai');
        }

        $services = ARCHIVER_SERVICES;
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
     * 从 Wayback Machine 获取数据
     */
    private function fetch_from_wayback($url) {
        $api_url = add_query_arg([
            'url' => $url,
            'output' => 'json',
            'limit' => 10
        ], ARCHIVER_SERVICES['wayback']['fetch_url']);

        $response = wp_remote_get($api_url, [
            'timeout' => 30,
            'sslverify' => true,
            'headers' => [
                'User-Agent' => 'WP-Archiver/' . ARCHIVER_VERSION
            ]
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !is_array($data)) {
            return false;
        }

        return $this->process_wayback_response($data);
    }

    /**
     * 从 WenPai Archive 获取数据
     */
    private function fetch_from_wenpai($url) {
        $api_url = add_query_arg([
            'url' => $url,
            'output' => 'json',
        ], ARCHIVER_SERVICES['wenpai']['fetch_url']);

        $response = wp_remote_get($api_url, [
            'timeout' => 30,
            'sslverify' => false, // WenPai 可能使用自签名证书
            'headers' => [
                'User-Agent' => 'WP-Archiver/' . ARCHIVER_VERSION
            ]
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !is_array($data)) {
            return false;
        }

        return $this->process_wayback_response($data); // 使用相同的处理方法
    }

    /**
     * 处理 Wayback Machine 响应
     */
    private function process_wayback_response($data) {
        if (count($data) < 2) {
            return [];
        }

        $headers = array_shift($data);
        $snapshots = [];

        // 只取最新的10条
        $data = array_slice($data, -10);

        foreach ($data as $row) {
            $snapshot = [];
            foreach ($row as $i => $value) {
                if (isset($headers[$i])) {
                    $snapshot[$headers[$i]] = $value;
                }
            }
            $snapshots[] = $snapshot;
        }

        return array_reverse($snapshots);
    }

    /**
     * 从 Archive.today 获取数据
     */
    private function fetch_from_archive_today($url) {
        // Archive.today 没有官方 API，返回空数组
        // 但仍可以触发保存
        return array();
    }

    /**
     * 更新访问统计
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
     * 提升缓存级别
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
     * 清理过期缓存
     */
    public function cleanup_expired_cache() {
        global $wpdb;

        // 保留最近30天的数据，即使已过期
        $cutoff_date = date('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name}
            WHERE expires_at < %s AND created_at < %s",
            current_time('mysql'), $cutoff_date
        ));

        // 清理孤立的内存缓存
        wp_cache_flush_group('archiver');

        return $deleted;
    }

    /**
     * 获取缓存统计
     */
    public function get_cache_stats() {
        global $wpdb;

        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total_entries,
                SUM(api_calls_saved) as total_api_saves,
                SUM(CASE WHEN cache_type = 'hot' THEN 1 ELSE 0 END) as hot_entries,
                SUM(CASE WHEN cache_type = 'warm' THEN 1 ELSE 0 END) as warm_entries,
                SUM(CASE WHEN cache_type = 'cold' THEN 1 ELSE 0 END) as cold_entries,
                SUM(CASE WHEN cache_type = 'frozen' THEN 1 ELSE 0 END) as frozen_entries,
                COUNT(DISTINCT service) as services_used
            FROM {$this->table_name}
            WHERE status = 'active'"
        );

        // 添加服务级别的统计
        if ($stats) {
            $stats->service_stats = $wpdb->get_results(
                "SELECT service, COUNT(*) as count, SUM(api_calls_saved) as saves
                FROM {$this->table_name}
                WHERE status = 'active'
                GROUP BY service"
            );
        }

        return $stats;
    }

    /**
     * 预热缓存
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
            foreach ($enabled_services as $service_id => $enabled) {
                if ($enabled) {
                    $this->queue_for_update($url, $service_id);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * 获取缓存大小
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
     * 优化缓存表
     */
    public function optimize_cache_table() {
        global $wpdb;

        // 优化表
        $wpdb->query("OPTIMIZE TABLE {$this->table_name}");

        // 更新统计信息
        $wpdb->query("ANALYZE TABLE {$this->table_name}");
    }

    /**
     * 增加存档计数
     */
    private function increment_archived_count() {
        $count = get_option('archiver_total_archived', 0);
        update_option('archiver_total_archived', $count + 1);
    }

    /**
     * 增加失败计数
     */
    private function increment_failed_count() {
        $count = get_option('archiver_failed_snapshots', 0);
        update_option('archiver_failed_snapshots', $count + 1);
    }

    /**
     * 获取服务健康状态
     */
    public function get_service_health($service = null) {
        global $wpdb;

        $where = $service ? $wpdb->prepare(" AND service = %s", $service) : "";

        $result = $wpdb->get_row(
            "SELECT
                COUNT(*) as total_attempts,
                SUM(CASE WHEN snapshot_count > 0 THEN 1 ELSE 0 END) as successful,
                AVG(api_calls_saved) as avg_saves
            FROM {$this->table_name}
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)" . $where
        );

        if ($result && $result->total_attempts > 0) {
            $result->success_rate = round(($result->successful / $result->total_attempts) * 100, 2);
        }

        return $result;
    }
}

// 注册清理任务
add_action('archiver_cleanup_cache', function() {
    $cache = new Archiver_Cache();
    $cleaned = $cache->cleanup_expired_cache();

    if ($cleaned > 0) {
        error_log('[WP Archiver] Cleaned ' . $cleaned . ' expired cache entries');
    }

    // 每周优化一次表
    if (date('w') == 0) { // 周日
        $cache->optimize_cache_table();
    }
});
