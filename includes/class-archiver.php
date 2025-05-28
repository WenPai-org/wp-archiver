<?php
/**
 * Main Archiver Class
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

        // Initialize services with fallback
        $this->services = $this->get_available_services();

        // Register hooks
        add_action('init', array($this, 'setup_cron'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
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

    public function get_slug() {
        return $this->slug;
    }

    public function run() {
        add_action('wp_loaded', array($this, 'init'));
        add_action('admin_init', array($this, 'admin_init'));
    }

    public function init() {
        $this->set_locale();

        // Initialize cache system after WordPress is fully loaded
        if (class_exists('Archiver_Cache')) {
            $this->cache = new Archiver_Cache();
        }

        // Register scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'register_scripts_and_styles'), 5);
        add_action('admin_enqueue_scripts', array($this, 'register_scripts_and_styles'), 5);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));

        // AJAX handlers
        add_action('wp_ajax_archiver_immediate_snapshot', array($this, 'ajax_immediate_snapshot'));
        add_action('wp_ajax_archiver_check_cache_update', array($this, 'ajax_check_cache_update'));
        add_action('wp_ajax_archiver_get_snapshots', array($this, 'ajax_get_snapshots'));

        if ($this->can_run()) {
            // Content hooks
            if (get_option('archiver_auto_archive', true)) {
                add_action('save_post', array($this, 'trigger_post_snapshot'));
                add_action('created_term', array($this, 'trigger_term_snapshot'), 10, 3);
                add_action('edited_term', array($this, 'trigger_term_snapshot'), 10, 3);
                add_action('profile_update', array($this, 'trigger_user_snapshot'), 10, 3);
            }
            add_action('admin_bar_menu', array($this, 'add_admin_bar_links'), 999);
        }

        // Background processing hooks
        if ($this->cache) {
            add_action('archiver_process_background_queue', array($this->cache, 'process_background_queue'));
        }
        add_action('archiver_fetch_single_url', array($this, 'fetch_single_url_background'));
        
        // Register new hook for priority queue
        add_action('archiver_process_priority_queue', array($this, 'process_priority_queue'));
        add_action('archiver_trigger_single_service', array($this, 'trigger_single_service_archive'), 10, 2);
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
        // Add custom Cron intervals - ensure "hourly" works correctly
        add_filter('cron_schedules', array($this, 'add_custom_cron_intervals'));
        
        // Get current frequency setting
        $frequency = get_option('archiver_update_frequency', 'hourly');
        
        // Check existing scheduled tasks
        $timestamp = wp_next_scheduled('archiver_process_urls');
        
        // If no schedule exists or the last schedule time is more than 24 hours ago, reschedule task
        $last_schedule_time = get_option('archiver_last_schedule_time', 0);
        $schedule_age = time() - $last_schedule_time;
        
        if (!$timestamp || $schedule_age > DAY_IN_SECONDS) {
            // Cancel any existing scheduled tasks
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'archiver_process_urls');
            }
            
            // Ensure no duplicate tasks
            $this->clear_duplicate_cron_tasks('archiver_process_urls');
            
            // Create new scheduled task, add 1-60 seconds random delay
            $offset = mt_rand(1, 60);
            wp_schedule_event(time() + $offset, $frequency, 'archiver_process_urls');
            
            // Record scheduling time
            update_option('archiver_last_schedule_time', time());
            
            // Record next run time for immediate display
            $this->update_next_run_display_time();
            
            // Debug log
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Archiver] Rescheduled process_urls task with frequency: ' . $frequency);
            }
        }
        
        add_action('archiver_process_urls', array($this, 'process_urls_for_update'));
    }

    /**
     * Add custom Cron intervals - ensure "hourly" and other intervals work as expected
     */
    public function add_custom_cron_intervals($schedules) {
        // Add 15-minute interval for high-frequency scenarios
        $schedules['minutes_15'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Every 15 Minutes', 'archiver')
        );
        
        // Add 30-minute interval for medium-frequency scenarios
        $schedules['minutes_30'] = array(
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Every 30 Minutes', 'archiver')
        );
        
        // Ensure hourly interval exists and is clearly defined
        $schedules['exact_hourly'] = array(
            'interval' => HOUR_IN_SECONDS,
            'display' => __('Exactly Every Hour', 'archiver')
        );
        
        return $schedules;
    }

    public function can_run() {
        return apply_filters('archiver_can_run', __return_true());
    }

    // Meta Box related methods
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
        if (!isset($_GET['tag_ID'])) {
            return;
        }

        $term = get_term($_GET['tag_ID']);
        if (is_wp_error($term)) {
            return;
        }

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
                $service = isset($this->services[$service_id]) ? $this->services[$service_id] : array('name' => ucfirst($service_id));
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
                <?php _e('Archive Now', 'archiver'); ?>
            </button>
            <span id="archiver-status" style="margin-left: 10px; display: none;"></span>
        </div>
        <?php
    }

    // AJAX handler methods
    public function ajax_get_snapshots() {
        check_ajax_referer('archiver_immediate_snapshot', '_ajax_nonce');

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $service = isset($_POST['service']) ? sanitize_text_field($_POST['service']) : null;

        if (empty($url)) {
            wp_send_json_error(['message' => __('Invalid URL', 'archiver')]);
        }

        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Archiver] Getting snapshots for URL: ' . $url . ' Service: ' . $service);
        }

        $snapshots = array();
        if ($this->cache && method_exists($this->cache, 'get_snapshots')) {
            $snapshots = $this->cache->get_snapshots($url, $service);
        }

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

        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Archiver] Triggering snapshot for URL: ' . $url . ' Service: ' . $service);
        }

        // Add URL to high-priority queue
        if ($this->cache && method_exists($this->cache, 'queue_for_update')) {
            if ($service) {
                $this->cache->queue_for_update($url, $service, true);
            } else {
                // Add to queue for all enabled services
                $enabled_services = get_option('archiver_services', array('wenpai' => true));
                foreach ($enabled_services as $service_id => $enabled) {
                    if ($enabled) {
                        $this->cache->queue_for_update($url, $service_id, true);
                    }
                }
            }
        }

        // Schedule an immediate event to process high-priority queue
        if (!wp_next_scheduled('archiver_process_priority_queue')) {
            wp_schedule_single_event(time() + 5, 'archiver_process_priority_queue');
        }

        wp_send_json_success([
            'message' => __('Snapshot request submitted and will be processed shortly.', 'archiver'),
            'refresh' => true
        ]);
    }

    public function ajax_check_cache_update() {
        check_ajax_referer('archiver_immediate_snapshot', '_ajax_nonce');

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $service = isset($_POST['service']) ? sanitize_text_field($_POST['service']) : null;

        if (empty($url)) {
            wp_send_json_error();
        }

        $snapshots = array();
        if ($this->cache && method_exists($this->cache, 'get_snapshots')) {
            $snapshots = $this->cache->get_snapshots($url, $service, true); // Force refresh
        }

        wp_send_json_success([
            'has_update' => !empty($snapshots),
            'count' => count($snapshots),
            'html' => $this->format_snapshots_html($snapshots, $service)
        ]);
    }

    private function format_snapshots_html($snapshots, $service = 'wenpai') {
        if (empty($snapshots)) {
            return '';
        }

        $html = '<ul class="archiver-snapshot-list">';
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $service_config = isset($this->services[$service]) ? $this->services[$service] : $this->services['wenpai'];

        foreach (array_slice($snapshots, 0, $this->snapshot_max_count) as $snapshot) {
            $timestamp = isset($snapshot['timestamp']) ? $snapshot['timestamp'] : '';
            $original = isset($snapshot['original']) ? $snapshot['original'] : '';

            if ($timestamp && $original) {
                // Handle different timestamp formats
                if (strlen($timestamp) === 14) {
                    // Format: YYYYMMDDHHMMSS
                    $date_time = substr($timestamp, 0, 4) . '-' .
                                substr($timestamp, 4, 2) . '-' .
                                substr($timestamp, 6, 2) . ' ' .
                                substr($timestamp, 8, 2) . ':' .
                                substr($timestamp, 10, 2) . ':' .
                                substr($timestamp, 12, 2);
                } else {
                    $date_time = $timestamp;
                }

                $snapshot_url = $service_config['view_url'] . $timestamp . '/' . $original;
                $formatted_date = date_i18n($date_format . ' @ ' . $time_format, strtotime($date_time));

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
            __('View all snapshots', 'archiver')
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
        $enabled_services = get_option('archiver_services', array('wenpai' => true));
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

    // Get current page URL
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

    // Scripts and styles
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

        // Add admin nonce
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
                'view_all' => __('View all snapshots', 'archiver'),
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
            'tools_page_archiver-settings',
            'index.php' // Dashboard
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

        if ($this->cache && method_exists($this->cache, 'queue_for_update')) {
            if ($service) {
                $this->cache->queue_for_update($url, $service, true);
            } else {
                // Queue for all enabled services
                $enabled_services = get_option('archiver_services', array('wenpai' => true));
                foreach ($enabled_services as $service_id => $enabled) {
                    if ($enabled) {
                        $this->cache->queue_for_update($url, $service_id, true);
                    }
                }
            }
        }

        // Schedule processing of high-priority queue
        if (!wp_next_scheduled('archiver_process_priority_queue')) {
            wp_schedule_single_event(time() + 5, 'archiver_process_priority_queue');
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => __('Snapshot request recorded.', 'archiver')
        ), 200);
    }

    public function rest_get_snapshots($request) {
        $url = $request->get_param('url');
        $service = $request->get_param('service');

        $snapshots = array();
        if ($this->cache && method_exists($this->cache, 'get_snapshots')) {
            $snapshots = $this->cache->get_snapshots($url, $service);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'snapshots' => $snapshots,
            'count' => count($snapshots)
        ), 200);
    }

    // Trigger snapshots - modified to async mode
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

    /**
     * Optimized URL snapshot trigger method - changed to use async queue
     */
    public function trigger_url_snapshot($url) {
        if (empty($url)) {
            return;
        }

        // Create a high-priority queue item
        $priority = get_option('archiver_archive_on_publish', true) ? 'high' : 'normal';
        
        // Add to queue regardless of immediate archiving setting
        if ($this->cache && method_exists($this->cache, 'queue_for_update')) {
            // Add to cache queue
            $enabled_services = get_option('archiver_services', array('wenpai' => true));
            foreach ($enabled_services as $service_id => $enabled) {
                if ($enabled) {
                    $this->cache->queue_for_update($url, $service_id, ($priority === 'high'));
                }
            }
        } else {
            // Fall back to simple queue if cache class unavailable
            $existing = get_option('archiver_urls_to_update', array());
            if (!is_array($existing)) {
                $existing = array();
            }
            
            $existing[] = array(
                'url' => $url,
                'priority' => $priority,
                'added' => time()
            );
            update_option('archiver_urls_to_update', array_unique($existing));
        }
        
        // If immediate archiving is enabled, schedule a near-term single event to process the high-priority queue
        if ($priority === 'high' && !wp_next_scheduled('archiver_process_priority_queue')) {
            wp_schedule_single_event(time() + 5, 'archiver_process_priority_queue');
        }
    }

    /**
     * Process high-priority queue
     */
    public function process_priority_queue() {
        // First get the background queue
        $background_queue = get_option('archiver_background_queue', array());
        if (!is_array($background_queue)) {
            $background_queue = array();
        }
        
        // Find high-priority items added within the last 10 minutes
        $priority_items = array();
        $regular_items = array();
        $current_time = time();
        $cutoff_time = $current_time - (10 * MINUTE_IN_SECONDS);
        
        foreach ($background_queue as $item) {
            // Check if it's an array and has an 'added' field
            if (is_array($item) && isset($item['added']) && $item['added'] > $cutoff_time) {
                $priority_items[] = $item;
            } else {
                $regular_items[] = $item;
            }
        }
        
        // Process only up to 5 high-priority items
        $batch_size = min(5, count($priority_items));
        if ($batch_size === 0) {
            return; // No high-priority items to process
        }
        
        // Remove items to be processed from the queue
        $batch = array_slice($priority_items, 0, $batch_size);
        $remaining_priority_items = array_slice($priority_items, $batch_size);
        
        // Update queue
        update_option('archiver_background_queue', array_merge($regular_items, $remaining_priority_items));
        
        // Process high-priority items
        foreach ($batch as $item) {
            if (is_array($item)) {
                $url = isset($item['url']) ? $item['url'] : (is_string($item) ? $item : '');
                $service = isset($item['service']) ? $item['service'] : null;
                
                if (!empty($url)) {
                    // Process archive request asynchronously
                    $this->schedule_nonblocking_archive($url, $service);
                }
            } else if (is_string($item)) {
                // Handle old format queue items
                $this->schedule_nonblocking_archive($item);
            }
        }
        
        // If more high-priority items remain, schedule another processing run
        if (count($remaining_priority_items) > 0) {
            wp_schedule_single_event(time() + 30, 'archiver_process_priority_queue');
        }
    }

    /**
     * Schedule non-blocking archive request
     */
    private function schedule_nonblocking_archive($url, $service = null) {
        $enabled_services = get_option('archiver_services', array('wenpai' => true));
        
        foreach ($enabled_services as $service_id => $enabled) {
            if ($enabled && (!$service || $service === $service_id)) {
                // Register separate events for each service to avoid processing multiple services in the same request
                if (!wp_next_scheduled('archiver_trigger_single_service', array($url, $service_id))) {
                    wp_schedule_single_event(time() + rand(5, 15), 'archiver_trigger_single_service', array($url, $service_id));
                }
            }
        }
    }

    /**
     * Trigger single service archive - executed in a separate WP-Cron request
     */
    public function trigger_single_service_archive($url, $service) {
        if (!isset($this->services[$service])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Archiver] Service not found: ' . $service);
            }
            return false;
        }

        $service_config = $this->services[$service];
        $save_url = $service_config['save_url'] . urlencode($url);

        $response = wp_remote_get($save_url, array(
            'timeout' => 15,
            'sslverify' => ($service !== 'wenpai'), // WenPai might use self-signed certificates
            'headers' => array(
                'User-Agent' => 'WP-Archiver/' . ARCHIVER_VERSION . ' (WordPress/' . get_bloginfo('version') . ')'
            )
        ));

        $success = !is_wp_error($response) && in_array(wp_remote_retrieve_response_code($response), array(200, 201, 202));

        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($success) {
                error_log('[WP Archiver] Successfully triggered ' . $service . ' for: ' . $url);
            } else {
                $error_msg = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
                error_log('[WP Archiver] Failed to trigger ' . $service . ' for: ' . $url . ' - ' . $error_msg);
            }
        }

        // Update cache
        if ($success && $this->cache && method_exists($this->cache, 'fetch_and_cache_snapshots')) {
            // Wait a moment before refreshing the cache to give the archive service time to process
            wp_schedule_single_event(time() + 60, 'archiver_fetch_single_url', array($url));
        }

        return $success;
    }

    /**
     * Old synchronous archive call method - kept for compatibility but no longer used directly
     */
    private function trigger_archive_service($url, $service = 'wenpai') {
        if (!isset($this->services[$service])) {
            return false;
        }

        // Convert this operation to a non-blocking call
        if (!wp_next_scheduled('archiver_trigger_single_service', array($url, $service))) {
            wp_schedule_single_event(time() + 1, 'archiver_trigger_single_service', array($url, $service));
        }
        
        return true;
    }

    // Process URL updates
    public function process_urls_for_update() {
        $urls = get_option('archiver_urls_to_update', array());
        if (empty($urls)) {
            $this->update_run_times();
            return 0;
        }

        $batch_size = get_option('archiver_batch_size', 10);
        $batch = array_splice($urls, 0, $batch_size);

        foreach ($batch as $url) {
            if (is_array($url) && isset($url['url'])) {
                $url = $url['url'];
            }
            wp_schedule_single_event(time() + rand(5, 30), 'archiver_fetch_single_url', array($url));
        }

        update_option('archiver_urls_to_update', $urls);
        
        // Update run times
        $this->update_run_times();
        
        return count($batch);
    }

    /**
     * Update run time records - use UTC timestamps for internal storage
     */
    private function update_run_times() {
        // Record last run time using UTC time
        $now_utc = time();
        update_option('archiver_last_run_timestamp', $now_utc);
        
        // Also store a formatted version using site's timezone for direct display
        update_option('archiver_last_run', $this->get_formatted_schedule_time($now_utc));
        
        // Calculate and record next run time
        $next_run = wp_next_scheduled('archiver_process_urls');
        if ($next_run) {
            update_option('archiver_next_run_timestamp', $next_run);
            update_option('archiver_next_run_display', $this->get_formatted_schedule_time($next_run));
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Archiver] Run times updated. Last run: ' . date('Y-m-d H:i:s', $now_utc) . ', Next run timestamp: ' . ($next_run ?: 'none'));
        }
    }

    public function fetch_single_url_background($url) {
        $enabled_services = get_option('archiver_services', array('wenpai' => true));

        foreach ($enabled_services as $service_id => $enabled) {
            if ($enabled) {
                // Fetch and cache snapshots
                if ($this->cache && method_exists($this->cache, 'fetch_and_cache_snapshots')) {
                    $this->cache->fetch_and_cache_snapshots($url, $service_id);
                }
            }
        }
    }

    /**
     * Fixed Cron task rescheduling method
     */
    public function reschedule_cron_task($frequency) {
        // Convert to exact interval if hourly is specified
        if ($frequency === 'hourly') {
            $frequency = 'exact_hourly';
        }
        
        // Ensure frequency is valid
        $valid_frequencies = array('minutes_15', 'minutes_30', 'exact_hourly', 'hourly', 'twicedaily', 'daily', 'weekly');
        if (!in_array($frequency, $valid_frequencies)) {
            $frequency = 'hourly'; // Default to hourly
        }
        
        // Cancel all existing tasks
        $timestamp = wp_next_scheduled('archiver_process_urls');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'archiver_process_urls');
        }
        
        // Ensure any duplicate tasks are cleaned up
        $this->clear_duplicate_cron_tasks('archiver_process_urls');
        
        // Create new task, add 1-60 seconds random delay
        $offset = mt_rand(1, 60);
        wp_schedule_event(time() + $offset, $frequency, 'archiver_process_urls');
        
        // Record scheduling time
        update_option('archiver_last_schedule_time', time());
        
        // Update displayed next run time to immediately update UI
        $this->update_next_run_display_time();
        
        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Archiver] Cron task rescheduled with frequency: ' . $frequency);
        }
    }

    /**
     * Update next run display time
     */
    private function update_next_run_display_time() {
        $next_run = wp_next_scheduled('archiver_process_urls');
        if ($next_run) {
            // Save original timestamp and formatted display time
            update_option('archiver_next_run_timestamp', $next_run);
            update_option('archiver_next_run_display', $this->get_formatted_schedule_time($next_run));
        } else {
            delete_option('archiver_next_run_timestamp');
            delete_option('archiver_next_run_display');
        }
    }

    /**
     * Clear duplicate Cron tasks
     */
    private function clear_duplicate_cron_tasks($hook) {
        $crons = _get_cron_array();
        $found = false;
        
        if (empty($crons)) {
            return false;
        }
        
        foreach ($crons as $timestamp => $cron) {
            if (isset($cron[$hook])) {
                foreach ($cron[$hook] as $key => $event) {
                    if ($found) {
                        // Already found one event, delete other duplicates
                        wp_unschedule_event($timestamp, $hook, $event['args']);
                    } else {
                        // First time finding an event
                        $found = true;
                    }
                }
            }
        }
        
        return $found;
    }
    
    /**
     * Get formatted schedule time (fix timezone issues)
     */
    public function get_formatted_schedule_time($timestamp) {
        if (!$timestamp) {
            return __('Not scheduled', 'archiver');
        }
        
        // Get WordPress timezone settings
        $timezone_string = get_option('timezone_string');
        $gmt_offset = get_option('gmt_offset');
        
        try {
            if ($timezone_string) {
                // Use timezone string
                $timezone = new DateTimeZone($timezone_string);
                $datetime = new DateTime('@' . $timestamp);  // Create GMT time using @ symbol
                $datetime->setTimezone($timezone);           // Convert to local timezone
                return $datetime->format(get_option('date_format') . ' ' . get_option('time_format'));
            } else if (!empty($gmt_offset)) {
                // Use GMT offset
                $local_timestamp = $timestamp + ($gmt_offset * HOUR_IN_SECONDS);
                return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $local_timestamp);
            }
        } catch (Exception $e) {
            // Log debug info on error
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Archiver] Time formatting error: ' . $e->getMessage());
            }
        }
        
        // Default fallback
        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }
}