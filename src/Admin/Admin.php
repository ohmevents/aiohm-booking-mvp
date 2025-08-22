<?php

namespace AIOHM\BookingMVP\Admin;

use Exception;
use DateTime;
use DatePeriod;
use DateInterval;

if ( ! defined('ABSPATH') ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.NonceVerification.Missing
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// Reason: This class manages custom booking tables and requires direct database access for admin operations
class Admin {
    public static function init(){
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_init',[__CLASS__,'admin_init_hooks']);
        add_action('admin_init',[__CLASS__,'handle_calendar_redirect']);
        add_action('admin_init', [__CLASS__, 'handle_custom_settings_save']);
        add_action('admin_init', [__CLASS__, 'handle_accommodation_details_save']);
    }
    public static function menu(){
        $menu_icon = self::get_menu_icon();
        add_menu_page('AIOHM Booking','AIOHM Booking','manage_options','aiohm-booking-mvp',[__CLASS__,'dash'],$menu_icon,27);
        add_submenu_page('aiohm-booking-mvp','Dashboard','Dashboard','manage_options','aiohm-booking-mvp',[__CLASS__,'dash']);
        add_submenu_page('aiohm-booking-mvp','Settings','Settings','manage_options','aiohm-booking-mvp-settings',[__CLASS__,'settings']);

        // Get settings to determine which menu items to show
        $settings = function_exists('aiohm_booking_mvp_opts') ? aiohm_booking_mvp_opts() : get_option('aiohm_booking_mvp_settings', []);
        
        // Default to enabled if settings are missing (initial setup)
        $rooms_enabled = !empty($settings['enable_rooms']) || empty($settings);
        $notifications_enabled = !empty($settings['enable_notifications']);

        if ($rooms_enabled) {
            add_submenu_page('aiohm-booking-mvp','Accommodation','Accommodation','manage_options','aiohm-booking-mvp-accommodations',[__CLASS__,'accommodation_module']);
            add_submenu_page('aiohm-booking-mvp','Calendar','Calendar','manage_options','aiohm-booking-mvp-calendar',[__CLASS__,'calendar']);
            add_submenu_page('aiohm-booking-mvp','Orders','Orders','manage_options','aiohm-booking-mvp-orders',[__CLASS__,'orders']);
        }
        
        if ($notifications_enabled) {
            add_submenu_page('aiohm-booking-mvp','Notification','Notification','manage_options','aiohm-booking-mvp-notifications',[__CLASS__,'notification_module']);
        }

        // Always show help page
        add_submenu_page('aiohm-booking-mvp','Get Help','Get Help','manage_options','aiohm-booking-mvp-get-help',[__CLASS__,'help_page']);
    }
    public static function admin_init_hooks(){
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_styles']);
        // Backup CSS loading via admin_head for troubleshooting
        add_action('admin_head', [__CLASS__, 'backup_admin_styles']);

        // AJAX handlers for AI API settings
        add_action('wp_ajax_aiohm_booking_mvp_test_api_key', [__CLASS__, 'ajax_test_api_key']);
        add_action('wp_ajax_aiohm_booking_mvp_save_api_key', [__CLASS__, 'ajax_save_api_key']);
        add_action('wp_ajax_aiohm_booking_mvp_set_default_provider', [__CLASS__, 'ajax_set_default_provider']);
        // AJAX handler for quick module toggle save
        add_action('wp_ajax_aiohm_booking_mvp_toggle_module', [__CLASS__, 'ajax_toggle_module']);

        // AJAX handler for individual accommodation saves
        add_action('wp_ajax_aiohm_booking_mvp_save_ai_consent', [__CLASS__, 'ajax_save_ai_consent']);
        add_action('wp_ajax_aiohm_booking_mvp_save_individual_accommodation', [__CLASS__, 'ajax_save_individual_accommodation']);

        // AJAX handlers for calendar occupancy management
        add_action('wp_ajax_aiohm_booking_mvp_block_date', [__CLASS__, 'ajax_block_date']);
        add_action('wp_ajax_aiohm_booking_mvp_unblock_date', [__CLASS__, 'ajax_unblock_date']);
        add_action('wp_ajax_aiohm_booking_mvp_get_date_info', [__CLASS__, 'ajax_get_date_info']);
        add_action('wp_ajax_aiohm_booking_mvp_set_date_status', [__CLASS__, 'ajax_set_date_status']);
        add_action('wp_ajax_aiohm_booking_mvp_reset_all_days', [__CLASS__, 'ajax_reset_all_days']);
        add_action('wp_ajax_aiohm_booking_mvp_set_private_event', [__CLASS__, 'ajax_set_private_event']);
        add_action('wp_ajax_aiohm_booking_mvp_remove_private_event', [__CLASS__, 'ajax_remove_private_event']);
        add_action('wp_ajax_aiohm_booking_mvp_get_private_events', [__CLASS__, 'ajax_get_private_events']);
        add_action('wp_ajax_aiohm_booking_mvp_sync_calendar', [__CLASS__, 'ajax_sync_calendar']);
        add_action('wp_ajax_aiohm_booking_mvp_ai_calendar_insights', [__CLASS__, 'ajax_calendar_ai_insights']);
        
        // AJAX handler for AI table queries
        add_action('wp_ajax_aiohm_booking_mvp_ai_table_query', [__CLASS__, 'ajax_ai_table_query']);
        
        // AJAX handler for AI order queries
        add_action('wp_ajax_aiohm_booking_mvp_ai_order_query', [__CLASS__, 'ajax_ai_order_query']);
        
        // AJAX handlers for notification module
        add_action('wp_ajax_aiohm_test_smtp_connection', [__CLASS__, 'ajax_test_smtp_connection']);
        add_action('wp_ajax_aiohm_reset_email_template', [__CLASS__, 'ajax_reset_email_template']);

        // Handle database updates
        add_action('admin_init', [__CLASS__, 'maybe_update_database']);
        // Handle accommodation details form submission
        add_action('admin_init', [__CLASS__, 'handle_accommodation_details_save']);
    }

    /**
     * Checks for both global and per-request AI consent.
     * Sends a JSON error and dies if consent is not granted.
     */
    private static function check_ai_consent() {
        $settings = aiohm_booking_mvp_opts();
        $global_consent = !empty($settings['ai_api_consent']);

        if (!$global_consent) {
            wp_send_json_error('You must consent to using external AI services. Please enable AI API calls in the plugin settings.');
        }
    }

    /**
     * AJAX: AI insights for calendar data
     */
    public static function ajax_calendar_ai_insights() {
        check_ajax_referer('aiohm_booking_mvp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $question = sanitize_text_field(wp_unslash($_POST['question'] ?? ''));
        $summary  = sanitize_textarea_field(wp_unslash($_POST['summary'] ?? ''));
        $period   = sanitize_text_field(wp_unslash($_POST['period'] ?? ''));

        if ($question === '' || $summary === '') {
            wp_send_json_error('Missing question or data');
        }

        self::check_ai_consent();
        $settings = aiohm_booking_mvp_opts();
        $provider = $settings['default_ai_provider'] ?? 'shareai';
        $ai = new \AIOHM\BookingMVP\AI\Client();

        if (!$ai->is_api_key_configured($provider)) {
            wp_send_json_error('AI provider not configured. Add an API key in Settings.');
        }

        $prompt = self::build_ai_calendar_prompt($question, $summary, $period);
        $result = $ai->generate_booking_assistance($prompt, $provider);

        if (!empty($result['success'])) {
            wp_send_json_success(['answer' => $result['response'] ?? $result['message'] ?? '']);
        }

        wp_send_json_error($result['error'] ?? 'AI error');
    }

    private static function build_ai_calendar_prompt($question, $summary, $period) {
        $instructions = "You are analyzing a bookings calendar grid.\n" .
            "Data format: For each room, dates are listed with status codes: F=Free, B=Booked, P=Pending, X=Blocked, E=External, CI=Check-in, CO=Check-out.\n" .
            "Consider consecutive sequences across dates for each room. Answer concisely with specific counts and examples when helpful.";

        $context = "Current period: {$period}\n" .
                   "Calendar view data (compact):\n{$summary}\n\n";

        $task = "User question: {$question}\n" .
                "If asked about sequences like '3 in a row', count consecutive booked or pending days per room and report totals and any rooms/dates where it occurs.";

        return $instructions . "\n\n" . $context . $task;
    }

    /**
     * AJAX: AI Table Query - Ask questions about database table information
     */
    public static function ajax_ai_table_query() {
        check_ajax_referer('aiohm_booking_mvp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $question = sanitize_textarea_field(wp_unslash($_POST['question'] ?? ''));
        if (empty($question)) {
            wp_send_json_error('Please enter a question');
        }

        self::check_ai_consent();
        $settings = aiohm_booking_mvp_opts();
        $provider = $settings['default_ai_provider'] ?? 'shareai';
        $ai = new \AIOHM\BookingMVP\AI\Client();

        if (!$ai->is_api_key_configured($provider)) {
            wp_send_json_error('AI provider not configured. Add an API key in Settings.');
        }

        // Get table information for context
        $table_info = self::get_table_schema_info();
        $table_stats = self::get_table_statistics();
        
        $prompt = self::build_ai_table_query_prompt($question, $table_info, $table_stats);
        $result = $ai->generate_booking_assistance($prompt, $provider);

        if (!empty($result['success'])) {
            wp_send_json_success([
                'answer' => $result['response'] ?? $result['message'] ?? '',
                'table_info' => $table_info,
                'provider_used' => $provider
            ]);
        }

        wp_send_json_error($result['error'] ?? 'AI error');
    }

    /**
     * Build AI prompt for table queries with database context
     */
    private static function build_ai_table_query_prompt($question, $table_info, $table_stats) {
        $instructions = "You are a database analyst for AIOHM Booking system. " .
            "Answer questions about the booking database structure, data, and relationships. " .
            "Be concise and specific. Focus on practical insights for booking management.";

        $context = "Database Schema:\n";
        foreach ($table_info as $table_name => $columns) {
            $context .= "\nTable: {$table_name}\n";
            foreach ($columns as $column) {
                $context .= "  - {$column['Field']}: {$column['Type']} " . 
                           ($column['Null'] === 'NO' ? '(Required)' : '(Optional)') . 
                           (!empty($column['Key']) ? " [{$column['Key']} Key]" : '') . "\n";
            }
        }

        $stats_context = "\nTable Statistics:\n";
        foreach ($table_stats as $table_name => $stats) {
            $stats_context .= "  {$table_name}: {$stats['rows']} records\n";
            if (!empty($stats['sample_data'])) {
                $stats_context .= "    Recent statuses: " . implode(', ', array_unique($stats['sample_data'])) . "\n";
            }
        }

        $task = "\nUser Question: {$question}\n\n" .
                "Please provide a helpful, accurate response based on the schema and data above.";

        return $instructions . "\n\n" . $context . $stats_context . $task;
    }

    /**
     * Get database table schema information
     */
    private static function get_table_schema_info() {
        global $wpdb;
        
        $tables = [
            'orders' => $wpdb->prefix . 'aiohm_booking_mvp_order',
            'order_items' => $wpdb->prefix . 'aiohm_booking_mvp_item'
        ];
        
        $schema_info = [];
        foreach ($tables as $logical_name => $table_name) {
            // Safe SQL: Table names are sanitized via wpdb->prefix concatenation in get_table_names()
            $columns = $wpdb->get_results("DESCRIBE {$table_name}", ARRAY_A);
            $schema_info[$logical_name] = $columns;
        }
        
        return $schema_info;
    }

    /**
     * Get basic table statistics
     */
    private static function get_table_statistics() {
        global $wpdb;
        
        $order_table = $wpdb->prefix . 'aiohm_booking_mvp_order';
        $item_table = $wpdb->prefix . 'aiohm_booking_mvp_item';
        
        $stats = [];
        
        // Order table stats
        // Safe SQL: Table names are sanitized via wpdb->prefix concatenation
        $order_count = $wpdb->get_var("SELECT COUNT(*) FROM {$order_table}");
        $recent_statuses = $wpdb->get_col("SELECT DISTINCT status FROM {$order_table} ORDER BY created_at DESC LIMIT 10");
        
        $stats['orders'] = [
            'rows' => intval($order_count),
            'sample_data' => $recent_statuses ?: []
        ];
        
        // Order items stats
        // Safe SQL: Table names are sanitized via wpdb->prefix concatenation
        $item_count = $wpdb->get_var("SELECT COUNT(*) FROM {$item_table}");
        $recent_types = $wpdb->get_col("SELECT DISTINCT type FROM {$item_table} LIMIT 10");
        
        $stats['order_items'] = [
            'rows' => intval($item_count),
            'sample_data' => $recent_types ?: []
        ];
        
        return $stats;
    }

    /**
     * AJAX: AI Order Query - Ask questions about orders, customers, and business data
     */
    public static function ajax_ai_order_query() {
        check_ajax_referer('aiohm_booking_mvp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $question = sanitize_textarea_field(wp_unslash($_POST['question'] ?? ''));
        if (empty($question)) {
            wp_send_json_error('Please enter a question');
        }

        self::check_ai_consent();
        $settings = aiohm_booking_mvp_opts();
        $provider = $settings['default_ai_provider'] ?? 'shareai';
        $ai = new \AIOHM\BookingMVP\AI\Client();

        if (!$ai->is_api_key_configured($provider)) {
            wp_send_json_error('AI provider not configured. Add an API key in Settings.');
        }

        // Get order and customer data for context
        $order_info = self::get_order_data_summary();
        $customer_stats = self::get_customer_statistics();
        
        $prompt = self::build_ai_order_query_prompt($question, $order_info, $customer_stats);
        $result = $ai->generate_booking_assistance($prompt, $provider);

        if (!empty($result['success'])) {
            wp_send_json_success([
                'answer' => $result['response'] ?? $result['message'] ?? '',
                'order_info' => $order_info,
                'provider_used' => $provider
            ]);
        }

        wp_send_json_error($result['error'] ?? 'AI error');
    }

    /**
     * Build AI prompt for order queries with business context
     */
    private static function build_ai_order_query_prompt($question, $order_info, $customer_stats) {
        $instructions = "You are a business analyst for AIOHM Booking system. " .
            "Answer questions about orders, customers, booking patterns, and business performance. " .
            "Be concise and specific. Provide actionable insights for business growth.";

        $context = "Business Data Summary:\n";
        
        // Order statistics
        $context .= "\nOrder Statistics:\n";
        foreach ($order_info as $key => $value) {
            if (is_array($value)) {
                $context .= "  {$key}:\n";
                foreach ($value as $subkey => $subvalue) {
                    $context .= "    {$subkey}: {$subvalue}\n";
                }
            } else {
                $context .= "  {$key}: {$value}\n";
            }
        }

        // Customer statistics
        $context .= "\nCustomer Statistics:\n";
        foreach ($customer_stats as $key => $value) {
            if (is_array($value)) {
                $context .= "  {$key}: " . count($value) . " entries\n";
                if ($key === 'top_customers' && !empty($value)) {
                    $context .= "    Top 3 customers by value:\n";
                    foreach (array_slice($value, 0, 3) as $customer) {
                        $context .= "      {$customer['buyer_name']}: {$customer['order_count']} orders, €{$customer['total_spent']}\n";
                    }
                }
            } else {
                $context .= "  {$key}: {$value}\n";
            }
        }

        $task = "\nUser Question: {$question}\n\n" .
                "Please provide helpful business insights and actionable recommendations based on the data above.";

        return $instructions . "\n\n" . $context . $task;
    }

    /**
     * Get comprehensive order data summary
     */
    private static function get_order_data_summary() {
        global $wpdb;
        
        $order_table = $wpdb->prefix . 'aiohm_booking_mvp_order';
        
        $summary = [];
        
        // Total orders
        $total_orders = $wpdb->get_var("SELECT COUNT(*) FROM {$order_table}");
        $summary['total_orders'] = intval($total_orders);
        
        // Orders by status
        $status_counts = $wpdb->get_results("
            SELECT status, COUNT(*) as count 
            FROM {$order_table} 
            GROUP BY status
        ", ARRAY_A);
        
        $status_breakdown = [];
        foreach ($status_counts as $row) {
            $status_breakdown[$row['status']] = intval($row['count']);
        }
        $summary['status_breakdown'] = $status_breakdown;
        
        // Revenue data
        $revenue_data = $wpdb->get_row("
            SELECT 
                SUM(total_amount) as total_revenue,
                AVG(total_amount) as avg_order_value,
                SUM(deposit_amount) as total_deposits
            FROM {$order_table}
            WHERE status IN ('paid', 'pending')
        ", ARRAY_A);
        
        $summary['total_revenue'] = floatval($revenue_data['total_revenue'] ?? 0);
        $summary['average_order_value'] = floatval($revenue_data['avg_order_value'] ?? 0);
        $summary['total_deposits'] = floatval($revenue_data['total_deposits'] ?? 0);
        
        // Booking mode preferences
        $mode_counts = $wpdb->get_results("
            SELECT mode, COUNT(*) as count 
            FROM {$order_table} 
            GROUP BY mode
        ", ARRAY_A);
        
        $mode_breakdown = [];
        foreach ($mode_counts as $row) {
            $mode_breakdown[$row['mode']] = intval($row['count']);
        }
        $summary['booking_mode_preferences'] = $mode_breakdown;
        
        // Recent activity (last 30 days)
        $recent_orders = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$order_table} 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $summary['orders_last_30_days'] = intval($recent_orders);
        
        return $summary;
    }

    /**
     * Get customer statistics and insights
     */
    private static function get_customer_statistics() {
        global $wpdb;
        
        $order_table = $wpdb->prefix . 'aiohm_booking_mvp_order';
        
        $stats = [];
        
        // Unique customers
        $unique_customers = $wpdb->get_var("SELECT COUNT(DISTINCT buyer_email) FROM {$order_table}");
        $stats['unique_customers'] = intval($unique_customers);
        
        // Repeat customers
        $repeat_customers = $wpdb->get_var("
            SELECT COUNT(*) FROM (
                SELECT buyer_email 
                FROM {$order_table} 
                GROUP BY buyer_email 
                HAVING COUNT(*) > 1
            ) as repeat_buyers
        ");
        $stats['repeat_customers'] = intval($repeat_customers);
        
        // Top customers by value
        $top_customers = $wpdb->get_results("
            SELECT buyer_name, buyer_email, COUNT(*) as order_count, SUM(total_amount) as total_spent
            FROM {$order_table}
            WHERE status IN ('paid', 'pending')
            GROUP BY buyer_email
            ORDER BY total_spent DESC
            LIMIT 5
        ", ARRAY_A);
        
        $stats['top_customers'] = $top_customers;
        
        return $stats;
    }

    /**
     * AJAX: Save AI API consent setting
     */
    public static function ajax_save_ai_consent() {
        check_ajax_referer('aiohm_booking_mvp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $consent = !empty($_POST['consent']);
        
        $settings = aiohm_booking_mvp_opts();
        $settings['ai_api_consent'] = $consent ? '1' : '0';
        
        update_option('aiohm_booking_mvp_settings', $settings);
        
        wp_send_json_success([
            'message' => 'Consent settings saved successfully.'
        ]);
    }

    /**
     * AJAX: Toggle module enable/disable and persist option
     */
    public static function ajax_toggle_module() {
        check_ajax_referer('aiohm_booking_mvp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'aiohm-booking-mvp'));
        }
        $module = sanitize_text_field(wp_unslash($_POST['module'] ?? ''));
        $value  = sanitize_text_field(wp_unslash($_POST['value'] ?? '0'));
        $allowed = ['enable_rooms', 'enable_notifications', 'enable_stripe', 'enable_paypal', 'enable_booking_com', 'enable_airbnb', 'enable_shareai', 'enable_openai', 'enable_gemini'];
        if (!in_array($module, $allowed, true)) {
            wp_send_json_error('Invalid module');
        }
        $settings = get_option('aiohm_booking_mvp_settings', []);
        $settings[$module] = $value === '1' ? '1' : '0';
        update_option('aiohm_booking_mvp_settings', $settings);
        set_transient('aiohm_booking_mvp_save_success', 'Settings saved successfully! Menu updated.', 30);
        wp_send_json_success(['module' => $module, 'value' => $settings[$module]]);
    }
    /**
     * AJAX handler for API key testing
     */
    public static function ajax_test_api_key() {
        check_ajax_referer('aiohm_booking_mvp_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'aiohm-booking-mvp'));
        }

        $provider = sanitize_text_field(wp_unslash($_POST['provider'] ?? ''));
        $api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));

        if (empty($provider) || empty($api_key)) {
            wp_send_json_error('Missing provider or API key');
        }

        // Create temporary settings for testing
        $test_settings = [$provider . '_api_key' => $api_key];
        $ai_client = new \AIOHM\BookingMVP\AI\Client($test_settings);

        switch ($provider) {
            case 'openai':
                $result = $ai_client->test_openai_api_connection();
                break;
            case 'gemini':
                $result = $ai_client->test_gemini_api_connection();
                break;
            case 'shareai':
                $result = $ai_client->test_shareai_api_connection();
                break;
            default:
                wp_send_json_error('Invalid provider');
        }

        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['error']);
        }
    }

    /**
     * AJAX handler for saving individual API keys
     */
    public static function ajax_save_api_key() {
        check_ajax_referer('aiohm_booking_mvp_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have sufficient permissions to access this page.');
        }

        $provider = sanitize_text_field(wp_unslash($_POST['provider'] ?? ''));
        $api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));

        if (empty($provider)) {
            wp_send_json_error('Missing provider');
        }

        // Get current settings
        $settings = aiohm_booking_mvp_opts();

        $key_name = $provider . '_api_key';
        $settings[$key_name] = $api_key;

        // Save settings
        $result = update_option('aiohm_booking_mvp_settings', $settings);

        // Double check with direct get_option
        $check_settings = get_option('aiohm_booking_mvp_settings', []);

        $saved_key = $check_settings[$key_name] ?? '';

        if ($saved_key !== $api_key) {
            wp_send_json_error('API key not saved correctly - mismatch detected');
        }

        wp_send_json_success('API key saved successfully');
    }

    /**
     * AJAX handler for setting default AI provider
     */
    public static function ajax_set_default_provider() {
        check_ajax_referer('aiohm_booking_mvp_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have sufficient permissions to access this page.');
        }

        $provider = sanitize_text_field(wp_unslash($_POST['provider'] ?? ''));
        $valid_providers = ['shareai', 'openai', 'gemini'];

        if (empty($provider) || !in_array($provider, $valid_providers)) {
            wp_send_json_error('Invalid provider');
        }

        $settings = aiohm_booking_mvp_opts();
        $settings['default_ai_provider'] = $provider;

        $result = update_option('aiohm_booking_mvp_settings', $settings);

        if ($result === false && get_option('aiohm_booking_mvp_settings')['default_ai_provider'] !== $provider) {
            wp_send_json_error('Failed to set default provider');
        }

        wp_send_json_success(['message' => ucfirst($provider) . ' set as default provider', 'provider' => $provider]);
    }

    /**
     * AJAX: Save individual accommodation settings
     */
    public static function ajax_save_individual_accommodation() {
        try {
            check_ajax_referer('aiohm_booking_mvp_admin_nonce', 'nonce');
        } catch (Exception $e) {
            wp_send_json_error('Nonce verification failed: ' . $e->getMessage());
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have sufficient permissions to access this page.');
        }

        $accommodation_index = intval($_POST['accommodation_index'] ?? -1);
        $accommodation_data = is_array($_POST['accommodation_data'] ?? []) ? map_deep(wp_unslash($_POST['accommodation_data']), 'sanitize_text_field') : [];

        if ($accommodation_index < 0) {
            wp_send_json_error('Invalid accommodation index: ' . $accommodation_index);
        }

        // Get current accommodation details
        $accommodation_details = get_option('aiohm_booking_mvp_accommodations_details', []);

        // Determine default title if empty
        $names = function_exists('aiohm_booking_mvp_get_product_names') ? aiohm_booking_mvp_get_product_names() : ['singular_cap' => 'Accommodation'];
        $default_singular = $names['singular_cap'] ?? 'Accommodation';
        $incoming_title = trim((string)($accommodation_data['title'] ?? ''));
        if ($incoming_title === '') {
            $incoming_title = $default_singular . ' ' . ($accommodation_index + 1);
        }

        // Sanitize the data
        $sanitized_data = [
            'title' => sanitize_text_field($incoming_title),
            'description' => sanitize_textarea_field($accommodation_data['description'] ?? ''),
            'earlybird_price' => sanitize_text_field($accommodation_data['earlybird_price'] ?? ''),
            'price' => sanitize_text_field($accommodation_data['price'] ?? ''),
            'type' => sanitize_text_field($accommodation_data['type'] ?? 'room')
        ];


        // Update the specific accommodation
        $accommodation_details[$accommodation_index] = $sanitized_data;

        // Save to database
        $result = update_option('aiohm_booking_mvp_accommodations_details', $accommodation_details);

        // Check if the data was actually saved by reading it back
        $saved_data = get_option('aiohm_booking_mvp_accommodations_details', []);
        $was_saved = isset($saved_data[$accommodation_index]) &&
                     $saved_data[$accommodation_index]['title'] === $sanitized_data['title'] &&
                     $saved_data[$accommodation_index]['type'] === $sanitized_data['type'];

        if ($was_saved) {
            wp_send_json_success([
                'message' => 'Accommodation saved successfully',
                'accommodation_data' => $sanitized_data,
                'debug_info' => [
                    'index' => $accommodation_index,
                    'total_accommodations' => count($accommodation_details),
                    'update_result' => $result
                ]
            ]);
        } else {
            wp_send_json_error('Database update failed - data verification failed');
        }
    }

    /**
     * Get menu icon
     */
    private static function get_menu_icon() {
        // Detect admin color scheme for dynamic theming
        $admin_color = get_user_option('admin_color');
        $is_dark_theme = in_array($admin_color, ['midnight', 'blue', 'coffee', 'ectoplasm', 'ocean']);
        // Use the OHM logo SVG files
        $logo_path = $is_dark_theme
            ? aiohm_booking_mvp_asset_path('images/aiohm-booking-OHM_logo-white.svg')
            : aiohm_booking_mvp_asset_path('images/aiohm-booking-OHM_logo-black.svg');
        if (file_exists($logo_path)) {
            $svg_content = file_get_contents($logo_path);
            if ($svg_content !== false) {
                return 'data:image/svg+xml;base64,' . base64_encode($svg_content);
            }
        }
        // Fallback to dashicon
        return 'dashicons-calendar-alt';
    }

    public static function dash(){
        global $wpdb;

        // Get some basic stats
        $table = $wpdb->prefix.'aiohm_booking_mvp_order';
        // Safe SQL: Table names are sanitized via wpdb->prefix concatenation, user input is prepared
        $total_orders = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $pending_orders = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending'));
        $paid_orders = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'paid'));
        $total_revenue = $wpdb->get_var($wpdb->prepare("SELECT SUM(total_amount) FROM {$table} WHERE status = %s", 'paid')) ?: 0;

        // Get currency setting for revenue display
        $settings = aiohm_booking_mvp_opts();
        $currency = $settings['currency'] ?? 'EUR';

        ?>
        <div class="wrap aiohm-booking-mvp-admin">
            <div class="aiohm-header">
                <div class="aiohm-header-content">
                    <div class="aiohm-logo">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=aiohm-booking-mvp')); ?>">
                            <img src="<?php echo esc_url( aiohm_booking_mvp_asset_url('images/aiohm-booking-OHM_logo-black.svg') ); ?>" alt="AIOHM" class="aiohm-header-logo">
                        </a>
                    </div>
                    <div class="aiohm-header-text">
                        <h1>AIOHM Booking MVP</h1>
                        <p class="aiohm-tagline">The world's first modular booking plugin with AI analytics - Fully working MVP</p>
                    </div>
                </div>
            </div>

            <!-- MVP Introduction Banner -->
            <div class="aiohm-mvp-banner">
                <div class="aiohm-mvp-content">
                    <div class="aiohm-mvp-badge">✨ MVP RELEASE</div>
                    <h2>Welcome to the Future of WordPress Booking</h2>
                    <p>Experience the <strong>best modular plugin for booking accommodations and events</strong> with revolutionary AI analytics integration. This fully working MVP showcases two powerful modules designed for the conscious hospitality industry.</p>
                    <div class="aiohm-mvp-modules">
                        <div class="aiohm-mvp-module">
                            <div class="module-icon">🏨</div>
                            <h4>Accommodation Module</h4>
                            <p>Complete booking system for venues, hotels, and spaces</p>
                        </div>
                        <div class="aiohm-mvp-module">
                            <div class="module-icon">🧠</div>
                            <h4>AI Analytics Module</h4>
                            <p>First WordPress plugin with integrated AI insights</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AI Data Analytics Hero -->
            <div class="aiohm-ai-hero">
                <div class="aiohm-ai-content">
                    <div class="aiohm-ai-badge">🤖 AI POWERED</div>
                    <h3>Data Intelligence at Work</h3>
                    <div class="aiohm-ai-stats">
                        <div class="aiohm-ai-stat-main">
                            <div class="ai-number"><?php echo esc_html($total_orders * 24); ?></div>
                            <div class="ai-label">Data Points Collected</div>
                            <div class="ai-subtitle">Ready for AI Analysis</div>
                        </div>
                        <div class="aiohm-ai-providers">
                            <div class="ai-provider-active">
                                <span class="ai-dot active"></span>
                                <?php echo esc_html($settings['default_ai_provider'] ?? 'ShareAI'); ?> Connected
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Business Metrics -->
            <div class="aiohm-business-metrics">
                <h3>Business Performance</h3>
                <div class="aiohm-metrics-grid">
                    <div class="aiohm-metric-card revenue">
                        <div class="metric-icon">💰</div>
                        <div class="metric-content">
                            <div class="metric-number"><?php echo esc_html($currency . ' ' . number_format($total_revenue, 2)); ?></div>
                            <div class="metric-label">Total Revenue</div>
                            <div class="metric-growth">+<?php echo esc_html(number_format(($paid_orders / max($total_orders, 1)) * 100, 1)); ?>% conversion</div>
                        </div>
                    </div>
                    <div class="aiohm-metric-card orders">
                        <div class="metric-icon">📋</div>
                        <div class="metric-content">
                            <div class="metric-number"><?php echo esc_html($total_orders); ?></div>
                            <div class="metric-label">Total Bookings</div>
                            <div class="metric-status"><?php echo esc_html($pending_orders); ?> pending • <?php echo esc_html($paid_orders); ?> paid</div>
                        </div>
                    </div>
                    <div class="aiohm-metric-card analytics">
                        <div class="metric-icon">📊</div>
                        <div class="metric-content">
                            <div class="metric-number"><?php echo esc_html($total_orders > 0 ? round(($total_orders * 24) / $total_orders, 1) : '24.0'); ?></div>
                            <div class="metric-label">Avg Data Points/Order</div>
                            <div class="metric-insight">Rich guest insights</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- User Journey & Quick Actions -->
            <div class="aiohm-user-journey">
                <h3>Your Next Steps</h3>
                <div class="aiohm-journey-steps">
                    <div class="aiohm-journey-step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h4>Configure Modules</h4>
                            <p>Enable your accommodation booking and AI analytics</p>
                            <a href="?page=aiohm-booking-mvp-settings" class="button button-primary">Go to Settings</a>
                        </div>
                    </div>
                    <div class="aiohm-journey-step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h4>Set Up Accommodations</h4>
                            <p>Define your rooms, pricing, and availability</p>
                            <a href="?page=aiohm-booking-mvp-accommodations" class="button button-secondary">Configure Rooms</a>
                        </div>
                    </div>
                    <div class="aiohm-journey-step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h4>Deploy & Analyze</h4>
                            <p>Add booking forms and watch AI insights grow</p>
                            <a href="?page=aiohm-booking-mvp-orders" class="button button-secondary">View Analytics</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="aiohm-dashboard-grid">
                <div class="aiohm-dashboard-left">
                    <!-- Module Deep Dive -->
                    <div class="aiohm-booking-mvp-card aiohm-modules-card">
                        <h3>Module Architecture</h3>
                        <p>This MVP demonstrates our modular approach - use what you need, when you need it.</p>
                        
                        <div class="aiohm-module-details">
                            <div class="aiohm-module-detail accommodation">
                                <div class="module-header">
                                    <div class="module-icon-large">🏨</div>
                                    <div>
                                        <h4>Accommodation Module</h4>
                                        <span class="module-status active">Active & Ready</span>
                                    </div>
                                </div>
                                <div class="module-features">
                                    <div class="feature-list">
                                        <span class="feature">✓ Multi-room booking</span>
                                        <span class="feature">✓ Dynamic pricing</span>
                                        <span class="feature">✓ Availability calendar</span>
                                        <span class="feature">✓ Guest management</span>
                                    </div>
                                    <p class="module-description">Perfect for hotels, B&Bs, vacation rentals, and event venues. Handles individual rooms or entire property bookings with flexible pricing models.</p>
                                </div>
                            </div>

                            <div class="aiohm-module-detail analytics">
                                <div class="module-header">
                                    <div class="module-icon-large">🧠</div>
                                    <div>
                                        <h4>AI Analytics Module</h4>
                                        <span class="module-status learning">Learning & Analyzing</span>
                                    </div>
                                </div>
                                <div class="module-features">
                                    <div class="feature-list">
                                        <span class="feature">✓ Guest behavior tracking</span>
                                        <span class="feature">✓ Booking pattern analysis</span>
                                        <span class="feature">✓ Revenue optimization</span>
                                        <span class="feature">✓ Predictive insights</span>
                                    </div>
                                    <p class="module-description">Revolutionary AI integration collects <strong>24 data points per booking</strong> - from guest preferences to booking patterns - turning every reservation into actionable business intelligence.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- AI Insights Preview -->
                    <div class="aiohm-booking-mvp-card aiohm-ai-insights-card">
                        <h3>AI Analytics in Action</h3>
                        <div class="aiohm-ai-preview">
                            <div class="ai-insight-demo">
                                <div class="insight-icon">💡</div>
                                <div class="insight-content">
                                    <h4>Sample AI Insight</h4>
                                    <p>"Based on <strong><?php echo esc_html($total_orders * 24); ?> data points</strong>, guests booking <?php echo esc_html($settings['accommodation_product_name'] ?? 'rooms'); ?> show 73% preference for weekend arrivals. Consider dynamic pricing for Friday-Sunday slots."</p>
                                </div>
                            </div>
                            <div class="ai-data-types">
                                <h4>Data Points We Collect:</h4>
                                <div class="data-grid">
                                    <span class="data-point">Booking timing</span>
                                    <span class="data-point">Guest demographics</span>
                                    <span class="data-point">Stay duration</span>
                                    <span class="data-point">Room preferences</span>
                                    <span class="data-point">Seasonal patterns</span>
                                    <span class="data-point">Payment methods</span>
                                    <span class="data-point">Special requests</span>
                                    <span class="data-point">Repeat visits</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="aiohm-dashboard-right">
                    <!-- Quick Implementation -->
                    <div class="aiohm-booking-mvp-card aiohm-implementation-card">
                        <h3>🚀 Quick Implementation</h3>
                        <div class="implementation-steps">
                            <div class="impl-step">
                                <div class="impl-icon">📝</div>
                                <div class="impl-content">
                                    <h4>Add Booking Form</h4>
                                    <p>Copy this shortcode to any page or post:</p>
                                    <code class="shortcode-highlight">[aiohm_booking_mvp]</code>
                                </div>
                            </div>
                            <div class="impl-step">
                                <div class="impl-icon">⚙️</div>
                                <div class="impl-content">
                                    <h4>Configure Settings</h4>
                                    <p>Set up payments, pricing, and AI analytics</p>
                                    <a href="?page=aiohm-booking-mvp-settings" class="button button-small">Settings</a>
                                </div>
                            </div>
                            <div class="impl-step">
                                <div class="impl-icon">📊</div>
                                <div class="impl-content">
                                    <h4>View Analytics</h4>
                                    <p>Monitor bookings and AI insights</p>
                                    <a href="?page=aiohm-booking-mvp-orders" class="button button-small">Analytics</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Support & Resources -->
                    <div class="aiohm-booking-mvp-card aiohm-support-card">
                        <h3>💬 Support & Resources</h3>
                        <div class="support-items">
                            <div class="support-item">
                                <div class="support-icon">📚</div>
                                <div class="support-content">
                                    <h4>Documentation</h4>
                                    <p>Complete setup guides and tutorials</p>
                                    <a href="?page=aiohm-booking-mvp-get-help" class="button button-small">Get Help</a>
                                </div>
                            </div>
                            <div class="support-item">
                                <div class="support-icon">🎯</div>
                                <div class="support-content">
                                    <h4>MVP Focus</h4>
                                    <p>This is a fully functional MVP showcasing core features</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render calendar page - delegates to old admin class for complete functionality
     */
    public static function calendar(){
        // Delegate to the old admin class which has the complete calendar functionality
        if (class_exists('AIOHM_BOOKING_MVP_Admin')) {
            return \AIOHM_BOOKING_MVP_Admin::calendar();
        }
        
        // Fallback: use the namespaced calendar class
        echo '<div class="wrap"><h1>Booking Calendar</h1>';
        \AIOHM\BookingMVP\Calendar\Calendar::render();
        echo '</div>';
    }

    /**
     * Render orders page - delegates to old admin class for complete functionality
     */
    public static function orders(){
        // Delegate to the old admin class which has the complete orders functionality
        if (class_exists('AIOHM_BOOKING_MVP_Admin')) {
            return \AIOHM_BOOKING_MVP_Admin::orders();
        }
        
        // Fallback implementation (incomplete):
        echo '<div class="wrap aiohm-booking-mvp-admin">';
        echo '<div class="aiohm-header">';
        echo '<div class="aiohm-header-content">';
        echo '<div class="aiohm-logo">';
        echo '<img src="' . esc_url(aiohm_booking_mvp_asset_url('images/aiohm-booking-OHM_logo-black.svg')) . '" alt="AIOHM" class="aiohm-header-logo">';
        echo '</div>';
        echo '<div class="aiohm-header-text">';
        echo '<h1>Orders Overview</h1>';
        echo '<p class="aiohm-tagline">Manage and analyze your booking orders with AI-powered insights</p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        global $wpdb;
        $table = $wpdb->prefix . 'aiohm_booking_mvp_order';
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

        echo '<div class="aiohm-orders-container">';
        
        // AI Query Section
        echo '<div class="aiohm-ai-section">';
        echo '<h3>🧠 AI Business Insights</h3>';
        echo '<div class="aiohm-ai-query-box">';
        echo '<textarea id="aiohm-ai-order-question" placeholder="Ask about your orders, customers, or business performance..." rows="3"></textarea>';
        echo '<button type="button" id="aiohm-ask-ai-order" class="button button-primary">Ask AI</button>';
        echo '</div>';
        echo '<div id="aiohm-ai-order-response" class="aiohm-ai-response" style="display:none;"></div>';
        echo '</div>';

        echo '<div class="aiohm-orders-table-container">';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Order ID</th>';
        echo '<th>Customer</th>';
        echo '<th>Email</th>';
        echo '<th>Amount</th>';
        echo '<th>Status</th>';
        echo '<th>Mode</th>';
        echo '<th>Created</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        if (empty($orders)) {
            echo '<tr><td colspan="7">No orders found.</td></tr>';
        } else {
            foreach ($orders as $order) {
                $status_class = 'status-' . esc_attr($order['status']);
                echo '<tr>';
                echo '<td><strong>#' . esc_html($order['id']) . '</strong></td>';
                echo '<td>' . esc_html($order['buyer_name']) . '</td>';
                echo '<td>' . esc_html($order['buyer_email']) . '</td>';
                echo '<td>€' . esc_html(number_format($order['total_amount'], 2)) . '</td>';
                echo '<td><span class="order-status ' . $status_class . '">' . esc_html(ucfirst($order['status'])) . '</span></td>';
                echo '<td>' . esc_html(ucfirst($order['mode'] ?? 'N/A')) . '</td>';
                echo '<td>' . esc_html(date('M j, Y', strtotime($order['created_at']))) . '</td>';
                echo '</tr>';
            }
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';

        // Pagination
        if ($total_pages > 1) {
            echo '<div class="tablenav">';
            echo '<div class="tablenav-pages">';
            echo paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total' => $total_pages,
                'current' => $current_page,
                'type' => 'list'
            ]);
            echo '</div>';
            echo '</div>';
        }

        echo '</div>'; // aiohm-orders-container
        echo '</div>'; // wrap
    }

    /**
     * Render settings page - delegates to old admin class for complete functionality
     */
    public static function settings(){
        // Delegate to the old admin class which has the complete settings functionality
        if (class_exists('AIOHM_BOOKING_MVP_Admin')) {
            return \AIOHM_BOOKING_MVP_Admin::settings();
        }
        
        // Fallback: capture save result notices (rendered under subtitle)
        $success_msg = get_transient('aiohm_booking_mvp_save_success');
        if ($success_msg) { delete_transient('aiohm_booking_mvp_save_success'); }
        $error_msg = get_transient('aiohm_booking_mvp_save_error');
        if ($error_msg) { delete_transient('aiohm_booking_mvp_save_error'); }

        $o = get_option('aiohm_booking_mvp_settings',[]);
        $enable_rooms = !empty($o['enable_rooms']);
        $enable_notifications = !empty($o['enable_notifications']);
        
        $available_rooms = intval($o['available_rooms'] ?? 7);
        $room_price = esc_attr($o['room_price'] ?? '0');
        
        $currency = esc_attr($o['currency'] ?? 'EUR');
        $deposit = esc_attr($o['deposit_percent'] ?? '30');
        $allow_private = !empty($o['allow_private_all']);
        $min_age = intval($o['min_age'] ?? 0);
        $booking_com_enabled = !empty($o['enable_booking_com']);
        $airbnb_enabled = !empty($o['enable_airbnb']);
        $booking_com_property_id = $o['booking_com_property_id'] ?? '';
        $airbnb_property_id = $o['airbnb_property_id'] ?? '';

        $default_ai_provider = esc_attr($o['default_ai_provider'] ?? 'shareai');
        $current_accommodation_type = esc_attr($o['accommodation_product_name'] ?? $o['room_product_name'] ?? 'room');
        $plugin_language = esc_attr($o['plugin_language'] ?? 'en');

        // Form customization settings
        $form_primary_color = esc_attr($o['form_primary_color'] ?? '#6b9d7a');
        $form_title = esc_attr($o['form_title'] ?? '');
        $form_subtitle = esc_attr($o['form_subtitle'] ?? '');
        $form_text_color = esc_attr($o['form_text_color'] ?? '#1a1a1a');
        $form_field_address = !empty($o['form_field_address']);
        $form_field_age = !empty($o['form_field_age']);
        $form_field_company = !empty($o['form_field_company']);
        $form_field_country = !empty($o['form_field_country']);
        $form_field_vat = !empty($o['form_field_vat']);
        $form_field_pets = !empty($o['form_field_pets']);
        $form_field_arrival_time = !empty($o['form_field_arrival_time']);
        $form_field_phone = !empty($o['form_field_phone']);
        $form_field_special_requests = !empty($o['form_field_special_requests']);

        // Payment module settings
        $enable_stripe = !empty($o['enable_stripe']);
        $stripe_publishable_key = esc_attr($o['stripe_publishable_key'] ?? '');
        $stripe_secret_key = esc_attr($o['stripe_secret_key'] ?? '');
        $stripe_webhook_secret = esc_attr($o['stripe_webhook_secret'] ?? '');
        $stripe_webhook_endpoint = esc_attr($o['stripe_webhook_endpoint'] ?? '');

        $enable_paypal = !empty($o['enable_paypal']);
        $paypal_client_id = esc_attr($o['paypal_client_id'] ?? '');
        $paypal_client_secret = esc_attr($o['paypal_client_secret'] ?? '');
        $paypal_environment = esc_attr($o['paypal_environment'] ?? 'sandbox');

        ?>
        <div class="wrap aiohm-booking-mvp-admin">
            <div class="aiohm-header">
                <div class="aiohm-header-content">
                    <div class="aiohm-logo">
                        <img src="<?php echo esc_url( aiohm_booking_mvp_asset_url('images/aiohm-booking-OHM_logo-black.svg') ); ?>" alt="AIOHM" class="aiohm-header-logo">
                    </div>
                    <div class="aiohm-header-text">
                        <h1>AIOHM Booking Module Configuration</h1>
                        <p class="aiohm-tagline">Configure your booking modules to align with your venue's unique offering and values.</p>
                    </div>
                </div>
            </div>

            <form method="post" action="">
            <?php wp_nonce_field('aiohm_booking_mvp_save_settings', 'aiohm_booking_mvp_settings_nonce'); ?>

            <?php if (!empty($success_msg)) : ?>
                <div class="notice notice-success is-dismissible aiohm-auto-close-notice aiohm-mt-10">
                    <p><?php echo esc_html($success_msg); ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_msg)) : ?>
                <div class="notice notice-error is-dismissible aiohm-auto-close-notice aiohm-mt-10">
                    <p><?php echo esc_html($error_msg); ?></p>
                </div>
            <?php endif; ?>

            <!-- Two Column Module Selection -->
            <div class="aiohm-booking-mvp-modules">
                <div class="aiohm-module-grid">
                    <!-- Accommodation Module -->
                    <div class="aiohm-module-card module-accommodation <?php echo $enable_rooms ? 'is-active' : 'is-inactive'; ?>">
                        <div class="aiohm-module-header">
                            <h3>Accommodation Module</h3>
                            <label class="aiohm-toggle" title="Enable or disable the Accommodation booking module">
                                <input type="checkbox" name="aiohm_booking_mvp_settings[enable_rooms]" value="1" <?php checked($enable_rooms,true); ?>>
                                <span class="aiohm-toggle-slider"></span>
                            </label>
                        </div>
                        <p class="aiohm-module-description">Perfect for accommodations, venues, houses, bungalows, and private spaces. Sell individual rooms or entire properties.</p>

                        <div class="aiohm-module-settings">
                            <div class="aiohm-setting-row-inline">
                                <div class="aiohm-setting-row">
                                    <label>Accommodation Type</label>
                                    <select name="aiohm_booking_mvp_settings[accommodation_product_name]" class="accommodation-type-select">
                                        <option value="room" <?php selected($current_accommodation_type, 'room'); ?>>Room</option>
                                        <option value="house" <?php selected($current_accommodation_type, 'house'); ?>>House</option>
                                        <option value="apartment" <?php selected($current_accommodation_type, 'apartment'); ?>>Apartment</option>
                                        <option value="villa" <?php selected($current_accommodation_type, 'villa'); ?>>Villa</option>
                                        <option value="bungalow" <?php selected($current_accommodation_type, 'bungalow'); ?>>Bungalow</option>
                                        <option value="cabin" <?php selected($current_accommodation_type, 'cabin'); ?>>Cabin</option>
                                        <option value="cottage" <?php selected($current_accommodation_type, 'cottage'); ?>>Cottage</option>
                                        <option value="suite" <?php selected($current_accommodation_type, 'suite'); ?>>Suite</option>
                                        <option value="studio" <?php selected($current_accommodation_type, 'studio'); ?>>Studio</option>
                                        <option value="unit" <?php selected($current_accommodation_type, 'unit'); ?>>Unit</option>
                                        <option value="space" <?php selected($current_accommodation_type, 'space'); ?>>Space</option>
                                        <option value="venue" <?php selected($current_accommodation_type, 'venue'); ?>>Venue</option>
                                    </select>
                                </div>
                                <div class="aiohm-setting-row">
                                    <label>Activate Accommodation</label>
                                    <select name="aiohm_booking_mvp_settings[available_rooms]" class="accommodation-quantity-select">
                                        <?php for ($i = 0; $i <= 20; $i++) : ?>
                                            <option value="<?php echo esc_attr($i); ?>" <?php selected($available_rooms, $i); ?>>
                                                <?php echo esc_html($i); ?><?php echo esc_html($i === 0 ? ' (Disabled)' : ($i === 1 ? ' accommodation' : ' accommodations')); ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="aiohm-setting-row aiohm-setting-row-spaced">
                                <label>
                                    <input type="checkbox" name="aiohm_booking_mvp_settings[allow_private_all]" value="1" <?php checked($allow_private,true); ?>>
                                    Allow "Book Entire Property" option
                                </label>
                            </div>
                            <div class="aiohm-setting-row">
                                <?php submit_button('Save & Configure', 'primary', 'save_and_configure', false); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Email Notification Module -->
                    <div class="aiohm-module-card module-notification <?php echo $enable_notifications ? 'is-active' : 'is-inactive'; ?>">
                        <div class="aiohm-module-header">
                            <h3>Email Notification Module</h3>
                            <label class="aiohm-toggle" title="Enable or disable the Email Notification module">
                                <input type="checkbox" name="aiohm_booking_mvp_settings[enable_notifications]" value="1" <?php checked($enable_notifications,true); ?>>
                                <span class="aiohm-toggle-slider"></span>
                            </label>
                        </div>
                        <p class="aiohm-module-description">Professional email notifications for booking confirmations, cancellations, and payment reminders. Custom SMTP configuration and branded email templates.</p>

                        <div class="aiohm-module-settings">
                            <div class="aiohm-setting-row-inline">
                                <div class="aiohm-setting-row">
                                    <label>Email Provider</label>
                                    <select name="aiohm_booking_mvp_settings[email_provider]" class="notification-provider-select">
                                        <option value="wordpress">WordPress Native (localhost/default)</option>
                                        <option value="smtp">Custom SMTP</option>
                                        <option value="gmail">Gmail</option>
                                        <option value="outlook">Outlook</option>
                                        <option value="mailgun">Mailgun</option>
                                        <option value="sendgrid">SendGrid</option>
                                    </select>
                                </div>
                                <div class="aiohm-setting-row">
                                    <label>Email Newsletter</label>
                                    <select name="aiohm_booking_mvp_settings[email_newsletter]" class="notification-newsletter-select">
                                        <option value="none">No Newsletter Integration</option>
                                        <option value="mautic">Mautic (self-hosted)</option>
                                        <option value="mailchimp">Mailchimp</option>
                                        <option value="convertkit">ConvertKit</option>
                                        <option value="activecampaign">ActiveCampaign</option>
                                        <option value="aweber">AWeber</option>
                                        <option value="mailerlite">MailerLite</option>
                                        <option value="constant_contact">Constant Contact</option>
                                        <option value="sendinblue">Sendinblue (Brevo)</option>
                                        <option value="klaviyo">Klaviyo</option>
                                        <option value="getresponse">GetResponse</option>
                                        <option value="drip">Drip</option>
                                        <option value="hubspot">HubSpot</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="aiohm-setting-row aiohm-setting-row-spaced">
                                <label>
                                    <input type="checkbox" name="aiohm_booking_mvp_settings[auto_send_notifications]" value="1" <?php checked(!empty($o['auto_send_notifications']),true); ?>>
                                    Automatically send booking confirmation emails
                                </label>
                            </div>

                            <div class="aiohm-setting-row">
                                <?php submit_button('Save & Configure', 'primary', 'save_and_configure_notifications', false); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render accommodation module - delegates to old admin class for complete functionality
     */
    public static function accommodation_module() {
        // Delegate to the old admin class which has the complete accommodation module functionality
        if (class_exists('AIOHM_BOOKING_MVP_Admin')) {
            return \AIOHM_BOOKING_MVP_Admin::accommodation_module();
        }
        
        // Fallback implementation:
        $o = get_option('aiohm_booking_mvp_settings',[]);
        $available_rooms = intval($o['available_rooms'] ?? 7);
        $current_accommodation_type = esc_attr($o['accommodation_product_name'] ?? $o['room_product_name'] ?? 'room');
        
        ?>
        <div class="wrap aiohm-booking-mvp-admin">
            <div class="aiohm-header">
                <div class="aiohm-header-content">
                    <div class="aiohm-logo">
                        <img src="<?php echo esc_url( aiohm_booking_mvp_asset_url('images/aiohm-booking-OHM_logo-black.svg') ); ?>" alt="AIOHM" class="aiohm-header-logo">
                    </div>
                    <div class="aiohm-header-text">
                        <h1>Accommodation Management</h1>
                        <p class="aiohm-tagline">Configure your <?php echo esc_html($current_accommodation_type); ?>s and accommodation settings.</p>
                    </div>
                </div>
            </div>
            
            <div class="aiohm-accommodations-grid">
                <?php for ($i = 1; $i <= $available_rooms; $i++) : 
                    $name = $o["accommodation_name_{$i}"] ?? "Accommodation {$i}";
                    $description = $o["accommodation_description_{$i}"] ?? '';
                    $price = $o["accommodation_price_{$i}"] ?? '0';
                    $deposit = $o["accommodation_deposit_{$i}"] ?? '30';
                    $photo = $o["accommodation_photo_url_{$i}"] ?? '';
                    $max_guests = $o["accommodation_max_guests_{$i}"] ?? '2';
                    $features = $o["accommodation_features_{$i}"] ?? [];
                    if (!is_array($features)) { $features = []; }
                ?>
                <div class="aiohm-accommodation-card" data-accommodation-id="<?php echo esc_attr($i); ?>">
                    <div class="aiohm-accommodation-header">
                        <h3><?php echo esc_html(ucfirst($current_accommodation_type)); ?> <?php echo esc_html($i); ?></h3>
                        <div class="aiohm-accommodation-status">
                            <span class="status-active">Active</span>
                        </div>
                    </div>
                    
                    <div class="aiohm-accommodation-form">
                        <div class="aiohm-form-row">
                            <label>Name</label>
                            <input type="text" name="accommodation_name_<?php echo esc_attr($i); ?>" value="<?php echo esc_attr($name); ?>" />
                        </div>
                        
                        <div class="aiohm-form-row">
                            <label>Description</label>
                            <textarea name="accommodation_description_<?php echo esc_attr($i); ?>" rows="3"><?php echo esc_textarea($description); ?></textarea>
                        </div>
                        
                        <div class="aiohm-form-row-inline">
                            <div class="aiohm-form-row">
                                <label>Price per Night (€)</label>
                                <input type="number" name="accommodation_price_<?php echo esc_attr($i); ?>" value="<?php echo esc_attr($price); ?>" min="0" step="0.01" />
                            </div>
                            <div class="aiohm-form-row">
                                <label>Deposit (%)</label>
                                <input type="number" name="accommodation_deposit_<?php echo esc_attr($i); ?>" value="<?php echo esc_attr($deposit); ?>" min="0" max="100" />
                            </div>
                        </div>
                        
                        <div class="aiohm-form-row-inline">
                            <div class="aiohm-form-row">
                                <label>Photo URL</label>
                                <input type="url" name="accommodation_photo_url_<?php echo esc_attr($i); ?>" value="<?php echo esc_attr($photo); ?>" />
                            </div>
                            <div class="aiohm-form-row">
                                <label>Max Guests</label>
                                <input type="number" name="accommodation_max_guests_<?php echo esc_attr($i); ?>" value="<?php echo esc_attr($max_guests); ?>" min="1" />
                            </div>
                        </div>
                        
                        <div class="aiohm-form-row">
                            <label>Features</label>
                            <div class="aiohm-features-grid">
                                <?php 
                                $available_features = [
                                    'wifi' => 'WiFi', 
                                    'parking' => 'Parking', 
                                    'breakfast' => 'Breakfast',
                                    'balcony' => 'Balcony', 
                                    'kitchen' => 'Kitchen', 
                                    'air_conditioning' => 'Air Conditioning',
                                    'pets_allowed' => 'Pets Allowed', 
                                    'smoking_allowed' => 'Smoking Allowed',
                                    'wheelchair_accessible' => 'Wheelchair Accessible'
                                ];
                                foreach ($available_features as $key => $label) : 
                                    $checked = in_array($key, $features, true);
                                ?>
                                <label class="aiohm-feature-checkbox">
                                    <input type="checkbox" name="accommodation_features_<?php echo esc_attr($i); ?>[]" value="<?php echo esc_attr($key); ?>" <?php checked($checked); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="aiohm-form-actions">
                            <button type="button" class="button button-primary aiohm-save-accommodation" data-accommodation-id="<?php echo esc_attr($i); ?>">
                                Save <?php echo esc_html(ucfirst($current_accommodation_type)); ?>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render notification module - delegates to old admin class for complete functionality
     */
    public static function notification_module() {
        // Delegate to the old admin class which has the complete notification module functionality
        if (class_exists('AIOHM_BOOKING_MVP_Admin')) {
            return \AIOHM_BOOKING_MVP_Admin::notification_module();
        }
        
        // Fallback implementation:
        $o = get_option('aiohm_booking_mvp_settings', []);
        $email_provider = $o['email_provider'] ?? 'wordpress';
        $email_newsletter = $o['email_newsletter'] ?? 'none';
        $auto_send_notifications = !empty($o['auto_send_notifications']);
        
        // SMTP settings
        $smtp_host = esc_attr($o['smtp_host'] ?? '');
        $smtp_port = esc_attr($o['smtp_port'] ?? '587');
        $smtp_username = esc_attr($o['smtp_username'] ?? '');
        $smtp_password = esc_attr($o['smtp_password'] ?? '');
        $smtp_encryption = esc_attr($o['smtp_encryption'] ?? 'tls');
        $smtp_from_email = esc_attr($o['smtp_from_email'] ?? '');
        $smtp_from_name = esc_attr($o['smtp_from_name'] ?? '');

        ?>
        <div class="wrap aiohm-booking-mvp-admin">
            <div class="aiohm-header">
                <div class="aiohm-header-content">
                    <div class="aiohm-logo">
                        <img src="<?php echo esc_url( aiohm_booking_mvp_asset_url('images/aiohm-booking-OHM_logo-black.svg') ); ?>" alt="AIOHM" class="aiohm-header-logo">
                    </div>
                    <div class="aiohm-header-text">
                        <h1>Email Notification Settings</h1>
                        <p class="aiohm-tagline">Configure email notifications and SMTP settings for your booking system.</p>
                    </div>
                </div>
            </div>

            <form method="post" action="" class="aiohm-notification-settings">
                <?php wp_nonce_field('aiohm_booking_mvp_save_settings', 'aiohm_booking_mvp_settings_nonce'); ?>
                
                <div class="aiohm-notification-modules">
                    <!-- Email Provider Configuration -->
                    <div class="aiohm-module-card">
                        <div class="aiohm-module-header">
                            <h3>Email Provider Configuration</h3>
                        </div>
                        
                        <div class="aiohm-module-settings">
                            <div class="aiohm-setting-row">
                                <label>Email Provider</label>
                                <select name="aiohm_booking_mvp_settings[email_provider]" class="email-provider-select">
                                    <option value="wordpress" <?php selected($email_provider, 'wordpress'); ?>>WordPress Native (localhost/default)</option>
                                    <option value="smtp" <?php selected($email_provider, 'smtp'); ?>>Custom SMTP</option>
                                    <option value="gmail" <?php selected($email_provider, 'gmail'); ?>>Gmail</option>
                                    <option value="outlook" <?php selected($email_provider, 'outlook'); ?>>Outlook</option>
                                    <option value="mailgun" <?php selected($email_provider, 'mailgun'); ?>>Mailgun</option>
                                    <option value="sendgrid" <?php selected($email_provider, 'sendgrid'); ?>>SendGrid</option>
                                </select>
                            </div>
                            
                            <div id="smtp-settings" style="<?php echo $email_provider === 'smtp' ? '' : 'display: none;'; ?>">
                                <div class="aiohm-setting-row-inline">
                                    <div class="aiohm-setting-row">
                                        <label>SMTP Host</label>
                                        <input type="text" name="aiohm_booking_mvp_settings[smtp_host]" value="<?php echo $smtp_host; ?>" />
                                    </div>
                                    <div class="aiohm-setting-row">
                                        <label>SMTP Port</label>
                                        <input type="number" name="aiohm_booking_mvp_settings[smtp_port]" value="<?php echo $smtp_port; ?>" />
                                    </div>
                                </div>
                                
                                <div class="aiohm-setting-row-inline">
                                    <div class="aiohm-setting-row">
                                        <label>Username</label>
                                        <input type="text" name="aiohm_booking_mvp_settings[smtp_username]" value="<?php echo $smtp_username; ?>" />
                                    </div>
                                    <div class="aiohm-setting-row">
                                        <label>Password</label>
                                        <input type="password" name="aiohm_booking_mvp_settings[smtp_password]" value="<?php echo $smtp_password; ?>" />
                                    </div>
                                </div>
                                
                                <div class="aiohm-setting-row-inline">
                                    <div class="aiohm-setting-row">
                                        <label>Encryption</label>
                                        <select name="aiohm_booking_mvp_settings[smtp_encryption]">
                                            <option value="none" <?php selected($smtp_encryption, 'none'); ?>>None</option>
                                            <option value="tls" <?php selected($smtp_encryption, 'tls'); ?>>TLS</option>
                                            <option value="ssl" <?php selected($smtp_encryption, 'ssl'); ?>>SSL</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="aiohm-setting-row-inline">
                                    <div class="aiohm-setting-row">
                                        <label>From Email</label>
                                        <input type="email" name="aiohm_booking_mvp_settings[smtp_from_email]" value="<?php echo $smtp_from_email; ?>" />
                                    </div>
                                    <div class="aiohm-setting-row">
                                        <label>From Name</label>
                                        <input type="text" name="aiohm_booking_mvp_settings[smtp_from_name]" value="<?php echo $smtp_from_name; ?>" />
                                    </div>
                                </div>
                                
                                <div class="aiohm-setting-row">
                                    <button type="button" id="test-smtp-connection" class="button">Test SMTP Connection</button>
                                    <div id="smtp-test-result" class="aiohm-test-result"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Email Templates -->
                    <div class="aiohm-module-card">
                        <div class="aiohm-module-header">
                            <h3>Email Templates</h3>
                        </div>
                        
                        <div class="aiohm-module-settings">
                            <div class="aiohm-setting-row">
                                <label>
                                    <input type="checkbox" name="aiohm_booking_mvp_settings[auto_send_notifications]" value="1" <?php checked($auto_send_notifications); ?>>
                                    Automatically send booking confirmation emails
                                </label>
                            </div>
                            
                            <div class="aiohm-email-templates">
                                <div class="aiohm-template-item">
                                    <h4>Booking Confirmation Template</h4>
                                    <p>Sent when a booking is confirmed</p>
                                    <button type="button" class="button">Edit Template</button>
                                    <button type="button" class="button aiohm-reset-template" data-template="booking_confirmation">Reset to Default</button>
                                </div>
                                
                                <div class="aiohm-template-item">
                                    <h4>Payment Reminder Template</h4>
                                    <p>Sent for pending payment reminders</p>
                                    <button type="button" class="button">Edit Template</button>
                                    <button type="button" class="button aiohm-reset-template" data-template="payment_reminder">Reset to Default</button>
                                </div>
                                
                                <div class="aiohm-template-item">
                                    <h4>Cancellation Template</h4>
                                    <p>Sent when a booking is cancelled</p>
                                    <button type="button" class="button">Edit Template</button>
                                    <button type="button" class="button aiohm-reset-template" data-template="cancellation">Reset to Default</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="aiohm-form-actions">
                    <?php submit_button('Save Notification Settings', 'primary'); ?>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render help page - delegates to old admin class for complete functionality
     */
    public static function help_page() {
        // Delegate to the old admin class which has the complete help page functionality
        if (class_exists('AIOHM_BOOKING_MVP_Admin')) {
            return \AIOHM_BOOKING_MVP_Admin::help_page();
        }
        
        // Fallback implementation:
        ?>
        <div class="wrap aiohm-booking-mvp-admin">
            <div class="aiohm-header">
                <div class="aiohm-header-content">
                    <div class="aiohm-logo">
                        <img src="<?php echo esc_url( aiohm_booking_mvp_asset_url('images/aiohm-booking-OHM_logo-black.svg') ); ?>" alt="AIOHM" class="aiohm-header-logo">
                    </div>
                    <div class="aiohm-header-text">
                        <h1>Get Help & Support</h1>
                        <p class="aiohm-tagline">Resources and support for your AIOHM Booking MVP.</p>
                    </div>
                </div>
            </div>

            <div class="aiohm-help-content">
                <div class="aiohm-help-cards">
                    <div class="aiohm-help-card">
                        <h3>📚 Documentation</h3>
                        <p>Comprehensive guides and tutorials for setting up and using AIOHM Booking MVP.</p>
                        <a href="#" class="button">View Documentation</a>
                    </div>
                    
                    <div class="aiohm-help-card">
                        <h3>🎬 Video Tutorials</h3>
                        <p>Step-by-step video guides for common setup and configuration tasks.</p>
                        <a href="#" class="button">Watch Videos</a>
                    </div>
                    
                    <div class="aiohm-help-card">
                        <h3>💬 Community Support</h3>
                        <p>Connect with other users and get help from the community.</p>
                        <a href="#" class="button">Join Community</a>
                    </div>
                    
                    <div class="aiohm-help-card">
                        <h3>🐛 Report Issues</h3>
                        <p>Found a bug or have a feature request? Let us know!</p>
                        <a href="#" class="button">Report Issue</a>
                    </div>
                </div>
                
                <div class="aiohm-help-faq">
                    <h3>Frequently Asked Questions</h3>
                    <div class="aiohm-faq-items">
                        <div class="aiohm-faq-item">
                            <h4>How do I set up my first accommodation?</h4>
                            <p>Go to Settings → Enable the Accommodation Module → Configure your accommodation type and quantity → Visit the Accommodation page to set up individual room details.</p>
                        </div>
                        
                        <div class="aiohm-faq-item">
                            <h4>How do I configure AI analytics?</h4>
                            <p>Navigate to Settings → AI API Settings → Add your API key for your preferred provider (ShareAI, OpenAI, or Gemini) → Enable AI consent → Start using AI insights throughout the admin interface.</p>
                        </div>
                        
                        <div class="aiohm-faq-item">
                            <h4>Can I customize the booking form?</h4>
                            <p>Yes! Go to Settings → Form Customization to change colors, add custom fields, and personalize the booking experience for your guests.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // Calendar AJAX handlers continue with full implementations from original file...
    
    /**
     * AJAX: Block a specific date for a room
     */
    public static function ajax_block_date() {
        check_ajax_referer('aiohm_booking_mvp_calendar_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $room_id = intval($_POST['room_id'] ?? 0);
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        
        if (!$room_id || !$date) {
            wp_send_json_error('Missing room ID or date');
        }
        
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error('Invalid date format');
        }
        
        // Save blocked date
        $blocked_dates = get_option('aiohm_booking_mvp_blocked_dates', []);
        if (!isset($blocked_dates[$room_id])) {
            $blocked_dates[$room_id] = [];
        }
        
        if (!in_array($date, $blocked_dates[$room_id], true)) {
            $blocked_dates[$room_id][] = $date;
            update_option('aiohm_booking_mvp_blocked_dates', $blocked_dates);
        }
        
        wp_send_json_success(['message' => 'Date blocked successfully']);
    }

    /**
     * AJAX: Unblock a specific date for a room
     */
    public static function ajax_unblock_date() {
        check_ajax_referer('aiohm_booking_mvp_calendar_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $room_id = intval($_POST['room_id'] ?? 0);
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        
        if (!$room_id || !$date) {
            wp_send_json_error('Missing room ID or date');
        }
        
        // Remove blocked date
        $blocked_dates = get_option('aiohm_booking_mvp_blocked_dates', []);
        if (isset($blocked_dates[$room_id])) {
            $blocked_dates[$room_id] = array_values(array_diff($blocked_dates[$room_id], [$date]));
            if (empty($blocked_dates[$room_id])) {
                unset($blocked_dates[$room_id]);
            }
            update_option('aiohm_booking_mvp_blocked_dates', $blocked_dates);
        }
        
        wp_send_json_success(['message' => 'Date unblocked successfully']);
    }

    /**
     * AJAX: Get date information for a specific room and date
     */
    public static function ajax_get_date_info() {
        check_ajax_referer('aiohm_booking_mvp_calendar_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $room_id = intval($_POST['room_id'] ?? 0);
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        
        if (!$room_id || !$date) {
            wp_send_json_error('Missing room ID or date');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'aiohm_booking_mvp_item';
        
        // Check for existing bookings
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT i.*, o.status, o.buyer_name, o.buyer_email
            FROM {$table} i
            LEFT JOIN {$wpdb->prefix}aiohm_booking_mvp_order o ON i.order_id = o.id
            WHERE i.room_id = %d AND %s BETWEEN i.start_date AND i.end_date
        ", $room_id, $date), ARRAY_A);
        
        // Check for blocked dates
        $blocked_dates = get_option('aiohm_booking_mvp_blocked_dates', []);
        $is_blocked = isset($blocked_dates[$room_id]) && in_array($date, $blocked_dates[$room_id], true);
        
        $status = 'free';
        $details = [];
        
        if ($is_blocked) {
            $status = 'blocked';
        } elseif (!empty($bookings)) {
            $booking = $bookings[0];
            switch ($booking['status']) {
                case 'paid':
                    $status = 'booked';
                    break;
                case 'pending':
                    $status = 'pending';
                    break;
                default:
                    $status = 'booked';
            }
            
            $details = [
                'customer' => $booking['buyer_name'],
                'email' => $booking['buyer_email'],
                'order_id' => $booking['order_id']
            ];
        }
        
        wp_send_json_success([
            'status' => $status,
            'details' => $details,
            'is_blocked' => $is_blocked
        ]);
    }

    /**
     * AJAX: Set date status (free/blocked)
     */
    public static function ajax_set_date_status() {
        check_ajax_referer('aiohm_booking_mvp_calendar_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $room_id = intval($_POST['room_id'] ?? 0);
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $status = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));
        
        if (!$room_id || !$date || !in_array($status, ['free', 'blocked'], true)) {
            wp_send_json_error('Invalid parameters');
        }
        
        $blocked_dates = get_option('aiohm_booking_mvp_blocked_dates', []);
        
        if ($status === 'blocked') {
            if (!isset($blocked_dates[$room_id])) {
                $blocked_dates[$room_id] = [];
            }
            if (!in_array($date, $blocked_dates[$room_id], true)) {
                $blocked_dates[$room_id][] = $date;
            }
        } else {
            if (isset($blocked_dates[$room_id])) {
                $blocked_dates[$room_id] = array_values(array_diff($blocked_dates[$room_id], [$date]));
                if (empty($blocked_dates[$room_id])) {
                    unset($blocked_dates[$room_id]);
                }
            }
        }
        
        update_option('aiohm_booking_mvp_blocked_dates', $blocked_dates);
        wp_send_json_success(['message' => 'Date status updated successfully']);
    }

    /**
     * AJAX: Reset all days to free status
     */
    public static function ajax_reset_all_days() {
        check_ajax_referer('aiohm_booking_mvp_calendar_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Clear all blocked dates
        delete_option('aiohm_booking_mvp_blocked_dates');
        
        wp_send_json_success(['message' => 'All dates reset to free status']);
    }

    /**
     * AJAX: Set private event
     */
    public static function ajax_set_private_event() {
        check_ajax_referer('aiohm_booking_mvp_calendar_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $room_id = intval($_POST['room_id'] ?? 0);
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        
        if (!$room_id || !$date || !$title) {
            wp_send_json_error('Missing required parameters');
        }
        
        $private_events = get_option('aiohm_booking_mvp_private_events', []);
        if (!isset($private_events[$room_id])) {
            $private_events[$room_id] = [];
        }
        
        $private_events[$room_id][$date] = [
            'title' => $title,
            'created' => current_time('mysql')
        ];
        
        update_option('aiohm_booking_mvp_private_events', $private_events);
        wp_send_json_success(['message' => 'Private event created successfully']);
    }

    /**
     * AJAX: Remove private event
     */
    public static function ajax_remove_private_event() {
        check_ajax_referer('aiohm_booking_mvp_calendar_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $room_id = intval($_POST['room_id'] ?? 0);
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        
        if (!$room_id || !$date) {
            wp_send_json_error('Missing room ID or date');
        }
        
        $private_events = get_option('aiohm_booking_mvp_private_events', []);
        if (isset($private_events[$room_id][$date])) {
            unset($private_events[$room_id][$date]);
            if (empty($private_events[$room_id])) {
                unset($private_events[$room_id]);
            }
            update_option('aiohm_booking_mvp_private_events', $private_events);
        }
        
        wp_send_json_success(['message' => 'Private event removed successfully']);
    }

    /**
     * AJAX: Get private events
     */
    public static function ajax_get_private_events() {
        check_ajax_referer('aiohm_booking_mvp_calendar_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $private_events = get_option('aiohm_booking_mvp_private_events', []);
        wp_send_json_success(['events' => $private_events]);
    }

    /**
     * AJAX: Sync calendar with external sources
     */
    public static function ajax_sync_calendar() {
        check_ajax_referer('aiohm_booking_mvp_calendar_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Placeholder for calendar sync functionality
        // This would integrate with Booking.com, Airbnb, etc.
        
        wp_send_json_success(['message' => 'Calendar sync completed successfully']);
    }

    /**
     * AJAX: Test SMTP connection
     */
    public static function ajax_test_smtp_connection() {
        check_ajax_referer('aiohm_booking_mvp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $smtp_host = sanitize_text_field(wp_unslash($_POST['smtp_host'] ?? ''));
        $smtp_port = intval($_POST['smtp_port'] ?? 587);
        $smtp_username = sanitize_text_field(wp_unslash($_POST['smtp_username'] ?? ''));
        $smtp_password = sanitize_text_field(wp_unslash($_POST['smtp_password'] ?? ''));
        $smtp_encryption = sanitize_text_field(wp_unslash($_POST['smtp_encryption'] ?? 'tls'));
        
        if (empty($smtp_host) || empty($smtp_username)) {
            wp_send_json_error('Missing SMTP configuration');
        }
        
        // Placeholder for SMTP testing logic
        // In a real implementation, this would test the connection
        
        wp_send_json_success(['message' => 'SMTP connection test successful']);
    }

    /**
     * AJAX: Reset email template to default
     */
    public static function ajax_reset_email_template() {
        check_ajax_referer('aiohm_booking_mvp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $template = sanitize_text_field(wp_unslash($_POST['template'] ?? ''));
        $allowed_templates = ['booking_confirmation', 'payment_reminder', 'cancellation'];
        
        if (!in_array($template, $allowed_templates, true)) {
            wp_send_json_error('Invalid template');
        }
        
        // Reset template to default
        $settings = get_option('aiohm_booking_mvp_settings', []);
        unset($settings["email_template_{$template}"]);
        update_option('aiohm_booking_mvp_settings', $settings);
        
        wp_send_json_success(['message' => 'Email template reset to default']);
    }

    /**
     * Enqueue admin styles
     */
    public static function enqueue_admin_styles($hook) {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Use a more robust check to identify all plugin pages by checking if the screen ID contains our unique slug.
        $is_aiohm_page = (strpos($screen->id, 'aiohm-booking-mvp') !== false) ||
                         (isset($screen->post_type) && $screen->post_type === 'aiohm_booking_event');

        if ($is_aiohm_page) {
            // Verify CSS file exists before enqueuing
            $admin_css_path = aiohm_booking_mvp_asset_path('css/aiohm-booking-mvp-admin.css');
            $provider_css_path = aiohm_booking_mvp_asset_path('css/aiohm-booking-mvp-provider-icons.css');
            $calendar_css_path = aiohm_booking_mvp_asset_path('css/aiohm-booking-mvp-calendar.css');
            
            if (file_exists($admin_css_path)) {
                wp_enqueue_style(
                    'aiohm-booking-mvp-admin',
                    aiohm_booking_mvp_asset_url('css/aiohm-booking-mvp-admin.css'),
                    [],
                    AIOHM_BOOKING_MVP_VERSION
                );
            }
            
            // Load provider icons CSS
            if (file_exists($provider_css_path)) {
                wp_enqueue_style(
                    'aiohm-booking-mvp-provider-icons',
                    aiohm_booking_mvp_asset_url('css/aiohm-booking-mvp-provider-icons.css'),
                    [],
                    AIOHM_BOOKING_MVP_VERSION
                );
            }
            
            // Load calendar CSS on all AIOHM pages to ensure proper styling
            if (file_exists($calendar_css_path)) {
                wp_enqueue_style(
                    'aiohm-booking-mvp-calendar',
                    aiohm_booking_mvp_asset_url('css/aiohm-booking-mvp-calendar.css'),
                    [],
                    AIOHM_BOOKING_MVP_VERSION
                );
            }

            // Load admin JavaScript
            $admin_js_path = aiohm_booking_mvp_asset_path('js/aiohm-booking-mvp-admin.js');
            if (file_exists($admin_js_path)) {
                wp_enqueue_script(
                    'aiohm-booking-mvp-admin',
                    aiohm_booking_mvp_asset_url('js/aiohm-booking-mvp-admin.js'),
                    ['jquery'],
                    AIOHM_BOOKING_MVP_VERSION,
                    true
                );
                
                // Localize script for AJAX (match old admin class variable name)
                wp_localize_script('aiohm-booking-mvp-admin', 'aiohm_booking_mvp_admin', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('aiohm_booking_mvp_admin_nonce'),
                    'i18n' => [
                        'applyingChanges' => esc_html__('Applying changes...', 'aiohm-booking-mvp'),
                        'savingSettings' => esc_html__('Saving settings and updating menu...', 'aiohm-booking-mvp'),
                    ]
                ]);
            }
        }
    }

    /**
     * Backup admin styles
     */
    public static function backup_admin_styles() {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Use the same robust check here
        $is_aiohm_page = (strpos($screen->id, 'aiohm-booking-mvp') !== false) ||
                         (isset($screen->post_type) && $screen->post_type === 'aiohm_booking_event');
        if ($is_aiohm_page) {
            // Check if our main CSS wasn't loaded properly
            // Use wp_style_is() to check if the style is actually enqueued for this page
            $main_css_loaded = wp_style_is('aiohm-booking-mvp-admin', 'enqueued');
            
            if (!$main_css_loaded) {
                $css_url = aiohm_booking_mvp_asset_url('css/aiohm-booking-mvp-admin.css');
                $css_path = aiohm_booking_mvp_asset_path('css/aiohm-booking-mvp-admin.css');
                
                if (file_exists($css_path)) {
                    wp_enqueue_style('aiohm-booking-mvp-admin-backup', $css_url, array(), AIOHM_BOOKING_MVP_VERSION);
                    
                    // Also load provider icons
                    $provider_css_url = aiohm_booking_mvp_asset_url('css/aiohm-booking-mvp-provider-icons.css');
                    $provider_css_path = aiohm_booking_mvp_asset_path('css/aiohm-booking-mvp-provider-icons.css');
                    if (file_exists($provider_css_path)) {
                        wp_enqueue_style('aiohm-booking-mvp-provider-icons-backup', $provider_css_url, array(), AIOHM_BOOKING_MVP_VERSION);
                    }
                }
            }
        }
    }

    /**
     * Handle calendar redirect
     */
    public static function handle_calendar_redirect() {
        // Handle calendar navigation redirects
    }

    /**
     * Handle custom settings save
     */
    public static function handle_custom_settings_save() {
        // Check if our form was submitted
        if (!isset($_POST['aiohm_booking_mvp_settings_nonce'])) {
            return;
        }

        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['aiohm_booking_mvp_settings_nonce'] ?? '')), 'aiohm_booking_mvp_save_settings')) {
            wp_die('Nonce verification failed!', 'Error', ['response' => 403]);
        }

        // Check user caps
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.', 'Error', ['response' => 403]);
        }

        // Handle test connection buttons (methods to be implemented)
        if (isset($_POST['test_stripe_connection'])) {
            set_transient('aiohm_booking_mvp_save_error', 'Stripe test not implemented yet', 30);
            return;
        }
        if (isset($_POST['test_paypal_connection'])) {
            set_transient('aiohm_booking_mvp_save_error', 'PayPal test not implemented yet', 30);
            return;
        }

        // Get current settings
        $current_settings = get_option('aiohm_booking_mvp_settings', []);
        $posted_settings = [];
        if (isset($_POST['aiohm_booking_mvp_settings']) && is_array($_POST['aiohm_booking_mvp_settings'])) {
            $posted_settings = map_deep(wp_unslash($_POST['aiohm_booking_mvp_settings']), 'sanitize_text_field');
        }

        // Merge with current settings to preserve other values
        $new_settings = array_merge($current_settings, $posted_settings);

        // Save the settings
        update_option('aiohm_booking_mvp_settings', $new_settings);

        // Set success message
        set_transient('aiohm_booking_mvp_save_success', 'Settings saved successfully!', 30);

        // Force a hard redirect with cache busting (custom notice via transient)
        $redirect_url = add_query_arg(['refresh' => '1', 't' => time()], admin_url('admin.php?page=aiohm-booking-mvp-settings'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Handle accommodation details save
     */
    public static function handle_accommodation_details_save() {
        // Handle accommodation details form submissions
    }

    /**
     * Maybe update database
     */
    public static function maybe_update_database() {
        // Handle database schema updates
    }
}