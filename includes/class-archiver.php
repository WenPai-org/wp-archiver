<?php
if (!defined('ABSPATH')) {
    exit;
}

class Archiver {
    protected $slug = 'archiver';
    protected $name;
    protected $min_suffix = '';
    protected $snapshot_max_count;
    protected $wayback_machine_url_save = 'https://web.wenpai.net/save/';
    protected $wayback_machine_url_fetch_archives = 'https://web.wenpai.net/cdx/';
    protected $wayback_machine_url_view = 'https://web.wenpai.net/web/';
    protected $current_permalink = '';
    protected static $instance = null;

    public static function get_instance($args = array()) {
        if (null == self::$instance) {
            self::$instance = new self($args);
        }
        return self::$instance;
    }

    public function __construct($args) {
        $this->name = __('Archiver', 'archiver');
        $this->snapshot_max_count = apply_filters('archiver_snapshot_max_count', 10);
        $this->min_suffix = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '.min';
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
        add_action('wp_enqueue_scripts', array($this, 'register_scripts_and_styles'), 5);
        add_action('admin_enqueue_scripts', array($this, 'register_scripts_and_styles'), 5);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_ajax_archiver_immediate_snapshot', array($this, 'ajax_immediate_snapshot'));
        if ($this->can_run()) {
            add_action('save_post', array($this, 'trigger_post_snapshot'));
            add_action('created_term', array($this, 'trigger_term_snapshot'), 10, 3);
            add_action('edited_term', array($this, 'trigger_term_snapshot'), 10, 3);
            add_action('profile_update', array($this, 'trigger_user_snapshot'), 10, 3);
            add_action('admin_bar_menu', array($this, 'add_admin_bar_links'), 999);
        } else {
            add_action('admin_notices', array($this, 'do_admin_notice_disabled'));
        }
    }

