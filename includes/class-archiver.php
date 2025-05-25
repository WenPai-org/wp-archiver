<?php
/**
 * 主要的 Archiver 类
 */

if (!defined('ABSPATH')) {
    exit;
}

class Archiver {
    protected $slug = 'archiver';
    protected $name;
    protected $snapshot_max_count;
    protected $current_permalink = '';
    protected static $instance = null;
    protected $cache;
    protected $services = array();

    public static function get_instance($args = array()) {
        if (null == self::$instance) {
            self::$instance = new self($args);
        }
        return self::$instance;
    }

    public function __construct($args = array()) {
        $this->name = __('Archiver', 'archiver');
        $this->snapshot_max_count = apply_filters('archiver_snapshot_max_count', 10);
        $this->services = ARCHIVER_SERVICES;

        // 初始化缓存系统
        $this->cache = new Archiver_Cache();

        // 注册钩子
        add_action('init', array($this, 'setup_cron'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    public function get_slug() {
        return $this->slug;
    }

    public function run() {
        add_action('wp_loaded', array($this, 'init'));
        add_action('admin_init', array($this, 'admin_init'));
    }

    public function init() {
        $this->set_locale();

        // 注册脚本和样式
        add_action('wp_enqueue_scripts', array($this, 'register_scripts_and_styles'), 5);
        add_action('admin_enqueue_scripts', array($this, 'register_scripts_and_styles'), 5);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));

        // AJAX 处理
        add_action('wp_ajax_archiver_immediate_snapshot', array($this, 'ajax_immediate_snapshot'));
        add_action('wp_ajax_archiver_check_cache_update', array($this, 'ajax_check_cache_update'));
        add_action('wp_ajax_archiver_get_snapshots', array($this, 'ajax_get_snapshots'));

        if ($this->can_run()) {
            // 内容钩子
            if (get_option('archiver_auto_archive', true)) {
                add_action('save_post', array($this, 'trigger_post_snapshot'));
                add_action('created_term', array($this, 'trigger_term_snapshot'), 10, 3);
                add_action('edited_term', array($this, 'trigger_term_snapshot'), 10, 3);
                add_action('profile_update', array($this, 'trigger_user_snapshot'), 10, 3);
            }
            add_action('admin_bar_menu', array($this, 'add_admin_bar_links'), 999);
        }

        // 后台处理钩子
        add_action('archiver_process_background_queue', array($this->cache, 'process_background_queue'));
        add_action('archiver_fetch_single_url', array($this, 'fetch_single_url_background'));
    }

    public function admin_init() {
        $this->add_post_meta_box();
        $this->add_term_meta_box();
        $this->add_user_meta_box();
    }

    protected function set_locale() {
        load_plugin_textdomain(
            $this->slug,
            false,
            dirname(dirname(plugin_basename(__FILE__))) . '/languages/'
        );
    }

    public function setup_cron() {
        $frequency = get_option('archiver_update_frequency', 'daily');
        if (!wp_next_scheduled('archiver_process_urls')) {
            wp_schedule_event(time(), $frequency, 'archiver_process_urls');
        }
        add_action('archiver_process_urls', array($this, 'process_urls_for_update'));
    }

    public function can_run() {
        return apply_filters('archiver_can_run', __return_true());
    }

    // Meta Box 相关方法
    public function add_post_meta_box() {
        $post_types = get_option('archiver_post_types', array('post', 'page'));
        add_meta_box(
            'archiver_post',
            __('Archives', 'archiver'),
            array($this, 'output_archiver_metabox'),
            $post_types,
            'side',
            'default'
        );
    }

    public function add_term_meta_box() {
        $taxonomies = apply_filters('archiver_taxonomies', get_taxonomies());
        foreach ($taxonomies as $taxonomy) {
            add_action("{$taxonomy}_edit_form", array($this, 'output_term_meta_box'));
        }
    }

    public function output_term_meta_box() {
        $term = get_term($_GET['tag_ID']);
        $url = get_term_link($term);
        if (!is_wp_error($url)) {
            $this->current_permalink = $url;
            echo '<div class="postbox archiver-metabox">';
            echo '<h2>' . __('Archives', 'archiver') . '</h2>';
            echo '<div class="inside">';
            $this->output_archiver_metabox();
            echo '</div></div>';
        }
    }

    public function add_user_meta_box() {
        add_action('show_user_profile', array($this, 'output_user_meta_box'));
        add_action('edit_user_profile', array($this, 'output_user_meta_box'));
    }

    public function output_user_meta_box($user) {
        $url = get_author_posts_url($user->ID);
        $this->current_permalink = $url;
        ?>
        <h2><?php _e('Archives', 'archiver'); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php _e('Archive Snapshots', 'archiver'); ?></th>
                <td class="archiver-metabox">
                    <?php $this->output_archiver_metabox(); ?>
                </td>
            </tr>
        </table>
        <?php
    }

    public function output_archiver_metabox() {
        $url = $this->get_current_permalink();
        $enabled_services = get_option('archiver_services', array('wenpai' => true));
        $primary_service = get_option('archiver_primary_service', 'wenpai');

        wp_nonce_field('archiver_immediate_snapshot', '_ajax_nonce');
        ?>
        <input type="hidden" id="archiver-url" value="<?php echo esc_attr($url); ?>">
        <input type="hidden" id="archiver_nonce" value="<?php echo wp_create_nonce('archiver_immediate_snapshot'); ?>">
        <input type="hidden" id="archiver_primary_service" value="<?php echo esc_attr($primary_service); ?>">

        <div class="archiver-service-tabs">
            <?php foreach ($enabled_services as $service_id => $enabled) :
                if (!$enabled) continue;
                $service = $this->services[$service_id];
                ?>
                <button class="archiver-service-tab <?php echo $service_id === $primary_service ? 'active' : ''; ?>"
                        data-service="<?php echo esc_attr($service_id); ?>">
                    <?php echo esc_html($service['name']); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div id="archiver-snapshots" data-url="<?php echo esc_attr($url); ?>" data-service="<?php echo esc_attr($primary_service); ?>">
            <div class="archiver-loading">
                <span class="spinner is-active"></span>
                <?php _e('Loading snapshots...', 'archiver'); ?>
            </div>
        </div>

        <div class="archiver-actions">
            <button id="archiver-immediate-snapshot" class="button button-secondary">
                <span class="dashicons dashicons-backup"></span>
                <?php _e('Archive Now', 'archiver'); ?>
            </button>
            <span id="archiver-status" style="margin-left: 10px; display: none;"></span>
        </div>
        <?php
    }

    // AJAX 处理方法
    public function ajax_get_snapshots() {
        check_ajax_referer('archiver_immediate_snapshot', '_ajax_nonce');

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $service = isset($_POST['service']) ? sanitize_text_field($_POST['service']) : null;

        if (empty($url)) {
            wp_send_json_error(['message' => __('Invalid URL', 'archiver')]);
        }

        $snapshots = $this->cache->get_snapshots($url, $service);

        if (!empty($snapshots)) {
            $html = $this->format_snapshots_html($snapshots, $service);
            wp_send_json_success(['html' => $html, 'count' => count($snapshots)]);
        } else {
            $urls_to_update = get_option('archiver_urls_to_update', array());
            $message = in_array($url, $urls_to_update)
                ? __('No archives yet. A snapshot request has been scheduled.', 'archiver')
                : __('There are no archives of this URL.', 'archiver');

            wp_send_json_success(['html' => '<p class="archiver-no-snapshots">' . esc_html($message) . '</p>', 'count' => 0]);
        }
    }

    public function ajax_immediate_snapshot() {
        if (!check_ajax_referer('archiver_immediate_snapshot', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'archiver')], 403);
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied.', 'archiver')], 403);
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $service = isset($_POST['service']) ? sanitize_text_field($_POST['service']) : null;

        if (empty($url)) {
            wp_send_json_error(['message' => __('Invalid URL.', 'archiver')], 400);
        }

        // 触发快照到所有启用的服务
        $enabled_services = get_option('archiver_services', array('wayback' => true));
        $triggered = 0;

        foreach ($enabled_services as $service_id => $enabled) {
            if ($enabled && (!$service || $service === $service_id)) {
                $result = $this->trigger_archive_service($url, $service_id);
                if ($result) {
                    $triggered++;
                }
                // 强制刷新缓存
                $this->cache->queue_for_update($url, $service_id, true);
            }
        }

        if ($triggered > 0) {
            wp_send_json_success([
                'message' => sprintf(__('Snapshot request submitted to %d service(s).', 'archiver'), $triggered),
                'refresh' => true
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to trigger snapshot.', 'archiver')]);
        }
    }

    public function ajax_check_cache_update() {
        check_ajax_referer('archiver_immediate_snapshot', '_ajax_nonce');

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $service = isset($_POST['service']) ? sanitize_text_field($_POST['service']) : null;

        if (empty($url)) {
            wp_send_json_error();
        }

        $snapshots = $this->cache->get_snapshots($url, $service);
        wp_send_json_success([
            'has_update' => !empty($snapshots),
            'count' => count($snapshots),
            'html' => $this->format_snapshots_html($snapshots, $service)
        ]);
    }

    private function format_snapshots_html($snapshots, $service = 'wayback') {
        if (empty($snapshots)) {
            return '';
        }

        $html = '<ul class="archiver-snapshot-list">';
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $service_config = $this->services[$service] ?? $this->services['wenpai'];

        foreach (array_slice($snapshots, 0, $this->snapshot_max_count) as $snapshot) {
            $timestamp = isset($snapshot['timestamp']) ? $snapshot['timestamp'] : '';
            $original = isset($snapshot['original']) ? $snapshot['original'] : '';

            if ($timestamp && $original) {
                $date_time = date('Y.m.d H:i', strtotime($timestamp));
                $adjusted_date = get_date_from_gmt($date_time);
                $snapshot_url = $service_config['view_url'] . $timestamp . '/' . $original;
                $formatted_date = date_i18n($date_format . ' @ ' . $time_format, strtotime($adjusted_date));

                $html .= sprintf(
                    '<li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
                    esc_url($snapshot_url),
                    esc_html($formatted_date)
                );
            }
        }

        $html .= '</ul>';

        // Add view all link
        $view_all_url = $service_config['view_url'] . '*/' . (isset($snapshots[0]['original']) ? $snapshots[0]['original'] : '');
        $html .= '<p class="archiver-view-all">';
        $html .= sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s <span class="dashicons dashicons-external"></span></a>',
            esc_url($view_all_url),
            __('See all snapshots', 'archiver')
        );
        $html .= '</p>';

        return $html;
    }

    // Admin Bar
    public function add_admin_bar_links($wp_admin_bar) {
        if (!current_user_can('edit_posts')) {
            return;
        }

        $url = $this->get_current_permalink();
        if (!$url) {
            return;
        }

        $wp_admin_bar->add_node([
            'id' => 'archiver',
            'title' => '<span class="ab-icon dashicons dashicons-backup"></span><span class="ab-label">' .
                      __('Archiver', 'archiver') . '</span>',
            'href' => admin_url('tools.php?page=archiver-settings'),
            'meta' => ['class' => 'archiver-admin-bar-item']
        ]);

        $wp_admin_bar->add_node([
            'parent' => 'archiver',
            'id' => 'archiver-snapshots',
            'title' => __('Loading...', 'archiver'),
            'href' => '#',
            'meta' => ['class' => 'archiver-snapshots-count']
        ]);

        $wp_admin_bar->add_node([
            'parent' => 'archiver',
            'id' => 'archiver-trigger',
            'title' => __('Trigger Snapshot', 'archiver'),
            'href' => '#',
            'meta' => ['class' => 'archiver-trigger']
        ]);

        // Add service submenu
        $enabled_services = get_option('archiver_services', array('wayback' => true));
        foreach ($enabled_services as $service_id => $enabled) {
            if ($enabled && isset($this->services[$service_id])) {
                $service = $this->services[$service_id];
                $wp_admin_bar->add_node([
                    'parent' => 'archiver',
                    'id' => 'archiver-service-' . $service_id,
                    'title' => sprintf(__('View on %s', 'archiver'), $service['name']),
                    'href' => $service['view_url'] . '*/' . $url,
                    'meta' => ['target' => '_blank']
                ]);
            }
        }
    }

    // 获取当前页面 URL
    public function get_current_permalink() {
        if (empty($this->current_permalink)) {
            if (is_admin()) {
                $this->current_permalink = $this->get_current_permalink_admin();
            } else {
                $this->current_permalink = $this->get_current_permalink_public();
            }
        }
        return apply_filters('archiver_permalink', $this->current_permalink);
    }

    private function get_current_permalink_admin() {
        $permalink = '';
        $current_screen = get_current_screen();

        if (!$current_screen) {
            return '';
        }

        switch ($current_screen->base) {
            case 'post':
                global $post;
                $post_id = $post ? $post->ID : (isset($_GET['post']) ? intval($_GET['post']) : 0);
                if ($post_id) {
                    $permalink = get_permalink($post_id);
                }
                break;

            case 'term':
                if (isset($_GET['tag_ID'])) {
                    $term = get_term($_GET['tag_ID']);
                    if ($term && !is_wp_error($term)) {
                        $permalink = get_term_link($term);
                    }
                }
                break;

            case 'profile':
            case 'user-edit':
                $user_id = !empty($_GET['user_id']) ? intval($_GET['user_id']) : get_current_user_id();
                $permalink = get_author_posts_url($user_id);
                break;
        }

        return is_string($permalink) ? $permalink : '';
    }

    private function get_current_permalink_public() {
        global $wp;
        $permalink = home_url($wp->request);

        if (!empty($_SERVER['QUERY_STRING'])) {
            $permalink .= '?' . $_SERVER['QUERY_STRING'];
        }

        return $permalink;
    }

    // 脚本和样式
    public function register_scripts_and_styles() {
        wp_register_script(
            'archiver',
            ARCHIVER_PLUGIN_DIR_URL . 'assets/js/archiver.js',
            array('jquery', 'wp-i18n'),
            ARCHIVER_VERSION,
            true
        );

        wp_register_style(
            'archiver',
            ARCHIVER_PLUGIN_DIR_URL . 'assets/css/archiver.css',
            array('dashicons'),
            ARCHIVER_VERSION
        );

        // 添加管理员 nonce
        $admin_nonce = is_admin() ? wp_create_nonce('archiver_admin_nonce') : '';

        wp_localize_script('archiver', 'archiver', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('archiver/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'ajax_nonce' => wp_create_nonce('archiver_immediate_snapshot'),
            'admin_nonce' => $admin_nonce,
            'url' => $this->get_current_permalink(),
            'services' => $this->services,
            'enabled_services' => get_option('archiver_services', array('wenpai' => true)),
            'primary_service' => get_option('archiver_primary_service', 'wenpai'),
            'i18n' => array(
                'loading' => __('Loading snapshots...', 'archiver'),
                'triggering' => __('Triggering snapshot...', 'archiver'),
                'success' => __('Snapshot triggered successfully!', 'archiver'),
                'error' => __('Failed to trigger snapshot.', 'archiver'),
                'no_snapshots' => __('No archives yet.', 'archiver'),
                'view_all' => __('See all snapshots', 'archiver'),
                'checking' => __('Checking...', 'archiver'),
                'online' => __('Online', 'archiver'),
                'offline' => __('Offline', 'archiver'),
                'test_success' => __('Connection successful', 'archiver'),
                'test_failed' => __('Connection failed', 'archiver')
            )
        ));
    }

    public function enqueue_scripts() {
        if (!is_admin() && is_user_logged_in() && current_user_can('edit_posts')) {
            wp_enqueue_script('archiver');
            wp_enqueue_style('archiver');
        }
    }

    public function admin_enqueue_scripts($hook) {
        $allowed_hooks = array(
            'post.php',
            'post-new.php',
            'term.php',
            'profile.php',
            'user-edit.php',
            'tools_page_archiver-settings'
        );

        if (in_array($hook, $allowed_hooks)) {
            wp_enqueue_script('archiver');
            wp_enqueue_style('archiver');
        }
    }

    // REST API
    public function register_rest_routes() {
        register_rest_route('archiver/v1', '/trigger-snapshot', [
            'methods' => 'POST',
            'callback' => array($this, 'rest_trigger_snapshot'),
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
            'args' => array(
                'url' => array(
                    'required' => true,
                    'sanitize_callback' => 'esc_url_raw'
                ),
                'service' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ]);

        register_rest_route('archiver/v1', '/get-snapshots', [
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_snapshots'),
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
            'args' => array(
                'url' => array(
                    'required' => true,
                    'sanitize_callback' => 'esc_url_raw'
                ),
                'service' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ]);
    }

    public function rest_trigger_snapshot($request) {
        $url = $request->get_param('url');
        $service = $request->get_param('service');

        if ($service) {
            $this->cache->queue_for_update($url, $service, true);
        } else {
            // Queue for all enabled services
            $enabled_services = get_option('archiver_services', array('wayback' => true));
            foreach ($enabled_services as $service_id => $enabled) {
                if ($enabled) {
                    $this->cache->queue_for_update($url, $service_id, true);
                }
            }
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => __('Snapshot request recorded.', 'archiver')
        ), 200);
    }

    public function rest_get_snapshots($request) {
        $url = $request->get_param('url');
        $service = $request->get_param('service');

        $snapshots = $this->cache->get_snapshots($url, $service);

        return new WP_REST_Response(array(
            'success' => true,
            'snapshots' => $snapshots,
            'count' => count($snapshots)
        ), 200);
    }

    // 触发快照
    public function trigger_post_snapshot($post_id) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return;
        }

        $allowed_post_types = get_option('archiver_post_types', array('post', 'page'));
        if (!in_array($post->post_type, $allowed_post_types)) {
            return;
        }

        $url = get_permalink($post_id);
        $this->trigger_url_snapshot($url);
    }

