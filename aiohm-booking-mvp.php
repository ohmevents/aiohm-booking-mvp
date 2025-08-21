<?php
/**
 * Plugin Name: AIOHM Booking MVP
 * Plugin URI:  https://wordpress.org/plugins/aiohm-booking-mvp/
 * Description: Transform your WordPress into a seamless accommodation booking experience. Focuses exclusively on room and accommodation bookings with deposits, payments, and full customization. Built with AIOHM's signature attention to conscious business flow.
 * Version:     1.0.0
 * Author:      OHM Events Agency
 * Author URI:  https://www.ohm.events
 * Text Domain: aiohm-booking-mvp
 * Domain Path: /languages
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.2
 * Tested up to: 6.8
 * Requires PHP: 7.4
 */

/**
 * Main plugin file for AIOHM Booking
 * Handles accommodation booking with rooms and deposit management
 */

if ( ! defined( 'ABSPATH' ) ) { 
    exit; 
}

define('AIOHM_BOOKING_MVP_VERSION','1.0.0');
define('AIOHM_BOOKING_MVP_FILE', __FILE__);
define('AIOHM_BOOKING_MVP_DIR', plugin_dir_path(__FILE__));
define('AIOHM_BOOKING_MVP_URL', plugin_dir_url(__FILE__));

require_once AIOHM_BOOKING_MVP_DIR.'includes/aiohm-booking-mvp-helpers.php';
require_once AIOHM_BOOKING_MVP_DIR.'includes/aiohm-booking-mvp-activator.php';
require_once AIOHM_BOOKING_MVP_DIR.'includes/aiohm-booking-mvp-events.php';
require_once AIOHM_BOOKING_MVP_DIR.'includes/aiohm-booking-mvp-api.php';
require_once AIOHM_BOOKING_MVP_DIR.'includes/aiohm-booking-mvp-shortcodes.php';
require_once AIOHM_BOOKING_MVP_DIR.'includes/aiohm-booking-mvp-admin.php';
require_once AIOHM_BOOKING_MVP_DIR.'includes/aiohm-booking-mvp-calendar.php';
require_once AIOHM_BOOKING_MVP_DIR.'includes/aiohm-booking-mvp-ai-client.php';
require_once AIOHM_BOOKING_MVP_DIR.'includes/aiohm-booking-mvp-security.php';
require_once AIOHM_BOOKING_MVP_DIR.'includes/aiohm-booking-mvp-cron.php';

register_activation_hook(__FILE__, ['AIOHM_BOOKING_MVP_Activator','activate']);
register_deactivation_hook(__FILE__, ['AIOHM_BOOKING_MVP_Activator','deactivate']);

/**
 * Initialize plugin components
 */
class AIOHM_Booking_MVP {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'init_components'));
        add_action('init', array($this, 'load_textdomain'));
    }

    /**
     * Load plugin text domain for translations
     * Note: WordPress automatically loads translations from wp-content/languages/plugins/
     */
    public function load_textdomain() {
        // Load translations based on plugin language setting
        $settings = get_option('aiohm_booking_mvp_settings', []);
        $plugin_language = $settings['plugin_language'] ?? 'en';
        
        // Only apply custom locale if not English
        if ($plugin_language !== 'en') {
            add_filter('locale', array($this, 'set_plugin_locale'));
            add_filter('plugin_locale', array($this, 'set_plugin_locale'), 10, 2);
        }
        
        // Load plugin textdomain
        load_plugin_textdomain(
            'aiohm-booking-mvp',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }
    
    /**
     * Set custom locale for plugin translations
     */
    public function set_plugin_locale($locale) {
        $settings = get_option('aiohm_booking_mvp_settings', []);
        $plugin_language = $settings['plugin_language'] ?? 'en';
        
        if ($plugin_language === 'ro') {
            return 'ro_RO';
        }
        
        return $locale;
    }

    public function init_components() {
        AIOHM_BOOKING_MVP_Events::init();
        AIOHM_BOOKING_MVP_API::init();
        AIOHM_BOOKING_MVP_Shortcodes::init();
        AIOHM_BOOKING_MVP_Admin::init();
        AIOHM_BOOKING_MVP_Security::init();
    }
}

// Initialize the plugin
AIOHM_Booking_MVP::get_instance();

/**
 * Add settings link to plugin action links
 */
function aiohm_booking_mvp_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=aiohm-booking-mvp-settings') . '">' . __('Settings', 'aiohm-booking-mvp') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Add settings link filter
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'aiohm_booking_mvp_add_settings_link');
