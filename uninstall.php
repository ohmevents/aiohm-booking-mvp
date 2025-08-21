<?php
/**
 * AIOHM Booking MVP Uninstall
 *
 * This file is triggered when the user deletes the plugin from the WordPress admin.
 * It cleans up all plugin-specific data from the database to ensure a clean uninstall.
 *
 * @package AIOHM_Booking_MVP
 * @since 1.0.0
 */

// If uninstall.php is not called by WordPress, exit.
if ( ! defined('WP_UNINSTALL_PLUGIN') ) {
    exit;
}

global $wpdb;

// 1. Delete Custom Database Tables
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aiohm_booking_mvp_order");
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}aiohm_booking_mvp_item");

// 2. Delete Custom Post Type Data
$events = get_posts([
    'post_type' => 'aiohm_booking_event',
    'numberposts' => -1,
    'post_status' => 'any'
]);

foreach ($events as $event) {
    wp_delete_post($event->ID, true); // true = force delete, bypass trash
}

// 3. Delete Plugin Options
$options_to_delete = [
    'aiohm_booking_mvp_settings',
    'aiohm_booking_mvp_accommodations_details',
    'aiohm_booking_mvp_blocked_dates',
    'aiohm_booking_mvp_order_rooms',
    'aiohm_booking_db_fixed',
];

foreach ($options_to_delete as $option_name) {
    delete_option($option_name);
}

// 4. Clean up scheduled cron events
wp_clear_scheduled_hook('aiohm_booking_mvp_cleanup_holds');
wp_clear_scheduled_hook('aiohm_booking_mvp_sync_booking_com');
wp_clear_scheduled_hook('aiohm_booking_mvp_sync_airbnb');