    public function trigger_term_snapshot($term_id, $tt_id, $taxonomy) {
        $url = get_term_link($term_id, $taxonomy);
        if (!is_wp_error($url)) {
            $this->trigger_url_snapshot($url);
        }
    }

    public function trigger_user_snapshot($user_id) {
        $url = get_author_posts_url($user_id);
        $this->trigger_url_snapshot($url);
    }

    public function trigger_url_snapshot($url) {
        if (empty($url)) {
            return;
        }

        // 如果启用了立即存档，直接触发
        if (get_option('archiver_archive_on_publish', true)) {
            $enabled_services = get_option('archiver_services', array('wenpai' => true));
            foreach ($enabled_services as $service_id => $enabled) {
                if ($enabled) {
                    $this->trigger_archive_service($url, $service_id);
                }
            }
        }

        // 添加到更新队列
        $this->cache->queue_for_update($url);
    }

    private function trigger_archive_service($url, $service = 'wenpai') {
        if (!isset($this->services[$service])) {
            return false;
        }

        $service_config = $this->services[$service];
        $save_url = $service_config['save_url'] . urlencode($url);

        $response = wp_remote_get($save_url, array(
            'timeout' => 10,
            'sslverify' => true,
            'headers' => array(
                'User-Agent' => 'WP-Archiver/' . ARCHIVER_VERSION
            )
        ));

        return !is_wp_error($response) && in_array(wp_remote_retrieve_response_code($response), array(200, 201, 202));
    }

    // 处理URL更新
    public function process_urls_for_update() {
        $urls = get_option('archiver_urls_to_update', array());
        if (empty($urls)) {
            return 0;
        }

        $batch_size = apply_filters('archiver_batch_size', 5);
        $batch = array_splice($urls, 0, $batch_size);

        foreach ($batch as $url) {
            wp_schedule_single_event(time() + rand(5, 30), 'archiver_fetch_single_url', array($url));
        }

        update_option('archiver_urls_to_update', $urls);
        update_option('archiver_last_run', current_time('mysql'));

        return count($batch);
    }

    public function fetch_single_url_background($url) {
        $enabled_services = get_option('archiver_services', array('wenpai' => true));

        foreach ($enabled_services as $service_id => $enabled) {
            if ($enabled) {
                // Fetch and cache snapshots
                $this->cache->fetch_and_cache_snapshots($url, $service_id);

                // Also trigger new snapshot
                $this->trigger_archive_service($url, $service_id);
            }
        }
    }

    // 重新安排 Cron 任务
    public function reschedule_cron_task($frequency) {
        $timestamp = wp_next_scheduled('archiver_process_urls');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'archiver_process_urls');
        }

        wp_schedule_event(time(), $frequency, 'archiver_process_urls');
    }
}
