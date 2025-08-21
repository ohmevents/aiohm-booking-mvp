<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * AIOHM Booking MVP REST API Handler
 * 
 * Note: This file uses direct database queries to custom plugin tables.
 * WordPress.org compliance: Direct database access is legitimate for plugin-specific custom tables.
 * Caching is not applicable for booking operations requiring real-time data consistency.
 * 
 * Handles all REST API endpoints for booking operations including holds,
 * payment processing, and availability checks with proper security.
 * 
 * @package AIOHM_Booking_MVP
 * @since   1.0.0
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// Reason: This class manages custom booking tables that require direct database access for real-time booking operations
class AIOHM_BOOKING_MVP_API {
    
    /**
     * Initialize the API by registering REST routes
     * 
     * @since 1.0.0
     * @return void
     */
    public static function init(){
        add_action('rest_api_init',[__CLASS__,'routes']);
    }
    
    /**
     * Register all REST API routes with proper security callbacks
     * 
     * @since 1.0.0
     * @return void
     */
    public static function routes(){
        register_rest_route('aiohm-booking-mvp/v1','/hold',[
            'methods'=>'POST',
            'callback'=>[__CLASS__,'hold'],
            'permission_callback'=>[__CLASS__, 'verify_public_nonce']
        ]);
        register_rest_route('aiohm-booking-mvp/v1','/stripe/session',[
            'methods'=>'POST',
            'callback'=>[__CLASS__,'stripe_session'],
            'permission_callback'=>[__CLASS__, 'verify_public_nonce']
        ]);
        register_rest_route('aiohm-booking-mvp/v1','/stripe-webhook',[
            'methods'=>'POST',
            'callback'=>[__CLASS__,'stripe_webhook'],
            'permission_callback'=>[__CLASS__, 'verify_webhook']
        ]);
        register_rest_route('aiohm-booking-mvp/v1','/paypal/capture',[
            'methods'=>'POST',
            'callback'=>[__CLASS__,'paypal_capture'],
            'permission_callback'=>[__CLASS__, 'verify_public_nonce']
        ]);
        // Availability for frontend calendar
        register_rest_route('aiohm-booking-mvp/v1','/availability',[
            'methods'=>'GET',
            'callback'=>[__CLASS__,'availability'],
            'permission_callback'=>'__return_true' // Public read-only endpoint
        ]);
        // Admin: toggle block/unblock dates on calendar
        register_rest_route('aiohm-booking-mvp/v1','/calendar/block',[
            'methods'=>'POST',
            'callback'=>[__CLASS__,'toggle_block_date'],
            'permission_callback'=>function(){ return current_user_can('manage_options'); }
        ]);
    }
    
