<?php
/**
 * Helper functions for AIOHM Booking
 * Core utility functions for settings, pricing, and module states
 */
if (!defined('ABSPATH')) exit;

/**
 * Get all plugin settings with caching for performance
 * 
 * @since 1.0.0
 * @return array Plugin settings array
 */
function aiohm_booking_mvp_opts() { 
    static $cached_settings = null;
    
    // Check if cache should be cleared
    global $_aiohm_settings_cache_cleared;
    if ($_aiohm_settings_cache_cleared) {
        $cached_settings = null;
        $_aiohm_settings_cache_cleared = false;
    }
    
    if ($cached_settings === null) {
        $cached_settings = get_option('aiohm_booking_mvp_settings', []);
    }
    
    return $cached_settings;
}

/**
 * Get specific plugin setting with default fallback
 * 
 * @since 1.0.0
 * @param string $k Setting key
 * @param mixed $d Default value if key not found
 * @return mixed Setting value or default
 */
function aiohm_booking_mvp_opt($k, $d = '') { 
    $o = aiohm_booking_mvp_opts(); 
    return $o[$k] ?? $d; 
}

/**
 * Update plugin settings and clear cache automatically
 * 
 * @since 1.0.0
 * @param array $settings New settings array
 * @return bool True on success, false on failure
 */
function aiohm_booking_mvp_update_settings($settings) {
    // Clear static cache before updating
    aiohm_booking_mvp_clear_settings_cache();
    
    $result = update_option('aiohm_booking_mvp_settings', $settings);
    
    // Clear cache again after updating to force reload
    aiohm_booking_mvp_clear_settings_cache();
    
    return $result;
}

/**
 * Clear settings cache (call after updating settings)
 * 
 * @since 1.0.0
 * @return void
 */
function aiohm_booking_mvp_clear_settings_cache() {
    // Use WordPress cache API if available
    if (function_exists('wp_cache_delete')) {
        wp_cache_delete('aiohm_booking_mvp_settings', 'options');
    }
    
    // Force static variable reset on next call
    global $_aiohm_settings_cache_cleared;
    $_aiohm_settings_cache_cleared = true;
}

/**
 * Build plugin asset URL (css/js/images)
 */
function aiohm_booking_mvp_asset_url($relative){
    $relative = ltrim((string)$relative, '/');
    $url = AIOHM_BOOKING_MVP_URL . 'assets/' . $relative;
    
    // Ensure correct scheme using WordPress core function for robustness
    if (is_ssl()) {
        $url = set_url_scheme($url, 'https');
    }
    
    return $url;
}

/**
 * Build plugin asset filesystem path
 */
function aiohm_booking_mvp_asset_path($relative){
    $relative = ltrim((string)$relative, '/');
    return AIOHM_BOOKING_MVP_DIR . 'assets/' . $relative;
}

function aiohm_booking_mvp_enabled_rooms(){ return !empty(aiohm_booking_mvp_opt('enable_rooms', 1)); }

function aiohm_booking_mvp_prices(){
    return [
        'room_price' => floatval(aiohm_booking_mvp_opt('room_price', 0.00)),
        'deposit_percent' => floatval(aiohm_booking_mvp_opt('deposit_percent', 30.0)),
        'currency' => aiohm_booking_mvp_opt('currency','EUR'),
        'available_rooms' => intval(aiohm_booking_mvp_opt('available_rooms', 7)),
        'allow_private_all' => !empty(aiohm_booking_mvp_opt('allow_private_all', 1)),
    ];
}

/**
 * Get the customized product names for rooms
 * @return array Array with singular and plural product names
 */
function aiohm_booking_mvp_get_product_names() {
    // Force fresh settings load - no caching
    $settings = get_option('aiohm_booking_mvp_settings', array());

    // Check new field name first, then fall back to old name for backward compatibility
    $accommodation_type_name = $settings['accommodation_product_name'] ?? $settings['room_product_name'] ?? 'room';

    // Define plural forms for each product type
    $plurals = [
        'accommodation' => 'accommodations',
        'room' => 'rooms',
        'house' => 'houses',
        'apartment' => 'apartments',
        'villa' => 'villas',
        'bungalow' => 'bungalows',
        'cabin' => 'cabins',
        'cottage' => 'cottages',
        'suite' => 'suites',
        'studio' => 'studios',
        'unit' => 'units',
        'space' => 'spaces',
        'venue' => 'venues'
    ];

    return [
        'singular' => $accommodation_type_name,
        'plural' => $plurals[$accommodation_type_name] ?? $accommodation_type_name . 's',
        'singular_cap' => ucfirst($accommodation_type_name),
        'plural_cap' => ucfirst($plurals[$accommodation_type_name] ?? $accommodation_type_name . 's')
    ];
}




/**
 * Force SSL for all enqueued assets to prevent mixed content errors.
 * This is a robust way to handle issues where WordPress or other plugins
 * might generate http:// URLs on an https:// site.
 *
 * @param string $url The asset URL.
 * @return string The corrected asset URL.
 */
function aiohm_booking_mvp_force_ssl_for_assets($url) {
    if (is_ssl()) {
        $url = str_replace('http://', 'https://', $url);
    }
    return $url;
}
add_filter('style_loader_src', 'aiohm_booking_mvp_force_ssl_for_assets', 999);
add_filter('script_loader_src', 'aiohm_booking_mvp_force_ssl_for_assets', 999);

/**
 * Fix mixed content issues by intercepting HTTP requests and converting them to HTTPS
 * This addresses Elementor font loading issues and other similar problems
 */
function aiohm_booking_mvp_fix_mixed_content_buffer() {
    if (is_ssl() && !is_admin()) {
        ob_start('aiohm_booking_mvp_force_https_in_content');
    }
}

function aiohm_booking_mvp_force_https_in_content($content) {
    if (is_ssl()) {
        // Fix font URLs and other asset URLs that might be loaded over HTTP
        $content = preg_replace('/http:\/\/([^\/]+)\/wp-content\/uploads\/elementor\/google-fonts\/fonts\//i', 'https://$1/wp-content/uploads/elementor/google-fonts/fonts/', $content);
        
        // More comprehensive fix for any HTTP asset URLs in content
        $content = preg_replace('/http:\/\/([^\/]+)\/wp-content\//i', 'https://$1/wp-content/', $content);
    }
    return $content;
}

// Only apply buffer on frontend when SSL is enabled
if (is_ssl() && !is_admin()) {
    add_action('template_redirect', 'aiohm_booking_mvp_fix_mixed_content_buffer', 1);
}
