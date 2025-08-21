<?php
if ( ! defined('ABSPATH') ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching  
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// Reason: This class handles plugin activation and database schema setup requiring direct database access
class AIOHM_BOOKING_MVP_Activator {
    public static function activate(){
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $order = $wpdb->prefix.'aiohm_booking_mvp_order';
        $item  = $wpdb->prefix.'aiohm_booking_mvp_item';
        
        // Handle database schema updates
        self::maybe_update_database_schema();

        $sql1 = "CREATE TABLE {$order} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            mode VARCHAR(10) NOT NULL DEFAULT 'rooms',
            guests_qty INT(11) NOT NULL DEFAULT 1,
            rooms_qty INT(11) NOT NULL DEFAULT 0,
            private_all TINYINT(1) NOT NULL DEFAULT 0,
            buyer_name VARCHAR(191) NOT NULL,
            buyer_email VARCHAR(191) NOT NULL,
            buyer_phone VARCHAR(64) NULL,
            buyer_age INT(11) NOT NULL DEFAULT 0,
            vat_number VARCHAR(50) NULL,
            purpose_of_stay VARCHAR(50) NULL,
            estimated_arrival_time VARCHAR(50) NULL,
            bringing_pets TINYINT(1) NOT NULL DEFAULT 0,
            pet_details TEXT NULL,
            check_in_date DATE NULL,
            check_out_date DATE NULL,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            deposit_amount DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            payment_method VARCHAR(20) NULL,
            payment_id VARCHAR(191) NULL,
            external_booking_source VARCHAR(50) NULL,
            external_booking_id VARCHAR(191) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY date_idx (check_in_date, check_out_date),
            KEY status_idx (status),
            KEY email_idx (buyer_email),
            KEY created_idx (created_at),
            KEY payment_idx (payment_method, status),
            KEY external_idx (external_booking_source, external_booking_id)
        ) {$charset};";

        $sql2 = "CREATE TABLE {$item} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            type VARCHAR(10) NOT NULL,
            qty INT(11) NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            PRIMARY KEY  (id),
            KEY order_idx (order_id)
        ) {$charset};";

        dbDelta($sql1);
        dbDelta($sql2);

        self::register_cpt();
        self::create_default_settings();
        flush_rewrite_rules();
    }


    public static function deactivate(){
        wp_clear_scheduled_hook('aiohm_booking_mvp_cleanup_holds');
        flush_rewrite_rules();
    }

    public static function register_cpt(){
        register_post_type('aiohm_booking_event',[
            'labels'=>[
                'name'=>__('Events','aiohm-booking-mvp'),
                'singular_name'=>__('Event','aiohm-booking-mvp')
            ],
            'public'=>false, 'show_ui'=>true, 'menu_icon'=>'dashicons-calendar-alt',
            'supports'=>['title','editor','thumbnail','custom-fields']
        ]);
    }
    
    /**
     * Create default plugin settings on activation
     */
    private static function create_default_settings() {
        $existing_settings = get_option('aiohm_booking_mvp_settings', []);
        
        // Only create defaults if no settings exist
        if (empty($existing_settings)) {
            $default_settings = [
                // Module Settings
                'enable_rooms' => '1',
                
                // Basic Configuration
                'room_price' => '0.00',
                'currency' => 'EUR',
                'deposit_percent' => '30.0',
                'available_rooms' => '7',
                'allow_private_all' => '1',
                'min_age' => '0',
                
                // Product Naming
                'accommodation_product_name' => 'room',
                
                // Form Settings
                'form_primary_color' => '#457d58',
                'form_text_color' => '#333333',
                'form_title' => 'Book Your Stay',
                'form_subtitle' => 'Choose your perfect accommodation',
                'form_field_address' => '0',
                'form_field_age' => '1',
                'form_field_company' => '0',
                'form_field_country' => '0',
                'form_field_vat' => '0',
                'form_field_pets' => '0',
                'form_field_arrival_time' => '0',
                'form_field_purpose' => '0',
                'form_field_special_requests' => '0',
                
                // Payment Settings
                'enable_stripe' => '0',
                'enable_paypal' => '0',
                'stripe_publishable_key' => '',
                'stripe_secret_key' => '',
                'paypal_client_id' => '',
                'paypal_client_secret' => '',
                
                // External Platform Integration
                'enable_booking_com' => '0',
                'enable_airbnb' => '0',
                'booking_com_property_id' => '',
                'airbnb_property_id' => '',
                'booking_com_ical_url' => '',
                'airbnb_ical_url' => '',
                'booking_com_cron_frequency' => 'hourly',
                'airbnb_cron_frequency' => 'hourly',
                
                // AI Integration
                'shareai_api_key' => '',
                'openai_api_key' => '',
                'gemini_api_key' => '',
                'default_ai_provider' => 'shareai',
                
                // Page URLs
                'checkout_page_url' => '',
                'thankyou_page_url' => ''
            ];
            
            update_option('aiohm_booking_mvp_settings', $default_settings);
            
            // Create default accommodation details (7 rooms with basic setup)
            $default_accommodations = [];
            for ($i = 0; $i < 7; $i++) {
                $default_accommodations[] = [
                    'title' => 'Room ' . ($i + 1),
                    'price' => '',
                    'earlybird_price' => '',
                    'type' => 'standard',
                    'description' => 'Comfortable accommodation with essential amenities'
                ];
            }
            
            update_option('aiohm_booking_mvp_accommodations_details', $default_accommodations);
        }
    }
    
    /**
     * Handle database schema updates for existing installations
     */
    private static function maybe_update_database_schema() {
        global $wpdb;
        $order_table = $wpdb->prefix . 'aiohm_booking_mvp_order';
        
        // Check if buyer_age column exists, if not add it
        // Safe SQL: Table name sanitized via wpdb->prefix concatenation
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$order_table}` LIKE 'buyer_age'");
        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$order_table}` ADD COLUMN buyer_age INT(11) NOT NULL DEFAULT 0 AFTER buyer_phone");
        }

        // Check if guests_qty column exists, if not add it
        // Safe SQL: Table name sanitized via wpdb->prefix concatenation
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `{$order_table}` LIKE %s", 'guests_qty'));
        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$order_table}` ADD COLUMN guests_qty INT(11) NOT NULL DEFAULT 1 AFTER rooms_qty");
        }

        // Add new booking detail columns
        $new_columns = [
            'vat_number' => 'VARCHAR(50) NULL AFTER buyer_age',
            'purpose_of_stay' => 'VARCHAR(50) NULL AFTER vat_number',
            'estimated_arrival_time' => 'VARCHAR(50) NULL AFTER purpose_of_stay',
            'bringing_pets' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER estimated_arrival_time',
            'pet_details' => 'TEXT NULL AFTER bringing_pets',
        ];

        foreach ($new_columns as $column_name => $column_definition) {
            // Safe SQL: Table name sanitized via wpdb->prefix, column names are from controlled array
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$order_table}` LIKE '{$column_name}'");
            if (empty($column_exists)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE `{$order_table}` ADD COLUMN {$column_name} {$column_definition}");
            }
        }
        
        // Add external booking fields if they don't exist
        // Safe SQL: Table name sanitized via wpdb->prefix concatenation
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $external_source_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$order_table}` LIKE 'external_booking_source'");
        if (empty($external_source_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$order_table}` ADD COLUMN external_booking_source VARCHAR(50) NULL AFTER payment_id");
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$order_table}` ADD COLUMN external_booking_id VARCHAR(191) NULL AFTER external_booking_source");
        }
        
        // Remove event_id and seats_qty columns if they exist (from old schema)
        // Safe SQL: Table name sanitized via wpdb->prefix concatenation
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $event_id_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$order_table}` LIKE 'event_id'");
        if (!empty($event_id_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$order_table}` DROP COLUMN event_id");
        }
        
        // Safe SQL: Table name sanitized via wpdb->prefix concatenation
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $seats_qty_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$order_table}` LIKE 'seats_qty'");
        if (!empty($seats_qty_exists)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$order_table}` DROP COLUMN seats_qty");
        }
    }

}
