<?php
if (!defined('ABSPATH')) {
    exit;
}

class Archiver_Admin {
    protected $archiver;
    protected $slug;
    protected $min_suffix = '';

    public function __construct($archiver) {
        $this->archiver = $archiver;
        $this->slug = $archiver->get_slug();
        $this->min_suffix = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '.min';
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_settings_actions'));
    }

    public function get_slug() {
        return $this->slug;
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

    public function render_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    $post_types = get_post_types(array('public' => true), 'objects');
    $selected_post_types = get_option('archiver_post_types', array('post', 'page'));
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <h2 class="nav-tab-wrapper">
            <a href="#general" class="nav-tab nav-tab-active" data-tab="general"><?php _e('General Settings', 'archiver'); ?></a>
            <a href="#tools" class="nav-tab" data-tab="tools"><?php _e('Tools', 'archiver'); ?></a>
        </h2>
        <?php settings_errors('archiver_messages'); ?>
        
        <div id="archiver-tabs-content">
            <div id="general" class="archiver-tab-content" style="display: block;">
                <form method="post" action="">
                    <?php wp_nonce_field('archiver_settings_nonce'); ?>
                    <div class="postbox">
                        <div class="inside">
                            <h3><?php _e('Content Types to Archive', 'archiver'); ?></h3>
                            <p class="description"><?php _e('Select which content types should be automatically archived when published or updated.', 'archiver'); ?></p>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php _e('Post Types', 'archiver'); ?></th>
                                    <td>
                                        <fieldset>
                                            <?php foreach ($post_types as $post_type) : ?>
                                                <label>
                                                    <input type="checkbox" name="archiver_post_types[]" 
                                                        value="<?php echo esc_attr($post_type->name); ?>" 
                                                        <?php checked(in_array($post_type->name, $selected_post_types)); ?>>
                                                    <?php echo esc_html($post_type->label); ?>
                                                </label><br>
                                            <?php endforeach; ?>
                                        </fieldset>
                                    </td>
                                </tr>
                            </table>
                            <h3><?php _e('Update Frequency', 'archiver'); ?></h3>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php _e('Frequency', 'archiver'); ?></th>
                                    <td>
                                        <select name="archiver_update_frequency" class="regular-text">
                                            <option value="hourly" <?php selected(get_option('archiver_update_frequency', 'daily'), 'hourly'); ?>>
                                                <?php _e('Hourly', 'archiver'); ?>
                                            </option>
                                            <option value="twicedaily" <?php selected(get_option('archiver_update_frequency', 'daily'), 'twicedaily'); ?>>
                                                <?php _e('Twice Daily', 'archiver'); ?>
                                            </option>
                                            <option value="daily" <?php selected(get_option('archiver_update_frequency', 'daily'), 'daily'); ?>>
                                                <?php _e('Daily', 'archiver'); ?>
                                            </option>
                                            <option value="weekly" <?php selected(get_option('archiver_update_frequency', 'daily'), 'weekly'); ?>>
                                                <?php _e('Weekly', 'archiver'); ?>
                                            </option>
                                        </select>
                                        <p class="description"><?php _e('How often should the plugin check for new archives?', 'archiver'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button(__('Save Settings', 'archiver'), 'primary', 'submit'); ?>
                        </div>
                    </div>
                </form>
            </div>
            
            <div id="tools" class="archiver-tab-content" style="display: none;">
                <div class="postbox">
                    <div class="inside">
                        <h3><?php _e('Archive Statistics', 'archiver'); ?></h3>
                        <div class="archiver-stats-container">
                            <div class="archiver-stat-card">
                                <h3><?php _e('Pending URLs', 'archiver'); ?></h3>
                                <div class="stat-value"><?php echo number_format(count(get_option('archiver_urls_to_update', array()))); ?></div>
                            </div>
                            <div class="archiver-stat-card">
                                <h3><?php _e('Total Archived', 'archiver'); ?></h3>
                                <div class="stat-value"><?php echo number_format((int) get_option('archiver_total_archived', 0)); ?></div>
                            </div>
                            <div class="archiver-stat-card">
                                <h3><?php _e('Failed Snapshots', 'archiver'); ?></h3>
                                <div class="stat-value"><?php echo number_format((int) get_option('archiver_failed_snapshots', 0)); ?></div>
                            </div>
                            <div class="archiver-stat-card">
                                <h3><?php _e('Last Run', 'archiver'); ?></h3>
                                <div class="stat-value"><?php echo esc_html(get_option('archiver_last_run', __('Never', 'archiver'))); ?></div>
                            </div>
                            <div class="archiver-stat-card">
                                <h3><?php _e('Next Run', 'archiver'); ?></h3>
                                <div class="stat-value"><?php 
                                    $next_run = wp_next_scheduled('archiver_process_urls');
                                    echo $next_run ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_run)) : __('Not scheduled', 'archiver');
                                ?></div>
                            </div>
                        </div>
                        <h3><?php _e('Pending Updates', 'archiver'); ?></h3>
                        <?php $this->display_pending_updates(); ?>
                        <form method="post" action="">
                            <?php wp_nonce_field('archiver_manual_update_nonce'); ?>
                            <p>
                                <input type="submit" name="archiver_trigger_update" 
                                       class="button button-primary" 
                                       value="<?php esc_attr_e('Run Manual Update Now', 'archiver'); ?>">
                                <span class="description"><?php _e('Process all pending URLs immediately.', 'archiver'); ?></span>
                            </p>
                        </form>
                        <h3><?php _e('Bulk Actions', 'archiver'); ?></h3>
                        <form method="post" action="">
                            <?php wp_nonce_field('archiver_bulk_actions_nonce'); ?>
                            <p>
                                <select name="archiver_bulk_action" class="regular-text">
                                    <option value=""><?php _e('Select bulk action...', 'archiver'); ?></option>
                                    <option value="archive_all_posts"><?php _e('Archive all published posts', 'archiver'); ?></option>
                                    <option value="clear_queue"><?php _e('Clear pending queue', 'archiver'); ?></option>
                                </select>
                            </p>
                            <p>
                                <input type="submit" name="archiver_submit_bulk_action" 
                                       class="button button-secondary" 
                                       value="<?php esc_attr_e('Apply', 'archiver'); ?>">
                            </p>
                        </form>
                        <h3><?php _e('Debug Tools', 'archiver'); ?></h3>
                        <form method="post" action="">
                            <?php wp_nonce_field('archiver_debug_tools_nonce'); ?>
                            <p>
                                <input type="submit" name="archiver_clear_cache" 
                                       class="button button-secondary" 
                                       value="<?php esc_attr_e('Clear Cache', 'archiver'); ?>">
                                <span class="description"><?php _e('Clear all cached snapshot data.', 'archiver'); ?></span>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