    public function admin_init() {
        $this->add_post_meta_box();
        $this->add_term_meta_box();
        $this->add_user_meta_box();
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

    protected function set_locale() {
        load_plugin_textdomain(
            $this->slug,
            false,
            dirname(dirname(plugin_basename(__FILE__))) . '/languages/'
        );
    }

    public function add_post_meta_box() {
        $post_types = apply_filters('archiver_post_types', get_post_types());
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
        $archiver_taxonomy_slugs = array_map(
            function($taxonomy) { return "archiver-" . $taxonomy; },
            $taxonomies
        );
        add_meta_box(
            'archiver_terms',
            __('Archives', 'archiver'),
            array($this, 'output_archiver_metabox'),
            $archiver_taxonomy_slugs,
            'side',
            'default'
        );
        foreach ($taxonomies as $taxonomy) {
            add_action("{$taxonomy}_edit_form", array($this, 'output_term_meta_box'));
        }
    }

    public function output_term_meta_box() {
        $object_type = get_current_screen()->taxonomy;
        $this->output_manual_meta_box($object_type);
    }

    public function add_user_meta_box() {
        add_meta_box(
            'archiver_terms',
            __('Archives', 'archiver'),
            array($this, 'output_archiver_metabox'),
            array('archiver-user'),
            'side',
            'default'
        );
    }

    public function output_archiver_metabox() {
        $url = $this->get_current_permalink();
        $snapshots = $this->get_post_snapshots($url);
        wp_nonce_field('archiver_immediate_snapshot', '_ajax_nonce');
        echo '<input type="hidden" id="archiver-url" value="' . esc_attr($url) . '">';
        echo '<input type="hidden" id="archiver_nonce" value="' . wp_create_nonce('archiver_immediate_snapshot') . '">';
        echo '<div id="archiver-snapshots">';
        if (empty($snapshots)) {
            $urls_to_update = get_option('archiver_urls_to_update', array());
            if (in_array($url, $urls_to_update)) {
                esc_html_e('No archives yet. A snapshot request has been scheduled and will be processed soon.', 'archiver');
            } else {
                esc_html_e('There are no archives of this URL.', 'archiver');
            }
        } else {
            $snapshots = array_slice($snapshots, 0, $this->snapshot_max_count);
            $date_format = get_option('date_format');
            $time_format = get_option('time_format');
            echo '<ul>';
            foreach ($snapshots as $snapshot) {
                $date_time = date('Y.m.d H:i', strtotime($snapshot['timestamp']));
                $adjusted_date = get_date_from_gmt($date_time);
                $snapshot_url = $this->wayback_machine_url_view . $snapshot['timestamp'] . '/' . $snapshot['original'];
                $date_time = date_i18n($date_format . ' @ ' . $time_format, strtotime($adjusted_date));
                echo '<li><a href="' . esc_url($snapshot_url) . '" target="_blank">' . esc_html($date_time) . '</a></li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        echo '<hr />';
        printf('<a href="%s" target="_external">%s</a>', 
            esc_url($this->wayback_machine_url_view . '*/' . $url), 
            esc_html__('See all snapshots ↗', 'archiver')
        );
        echo '<div style="margin-top: 10px;">';
        echo '<button id="archiver-immediate-snapshot" class="button button-secondary">' . esc_html__('Archive Now', 'archiver') . '</button>';
        echo '<span id="archiver-status" style="margin-left: 10px; display: none;"></span>';
        echo '</div>';
    }

    public function ajax_immediate_snapshot() {
        if (!check_ajax_referer('archiver_immediate_snapshot', '_ajax_nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed. Please try again.', 'archiver')
            ], 403);
        }
        if (!current_user_can('edit_posts')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'archiver')
            ], 403);
        }
        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        if (empty($url)) {
            wp_send_json_error([
                'message' => __('Invalid URL provided.', 'archiver')
            ], 400);
        }
        $result = $this->trigger_wayback_machine_snapshot($url);
        $snapshots = $this->fetch_snapshots_from_wayback($url);
        if (!empty($snapshots)) {
            $cache_key = 'archiver_snapshots_' . md5($url);
            set_transient($cache_key, $snapshots, WEEK_IN_SECONDS);
            $date_format = get_option('date_format');
            $time_format = get_option('time_format');
            $snapshot_list = [];
            foreach ($snapshots as $snapshot) {
                $date_time = date_i18n("$date_format @ $time_format", strtotime($snapshot['timestamp']));
                $snapshot_url = $this->wayback_machine_url_view . $snapshot['timestamp'] . '/' . $snapshot['original'];
                $snapshot_list[] = '<li><a href="'.esc_url($snapshot_url).'" target="_blank">'.esc_html($date_time).'</a></li>';
            }
            wp_send_json_success([
                'message' => __('Snapshot created successfully!', 'archiver'),
                'snapshots' => $snapshot_list
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to retrieve snapshots.', 'archiver')
            ], 500);
        }
    }

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
            'title' => '<span class="ab-icon dashicons dashicons-backup"></span><span class="ab-label">' . __('Archiver', 'archiver') . '</span>',
            'href' => admin_url('tools.php?page=archiver-settings'), 
            'meta' => ['target' => '_self'] 
        ]);
        $snapshots = $this->get_post_snapshots();
        $snapshot_count = is_wp_error($snapshots) ? 0 : count($snapshots);
        if ($snapshot_count >= $this->snapshot_max_count) {
            $snapshot_count = $this->snapshot_max_count . '+';
        }
        $wp_admin_bar->add_node([
            'parent' => 'archiver',
            'id' => 'archiver-snapshots',
            'title' => __('Snapshots', 'archiver') . " ({$snapshot_count})",
            'href' => $this->wayback_machine_url_view . '*/' . $url,
            'meta' => ['target' => '_blank']
        ]);
        $wp_admin_bar->add_node([
            'parent' => 'archiver',
            'id' => 'archiver-trigger',
            'title' => __('Trigger Snapshot', 'archiver') . ' <span class="ab-icon"></span>',
            'href' => '#',
            'meta' => [
                'class' => 'archiver-trigger'
            ]
        ]);
    }

    public function get_post_snapshots($url = '') {
        $url = $url ? $url : $this->get_current_permalink();
        if (empty($url)) {
            return array();
        }
        $cache_key = 'archiver_snapshots_' . md5($url);
        $snapshots = wp_cache_get($cache_key);
        if (false === $snapshots) {
            $snapshots = get_transient('archiver_last_known_snapshots_' . md5($url));
            if (false === $snapshots) {
                $this->record_url_for_update($url);
                $snapshots = array();
            } else {
                wp_cache_set($cache_key, $snapshots, '', HOUR_IN_SECONDS);
            }
        }
        return $snapshots;
    }

    private function fetch_snapshots_from_wayback($url) {
        $fetch_url = add_query_arg([
            'url' => $url,
            'output' => 'json',
        ], $this->wayback_machine_url_fetch_archives);
        $response = wp_remote_get($fetch_url, array(
            'timeout' => 30,
            'sslverify' => false
        ));
        if (is_wp_error($response)) {
            error_log('Archiver: Failed to fetch snapshots for ' . $url . '. Error: ' . $response->get_error_message());
            return array();
        }
        $response_code = wp_remote_retrieve_response_code($response);
        if (200 != $response_code) {
            error_log('Archiver: Failed to fetch snapshots for ' . $url . '. Status code: ' . $response_code);
            return array();
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data)) {
            error_log('Archiver: Empty response data for ' . $url);
            return array();
        }
        return $this->process_snapshot_data($data);
    }

    private function record_url_for_update($url, $priority = false) {
        $urls_to_update = get_option('archiver_urls_to_update', array());
        if (!in_array($url, $urls_to_update)) {
            if ($priority) {
                array_unshift($urls_to_update, $url);
            } else {
                $urls_to_update[] = $url;
            }
            update_option('archiver_urls_to_update', $urls_to_update);
        }
    }

    private function fetch_and_cache_snapshots($url) {
        $fetch_url = add_query_arg([
            'url' => $url,
            'output' => 'json',
        ], $this->wayback_machine_url_fetch_archives);
        $response = wp_remote_get($fetch_url, array(
            'timeout' => 30,
            'sslverify' => false
        ));
        if (is_wp_error($response)) {
            error_log('Archiver: Failed to fetch snapshots for ' . $url . '. Error: ' . $response->get_error_message());
            return false;
        }
        $response_code = wp_remote_retrieve_response_code($response);
        if (200 != $response_code) {
            error_log('Archiver: Failed to fetch snapshots for ' . $url . '. Status code: ' . $response_code);
            return false;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data)) {
            error_log('Archiver: Empty response data for ' . $url);
            return false;
        }
        $snapshots = $this->process_snapshot_data($data);
        set_transient('archiver_last_known_snapshots_' . md5($url), $snapshots, WEEK_IN_SECONDS);
        wp_cache_set('archiver_snapshots_' . md5($url), $snapshots, '', HOUR_IN_SECONDS);
        return true;
    }

    private function trigger_wayback_machine_snapshot($url) {
        $save_url = $this->wayback_machine_url_save . $url;
        $response = wp_remote_get($save_url, array(
            'timeout' => 10,
            'sslverify' => false
        ));
        if (is_wp_error($response)) {
            error_log('Archiver: Failed to trigger Wayback Machine snapshot for ' . $url . '. Error: ' . $response->get_error_message());
            return false;
        }
        $response_code = wp_remote_retrieve_response_code($response);
        if (200 == $response_code) {
            error_log('Archiver: Successfully triggered Wayback Machine snapshot for ' . $url);
            return true;
        }
        error_log('Archiver: Failed to trigger Wayback Machine snapshot for ' . $url . '. Status: ' . $response_code);
        return false;
    }

    private function process_snapshot_data($data) {
        if (empty($data)) {
            return array();
        }
        $field_columns = $data[0];
        unset($data[0]);
        $data = array_reverse($data);
        $data = array_slice($data, 0, $this->snapshot_max_count);
        $snapshots = array();
        foreach ($data as $snapshot) {
            $keyed_snapshot = array();
            foreach ($snapshot as $i => $field) {
                $keyed_snapshot[$field_columns[$i]] = $field;
            }
            $snapshots[] = $keyed_snapshot;
        }
        return $snapshots;
    }

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

    public function get_current_permalink_admin() {
        $permalink = '';
        $current_screen = get_current_screen();
        $object_type = $current_screen->base;
        switch ($object_type) {
            case 'post':
                global $post;
                if ($post && $post->ID) {
                    $permalink = get_permalink($post->ID);
                } else {
                    $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
                    if ($post_id) {
                        $permalink = get_permalink($post_id);
                    }
                }
                break;
            case 'term':
                global $taxnow, $tag;
                $taxonomy = $taxnow;
                $term_id = intval($tag->term_id);
                $permalink = get_term_link($term_id, $taxonomy);
                break;
            case 'profile':
            case 'user-edit':
                $user_id = !empty($_GET['user_id']) ? intval($_GET['user_id']) : get_current_user_id();
                $permalink = get_author_posts_url($user_id);
                break;
        }
        return apply_filters('archiver_permalink_admin', $permalink);
    }

    public function get_current_permalink_public() {
        global $wp;
        $permalink = home_url($wp->request);
        if (!empty($_SERVER['QUERY_STRING'])) {
            $permalink .= '?' . $_SERVER['QUERY_STRING'];
        }
        return apply_filters('archiver_permalink_public', $permalink);
    }

    public function register_scripts_and_styles() {
        wp_register_script(
            'archiver',
            ARCHIVER_PLUGIN_DIR_URL . 'js/archiver' . $this->min_suffix . '.js',
            array('jquery', 'wp-api-request', 'wp-i18n'),
            filemtime(ARCHIVER_PLUGIN_DIR_PATH . 'js/archiver' . $this->min_suffix . '.js'),
            true
        );
        wp_register_style(
            'archiver',
            ARCHIVER_PLUGIN_DIR_URL . 'css/archiver' . $this->min_suffix . '.css',
            array('dashicons'),
            filemtime(ARCHIVER_PLUGIN_DIR_PATH . 'css/archiver' . $this->min_suffix . '.css')
        );
        wp_localize_script('archiver', 'archiver', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('archiver/v1/trigger-snapshot'),
            'nonce' => wp_create_nonce('wp_rest'),
            'url' => $this->get_current_permalink(),
            'i18n' => array(
                'triggering' => __('Triggering snapshot...', 'archiver'),
                'success' => __('Snapshot triggered successfully!', 'archiver'),
                'error' => __('Failed to trigger snapshot.', 'archiver')
            )
        ));
    }

    public function enqueue_scripts() {
        $url = $this->get_current_permalink();
        if (!$url) {
            return;
        }
        wp_enqueue_script('archiver');
        wp_enqueue_style('archiver');
    }

    public function admin_enqueue_scripts($hook) {
        if ('tools_page_archiver-settings' === $hook) {
            wp_enqueue_style('archiver');
        }
        wp_enqueue_script('archiver');
    }

    public function do_admin_notice_disabled() {
        $id = 'archiver-notice-disabled';
        $dismiss_notice_key = 'archiver_dismiss_notice_' . $id;
        if (get_user_meta(get_current_user_id(), $dismiss_notice_key)) {
            return;
        }
        $class = 'archiver-notice notice notice-error is-dismissible';
        $message = __("Archiver is currently disabled via the <code>archiver_can_run</code> filter.", 'archiver');
        printf('<div id="%s" class="%s"><p>%s</p></div>', $id, $class, $message);
    }

    public function register_rest_routes() {
        register_rest_route('archiver/v1', '/trigger-snapshot', [
            'methods' => 'POST',
            'callback' => array($this, 'rest_trigger_snapshot'),
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            }
        ]);
    }

    public function rest_trigger_snapshot($request) {
        if (!current_user_can('edit_posts')) {
            return new WP_Error('rest_forbidden', __('You do not have permissions to trigger snapshots.', 'archiver'), array('status' => 401));
        }
        $url = $request->get_param('url');
        if (empty($url)) {
            return new WP_Error('rest_invalid_param', __('Invalid URL parameter.', 'archiver'), array('status' => 400));
        }
        $this->record_url_for_update($url, true);
        return new WP_REST_Response(array(
            'success' => true,
            'message' => __('Snapshot request recorded and will be processed soon.', 'archiver')
        ), 200);
    }

