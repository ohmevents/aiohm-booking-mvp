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

// Load Composer autoloader
require_once AIOHM_BOOKING_MVP_DIR . 'vendor/autoload.php';

// Use namespaced classes
use AIOHM\BookingMVP\Core\Activator;
use AIOHM\BookingMVP\Events\Events;
use AIOHM\BookingMVP\API\API;
use AIOHM\BookingMVP\Shortcodes\Shortcodes;
use AIOHM\BookingMVP\Admin\Admin;
use AIOHM\BookingMVP\Security\Security;

register_activation_hook(__FILE__, [Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [Activator::class, 'deactivate']);

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
        
        // WordPress automatically loads plugin textdomains since 4.6
        // The textdomain is loaded from the /languages/ directory automatically
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
        Events::init();
        API::init();
        Shortcodes::init();
        Admin::init();
        Security::init();
        
    }
}

// Initialize the plugin
AIOHM_Booking_MVP::get_instance();
