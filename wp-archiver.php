<?php
/**
 * Plugin Name:       WP Archiver
 * Plugin URI:        http://wenpai.org/plugins/wp-archiver
 * Description:       Archive your content using multiple archive services with advanced caching.
 * Version:           2.0.0
 * Author:            WenPai.org
 * Author URI:        https://wenpai.org/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       archiver
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

// 定义常量
define('ARCHIVER_VERSION', '2.0.0');
define('ARCHIVER_PLUGIN_DIR_URL', plugin_dir_url(__FILE__));
define('ARCHIVER_PLUGIN_DIR_PATH', plugin_dir_path(__FILE__));
define('ARCHIVER_PLUGIN_BASENAME', plugin_basename(__FILE__));

// 定义存档服务 - 修复原始的 wenpai.net 服务
define('ARCHIVER_SERVICES', array(
    'wayback' => array(
        'name' => 'Internet Archive',
        'save_url' => 'https://web.archive.org/save/',
        'fetch_url' => 'https://web.archive.org/cdx/search/cdx',
        'view_url' => 'https://web.archive.org/web/',
        'enabled' => true
    ),
    'wenpai' => array(
        'name' => 'WenPai Archive',
        'save_url' => 'https://web.wenpai.net/save/',
        'fetch_url' => 'https://web.wenpai.net/cdx/',
        'view_url' => 'https://web.wenpai.net/web/',
        'enabled' => true
    ),
    'archive_today' => array(
        'name' => 'Archive.today',
        'save_url' => 'https://archive.today/?run=1&url=',
        'fetch_url' => 'https://archive.today/',
        'view_url' => 'https://archive.today/',
        'enabled' => false
    )
));

// 插件激活
register_activation_hook(__FILE__, 'archiver_activate');
function archiver_activate() {
    // 设置默认选项
    add_option('archiver_post_types', array('post', 'page'));
    add_option('archiver_update_frequency', 'daily');
    add_option('archiver_urls_to_update', array());
    add_option('archiver_cache_enabled', true);
    add_option('archiver_background_queue', array());
    add_option('archiver_total_archived', 0);
    add_option('archiver_failed_snapshots', 0);
    add_option('archiver_services', array('wenpai' => true));
    add_option('archiver_primary_service', 'wenpai');
    add_option('archiver_auto_archive', true);
    add_option('archiver_archive_on_publish', true);
    add_option('archiver_max_queue_size', 100);
    add_option('archiver_batch_size', 3);

    // 创建缓存表
    archiver_create_cache_table();

    // 安排定时任务
    if (!wp_next_scheduled('archiver_process_urls')) {
        wp_schedule_event(time(), 'daily', 'archiver_process_urls');
    }

    if (!wp_next_scheduled('archiver_cleanup_cache')) {
        wp_schedule_event(time(), 'daily', 'archiver_cleanup_cache');
    }

    // 清理缓存
    wp_cache_flush();
}

// 创建缓存表
function archiver_create_cache_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'archiver_cache';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        url varchar(255) NOT NULL,
        url_hash varchar(32) NOT NULL,
        service varchar(50) NOT NULL DEFAULT 'wayback',
        snapshot_data longtext NOT NULL,
        snapshot_count int(11) DEFAULT 0,
        cache_type varchar(20) DEFAULT 'warm',
        last_accessed datetime DEFAULT CURRENT_TIMESTAMP,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        expires_at datetime NOT NULL,
        api_calls_saved int(11) DEFAULT 0,
        status varchar(20) DEFAULT 'active',
        PRIMARY KEY (id),
        UNIQUE KEY url_service (url_hash, service),
        KEY expires_at (expires_at),
        KEY cache_type (cache_type),
        KEY service (service)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // 添加索引以提高查询性能
    $wpdb->query("CREATE INDEX IF NOT EXISTS idx_status_expires ON {$table_name} (status, expires_at)");
}

// 插件停用
register_deactivation_hook(__FILE__, 'archiver_deactivate');
function archiver_deactivate() {
    // 删除定时任务
    $timestamp = wp_next_scheduled('archiver_process_urls');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'archiver_process_urls');
    }

    $timestamp = wp_next_scheduled('archiver_cleanup_cache');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'archiver_cleanup_cache');
    }

    // 清理缓存
    wp_cache_flush();
}

// 插件卸载
register_uninstall_hook(__FILE__, 'archiver_uninstall');
function archiver_uninstall() {
    // 删除选项
    $options = array(
        'archiver_post_types',
        'archiver_update_frequency',
        'archiver_urls_to_update',
        'archiver_cache_enabled',
        'archiver_background_queue',
        'archiver_last_run',
        'archiver_total_archived',
        'archiver_failed_snapshots',
        'archiver_services',
        'archiver_primary_service',
        'archiver_auto_archive',
        'archiver_archive_on_publish',
        'archiver_max_queue_size',
        'archiver_batch_size'
    );

    foreach ($options as $option) {
        delete_option($option);
    }

    // 删除缓存表
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}archiver_cache");

    // 清理所有transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_archiver_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_archiver_%'");
}

// 加载文本域
add_action('init', 'archiver_load_textdomain');
function archiver_load_textdomain() {
    load_plugin_textdomain('archiver', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

// 延长 nonce 有效期（仅针对本插件）
add_filter('nonce_life', function($lifespan) {
    if (isset($_GET['page']) && $_GET['page'] === 'archiver-settings') {
        return DAY_IN_SECONDS; // 24小时
    }
    return $lifespan;
});

// 错误处理
function archiver_handle_error($message, $type = 'error') {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[WP Archiver] ' . $type . ': ' . $message);
    }
}

// 加载类文件 - 注意顺序很重要！
function archiver_load_dependencies() {
    $includes_dir = ARCHIVER_PLUGIN_DIR_PATH . 'includes/';

    // 按正确顺序加载
    $files = array(
        'class-archiver-cache.php',    // 缓存类必须先加载
        'class-archiver.php',          // 主类
        'class-archiver-admin.php'     // 管理类最后加载
    );

    foreach ($files as $file) {
        $file_path = $includes_dir . $file;
        if (file_exists($file_path)) {
            require_once $file_path;
        } else {
            archiver_handle_error('Missing required file: ' . $file);
        }
    }
}

// 初始化插件
add_action('plugins_loaded', 'archiver_init', 5);
function archiver_init() {
    // 加载依赖
    archiver_load_dependencies();

    // 运行插件
    archiver_run();
}

// 运行插件主逻辑
function archiver_run() {
    try {
        $archiver = Archiver::get_instance();

        if (is_admin()) {
            new Archiver_Admin($archiver);
        }

        $archiver->run();

        return $archiver;

    } catch (Exception $e) {
        archiver_handle_error('Failed to initialize: ' . $e->getMessage());
    }
}

// 添加设置链接到插件列表
add_filter('plugin_action_links_' . ARCHIVER_PLUGIN_BASENAME, 'archiver_add_action_links');
function archiver_add_action_links($links) {
    $settings_link = '<a href="' . admin_url('tools.php?page=archiver-settings') . '">' . __('Settings', 'archiver') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// 检查系统要求
add_action('admin_init', 'archiver_check_requirements');
function archiver_check_requirements() {
    if (version_compare(PHP_VERSION, '5.6', '<')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo __('WP Archiver requires PHP 5.6 or higher.', 'archiver');
            echo '</p></div>';
        });
    }

    if (version_compare(get_bloginfo('version'), '4.9', '<')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo __('WP Archiver requires WordPress 4.9 or higher.', 'archiver');
            echo '</p></div>';
        });
    }
}

// 性能优化：限制队列大小
add_filter('archiver_queue_limit', function($limit) {
    return get_option('archiver_max_queue_size', 100);
});

// 性能优化：批处理大小
add_filter('archiver_batch_size', function($size) {
    return get_option('archiver_batch_size', 3);
});