    public function handle_settings_actions() {
        if (isset($_POST['submit']) && check_admin_referer('archiver_settings_nonce')) {
            $this->save_settings();
        }
        if (isset($_POST['archiver_trigger_update']) && check_admin_referer('archiver_manual_update_nonce')) {
            $this->archiver->process_urls_for_update();
            add_settings_error(
                'archiver_messages',
                'archiver_message',
                __('Manual update triggered. The update will run in the background.', 'archiver'),
                'updated'
            );
        }
        if (isset($_POST['archiver_submit_bulk_action']) && check_admin_referer('archiver_bulk_actions_nonce')) {
            $this->handle_bulk_actions();
        }
        if (isset($_POST['archiver_clear_cache']) && check_admin_referer('archiver_debug_tools_nonce')) {
            $this->clear_cache();
        }
    }

    private function save_settings() {
        if (!isset($_POST['archiver_post_types']) || !is_array($_POST['archiver_post_types'])) {
            add_settings_error(
                'archiver_messages',
                'archiver_message',
                __('Please select at least one post type to archive.', 'archiver'),
                'error'
            );
            return;
        }
        $valid_post_types = array_keys(get_post_types(['public' => true]));
        $selected_types = array_intersect($_POST['archiver_post_types'], $valid_post_types);
        if (empty($selected_types)) {
            add_settings_error(
                'archiver_messages',
                'archiver_message',
                __('Invalid post types selected.', 'archiver'),
                'error'
            );
            return;
        }
        update_option('archiver_post_types', $selected_types);
        $valid_frequencies = ['hourly', 'twicedaily', 'daily', 'weekly'];
        $frequency = isset($_POST['archiver_update_frequency']) && in_array($_POST['archiver_update_frequency'], $valid_frequencies) 
            ? $_POST['archiver_update_frequency'] 
            : 'daily';
        update_option('archiver_update_frequency', $frequency);
        $this->archiver->reschedule_cron_task($frequency);
        add_settings_error(
            'archiver_messages',
            'archiver_message',
            __('Settings saved successfully.', 'archiver'),
            'success'
        );
    }

    private function handle_bulk_actions() {
        $action = isset($_POST['archiver_bulk_action']) ? sanitize_text_field($_POST['archiver_bulk_action']) : '';
        switch ($action) {
            case 'archive_all_posts':
                $this->archive_all_published_posts();
                break;
            case 'clear_queue':
                update_option('archiver_urls_to_update', array());
                add_settings_error(
                    'archiver_messages',
                    'archiver_message',
                    __('Pending queue has been cleared.', 'archiver'),
                    'updated'
                );
                break;
            default:
                add_settings_error(
                    'archiver_messages',
                    'archiver_message',
                    __('Please select a valid bulk action.', 'archiver'),
                    'error'
                );
        }
    }

    private function archive_all_published_posts() {
        $post_types = get_option('archiver_post_types', array('post', 'page'));
        $posts = get_posts(array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
        $urls = array();
        foreach ($posts as $post_id) {
            $urls[] = get_permalink($post_id);
        }
        update_option('archiver_urls_to_update', $urls);
        add_settings_error(
            'archiver_messages',
            'archiver_message',
            sprintf(__('%d posts have been added to the archive queue.', 'archiver'), count($posts)),
            'updated'
        );
    }

private function clear_cache() {
    global $wpdb;
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_archiver_last_known_snapshots_%'");
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_archiver_last_known_snapshots_%'");
    update_option('archiver_total_archived', 0);
    update_option('archiver_failed_snapshots', 0);
    wp_cache_flush();
    add_settings_error(
        'archiver_messages',
        'archiver_message',
        __('All cached data has been cleared.', 'archiver'),
        'updated'
    );
}

    private function display_pending_updates() {
        $urls_to_update = get_option('archiver_urls_to_update', array());
        if (empty($urls_to_update)) {
            echo '<p>' . __('No pending updates.', 'archiver') . '</p>';
            return;
        }
        echo '<p>' . sprintf(__('Total pending URLs: %d', 'archiver'), count($urls_to_update)) . '</p>';
        echo '<div style="max-height: 300px; overflow-y: scroll; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">';
        echo '<ul>';
        foreach (array_slice($urls_to_update, 0, 50) as $url) {
            echo '<li style="word-break: break-all;">' . esc_html($url) . '</li>';
        }
        if (count($urls_to_update) > 50) {
            echo '<li>... ' . (count($urls_to_update) - 50) . ' ' . __('more', 'archiver') . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }
}