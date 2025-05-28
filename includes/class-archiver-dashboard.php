<?php
/**
 * WP Archiver Dashboard Widget
 */

if (!defined('ABSPATH')) {
    exit;
}

class Archiver_Dashboard {

    protected $archiver;

    public function __construct($archiver) {
        $this->archiver = $archiver;

        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        add_action('wp_ajax_archiver_dashboard_trigger', array($this, 'ajax_dashboard_trigger'));
        add_action('wp_ajax_archiver_dashboard_stats', array($this, 'ajax_dashboard_stats'));
    }

    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Remove the second callback function to cancel configuration options
        wp_add_dashboard_widget(
            'archiver_dashboard_widget',
            __('Archive Status', 'archiver'),
            array($this, 'render_dashboard_widget')
            // Remove configuration callback
            // array($this, 'render_dashboard_widget_config')
        );
    }

    /**
     * Render dashboard widget
     */
public function render_dashboard_widget() {
    $stats = $this->get_dashboard_stats();
    $pending_count = count(get_option('archiver_urls_to_update', array())) +
                    count(get_option('archiver_background_queue', array()));
    
    // Get pre-calculated display times or timestamps
    $last_run_display = get_option('archiver_last_run', '');
    $last_run_timestamp = get_option('archiver_last_run_timestamp', 0);
    $next_run = wp_next_scheduled('archiver_process_urls');

    // Format last run time
    if (!empty($last_run_display)) {
        $last_run_formatted = $last_run_display;
    } elseif ($last_run_timestamp > 0) {
        $last_run_formatted = $this->format_timestamp_with_timezone($last_run_timestamp);
    } else {
        // If we have a string that might be a date
        $last_run = get_option('archiver_last_run');
        if (!empty($last_run) && ($timestamp = strtotime($last_run)) !== false) {
            $last_run_formatted = $this->format_timestamp_with_timezone($timestamp);
        } else {
            $last_run_formatted = __('Never', 'archiver');
        }
    }
    
    // Format next run time
    $next_run_display = get_option('archiver_next_run_display', '');
    if (!empty($next_run_display)) {
        $next_run_formatted = $next_run_display;
    } elseif ($next_run) {
        $next_run_formatted = $this->format_timestamp_with_timezone($next_run);
    } else {
        $next_run_formatted = __('Not scheduled', 'archiver');
    }

    ?>
        <div class="archiver-dashboard-widget">
            <div class="archiver-dashboard-stats">
                <div class="archiver-stat-item">
                    <span class="stat-number"><?php echo number_format($stats['total_archived']); ?></span>
                    <span class="stat-label"><?php _e('Total Archived', 'archiver'); ?></span>
                </div>
                <div class="archiver-stat-item">
                    <span class="stat-number <?php echo $pending_count > 0 ? 'has-pending' : ''; ?>"><?php echo number_format($pending_count); ?></span>
                    <span class="stat-label"><?php _e('Pending', 'archiver'); ?></span>
                </div>
                <div class="archiver-stat-item">
                    <span class="stat-number"><?php echo number_format($stats['failed_snapshots']); ?></span>
                    <span class="stat-label"><?php _e('Failed', 'archiver'); ?></span>
                </div>
            </div>

        <div class="archiver-dashboard-info">
            <p>
                <strong><?php _e('Last Run:', 'archiver'); ?></strong>
                <?php echo esc_html($last_run_formatted); ?>
            </p>
            <p>
                <strong><?php _e('Next Run:', 'archiver'); ?></strong>
                <?php echo esc_html($next_run_formatted); ?>
            </p>
        </div>
        
            <div class="archiver-dashboard-actions">
                <div class="action-group">
                    <input type="url" id="archiver-dashboard-url" placeholder="<?php esc_attr_e('Enter URL to archive...', 'archiver'); ?>" />
                    <button type="button" class="button button-primary" id="archiver-dashboard-submit">
                        <?php _e('Archive Now', 'archiver'); ?>
                    </button>
                </div>
                <div class="action-group">
                    <button type="button" class="button" id="archiver-process-queue">
                        <?php _e('Process Queue', 'archiver'); ?>
                    </button>
                    <button type="button" class="button" id="archiver-refresh-stats">
                        <?php _e('Refresh Stats', 'archiver'); ?>
                    </button>
                </div>
            </div>

            <div id="archiver-dashboard-status" class="archiver-status" style="display: none;"></div>

            <?php if ($pending_count > 0): ?>
            <div class="archiver-queue-preview">
                <h4><?php _e('Recent Queue Items', 'archiver'); ?></h4>
                <div class="queue-items">
                    <?php
                    $queue_items = array_merge(
                        array_slice(get_option('archiver_urls_to_update', array()), 0, 3),
                        array_slice(get_option('archiver_background_queue', array()), 0, 3)
                    );
                    foreach (array_slice($queue_items, 0, 3) as $item):
                        $url = is_array($item) ? $item['url'] : $item;
                    ?>
                        <div class="queue-item"><?php echo esc_html($url); ?></div>
                    <?php endforeach; ?>

                    <?php if ($pending_count > 3): ?>
                        <div class="queue-more">
                            <?php printf(__('... and %d more', 'archiver'), $pending_count - 3); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <style>
        .archiver-dashboard-widget {
            font-size: 13px;
        }

        .archiver-dashboard-stats {
          display: grid;
          grid-template-columns: repeat(3, 1fr);
          gap: 10px;
          margin-bottom: 15px;
        }

        .archiver-stat-item {
          text-align: center;
          padding: 10px;
          background: #f8f9fa;
          border-radius: 4px;
          border: 1px solid #e1e4e8;
        }

        .stat-number {
          font-size: 18px;
          font-weight: 600;
          color: #2271b1;
          line-height: 1;
        }

        .stat-number.has-pending {
            color: #d63638;
        }

        .stat-label {
            display: block;
            font-size: 11px;
            color: #646970;
            margin-top: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .archiver-dashboard-info {
          background: #fff;
              border: 1px solid #ccd0d4;
              border-radius: 4px;
              padding: 10px;
              margin-bottom: 15px;
        }

        .archiver-dashboard-info p {
            margin: 5px 0;
        }

        .archiver-dashboard-actions {
            margin-bottom: 15px;
        }

        .action-group {
            display: flex;
            gap: 5px;
            margin-bottom: 8px;
        }

        .action-group:last-child {
            margin-bottom: 0;
        }

        #archiver-dashboard-url {
            flex: 1;
            font-size: 12px;
            height: 28px;
        }

        .archiver-dashboard-widget .button {
            font-size: 11px;
            height: 28px;
            line-height: 26px;
            padding: 0 10px;
        }

        .archiver-status {
            padding: 8px 10px;
            border-radius: 3px;
            margin-top: 10px;
            font-size: 12px;
        }

        .archiver-status.success {
            background: #d7eddb;
            border-left: 4px solid #46b450;
            color: #155724;
        }

        .archiver-status.error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }

        .archiver-status.info {
            background: #cce7ff;
            border-left: 4px solid #007cba;
            color: #004085;
        }

        .archiver-queue-preview {
            border-top: 1px solid #ddd;
            padding-top: 15px;
            margin-top: 15px;
        }

        .archiver-queue-preview h4 {
            margin: 0 0 10px;
            font-size: 12px;
            color: #23282d;
        }

        .queue-item {
            background: #f6f7f7;
            padding: 6px 8px;
            margin-bottom: 3px;
            border-radius: 3px;
            font-size: 11px;
            word-break: break-all;
            color: #50575e;
            font-family: Consolas, Monaco, monospace;
        }

        .queue-more {
            text-align: center;
            font-style: italic;
            color: #646970;
            font-size: 11px;
            margin-top: 5px;
        }

        .archiver-dashboard-widget .spinner {
            float: none;
            margin: 0 5px 0 0;
        }
        </style>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Archive URL
            $('#archiver-dashboard-submit').on('click', function() {
                const $button = $(this);
                const $status = $('#archiver-dashboard-status');
                const url = $('#archiver-dashboard-url').val().trim();

                if (!url) {
                    showStatus('<?php echo esc_js(__('Please enter a valid URL', 'archiver')); ?>', 'error');
                    return;
                }

                $button.prop('disabled', true).text('<?php echo esc_js(__('Processing...', 'archiver')); ?>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'archiver_dashboard_trigger',
                        url: url,
                        nonce: '<?php echo wp_create_nonce('archiver_dashboard_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            showStatus(response.data.message, 'success');
                            $('#archiver-dashboard-url').val('');
                            refreshStats();
                        } else {
                            showStatus(response.data.message || '<?php echo esc_js(__('Archive failed', 'archiver')); ?>', 'error');
                        }
                    },
                    error: function() {
                        showStatus('<?php echo esc_js(__('Request failed', 'archiver')); ?>', 'error');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php echo esc_js(__('Archive Now', 'archiver')); ?>');
                    }
                });
            });

            // Process queue
            $('#archiver-process-queue').on('click', function() {
                const $button = $(this);

                $button.prop('disabled', true).text('<?php echo esc_js(__('Processing...', 'archiver')); ?>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'archiver_dashboard_trigger',
                        process_queue: true,
                        nonce: '<?php echo wp_create_nonce('archiver_dashboard_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            showStatus(response.data.message, 'success');
                            refreshStats();
                        } else {
                            showStatus(response.data.message || '<?php echo esc_js(__('Process failed', 'archiver')); ?>', 'error');
                        }
                    },
                    error: function() {
                        showStatus('<?php echo esc_js(__('Request failed', 'archiver')); ?>', 'error');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php echo esc_js(__('Process Queue', 'archiver')); ?>');
                    }
                });
            });

            // Refresh stats
            $('#archiver-refresh-stats').on('click', function() {
                refreshStats();
            });

            // Enter key support
            $('#archiver-dashboard-url').on('keypress', function(e) {
                if (e.which === 13) {
                    $('#archiver-dashboard-submit').trigger('click');
                }
            });

            function showStatus(message, type) {
                const $status = $('#archiver-dashboard-status');
                $status.removeClass('success error info')
                    .addClass(type)
                    .html(message)
                    .show();

                setTimeout(function() {
                    $status.fadeOut();
                }, 5000);
            }

            function refreshStats() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'archiver_dashboard_stats',
                        nonce: '<?php echo wp_create_nonce('archiver_dashboard_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload(); // Simple refresh for now
                        }
                    }
                });
            }
        });
        </script>
        <?php
    }


