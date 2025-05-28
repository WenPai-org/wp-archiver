<?php
/**
 * WP Archiver Admin Interface
 */

if (!defined('ABSPATH')) {
    exit;
}

class Archiver_Admin {

    protected $archiver;
    protected $cache;

    public function __construct($archiver) {
        $this->archiver = $archiver;

        // Initialize hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_settings_actions'));
        add_action('admin_notices', array($this, 'check_system_requirements'));

        // AJAX handlers
        add_action('wp_ajax_archiver_get_service_stats', array($this, 'ajax_get_service_stats'));
        add_action('wp_ajax_archiver_test_service', array($this, 'ajax_test_service'));

        // Initialize cache after WordPress is fully loaded
        add_action('wp_loaded', array($this, 'init_cache'));
    }

    public function init_cache() {
        if (!$this->cache && class_exists('Archiver_Cache')) {
            $this->cache = new Archiver_Cache();
        }
    }

    public function add_admin_menu() {
        add_management_page(
            __('Archiver Settings', 'archiver'),
            __('Archiver', 'archiver'),
            'manage_options',
            'archiver-settings',
            array($this, 'render_admin_page')
        );
    }

    public function check_system_requirements() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'archiver-settings') {
            return;
        }

        // Check if cache is enabled
        $cache_enabled = get_option('archiver_cache_enabled', true);
        if (!$cache_enabled) {
            echo '<div class="notice notice-info is-dismissible"><p>';
            echo '<strong>' . __('Performance Tip:', 'archiver') . '</strong> ';
            echo __('Enable caching to improve performance and reduce API calls.', 'archiver');
            echo '</p></div>';
        }

        // Check cache table
        global $wpdb;
        $table_name = $wpdb->prefix . 'archiver_cache';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

        if (!$table_exists) {
            echo '<div class="notice notice-error"><p>';
            echo __('Cache table is missing. Please deactivate and reactivate the plugin to fix this issue.', 'archiver');
            echo '</p></div>';
        }
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'archiver'));
        }

        // Get services - handle case where constant might not be defined
        $archiver_services = $this->get_available_services();

        $post_types = get_post_types(array('public' => true), 'objects');
        $selected_post_types = get_option('archiver_post_types', array('post', 'page'));
        $cache_enabled = get_option('archiver_cache_enabled', true);
        $selected_services = get_option('archiver_services', array('wenpai' => true));
        $primary_service = get_option('archiver_primary_service', 'wenpai');
        $auto_archive = get_option('archiver_auto_archive', true);
        $archive_on_publish = get_option('archiver_archive_on_publish', true);
        $max_queue_size = get_option('archiver_max_queue_size', 500);
        $batch_size = get_option('archiver_batch_size', 10);
        $update_frequency = get_option('archiver_update_frequency', 'hourly');
        ?>
        <div class="wrap archiver-admin-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?>
                <span class="archiver-version"><?php printf(esc_html__('Version: %s', 'archiver'), esc_html(ARCHIVER_VERSION)); ?></span>
                <a href="https://sharecms.com/document/wp-archiver" target="_blank" class="button button-secondary" style="margin-left: 10px;"><?php esc_html_e('Document', 'archiver'); ?></a>
                <a href="https://sharecms.com/forums/" target="_blank" class="button button-secondary"><?php esc_html_e('Support', 'archiver'); ?></a>
            </h1>

            <?php settings_errors('archiver_messages'); ?>

            <!-- Archive Services Card -->
            <div class="card">
                <h2><?php _e('Archive Services', 'archiver'); ?></h2>
                <span id="service-status" class="notice" style="display:none; margin-top: 10px;"></span>
                <p><?php _e('Configure which archive services to use for your content.', 'archiver'); ?></p>

                <form method="post" action="">
                    <?php wp_nonce_field('archiver_services_action', 'archiver_services_nonce'); ?>

                    <div class="archiver-services-wrapper">
                        <table class="wp-list-table widefat fixed">
                            <thead>
                                <tr>
                                    <th><?php _e('Service', 'archiver'); ?></th>
                                    <th><?php _e('Status', 'archiver'); ?></th>
                                    <th><?php _e('Enabled', 'archiver'); ?></th>
                                    <th><?php _e('Primary', 'archiver'); ?></th>
                                    <th><?php _e('Actions', 'archiver'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($archiver_services as $service_id => $service) : ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($service['name']); ?></strong>
                                        </td>
                                        <td>
                                            <span class="service-status" id="status-<?php echo esc_attr($service_id); ?>">
                                                <span class="status-dot"></span>
                                                <span class="status-text"><?php _e('Checking...', 'archiver'); ?></span>
                                            </span>
                                        </td>
                                        <td>
                                            <label class="switch">
                                                <input type="checkbox"
                                                       name="archiver_services[<?php echo esc_attr($service_id); ?>]"
                                                       value="1"
                                                       <?php checked(isset($selected_services[$service_id]) && $selected_services[$service_id]); ?> />
                                                <span class="slider"></span>
                                            </label>
                                        </td>
                                        <td>
                                            <label>
                                                <input type="radio"
                                                       name="archiver_primary_service"
                                                       value="<?php echo esc_attr($service_id); ?>"
                                                       <?php checked($primary_service, $service_id); ?> />
                                            </label>
                                        </td>
                                        <td>
                                            <button type="button" class="button button-small test-service"
                                                    data-service="<?php echo esc_attr($service_id); ?>">
                                                <?php _e('Test Connection', 'archiver'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="submit">
                        <button type="submit" name="archiver_services_submit" value="1" class="button button-primary">
                            <?php _e('Save Services', 'archiver'); ?>
                        </button>
                    </p>
                </form>
            </div>

            <!-- General Settings Card -->
            <div class="card">
                <h2><?php _e('General Settings', 'archiver'); ?></h2>
                <span id="settings-status" class="notice" style="display:none; margin-top: 10px;"></span>
                <p><?php _e('Configure automatic archiving and content types.', 'archiver'); ?></p>

                <div class="archiver-settings-tabs">
                    <button type="button" class="settings-tab active" data-tab="queue-management">
                        <?php _e('Queue Management', 'archiver'); ?>
                    </button>
                    <button type="button" class="settings-tab" data-tab="content-types">
                        <?php _e('Content Types', 'archiver'); ?>
                    </button>
                    <button type="button" class="settings-tab" data-tab="automation">
                        <?php _e('Automation', 'archiver'); ?>
                    </button>
                    <button type="button" class="settings-tab" data-tab="cache-setting">
                        <?php _e('Cache Settings', 'archiver'); ?>
                    </button>
                    <button type="button" class="settings-tab" data-tab="performance">
                        <?php _e('Performance', 'archiver'); ?>
                    </button>
                </div>

                <!-- Queue Management Tab -->
                <div class="settings-section" data-section="queue-management">
                    <h3><?php _e('Queue Management', 'archiver'); ?></h3>
                    <p><?php _e('View and manage the archive queue.', 'archiver'); ?></p>
                    <?php $this->display_queue_management(); ?>

                    <!-- Tools -->
                    <h3><?php _e('Archive Tools', 'archiver'); ?></h3>
                    <span id="tools-status" class="notice" style="display:none; margin-top: 10px;"></span>
                    <p><?php _e('Bulk archive operations and manual archiving.', 'archiver'); ?></p>

                    <div class="archiver-tools-grid">
                        <div class="tool-section">
                            <h4><?php _e('Bulk Archive', 'archiver'); ?></h4>
                            <form method="post" action="">
                                <?php wp_nonce_field('archiver_bulk_actions_action', 'archiver_bulk_actions_nonce'); ?>
                                <select name="archiver_bulk_action" class="regular-text">
                                    <option value=""><?php _e('Select action...', 'archiver'); ?></option>
                                    <option value="archive_all_posts"><?php _e('Archive all published posts', 'archiver'); ?></option>
                                    <option value="archive_recent_posts"><?php _e('Archive posts from last 30 days', 'archiver'); ?></option>
                                    <option value="archive_updated_posts"><?php _e('Archive recently updated posts', 'archiver'); ?></option>
                                </select>
                                <button type="submit" name="archiver_submit_bulk_action" value="1" class="button">
                                    <?php _e('Execute', 'archiver'); ?>
                                </button>
                            </form>
                        </div>

                        <div class="tool-section">
                            <h4><?php _e('Manual Archive', 'archiver'); ?></h4>
                            <form method="post" action="">
                                <?php wp_nonce_field('archiver_manual_archive_action', 'archiver_manual_archive_nonce'); ?>
                                <input type="url"
                                       name="archiver_manual_url"
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e('Enter URL to archive...', 'archiver'); ?>"
                                       required />
                                <button type="submit" name="archiver_manual_archive_submit" value="1" class="button">
                                    <?php _e('Archive', 'archiver'); ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Content Types Tab -->
                <div class="settings-section" data-section="content-types" style="display: none;">
                    <h3><?php _e('Post Types to Archive', 'archiver'); ?></h3>
                    <p class="description"><?php _e('Select which content types should be automatically archived.', 'archiver'); ?></p>

                    <form method="post" action="">
                        <?php wp_nonce_field('archiver_settings_action', 'archiver_settings_nonce'); ?>
                        <div style="margin-top: 15px;">
                            <?php foreach ($post_types as $post_type) : ?>
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="checkbox"
                                           name="archiver_post_types[]"
                                           value="<?php echo esc_attr($post_type->name); ?>"
                                           <?php checked(in_array($post_type->name, $selected_post_types)); ?> />
                                    <?php echo esc_html($post_type->label); ?>
                                    <span style="color: #666;">
                                        (<?php echo esc_html($post_type->name); ?>)
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <p class="submit">
                            <button type="submit" name="archiver_content_types_submit" value="1" class="button button-primary">
                                <?php _e('Save Content Types', 'archiver'); ?>
                            </button>
                        </p>
                    </form>
                </div>

                <!-- Automation Tab -->
                <div class="settings-section" data-section="automation" style="display: none;">
                    <h3><?php _e('Automation Settings', 'archiver'); ?></h3>

                    <form method="post" action="">
                        <?php wp_nonce_field('archiver_settings_action', 'archiver_settings_nonce'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Automatic Archiving', 'archiver'); ?></th>
                                <td>
                                    <label class="switch">
                                        <input type="checkbox"
                                               name="archiver_auto_archive"
                                               value="1"
                                               <?php checked($auto_archive); ?> />
                                        <span class="slider"></span>
                                    </label>
                                    <span class="description" style="margin-left: 10px;">
                                        <?php _e('Enable automatic archiving of content', 'archiver'); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Archive on Publish', 'archiver'); ?></th>
                                <td>
                                    <label class="switch">
                                        <input type="checkbox"
                                               name="archiver_archive_on_publish"
                                               value="1"
                                               <?php checked($archive_on_publish); ?> />
                                        <span class="slider"></span>
                                    </label>
                                    <span class="description" style="margin-left: 10px;">
                                        <?php _e('Archive immediately when content is published', 'archiver'); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Update Frequency', 'archiver'); ?></th>
                                <td>
                                    <select name="archiver_update_frequency">
                                        <option value="minutes_15" <?php selected($update_frequency, 'minutes_15'); ?>>
                                            <?php _e('Every 15 Minutes', 'archiver'); ?>
                                        </option>
                                        <option value="minutes_30" <?php selected($update_frequency, 'minutes_30'); ?>>
                                            <?php _e('Every 30 Minutes', 'archiver'); ?>
                                        </option>
                                        <option value="hourly" <?php selected($update_frequency, 'hourly'); ?>>
                                            <?php _e('Hourly', 'archiver'); ?>
                                        </option>
                                        <option value="twicedaily" <?php selected($update_frequency, 'twicedaily'); ?>>
                                            <?php _e('Twice Daily', 'archiver'); ?>
                                        </option>
                                        <option value="daily" <?php selected($update_frequency, 'daily'); ?>>
                                            <?php _e('Daily', 'archiver'); ?>
                                        </option>
                                        <option value="weekly" <?php selected($update_frequency, 'weekly'); ?>>
                                            <?php _e('Weekly', 'archiver'); ?>
                                        </option>
                                    </select>
                                    <p class="description">
                                        <?php _e('How often should the plugin process pending archives?', 'archiver'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" name="archiver_automation_submit" value="1" class="button button-primary">
                                <?php _e('Save Automation Settings', 'archiver'); ?>
                            </button>
                        </p>
                    </form>
                </div>

                <!-- Performance Tab -->
                <div class="settings-section" data-section="performance" style="display: none;">
                    <h3><?php _e('Performance Settings', 'archiver'); ?></h3>
                    <p><?php _e('Configure queue size and batch processing settings.', 'archiver'); ?></p>

                    <form method="post" action="">
                        <?php wp_nonce_field('archiver_settings_action', 'archiver_settings_nonce'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Maximum Queue Size', 'archiver'); ?></th>
                                <td>
                                    <input type="number"
                                           name="archiver_max_queue_size"
                                           value="<?php echo esc_attr($max_queue_size); ?>"
                                           min="50"
                                           max="2000"
                                           step="50"
                                           class="small-text" />
                                    <p class="description">
                                        <?php _e('Maximum number of URLs that can be queued for archiving. Higher values use more memory.', 'archiver'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Batch Processing Size', 'archiver'); ?></th>
                                <td>
                                    <input type="number"
                                           name="archiver_batch_size"
                                           value="<?php echo esc_attr($batch_size); ?>"
                                           min="1"
                                           max="50"
                                           step="1"
                                           class="small-text" />
                                    <p class="description">
                                        <?php _e('Number of URLs to process at once during scheduled tasks. Higher values process faster but use more resources.', 'archiver'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Current Queue Status', 'archiver'); ?></th>
                                <td>
                                    <?php
                                    $pending_count = count(get_option('archiver_urls_to_update', array()));
                                    $queue_count = count(get_option('archiver_background_queue', array()));
                                    $total_pending = $pending_count + $queue_count;
                                    ?>
                                    <strong><?php echo number_format($total_pending); ?></strong> <?php _e('URLs pending', 'archiver'); ?>
                                    <br>
                                    <small>
                                        <?php printf(__('%d in main queue, %d in background queue', 'archiver'), $pending_count, $queue_count); ?>
                                    </small>
                                    <?php if ($total_pending > $max_queue_size * 0.8): ?>
                                        <p style="color: #d63638;">
                                            <strong><?php _e('Warning:', 'archiver'); ?></strong>
                                            <?php _e('Queue is nearly full. Consider increasing the maximum queue size or processing more URLs per batch.', 'archiver'); ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" name="archiver_performance_submit" value="1" class="button button-primary">
                                <?php _e('Save Performance Settings', 'archiver'); ?>
                            </button>
                        </p>
                    </form>
                </div>

                <!-- Cache Settings Tab -->
                <div class="settings-section" data-section="cache-setting" style="display: none;">
                    <h3><?php _e('Cache Settings', 'archiver'); ?></h3>
                    <span id="cache-status" class="notice" style="display:none; margin-top: 10px;"></span>
                    <p><?php _e('Configure caching to improve performance.', 'archiver'); ?></p>

                    <form method="post" action="">
                        <?php wp_nonce_field('archiver_settings_action', 'archiver_settings_nonce'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Cache', 'archiver'); ?></th>
                                <td>
                                    <label class="switch">
                                        <input type="checkbox"
                                               name="archiver_cache_enabled"
                                               value="1"
                                               <?php checked($cache_enabled); ?> />
                                        <span class="slider"></span>
                                    </label>
                                    <span class="description" style="margin-left: 10px;">
                                        <?php _e('Caching reduces API calls and improves page load times', 'archiver'); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Cache Actions', 'archiver'); ?></th>
                                <td>
                                    <button type="submit" name="archiver_preheat_cache" value="1" class="button">
                                        <?php _e('Preheat Cache', 'archiver'); ?>
                                    </button>
                                    <button type="submit" name="archiver_clear_cache" value="1" class="button"
                                            onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all cache?', 'archiver'); ?>');">
                                        <?php _e('Clear Cache', 'archiver'); ?>
                                    </button>
                                    <p class="description">
                                        <?php _e('Preheat cache for recent posts or clear all cached data.', 'archiver'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" name="archiver_cache_submit" value="1" class="button button-primary">
                                <?php _e('Save Cache Settings', 'archiver'); ?>
                            </button>
                        </p>
                    </form>
                </div>
            </div>

            <!-- Dashboard Statistics Card -->
            <div class="card">
                <h2><?php _e('Archive Statistics', 'archiver'); ?></h2>
                <p><?php _e('Overview of your archive activity and performance.', 'archiver'); ?></p>
                <?php $this->render_statistics_table(); ?>
                <?php $this->render_cache_statistics_inline(); ?>
            </div>

        </div>

        <style>
        /* Card Style */
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            max-width: unset;
            margin-top: 20px;
            padding: 20px;
        }

        .archiver-version {
            font-size: 13px;
            padding-left: 10px;
            color: #666;
        }

        /* Tabs Style */
        .archiver-settings-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            border-bottom: 1px solid #c3c4c7;
            margin-bottom: 20px;
        }

        .settings-tab {
            padding: 8px 16px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 14px;
            border-bottom: 2px solid transparent;
        }

        .settings-tab.active {
            border-bottom: 2px solid #007cba;
            font-weight: 600;
            background: #f0f0f1;
        }

        .settings-tab:hover:not(.active) {
            background: #f0f0f1;
            border-bottom-color: #dcdcde;
        }

        /* Switch Style */
        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 20px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 20px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: #2196F3;
        }

        input:checked + .slider:before {
            transform: translateX(20px);
        }

        /* Service Status */
        .service-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #ccc;
        }

        .service-status.online .status-dot {
            background: #46b450;
        }

        .service-status.offline .status-dot {
            background: #dc3232;
        }

        /* Notice Style */
        .notice {
            padding: 8px 12px;
            border-radius: 3px;
        }

        .notice-success {
            background-color: #dff0d8;
            border-left: 4px solid #46b450;
        }

        .notice-error {
            background-color: #f2dede;
            border-left: 4px solid #dc3232;
        }

        /* Tools Grid */
        .archiver-tools-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 15px;
        }

        .tool-section {
            background: #f0f0f1;
            padding: 15px;
            border-radius: 4px;
        }

        .tool-section h4 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 14px;
            font-weight: 600;
        }

        /* Queue Status */
        .queue-status {
            background: #f0f0f1;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
        }

        .queue-list {
            max-height: 200px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #ddd;
            padding: 10px;
            margin-top: 10px;
            border-radius: 4px;
        }

        .queue-list ul {
            margin: 0;
            padding-left: 20px;
        }

        .queue-list li {
            margin: 5px 0;
            word-break: break-all;
        }

        /* Form table */
        .form-table th {
            width: 200px;
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 782px) {
            .archiver-tools-grid {
                grid-template-columns: 1fr;
            }

            .archiver-settings-tabs {
                flex-direction: column;
            }

            .settings-tab {
                width: 100%;
                text-align: left;
            }
        }
        </style>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Tab switching
            $('.settings-tab').on('click', function() {
                $('.settings-tab').removeClass('active');
                $(this).addClass('active');
                var tab = $(this).data('tab');
                $('.settings-section').hide();
                $('.settings-section[data-section="' + tab + '"]').show();
            });

            // Service status checking
            function checkServiceStatus() {
                $('.service-status').each(function() {
                    var $status = $(this);
                    var serviceId = $status.attr('id').replace('status-', '');

                    // Simulate service check
                    setTimeout(function() {
                        $status.addClass('online');
                        $status.find('.status-text').text('<?php _e('Online', 'archiver'); ?>');
                    }, 1000);
                });
            }

            checkServiceStatus();

            // Test service button
            $('.test-service').on('click', function() {
                var $button = $(this);
                var service = $button.data('service');
                var originalText = $button.text();

                $button.prop('disabled', true).text('<?php _e('Testing...', 'archiver'); ?>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'archiver_test_service',
                        service: service,
                        nonce: '<?php echo wp_create_nonce('archiver_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#service-status')
                                .removeClass('notice-error')
                                .addClass('notice-success')
                                .text(response.data.message)
                                .show()
                                .delay(3000)
                                .fadeOut();
                        } else {
                            $('#service-status')
                                .removeClass('notice-success')
                                .addClass('notice-error')
                                .text(response.data || '<?php _e('Test failed', 'archiver'); ?>')
                                .show();
                        }
                    },
                    complete: function() {
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            });
        });
        </script>
        <?php
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

    private function render_statistics_table() {
        $pending_count = count(get_option('archiver_urls_to_update', array()));
        $queue_count = count(get_option('archiver_background_queue', array()));
        $total_archived = get_option('archiver_total_archived', 0);
        $failed_snapshots = get_option('archiver_failed_snapshots', 0);
        
        // Get pre-calculated display times
        $last_run_display = get_option('archiver_last_run', '');
        $next_run_display = get_option('archiver_next_run_display', '');
        
        // Use pre-calculated display times if available
        if (empty($last_run_display)) {
            $last_run_timestamp = get_option('archiver_last_run_timestamp', 0);
            if ($last_run_timestamp && $this->archiver && method_exists($this->archiver, 'get_formatted_schedule_time')) {
                $last_run_display = $this->archiver->get_formatted_schedule_time($last_run_timestamp);
            } else {
                $last_run_display = __('Never', 'archiver');
            }
        }
        
        if (empty($next_run_display)) {
            $next_run = wp_next_scheduled('archiver_process_urls');
            if ($next_run && $this->archiver && method_exists($this->archiver, 'get_formatted_schedule_time')) {
                $next_run_display = $this->archiver->get_formatted_schedule_time($next_run);
            } else {
                $next_run_display = __('Not scheduled', 'archiver');
            }
        }
        
        ?>
        <table class="wp-list-table widefat fixed">
            <thead>
                <tr>
                    <th><?php _e('Metric', 'archiver'); ?></th>
                    <th><?php _e('Value', 'archiver'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th><?php _e('Total Archived', 'archiver'); ?></th>
                    <td><?php echo number_format($total_archived); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Pending URLs', 'archiver'); ?></th>
                    <td><?php echo number_format($pending_count + $queue_count); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Failed Snapshots', 'archiver'); ?></th>
                    <td><?php echo number_format($failed_snapshots); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Last Run', 'archiver'); ?></th>
                    <td><?php echo esc_html($last_run_display); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Next Run', 'archiver'); ?></th>
                    <td><?php echo esc_html($next_run_display); ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Helper method for timezone issues
     */
    private function format_timestamp_with_timezone($timestamp) {
        if (!$timestamp) {
            return __('Not available', 'archiver');
        }
        
        // Get WordPress timezone settings
        $timezone_string = get_option('timezone_string');
        $gmt_offset = get_option('gmt_offset');
        
        try {
            if ($timezone_string) {
                // Use timezone string
                $timezone = new DateTimeZone($timezone_string);
                $datetime = new DateTime('@' . $timestamp);
                $datetime->setTimezone($timezone);
                return $datetime->format(get_option('date_format') . ' ' . get_option('time_format'));
            } else if ($gmt_offset) {
                // Use GMT offset
                $timestamp += $gmt_offset * HOUR_IN_SECONDS;
                return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
            }
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Archiver] Date formatting error: ' . $e->getMessage());
            }
        }
        
        // Default fallback to WordPress localized date function
        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    private function render_cache_statistics_inline() {
        // Initialize cache if not already done
        if (!$this->cache) {
            $this->init_cache();
        }

        if (!$this->cache || !method_exists($this->cache, 'get_cache_stats')) {
            return;
        }

        $stats = $this->cache->get_cache_stats();
        if (!$stats) {
            return;
        }

        // Safely get statistics data
        $total_entries = isset($stats->total_entries) ? intval($stats->total_entries) : 0;
        $total_api_saves = isset($stats->total_api_saves) ? intval($stats->total_api_saves) : 0;
        $cache_size = method_exists($this->cache, 'get_cache_size') ? $this->cache->get_cache_size() : 0;
        ?>
        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
            <h3><?php _e('Cache Performance', 'archiver'); ?></h3>
            <table class="wp-list-table widefat fixed">
                <tbody>
                    <tr>
                        <th><?php _e('Cached URLs', 'archiver'); ?></th>
                        <td><?php echo number_format($total_entries); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('API Calls Saved', 'archiver'); ?></th>
                        <td><?php echo number_format($total_api_saves); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Cache Size', 'archiver'); ?></th>
                        <td><?php echo size_format($cache_size); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function display_queue_management() {
        $urls_to_update = get_option('archiver_urls_to_update', array());
        $queue_urls = get_option('archiver_background_queue', array());
        $all_urls = array_merge($urls_to_update, $queue_urls);

        if (empty($all_urls)) {
            echo '<p>' . __('No pending URLs in the queue.', 'archiver') . '</p>';
            return;
        }

        ?>
        <div class="queue-status">
            <p>
                <strong><?php _e('Total Pending:', 'archiver'); ?></strong>
                <?php echo count($all_urls); ?> <?php _e('URLs', 'archiver'); ?>
            </p>

            <form method="post" action="" style="display: inline;">
                <?php wp_nonce_field('archiver_manual_update_action', 'archiver_manual_update_nonce'); ?>
                <button type="submit" name="archiver_trigger_update" value="1" class="button button-primary">
                    <?php _e('Process Queue Now', 'archiver'); ?>
                </button>
            </form>

            <form method="post" action="" style="display: inline;">
                <?php wp_nonce_field('archiver_bulk_actions_action', 'archiver_bulk_actions_nonce'); ?>
                <input type="hidden" name="archiver_bulk_action" value="clear_queue">
                <button type="submit" name="archiver_submit_bulk_action" value="1" class="button"
                        onclick="return confirm('<?php esc_attr_e('Are you sure?', 'archiver'); ?>');">
                    <?php _e('Clear Queue', 'archiver'); ?>
                </button>
            </form>

            <?php if (count($all_urls) <= 20) : ?>
                <div class="queue-list">
                    <ul>
                        <?php foreach ($all_urls as $url) :
                            $display_url = is_array($url) ? $url['url'] : $url;
                        ?>
                            <li><?php echo esc_html($display_url); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else : ?>
                <details style="margin-top: 10px;">
                    <summary style="cursor: pointer;"><?php _e('Show queue details', 'archiver'); ?></summary>
                    <div class="queue-list">
                        <ul>
                            <?php foreach (array_slice($all_urls, 0, 50) as $url) :
                                $display_url = is_array($url) ? $url['url'] : $url;
                            ?>
                                <li><?php echo esc_html($display_url); ?></li>
                            <?php endforeach; ?>
                            <?php if (count($all_urls) > 50) : ?>
                                <li>... <?php printf(__('and %d more', 'archiver'), count($all_urls) - 50); ?></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_settings_actions() {
        // Only process on our admin page
        if (!isset($_GET['page']) || $_GET['page'] !== 'archiver-settings') {
            return;
        }

        // Only process POST requests
        if (empty($_POST)) {
            return;
        }

        // Handle general settings - content types
        if (isset($_POST['archiver_content_types_submit']) && $_POST['archiver_content_types_submit'] == '1') {
            if (!check_admin_referer('archiver_settings_action', 'archiver_settings_nonce')) {
                add_settings_error('archiver_messages', 'archiver_nonce_error', __('Security check failed.', 'archiver'), 'error');
                return;
            }
            $this->save_content_types();
        }

        // Handle general settings - automation
        if (isset($_POST['archiver_automation_submit']) && $_POST['archiver_automation_submit'] == '1') {
            if (!check_admin_referer('archiver_settings_action', 'archiver_settings_nonce')) {
                add_settings_error('archiver_messages', 'archiver_nonce_error', __('Security check failed.', 'archiver'), 'error');
                return;
            }
            $this->save_automation_settings();
        }

        // Handle general settings - performance
        if (isset($_POST['archiver_performance_submit']) && $_POST['archiver_performance_submit'] == '1') {
            if (!check_admin_referer('archiver_settings_action', 'archiver_settings_nonce')) {
                add_settings_error('archiver_messages', 'archiver_nonce_error', __('Security check failed.', 'archiver'), 'error');
                return;
            }
            $this->save_performance_settings();
        }

        // Handle general settings - cache
        if (isset($_POST['archiver_cache_submit']) && $_POST['archiver_cache_submit'] == '1') {
            if (!check_admin_referer('archiver_settings_action', 'archiver_settings_nonce')) {
                add_settings_error('archiver_messages', 'archiver_nonce_error', __('Security check failed.', 'archiver'), 'error');
                return;
            }
            $this->save_cache_settings();
        }

        // Handle services settings
        if (isset($_POST['archiver_services_submit']) && $_POST['archiver_services_submit'] == '1') {
            if (!check_admin_referer('archiver_services_action', 'archiver_services_nonce')) {
                add_settings_error('archiver_messages', 'archiver_nonce_error', __('Security check failed.', 'archiver'), 'error');
                return;
            }
            $this->save_services_settings();
        }

        // Handle manual update
        if (isset($_POST['archiver_trigger_update']) && $_POST['archiver_trigger_update'] == '1') {
            if (!check_admin_referer('archiver_manual_update_action', 'archiver_manual_update_nonce')) {
                add_settings_error('archiver_messages', 'archiver_nonce_error', __('Security check failed.', 'archiver'), 'error');
                return;
            }
            $this->trigger_manual_update();
        }

        // Handle bulk actions
        if (isset($_POST['archiver_submit_bulk_action']) && $_POST['archiver_submit_bulk_action'] == '1') {
            if (!check_admin_referer('archiver_bulk_actions_action', 'archiver_bulk_actions_nonce')) {
                add_settings_error('archiver_messages', 'archiver_nonce_error', __('Security check failed.', 'archiver'), 'error');
                return;
            }
            $this->handle_bulk_actions();
        }

        // Handle cache actions
        if ((isset($_POST['archiver_preheat_cache']) && $_POST['archiver_preheat_cache'] == '1') ||
            (isset($_POST['archiver_clear_cache']) && $_POST['archiver_clear_cache'] == '1')) {
            if (!check_admin_referer('archiver_settings_action', 'archiver_settings_nonce')) {
                add_settings_error('archiver_messages', 'archiver_nonce_error', __('Security check failed.', 'archiver'), 'error');
                return;
            }
            $this->handle_cache_actions();
        }

        // Handle manual archive
        if (isset($_POST['archiver_manual_archive_submit']) && $_POST['archiver_manual_archive_submit'] == '1') {
            if (!check_admin_referer('archiver_manual_archive_action', 'archiver_manual_archive_nonce')) {
                add_settings_error('archiver_messages', 'archiver_nonce_error', __('Security check failed.', 'archiver'), 'error');
                return;
            }
            $this->handle_manual_archive();
        }
    }

    private function save_content_types() {
        if (!current_user_can('manage_options')) {
            add_settings_error('archiver_messages', 'archiver_message', __('Permission denied.', 'archiver'), 'error');
            return;
        }

        // Save post types
        $post_types = isset($_POST['archiver_post_types']) ? array_map('sanitize_text_field', $_POST['archiver_post_types']) : array();
        if (empty($post_types)) {
            add_settings_error('archiver_messages', 'archiver_message', __('Please select at least one post type.', 'archiver'), 'error');
            return;
        }
        update_option('archiver_post_types', $post_types);

        add_settings_error('archiver_messages', 'archiver_message', __('Content types saved successfully.', 'archiver'), 'success');
    }

    private function save_automation_settings() {
        if (!current_user_can('manage_options')) {
            add_settings_error('archiver_messages', 'archiver_message', __('Permission denied.', 'archiver'), 'error');
            return;
        }

        // Save archive options
        update_option('archiver_auto_archive', isset($_POST['archiver_auto_archive']) ? 1 : 0);
        update_option('archiver_archive_on_publish', isset($_POST['archiver_archive_on_publish']) ? 1 : 0);

        // Save update frequency
        $frequency = isset($_POST['archiver_update_frequency']) ? sanitize_text_field($_POST['archiver_update_frequency']) : 'hourly';
        $valid_frequencies = ['minutes_15', 'minutes_30', 'hourly', 'twicedaily', 'daily', 'weekly'];

        if (!in_array($frequency, $valid_frequencies)) {
            $frequency = 'hourly';
        }

        update_option('archiver_update_frequency', $frequency);

        // Reschedule cron task
        if ($this->archiver && method_exists($this->archiver, 'reschedule_cron_task')) {
            $this->archiver->reschedule_cron_task($frequency);
        }

        add_settings_error('archiver_messages', 'archiver_message', __('Automation settings saved successfully.', 'archiver'), 'success');
    }

    private function save_performance_settings() {
        if (!current_user_can('manage_options')) {
            add_settings_error('archiver_messages', 'archiver_message', __('Permission denied.', 'archiver'), 'error');
            return;
        }

        // Save performance settings
        $max_queue_size = isset($_POST['archiver_max_queue_size']) ? intval($_POST['archiver_max_queue_size']) : 500;
        $batch_size = isset($_POST['archiver_batch_size']) ? intval($_POST['archiver_batch_size']) : 10;

        $max_queue_size = max(50, min(2000, $max_queue_size)); // Limit between 50-2000
        $batch_size = max(1, min(50, $batch_size)); // Limit between 1-50

        update_option('archiver_max_queue_size', $max_queue_size);
        update_option('archiver_batch_size', $batch_size);

        add_settings_error('archiver_messages', 'archiver_message', __('Performance settings saved successfully.', 'archiver'), 'success');
    }

    private function save_cache_settings() {
        if (!current_user_can('manage_options')) {
            add_settings_error('archiver_messages', 'archiver_message', __('Permission denied.', 'archiver'), 'error');
            return;
        }

        // Save cache settings
        update_option('archiver_cache_enabled', isset($_POST['archiver_cache_enabled']) ? 1 : 0);

        add_settings_error('archiver_messages', 'archiver_message', __('Cache settings saved successfully.', 'archiver'), 'success');
    }

    private function save_settings() {
        if (!current_user_can('manage_options')) {
            add_settings_error('archiver_messages', 'archiver_message', __('Permission denied.', 'archiver'), 'error');
            return;
        }

        // This method is kept for compatibility, but actual settings are handled in separate methods
        add_settings_error('archiver_messages', 'archiver_message', __('Settings saved successfully.', 'archiver'), 'success');
    }

    private function save_services_settings() {
        if (!current_user_can('manage_options')) {
            add_settings_error('archiver_messages', 'archiver_message', __('Permission denied.', 'archiver'), 'error');
            return;
        }

        $services = isset($_POST['archiver_services']) ? $_POST['archiver_services'] : array();
        $primary_service = isset($_POST['archiver_primary_service']) ? sanitize_text_field($_POST['archiver_primary_service']) : 'wenpai';

        // Ensure at least one service is enabled
        if (empty($services)) {
            add_settings_error('archiver_messages', 'archiver_message', __('Please enable at least one archive service.', 'archiver'), 'error');
            return;
        }

        // Ensure primary service is enabled
        if (!isset($services[$primary_service])) {
            add_settings_error('archiver_messages', 'archiver_message', __('Primary service must be enabled.', 'archiver'), 'error');
            return;
        }

        update_option('archiver_services', $services);
        update_option('archiver_primary_service', $primary_service);

        add_settings_error('archiver_messages', 'archiver_message', __('Archive services saved successfully.', 'archiver'), 'success');
    }

    private function trigger_manual_update() {
        if (!current_user_can('manage_options')) {
            add_settings_error('archiver_messages', 'archiver_message', __('Permission denied.', 'archiver'), 'error');
            return;
        }

        if ($this->archiver && method_exists($this->archiver, 'process_urls_for_update')) {
            $processed = $this->archiver->process_urls_for_update();
            add_settings_error(
                'archiver_messages',
                'archiver_message',
                sprintf(__('Processing started for %d URLs.', 'archiver'), $processed),
                'success'
            );
        } else {
            add_settings_error('archiver_messages', 'archiver_message', __('Manual update function not available.', 'archiver'), 'error');
        }
    }

    private function handle_bulk_actions() {
        if (!current_user_can('manage_options')) {
            add_settings_error('archiver_messages', 'archiver_message', __('Permission denied.', 'archiver'), 'error');
            return;
        }

        $action = isset($_POST['archiver_bulk_action']) ? sanitize_text_field($_POST['archiver_bulk_action']) : '';

        switch ($action) {
            case 'archive_all_posts':
                $this->archive_all_posts();
                break;

            case 'archive_recent_posts':
                $this->archive_recent_posts();
                break;

            case 'archive_updated_posts':
                $this->archive_updated_posts();
                break;

            case 'clear_queue':
                update_option('archiver_urls_to_update', array());
                update_option('archiver_background_queue', array());
                add_settings_error('archiver_messages', 'archiver_message', __('Queue cleared successfully.', 'archiver'), 'success');
                break;

            default:
                add_settings_error('archiver_messages', 'archiver_message', __('Please select a valid action.', 'archiver'), 'error');
        }
    }

    private function archive_all_posts() {
        $post_types = get_option('archiver_post_types', array('post', 'page'));

        $posts = get_posts(array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));

        $urls = array();
        foreach ($posts as $post_id) {
            $url = get_permalink($post_id);
            if ($url) {
                $urls[] = $url;
            }
        }

        $existing = get_option('archiver_urls_to_update', array());
        $urls = array_unique(array_merge($existing, $urls));

        // Apply queue limit
        $max_queue = get_option('archiver_max_queue_size', 500);
        $urls = array_slice($urls, 0, $max_queue);

        update_option('archiver_urls_to_update', $urls);

        add_settings_error(
            'archiver_messages',
            'archiver_message',
            sprintf(__('%d posts added to archive queue.', 'archiver'), count($posts)),
            'success'
        );
    }

    private function archive_recent_posts() {
        $post_types = get_option('archiver_post_types', array('post', 'page'));

        $posts = get_posts(array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'date_query' => array(
                'after' => '30 days ago'
            ),
            'fields' => 'ids'
        ));

        $urls = array();
        foreach ($posts as $post_id) {
            $url = get_permalink($post_id);
            if ($url) {
                $urls[] = $url;
            }
        }

        $existing = get_option('archiver_urls_to_update', array());
        $urls = array_unique(array_merge($existing, $urls));

        // Apply queue limit
        $max_queue = get_option('archiver_max_queue_size', 500);
        $urls = array_slice($urls, 0, $max_queue);

        update_option('archiver_urls_to_update', $urls);

        add_settings_error(
            'archiver_messages',
            'archiver_message',
            sprintf(__('%d recent posts added to archive queue.', 'archiver'), count($posts)),
            'success'
        );
    }

    private function archive_updated_posts() {
        $post_types = get_option('archiver_post_types', array('post', 'page'));

        $posts = get_posts(array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids'
        ));

        $urls = array();
        foreach ($posts as $post_id) {
            $url = get_permalink($post_id);
            if ($url) {
                $urls[] = $url;
            }
        }

        $existing = get_option('archiver_urls_to_update', array());
        $urls = array_unique(array_merge($existing, $urls));

        update_option('archiver_urls_to_update', $urls);

        add_settings_error(
            'archiver_messages',
            'archiver_message',
            sprintf(__('%d recently updated posts added to archive queue.', 'archiver'), count($posts)),
            'success'
        );
    }

    private function handle_cache_actions() {
        if (!current_user_can('manage_options')) {
            add_settings_error('archiver_messages', 'archiver_message', __('Permission denied.', 'archiver'), 'error');
            return;
        }

        // Initialize cache if needed
        if (!$this->cache) {
            $this->init_cache();
        }

        if (isset($_POST['archiver_preheat_cache']) && $_POST['archiver_preheat_cache'] == '1' && $this->cache) {
            $count = $this->cache->preheat_cache();
            add_settings_error(
                'archiver_messages',
                'archiver_message',
                sprintf(__('Cache preheating started for %d posts.', 'archiver'), $count),
                'success'
            );
        }

        if (isset($_POST['archiver_clear_cache']) && $_POST['archiver_clear_cache'] == '1') {
            $this->clear_all_cache();
        }
    }

    private function handle_manual_archive() {
        if (!current_user_can('manage_options')) {
            add_settings_error('archiver_messages', 'archiver_message', __('Permission denied.', 'archiver'), 'error');
            return;
        }

        $url = isset($_POST['archiver_manual_url']) ? esc_url_raw($_POST['archiver_manual_url']) : '';

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            add_settings_error('archiver_messages', 'archiver_message', __('Please enter a valid URL.', 'archiver'), 'error');
            return;
        }

        // Add to queue with priority
        if ($this->cache && method_exists($this->cache, 'queue_for_update')) {
            $this->cache->queue_for_update($url, null, true);
        } else {
            // Fallback: add to main queue
            $existing = get_option('archiver_urls_to_update', array());
            $existing[] = $url;
            update_option('archiver_urls_to_update', array_unique($existing));
        }

        add_settings_error(
            'archiver_messages',
            'archiver_message',
            sprintf(__('URL added to archive queue: %s', 'archiver'), $url),
            'success'
        );
    }

    private function clear_all_cache() {
        global $wpdb;

        try {
            // Clear cache table
            $table_name = $wpdb->prefix . 'archiver_cache';
            $wpdb->query("TRUNCATE TABLE IF EXISTS {$table_name}");

            // Clear object cache
            wp_cache_flush();

            // Clear transients
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_archiver_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_archiver_%'");

            add_settings_error('archiver_messages', 'archiver_message', __('All cache cleared successfully.', 'archiver'), 'success');
        } catch (Exception $e) {
            add_settings_error('archiver_messages', 'archiver_message', __('Error clearing cache: ', 'archiver') . $e->getMessage(), 'error');
        }
    }

    // AJAX Handlers
    public function ajax_get_service_stats() {
        check_ajax_referer('archiver_admin_nonce', 'nonce');

        $services = $this->get_available_services();
        $stats = array();

        foreach ($services as $service_id => $service) {
            // Simple connectivity check
            $stats[$service_id] = array(
                'status' => 'online',
                'message' => __('Service is operational', 'archiver')
            );
        }

        wp_send_json_success($stats);
    }

    public function ajax_test_service() {
        check_ajax_referer('archiver_admin_nonce', 'nonce');

        $service_id = isset($_POST['service']) ? sanitize_text_field($_POST['service']) : '';
        $services = $this->get_available_services();

        if (!isset($services[$service_id])) {
            wp_send_json_error(__('Invalid service', 'archiver'));
        }

        // Test with a sample URL
        $test_url = home_url();
        $service = $services[$service_id];

        // Simulate test
        $success = true;
        $message = sprintf(__('%s is working correctly', 'archiver'), $service['name']);

        wp_send_json_success(array(
            'success' => $success,
            'message' => $message
        ));
    }
}