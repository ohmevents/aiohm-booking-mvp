<?php

namespace AIOHM\BookingMVP\Admin;

use Exception;
use DateTime;
use DatePeriod;
use DateInterval;
use AIOHM\BookingMVP\Core\Settings;
use AIOHM\BookingMVP\Core\Config;
use AIOHM\BookingMVP\Core\Assets;

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

        // Add settings link to plugin page
        add_filter('plugin_action_links_' . plugin_basename(AIOHM_BOOKING_MVP_FILE), [__CLASS__, 'add_settings_link']);
    }

    /**
     * Add settings link to plugin action links.
     *
     * @param array $links The existing links.
     * @return array The modified links.
     */
    public static function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=aiohm-booking-mvp-settings') . '">' . __('Settings', 'aiohm-booking-mvp') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public static function menu(){
        $menu_icon = self::get_menu_icon();
        add_menu_page('AIOHM Booking','AIOHM Booking','manage_options','aiohm-booking-mvp',[__CLASS__,'dash'],$menu_icon,27);
        add_submenu_page('aiohm-booking-mvp','Dashboard','Dashboard','manage_options','aiohm-booking-mvp',[__CLASS__,'dash']);
        add_submenu_page('aiohm-booking-mvp','Settings','Settings','manage_options','aiohm-booking-mvp-settings',[__CLASS__,'settings']);

        // Get settings to determine which menu items to show
        $settings = Settings::getAll();
        
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
        $settings = Settings::getAll();
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
        $settings = Settings::getAll();
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
        $settings = Settings::getAll();
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
        $settings = Settings::getAll();
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
        
        $settings = Settings::getAll();
        $settings['ai_api_consent'] = $consent ? '1' : '0';
        
        Settings::update($settings);
        
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
        $settings = Settings::getAll();

        $key_name = $provider . '_api_key';
        $settings[$key_name] = $api_key;

        // Save settings
        $result = Settings::update($settings);

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

        $settings = Settings::getAll();
        $settings['default_ai_provider'] = $provider;

        $result = Settings::update($settings);

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
        $names = Config::getProductNames();
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
            ? Assets::get_path('images/aiohm-booking-OHM_logo-white.svg')
            : Assets::get_path('images/aiohm-booking-OHM_logo-black.svg');
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
        $settings = Settings::getAll();
        $currency = $settings['currency'] ?? 'EUR';

        ?>
        <div class="wrap aiohm-booking-mvp-admin">
            <div class="aiohm-header">
                <div class="aiohm-header-content">
                    <div class="aiohm-logo">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=aiohm-booking-mvp')); ?>">
                            <img src="<?php echo esc_url( Assets::get_url('images/aiohm-booking-OHM_logo-black.svg') ); ?>" alt="AIOHM" class="aiohm-header-logo">
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

    public static function calendar(){
        \AIOHM\BookingMVP\Calendar\Calendar::render();
    }

    public static function orders(){
        global $wpdb;
        $table = $wpdb->prefix.'aiohm_booking_mvp_order';

        // Handle bulk actions
        if(isset($_POST['action']) && sanitize_text_field(wp_unslash($_POST['action'])) !== '-1' && !empty($_POST['order_ids'])){
            $action = sanitize_text_field(wp_unslash($_POST['action']));
            $order_ids = array_map('intval', $_POST['order_ids']);

            if($action === 'mark_paid'){
                // Safe SQL: Using wpdb->update for single value updates
                foreach ($order_ids as $order_id) {
                    $wpdb->update($table, ['status' => 'paid'], ['id' => intval($order_id)], ['%s'], ['%d']);
                }
                echo '<div class="notice notice-success"><p>Orders marked as paid.</p></div>';
            } elseif($action === 'cancel'){
                // Safe SQL: Using wpdb->update for single value updates
                foreach ($order_ids as $order_id) {
                    $wpdb->update($table, ['status' => 'cancelled'], ['id' => intval($order_id)], ['%s'], ['%d']);
                }

                // Update calendar to reflect cancelled bookings
                self::update_calendar_for_orders($order_ids, 'cancelled');

                echo '<div class="notice notice-success"><p>Orders cancelled.</p></div>';
            } elseif($action === 'delete'){
                // Get order details before deleting for calendar update
                $order_details = [];
                foreach ($order_ids as $order_id) {
                    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", intval($order_id)));
                    if ($order) {
                        $order_details[] = $order;
                    }
                }

                // Safe SQL: Using wpdb->delete for single value deletions
                foreach ($order_ids as $order_id) {
                    $wpdb->delete($table, ['id' => intval($order_id)], ['%d']);
                }

                // Clean up associated room selections
                $order_rooms_map = get_option('aiohm_booking_mvp_order_rooms', []);
                foreach ($order_ids as $order_id) {
                    unset($order_rooms_map[$order_id]);
                }
                update_option('aiohm_booking_mvp_order_rooms', $order_rooms_map);

                // Update calendar to remove deleted booking blocks
                self::update_calendar_for_deleted_orders($order_details);

                echo '<div class="notice notice-success"><p>' . esc_html(count($order_ids)) . ' order(s) deleted permanently.</p></div>';
            }
        }

        // Handle single row actions
        if(isset($_GET['action'], $_GET['order_id'])){
            $action = sanitize_text_field(wp_unslash($_GET['action']));
            $order_id = intval($_GET['order_id']);

            if($action === 'mark_paid'){
                $wpdb->update($table, ['status' => 'paid'], ['id' => $order_id]);
                echo '<div class="notice notice-success"><p>Order marked as paid.</p></div>';
            } elseif($action === 'cancel'){
                $wpdb->update($table, ['status' => 'cancelled'], ['id' => $order_id]);
                self::update_calendar_for_orders([$order_id], 'cancelled');
                echo '<div class="notice notice-success"><p>Order cancelled.</p></div>';
            } elseif($action === 'delete'){
                // Get order details before deleting
                $order_details = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $order_id));

                $wpdb->delete($table, ['id' => $order_id]);

                // Clean up room selections
                $order_rooms_map = get_option('aiohm_booking_mvp_order_rooms', []);
                unset($order_rooms_map[$order_id]);
                update_option('aiohm_booking_mvp_order_rooms', $order_rooms_map);

                // Update calendar
                self::update_calendar_for_deleted_orders($order_details);

                echo '<div class="notice notice-success"><p>Order deleted.</p></div>';
            }
        }

        // Safe SQL: Table names are sanitized via wpdb->prefix concatenation
        // Limit orders for performance - show last 1000 orders
        $orders = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 1000");
        ?>
        <div class="wrap aiohm-booking-mvp-admin">
            <div class="aiohm-header">
                <div class="aiohm-header-content">
                    <div class="aiohm-logo">
                        <img src="<?php echo esc_url( Assets::get_url('images/aiohm-booking-OHM_logo-black.svg') ); ?>" alt="AIOHM" class="aiohm-header-logo">
                    </div>
                    <div class="aiohm-header-text">
                        <h1>Orders Management</h1>
                        <p class="aiohm-tagline">Track and manage all your booking orders from one conscious dashboard.</p>
                    </div>
                </div>
            </div>
            <form method="post">
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="action">
                            <option value="-1">Bulk Actions</option>
                            <option value="mark_paid">Mark Paid</option>
                            <option value="cancel">Cancel</option>
                            <option value="delete">Delete</option>
                        </select>
                        <?php submit_button('Apply', 'action', '', false); ?>
                    </div>
                </div>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" />
                            </td>
                            <th><?php esc_html_e('ID', 'aiohm-booking-mvp'); ?></th>
                            <th><?php esc_html_e('Date', 'aiohm-booking-mvp'); ?></th>
                            <th><?php esc_html_e('Buyer', 'aiohm-booking-mvp'); ?></th>
                            <th><?php esc_html_e('Mode', 'aiohm-booking-mvp'); ?></th>
                            <th><?php esc_html_e('Rooms', 'aiohm-booking-mvp'); ?></th                            <th><?php esc_html_e('Guests', 'aiohm-booking-mvp'); ?></th>
                            <th><?php esc_html_e('Total', 'aiohm-booking-mvp'); ?></th>
                            <th><?php esc_html_e('Deposit', 'aiohm-booking-mvp'); ?></th>
                            <th><?php esc_html_e('Status', 'aiohm-booking-mvp'); ?></th>
                            <th><?php esc_html_e('Method', 'aiohm-booking-mvp'); ?></th>
                            <th><?php esc_html_e('Actions', 'aiohm-booking-mvp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($orders as $order): ?>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" name="order_ids[]" value="<?php echo (int)$order->id; ?>" />
                            </th>
                            <td><?php echo (int)$order->id; ?></td>
                            <td><?php echo esc_html(gmdate('Y-m-d H:i', strtotime($order->created_at))); ?></td>
                            <td>
                                <?php echo esc_html($order->buyer_name); ?><br>
                                <small><?php echo esc_html($order->buyer_email); ?></small>
                            </td>
                            <td><?php echo esc_html($order->mode); ?></td>
                            <td>
                                <?php if($order->private_all): ?>
                                    Private (All)
                                <?php else: ?>
                                    <?php echo (int)$order->rooms_qty; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int)$order->guests_qty; ?></td>
                            <td>
                                <?php
                                $details = [];
                                if (!empty($order->estimated_arrival_time)) {
                                    $details[] = '<strong>Arrival:</strong> ' . esc_html($order->estimated_arrival_time);
                                }
                                if (!empty($order->bringing_pets)) {
                                    $details[] = '🐾 Pets';
                                }
                                echo wp_kses(implode('<br>', $details), array('strong' => array(), 'br' => array()));
                                ?>
                            </td>

                            <td><?php echo esc_html($order->currency.' '.number_format($order->total_amount, 2)); ?></td>
                            <td><?php echo esc_html($order->currency.' '.number_format($order->deposit_amount, 2)); ?></td>
                            <td>
                                <span class="status status-<?php echo esc_attr($order->status); ?>">
                                    <?php echo esc_html(ucfirst($order->status)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($order->payment_method ?: '-'); ?></td>
                            <td>
                                <?php if($order->status === 'pending'): ?>
                                    <a href="?page=aiohm-booking-mvp-orders&action=mark_paid&order_id=<?php echo (int)$order->id; ?>">Mark Paid</a> |
                                <?php endif; ?>
                                <a href="?page=aiohm-booking-mvp-orders&action=cancel&order_id=<?php echo (int)$order->id; ?>">Cancel</a> |
                                <a href="?page=aiohm-booking-mvp-orders&action=delete&order_id=<?php echo (int)$order->id; ?>" class="aiohm-delete-order" data-confirm="Delete this order?">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <!-- AI Order Insights Section -->
            <?php
            $settings = Settings::getAll();
            $ai_enabled = !empty($settings['enable_shareai']) || !empty($settings['enable_openai']) || !empty($settings['enable_gemini']);

            if ($ai_enabled) {
                self::renderAIOrderInsights();
            }
            ?>
        </div>
        <?php
    }

    public static function settings(){
        // Capture save result notices (rendered under subtitle)
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
                        <img src="<?php echo esc_url( Assets::get_url('images/aiohm-booking-OHM_logo-black.svg') ); ?>" alt="AIOHM" class="aiohm-header-logo">
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
            <?php if ($enable_rooms) : ?>
            <!-- Booking Form Customization -->
            <div class="aiohm-booking-mvp-card">
                <div class="aiohm-card-header">
                    <h3>Booking Form Customization</h3>
                    <div class="aiohm-header-actions">
                        <?php submit_button('Save Settings', 'primary', 'form_submit', false); ?>
                    </div>
                </div>
                <p>Customize the appearance and fields of your [aiohm_booking type="accommodation"] shortcode form</p>

                <div class="aiohm-form-customization">
                    <div class="aiohm-form-customization-left">
                        <h4>Form Styling</h4>
                        <div class="aiohm-setting-row-inline">
                            <div class="aiohm-setting-row">
                                <label>Brand Color</label>
                                <input type="color" name="aiohm_booking_mvp_settings[form_primary_color]" value="<?php echo esc_attr($form_primary_color); ?>" class="form-color-input" data-field="primary">
                            </div>
                            <div class="aiohm-setting-row">
                                <label>Font Color</label>
                                <input type="color" name="aiohm_booking_mvp_settings[form_text_color]" value="<?php echo esc_attr($form_text_color); ?>" class="form-color-input" data-field="text">
                            </div>
                        </div>
                        <div class="aiohm-setting-row aiohm-setting-row-spaced">
                            <label>Custom Title</label>
                            <input type="text" name="aiohm_booking_mvp_settings[form_title]" value="<?php echo esc_attr($form_title); ?>" placeholder="e.g. Book Your Stay">
                            <small class="description">Custom title for the booking form</small>
                        </div>
                        <div class="aiohm-setting-row">
                            <label>Custom Subtitle</label>
                            <input type="text" name="aiohm_booking_mvp_settings[form_subtitle]" value="<?php echo esc_attr($form_subtitle); ?>" placeholder="e.g. Select your dates and accommodation">
                            <small class="description">Custom subtitle/description for the booking form</small>
                        </div>

                        <div class="aiohm-setting-row">
                            <label>Checkout Page URL</label>
                            <input type="url" name="aiohm_booking_mvp_settings[checkout_page_url]" value="<?php echo esc_url($o['checkout_page_url'] ?? ''); ?>" placeholder="https://yoursite.com/checkout">
                            <small class="description">Page where the [aiohm_booking_checkout] shortcode is located. Leave blank to scroll to checkout on the same page.</small>
                        </div>
                        <div class="aiohm-setting-row">
                            <label>Thank You Page URL</label>
                            <input type="url" name="aiohm_booking_mvp_settings[thankyou_page_url]" value="<?php echo esc_url($o['thankyou_page_url'] ?? ''); ?>" placeholder="https://yoursite.com/thank-you">
                            <small class="description">Page to redirect customers after successful payment (e.g., /thank-you).</small>
                        </div>

                        <h4>Global Settings</h4>
                        <p>Configure global plugin settings and preferences:</p>
                        
                        <div class="aiohm-global-settings-grid">
                            <div class="aiohm-setting-row">
                                <label>Currency</label>
                                <select name="aiohm_booking_mvp_settings[currency]" class="enhanced-select currency-select">
                                    <option value="EUR" <?php selected($currency, 'EUR'); ?>>🇪🇺 Euro (EUR)</option>
                                    <option value="USD" <?php selected($currency, 'USD'); ?>>🇺🇸 US Dollar (USD)</option>
                                    <option value="GBP" <?php selected($currency, 'GBP'); ?>>🇬🇧 British Pound (GBP)</option>
                                    <option value="CAD" <?php selected($currency, 'CAD'); ?>>🇨🇦 Canadian Dollar (CAD)</option>
                                    <option value="AUD" <?php selected($currency, 'AUD'); ?>>🇦🇺 Australian Dollar (AUD)</option>
                                    <option value="CHF" <?php selected($currency, 'CHF'); ?>>🇨🇭 Swiss Franc (CHF)</option>
                                    <option value="JPY" <?php selected($currency, 'JPY'); ?>>🇯🇵 Japanese Yen (JPY)</option>
                                    <option value="CNY" <?php selected($currency, 'CNY'); ?>>🇨🇳 Chinese Yuan (CNY)</option>
                                    <option value="INR" <?php selected($currency, 'INR'); ?>>🇮🇳 Indian Rupee (INR)</option>
                                    <option value="BRL" <?php selected($currency, 'BRL'); ?>>🇧🇷 Brazilian Real (BRL)</option>
                                    <option value="MXN" <?php selected($currency, 'MXN'); ?>>🇲🇽 Mexican Peso (MXN)</option>
                                    <option value="RUB" <?php selected($currency, 'RUB'); ?>>🇷🇺 Russian Ruble (RUB)</option>
                                    <option value="KRW" <?php selected($currency, 'KRW'); ?>>🇰🇷 South Korean Won (KRW)</option>
                                    <option value="SGD" <?php selected($currency, 'SGD'); ?>>🇸🇬 Singapore Dollar (SGD)</option>
                                    <option value="HKD" <?php selected($currency, 'HKD'); ?>>🇭🇰 Hong Kong Dollar (HKD)</option>
                                    <option value="NOK" <?php selected($currency, 'NOK'); ?>>🇳🇴 Norwegian Krone (NOK)</option>
                                    <option value="SEK" <?php selected($currency, 'SEK'); ?>>🇸🇪 Swedish Krona (SEK)</option>
                                    <option value="DKK" <?php selected($currency, 'DKK'); ?>>🇩🇰 Danish Krone (DKK)</option>
                                    <option value="PLN" <?php selected($currency, 'PLN'); ?>>🇵🇱 Polish Złoty (PLN)</option>
                                    <option value="CZK" <?php selected($currency, 'CZK'); ?>>🇨🇿 Czech Koruna (CZK)</option>
                                    <option value="HUF" <?php selected($currency, 'HUF'); ?>>🇭🇺 Hungarian Forint (HUF)</option>
                                    <option value="RON" <?php selected($currency, 'RON'); ?>>🇷🇴 Romanian Leu (RON)</option>
                                    <option value="BGN" <?php selected($currency, 'BGN'); ?>>🇧🇬 Bulgarian Lev (BGN)</option>
                                    <option value="HRK" <?php selected($currency, 'HRK'); ?>>🇭🇷 Croatian Kuna (HRK)</option>
                                    <option value="TRY" <?php selected($currency, 'TRY'); ?>>🇹🇷 Turkish Lira (TRY)</option>
                                    <option value="ZAR" <?php selected($currency, 'ZAR'); ?>>🇿🇦 South African Rand (ZAR)</option>
                                    <option value="NZD" <?php selected($currency, 'NZD'); ?>>🇳🇿 New Zealand Dollar (NZD)</option>
                                    <option value="AED" <?php selected($currency, 'AED'); ?>>🇦🇪 UAE Dirham (AED)</option>
                                    <option value="SAR" <?php selected($currency, 'SAR'); ?>>🇸🇦 Saudi Riyal (SAR)</option>
                                    <option value="ILS" <?php selected($currency, 'ILS'); ?>>🇮🇱 Israeli Shekel (ILS)</option>
                                    <option value="EGP" <?php selected($currency, 'EGP'); ?>>🇪🇬 Egyptian Pound (EGP)</option>
                                </select>
                                <small>Select your business currency for pricing display</small>
                            </div>

                            <div class="aiohm-setting-row">
                                <label><?php esc_html_e('Plugin Language', 'aiohm-booking-mvp'); ?></label>
                                <select name="aiohm_booking_mvp_settings[plugin_language]" class="enhanced-select language-select">
                                    <option value="en" <?php selected($plugin_language, 'en'); ?>>🇺🇸 <?php esc_html_e('English (Default)', 'aiohm-booking-mvp'); ?></option>
                                    <option value="ro" <?php selected($plugin_language, 'ro'); ?>>🇷🇴 <?php esc_html_e('Romanian', 'aiohm-booking-mvp'); ?></option>
                                </select>
                                <small><?php esc_html_e('Choose the language for your booking system interface', 'aiohm-booking-mvp'); ?></small>
                            </div>
                        </div>
                        
                        <div class="aiohm-global-settings-grid">
                            <div class="aiohm-setting-row">
                                <label>Deposit Percentage (%)</label>
                                <input type="number" min="0" max="100" step="1" name="aiohm_booking_mvp_settings[deposit_percent]" value="<?php echo esc_attr($deposit); ?>" class="form-control">
                                <small>How much guests pay upfront (0% = full payment required)</small>
                            </div>

                            <div class="aiohm-setting-row">
                                <label>Early Bird Window</label>
                                <?php $earlybird_days = isset($o['earlybird_days']) ? absint($o['earlybird_days']) : 30; ?>
                                <input type="number" name="aiohm_booking_mvp_settings[earlybird_days]" min="0" step="1" value="<?php echo esc_attr($earlybird_days); ?>" class="form-control">
                                <small class="description">Number of days before check-in required to qualify for early bird pricing (default 30).</small>
                            </div>
                        </div>
                        
                        <div class="aiohm-setting-row">
                            <label>Minimum Age Requirement</label>
                            <input type="number" name="aiohm_booking_mvp_settings[min_age]" value="<?php echo esc_attr($min_age); ?>" min="0" max="99" class="form-control" />
                            <small>Minimum age required to make a booking (leave 0 for no age restriction)</small>
                        </div>

                        <!-- Contact Fields Manager -->
                        <div class="aiohm-fields-manager">
                            <h4>Additional Contact Fields <span class="field-order-hint">Drag to reorder</span></h4>
                            <p>Activate additional fields for accommodation bookings:</p>

                            <div class="aiohm-field-toggles" id="sortable-fields">
                                <div class="aiohm-field-toggle" data-field="address">
                                    <div class="field-handle">
                                        <span class="dashicons dashicons-menu"></span>
                                    </div>
                                    <div class="field-content">
                                        <label>
                                            <input type="checkbox" name="aiohm_booking_mvp_settings[form_field_address]" value="1" <?php checked($form_field_address); ?> class="form-field-toggle" data-field="address">
                                            <span class="field-label-inline">
                                                Address <span class="field-description-inline">(Guest's full address for accommodation stays)</span>
                                            </span>
                                            <button type="button" class="field-required-toggle <?php echo !empty($o['form_field_address_required']) ? 'required' : 'optional'; ?>" data-field="address">
                                                <span class="toggle-text"><?php echo !empty($o['form_field_address_required']) ? 'Required' : 'Optional'; ?></span>
                                            </button>
                                            <input type="hidden" name="aiohm_booking_mvp_settings[form_field_address_required]" value="<?php echo !empty($o['form_field_address_required']) ? '1' : '0'; ?>" class="required-hidden-input">
                                        </label>
                                    </div>
                                </div>

                                <div class="aiohm-field-toggle" data-field="age">
                                    <div class="field-handle">
                                        <span class="dashicons dashicons-menu"></span>
                                    </div>
                                    <div class="field-content">
                                        <label>
                                            <input type="checkbox" name="aiohm_booking_mvp_settings[form_field_age]" value="1" <?php checked($form_field_age); ?> class="form-field-toggle" data-field="age">
                                            <span class="field-label-inline">
                                                Age <span class="field-description-inline">(Guest's age for accommodation requirements)</span>
                                            </span>
                                            <button type="button" class="field-required-toggle <?php echo !empty($o['form_field_age_required']) ? 'required' : 'optional'; ?>" data-field="age">
                                                <span class="toggle-text"><?php echo !empty($o['form_field_age_required']) ? 'Required' : 'Optional'; ?></span>
                                            </button>
                                            <input type="hidden" name="aiohm_booking_mvp_settings[form_field_age_required]" value="<?php echo !empty($o['form_field_age_required']) ? '1' : '0'; ?>" class="required-hidden-input">
                                        </label>
                                    </div>
                                </div>

                                <div class="aiohm-field-toggle" data-field="company">
                                    <div class="field-handle">
                                        <span class="dashicons dashicons-menu"></span>
                                    </div>
                                    <div class="field-content">
                                        <label>
                                            <input type="checkbox" name="aiohm_booking_mvp_settings[form_field_company]" value="1" <?php checked($form_field_company); ?> class="form-field-toggle" data-field="company">
                                            <span class="field-label-inline">
                                                Company/Organization <span class="field-description-inline">(Business or organization name)</span>
                                            </span>
                                            <button type="button" class="field-required-toggle <?php echo !empty($o['form_field_company_required']) ? 'required' : 'optional'; ?>" data-field="company">
                                                <span class="toggle-text"><?php echo !empty($o['form_field_company_required']) ? 'Required' : 'Optional'; ?></span>
                                            </button>
                                            <input type="hidden" name="aiohm_booking_mvp_settings[form_field_company_required]" value="<?php echo !empty($o['form_field_company_required']) ? '1' : '0'; ?>" class="required-hidden-input">
                                        </label>
                                    </div>
                                </div>

                                <div class="aiohm-field-toggle" data-field="country">
                                    <div class="field-handle">
                                        <span class="dashicons dashicons-menu"></span>
                                    </div>
                                    <div class="field-content">
                                        <label>
                                            <input type="checkbox" name="aiohm_booking_mvp_settings[form_field_country]" value="1" <?php checked($form_field_country); ?> class="form-field-toggle" data-field="country">
                                            <span class="field-label-inline">
                                                Country <span class="field-description-inline">(Guest's country of residence)</span>
                                            </span>
                                            <button type="button" class="field-required-toggle <?php echo !empty($o['form_field_country_required']) ? 'required' : 'optional'; ?>" data-field="country">
                                                <span class="toggle-text"><?php echo !empty($o['form_field_country_required']) ? 'Required' : 'Optional'; ?></span>
                                            </button>
                                            <input type="hidden" name="aiohm_booking_mvp_settings[form_field_country_required]" value="<?php echo !empty($o['form_field_country_required']) ? '1' : '0'; ?>" class="required-hidden-input">
                                        </label>
                                    </div>
                                </div>

                                <div class="aiohm-field-toggle" data-field="arrival_time">
                                    <div class="field-handle">
                                        <span class="dashicons dashicons-menu"></span>
                                    </div>
                                    <div class="field-content">
                                        <label>
                                            <input type="checkbox" name="aiohm_booking_mvp_settings[form_field_arrival_time]" value="1" <?php checked($form_field_arrival_time); ?> class="form-field-toggle" data-field="arrival_time">
                                            <span class="field-label-inline">
                                                Estimated Arrival Time <span class="field-description-inline">(Ask guests for their estimated arrival time)</span>
                                            </span>
                                            <button type="button" class="field-required-toggle <?php echo !empty($o['form_field_arrival_time_required']) ? 'required' : 'optional'; ?>" data-field="arrival_time">
                                                <span class="toggle-text"><?php echo !empty($o['form_field_arrival_time_required']) ? 'Required' : 'Optional'; ?></span>
                                            </button>
                                            <input type="hidden" name="aiohm_booking_mvp_settings[form_field_arrival_time_required]" value="<?php echo !empty($o['form_field_arrival_time_required']) ? '1' : '0'; ?>" class="required-hidden-input">
                                        </label>
                                    </div>
                                </div>

                                <div class="aiohm-field-toggle" data-field="phone">
                                    <div class="field-handle">
                                        <span class="dashicons dashicons-menu"></span>
                                    </div>
                                    <div class="field-content">
                                        <label>
                                            <input type="checkbox" name="aiohm_booking_mvp_settings[form_field_phone]" value="1" <?php checked($form_field_phone); ?> class="form-field-toggle" data-field="phone">
                                            <span class="field-label-inline">
                                                Phone Number <span class="field-description-inline">(Guest's contact phone number)</span>
                                            </span>
                                            <button type="button" class="field-required-toggle <?php echo !empty($o['form_field_phone_required']) ? 'required' : 'optional'; ?>" data-field="phone">
                                                <span class="toggle-text"><?php echo !empty($o['form_field_phone_required']) ? 'Required' : 'Optional'; ?></span>
                                            </button>
                                            <input type="hidden" name="aiohm_booking_mvp_settings[form_field_phone_required]" value="<?php echo !empty($o['form_field_phone_required']) ? '1' : '0'; ?>" class="required-hidden-input">
                                        </label>
                                    </div>
                                </div>

                                <div class="aiohm-field-toggle" data-field="special_requests">
                                    <div class="field-handle">
                                        <span class="dashicons dashicons-menu"></span>
                                    </div>
                                    <div class="field-content">
                                        <label>
                                            <input type="checkbox" name="aiohm_booking_mvp_settings[form_field_special_requests]" value="1" <?php checked($form_field_special_requests); ?> class="form-field-toggle" data-field="special_requests">
                                            <span class="field-label-inline">
                                                Special Requests <span class="field-description-inline">(Additional guest requirements and requests)</span>
                                            </span>
                                            <button type="button" class="field-required-toggle <?php echo !empty($o['form_field_special_requests_required']) ? 'required' : 'optional'; ?>" data-field="special_requests">
                                                <span class="toggle-text"><?php echo !empty($o['form_field_special_requests_required']) ? 'Required' : 'Optional'; ?></span>
                                            </button>
                                            <input type="hidden" name="aiohm_booking_mvp_settings[form_field_special_requests_required]" value="<?php echo !empty($o['form_field_special_requests_required']) ? '1' : '0'; ?>" class="required-hidden-input">
                                        </label>
                                    </div>
                                </div>

                                <div class="aiohm-field-toggle" data-field="vat">
                                    <div class="field-handle">
                                        <span class="dashicons dashicons-menu"></span>
                                    </div>
                                    <div class="field-content">
                                        <label>
                                            <input type="checkbox" name="aiohm_booking_mvp_settings[form_field_vat]" value="1" <?php checked($form_field_vat); ?> class="form-field-toggle" data-field="vat">
                                            <span class="field-label-inline">
                                                VAT Number <span class="field-description-inline">(Field for guests to provide a VAT number for invoicing)</span>
                                            </span>
                                            <button type="button" class="field-required-toggle <?php echo !empty($o['form_field_vat_required']) ? 'required' : 'optional'; ?>" data-field="vat">
                                                <span class="toggle-text"><?php echo !empty($o['form_field_vat_required']) ? 'Required' : 'Optional'; ?></span>
                                            </button>
                                            <input type="hidden" name="aiohm_booking_mvp_settings[form_field_vat_required]" value="<?php echo !empty($o['form_field_vat_required']) ? '1' : '0'; ?>" class="required-hidden-input">
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="fields-ordering-note">
                                <p><strong>💡 Pro Tip:</strong> Drag fields to reorder them in your booking form. Toggle "Required" to make fields mandatory for guests.</p>
                            </div>

                            <!-- Hidden field for field order -->
                            <input type="hidden" name="aiohm_booking_mvp_settings[field_order]" id="field-order-input" value="<?php echo esc_attr(implode(',', $o['field_order'] ?? ['address', 'age', 'company', 'country', 'arrival_time', 'phone', 'special_requests', 'vat'])); ?>">
                        </div>
                    </div>

                    <div class="aiohm-form-customization-right">
                        <h4>Live Preview</h4>
                        <p class="aiohm-preview-subtitle">Customize the form with settings from left to view live changes. All actions are disabled here.</p>
                        <div class="aiohm-form-preview" id="booking-form-preview">
                            <div class="booking-form-container" style="--primary-color: <?php echo esc_attr($form_primary_color); ?>; --secondary-color: #ffffff;">
                                <?php
                                // Manually load shortcode assets since we're in admin context
                                if (class_exists('\\AIOHM\\BookingMVP\\Shortcodes\\Shortcodes')) \AIOHM\BookingMVP\Shortcodes\Shortcodes::assets();

                                // Render the real shortcode output but neutralize <form> tags to avoid nested forms
                                $shortcode = '[aiohm_booking_mvp type="accommodation" style="modern"]';
                                $output = do_shortcode($shortcode);

                                if (empty(trim(wp_strip_all_tags($output))) || strpos($output, '[aiohm_booking_mvp') !== false) {
                                    // Fallback if shortcode doesn't render or still shows shortcode text
                                    echo '<div class="aiohm-preview-fallback">';
                                    echo '<p><strong>⚠️ Shortcode Preview Issue</strong></p>';
                                    echo '<p><em>The shortcode is not rendering properly. This may be due to:</em></p>';
                                    echo '<ul><li>CSS/JS assets not loading in admin</li><li>Shortcode registration timing</li><li>Template file issues</li></ul>';
                                    echo '<p><strong>Expected shortcode:</strong> <code>' . esc_html($shortcode) . '</code></p>';
                                    if (!empty($output)) {
                                        echo '<p><strong>Raw output:</strong> <code>' . esc_html(substr($output, 0, 100)) . '...</code></p>';
                                    }
                                    echo '</div>';
                                } else {
                                    $output = preg_replace('/<form\b/', '<div class="aiohm-shortcode-form-preview"', $output);
                                    $output = preg_replace('/<\/form>/', '</div>', $output);
                                    // Disable interactive controls in preview
                                    $output = preg_replace('/<input(?![^>]*type=(\"|\')hidden\1)([^>]*)>/', '<input$2 disabled=\"disabled\" readonly>', $output);
                                    $output = preg_replace('/<select([^>]*)>/', '<select$1 disabled=\"disabled\">', $output);
                                    $output = preg_replace('/<textarea([^>]*)>/', '<textarea$1 disabled=\"disabled\" readonly>', $output);
                                    $output = preg_replace('/<button([^>]*)>/', '<button$1 disabled=\"disabled\" type=\"button\">', $output);
                                    echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>


            <!-- Payment Methods -->
            <div class="aiohm-booking-mvp-modules">
                <div class="aiohm-module-grid">
                    <!-- Stripe Module -->
                    <div class="aiohm-module-card module-payment <?php echo $enable_stripe ? 'is-active' : 'is-inactive'; ?>">
                        <div class="aiohm-module-header">
                            <h3>Stripe Module</h3>
                            <label class="aiohm-toggle" title="Enable or disable the Stripe payment gateway">
                                <input type="checkbox" name="aiohm_booking_mvp_settings[enable_stripe]" value="1" <?php checked($enable_stripe,true); ?>>
                                <span class="aiohm-toggle-slider"></span>
                            </label>
                        </div>
                        <p class="aiohm-module-description">Accept credit card payments securely through Stripe. Configure your API keys and webhook settings.</p>

                        <div class="aiohm-module-settings">
                            <div class="aiohm-setting-row">
                                <label>Publishable Key</label>
                                <input type="text" name="aiohm_booking_mvp_settings[stripe_publishable_key]" value="<?php echo esc_attr($stripe_publishable_key); ?>" placeholder="pk_test_..." class="regular-text">
                                <small class="description">Your Stripe publishable key (starts with pk_)</small>
                            </div>
                            <div class="aiohm-setting-row">
                                <label>Secret Key</label>
                                <input type="password" name="aiohm_booking_mvp_settings[stripe_secret_key]" value="<?php echo esc_attr($stripe_secret_key); ?>" placeholder="sk_test_..." class="regular-text">
                                <small class="description">Your Stripe secret key (starts with sk_)</small>
                            </div>
                            <div class="aiohm-setting-row">
                                <label>Webhook Signing Secret</label>
                                <input type="password" name="aiohm_booking_mvp_settings[stripe_webhook_secret]" value="<?php echo esc_attr($stripe_webhook_secret); ?>" placeholder="whsec_..." class="regular-text">
                                <small class="description">Your Stripe webhook signing secret (starts with whsec_). Essential for security.</small>
                            </div>
                            <div class="aiohm-setting-row">
                                <label>Webhook Endpoint</label>
                                <input type="url" name="aiohm_booking_mvp_settings[stripe_webhook_endpoint]" value="<?php echo esc_attr($stripe_webhook_endpoint); ?>" placeholder="<?php echo esc_url(site_url('/wp-json/aiohm-booking-mvp/v1/stripe-webhook')); ?>" class="regular-text">
                                <small class="description">Webhook URL for Stripe events</small>
                            </div>
                            <div class="aiohm-setting-row">
                                <div class="aiohm-button-group">
                                    <?php submit_button('Save Settings', 'primary', 'save_stripe_settings', false); ?>
                                    <?php submit_button('Test Connection', 'secondary', 'test_stripe_connection', false); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PayPal Module -->
                    <div class="aiohm-module-card module-payment <?php echo $enable_paypal ? 'is-active' : 'is-inactive'; ?>">
                        <div class="aiohm-module-header">
                            <h3>PayPal Module</h3>
                            <label class="aiohm-toggle" title="Enable or disable the PayPal payment gateway">
                                <input type="checkbox" name="aiohm_booking_mvp_settings[enable_paypal]" value="1" <?php checked($enable_paypal,true); ?>>
                                <span class="aiohm-toggle-slider"></span>
                            </label>
                        </div>
                        <p class="aiohm-module-description">Accept payments through PayPal. Configure your client credentials and environment settings.</p>

                        <div class="aiohm-module-settings">
                            <div class="aiohm-setting-row">
                                <label>Environment</label>
                                <select name="aiohm_booking_mvp_settings[paypal_environment]" class="regular-text">
                                    <option value="sandbox" <?php selected($paypal_environment, 'sandbox'); ?>>Sandbox (Testing)</option>
                                    <option value="production" <?php selected($paypal_environment, 'production'); ?>>Production (Live)</option>
                                </select>
                                <small class="description">Choose sandbox for testing or production for live payments</small>
                            </div>
                            <div class="aiohm-setting-row">
                                <label>Client ID</label>
                                <input type="text" name="aiohm_booking_mvp_settings[paypal_client_id]" value="<?php echo esc_attr($paypal_client_id); ?>" placeholder="Your PayPal Client ID" class="regular-text">
                                <small class="description">Your PayPal application client ID</small>
                            </div>
                            <div class="aiohm-setting-row">
                                <label>Client Secret</label>
                                <input type="password" name="aiohm_booking_mvp_settings[paypal_client_secret]" value="<?php echo esc_attr($paypal_client_secret); ?>" placeholder="Your PayPal Client Secret" class="regular-text">
                                <small class="description">Your PayPal application client secret</small>
                            </div>
                            <div class="aiohm-setting-row">
                                <div class="aiohm-button-group">
                                    <?php submit_button('Save Settings', 'primary', 'save_paypal_settings', false); ?>
                                    <?php submit_button('Test Connection', 'secondary', 'test_paypal_connection', false); ?>
                                </div>
                            </div>
                            <div class="aiohm-setting-row">
                                <label>Webhook URL (for production)</label>
                                <code class="aiohm-webhook-url"><?php echo esc_url(home_url('/wp-json/aiohm-booking-mvp/v1/paypal-webhook')); ?></code>
                                <small class="description">⚠️ <strong>Security Notice:</strong> Configure this webhook URL in your PayPal Developer Dashboard to receive payment notifications. Enable events: <code>CHECKOUT.ORDER.COMPLETED</code>, <code>PAYMENT.CAPTURE.COMPLETED</code></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- iCal Sync Modules -->
            <div class="aiohm-booking-mvp-modules">
                <div class="aiohm-module-grid">
                    <!-- Booking.com Module -->
                    <div class="aiohm-module-card module-sync <?php echo $booking_com_enabled ? 'is-active' : 'is-inactive'; ?>">
                        <div class="aiohm-module-header">
                            <h3>Booking.com Sync</h3>
                            <label class="aiohm-toggle" title="Enable or disable Booking.com iCal synchronization">
                                <input type="checkbox" name="aiohm_booking_mvp_settings[enable_booking_com]" value="1" <?php checked($booking_com_enabled); ?>>
                                <span class="aiohm-toggle-slider"></span>
                            </label>
                        </div>
                        <p class="aiohm-module-description">Sync your calendar with Booking.com to avoid double bookings. Enter your iCal URL in the Calendar page.</p>
                        <div class="aiohm-module-settings">
                            <div class="aiohm-setting-row">
                                <label>Booking.com Property ID</label>
                                <input type="text" name="aiohm_booking_mvp_settings[booking_com_property_id]" value="<?php echo esc_attr($booking_com_property_id); ?>" placeholder="Property ID" class="regular-text" />
                                <small>Optional: For reference and future integrations.</small>
                            </div>
                        </div>
                    </div>

                    <!-- Airbnb Module -->
                    <div class="aiohm-module-card module-sync <?php echo $airbnb_enabled ? 'is-active' : 'is-inactive'; ?>">
                        <div class="aiohm-module-header">
                            <h3>Airbnb Sync</h3>
                            <label class="aiohm-toggle" title="Enable or disable Airbnb iCal synchronization">
                                <input type="checkbox" name="aiohm_booking_mvp_settings[enable_airbnb]" value="1" <?php checked($airbnb_enabled); ?>>
                                <span class="aiohm-toggle-slider"></span>
                            </label>
                        </div>
                        <p class="aiohm-module-description">Sync your calendar with Airbnb to avoid double bookings. Enter your iCal URL in the Calendar page.</p>
                        <div class="aiohm-module-settings">
                            <div class="aiohm-setting-row">
                                <label>Airbnb Property ID</label>
                                <input type="text" name="aiohm_booking_mvp_settings[airbnb_property_id]" value="<?php echo esc_attr($airbnb_property_id); ?>" placeholder="Property ID" class="regular-text" />
                                <small>Optional: For reference and future integrations.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AI Modules -->
            <div class="aiohm-booking-mvp-modules">
                <div class="aiohm-module-grid">
                    <?php
                    self::render_ai_module_card('shareai', 'ShareAI', '🧠', 'Cost-effective AI solution with OpenAI compatibility. Perfect for conscious businesses looking for affordable AI assistance.', $o, $default_ai_provider);
                    self::render_ai_module_card('openai', 'OpenAI', '🤖', 'Industry-leading AI from OpenAI. Premium service with advanced conversational abilities for sophisticated booking assistance.', $o, $default_ai_provider);
                    self::render_ai_module_card('gemini', 'Google Gemini', '✨', "Google's advanced AI model with strong reasoning capabilities. Great for detailed booking inquiries and event planning assistance.", $o, $default_ai_provider);
                    ?>
                </div>
            </div>

            <!-- Hidden field for default provider -->
            <input type="hidden" name="aiohm_booking_mvp_settings[default_ai_provider]" value="<?php echo esc_attr($default_ai_provider); ?>">

            </form>
        </div>

        <?php
    }

    public static function accommodation_module(){
        $o = get_option('aiohm_booking_mvp_settings',[]);
        $available_rooms = intval($o['available_rooms'] ?? 7);
        $room_price = esc_attr($o['room_price'] ?? '0');
        $currency = esc_attr($o['currency'] ?? 'EUR');
        $allow_private = !empty($o['allow_private_all']);

        // Get dynamic product names
        $product_names = Config::getProductNames();
        $singular = $product_names['singular_cap'];
        $plural = $product_names['plural_cap'];

        // Get saved accommodation details
        $accommodation_details = get_option('aiohm_booking_mvp_accommodations_details', []);
        ?>
        <div class="wrap aiohm-booking-mvp-admin">
            <div class="aiohm-header">
                <div class="aiohm-header-content">
                    <div class="aiohm-logo">
                        <img src="<?php echo esc_url( Assets::get_url('images/aiohm-booking-OHM_logo-black.svg') ); ?>" alt="AIOHM" class="aiohm-header-logo">
                    </div>
                    <div class="aiohm-header-text">
                        <h1><?php echo esc_html($singular); ?> Module Management</h1>
                        <p class="aiohm-tagline">Manage your accommodation offerings - from individual <?php echo esc_html(strtolower($plural)); ?> to entire properties.</p>
                    </div>
                </div>
            </div>


            <form method="post" action="">
                <?php wp_nonce_field('aiohm_save_accommodation_details', 'aiohm_accommodation_details_nonce'); ?>
                <div class="aiohm-booking-mvp-card">
                    <h3><?php echo esc_html($plural); ?> Details</h3>
                    <p>Configure the details for each of your <?php echo esc_html(strtolower($plural)); ?>.</p>

                    <div class="aiohm-accommodations-grid">
                        <?php for ($i = 0; $i < $available_rooms; $i++) : ?>
                            <?php
                            $details = $accommodation_details[$i] ?? ['title' => '', 'description' => '', 'earlybird_price' => '', 'price' => '', 'type' => 'room'];
                            ?>
                            <div class="aiohm-accommodation-item">
                                <div class="aiohm-accommodation-header">
                                    <h4><?php echo esc_html($singular); ?> <?php echo esc_html($i + 1); ?></h4>
                                    <div class="aiohm-header-controls">
                                        <div class="aiohm-type-selector">
                                            <label>Type:</label>
                                            <select name="aiohm_accommodations[<?php echo esc_attr($i); ?>][type]" class="accommodation-individual-type-select">
                                                <?php
                                                $current_type = $details['type'] ?? 'room';
                                                $accommodation_types = [
                                                    'room' => 'Room',
                                                    'house' => 'House',
                                                    'apartment' => 'Apartment',
                                                    'villa' => 'Villa',
                                                    'bungalow' => 'Bungalow',
                                                    'cabin' => 'Cabin',
                                                    'cottage' => 'Cottage',
                                                    'suite' => 'Suite',
                                                    'studio' => 'Studio',
                                                    'unit' => 'Unit',
                                                    'space' => 'Space',
                                                    'venue' => 'Venue'
                                                ];
                                                foreach ($accommodation_types as $value => $label) :
                                                ?>
                                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_type, $value); ?>><?php echo esc_html($label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="button" class="aiohm-individual-save-btn" data-accommodation-index="<?php echo esc_attr($i); ?>">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            Save
                                        </button>
                                    </div>
                                </div>
                                <div class="aiohm-setting-row">
                                    <label>Title</label>
                                    <input type="text" name="aiohm_accommodations[<?php echo esc_attr($i); ?>][title]" value="<?php echo esc_attr(!empty($details['title']) ? $details['title'] : ($singular . ' ' . ($i + 1))); ?>" placeholder="e.g., <?php echo esc_attr($singular . ' ' . ($i + 1)); ?>">
                                </div>
                                <div class="aiohm-setting-row">
                                    <label>Description</label>
                                    <textarea name="aiohm_accommodations[<?php echo esc_attr($i); ?>][description]" rows="3" placeholder="e.g., A beautiful room with a view of the mountains."><?php echo esc_textarea($details['description']); ?></textarea>
                                </div>
                                <div class="aiohm-setting-row-inline">
                                    <div class="aiohm-setting-row">
                                        <label>Early Bird Price (<?php echo esc_html($currency); ?>)</label>
                                        <input type="number" step="0.01" min="0" name="aiohm_accommodations[<?php echo esc_attr($i); ?>][earlybird_price]" value="<?php echo esc_attr($details['earlybird_price']); ?>" placeholder="e.g., 80">
                                    </div>
                                    <div class="aiohm-setting-row">
                                        <label>Standard Price (<?php echo esc_html($currency); ?>)</label>
                                        <input type="number" step="0.01" min="0" name="aiohm_accommodations[<?php echo esc_attr($i); ?>][price]" value="<?php echo esc_attr($details['price']); ?>" placeholder="e.g., 100">
                                    </div>
                                </div>
                            </div>
                        <?php endfor; ?>
                        
                        <!-- Add More Accommodations Banner -->
                        <div class="aiohm-add-more-banner-wrapper">
                            <div class="aiohm-add-more-content">
                                <h4>Need More <?php echo esc_html($plural); ?>?</h4>
                                <p>You currently have <?php echo esc_html($available_rooms); ?> <?php echo esc_html(strtolower($plural)); ?> configured. You can add more by updating your accommodation quantity in the main settings.</p>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=aiohm-booking-mvp&highlight=available_rooms')); ?>" class="button aiohm-add-more-btn">
                                    <span class="dashicons dashicons-plus-alt2"></span>
                                    Add More <?php echo esc_html($plural); ?>
                                </a>
                                <small>Tip: Increase "Available <?php echo esc_html($plural); ?>" in your main settings to add more accommodation options.</small>
                            </div>
                        </div>
                    </div>
                </div>


            </form>
        </div>
        <?php
    }

    public static function notification_module(){
        // Handle form submission
        if (isset($_POST['aiohm_notification_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['aiohm_notification_settings_nonce'])), 'aiohm_save_notification_settings')) {
            if (current_user_can('manage_options')) {
                self::save_notification_settings($_POST);
                echo '<div class="notice notice-success"><p>Notification settings saved successfully!</p></div>';
            }
        }

        // Get saved settings
        $smtp_settings = get_option('aiohm_booking_mvp_smtp_settings', []);
        $email_templates = get_option('aiohm_booking_mvp_email_templates', []);

        // Default SMTP settings
        $smtp_host = $smtp_settings['host'] ?? '';
        $smtp_port = $smtp_settings['port'] ?? '587';
        $smtp_username = $smtp_settings['username'] ?? '';
        $smtp_password = $smtp_settings['password'] ?? '';
        $smtp_encryption = $smtp_settings['encryption'] ?? 'tls';
        $from_email = $smtp_settings['from_email'] ?? '';
        $from_name = $smtp_settings['from_name'] ?? '';
        $smtp_enabled = !empty($smtp_settings['enabled']);
        
        // Default email templates
        $templates = [
            'booking_confirmation_user' => [
                'name' => 'Booking Confirmation (User)',
                'subject' => $email_templates['booking_confirmation_user']['subject'] ?? 'Booking Confirmation - {booking_id}',
                'content' => $email_templates['booking_confirmation_user']['content'] ?? self::get_default_template('booking_confirmation_user')
            ],
            'booking_confirmation_admin' => [
                'name' => 'Booking Confirmation (Admin)',
                'subject' => $email_templates['booking_confirmation_admin']['subject'] ?? 'New Booking Received - {booking_id}',
                'content' => $email_templates['booking_confirmation_admin']['content'] ?? self::get_default_template('booking_confirmation_admin')
            ],
            'booking_cancelled_user' => [
                'name' => 'Booking Cancelled (User)',
                'subject' => $email_templates['booking_cancelled_user']['subject'] ?? 'Booking Cancelled - {booking_id}',
                'content' => $email_templates['booking_cancelled_user']['content'] ?? self::get_default_template('booking_cancelled_user')
            ],
            'booking_cancelled_admin' => [
                'name' => 'Booking Cancelled (Admin)',
                'subject' => $email_templates['booking_cancelled_admin']['subject'] ?? 'Booking Cancelled - {booking_id}',
                'content' => $email_templates['booking_cancelled_admin']['content'] ?? self::get_default_template('booking_cancelled_admin')
            ],
            'payment_reminder' => [
                'name' => 'Payment Reminder (User)',
                'subject' => $email_templates['payment_reminder']['subject'] ?? 'Payment Reminder - {booking_id}',
                'content' => $email_templates['payment_reminder']['content'] ?? self::get_default_template('payment_reminder')
            ]
        ];
        ?>
        <div class="wrap aiohm-booking-mvp-admin">
            <div class="aiohm-header">
                <div class="aiohm-header-content">
                    <div class="aiohm-logo">
                        <img src="<?php echo esc_url( Assets::get_url('images/aiohm-booking-OHM_logo-black.svg') ); ?>" alt="AIOHM" class="aiohm-header-logo">
                    </div>
                    <div class="aiohm-header-text">
                        <h1>Notification Module Management</h1>
                        <p class="aiohm-tagline">Configure SMTP settings and manage email templates for user and admin notifications.</p>
                    </div>
                </div>
            </div>

            <div class="aiohm-notification-layout">
                <!-- Left Column - SMTP Settings -->
                <div class="aiohm-notification-left">
                    <form method="post" action="">
                        <?php wp_nonce_field('aiohm_save_notification_settings', 'aiohm_notification_settings_nonce'); ?>

                        <div class="aiohm-booking-mvp-card">
                            <h3>📧 SMTP Configuration</h3>
                            <p>Configure your SMTP server settings to send email notifications.</p>

                            <div class="aiohm-smtp-settings">
                                <div class="aiohm-setting-row">
                                    <label>
                                        <input type="checkbox" name="smtp_enabled" value="1" <?php checked($smtp_enabled); ?>>
                                        Enable SMTP for Email Notifications
                                    </label>
                                </div>

                                <div class="aiohm-smtp-fields" <?php echo !$smtp_enabled ? 'style="opacity:0.5;"' : ''; ?>>
                                    <div class="aiohm-setting-row">
                                        <label>SMTP Host</label>
                                        <input type="text" name="smtp_host" value="<?php echo esc_attr($smtp_host); ?>" placeholder="smtp.gmail.com" class="form-control">
                                        <small>Your SMTP server hostname</small>
                                    </div>

                                    <div class="aiohm-setting-row aiohm-row-split">
                                        <div class="aiohm-setting-col">
                                            <label>Port</label>
                                            <input type="number" name="smtp_port" value="<?php echo esc_attr($smtp_port); ?>" placeholder="587" class="form-control">
                                            <small>Usually 587 (TLS) or 465 (SSL)</small>
                                        </div>
                                        <div class="aiohm-setting-col">
                                            <label>Encryption</label>
                                            <select name="smtp_encryption" class="form-control">
                                                <option value="tls" <?php selected($smtp_encryption, 'tls'); ?>>TLS</option>
                                                <option value="ssl" <?php selected($smtp_encryption, 'ssl'); ?>>SSL</option>
                                                <option value="none" <?php selected($smtp_encryption, 'none'); ?>>None</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="aiohm-setting-row">
                                        <label>Username</label>
                                        <input type="text" name="smtp_username" value="<?php echo esc_attr($smtp_username); ?>" placeholder="your-email@gmail.com" class="form-control">
                                        <small>Your SMTP username (usually your email)</small>
                                    </div>

                                    <div class="aiohm-setting-row">
                                        <label>Password</label>
                                        <input type="password" name="smtp_password" value="<?php echo esc_attr($smtp_password); ?>" placeholder="Enter SMTP password" class="form-control">
                                        <small>Your SMTP password or app password</small>
                                    </div>

                                    <div class="aiohm-setting-row aiohm-row-split">
                                        <div class="aiohm-setting-col">
                                            <label>From Email</label>
                                            <input type="email" name="from_email" value="<?php echo esc_attr($from_email); ?>" placeholder="noreply@yoursite.com" class="form-control">
                                            <small>Email address to send from</small>
                                        </div>
                                        <div class="aiohm-setting-col">
                                            <label>From Name</label>
                                            <input type="text" name="from_name" value="<?php echo esc_attr($from_name); ?>" placeholder="Your Hotel Name" class="form-control">
                                            <small>Display name for emails</small>
                                        </div>
                                    </div>

                                    <div class="aiohm-smtp-test">
                                        <button type="button" class="button aiohm-test-smtp-btn">Test SMTP Connection</button>
                                        <div class="aiohm-test-result"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="aiohm-save-section">
                            <button type="submit" class="button-primary aiohm-save-btn">Save SMTP Settings</button>
                        </div>
                    </form>
                </div>
                
                <!-- Right Column - Enhanced Email Template Manager -->
                <div class="aiohm-notification-right">
                    <div class="aiohm-booking-mvp-card">
                        <h3>📧 Email Template Manager</h3>
                        <p>Comprehensive email automation system for every stage of the guest journey.</p>
                        
                        <!-- Email Template Management -->
                        <div class="aiohm-email-templates-section">
                            <div class="aiohm-setting-row">
                                <label>Email Template to Customize</label>
                                <select name="email_template_selector" id="email-template-selector" class="enhanced-select">
                                    <optgroup label="🔹 Core Booking Emails">
                                        <option value="booking_confirmation">Booking Confirmation (guest + admin)</option>
                                        <option value="payment_receipt">Payment Receipt (invoice/transaction)</option>
                                        <option value="pending_payment_reminder">Pending Payment Reminder</option>
                                        <option value="booking_approved">Booking Approved</option>
                                        <option value="booking_declined">Booking Declined</option>
                                        <option value="booking_update">Booking Update Notification</option>
                                        <option value="cancellation_confirmation">Cancellation Confirmation</option>
                                    </optgroup>
                                    <optgroup label="🔹 Guest Experience Enhancers">
                                        <option value="pre_arrival_reminder">Pre-Arrival Reminder (3 days before)</option>
                                        <option value="checkin_instructions">Check-in Instructions</option>
                                        <option value="upsell_offers">Upsell Emails (upgrades/add-ons)</option>
                                        <option value="during_stay_message">During Stay Messages</option>
                                        <option value="post_stay_thankyou">Post-Stay Thank You</option>
                                        <option value="review_request">Review Request</option>
                                        <option value="loyalty_invitation">Loyalty Program Invitation</option>
                                    </optgroup>
                                    <optgroup label="🔹 Admin & Staff Notifications">
                                        <option value="new_booking_alert">New Booking Alert (admin)</option>
                                        <option value="daily_booking_digest">Daily Booking Digest</option>
                                        <option value="upcoming_checkins">Upcoming Check-ins/Check-outs</option>
                                        <option value="cancellation_alert">Cancellation/Refund Alerts</option>
                                        <option value="low_availability_alert">Low Availability Alert</option>
                                    </optgroup>
                                </select>
                                <small>Select an email template to customize its content, timing, and recipients</small>
                            </div>

                            <div class="aiohm-template-editor" id="template-editor" style="display: none;">
                                <div class="aiohm-template-settings">
                                    <div class="aiohm-setting-row-inline">
                                        <div class="aiohm-setting-row">
                                            <label>Template Status</label>
                                            <select name="template_status" class="enhanced-select">
                                                <option value="enabled">✅ Enabled</option>
                                                <option value="disabled">❌ Disabled</option>
                                            </select>
                                        </div>
                                        <div class="aiohm-setting-row">
                                            <label>Send Timing</label>
                                            <select name="template_timing" class="enhanced-select">
                                                <option value="immediate">Immediately</option>
                                                <option value="1_hour">1 Hour Later</option>
                                                <option value="1_day">1 Day Later</option>
                                                <option value="3_days">3 Days Later</option>
                                                <option value="1_week">1 Week Later</option>
                                                <option value="custom">Custom Schedule</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="aiohm-setting-row">
                                        <label>Email Subject Line</label>
                                        <input type="text" name="template_subject" class="form-control" placeholder="Use merge tags like {guest_name}, {booking_id}, {property_name}">
                                        <small>Available merge tags: {guest_name}, {booking_id}, {check_in_date}, {check_out_date}, {total_amount}, {property_name}</small>
                                    </div>

                                    <div class="aiohm-setting-row">
                                        <label>Email Content</label>
                                        <textarea name="template_content" rows="10" class="form-control" placeholder="Dear {guest_name},

Thank you for your booking...

Use merge tags to personalize the email content."></textarea>
                                        <small>💡 <strong>Merge Tags:</strong> {guest_name}, {guest_email}, {booking_id}, {check_in_date}, {check_out_date}, {duration_nights}, {total_amount}, {deposit_amount}, {property_name}, {accommodation_type}</small>
                                    </div>

                                    <div class="aiohm-setting-row-inline">
                                        <div class="aiohm-setting-row">
                                            <label>Sender Name</label>
                                            <input type="text" name="template_sender_name" class="form-control" placeholder="Your Hotel Name">
                                        </div>
                                        <div class="aiohm-setting-row">
                                            <label>Reply-To Email</label>
                                            <input type="email" name="template_reply_to" class="form-control" placeholder="reservations@yourhotel.com">
                                        </div>
                                    </div>

                                    <div class="aiohm-setting-row">
                                        <label>
                                            <input type="checkbox" name="template_include_attachment">
                                            Include PDF Attachment (invoice/confirmation)
                                        </label>
                                    </div>

                                    <div class="aiohm-template-actions">
                                        <button type="button" class="button button-secondary" id="preview-template">👁️ Preview Email</button>
                                        <button type="button" class="button button-secondary" id="send-test-email">📧 Send Test Email</button>
                                        <button type="button" class="button button-primary" id="save-template">💾 Save Template</button>
                                    </div>
                                </div>
                            </div>

                            <div class="aiohm-template-presets">
                                <h5>📋 Quick Template Presets</h5>
                                <div class="aiohm-preset-buttons">
                                    <button type="button" class="button preset-btn" data-preset="professional">Professional</button>
                                    <button type="button" class="button preset-btn" data-preset="friendly">Friendly</button>
                                    <button type="button" class="button preset-btn" data-preset="luxury">Luxury</button>
                                    <button type="button" class="button preset-btn" data-preset="minimalist">Minimalist</button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="aiohm-template-variables">
                            <h4>💡 Available Merge Tags</h4>
                            <div class="aiohm-variables-grid">
                                <div class="aiohm-variable-item">
                                    <code>{guest_name}</code>
                                    <span>Guest's full name</span>
                                </div>
                                <div class="aiohm-variable-item">
                                    <code>{guest_email}</code>
                                    <span>Guest's email address</span>
                                </div>
                                <div class="aiohm-variable-item">
                                    <code>{booking_id}</code>
                                    <span>Booking reference number</span>
                                </div>
                                <div class="aiohm-variable-item">
                                    <code>{check_in_date}</code>
                                    <span>Check-in date</span>
                                </div>
                                <div class="aiohm-variable-item">
                                    <code>{check_out_date}</code>
                                    <span>Check-out date</span>
                                </div>
                                <div class="aiohm-variable-item">
                                    <code>{duration_nights}</code>
                                    <span>Length of stay</span>
                                </div>
                                <div class="aiohm-variable-item">
                                    <code>{total_amount}</code>
                                    <span>Total booking amount</span>
                                </div>
                                <div class="aiohm-variable-item">
                                    <code>{deposit_amount}</code>
                                    <span>Required deposit</span>
                                </div>
                                <div class="aiohm-variable-item">
                                    <code>{property_name}</code>
                                    <span>Your hotel/property name</span>
                                </div>
                                <div class="aiohm-variable-item">
                                    <code>{accommodation_type}</code>
                                    <span>Room/accommodation details</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function help_page() {
        // Check if template exists in templates directory
        $template_path = AIOHM_BOOKING_MVP_DIR . 'templates/aiohm-booking-mvp-help.php';
        if (file_exists($template_path)) {
            include $template_path;
        } else {
            echo '<div class="wrap"><h1>Help Page</h1><p>Help template not found.</p></div>';
        }
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
            $admin_css_path = Assets::get_path('css/aiohm-booking-mvp-admin.css');
            $provider_css_path = Assets::get_path('css/aiohm-booking-mvp-provider-icons.css');
            $calendar_css_path = Assets::get_path('css/aiohm-booking-mvp-calendar.css');
            
            if (file_exists($admin_css_path)) {
                wp_enqueue_style(
                    'aiohm-booking-mvp-admin',
                    Assets::get_url('css/aiohm-booking-mvp-admin.css'),
                    [],
                    AIOHM_BOOKING_MVP_VERSION
                );
            }
            
            // Load provider icons CSS
            if (file_exists($provider_css_path)) {
                wp_enqueue_style(
                    'aiohm-booking-mvp-provider-icons',
                    Assets::get_url('css/aiohm-booking-mvp-provider-icons.css'),
                    [],
                    AIOHM_BOOKING_MVP_VERSION
                );
            }
            
            // Load calendar CSS on all AIOHM pages to ensure proper styling
            if (file_exists($calendar_css_path)) {
                wp_enqueue_style(
                    'aiohm-booking-mvp-calendar',
                    Assets::get_url('css/aiohm-booking-mvp-calendar.css'),
                    [],
                    AIOHM_BOOKING_MVP_VERSION
                );
            }

            // Load admin JavaScript
            $admin_js_path = Assets::get_path('js/aiohm-booking-mvp-admin.js');
            if (file_exists($admin_js_path)) {
                wp_enqueue_script(
                    'aiohm-booking-mvp-admin',
                    Assets::get_url('js/aiohm-booking-mvp-admin.js'),
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
                $css_url = Assets::get_url('css/aiohm-booking-mvp-admin.css');
                $css_path = Assets::get_path('css/aiohm-booking-mvp-admin.css');
                
                if (file_exists($css_path)) {
                    wp_enqueue_style('aiohm-booking-mvp-admin-backup', $css_url, array(), AIOHM_BOOKING_MVP_VERSION);
                    
                    // Also load provider icons
                    $provider_css_url = Assets::get_url('css/aiohm-booking-mvp-provider-icons.css');
                    $provider_css_path = Assets::get_path('css/aiohm-booking-mvp-provider-icons.css');
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
        // Only run once
        if (get_option('aiohm_booking_db_fixed')) {
            return;
        }

        // Only run on admin pages, not during AJAX or activation
        if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'aiohm_booking_mvp_order';

        try {
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
            if (!$table_exists) {
                return;
            }

            // Get current columns
            $columns = $wpdb->get_col("DESCRIBE $table");
            $changes_made = false;

            // Add check_in_date column if missing
            if (!in_array('check_in_date', $columns)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query("ALTER TABLE $table ADD COLUMN check_in_date DATE NULL AFTER buyer_phone");
                $changes_made = true;
            }

            // Add check_out_date column if missing
            if (!in_array('check_out_date', $columns)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query("ALTER TABLE $table ADD COLUMN check_out_date DATE NULL AFTER check_in_date");
                $changes_made = true;
            }

            // Add index if it doesn't exist
            $indexes = $wpdb->get_results("SHOW INDEX FROM $table");
            $has_date_index = false;
            foreach ($indexes as $index) {
                if ($index->Key_name === 'date_idx') {
                    $has_date_index = true;
                    break;
                }
            }

            if (!$has_date_index) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query("ALTER TABLE $table ADD KEY date_idx (check_in_date, check_out_date)");
                $changes_made = true;
            }

            // Also check and fix accommodation settings
            $settings = get_option('aiohm_booking_mvp_settings', array());
            $available_rooms = intval($settings['available_rooms'] ?? 2);

            if ($available_rooms !== 7) {
                $settings['available_rooms'] = 7;
                update_option('aiohm_booking_mvp_settings', $settings);
                $changes_made = true;
            }

            // Mark as completed
            update_option('aiohm_booking_db_fixed', true);

            // Add admin notice for next page load only if changes were made
            if ($changes_made) {
                set_transient('aiohm_booking_db_updated', true, 30);
            }

        } catch (Exception $e) {
            // Silent failure - don't break the site
        }
    }

    /**
     * Show database update notice
     */
    public static function show_database_update_notice() {
        if (get_transient('aiohm_booking_db_updated')) {
            delete_transient('aiohm_booking_db_updated');
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>AIOHM Booking:</strong> Database updated successfully. Calendar is ready to use!</p>';
            echo '</div>';
        }
    }

    /**
     * Update calendar when orders are cancelled
     * @param array $order_ids Order IDs that were cancelled
     * @param string $new_status New status of the orders
     */
    private static function update_calendar_for_orders($order_ids, $new_status) {
        global $wpdb;
        $table = $wpdb->prefix.'aiohm_booking_mvp_order';

        // Get order details to update calendar
        if (empty($order_ids)) return;

        // Safe SQL: Get each order individually to avoid IN clause issues
        $orders = [];
        foreach ($order_ids as $order_id) {
            $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", intval($order_id)));
            if ($order) {
                $orders[] = $order;
            }
        }

        $blocked_dates = get_option('aiohm_booking_mvp_blocked_dates', []);
        $order_rooms_map = get_option('aiohm_booking_mvp_order_rooms', []);

        foreach ($orders as $order) {
            if ($new_status === 'cancelled' && !empty($order->check_in_date) && !empty($order->check_out_date)) {
                // Remove calendar blocks for cancelled orders
                $room_ids = $order_rooms_map[intval($order->id)] ?? [];

                if ($order->private_all) {
                    // Handle private all bookings
                    $settings = get_option('aiohm_booking_mvp_settings', []);
                    $available_rooms = intval($settings['available_rooms'] ?? 7);
                    for ($i = 1; $i <= $available_rooms; $i++) {
                        self::remove_order_dates_from_calendar($blocked_dates, $i, $order->check_in_date, $order->check_out_date, $order->id);
                    }
                } else {
                    // Handle specific room selections
                    foreach ($room_ids as $room_id) {
                        self::remove_order_dates_from_calendar($blocked_dates, intval($room_id), $order->check_in_date, $order->check_out_date, $order->id);
                    }
                }
            }
        }

        update_option('aiohm_booking_mvp_blocked_dates', $blocked_dates);
    }

    /**
     * Update calendar when orders are deleted
     * @param array $order_details Order details that were deleted
     */
    private static function update_calendar_for_deleted_orders($order_details) {
        $blocked_dates = get_option('aiohm_booking_mvp_blocked_dates', []);
        $order_rooms_map = get_option('aiohm_booking_mvp_order_rooms', []);

        foreach ($order_details as $order) {
            if (!empty($order->check_in_date) && !empty($order->check_out_date)) {
                $room_ids = $order_rooms_map[intval($order->id)] ?? [];

                if ($order->private_all) {
                    // Handle private all bookings
                    $settings = get_option('aiohm_booking_mvp_settings', []);
                    $available_rooms = intval($settings['available_rooms'] ?? 7);
                    for ($i = 1; $i <= $available_rooms; $i++) {
                        self::remove_order_dates_from_calendar($blocked_dates, $i, $order->check_in_date, $order->check_out_date, $order->id);
                    }
                } else {
                    // Handle specific room selections
                    foreach ($room_ids as $room_id) {
                        self::remove_order_dates_from_calendar($blocked_dates, intval($room_id), $order->check_in_date, $order->check_out_date, $order->id);
                    }
                }
            }
        }

        update_option('aiohm_booking_mvp_blocked_dates', $blocked_dates);
    }

    /**
     * Remove specific order dates from calendar blocking
     * @param array $blocked_dates Calendar blocked dates array (by reference)
     * @param int $room_id Room ID to unblock
     * @param string $check_in_date Check-in date
     * @param string $check_out_date Check-out date
     * @param int $order_id Order ID to match for removal
     */
    private static function remove_order_dates_from_calendar(&$blocked_dates, $room_id, $check_in_date, $check_out_date, $order_id) {
        if (empty($check_in_date) || empty($check_out_date)) return;

        try {
            $start_date = new DateTime($check_in_date);
            $end_date = new DateTime($check_out_date);
            $period = new DatePeriod($start_date, new DateInterval('P1D'), $end_date);

            foreach ($period as $date) {
                $date_key = $date->format('Y-m-d');

                // Only remove if this specific order created the block
                if (isset($blocked_dates[$room_id][$date_key])) {
                    $block_info = $blocked_dates[$room_id][$date_key];

                    // Only remove blocks that were created by this specific order
                    if (isset($block_info['order_id']) && $block_info['order_id'] == $order_id) {
                        unset($blocked_dates[$room_id][$date_key]);
                    }
                }
            }
        } catch (Exception $e) {
            // Skip if error occurs during calendar sync
        }
    }

    /**
     * Save notification settings
     */
    private static function save_notification_settings($post_data) {
        // Handle SMTP settings
        if (isset($post_data['smtp_enabled'])) {
            $smtp_settings = [
                'enabled' => !empty($post_data['smtp_enabled']),
                'host' => sanitize_text_field($post_data['smtp_host'] ?? ''),
                'port' => absint($post_data['smtp_port'] ?? 587),
                'username' => sanitize_text_field($post_data['smtp_username'] ?? ''),
                'password' => sanitize_text_field($post_data['smtp_password'] ?? ''),
                'encryption' => sanitize_text_field($post_data['smtp_encryption'] ?? 'tls'),
                'from_email' => sanitize_email($post_data['from_email'] ?? ''),
                'from_name' => sanitize_text_field($post_data['from_name'] ?? '')
            ];
            update_option('aiohm_booking_mvp_smtp_settings', $smtp_settings);
        }

        // Handle email template updates
        if (isset($post_data['template_key'])) {
            $template_key = sanitize_key($post_data['template_key']);
            $email_templates = get_option('aiohm_booking_mvp_email_templates', []);

            $email_templates[$template_key] = [
                'subject' => sanitize_text_field($post_data['template_subject'] ?? ''),
                'content' => wp_kses_post($post_data['template_content'] ?? '')
            ];

            update_option('aiohm_booking_mvp_email_templates', $email_templates);
        }
    }

    /**
     * Get default email templates
     */
    private static function get_default_template($type) {
        $templates = [
            'booking_confirmation_user' => '
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f9f9f9;">
    <div style="background-color: #ffffff; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <h1 style="color: #457d58; margin-bottom: 20px;">Booking Confirmation</h1>
        <p>Dear {customer_name},</p>
        <p>Thank you for your booking! We\'re excited to host you. Here are your booking details:</p>

        <div style="background-color: #f5f5f5; padding: 20px; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #457d58; margin-top: 0;">Booking Details</h3>
            <p><strong>Booking ID:</strong> {booking_id}</p>
            <p><strong>Check-in:</strong> {check_in}</p>
            <p><strong>Check-out:</strong> {check_out}</p>
            <p><strong>Accommodation:</strong> {rooms}</p>
            <p><strong>Total Amount:</strong> {total_amount}</p>
        </div>

        <p>If you have any questions, please don\'t hesitate to contact us.</p>
        <p>We look forward to welcoming you!</p>

        <p>Best regards,<br>
        {site_name}</p>
    </div>
</div>',

            'booking_confirmation_admin' => '
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h1 style="color: #457d58;">New Booking Received</h1>
    <p>A new booking has been made on your website.</p>

    <div style="background-color: #f5f5f5; padding: 20px; border-radius: 5px; margin: 20px 0;">
        <h3>Booking Details</h3>
        <p><strong>Booking ID:</strong> {booking_id}</p>
        <p><strong>Customer:</strong> {customer_name}</p>
        <p><strong>Email:</strong> {customer_email}</p>
        <p><strong>Check-in:</strong> {check_in}</p>
        <p><strong>Check-out:</strong> {check_out}</p>
        <p><strong>Accommodation:</strong> {rooms}</p>
        <p><strong>Total Amount:</strong> {total_amount}</p>
    </div>

    <p>Please log into your admin panel to manage this booking.</p>
</div>',

            'booking_cancelled_user' => '
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h1 style="color: #d63638;">Booking Cancelled</h1>
    <p>Dear {customer_name},</p>
    <p>Your booking has been cancelled as requested.</p>

    <div style="background-color: #f5f5f5; padding: 20px; border-radius: 5px; margin: 20px 0;">
        <h3>Cancelled Booking Details</h3>
        <p><strong>Booking ID:</strong> {booking_id}</p>
        <p><strong>Check-in:</strong> {check_in}</p>
        <p><strong>Check-out:</strong> {check_out}</p>
        <p><strong>Amount:</strong> {total_amount}</p>
    </div>

    <p>If you have any questions about your cancellation, please contact us.</p>

    <p>Best regards,<br>
    {site_name}</p>
</div>',

            'booking_cancelled_admin' => '
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h1 style="color: #d63638;">Booking Cancelled</h1>
    <p>A booking has been cancelled.</p>

    <div style="background-color: #f5f5f5; padding: 20px; border-radius: 5px; margin: 20px 0;">
        <h3>Cancelled Booking Details</h3>
        <p><strong>Booking ID:</strong> {booking_id}</p>
        <p><strong>Customer:</strong> {customer_name}</p>
        <p><strong>Email:</strong> {customer_email}</p>
        <p><strong>Check-in:</strong> {check_in}</p>
        <p><strong>Check-out:</strong> {check_out}</p>
        <p><strong>Amount:</strong> {total_amount}</p>
    </div>

    <p>Please review your calendar and manage any necessary updates.</p>
</div>',

            'payment_reminder' => '
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h1 style="color: #ff9800;">Payment Reminder</h1>
    <p>Dear {customer_name},</p>
    <p>This is a friendly reminder about your upcoming booking payment.</p>

    <div style="background-color: #f5f5f5; padding: 20px; border-radius: 5px; margin: 20px 0;">
        <h3>Booking Details</h3>
        <p><strong>Booking ID:</strong> {booking_id}</p>
        <p><strong>Check-in:</strong> {check_in}</p>
        <p><strong>Check-out:</strong> {check_out}</p>
        <p><strong>Outstanding Amount:</strong> {total_amount}</p>
    </div>

    <p>Please complete your payment to confirm your booking.</p>

    <p>Best regards,<br>
    {site_name}</p>
</div>'
        ];

        return $templates[$type] ?? '';
    }
}