    /**
     * Create a booking hold with validation and pricing calculation
     * 
     * Validates user input, calculates totals and deposits, creates order record
     * and triggers calendar sync hooks.
     * 
     * @since 1.0.0
     * @param WP_REST_Request $r REST request object containing booking data
     * @return WP_REST_Response|WP_Error Order details on success, error on failure
     */
    public static function hold( WP_REST_Request $r ){
        try {
            global $wpdb;
            $p = $r->get_json_params();
            $opts = aiohm_booking_mvp_prices();

        // Validate required fields
        $name = sanitize_text_field($p['name'] ?? '');
        $email = sanitize_email($p['email'] ?? '');
        if(empty($name) || empty($email)){
            return new WP_Error('missing_fields', 'Name and email are required', ['status' => 400]);
        }

        $mode = 'rooms'; // Always rooms only now
        // Rooms
        $rooms_qty = max(0, intval($p['rooms_qty'] ?? 0));
        
        $room_ids = [];
        if (!empty($p['room_ids']) && is_array($p['room_ids'])) {
            foreach ($p['room_ids'] as $rid) {
                $rid = intval($rid);
                if ($rid > 0) { $room_ids[] = $rid; }
            }
        }
        $private_all = !empty($p['private_all']) ? 1 : 0;
        $guests_qty = max(1, intval($p['guests_qty'] ?? 1));
        $vat_number = sanitize_text_field($p['vat_number'] ?? '');
        $purpose_of_stay = sanitize_text_field($p['purpose_of_stay'] ?? '');
        $estimated_arrival_time = sanitize_text_field($p['estimated_arrival_time'] ?? '');
        $bringing_pets = !empty($p['bringing_pets']) ? 1 : 0;
        $pet_details = $bringing_pets ? sanitize_textarea_field($p['pet_details'] ?? '') : '';

        // Validate minimum age requirement (only if age field is enabled and minimum age is set)
        $age = intval($p['age'] ?? 0);
        $settings = aiohm_booking_mvp_opts();
        $age_field_enabled = !empty($settings['form_field_age']);
        $min_age = intval($settings['min_age'] ?? 0);
        
        if ($age_field_enabled && $min_age > 0 && $age < $min_age) {
            return new WP_Error('age_requirement', "Minimum age requirement is {$min_age} years", ['status' => 400]);
        }

        // Validate mode settings
        $rooms_enabled = aiohm_booking_mvp_enabled_rooms();
        if (!$rooms_enabled) {
            return new WP_Error('rooms_disabled', 'Accommodation booking is not enabled', ['status' => 400]);
        }

        // Validate quantities
        if ($rooms_qty <= 0 && !$private_all) {
            return new WP_Error('invalid_rooms', 'At least one accommodation must be selected', ['status' => 400]);
        }

        // Calculate totals - rooms only now
        $check_in_date  = sanitize_text_field($p['check_in_date'] ?? ($p['checkin_date'] ?? '')) ?: null;
        $check_out_date = sanitize_text_field($p['check_out_date'] ?? ($p['checkout_date'] ?? '')) ?: null;

        // Check for private event days
        $private_events = get_option('aiohm_booking_mvp_private_events', []);
        $booking_dates = self::get_booking_date_range($check_in_date, $check_out_date);
        $has_private_only_days = false;
        $private_event_info = null;

        foreach ($booking_dates as $date) {
            if (isset($private_events[$date])) {
                $event = $private_events[$date];
                $mode = $event['mode'] ?? 'private_only';
                
                // Only block individual bookings for 'private_only' mode
                if ($mode === 'private_only') {
                    $has_private_only_days = true;
                    $private_event_info = $event;
                    break;
                }
            }
        }

        // If booking includes private-only event days, force private_all booking
        if ($has_private_only_days && !$private_all) {
            return new WP_Error(
                'private_event_only', 
                'This date is reserved for private events. Only full property bookings are available for ' . $private_event_info['name'] . '.', 
                ['status' => 400, 'private_event' => true, 'event_info' => $private_event_info]
            );
        }

        $total = self::calculate_accommodation_total($room_ids, $rooms_qty, $private_all, $opts, $check_in_date, $check_out_date, $private_event_info);

        if($total <= 0){
            return new WP_Error('invalid_total', 'Order total must be greater than 0', ['status' => 400]);
        }

        $deposit = round($total * ($opts['deposit_percent'] / 100), 2);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $insert_result = $wpdb->insert($wpdb->prefix.'aiohm_booking_mvp_order',[
            'mode' => $mode,
            'rooms_qty' => $rooms_qty,
            'guests_qty' => $guests_qty,
            'private_all' => $private_all,
            'buyer_name' => $name,
            'buyer_email' => $email,
            'buyer_phone' => sanitize_text_field($p['phone'] ?? ''),
            'buyer_age' => $age,
            'vat_number' => $vat_number,
            'purpose_of_stay' => $purpose_of_stay,
            'estimated_arrival_time' => $estimated_arrival_time,
            'bringing_pets' => $bringing_pets,
            'pet_details' => $pet_details,
            'check_in_date' => $check_in_date,
            'check_out_date' => $check_out_date,
            'total_amount' => $total,
            'deposit_amount' => $deposit,
            'currency' => $opts['currency'],
            'status' => 'pending',
        ]);
        
        // Check for database errors
        if ($insert_result === false) {
            return new WP_Error('database_error', 'Unable to create booking. Please try again.', ['status' => 500]);
        }
        
        $order_id = $wpdb->insert_id;
        
        // Validate order was created successfully
        if (!$order_id) {
            return new WP_Error('database_error', 'Unable to create booking. Please try again.', ['status' => 500]);
        }

        // Persist explicit room selections, if provided
        if (!empty($room_ids)) {
            $map = get_option('aiohm_booking_mvp_order_rooms', []);
            if (!is_array($map)) { $map = []; }
            $map[intval($order_id)] = array_values(array_unique($room_ids));
            update_option('aiohm_booking_mvp_order_rooms', $map);
        }

        // Trigger calendar sync hook
        do_action('aiohm_booking_mvp_order_created', $order_id, [
            'mode' => $mode,
            'rooms_qty' => $rooms_qty,
            'guests_qty' => $guests_qty,
            'private_all' => $private_all,
            'check_in_date' => $check_in_date,
            'check_out_date' => $check_out_date,
            'room_ids' => $room_ids,
            'vat_number' => $vat_number,
            'purpose_of_stay' => $purpose_of_stay,
            'estimated_arrival_time' => $estimated_arrival_time,
            'bringing_pets' => $bringing_pets,
            'pet_details' => $pet_details,
            'age' => $age,
        ]);

        return rest_ensure_response([
            'order_id'=>$order_id,
            'buyer_email'=>$email,
            'total'=>$total,
            'deposit'=>$deposit,
            'currency'=>$opts['currency'],
        ]);
        
        } catch (Exception $e) {
            return new WP_Error('server_error', 'A server error occurred. Please try again.', ['status' => 500]);
        }
    }

