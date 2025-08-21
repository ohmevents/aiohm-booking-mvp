<?php
if ( ! defined('ABSPATH') ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// Reason: This class accesses custom booking tables for shortcode data display
class AIOHM_BOOKING_MVP_Shortcodes {
    public static function init(){
        add_shortcode('aiohm_booking_mvp',[__CLASS__,'sc_widget']);
        add_shortcode('aiohm_booking',[__CLASS__,'sc_widget']); // Shorter alias
        add_shortcode('aiohm_booking_mvp_checkout',[__CLASS__,'sc_checkout']);
        add_action('wp_enqueue_scripts',[__CLASS__,'assets']);
    }
    public static function assets(){
        wp_register_style('aiohm-booking-mvp', aiohm_booking_mvp_asset_url('css/aiohm-booking-mvp-style.css'),[],AIOHM_BOOKING_MVP_VERSION);
        wp_enqueue_style('aiohm-booking-mvp');
        wp_register_script('aiohm-booking-mvp', aiohm_booking_mvp_asset_url('js/aiohm-booking-mvp-app.js'),['jquery', 'wp-element'],AIOHM_BOOKING_MVP_VERSION,true);
        wp_localize_script('aiohm-booking-mvp','AIOHM_BOOKING',[
            'rest' => esc_url_raw( rest_url('aiohm-booking-mvp/v1') ),
            'nonce' => wp_create_nonce('wp_rest'),
            'checkout_url' => aiohm_booking_mvp_opt('checkout_page_url', ''),
            'thankyou_url' => aiohm_booking_mvp_opt('thankyou_page_url', ''),
            'i18n' => [
                'unexpectedError' => esc_html__('An unexpected error occurred. Please try again.', 'aiohm-booking-mvp'),
                'requestTimeout' => esc_html__('Request timed out. Please check your connection and try again.', 'aiohm-booking-mvp'),
                'enterFullName' => esc_html__('Please enter your full name', 'aiohm-booking-mvp'),
                'enterEmail' => esc_html__('Please enter your email address', 'aiohm-booking-mvp'),
                'invalidEmail' => esc_html__('Please enter a valid email address (e.g., name@example.com)', 'aiohm-booking-mvp'),
                'enterAge' => esc_html__('Please enter your age', 'aiohm-booking-mvp'),
                // translators: %d is the minimum age requirement
                'ageMin' => esc_html__('Age must be at least %d years old', 'aiohm-booking-mvp'),
                'ageMax' => esc_html__('Please enter a valid age (maximum 99)', 'aiohm-booking-mvp'),
                'selectAccommodation' => esc_html__('Please select at least one accommodation or choose "Book Entire Property"', 'aiohm-booking-mvp'),
                'atLeastOneGuest' => esc_html__('Please enter at least one guest', 'aiohm-booking-mvp'),
                'maxGuests' => esc_html__('Maximum number of guests is 20. Please contact us for larger groups.', 'aiohm-booking-mvp'),
                'checkoutAfterCheckin' => esc_html__('Check-out date must be after check-in date', 'aiohm-booking-mvp'),
                'formValidationError' => esc_html__('Form validation error. Please refresh the page and try again.', 'aiohm-booking-mvp'),
                // translators: %s is the deposit percentage
                'depositRequired' => esc_html__('Deposit Required (%s%)', 'aiohm-booking-mvp'),
                'creatingHold' => esc_html__('Creating hold...', 'aiohm-booking-mvp'),
                // translators: %d is the order number
                'holdCreated' => esc_html__('Hold created successfully! Order #%d', 'aiohm-booking-mvp'),
                'invalidResponse' => esc_html__('Invalid response from server', 'aiohm-booking-mvp'),
                'nonceExpired' => esc_html__('Security token expired. Please refresh the page and try again.', 'aiohm-booking-mvp'),
                'systemUnavailable' => esc_html__('System temporarily unavailable. Please try again in a few moments.', 'aiohm-booking-mvp'),
                'holdFailed' => esc_html__('Hold creation failed. Please try again.', 'aiohm-booking-mvp'),
                'monthNames' => [
                    esc_html__('January', 'aiohm-booking-mvp'), esc_html__('February', 'aiohm-booking-mvp'),
                    esc_html__('March', 'aiohm-booking-mvp'), esc_html__('April', 'aiohm-booking-mvp'),
                    esc_html__('May', 'aiohm-booking-mvp'), esc_html__('June', 'aiohm-booking-mvp'),
                    esc_html__('July', 'aiohm-booking-mvp'), esc_html__('August', 'aiohm-booking-mvp'),
                    esc_html__('September', 'aiohm-booking-mvp'), esc_html__('October', 'aiohm-booking-mvp'),
                    esc_html__('November', 'aiohm-booking-mvp'), esc_html__('December', 'aiohm-booking-mvp'),
                ],
                'dayNames' => [
                    esc_html_x('Sun', 'short day name', 'aiohm-booking-mvp'), esc_html_x('Mon', 'short day name', 'aiohm-booking-mvp'),
                    esc_html_x('Tue', 'short day name', 'aiohm-booking-mvp'), esc_html_x('Wed', 'short day name', 'aiohm-booking-mvp'),
                    esc_html_x('Thu', 'short day name', 'aiohm-booking-mvp'), esc_html_x('Fri', 'short day name', 'aiohm-booking-mvp'),
                    esc_html_x('Sat', 'short day name', 'aiohm-booking-mvp'),
                ],
                // translators: %s is the date type (check-in or check-out)
                'selectDate' => esc_html__('Select %s', 'aiohm-booking-mvp'),
                // translators: %1$s is the currency symbol, %2$s is the price amount
                'fromPrice' => esc_html__('from %1$s %2$s', 'aiohm-booking-mvp'),
                // translators: %1$s is the currency symbol, %2$s is the price amount
                'pricePerNight' => esc_html__('%1$s %2$s per night', 'aiohm-booking-mvp'),
                'selectCheckinFirst' => esc_html__('Select check-in first', 'aiohm-booking-mvp'),
            ]
        ]);
        wp_enqueue_script('aiohm-booking-mvp');
    }
    public static function sc_widget($atts=[]){
        $atts = shortcode_atts([
            'type' => 'auto', // auto, rooms, accommodation
            'style' => 'modern' // modern, classic
        ], $atts);

        // Override global settings if specific type is requested - rooms only now
        if ($atts['type'] !== 'auto') {
            $GLOBALS['aiohm_booking_mvp_shortcode_override'] = [
                'enable_rooms' => in_array($atts['type'], ['rooms', 'accommodation'])
            ];
        }

        // Handle dynamic inline styles for text color
        $opts = aiohm_booking_mvp_opts();
        $text_color_raw = $opts['form_text_color'] ?? '';
        $text_color = sanitize_hex_color($text_color_raw);
        $instance_id = 'aiohm-booking-mvp-' . uniqid(); // Generate a unique ID for the widget

        ob_start();

        // Print style tag directly, as shortcode runs too late for wp_add_inline_style
        if (!empty($text_color)) {
            echo wp_kses("<style>
                #{$instance_id} .booking-header .booking-title,
                #{$instance_id} .booking-header .booking-subtitle {
                    color: {$text_color} !important;
                }
            </style>", array('style' => array()));
        }

        if ($atts['style'] === 'classic') {
            include AIOHM_BOOKING_MVP_DIR.'templates/aiohm-booking-mvp-widget-classic.php';
        } else {
            include AIOHM_BOOKING_MVP_DIR.'templates/aiohm-booking-mvp-widget.php';
        }
        return ob_get_clean();
    }
    public static function sc_checkout($atts=[]){
        // Enqueue checkout specific assets
        wp_enqueue_style(
            'aiohm-booking-mvp-checkout',
            aiohm_booking_mvp_asset_url('css/aiohm-booking-mvp-checkout.css'),
            ['aiohm-booking-mvp'],
            AIOHM_BOOKING_MVP_VERSION
        );
        wp_enqueue_script(
            'aiohm-booking-mvp-checkout',
            aiohm_booking_mvp_asset_url('js/aiohm-booking-mvp-checkout.js'),
            [],
            AIOHM_BOOKING_MVP_VERSION,
            true
        );

        // Secure order access with email verification
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_id = absint($_GET['order_id'] ?? 0);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order_email = sanitize_email($_GET['email'] ?? '');
        
        global $wpdb;
        $order = null;
        if($order_id && $order_email){
            // Verify order belongs to the provided email
            $order = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aiohm_booking_mvp_order WHERE id=%d AND buyer_email=%s", 
                $order_id, 
                $order_email
            ) );
        }
        $settings = get_option('aiohm_booking_mvp_settings', []);
        $paypal_client_id = trim((string)($settings['paypal_client_id'] ?? ''));

        wp_localize_script('aiohm-booking-mvp-checkout', 'AIOHM_CHECKOUT_DATA', [
            'order_id' => $order_id,
            'nonce' => wp_create_nonce('wp_rest'),
            'stripe_session_url' => esc_url_raw(rest_url('aiohm-booking-mvp/v1/stripe/session')),
            'paypal_capture_url' => esc_url_raw(rest_url('aiohm-booking-mvp/v1/paypal/capture')),
            'thankyou_url' => esc_url_raw(aiohm_booking_mvp_opt('thankyou_page_url', home_url())),
            'paypal_ready' => !empty($settings['enable_paypal']) && !empty($paypal_client_id),
            'paypal_client_id' => $paypal_client_id,
            'currency' => $order->currency ?? 'USD',
            'deposit_amount' => number_format($order->deposit_amount ?? 0, 2, '.', ''),
            'i18n' => [
                'checkout_error' => esc_html__('Unable to start checkout. Please try again or contact support.', 'aiohm-booking-mvp'),
                'payment_error' => esc_html__('Payment failed. Please try again or contact support.', 'aiohm-booking-mvp'),
            ],
        ]);

        ob_start(); include AIOHM_BOOKING_MVP_DIR.'templates/aiohm-booking-mvp-checkout.php'; return ob_get_clean();
    }
}
