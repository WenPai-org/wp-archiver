<?php
/**
 * Plugin Name:       WP Archiver
 * Plugin URI:        http://wenpai.org/plugins/wp-archiver
 * Description:       Archive your content using the Wayback Machine.
 * Version:           1.3.2
 * Author:            WenPai.org
 * Author URI:        https://wenpai.org/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       archiver
 * Domain Path:       /languages
 */


if (!defined('WPINC')) {
    die;
}

define('ARCHIVER_VERSION', '1.3.2');
define('ARCHIVER_PLUGIN_DIR_URL', plugin_dir_url(__FILE__));
define('ARCHIVER_PLUGIN_DIR_PATH', plugin_dir_path(__FILE__));
define('ARCHIVER_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once ARCHIVER_PLUGIN_DIR_PATH . 'includes/class-archiver.php';
require_once ARCHIVER_PLUGIN_DIR_PATH . 'includes/class-archiver-admin.php';

function archiver_activate() {
    if (!get_option('archiver_post_types')) {
        update_option('archiver_post_types', array('post', 'page'));
    }
    if (!get_option('archiver_update_frequency')) {
        update_option('archiver_update_frequency', 'daily');
    }
    if (!get_option('archiver_urls_to_update')) {
        update_option('archiver_urls_to_update', array());
    }
    if (!wp_next_scheduled('archiver_process_urls')) {
        $frequency = get_option('archiver_update_frequency', 'daily');
        wp_schedule_event(time(), $frequency, 'archiver_process_urls');
    }
}
register_activation_hook(__FILE__, 'archiver_activate');

function archiver_uninstall() {
    $timestamp = wp_next_scheduled('archiver_process_urls');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'archiver_process_urls');
    }
    delete_option('archiver_post_types');
    delete_option('archiver_update_frequency');
    delete_option('archiver_urls_to_update');
    delete_option('archiver_last_run');
}
register_uninstall_hook(__FILE__, 'archiver_uninstall');

function archiver_load_textdomain() {
    load_plugin_textdomain(
        'archiver',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
}
add_action('init', 'archiver_load_textdomain');

function archiver_run() {
    $archiver = Archiver::get_instance();
    if (is_admin()) {
        new Archiver_Admin($archiver);
    }
    $archiver->run();
    return $archiver;
}
add_action('plugins_loaded', 'archiver_run');


// Integrate UpdatePulse Server for updates using PUC v5.3
require_once plugin_dir_path(__FILE__) . 'lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5p3\PucFactory;

$WpArchiverUpdateChecker = PucFactory::buildUpdateChecker(
    'https://updates.weixiaoduo.com/wp-archiver.json',
    __FILE__,
    'wp-archiver'
);