    public static function stripe_session( WP_REST_Request $r ){
        $p = $r->get_json_params();
        $order_id = absint($p['order_id'] ?? 0);

        if (!$order_id) {
            return new WP_Error('invalid_order', 'A valid order ID is required.', ['status' => 400]);
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}aiohm_booking_mvp_order WHERE id = %d", $order_id));

        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found.', ['status' => 404]);
        }

        $settings = get_option('aiohm_booking_mvp_settings', []);
        $stripe_secret_key = trim($settings['stripe_secret_key'] ?? '');

        if (empty($stripe_secret_key)) {
            return new WP_Error('stripe_not_configured', 'Stripe is not configured.', ['status' => 500]);
        }

        $line_items = [
            [
                'price_data' => [
                    'currency' => strtolower($order->currency),
                    'product_data' => [
                        'name' => 'Booking Deposit - Order #' . $order->id,
                    ],
                    'unit_amount' => round($order->deposit_amount * 100), // Amount in cents
                ],
                'quantity' => 1,
            ],
        ];

        $checkout_session_args = [
            'payment_method_types' => ['card'],
            'line_items' => $line_items,
            'mode' => 'payment',
            'success_url' => aiohm_booking_mvp_opt('thankyou_page_url', home_url('/')) . '?order_id=' . $order_id,
            'cancel_url' => aiohm_booking_mvp_opt('checkout_page_url', home_url('/')) . '?order_id=' . $order_id . '&cancelled=true',
            'client_reference_id' => $order->id,
            'customer_email' => $order->buyer_email,
        ];

