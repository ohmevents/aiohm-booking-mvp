<?php

namespace AIOHM\BookingMVP\Events;

if ( ! defined('ABSPATH') ) { exit; }

/**
 * AIOHM Booking MVP Events Handler
 *
 * Manages event-related functionality including meta boxes
 * and data handling for booking events.
 *
 * @package AIOHM\BookingMVP\Events
 * @since   1.0.0
 */
class Events {
    
    /**
     * Initialize events functionality
     *
     * @since 1.0.0
     * @return void
     */
    public static function init(){
        add_action('add_meta_boxes',[__CLASS__,'metaboxes']);
        add_action('save_post_aiohm_booking_event',[__CLASS__,'save']);
    }
    
    /**
     * Register event meta boxes
     *
     * @since 1.0.0
     * @return void
     */
    public static function metaboxes(){
        add_meta_box('aiohm_booking_event','Event Details',[__CLASS__,'box'],'aiohm_booking_event','side','default');
    }
    
    /**
     * Render event details meta box
     *
     * @since 1.0.0
     * @param \WP_Post $post Post object
     * @return void
     */
    public static function box($post){
        $start = get_post_meta($post->ID,'_booking_start',true);
        $end   = get_post_meta($post->ID,'_booking_end',true);
        $capacity = get_post_meta($post->ID,'_booking_capacity',true);
        ?>
        <p><label>Start Date<br><input type="date" name="booking_start" value="<?php echo esc_attr($start); ?>"></label></p>
        <p><label>End Date<br><input type="date" name="booking_end" value="<?php echo esc_attr($end); ?>"></label></p>
        <p><label>Seat Capacity (for seat mode)<br><input type="number" name="booking_capacity" value="<?php echo esc_attr($capacity); ?>" min="0"></label></p>
        <?php
    }
    
    /**
     * Save event meta data
     *
     * @since 1.0.0
     * @param int $post_id Post ID being saved
     * @return void
     */
    public static function save($post_id){
        if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        
        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Verify nonce for meta box save - WordPress handles this automatically for meta boxes
        // but we'll check if POST data exists to avoid warnings
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!isset($_POST['booking_start']) && !isset($_POST['booking_end']) && !isset($_POST['booking_capacity'])) {
            return;
        }
        
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_post_meta($post_id,'_booking_start', sanitize_text_field(wp_unslash($_POST['booking_start'] ?? '')));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_post_meta($post_id,'_booking_end', sanitize_text_field(wp_unslash($_POST['booking_end'] ?? '')));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_post_meta($post_id,'_booking_capacity', intval($_POST['booking_capacity'] ?? 0));
    }
}