/**
 * Add new method to handle timestamp timezone issues
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

    /**
     * Get dashboard statistics
     */
    private function get_dashboard_stats() {
        return array(
            'total_archived' => get_option('archiver_total_archived', 0),
            'failed_snapshots' => get_option('archiver_failed_snapshots', 0),
            'pending_urls' => count(get_option('archiver_urls_to_update', array())),
            'queue_count' => count(get_option('archiver_background_queue', array()))
        );
    }

    /**
     * AJAX handler for dashboard trigger
     */
    public function ajax_dashboard_trigger() {
        check_ajax_referer('archiver_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'archiver')));
        }

        // Process queue
        if (isset($_POST['process_queue'])) {
            if ($this->archiver && method_exists($this->archiver, 'process_urls_for_update')) {
                $processed = $this->archiver->process_urls_for_update();
                wp_send_json_success(array(
                    'message' => sprintf(__('Started processing %d URLs from queue', 'archiver'), $processed)
                ));
            } else {
                wp_send_json_error(array('message' => __('Queue processing not available', 'archiver')));
            }
            return;
        }

        // Archive single URL
        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(array('message' => __('Please enter a valid URL', 'archiver')));
        }

        // Add to queue with high priority
        if ($this->archiver && method_exists($this->archiver, 'trigger_url_snapshot')) {
            $this->archiver->trigger_url_snapshot($url);
            wp_send_json_success(array(
                'message' => sprintf(__('URL added to archive queue: %s', 'archiver'), $url)
            ));
        } else {
            wp_send_json_error(array('message' => __('Archive functionality not available', 'archiver')));
        }
    }

    /**
     * AJAX handler for dashboard stats
     */
    public function ajax_dashboard_stats() {
        check_ajax_referer('archiver_dashboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'archiver')));
        }

        $stats = $this->get_dashboard_stats();
        wp_send_json_success($stats);
    }
}