        $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $stripe_secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query($checkout_session_args),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('stripe_error', $response->get_error_message(), ['status' => 500]);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['error'])) {
            return new WP_Error('stripe_error', $body['error']['message'], ['status' => 400]);
        }

        if (empty($body['url'])) {
            return new WP_Error('stripe_error', 'Could not create Stripe checkout session.', ['status' => 500]);
        }

        return rest_ensure_response(['checkout_url' => $body['url']]);
    }

    public static function stripe_webhook( WP_REST_Request $r ){
        $payload = $r->get_body();
        $event = json_decode($payload);

        // Handle the event
        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                
                // The order ID should be in the client_reference_id
                $order_id = absint($session->client_reference_id ?? 0);
                $payment_intent_id = sanitize_text_field($session->payment_intent ?? '');

                if ($order_id > 0) {
                    global $wpdb;
                    $table = $wpdb->prefix . 'aiohm_booking_mvp_order';

                    // Check if order exists and is pending
                    // Safe SQL: Table name is sanitized via wpdb->prefix concatenation
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND status = 'pending'", $order_id));

                    if ($order) {
                        // Update order status to 'paid'
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->update(
                            $table,
                            [
                                'status' => 'paid',
                                'payment_method' => 'stripe',
                                'payment_id' => $payment_intent_id
                            ],
                            ['id' => $order_id]
                        );

                        // Trigger action for successful payment
                        do_action('aiohm_booking_mvp_payment_completed', $order_id, 'stripe');
                    }
                }
                break;
            // ... handle other event types
            default:
                // Unexpected event type
        }

        return rest_ensure_response(['status' => 'success']);
    }

    public static function paypal_capture( WP_REST_Request $r ){
        // PayPal capture handler - implement server-side verification for production
        $p = $r->get_json_params();
        $order_id = absint($p['order_id'] ?? 0);

        if($order_id){
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix.'aiohm_booking_mvp_order',
                ['status' => 'paid', 'payment_method' => 'paypal', 'payment_id' => 'pp_stub_'.time()],
                ['id' => $order_id]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'payment_id' => 'pp_stub_'.time()
        ]);
    }

    /**
     * Return dates that are fully unavailable (all rooms blocked) for the period
     * A date is considered "occupied" if all available rooms are booked by customers or blocked by an admin.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The REST response.
     */
    public static function availability(WP_REST_Request $request) {
        global $wpdb;
        
        $from_str = sanitize_text_field($request->get_param('from'));
        $to_str = sanitize_text_field($request->get_param('to'));

        try {
            $from_dt = new DateTime($from_str);
            $to_dt = new DateTime($to_str);
        } catch (Exception $e) {
            return new WP_Error('invalid_date', 'Invalid date format.', ['status' => 400]);
        }

        // Load settings
        $settings = get_option('aiohm_booking_mvp_settings', []);
        $total_rooms = intval($settings['available_rooms'] ?? 0);

        if ($total_rooms <= 0) {
            // If no rooms are configured, all dates are occupied.
            $occupied_dates = [];
            $period = new DatePeriod($from_dt, new DateInterval('P1D'), (clone $to_dt)->modify('+1 day'));
            foreach ($period as $day) {
                $occupied_dates[] = $day->format('Y-m-d');
            }
            return rest_ensure_response(['occupied_dates' => $occupied_dates, 'custom_prices' => []]);
        }

        // 1. Tally rooms occupied by customer bookings per day
        $order_table = $wpdb->prefix . 'aiohm_booking_mvp_order';
        // Safe SQL: Table name is sanitized via wpdb->prefix concatenation
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT check_in_date, check_out_date, private_all, rooms_qty FROM {$order_table} WHERE status IN ('paid', 'pending') AND check_in_date <= %s AND check_out_date > %s",
            $to_dt->format('Y-m-d'),
            $from_dt->format('Y-m-d')
        ));

        $daily_booked_rooms = [];
        if ($bookings) {
            foreach ($bookings as $booking) {
                try {
                    $start = new DateTime($booking->check_in_date);
                    $end = new DateTime($booking->check_out_date);
                    $booking_period = new DatePeriod($start, new DateInterval('P1D'), $end);

                    foreach ($booking_period as $day) {
                        $date_key = $day->format('Y-m-d');
                        if (!isset($daily_booked_rooms[$date_key])) {
                            $daily_booked_rooms[$date_key] = 0;
                        }
                        if ($booking->private_all) {
                            $daily_booked_rooms[$date_key] = $total_rooms; // Mark all rooms as booked
                        } else {
                            $daily_booked_rooms[$date_key] += intval($booking->rooms_qty);
                        }
                    }
                } catch (Exception $e) {
                    // Skip invalid booking dates
                }
            }
        }

        // 2. Tally rooms blocked by admin per day
        $admin_blocked_dates = get_option('aiohm_booking_mvp_blocked_dates', []);
        $daily_admin_blocked_rooms = [];
        if (!empty($admin_blocked_dates)) {
            foreach ($admin_blocked_dates as $room_id => $dates) {
                if (!is_array($dates)) continue;
                foreach ($dates as $date_str => $details) {
                    $status = is_array($details) ? ($details['status'] ?? 'blocked') : 'blocked';
                    if (in_array($status, ['blocked', 'booked', 'pending', 'external'])) {
                        try {
                            $current_date = new DateTime($date_str);
                            if ($current_date >= $from_dt && $current_date <= $to_dt) {
                                if (!isset($daily_admin_blocked_rooms[$date_str])) {
                                    $daily_admin_blocked_rooms[$date_str] = [];
                                }
                                // Use room_id as key to prevent double counting a room on a given day
                                $daily_admin_blocked_rooms[$date_str][$room_id] = true;
                            }
                        } catch (Exception $e) {
                            // Skip invalid date
                        }
                    }
                }
            }
        }

        // 3. Get admin-set custom prices for the date range
        $admin_custom_prices = [];
        if (!empty($admin_blocked_dates)) {
            $price_period = new DatePeriod($from_dt, new DateInterval('P1D'), (clone $to_dt)->modify('+1 day'));
            foreach ($price_period as $day) {
                $date_key = $day->format('Y-m-d');
                $day_specific_prices = [];
                foreach ($admin_blocked_dates as $room_id => $dates) {
                    if (isset($dates[$date_key]) && !empty($dates[$date_key]['price'])) {
                        $day_specific_prices[] = floatval($dates[$date_key]['price']);
                    }
                }
                if (!empty($day_specific_prices)) {
                    // If multiple rooms have custom prices on the same day, the frontend will use the lowest one.
                    $admin_custom_prices[$date_key] = min($day_specific_prices);
                }
            }
        }

        // 4. Calculate daily prices for frontend display
        $accommodation_details = get_option('aiohm_booking_mvp_accommodations_details', []);
        $all_prices = [];
        $default_price = floatval($settings['room_price'] ?? 0);

        // Collect all configured standard prices to find the minimum "starting from" price
        if ($total_rooms > 0) {
            for ($i = 0; $i < $total_rooms; $i++) {
                $details = $accommodation_details[$i] ?? [];
                $price = !empty($details['price']) ? floatval($details['price']) : $default_price;
                if ($price > 0) {
                    $all_prices[] = $price;
                }
            }
        }
        $base_price = !empty($all_prices) ? min($all_prices) : $default_price;

        $daily_prices = [];
        $private_events_info = [];
        $private_events = get_option('aiohm_booking_mvp_private_events', []);
        
        $price_period_final = new DatePeriod($from_dt, new DateInterval('P1D'), (clone $to_dt)->modify('+1 day'));
        foreach ($price_period_final as $day) {
            $date_key = $day->format('Y-m-d');
            
            // Check for private events
            if (isset($private_events[$date_key])) {
                $event = $private_events[$date_key];
                $event_mode = $event['mode'] ?? 'private_only';
                
                // Store event info for frontend
                $private_events_info[$date_key] = [
                    'mode' => $event_mode,
                    'name' => $event['name'] ?? 'Private Event',
                    'price' => floatval($event['price'] ?? 0)
                ];
                
                // For special pricing mode, use the event price
                if ($event_mode === 'special_pricing') {
                    $daily_prices[$date_key] = floatval($event['price']);
                } else {
                    // For private_only, still show the custom price or base price for reference
                    $daily_prices[$date_key] = $admin_custom_prices[$date_key] ?? $base_price;
                }
            } else {
                // Use admin custom price if set, otherwise use the calculated base price
                $daily_prices[$date_key] = $admin_custom_prices[$date_key] ?? $base_price;
            }
        }

        // 5. Combine and determine fully occupied dates
        $occupied_dates = [];
        $period = new DatePeriod($from_dt, new DateInterval('P1D'), (clone $to_dt)->modify('+1 day'));
        foreach ($period as $day) {
            $date_key = $day->format('Y-m-d');
            $booked_count = $daily_booked_rooms[$date_key] ?? 0;
            $admin_blocked_count = isset($daily_admin_blocked_rooms[$date_key]) ? count($daily_admin_blocked_rooms[$date_key]) : 0;

            if (($booked_count + $admin_blocked_count) >= $total_rooms) {
                $occupied_dates[] = $date_key;
            }
        }

        return rest_ensure_response([
            'occupied_dates' => array_values(array_unique($occupied_dates)),
            'daily_prices' => $daily_prices,
            'private_events' => $private_events_info,
        ]);
    }

    /**
     * Admin: Toggle block/unblock a specific room/date
     */
    public static function toggle_block_date( WP_REST_Request $r ){
        // Security: require REST nonce header and capability via permission_callback
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden','Insufficient permissions',['status'=>403]);
        }
        $params = $r->get_json_params();
        $room_id = absint($params['room_id'] ?? 0);
        $date    = sanitize_text_field($params['date'] ?? '');
        $block   = !empty($params['block']) ? 1 : 0;

        if ($room_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
            return new WP_Error('invalid_params','Invalid room or date',['status'=>400]);
        }

        $blocked = get_option('aiohm_booking_mvp_blocked_dates', []);
        if ($block){
            if (!isset($blocked[$room_id])) $blocked[$room_id] = [];
            $blocked[$room_id][$date] = [
                'status' => 'blocked',
                'reason' => 'Set via API',
                'blocked_at' => current_time('mysql'),
                'blocked_by' => get_current_user_id()
            ];
        } else {
            if (isset($blocked[$room_id][$date])){
                unset($blocked[$room_id][$date]);
            }
        }
        update_option('aiohm_booking_mvp_blocked_dates', $blocked);

        return rest_ensure_response(['success'=>true,'room_id'=>$room_id,'date'=>$date,'blocked'=>$block]);
    }

    /**
     * Get array of dates for a booking period
     * @param string $check_in_date Check-in date (Y-m-d format)
     * @param string $check_out_date Check-out date (Y-m-d format) 
     * @return array Array of date strings in Y-m-d format
     */
    private static function get_booking_date_range($check_in_date, $check_out_date) {
        $dates = [];
        
        if (!$check_in_date || !$check_out_date) {
            return $dates;
        }
        
        try {
            $start = new DateTime($check_in_date);
            $end = new DateTime($check_out_date);
            $period = new DatePeriod($start, new DateInterval('P1D'), $end);
            
            foreach ($period as $day) {
                $dates[] = $day->format('Y-m-d');
            }
        } catch (Exception $e) {
            // Return empty array on error
        }
        
        return $dates;
    }

    /**
     * Calculate accommodation pricing based on actual selected rooms or private all
     * @param array $room_ids Array of selected room IDs (0-based from frontend)
     * @param int $rooms_qty Quantity of rooms
     * @param bool $private_all Whether private all rooms is selected
     * @param array $opts Pricing options from settings
     * @return float Total accommodation cost
     */
    private static function calculate_accommodation_total($room_ids, $rooms_qty, $private_all, $opts, $check_in_str = null, $check_out_str = null, $private_event_info = null) {
        $accommodation_details = get_option('aiohm_booking_mvp_accommodations_details', []);
        $admin_blocked_dates = get_option('aiohm_booking_mvp_blocked_dates', []);
        $default_room_price = $opts['room_price'];
        $available_rooms = $opts['available_rooms'];

        // Fallback to simple calculation if dates are not provided
        if (!$check_in_str || !$check_out_str) {
            // Use private event price if applicable
            if ($private_event_info && $private_all) {
                return floatval($private_event_info['price']);
            }
            return floatval($rooms_qty * $default_room_price);
        }

        try {
            $start = new DateTime($check_in_str);
            $end = new DateTime($check_out_str);
            $period = new DatePeriod($start, new DateInterval('P1D'), $end);
        } catch (Exception $e) {
            return floatval($rooms_qty * $default_room_price);
        }

        $total = 0.0;
        $rooms_to_price = [];

        if ($private_all) {
            for ($i = 1; $i <= $available_rooms; $i++) {
                $rooms_to_price[] = $i;
            }
        } else {
            $rooms_to_price = $room_ids;
        }

        // Get private events for checking dates
        $private_events = get_option('aiohm_booking_mvp_private_events', []);

        foreach ($period as $day) {
            $date_key = $day->format('Y-m-d');
            $daily_total = 0;

            // Check if this date has a private event
            $event_on_date = $private_events[$date_key] ?? null;
            $event_mode = $event_on_date['mode'] ?? null;
            
            if ($event_on_date && $event_mode === 'private_only' && $private_all) {
                // Private event with full property booking - use event price
                $daily_total = floatval($event_on_date['price']);
            } else {
                // Regular room-by-room pricing (including special pricing days)
                foreach ($rooms_to_price as $room_id) {
                    $accommodation_index = intval($room_id) - 1;
                    
                    $custom_price = null;
                    
                    // Check for admin blocked dates with custom prices first
                    if (isset($admin_blocked_dates[$room_id][$date_key]) && !empty($admin_blocked_dates[$room_id][$date_key]['price'])) {
                        $custom_price = floatval($admin_blocked_dates[$room_id][$date_key]['price']);
                    }
                    // Check for special pricing events (not private_only)
                    elseif ($event_on_date && $event_mode === 'special_pricing') {
                        // For special pricing, use the event price per room
                        $custom_price = floatval($event_on_date['price']);
                    }

                    if ($custom_price !== null && $custom_price >= 0) {
                        $daily_total += $custom_price;
                    } else {
                        $details = $accommodation_details[$accommodation_index] ?? [];
                        $price = !empty($details['price']) ? floatval($details['price']) : $default_room_price;
                        $daily_total += $price;
                    }
                }
            }
            $total += $daily_total;
        }

        return $total;
    }

    /**
     * Get price for a specific accommodation index
     * @param int $index 0-based accommodation index
     * @param array $accommodation_details Accommodation details array
     * @param float $default_price Default price fallback
     * @return float Price for this accommodation
     */
    private static function get_accommodation_price($index, $accommodation_details, $default_price) {
        $details = $accommodation_details[$index] ?? [];
        
        // Use standard price first, then early bird as fallback, then default
        if (!empty($details['price']) && floatval($details['price']) > 0) {
            return floatval($details['price']);
        } elseif (!empty($details['earlybird_price']) && floatval($details['earlybird_price']) > 0) {
            return floatval($details['earlybird_price']);
        } else {
            return floatval($default_price);
        }
    }

    /**
     * Verify nonce for public endpoints that accept user input
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function verify_public_nonce($request) {
        // Check for nonce in header or request parameter
        $nonce = $request->get_header('X-WP-Nonce') ?: $request->get_param('_wpnonce');
        
        if (empty($nonce)) {
            return new WP_Error('missing_nonce', 'Security nonce is required', ['status' => 403]);
        }
        
        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('invalid_nonce', 'Invalid security nonce', ['status' => 403]);
        }
        
        return true;
    }

    /**
     * Verify webhook requests (minimal verification for payment webhooks)
     * @param WP_REST_Request $request
     * @return bool
     */
    public static function verify_webhook($request) {
        $settings = get_option('aiohm_booking_mvp_settings', []);
        $webhook_secret = trim($settings['stripe_webhook_secret'] ?? '');

        if (empty($webhook_secret)) {
            // If no secret is configured, deny access
            return false;
        }

        $signature_header = $request->get_header('stripe_signature');
        if (empty($signature_header)) {
            return false; // No signature, deny.
        }

        $payload = $request->get_body();

        // Parse the signature header
        $timestamp = '';
        $signature_v1 = '';
        $parts = explode(',', $signature_header);
        foreach ($parts as $part) {
            list($key, $value) = explode('=', $part, 2);
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signature_v1 = $value;
            }
        }

        if (empty($timestamp) || empty($signature_v1)) {
            return false; // Malformed header
        }

        // Check if the timestamp is too old (e.g., more than 5 minutes) to prevent replay attacks
        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $signed_payload = $timestamp . '.' . $payload;
        $expected_signature = hash_hmac('sha256', $signed_payload, $webhook_secret);

        // Compare signatures securely to prevent timing attacks
        return hash_equals($expected_signature, $signature_v1);
    }
}