public function process_urls_for_update() {
    $urls_to_update = get_option('archiver_urls_to_update', array());
    $processed_urls = array();
    $selected_post_types = get_option('archiver_post_types', array('post', 'page'));
    $normalized_urls = array();
    $valid_urls = array();
    $total_archived = (int) get_option('archiver_total_archived', 0);
    $failed_snapshots = (int) get_option('archiver_failed_snapshots', 0);

    foreach ($urls_to_update as $url) {
        $normalized_url = $this->normalize_url($url);
        if (empty($normalized_url)) {
            $processed_urls[] = $url;
            continue;
        }
        if (!in_array($normalized_url, $normalized_urls)) {
            $normalized_urls[] = $normalized_url;
            $valid_urls[$normalized_url] = $url;
        } else {
            $processed_urls[] = $url;
            error_log("Archiver: Duplicate URL removed: $url");
        }
    }

    foreach ($normalized_urls as $normalized_url) {
        $url = $valid_urls[$normalized_url];
        $post_id = $this->get_post_id_from_url($url);
        if ($post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_status === 'draft' || $post->post_status === 'private' || !empty($post->post_password) || $this->is_preview_url($url)) {
                $processed_urls[] = $url;
                continue;
            }
        }
        if (!$post_id || ($post_id && in_array(get_post_type($post_id), $selected_post_types))) {
            $fetch_result = $this->fetch_and_cache_snapshots($normalized_url);
            $processed_urls[] = $url;
            if ($fetch_result) {
                $snapshot_result = $this->trigger_wayback_machine_snapshot($normalized_url);
                if ($snapshot_result) {
                    $total_archived++;
                } else {
                    $failed_snapshots++;
                }
            } else {
                $failed_snapshots++;
            }
            if (count($processed_urls) >= 5) {
                break;
            }
        } else {
            $processed_urls[] = $url;
        }
    }

    $remaining_urls = array_diff($urls_to_update, $processed_urls);
    update_option('archiver_urls_to_update', array_values($remaining_urls));
    update_option('archiver_last_run', current_time('mysql'));
    update_option('archiver_total_archived', $total_archived);
    update_option('archiver_failed_snapshots', $failed_snapshots);
    error_log("Archiver: Updated pending URLs: " . print_r($remaining_urls, true));
}

    private function normalize_url($url) {
        $parsed_url = parse_url($url);
        $normalized = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : 'https://';
        $normalized .= isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $normalized .= isset($parsed_url['path']) ? $parsed_url['path'] : '/';
        $normalized = preg_replace('/^www\./i', '', $normalized);
        $normalized = rtrim($normalized, '/');
        if (isset($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query_params);
            if (isset($query_params['preview'])) {
                error_log("Archiver: Excluding preview URL: $url");
                return '';
            }
            if (isset($query_params['p'])) {
                $post_id = intval($query_params['p']);
                $post = get_post($post_id);
                if ($post && $post->post_status === 'publish' && empty($post->post_password)) {
                    $normalized = get_permalink($post_id);
                    error_log("Archiver: Normalized $url to $normalized");
                } else {
                    error_log("Archiver: Excluding draft/protected URL: $url");
                    return '';
                }
            }
        }
        $normalized = rtrim($normalized, '/');
        error_log("Archiver: Normalized URL: $url -> $normalized");
        return $normalized;
    }

    private function get_post_id_from_url($url) {
        $post_id = url_to_postid($url);
        if (!$post_id) {
            $parsed_url = parse_url($url);
            if (isset($parsed_url['query'])) {
                parse_str($parsed_url['query'], $query_params);
                if (isset($query_params['p'])) {
                    $post_id = intval($query_params['p']);
                }
            }
        }
        return $post_id;
    }

    private function is_preview_url($url) {
        $parsed_url = parse_url($url);
        if (isset($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query_params);
            return isset($query_params['preview']) && $query_params['preview'] === 'true';
        }
        return false;
    }

    public function trigger_post_snapshot($post_id) {
        if ('publish' != get_post_status($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        $url = get_permalink($post_id);
        $this->trigger_url_snapshot($url);
    }

    public function trigger_term_snapshot($term_id, $taxonomy_id, $taxonomy) {
        $url = get_term_link($term_id, $taxonomy);
        if (is_wp_error($url)) {
            return;
        }
        $this->trigger_url_snapshot($url);
    }

    public function trigger_user_snapshot($user_id) {
        $url = get_author_posts_url($user_id);
        $this->trigger_url_snapshot($url);
    }

    public function trigger_url_snapshot($url) {
        if (empty($url)) {
            return new WP_Error('empty_url', __('URL cannot be empty.', 'archiver'));
        }
        $last_snapshot = get_transient('archiver_last_snapshot_' . md5($url));
        if ($last_snapshot) {
            return new WP_Error('snapshot_throttled', __('Snapshot already taken recently.', 'archiver'));
        }
        $response = wp_safe_remote_get($this->wayback_machine_url_save . $url, array(
            'timeout' => 30,
            'sslverify' => false
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $response_code = wp_remote_retrieve_response_code($response);
        if (200 === $response_code) {
            set_transient('archiver_last_snapshot_' . md5($url), time(), HOUR_IN_SECONDS);
            return true;
        } else {
            return new WP_Error('snapshot_failed', __('Failed to trigger snapshot.', 'archiver'));
        }
    }

    public function reschedule_cron_task($frequency) {
    $timestamp = wp_next_scheduled('archiver_process_urls');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'archiver_process_urls');
        error_log("Archiver: Cleared previous cron task scheduled at " . date('Y.m.d H:i', $timestamp));
    }

    $last_run = get_option('archiver_last_run'); // e.g., "2025-04-05 10:33:46"
    $start_time = time(); // Default to now

    if ($last_run && $frequency === 'hourly') {
        $last_run_timestamp = strtotime($last_run); // Convert to UTC timestamp
        $next_hour = strtotime('+1 hour', $last_run_timestamp); // Next hourly slot
        if ($next_hour > time()) {
            $start_time = $next_hour; // Schedule from next hour after last run
        }
    }

    $result = wp_schedule_event($start_time, $frequency, 'archiver_process_urls');
    if ($result === false) {
        error_log("Archiver: Failed to schedule cron task with frequency $frequency");
    } else {
        $next_run = wp_next_scheduled('archiver_process_urls');
        error_log("Archiver: Cron task rescheduled to $frequency, next run: " . date('Y.m.d H:i', $next_run) . " (Timezone: " . wp_timezone_string() . ")");
    }
}

    public static function deactivate() {
        $timestamp = wp_next_scheduled('archiver_process_urls');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'archiver_process_urls');
        }
        delete_option('archiver_urls_to_update');
    }
}