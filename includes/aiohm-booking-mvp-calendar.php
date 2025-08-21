<?php
/**
 * AIOHM Booking Calendar Class
 *
 * Professional calendar management system for booking and room availability visualization.
 * Handles different period types, room management, and booking data integration.
 *
 * @package AIOHM_Booking
 * @since   1.0.0
 */

if (!defined('ABSPATH')) { exit; }

/**
 * AIOHM Booking Calendar - Advanced Calendar System
 *
 * Provides comprehensive calendar functionality with support for multiple period types,
 * room management, booking visualization, and administrative controls.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// Reason: This class manages custom booking tables and requires direct database access for calendar operations
class AIOHM_BOOKING_MVP_Calendar {

    // Calendar Configuration Constants
    const ALL_ROOM_TYPES = '0';

    // Period Type Constants
    const PERIOD_TYPE_MONTH = 'month';
    const PERIOD_TYPE_QUARTER = 'quarter';
    const PERIOD_TYPE_YEAR = 'year';
    const PERIOD_TYPE_CUSTOM = 'custom';

    // Default Configuration Values
    const DEFAULT_ROOM_COUNT = 7;
    const DEFAULT_PERIOD_DAYS = 6;

    // Calendar Properties
    private $period_type;
    private $period_page;
    private $custom_period_from;
    private $custom_period_to;
    private $period;
    private $period_array;
    private $period_start_date;
    private $period_end_date;
    private $room_type_id;
    private $room_posts = [];
    private $booking_data = [];

    /**
     * Initialize Calendar Instance
     *
     * @param array $attributes Configuration attributes for calendar setup
     */
    public function __construct($attributes = []) {
        $default_attributes = $this->getDefaultAttributes();
        $attributes = array_merge($default_attributes, $attributes);
        $attributes = $this->parseFilterAttributes($attributes);

        $this->setCalendarProperties($attributes);
        $this->initializeCalendar();
    }

    /**
     * Get default calendar attributes
     *
     * @return array Default configuration attributes
     */
    private function getDefaultAttributes() {
        return array(
            'room_type_id' => self::ALL_ROOM_TYPES,
            'period_type' => self::PERIOD_TYPE_CUSTOM,
            'period_page' => 0,
            'custom_period_from' => new DateTime('today'),
            'custom_period_to' => new DateTime('+' . self::DEFAULT_PERIOD_DAYS . ' days'),
        );
    }

    /**
     * Set calendar properties from attributes
     *
     * @param array $attributes Parsed configuration attributes
     */
    private function setCalendarProperties($attributes) {
        $this->room_type_id = absint($attributes['room_type_id']);
        $this->period_type = $attributes['period_type'];
        $this->period_page = $attributes['period_page'];

        if ($this->period_type === self::PERIOD_TYPE_CUSTOM) {
            $this->custom_period_from = $attributes['custom_period_from'];
            $this->custom_period_to = $attributes['custom_period_to'];
        }
    }

    /**
     * Initialize calendar components
     */
    private function initializeCalendar() {
        $this->setupCalendarPeriod();
        $this->setupRoomConfiguration();
        $this->setupBookingData();
    }

    /**
     * Setup calendar period based on type and configuration
     */
    private function setupCalendarPeriod() {
        switch ($this->period_type) {
            case self::PERIOD_TYPE_QUARTER:
                $this->period = $this->createQuarterPeriod($this->period_page);
                break;
            case self::PERIOD_TYPE_YEAR:
                $this->period = $this->createYearPeriod($this->period_page);
                break;
            case self::PERIOD_TYPE_CUSTOM:
                $this->period = $this->createCustomPeriod();
                break;
            case self::PERIOD_TYPE_MONTH:
            default:
                $this->period = $this->createMonthPeriod($this->period_page);
                break;
        }

        $this->period_array = iterator_to_array($this->period);
        $this->period_end_date = end($this->period_array);
        $this->period_start_date = reset($this->period_array);
    }

    /**
     * Create quarter period for calendar display
     *
     * @param int $quarter_page Quarter navigation offset
     * @return DatePeriod Quarter period object
     */
    private function createQuarterPeriod($quarter_page = 0) {
        $current_quarter = ceil(current_time('n') / 3);
        $target_quarter = $current_quarter + $quarter_page;
        $year = current_time('Y') + floor($target_quarter / 4);
        $quarter = $target_quarter % 4 ?: 4;

        $first_month = ($quarter - 1) * 3 + 1;
        $last_month = $quarter * 3;

        $first_day = new DateTime($year . '-' . sprintf('%02d', $first_month) . '-01');
        $last_day = new DateTime($year . '-' . sprintf('%02d', $last_month) . '-01');
        $last_day->modify('last day of this month');

        return $this->createDatePeriod($first_day, $last_day, true);
    }

    /**
     * Create year period for calendar display
     *
     * @param int $year_page Year navigation offset
     * @return DatePeriod Year period object
     */
    private function createYearPeriod($year_page = 0) {
        $current_year = current_time('Y');
        $target_year = $current_year + $year_page;
        $first_day = new DateTime('first day of January ' . $target_year);
        $last_day = new DateTime('last day of December ' . $target_year);

        return $this->createDatePeriod($first_day, $last_day, true);
    }

    /**
     * Create custom period for calendar display
     *
     * @return DatePeriod Custom period object
     */
    private function createCustomPeriod() {
        $start_date = $this->custom_period_from;
        $end_date = $this->custom_period_to;

        // Ensure logical date order
        if ($start_date > $end_date) {
            list($start_date, $end_date) = array($end_date, $start_date);
        }

        return $this->createDatePeriod($start_date, $end_date, true);
    }

    /**
     * Create month period for calendar display
     *
     * @param int $month_page Month navigation offset
     * @return DatePeriod Month period object
     */
    private function createMonthPeriod($month_page = 0) {
        $base_date = new DateTime('first day of this month');
        $direction = $month_page < 0 ? '-' : '+';

        $first_day = clone $base_date;
        $first_day->modify($direction . absint($month_page) . ' month');

        $last_day = clone $first_day;
        $last_day->modify('last day of this month');

        return $this->createDatePeriod($first_day, $last_day, true);
    }

    /**
     * Create date period with optional end date inclusion
     *
     * @param DateTime $start_date Period start date
     * @param DateTime $end_date Period end date
     * @param bool $include_end Whether to include end date
     * @return DatePeriod Configured date period
     */
    private function createDatePeriod($start_date, $end_date, $include_end = false) {
        $interval = new DateInterval('P1D');

        if ($include_end) {
            $end_date_extended = clone $end_date;
            $end_date_extended->modify('+1 day');
            return new DatePeriod($start_date, $interval, $end_date_extended);
        }

        return new DatePeriod($start_date, $interval, $end_date);
    }

    /**
     * Setup room configuration and accommodation details
     */
    private function setupRoomConfiguration() {
        $settings = get_option('aiohm_booking_mvp_settings', array());
        $room_count = intval($settings['available_rooms'] ?? self::DEFAULT_ROOM_COUNT);

        $accommodation_details = get_option('aiohm_booking_mvp_accommodations_details', array());
        $product_names = aiohm_booking_mvp_get_product_names();

        $this->room_posts = array();

        for ($room_number = 1; $room_number <= $room_count; $room_number++) {
            $room_data = $this->createRoomData($room_number, $accommodation_details, $product_names);
            $this->room_posts[] = $room_data;
        }
    }

    /**
     * Create room data object
     *
     * @param int $room_number Room number identifier
     * @param array $accommodation_details Custom accommodation configuration
     * @param array $product_names Product naming configuration
     * @return stdClass Room data object
     */
    private function createRoomData($room_number, $accommodation_details, $product_names) {
        $room = new stdClass();
        $room->ID = $room_number;

        $accommodation_index = $room_number - 1;
        $has_custom_title = isset($accommodation_details[$accommodation_index])
                          && !empty($accommodation_details[$accommodation_index]['title']);

        if ($has_custom_title) {
            $room->post_title = sanitize_text_field($accommodation_details[$accommodation_index]['title']);
        } else {
            $room->post_title = ucfirst($product_names['singular']) . ' ' . $room_number;
        }

        return $room;
    }

    /**
     * Setup booking data from database orders
     */
    private function setupBookingData() {
        global $wpdb;

        $orders_table = $wpdb->prefix . 'aiohm_booking_mvp_order';
        $period_start = $this->period_start_date->format('Y-m-d');
        $period_end = $this->period_end_date->format('Y-m-d');

        $booking_orders = $this->fetchBookingOrders($orders_table, $period_start, $period_end);
        $this->booking_data = array();

        $explicit_room_assignments = get_option('aiohm_booking_mvp_order_rooms', []);

        foreach ($booking_orders as $order) {
            $this->processBookingOrder($order, $explicit_room_assignments);
        }
    }

    /**
     * Fetch booking orders from database
     *
     * @param string $table_name Orders table name
     * @param string $start_date Period start date
     * @param string $end_date Period end date
     * @return array Booking orders data
     */
    private function fetchBookingOrders($table_name, $start_date, $end_date) {
        global $wpdb;

        // Safe SQL: Table name is constructed via wpdb->prefix concatenation at line 269
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name}
             WHERE (check_in_date <= %s AND check_out_date > %s)
             AND status IN ('pending', 'paid')
             ORDER BY created_at DESC",
            $end_date, $start_date
        ));
    }

    /**
     * Process individual booking order
     *
     * @param object $order Booking order data
     * @param array $explicit_assignments Explicit room assignments
     */
    private function processBookingOrder($order, $explicit_assignments) {
        if (!$this->isValidOrderDates($order)) {
            return;
        }

        $check_in_date = new DateTime($order->check_in_date);
        $check_out_date = new DateTime($order->check_out_date);
        $assigned_rooms = $this->determineRoomAssignments($order, $explicit_assignments);

        foreach ($assigned_rooms as $room_id) {
            $this->assignOrderToRoom($room_id, $check_in_date, $check_out_date, $order);
        }
    }

    /**
     * Validate order dates
     *
     * @param object $order Booking order data
     * @return bool Whether dates are valid
     */
    private function isValidOrderDates($order) {
        return !empty($order->check_in_date) && !empty($order->check_out_date);
    }

    /**
     * Determine room assignments for booking order
     *
     * @param object $order Booking order data
     * @param array $explicit_assignments Explicit room assignments
     * @return array Room assignment IDs
     */
    private function determineRoomAssignments($order, $explicit_assignments) {
        $order_id = intval($order->id);

        if ($order->private_all) {
            return range(1, count($this->room_posts));
        }

        // Check for explicit room assignments
        if (!empty($explicit_assignments[$order_id]) && is_array($explicit_assignments[$order_id])) {
            $assigned_rooms = array_map('intval', $explicit_assignments[$order_id]);
            return array_values(array_filter($assigned_rooms, function($room_id) {
                return $room_id > 0;
            }));
        }

        // Default room assignment based on quantity
        $rooms_needed = max(1, intval($order->rooms_qty));
        return range(1, min($rooms_needed, count($this->room_posts)));
    }

    /**
     * Assign booking order to specific room
     *
     * @param int $room_id Room identifier
     * @param DateTime $check_in Check-in date
     * @param DateTime $check_out Check-out date
     * @param object $order Booking order data
     */
    private function assignOrderToRoom($room_id, $check_in, $check_out, $order) {
        if (!isset($this->booking_data[$room_id])) {
            $this->booking_data[$room_id] = array();
        }

        $booking_period = new DatePeriod($check_in, new DateInterval('P1D'), $check_out);

        foreach ($booking_period as $date) {
            $date_key = $date->format('Y-m-d');
            $is_check_in = $date->format('Y-m-d') === $check_in->format('Y-m-d');

            $this->booking_data[$room_id][$date_key] = $this->createBookingDataEntry($order, $is_check_in);
        }

        // Mark check-out date
        $this->markCheckOutDate($room_id, $check_out, $order);
    }

    /**
     * Create booking data entry
     *
     * @param object $order Booking order data
     * @param bool $is_check_in Whether this is check-in date
     * @return array Booking data entry
     */
    private function createBookingDataEntry($order, $is_check_in) {
        return array(
            'is_locked' => true,
            'is_check_in' => $is_check_in,
            'is_check_out' => false,
            'booking_status' => $order->status,
            'booking_id' => $order->id,
            'buyer_name' => $order->buyer_name,
            'buyer_email' => $order->buyer_email,
            'order_mode' => $order->mode,
            'is_private' => $order->private_all,
            'rooms_qty' => $order->rooms_qty,
            'total_amount' => $order->total_amount,
            'currency' => $order->currency
        );
    }

    /**
     * Mark check-out date in booking data
     *
     * @param int $room_id Room identifier
     * @param DateTime $check_out Check-out date
     * @param object $order Booking order data
     */
    private function markCheckOutDate($room_id, $check_out, $order) {
        $checkout_key = $check_out->format('Y-m-d');

        if (!isset($this->booking_data[$room_id][$checkout_key])) {
            $this->booking_data[$room_id][$checkout_key] = array();
        }

        $this->booking_data[$room_id][$checkout_key] = array_merge(
            $this->booking_data[$room_id][$checkout_key] ?? array(),
            array(
                'is_check_out' => true,
                'check_out_booking_id' => $order->id,
                'check_out_booking_status' => $order->status,
                'check_out_buyer_name' => $order->buyer_name
            )
        );
    }

    /**
     * Get comprehensive room date details including admin status
     *
     * @param int $room_id Room identifier
     * @param DateTime $date Target date
     * @return array Complete date details
     */
    private function getRoomDateDetails($room_id, $date) {
        $date_details = $this->getDefaultDateDetails();
        $date_formatted = $date->format('Y-m-d');

        // Apply admin-set status if exists
        $admin_status = $this->getAdminStatusForDate($room_id, $date_formatted);
        if ($admin_status) {
            $date_details = array_merge($date_details, $admin_status);
        }

        // Apply booking data if exists
        if (isset($this->booking_data[$room_id]) && isset($this->booking_data[$room_id][$date_formatted])) {
            $date_details = array_merge($date_details, $this->booking_data[$room_id][$date_formatted]);
        }

        // Check for private events (special pricing or private only)
        $private_events = get_option('aiohm_booking_mvp_private_events', []);
        if (isset($private_events[$date_formatted])) {
            $event = $private_events[$date_formatted];
            $mode = $event['mode'] ?? 'private_only';
            
            $date_details['has_private_event'] = true;
            $date_details['private_event_mode'] = $mode;
            $date_details['private_event_name'] = $event['name'] ?? 'Private Event';
            $date_details['private_event_price'] = $event['price'] ?? 0;
        }

        return $date_details;
    }

    /**
     * Get default date details structure
     *
     * @return array Default date details
     */
    private function getDefaultDateDetails() {
        return array(
            'is_locked' => false,
            'is_check_out' => false,
            'is_check_in' => false,
            'is_blocked' => false,
        );
    }

    /**
     * Get admin status for specific date
     *
     * @param int $room_id Room identifier
     * @param string $date_formatted Formatted date string
     * @return array|false Admin status data or false if none
     */
    private function getAdminStatusForDate($room_id, $date_formatted) {
        $blocked_dates = get_option('aiohm_booking_mvp_blocked_dates', []);

        if (empty($blocked_dates[$room_id]) || empty($blocked_dates[$room_id][$date_formatted])) {
            return false;
        }

        $block_data = $blocked_dates[$room_id][$date_formatted];
        $status = $block_data['status'] ?? 'blocked';

        $admin_status = array(
            'is_blocked' => true,
            'admin_status' => $status,
            'block_reason' => $block_data['reason'] ?? '',
            'blocked_at' => $block_data['blocked_at'] ?? '',
            'custom_price' => $block_data['price'] ?? '',
        );

        // Set specific status flags
        switch ($status) {
            case 'booked':
                $admin_status['is_admin_booked'] = true;
                break;
            case 'pending':
                $admin_status['is_admin_pending'] = true;
                break;
            case 'external':
                $admin_status['is_external'] = true;
                break;
            case 'blocked':
            default:
                $admin_status['is_admin_blocked'] = true;
                break;
        }

        return $admin_status;
    }

    /**
     * Parse filter attributes from request parameters
     * Note: No nonce verification needed - this is read-only calendar filtering for display purposes
     *
     * @param array $defaults Default attribute values
     * @return array Parsed attributes
     */
    private function parseFilterAttributes($defaults = array()) {
        $attributes = $defaults;

        // Safe: Read-only calendar filtering, properly sanitized
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['room_type_id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $attributes['room_type_id'] = absint(wp_unslash($_GET['room_type_id']));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['period']) && array_key_exists(sanitize_text_field(wp_unslash($_GET['period'])), $this->getAvailablePeriods())) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $attributes['period_type'] = sanitize_text_field(wp_unslash($_GET['period']));
            $attributes = $this->parseCustomPeriodAttributes($attributes);
            $attributes = $this->parsePeriodPageAttributes($attributes);
        }

        $attributes = $this->parsePeriodNavigation($attributes);

        return $attributes;
    }

    /**
     * Parse custom period attributes from request
     * Note: No nonce verification needed - this is read-only calendar filtering for display purposes
     *
     * @param array $attributes Current attributes
     * @return array Updated attributes
     */
    private function parseCustomPeriodAttributes($attributes) {
        if ($attributes['period_type'] !== self::PERIOD_TYPE_CUSTOM) {
            return $attributes;
        }

        // Safe: Read-only calendar filtering, properly sanitized
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!empty($_GET['custom_period_from'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $date_string = sanitize_text_field(wp_unslash($_GET['custom_period_from']));
            $custom_from = DateTime::createFromFormat('Y-m-d', $date_string);
            if ($custom_from !== false && $custom_from->format('Y-m-d') === $date_string) {
                $attributes['custom_period_from'] = $custom_from;
            }
        }

        // Safe: Read-only calendar filtering, properly sanitized
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!empty($_GET['custom_period_to'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $date_string = sanitize_text_field(wp_unslash($_GET['custom_period_to']));
            $custom_to = DateTime::createFromFormat('Y-m-d', $date_string);
            if ($custom_to !== false && $custom_to->format('Y-m-d') === $date_string) {
                $attributes['custom_period_to'] = $custom_to;
            }
        }

        return $attributes;
    }

    /**
     * Parse period page attributes from request
     * Note: No nonce verification needed - this is read-only calendar pagination for display purposes
     *
     * @param array $attributes Current attributes
     * @return array Updated attributes
     */
    private function parsePeriodPageAttributes($attributes) {
        $page_parameter = 'period_page_' . $attributes['period_type'];
        // Safe: Read-only calendar pagination, properly sanitized
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET[$page_parameter])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $attributes['period_page'] = intval(wp_unslash($_GET[$page_parameter]));
        }

        return $attributes;
    }

    /**
     * Parse period navigation from request
     * Note: No nonce verification needed - this is read-only calendar navigation for display purposes
     *
     * @param array $attributes Current attributes
     * @return array Updated attributes
     */
    private function parsePeriodNavigation($attributes) {
        // Safe: Read-only calendar navigation, no data processing
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['action_period_next'])) { // No unslash needed - just checking existence
            $attributes = $this->handleNextPeriodNavigation($attributes);
        }

        // Safe: Read-only calendar navigation, no data processing
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['action_period_prev'])) { // No unslash needed - just checking existence
            $attributes = $this->handlePreviousPeriodNavigation($attributes);
        }

        return $attributes;
    }

    /**
     * Handle next period navigation
     *
     * @param array $attributes Current attributes
     * @return array Updated attributes
     */
    private function handleNextPeriodNavigation($attributes) {
        if ($attributes['period_type'] === self::PERIOD_TYPE_CUSTOM) {
            return $this->shiftCustomPeriodForward($attributes);
        } else {
            $attributes['period_page']++;
            return $attributes;
        }
    }

    /**
     * Handle previous period navigation
     *
     * @param array $attributes Current attributes
     * @return array Updated attributes
     */
    private function handlePreviousPeriodNavigation($attributes) {
        if ($attributes['period_type'] === self::PERIOD_TYPE_CUSTOM) {
            return $this->shiftCustomPeriodBackward($attributes);
        } else {
            $attributes['period_page']--;
            return $attributes;
        }
    }

    /**
     * Shift custom period forward for navigation
     *
     * @param array $attributes Current attributes
     * @return array Updated attributes
     */
    private function shiftCustomPeriodForward($attributes) {
        $days_difference = $attributes['custom_period_from']->diff($attributes['custom_period_to'])->days;

        $attributes['custom_period_from'] = clone $attributes['custom_period_from'];
        $attributes['custom_period_from']->modify('+' . ($days_difference + 1) . ' days');

        $attributes['custom_period_to'] = clone $attributes['custom_period_from'];
        $attributes['custom_period_to']->modify('+' . $days_difference . ' days');

        return $attributes;
    }

    /**
     * Shift custom period backward for navigation
     *
     * @param array $attributes Current attributes
     * @return array Updated attributes
     */
    private function shiftCustomPeriodBackward($attributes) {
        $days_difference = $attributes['custom_period_from']->diff($attributes['custom_period_to'])->days;

        $attributes['custom_period_from'] = clone $attributes['custom_period_from'];
        $attributes['custom_period_from']->modify('-' . ($days_difference + 1) . ' days');

        $attributes['custom_period_to'] = clone $attributes['custom_period_from'];
        $attributes['custom_period_to']->modify('+' . $days_difference . ' days');

        return $attributes;
    }

    /**
     * Get available period types
     *
     * @return array Available periods with labels
     */
    public static function getAvailablePeriods() {
        return array(
            self::PERIOD_TYPE_CUSTOM => __('Week', 'aiohm-booking-mvp'),
            self::PERIOD_TYPE_MONTH => __('Month', 'aiohm-booking-mvp'),
            self::PERIOD_TYPE_YEAR => __('Year', 'aiohm-booking-mvp'),
        );
    }

    /**
     * Static method to render calendar instance
     */
    public static function render() {
        $default_arguments = array();
        // Safe: Read-only check for default calendar period, no data processing
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (empty($_GET['period'])) { // No unslash needed - just checking if empty
            $default_arguments = array(
                'period_type' => self::PERIOD_TYPE_CUSTOM,
            );
        }

        $calendar = new self($default_arguments);
        require_once AIOHM_BOOKING_MVP_DIR . 'templates/aiohm-booking-mvp-calendar.php';
    }

    /**
     * Render complete calendar interface
     */
    public function render_calendar() {
        $period_type = $this->period_type;
        if ($period_type == self::PERIOD_TYPE_CUSTOM) {
            $period_type .= '-period';
        }

        $room_count = count($this->room_posts);
        $calendar_size = ($room_count > 5) ? 'default' : 'large';

        $product_names = aiohm_booking_mvp_get_product_names();
        ?>
        <div class="aiohm-bookings-calendar-wrapper">
            <?php $this->renderCalendarFilters(); ?>
            <div class="aiohm-booking-calendar-tables-wrapper <?php echo esc_attr("aiohm-booking-calendar-{$period_type}-tables"); ?> <?php echo esc_attr("aiohm-booking-calendar-size-{$calendar_size}"); ?>">
                <?php $this->renderRoomsTable(); ?>
                <div class="aiohm-bookings-calendar-holder">
                    <?php $this->renderCalendarTable(); ?>
                </div>
            </div>

            <?php $this->renderBookingDetailsPopup(); ?>
            <?php $this->renderFooterFilters(); ?>
            <?php $this->renderSyncModules(); ?>
            <?php $this->renderAITableInsights(); ?>
        </div>
        <?php
    }

    /**
     * Render calendar filter controls
     */
    private function renderCalendarFilters() {
        $product_names = aiohm_booking_mvp_get_product_names();
        ?>
        <div class="aiohm-bookings-calendar-filters-wrapper">
            <form id="aiohm-bookings-calendar-filters" method="get" class="wp-filter">
                <?php $this->renderHiddenFormParameters(); ?>
                <div class="aiohm-bookings-calendar-controls">
                    <div class="aiohm-filter-group">
                        <?php $this->renderPeriodFilterControls(); ?>
                        <?php submit_button(__('Show', 'aiohm-booking-mvp'), 'button aiohm-show-button', 'action_filter', false); ?>
                    </div>
                    <?php $this->renderCalendarLegend(); ?>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render hidden form parameters
     */
    private function renderHiddenFormParameters() {
        $parameters = array();
        // Safe: Read-only form parameter preservation for calendar navigation
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['page'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $parameters['page'] = sanitize_text_field(wp_unslash($_GET['page']));
        }

        foreach ($parameters as $param_name => $param_value) {
            printf('<input type="hidden" name="%s" value="%s" />', esc_attr($param_name), esc_attr($param_value));
        }
    }

    /**
     * Render period filter controls
     */
    private function renderPeriodFilterControls() {
        $available_periods = $this->getAvailablePeriods();

        // Render hidden period page inputs
        foreach ($available_periods as $period => $period_label) {
            if ($period === self::PERIOD_TYPE_CUSTOM) continue;

            $period_page = $this->period_type === $period ? $this->period_page : 0;
            printf('<input type="hidden" name="period_page_%s" value="%s" />',
                   esc_attr($period), esc_attr($period_page));
        }

        echo '<label for="aiohm-bookings-calendar-filter-period">' . esc_html__('Period:', 'aiohm-booking-mvp') . '</label>';

        // Navigation buttons
        submit_button('&lt;', 'button aiohm-period-prev', 'action_period_prev', false,
                     array('title' => __('&lt; Prev', 'aiohm-booking-mvp')));

        // Period selector
        echo '<select id="aiohm-bookings-calendar-filter-period" name="period">';
        foreach ($available_periods as $period => $period_label) {
            printf('<option %s value="%s">%s</option>',
                   selected($this->period_type, $period, false),
                   esc_attr($period),
                   esc_html($period_label));
        }
        echo '</select>';

        submit_button('&gt;', 'button aiohm-period-next', 'action_period_next', false,
                     array('title' => __('Next &gt;', 'aiohm-booking-mvp')));

        $this->renderCustomPeriodInputs();
    }

    /**
     * Render custom period input fields
     */
    private function renderCustomPeriodInputs() {
        // Keep week navigation via arrows; hide date range inline controls
        $custom_period_class = ' aiohm-hide';

        $date_from = !is_null($this->custom_period_from) ? $this->custom_period_from->format('Y-m-d') : '';
        $date_to = !is_null($this->custom_period_to) ? $this->custom_period_to->format('Y-m-d') : '';
        ?>
        <div class="aiohm-custom-period-wrapper<?php echo esc_attr($custom_period_class); ?>">
            <input type="date" class="aiohm-custom-period-from" name="custom_period_from"
                   placeholder="<?php esc_attr_e('From', 'aiohm-booking-mvp'); ?>"
                   value="<?php echo esc_attr($date_from); ?>" />
            <input type="date" class="aiohm-custom-period-to" name="custom_period_to"
                   placeholder="<?php esc_attr_e('Until', 'aiohm-booking-mvp'); ?>"
                   value="<?php echo esc_attr($date_to); ?>" />
        </div>
        <?php
    }

    /**
     * Render calendar legend
     */
    private function renderCalendarLegend() {
        ?>
        <div class="aiohm-calendar-legend" aria-label="Calendar legend">
            <span class="legend-item"><span class="legend-dot legend-free" aria-hidden="true"></span><span class="legend-text">Free</span></span>
            <span class="legend-item"><span class="legend-dot legend-booked" aria-hidden="true"></span><span class="legend-text">Booked</span></span>
            <span class="legend-item"><span class="legend-dot legend-pending" aria-hidden="true"></span><span class="legend-text">Pending</span></span>
            <span class="legend-item"><span class="legend-dot legend-external" aria-hidden="true"></span><span class="legend-text">External</span></span>
            <span class="legend-item"><span class="legend-dot legend-blocked" aria-hidden="true"></span><span class="legend-text">Blocked</span></span>
            <span class="legend-item"><span class="legend-dot legend-special-pricing" aria-hidden="true"></span><span class="legend-text">Special Pricing</span></span>
            <span class="legend-item"><span class="legend-dot legend-private-only" aria-hidden="true"></span><span class="legend-text">Private Only</span></span>
        </div>
        <?php
    }

    /**
     * Render rooms table
     */
    private function renderRoomsTable() {
        ?>
        <table class="aiohm-bookings-calendar-rooms widefat">
            <thead>
                <tr>
                    <th><?php esc_html_e('Accommodation', 'aiohm-booking-mvp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($this->room_posts)) : ?>
                    <?php foreach ($this->room_posts as $room_post) : ?>
                        <tr>
                            <td>
                                <a href="#" class="aiohm-room-link" data-room-id="<?php echo esc_attr($room_post->ID); ?>">
                                    <?php echo esc_html($room_post->post_title); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td><?php esc_html_e('No rooms configured', 'aiohm-booking-mvp'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render main calendar table
     */
    private function renderCalendarTable() {
        ?>
        <table class="aiohm-bookings-date-table widefat">
            <thead>
                <?php $this->renderCalendarTableHeader(); ?>
            </thead>
            <tbody>
                <?php if (!empty($this->room_posts)) : ?>
                    <?php foreach ($this->room_posts as $room_post) : ?>
                        <tr room-id="<?php echo esc_attr($room_post->ID); ?>">
                            <?php
                            foreach ($this->period_array as $date) {
                                $this->renderCalendarCell($room_post->ID, $date);
                            }
                            ?>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td class="aiohm-no-rooms-found" colspan="<?php echo esc_attr(count($this->period_array) * 2); ?>">
                            <?php esc_html_e('No rooms configured.', 'aiohm-booking-mvp'); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render calendar table header
     */
    private function renderCalendarTableHeader() {
        ?>
        <tr>
            <?php foreach ($this->period_array as $date) : ?>
                <?php
                $is_today = $date->format('Y-m-d') === current_time('Y-m-d');
                $header_class = $is_today ? 'aiohm-date-today' : '';
                ?>
                <th colspan="2" class="<?php echo esc_attr($header_class); ?>">
                    <div class="aiohm-date-header">
                        <span class="aiohm-date-main"><?php echo esc_html($date->format('j M')); ?></span>
                        <span class="aiohm-date-year"><?php echo esc_html($date->format('Y')); ?></span>
                        <span class="aiohm-date-weekday"><?php echo esc_html($date->format('D')); ?></span>
                    </div>
                </th>
            <?php endforeach; ?>
        </tr>
        <?php
    }

    /**
     * Render individual calendar cell (two half-day cells)
     *
     * @param int $room_id Room identifier
     * @param DateTime $date Cell date
     */
    private function renderCalendarCell($room_id, $date) {
        $cell_classes = $this->calculateCellClasses($room_id, $date);
        $cell_content = $this->generateCellContent($room_id, $date);
        $cell_titles = $this->generateCellTitles($room_id, $date);
        $custom_price = $date_details['custom_price'] ?? '';

        $edit_attributes = '';
        if (current_user_can('manage_options')) {
            $edit_attributes = ' data-editable="true"';
            $cell_classes['first'] .= ' aiohm-editable-cell';
            $cell_classes['second'] .= ' aiohm-editable-cell';
        }

        $date_string = $date->format('Y-m-d');
        ?>
        <td class="aiohm-date-first-part<?php echo esc_attr($cell_classes['first']); ?>"
            data-room-id="<?php echo esc_attr($room_id); ?>"
            data-date="<?php echo esc_attr($date_string); ?>"
            data-part="first"
            data-price="<?php echo esc_attr($custom_price); ?>"
            title="<?php echo esc_attr($cell_titles['first']); ?>"<?php echo wp_kses($edit_attributes, array()); // Safe data attribute ?>>
            <?php echo wp_kses_post($cell_content['first']); // Allow safe HTML for booking links ?>
        </td>
        <td class="aiohm-date-second-part<?php echo esc_attr($cell_classes['second']); ?>"
            data-room-id="<?php echo esc_attr($room_id); ?>"
            data-date="<?php echo esc_attr($date_string); ?>"
            data-part="second"
            data-price="<?php echo esc_attr($custom_price); ?>"
            title="<?php echo esc_attr($cell_titles['second']); ?>"<?php echo wp_kses($edit_attributes, array()); // Safe data attribute ?>>
            <?php echo wp_kses_post($cell_content['second']); // Allow safe HTML for booking links ?>
        </td>
        <?php
    }

    /**
     * Calculate CSS classes for calendar cell
     *
     * @param int $room_id Room identifier
     * @param DateTime $date Cell date
     * @return array Cell classes for first and second parts
     */
    private function calculateCellClasses($room_id, $date) {
        $classes = array('first' => '', 'second' => '');

        $date_details = $this->getRoomDateDetails($room_id, $date);
        $previous_details = $this->getPreviousDayDetails($room_id, $date);

        $is_today = $date->format('Y-m-d') === current_time('Y-m-d');
        if ($is_today) {
            $classes['first'] .= ' aiohm-date-today';
            $classes['second'] .= ' aiohm-date-today';
        }

        // Apply carry-over coloring from previous day
        $this->applyCarryOverClasses($classes, $previous_details);

        // Apply current day classes
        $this->applyCurrentDayClasses($classes, $date_details);

        return $classes;
    }

    /**
     * Get previous day details for carry-over logic
     *
     * @param int $room_id Room identifier
     * @param DateTime $date Current date
     * @return array Previous day details
     */
    private function getPreviousDayDetails($room_id, $date) {
        $previous_date = clone $date;
        $previous_date->modify('-1 day');
        return $this->getRoomDateDetails($room_id, $previous_date);
    }

    /**
     * Apply carry-over classes from previous day
     *
     * @param array &$classes Cell classes array (by reference)
     * @param array $previous_details Previous day details
     */
    private function applyCarryOverClasses(&$classes, $previous_details) {
        if (empty($previous_details)) {
            return;
        }

        // Carry-over from booking in progress
        if (!empty($previous_details['is_locked'])
            && empty($previous_details['is_check_in'])
            && empty($previous_details['is_check_out'])) {

            $classes['first'] .= ' aiohm-date-room-locked';
            $previous_status = $previous_details['booking_status'] ?? '';

            if ($previous_status === 'pending') {
                $classes['first'] .= ' aiohm-date-pending';
            } else {
                $classes['first'] .= ' aiohm-date-booked';
            }
        }

        // Carry-over from admin status
        if (!empty($previous_details['is_blocked'])) {
            $admin_status = $previous_details['admin_status'] ?? 'blocked';
            $classes['first'] .= ' aiohm-date-room-locked aiohm-date-' . esc_attr($admin_status);

            if ($admin_status === 'blocked') {
                $classes['first'] .= ' aiohm-date-blocked';
            }
        }
    }

    /**
     * Apply current day classes
     *
     * @param array &$classes Cell classes array (by reference)
     * @param array $date_details Current date details
     */
    private function applyCurrentDayClasses(&$classes, $date_details) {
        // Admin status classes
        if (!empty($date_details['is_blocked'])) {
            $admin_status = $date_details['admin_status'] ?? 'blocked';
            $classes['second'] .= " aiohm-date-room-locked aiohm-date-{$admin_status}";

            if ($admin_status === 'blocked') {
                $classes['second'] .= ' aiohm-date-blocked';
            }
        }

        // Private booking classes
        if (!empty($date_details['is_private'])) {
            $classes['second'] .= ' aiohm-date-private';
        }

        // Booking status classes
        if (!empty($date_details['is_locked'])) {
            $this->applyBookingStatusClasses($classes, $date_details);
        } elseif (empty($date_details['is_blocked'])) {
            $this->applyFreeStatusClasses($classes, $date_details);
        }

        // Private booking additional classes
        if (!empty($date_details['is_private'])) {
            $classes['second'] .= ' aiohm-date-room-locked aiohm-date-private';
        }

        // Private event classes (special pricing or private only)
        if (!empty($date_details['has_private_event'])) {
            $mode = $date_details['private_event_mode'] ?? 'private_only';
            
            if ($mode === 'special_pricing') {
                // Special pricing - orange styling, not locked
                $classes['first'] .= ' aiohm-date-special-pricing';
                $classes['second'] .= ' aiohm-date-special-pricing';
            } else {
                // Private only - blue styling, locked for individual bookings
                $classes['first'] .= ' aiohm-date-private-only';
                $classes['second'] .= ' aiohm-date-private-only aiohm-date-room-locked';
            }
        }
    }

    /**
     * Apply booking status classes
     *
     * @param array &$classes Cell classes array (by reference)
     * @param array $date_details Date details
     */
    private function applyBookingStatusClasses(&$classes, $date_details) {
        $booking_status = $date_details['booking_status'] ?? '';
        $is_middle_day = empty($date_details['is_check_in']) && empty($date_details['is_check_out']);

        if ($is_middle_day) {
            $classes['second'] .= ' aiohm-date-room-locked';
            $status_class = ($booking_status === 'pending') ? 'aiohm-date-pending' : 'aiohm-date-booked';
            $classes['second'] .= ' ' . $status_class;
        }

        // Check-in styling (second half)
        if (!empty($date_details['is_check_in'])) {
            $classes['second'] .= ' aiohm-date-room-locked aiohm-date-check-in';
            $status_class = ($booking_status === 'pending') ? 'aiohm-date-pending' : 'aiohm-date-booked';
            $classes['second'] .= ' ' . $status_class;
        }

        // Check-out styling (first half)
        if (!empty($date_details['is_check_out'])) {
            $classes['first'] .= ' aiohm-date-room-locked aiohm-date-check-out';
            $checkout_status = $date_details['check_out_booking_status'] ?? $booking_status;
            $status_class = ($checkout_status === 'pending') ? 'aiohm-date-check-out-pending' : 'aiohm-date-check-out-booked';
            $classes['first'] .= ' ' . $status_class;
        }
    }

    /**
     * Apply free status classes
     *
     * @param array &$classes Cell classes array (by reference)
     * @param array $date_details Date details
     */
    private function applyFreeStatusClasses(&$classes, $date_details) {
        $classes['first'] .= ' aiohm-date-free';
        $classes['second'] .= ' aiohm-date-free';

        // Handle check-out on free days
        if (!empty($date_details['is_check_out'])) {
            $classes['first'] .= ' aiohm-date-check-out aiohm-date-room-locked';
            $checkout_status = $date_details['check_out_booking_status'] ?? $date_details['booking_status'] ?? '';
            $status_class = ($checkout_status === 'pending') ? 'aiohm-date-check-out-pending' : 'aiohm-date-check-out-booked';
            $classes['first'] .= ' ' . $status_class;
        }
    }

    /**
     * Generate cell content (links, booking IDs, etc.)
     *
     * @param int $room_id Room identifier
     * @param DateTime $date Cell date
     * @return array Cell content for first and second parts
     */
    private function generateCellContent($room_id, $date) {
        $content = array('first' => '', 'second' => '');
        $date_details = $this->getRoomDateDetails($room_id, $date);

        // Add booking links for middle booking days
        if (!empty($date_details['is_locked'])
            && empty($date_details['is_check_in'])
            && empty($date_details['is_check_out'])) {
            $booking_id = $date_details['booking_id'];
            $content['second'] = '<a class="aiohm-silent-link-to-booking" href="?page=aiohm-booking-mvp-orders&booking_id=' . esc_attr($booking_id) . '"></a>';
        }

        // Add check-in booking links
        if (!empty($date_details['is_check_in']) && !empty($date_details['booking_id'])) {
            $booking_id = $date_details['booking_id'];
            $content['second'] = '<a class="aiohm-booking-link" href="?page=aiohm-booking-mvp-orders&booking_id=' . esc_attr($booking_id) . '">' . esc_html($booking_id) . '</a>';
        }

        return $content;
    }

    /**
     * Generate cell titles for hover information
     *
     * @param int $room_id Room identifier
     * @param DateTime $date Cell date
     * @return array Cell titles for first and second parts
     */
    private function generateCellTitles($room_id, $date) {
        $date_details = $this->getRoomDateDetails($room_id, $date);
        $titles = array();

        if ($date_details['is_check_out']) {
            /* translators: %d is the booking ID number */
            $titles[] = sprintf(__('Check-out #%d', 'aiohm-booking-mvp'), (int)$date_details['check_out_booking_id']);
        }

        if ($date_details['is_check_in']) {
            /* translators: %d is the booking ID number */
            $titles[] = sprintf(__('Check-in #%d', 'aiohm-booking-mvp'), (int)$date_details['booking_id']);
        }

        if ($date_details['is_locked'] && !($date_details['is_check_in'] || $date_details['is_check_out'])) {
            /* translators: %d is the booking ID number */
            $titles[] = sprintf(__('Booking #%d', 'aiohm-booking-mvp'), (int)$date_details['booking_id']);
        } elseif (!$date_details['is_locked'] && !$date_details['is_check_out']) {
            $titles[] = __('Available', 'aiohm-booking-mvp');
        }

        $date_string = $date->format('D j, M Y:');
        $availability_string = implode(', ', $titles);
        $base_title = $date_string . ' ' . $availability_string;

        $additional_info = $this->generateAdditionalTitleInfo($date_details);
        $complete_title = $base_title;

        if (!empty($additional_info)) {
            $complete_title .= '&#10;' . implode('&#10;', $additional_info);
        }

        return array(
            'first' => $complete_title,
            'second' => $complete_title
        );
    }

    /**
     * Generate additional title information
     *
     * @param array $date_details Date details
     * @return array Additional information lines
     */
    private function generateAdditionalTitleInfo($date_details) {
        $info = array();

        if (isset($date_details['buyer_name'])) {
            $info[] = 'Customer: ' . $date_details['buyer_name'];
        }

        if (isset($date_details['buyer_email'])) {
            $info[] = 'Email: ' . $date_details['buyer_email'];
        }

        if (isset($date_details['order_mode'])) {
            $info[] = 'Mode: ' . ucfirst($date_details['order_mode']);
        }

        if (isset($date_details['rooms_qty'])) {
            /* translators: %d is the number of rooms */
            $info[] = sprintf(__('Rooms: %d', 'aiohm-booking-mvp'), $date_details['rooms_qty']);
        }


        if (isset($date_details['total_amount'])) {
            /* translators: %1$s is the currency code, %2$s is the formatted amount */
            $info[] = sprintf(__('Total: %1$s %2$s', 'aiohm-booking-mvp'),
                            $date_details['currency'],
                            number_format($date_details['total_amount'], 2));
        }

        if (!empty($date_details['is_private'])) {
            $info[] = __('Private booking (all rooms)', 'aiohm-booking-mvp');
        }

        return $info;
    }

    /**
     * Render booking details popup
     */
    private function renderBookingDetailsPopup() {
        ?>
        <div id="aiohm-bookings-calendar-popup" class="aiohm-popup aiohm-hide">
            <div class="aiohm-popup-backdrop"></div>
            <div class="aiohm-popup-body">
                <div class="aiohm-header">
                    <h2 class="aiohm-title aiohm-inline"><?php esc_html_e('Booking Details', 'aiohm-booking-mvp'); ?></h2>
                    <button class="aiohm-close-popup-button dashicons dashicons-no-alt"></button>
                </div>
                <div class="aiohm-content"></div>
                <div class="aiohm-footer">
                    <a href="#" class="button button-primary aiohm-edit-button"><?php esc_html_e('View Order', 'aiohm-booking-mvp'); ?></a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render footer filter controls
     */
    private function renderFooterFilters() {
        ?>
        <div class="aiohm-bookings-calendar-footer-wrapper">
            <div class="aiohm-bookings-calendar-controls">
                <div class="aiohm-filter-group">
                    <label for="aiohm-calendar-status-filter"><?php esc_html_e('Filter by Status:', 'aiohm-booking-mvp'); ?></label>
                    <select id="aiohm-calendar-status-filter" class="aiohm-status-filter">
                        <option value=""><?php esc_html_e('Show All', 'aiohm-booking-mvp'); ?></option>
                        <option value="free"><?php esc_html_e('Free/Available', 'aiohm-booking-mvp'); ?></option>
                        <option value="booked"><?php esc_html_e('Booked', 'aiohm-booking-mvp'); ?></option>
                        <option value="pending"><?php esc_html_e('Pending', 'aiohm-booking-mvp'); ?></option>
                        <option value="external"><?php esc_html_e('External', 'aiohm-booking-mvp'); ?></option>
                        <option value="blocked"><?php esc_html_e('Blocked', 'aiohm-booking-mvp'); ?></option>
                    </select>
                    <button type="button" id="aiohm-calendar-search-btn" class="button aiohm-search-button">
                        <?php esc_html_e('Filter Calendar', 'aiohm-booking-mvp'); ?>
                    </button>
                    <button type="button" id="aiohm-calendar-reset-btn" class="button aiohm-reset-button">
                        <?php esc_html_e('Show All', 'aiohm-booking-mvp'); ?>
                    </button>
                    <button type="button" id="aiohm-calendar-reset-all-days-btn" class="button aiohm-reset-all-days-button" style="margin-left: 10px; background-color: #dc3545; color: white; border-color: #dc3545;">
                        <?php esc_html_e('Reset All Days', 'aiohm-booking-mvp'); ?>
                    </button>
                </div>
            </div>
            
            <!-- Special Event Management Section -->
            <div class="aiohm-special-events-section" style="margin-top: 20px; padding: 20px; background: #f9f9f9; border-radius: 8px;">
                <h4 style="margin-top: 0;"><?php esc_html_e('Private Event Management', 'aiohm-booking-mvp'); ?></h4>
                <p style="color: #666; margin-bottom: 20px;"><?php esc_html_e('Block entire property for private events. When a day is set as private event, only full property bookings are allowed.', 'aiohm-booking-mvp'); ?></p>
                
                <div class="aiohm-special-events-layout" style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start;">
                    <!-- Left Column: Settings -->
                    <div class="aiohm-special-events-settings">
                        <h5 style="margin-top: 0; margin-bottom: 15px; color: #333; border-bottom: 2px solid #6f42c1; padding-bottom: 5px; display: inline-block;"><?php esc_html_e('Event Settings', 'aiohm-booking-mvp'); ?></h5>
                        
                        <div class="aiohm-special-event-form">
                            <div class="aiohm-form-group" style="margin-bottom: 15px;">
                                <label for="aiohm-special-event-date" style="display: block; margin-bottom: 5px; font-weight: 600;"><?php esc_html_e('Event Date:', 'aiohm-booking-mvp'); ?></label>
                                <input type="date" id="aiohm-special-event-date" class="aiohm-date-input" style="padding: 8px; border-radius: 4px; border: 1px solid #ddd; width: 100%;">
                            </div>
                            
                            <div class="aiohm-form-group" style="margin-bottom: 15px;">
                                <label for="aiohm-special-event-price" style="display: block; margin-bottom: 5px; font-weight: 600;"><?php esc_html_e('Price:', 'aiohm-booking-mvp'); ?></label>
                                <input type="number" id="aiohm-special-event-price" class="aiohm-price-input" placeholder="0.00" step="0.01" min="0" style="padding: 8px; border-radius: 4px; border: 1px solid #ddd; width: 100%;">
                            </div>
                            
                            <div class="aiohm-form-group" style="margin-bottom: 15px;">
                                <label for="aiohm-special-event-name" style="display: block; margin-bottom: 5px; font-weight: 600;"><?php esc_html_e('Event Name:', 'aiohm-booking-mvp'); ?></label>
                                <input type="text" id="aiohm-special-event-name" class="aiohm-event-name" placeholder="<?php esc_attr_e('Special Event', 'aiohm-booking-mvp'); ?>" maxlength="50" style="padding: 8px; border-radius: 4px; border: 1px solid #ddd; width: 100%;">
                            </div>
                            
                            <div class="aiohm-form-group" style="margin-bottom: 20px;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 600;"><?php esc_html_e('Booking Mode:', 'aiohm-booking-mvp'); ?></label>
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    <label style="display: flex; align-items: flex-start; gap: 8px; font-weight: normal; padding: 8px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; transition: all 0.2s;">
                                        <input type="radio" id="aiohm-event-mode-private" name="aiohm-event-mode" value="private_only" checked style="margin: 2px 0 0 0; flex-shrink: 0;">
                                        <div>
                                            <span style="font-weight: 600; color: #1565c0;"><?php esc_html_e('Private Event Only', 'aiohm-booking-mvp'); ?></span>
                                            <div style="font-size: 12px; color: #666; margin-top: 2px;"><?php esc_html_e('Blocks individual rooms, full property only', 'aiohm-booking-mvp'); ?></div>
                                        </div>
                                    </label>
                                    <label style="display: flex; align-items: flex-start; gap: 8px; font-weight: normal; padding: 8px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; transition: all 0.2s;">
                                        <input type="radio" id="aiohm-event-mode-special" name="aiohm-event-mode" value="special_pricing" style="margin: 2px 0 0 0; flex-shrink: 0;">
                                        <div>
                                            <span style="font-weight: 600; color: #e65100;"><?php esc_html_e('Special Pricing', 'aiohm-booking-mvp'); ?></span>
                                            <div style="font-size: 12px; color: #666; margin-top: 2px;"><?php esc_html_e('Individual rooms at special price', 'aiohm-booking-mvp'); ?></div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="aiohm-form-actions" style="display: flex; flex-direction: column; gap: 10px;">
                                <button type="button" id="aiohm-set-private-event-btn" class="button button-primary" style="background-color: #6f42c1; border-color: #6f42c1; width: 100%; padding: 10px;">
                                    <?php esc_html_e('Set Event', 'aiohm-booking-mvp'); ?>
                                </button>
                                
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column: Current Events -->
                    <div class="aiohm-special-events-display">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h5 style="margin: 0; color: #333; border-bottom: 2px solid #457d58; padding-bottom: 5px; display: inline-block;"><?php esc_html_e('Current Events', 'aiohm-booking-mvp'); ?></h5>
                            <span id="aiohm-events-count" style="background: #457d58; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 600;"></span>
                        </div>
                        
                        <div class="aiohm-private-events-status" style="background: white; border-radius: 4px; border: 1px solid #ddd; min-height: 200px;">
                            <div id="aiohm-private-events-list" style="padding: 15px;">
                                <?php $this->renderCurrentPrivateEvents(); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render current private events list
     */
    private function renderCurrentPrivateEvents() {
        $private_events = get_option('aiohm_booking_mvp_private_events', []);
        
        if (empty($private_events)) {
            echo '<em style="color: #666;">' . esc_html__('No private events currently set.', 'aiohm-booking-mvp') . '</em>';
            return;
        }

        // Sort events by date
        ksort($private_events);
        
        $event_count = count($private_events);
        $scroll_class = $event_count > 5 ? 'aiohm-events-scroll' : '';
        
        echo '<div class="aiohm-private-events-grid ' . $scroll_class . '" style="display: grid; grid-template-columns: 1fr; gap: 8px;">';
        
        foreach ($private_events as $date => $event) {
            $date_obj = new DateTime($date);
            $formatted_date = $date_obj->format('M j, Y');
            $price = !empty($event['price']) ? number_format_i18n(floatval($event['price']), 2) : '0.00';
            $currency = get_option('aiohm_booking_mvp_settings', [])['currency'] ?? 'USD';
            $event_name = !empty($event['name']) ? esc_html($event['name']) : esc_html__('Private Event', 'aiohm-booking-mvp');
            $mode = $event['mode'] ?? 'private_only';
            
            // Different styling based on mode
            $bg_color = $mode === 'special_pricing' ? '#fff3e0' : '#e3f2fd';
            $border_color = $mode === 'special_pricing' ? '#ff9800' : '#2196f3';
            $text_color = $mode === 'special_pricing' ? '#e65100' : '#1565c0';
            $mode_label = $mode === 'special_pricing' ? esc_html__('Special Pricing', 'aiohm-booking-mvp') : esc_html__('Private Only', 'aiohm-booking-mvp');
            
            echo '<div class="aiohm-private-event-item" style="padding: 8px 12px; background: ' . esc_attr($bg_color) . '; border-radius: 4px; border-left: 4px solid ' . esc_attr($border_color) . '; position: relative;">';
            echo '<button class="aiohm-remove-event-btn" data-date="' . esc_attr($date) . '" style="position: absolute; top: 5px; right: 5px; background: #dc3545; color: white; border: none; border-radius: 50%; width: 20px; height: 20px; font-size: 12px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0;" title="' . esc_attr__('Remove Event', 'aiohm-booking-mvp') . '">×</button>';
            echo '<div style="font-weight: 600; color: ' . esc_attr($text_color) . '; padding-right: 25px;">' . esc_html($formatted_date) . '</div>';
            echo '<div style="color: #424242; font-size: 14px; margin-top: 2px; padding-right: 25px;">' . $event_name . '</div>';
            echo '<div style="color: #666; font-size: 13px; margin-top: 2px; padding-right: 25px;">' . esc_html($price) . ' ' . esc_html($currency) . ' • ' . $mode_label . '</div>';
            echo '</div>';
        }
        
        echo '</div>';
        
        // Update the event count display
        echo '<script>';
        echo 'document.addEventListener("DOMContentLoaded", function() {';
        echo 'const countElement = document.getElementById("aiohm-events-count");';
        echo 'if (countElement) countElement.textContent = "' . count($private_events) . '";';
        echo '});';
        echo '</script>';
    }

    /**
     * Render external calendar sync modules
     */
    private function renderSyncModules() {
        $settings = get_option('aiohm_booking_mvp_settings', []);
        $sync_configuration = $this->getSyncConfiguration($settings);
        ?>
        <div class="aiohm-calendar-modules">
            <div class="aiohm-module-grid">
                <?php $this->renderBookingSyncModule($sync_configuration['booking']); ?>
                <?php $this->renderAirbnbSyncModule($sync_configuration['airbnb']); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render AI Table Insights section
     */
    private function renderAITableInsights() {
        // Get default AI provider and map to display name
        $settings = aiohm_booking_mvp_opts();
        $default_provider = $settings['default_ai_provider'] ?? 'shareai';
        
        $provider_names = [
            'shareai' => 'ShareAI',
            'openai' => 'OpenAI',
            'gemini' => 'Google Gemini'
        ];
        
        $provider_display_name = $provider_names[$default_provider] ?? 'AI';
        ?>
        <div class="aiohm-booking-ai-query">
            <h3>AI Table Insights</h3>
            <p>Ask natural language questions about your booking database structure, data patterns, and business insights.</p>
            
            <div class="aiohm-ai-query-interface">
                <div class="aiohm-query-input-section">
                    <div class="aiohm-query-input-wrapper">
                        <textarea id="ai-table-query-input" placeholder="Ask questions like: 'How many orders do I have this month?' or 'What's the structure of the booking tables?' or 'Which payment methods are most popular?'" rows="3"></textarea>
                        <button type="button" id="submit-ai-table-query" class="button button-primary">
                            Ask
                        </button>
                    </div>
                    <div class="aiohm-query-examples">
                        <small><strong>Example questions:</strong></small>
                        <ul class="aiohm-example-queries">
                            <li><a href="#" data-query="What tables exist in my booking database and what do they contain?">📊 Database structure overview</a></li>
                            <li><a href="#" data-query="How many bookings do I have and what are the different statuses?">📈 Booking statistics</a></li>
                            <li><a href="#" data-query="What information is stored for each order?">🔍 Order details schema</a></li>
                            <li><a href="#" data-query="How can I analyze my booking trends and customer data?">📊 Business insights guidance</a></li>
                        </ul>
                    </div>
                </div>
                
                <div id="ai-table-response-area" class="aiohm-ai-response-area" style="display: none;">
                    <div class="aiohm-response-header">
                        <h4>AI Response</h4>
                        <span class="aiohm-provider-badge"></span>
                    </div>
                    <div class="aiohm-response-content">
                        <div class="aiohm-response-card">
                            <div id="ai-response-text"></div>
                        </div>
                    </div>
                    <div class="aiohm-response-actions">
                        <button type="button" id="copy-ai-response" class="button button-secondary">
                            <span class="dashicons dashicons-clipboard"></span>
                            Copy Response
                        </button>
                        <button type="button" id="clear-ai-response" class="button button-secondary">
                            <span class="dashicons dashicons-dismiss"></span>
                            Clear
                        </button>
                    </div>
                </div>
                
                <div id="ai-query-loading" class="aiohm-loading-indicator" style="display: none;">
                    <div class="aiohm-loading-spinner"></div>
                    <span>AI is analyzing your database...</span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get sync configuration settings
     *
     * @param array $settings Plugin settings
     * @return array Sync configuration
     */
    private function getSyncConfiguration($settings) {
        $cron_frequencies = $this->getAvailableCronFrequencies();

        return array(
            'booking' => array(
                'ical_url' => esc_url($settings['booking_com_ical_url'] ?? ''),
                'cron_frequency' => $settings['booking_com_cron_frequency'] ?? 'hourly'
            ),
            'airbnb' => array(
                'ical_url' => esc_url($settings['airbnb_ical_url'] ?? ''),
                'cron_frequency' => $settings['airbnb_cron_frequency'] ?? 'hourly'
            ),
            'frequencies' => $cron_frequencies
        );
    }

    /**
     * Get available cron frequencies
     *
     * @return array Available cron frequency options
     */
    private function getAvailableCronFrequencies() {
        return array(
            'every_5min' => __('Every 5 minutes', 'aiohm-booking-mvp'),
            'every_15min' => __('Every 15 minutes', 'aiohm-booking-mvp'),
            'every_30min' => __('Every 30 minutes', 'aiohm-booking-mvp'),
            'hourly' => __('Every hour', 'aiohm-booking-mvp'),
            'every_2hours' => __('Every 2 hours', 'aiohm-booking-mvp'),
            'every_6hours' => __('Every 6 hours', 'aiohm-booking-mvp'),
            'twicedaily' => __('Twice daily', 'aiohm-booking-mvp'),
            'daily' => __('Once daily', 'aiohm-booking-mvp'),
            'disabled' => __('Manual only', 'aiohm-booking-mvp')
        );
    }

    /**
     * Render Booking.com sync module
     *
     * @param array $config Booking sync configuration
     */
    private function renderBookingSyncModule($config) {
        $cron_frequencies = $this->getAvailableCronFrequencies();
        ?>
        <div class="aiohm-module-card is-active">
            <div class="aiohm-module-header">
                <h3><?php esc_html_e('Booking.com Sync', 'aiohm-booking-mvp'); ?></h3>
            </div>
            <p class="aiohm-module-description">
                <?php esc_html_e('Synchronize your calendar with Booking.com using iCal feed. Set your preferred sync frequency or sync manually.', 'aiohm-booking-mvp'); ?>
            </p>

            <div class="aiohm-module-settings">
                <form id="aiohm-booking-sync-form" method="post">
                    <?php wp_nonce_field('aiohm_save_booking_sync_settings', 'aiohm_booking_sync_nonce'); ?>

                    <div class="aiohm-setting-row">
                        <label for="booking_com_ical_url"><?php esc_html_e('Booking.com iCal URL', 'aiohm-booking-mvp'); ?></label>
                        <input type="url"
                               id="booking_com_ical_url"
                               name="booking_com_ical_url"
                               value="<?php echo esc_url($config['ical_url']); ?>"
                               placeholder="https://admin.booking.com/hotel/hoteladmin/ical/..." />
                    </div>

                    <div class="aiohm-setting-row">
                        <label for="booking_com_cron_frequency"><?php esc_html_e('Auto-Sync Frequency', 'aiohm-booking-mvp'); ?></label>
                        <select id="booking_com_cron_frequency" name="booking_com_cron_frequency">
                            <?php foreach ($cron_frequencies as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($config['cron_frequency'], $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="aiohm-calendar-module-actions">
                        <button type="submit" name="action" value="save_booking_sync" class="button button-primary">
                            <?php esc_html_e('Save Settings', 'aiohm-booking-mvp'); ?>
                        </button>
                        <button type="button" id="aiohm-sync-booking-btn" class="button button-secondary">
                            <?php esc_html_e('Sync Now', 'aiohm-booking-mvp'); ?>
                        </button>
                        <span id="aiohm-booking-sync-status" class="aiohm-calendar-module-status"></span>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render Airbnb sync module
     *
     * @param array $config Airbnb sync configuration
     */
    private function renderAirbnbSyncModule($config) {
        $cron_frequencies = $this->getAvailableCronFrequencies();
        ?>
        <div class="aiohm-module-card is-active">
            <div class="aiohm-module-header">
                <h3><?php esc_html_e('Airbnb Sync', 'aiohm-booking-mvp'); ?></h3>
            </div>
            <p class="aiohm-module-description">
                <?php esc_html_e('Synchronize your calendar with Airbnb using iCal feed. Import blocked dates and reservations with your preferred sync frequency.', 'aiohm-booking-mvp'); ?>
            </p>

            <div class="aiohm-module-settings">
                <form id="aiohm-airbnb-sync-form" method="post">
                    <?php wp_nonce_field('aiohm_save_airbnb_sync_settings', 'aiohm_airbnb_sync_nonce'); ?>

                    <div class="aiohm-setting-row">
                        <label for="airbnb_ical_url"><?php esc_html_e('Airbnb iCal URL', 'aiohm-booking-mvp'); ?></label>
                        <input type="url"
                               id="airbnb_ical_url"
                               name="airbnb_ical_url"
                               value="<?php echo esc_url($config['ical_url']); ?>"
                               placeholder="https://www.airbnb.com/calendar/ical/..." />
                    </div>

                    <div class="aiohm-setting-row">
                        <label for="airbnb_cron_frequency"><?php esc_html_e('Auto-Sync Frequency', 'aiohm-booking-mvp'); ?></label>
                        <select id="airbnb_cron_frequency" name="airbnb_cron_frequency">
                            <?php foreach ($cron_frequencies as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($config['cron_frequency'], $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="aiohm-calendar-module-actions">
                        <button type="submit" name="action" value="save_airbnb_sync" class="button button-primary">
                            <?php esc_html_e('Save Settings', 'aiohm-booking-mvp'); ?>
                        </button>
                        <button type="button" id="aiohm-sync-airbnb-btn" class="button button-secondary">
                            <?php esc_html_e('Sync Now', 'aiohm-booking-mvp'); ?>
                        </button>
                        <span id="aiohm-airbnb-sync-status" class="aiohm-calendar-module-status"></span>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Check if calendar has sufficient data for rendering
     *
     * @return bool Whether calendar can be rendered
     */
    public static function hasSufficientFilterData() {
        return true; // Always show calendar with default 7-day view
    }
}
