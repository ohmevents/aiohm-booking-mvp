<?php

namespace AIOHM\BookingMVP\API;

use AIOHM\BookingMVP\Core\Settings;
use AIOHM\BookingMVP\Core\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

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
 * @package AIOHM\BookingMVP\API
 * @since   1.0.0
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// Reason: This class manages custom booking tables that require direct database access for real-time booking operations
class API {

	/**
	 * Initialize the API by registering REST routes
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register all REST API routes with proper security callbacks
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			'aiohm-booking-mvp/v1',
			'/hold',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'hold' ),
				'permission_callback' => array( __CLASS__, 'verify_public_nonce' ),
			)
		);
		register_rest_route(
			'aiohm-booking-mvp/v1',
			'/stripe/session',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'stripe_session' ),
				'permission_callback' => array( __CLASS__, 'verify_public_nonce' ),
			)
		);
		register_rest_route(
			'aiohm-booking-mvp/v1',
			'/stripe-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'stripe_webhook' ),
				'permission_callback' => array( __CLASS__, 'verify_webhook' ),
			)
		);
		register_rest_route(
			'aiohm-booking-mvp/v1',
			'/paypal-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'paypal_webhook' ),
				'permission_callback' => array( __CLASS__, 'verify_paypal_webhook' ),
			)
		);
		register_rest_route(
			'aiohm-booking-mvp/v1',
			'/paypal/capture',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'paypal_capture' ),
				'permission_callback' => array( __CLASS__, 'verify_public_nonce' ),
			)
		);
		// Availability for frontend calendar
		register_rest_route(
			'aiohm-booking-mvp/v1',
			'/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'availability' ),
				'permission_callback' => '__return_true', // Public read-only endpoint
			)
		);
		// Admin: toggle block/unblock dates on calendar
		register_rest_route(
			'aiohm-booking-mvp/v1',
			'/calendar/block',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'toggle_block_date' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ); },
			)
		);
	}

	/**
	 * Create a booking hold with validation and pricing calculation
	 *
	 * Validates user input, calculates totals and deposits, creates order record
	 * and triggers calendar sync hooks.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $r REST request object containing booking data
	 * @return \WP_REST_Response|\WP_Error Order details on success, error on failure
	 */
	public static function hold( \WP_REST_Request $r ) {
		try {
			global $wpdb;
			$p    = $r->get_json_params();
			$opts = Config::getPrices();

			// Validate required fields
			$name  = sanitize_text_field( $p['name'] ?? '' );
			$email = sanitize_email( $p['email'] ?? '' );
			if ( empty( $name ) || empty( $email ) ) {
				return new \WP_Error( 'missing_fields', 'Name and email are required', array( 'status' => 400 ) );
			}

			$mode = 'rooms'; // Always rooms only now
			// Rooms
			$rooms_qty = max( 0, intval( $p['rooms_qty'] ?? 0 ) );

			$room_ids = array();
			if ( ! empty( $p['room_ids'] ) && is_array( $p['room_ids'] ) ) {
				foreach ( $p['room_ids'] as $rid ) {
					$rid = intval( $rid );
					if ( $rid > 0 ) {
						$room_ids[] = $rid; }
				}
			}
			$private_all            = ! empty( $p['private_all'] ) ? 1 : 0;
			$guests_qty             = max( 1, intval( $p['guests_qty'] ?? 1 ) );
			$vat_number             = sanitize_text_field( $p['vat_number'] ?? '' );
			$estimated_arrival_time = sanitize_text_field( $p['estimated_arrival_time'] ?? '' );
			$bringing_pets          = ! empty( $p['bringing_pets'] ) ? 1 : 0;
			$pet_details            = $bringing_pets ? sanitize_textarea_field( $p['pet_details'] ?? '' ) : '';

			// Validate minimum age requirement (only if age field is enabled and minimum age is set)
			$age               = intval( $p['age'] ?? 0 );
			$settings          = Settings::getAll();
			$age_field_enabled = ! empty( $settings['form_field_age'] );
			$min_age           = intval( $settings['min_age'] ?? 0 );

			if ( $age_field_enabled && $min_age > 0 && $age < $min_age ) {
				return new \WP_Error( 'age_requirement', "Minimum age requirement is {$min_age} years", array( 'status' => 400 ) );
			}

			// Validate mode settings
			$rooms_enabled = Config::areRoomsEnabled();
			if ( ! $rooms_enabled ) {
				return new \WP_Error( 'rooms_disabled', 'Accommodation booking is not enabled', array( 'status' => 400 ) );
			}

			// Validate quantities
			if ( $rooms_qty <= 0 && ! $private_all ) {
				return new \WP_Error( 'invalid_rooms', 'At least one accommodation must be selected', array( 'status' => 400 ) );
			}

			// Calculate totals - rooms only now
			$check_in_date  = sanitize_text_field( $p['check_in_date'] ?? ( $p['checkin_date'] ?? '' ) ) ?: null;
			$check_out_date = sanitize_text_field( $p['check_out_date'] ?? ( $p['checkout_date'] ?? '' ) ) ?: null;

			// Validate date formats
			if ( ! $check_in_date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $check_in_date ) || ! strtotime( $check_in_date ) ) {
				return new \WP_Error( 'invalid_checkin', 'Invalid check-in date format. Expected YYYY-MM-DD', array( 'status' => 400 ) );
			}
			if ( ! $check_out_date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $check_out_date ) || ! strtotime( $check_out_date ) ) {
				return new \WP_Error( 'invalid_checkout', 'Invalid check-out date format. Expected YYYY-MM-DD', array( 'status' => 400 ) );
			}

			// Validate date logic
			if ( strtotime( $check_in_date ) >= strtotime( $check_out_date ) ) {
				return new \WP_Error( 'invalid_date_range', 'Check-out date must be after check-in date', array( 'status' => 400 ) );
			}

			// Check for private event days
			$private_events        = get_option( 'aiohm_booking_mvp_private_events', array() );
			$booking_dates         = self::get_booking_date_range( $check_in_date, $check_out_date );
			$has_private_only_days = false;
			$private_event_info    = null;

			foreach ( $booking_dates as $date ) {
				if ( isset( $private_events[ $date ] ) ) {
					$event = $private_events[ $date ];
					$mode  = $event['mode'] ?? 'private_only';

					// Only block individual bookings for 'private_only' mode
					if ( $mode === 'private_only' ) {
						$has_private_only_days = true;
						$private_event_info    = $event;
						break;
					}
				}
			}

			// If booking includes private-only event days, force private_all booking
			if ( $has_private_only_days && ! $private_all ) {
				return new \WP_Error(
					'private_event_only',
					'This date is reserved for private events. Only full property bookings are available for ' . $private_event_info['name'] . '.',
					array(
						'status'        => 400,
						'private_event' => true,
						'event_info'    => $private_event_info,
					)
				);
			}

			// Check room availability against admin-blocked dates
			if ( ! $private_all ) {
				$admin_blocked_dates = get_option( 'aiohm_booking_mvp_blocked_dates', array() );
				$total_rooms         = intval( $opts['available_rooms'] ?? 0 );

				if ( ! empty( $room_ids ) ) {
					// Check specific room IDs
					foreach ( $room_ids as $room_id ) {
						foreach ( $booking_dates as $date ) {
							if ( isset( $admin_blocked_dates[ $room_id ][ $date ] ) ) {
								$blocked_info = $admin_blocked_dates[ $room_id ][ $date ];
								$status       = is_array( $blocked_info ) ? ( $blocked_info['status'] ?? 'blocked' ) : 'blocked';

								if ( in_array( $status, array( 'blocked', 'booked', 'pending', 'external' ) ) ) {
									return new \WP_Error(
										'room_unavailable',
										"Room $room_id is not available on $date.",
										array(
											'status'  => 400,
											'room_id' => $room_id,
											'date'    => $date,
										)
									);
								}
							}
						}
					}
				} elseif ( $rooms_qty > 0 ) {
					// Check if enough rooms are available (not admin-blocked or already booked) for quantity-based booking
					global $wpdb;
					$order_table = $wpdb->prefix . 'aiohm_booking_mvp_order';

					// Get existing bookings for the date range
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$existing_bookings = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT check_in_date, check_out_date, private_all, rooms_qty FROM {$order_table} WHERE status IN ('paid', 'pending') AND check_in_date <= %s AND check_out_date > %s",
							$check_out_date,
							$check_in_date
						)
					);

					// Count booked rooms per day
					$daily_booked_rooms = array();
					if ( $existing_bookings ) {
						foreach ( $existing_bookings as $booking ) {
							try {
								$start          = new \DateTime( $booking->check_in_date );
								$end            = new \DateTime( $booking->check_out_date );
								$booking_period = new \DatePeriod( $start, new \DateInterval( 'P1D' ), $end );

								foreach ( $booking_period as $day ) {
									$date_key = $day->format( 'Y-m-d' );
									if ( ! isset( $daily_booked_rooms[ $date_key ] ) ) {
										$daily_booked_rooms[ $date_key ] = 0;
									}
									if ( $booking->private_all ) {
										$daily_booked_rooms[ $date_key ] = $total_rooms; // Mark all rooms as booked
									} else {
										$daily_booked_rooms[ $date_key ] += intval( $booking->rooms_qty );
									}
								}
							} catch ( \Exception $e ) {
								// Skip invalid booking dates
							}
						}
					}

					foreach ( $booking_dates as $date ) {
						$blocked_rooms_count = 0;
						for ( $room_id = 1; $room_id <= $total_rooms; $room_id++ ) {
							if ( isset( $admin_blocked_dates[ $room_id ][ $date ] ) ) {
								$blocked_info = $admin_blocked_dates[ $room_id ][ $date ];
								$status       = is_array( $blocked_info ) ? ( $blocked_info['status'] ?? 'blocked' ) : 'blocked';

								if ( in_array( $status, array( 'blocked', 'booked', 'pending', 'external' ) ) ) {
									++$blocked_rooms_count;
								}
							}
						}

						$booked_rooms_count = $daily_booked_rooms[ $date ] ?? 0;
						$available_rooms    = $total_rooms - $blocked_rooms_count - $booked_rooms_count;

						if ( $available_rooms < $rooms_qty ) {
							return new \WP_Error(
								'insufficient_rooms',
								"Only $available_rooms rooms are available on $date, but $rooms_qty rooms were requested.",
								array(
									'status'    => 400,
									'date'      => $date,
									'available' => $available_rooms,
									'requested' => $rooms_qty,
								)
							);
						}
					}
				}
			}

			$total = self::calculate_accommodation_total( $room_ids, $rooms_qty, $private_all, $opts, $check_in_date, $check_out_date, $private_event_info );

			if ( $total <= 0 ) {
				return new \WP_Error( 'invalid_total', 'Order total must be greater than 0', array( 'status' => 400 ) );
			}

			$deposit = round( $total * ( $opts['deposit_percent'] / 100 ), 2 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$insert_result = $wpdb->insert(
				$wpdb->prefix . 'aiohm_booking_mvp_order',
				array(
					'mode'                   => $mode,
					'rooms_qty'              => $rooms_qty,
					'guests_qty'             => $guests_qty,
					'private_all'            => $private_all,
					'buyer_name'             => $name,
					'buyer_email'            => $email,
					'buyer_phone'            => sanitize_text_field( $p['phone'] ?? '' ),
					'buyer_age'              => $age,
					'vat_number'             => $vat_number,
					'estimated_arrival_time' => $estimated_arrival_time,
					'bringing_pets'          => $bringing_pets,
					'pet_details'            => $pet_details,
					'check_in_date'          => $check_in_date,
					'check_out_date'         => $check_out_date,
					'total_amount'           => $total,
					'deposit_amount'         => $deposit,
					'currency'               => $opts['currency'],
					'status'                 => 'pending',
				)
			);

			// Check for database errors
			if ( $insert_result === false ) {
				return new \WP_Error( 'database_error', 'Unable to create booking. Please try again.', array( 'status' => 500 ) );
			}

			$order_id = $wpdb->insert_id;

			// Validate order was created successfully
			if ( ! $order_id ) {
				return new \WP_Error( 'database_error', 'Unable to create booking. Please try again.', array( 'status' => 500 ) );
			}

			// Persist explicit room selections, if provided
			if ( ! empty( $room_ids ) ) {
				$map = get_option( 'aiohm_booking_mvp_order_rooms', array() );
				if ( ! is_array( $map ) ) {
					$map = array(); }
				$map[ intval( $order_id ) ] = array_values( array_unique( $room_ids ) );
				update_option( 'aiohm_booking_mvp_order_rooms', $map );
			}

			// Trigger calendar sync hook
			do_action(
				'aiohm_booking_mvp_order_created',
				$order_id,
				array(
					'mode'                   => $mode,
					'rooms_qty'              => $rooms_qty,
					'guests_qty'             => $guests_qty,
					'private_all'            => $private_all,
					'check_in_date'          => $check_in_date,
					'check_out_date'         => $check_out_date,
					'room_ids'               => $room_ids,
					'vat_number'             => $vat_number,
					'estimated_arrival_time' => $estimated_arrival_time,
					'bringing_pets'          => $bringing_pets,
					'pet_details'            => $pet_details,
					'age'                    => $age,
				)
			);

			return rest_ensure_response(
				array(
					'order_id'    => $order_id,
					'buyer_email' => $email,
					'total'       => $total,
					'deposit'     => $deposit,
					'currency'    => $opts['currency'],
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'server_error', 'A server error occurred. Please try again.', array( 'status' => 500 ) );
		}
	}

	public static function stripe_session( \WP_REST_Request $r ) {
		$p        = $r->get_json_params();
		$order_id = absint( $p['order_id'] ?? 0 );

		if ( ! $order_id ) {
			return new \WP_Error( 'invalid_order', 'A valid order ID is required.', array( 'status' => 400 ) );
		}

		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aiohm_booking_mvp_order WHERE id = %d", $order_id ) );

		if ( ! $order ) {
			return new \WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
		}

		$settings          = get_option( 'aiohm_booking_mvp_settings', array() );
		$stripe_secret_key = trim( $settings['stripe_secret_key'] ?? '' );

		if ( empty( $stripe_secret_key ) ) {
			return new \WP_Error( 'stripe_not_configured', 'Stripe is not configured.', array( 'status' => 500 ) );
		}

		$line_items = array(
			array(
				'price_data' => array(
					'currency'     => strtolower( $order->currency ),
					'product_data' => array(
						'name' => 'Booking Deposit - Order #' . $order->id,
					),
					'unit_amount'  => round( $order->deposit_amount * 100 ), // Amount in cents
				),
				'quantity'   => 1,
			),
		);

		$checkout_session_args = array(
			'payment_method_types' => array( 'card' ),
			'line_items'           => $line_items,
			'mode'                 => 'payment',
			'success_url'          => Settings::get( 'thankyou_page_url', home_url( '/' ) ) . '?order_id=' . $order_id,
			'cancel_url'           => Settings::get( 'checkout_page_url', home_url( '/' ) ) . '?order_id=' . $order_id . '&cancelled=true',
			'client_reference_id'  => $order->id,
			'customer_email'       => $order->buyer_email,
		);

		$response = wp_remote_post(
			'https://api.stripe.com/v1/checkout/sessions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $stripe_secret_key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $checkout_session_args ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'stripe_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['error'] ) ) {
			return new \WP_Error( 'stripe_error', $body['error']['message'], array( 'status' => 400 ) );
		}

		if ( empty( $body['url'] ) ) {
			return new \WP_Error( 'stripe_error', 'Could not create Stripe checkout session.', array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'checkout_url' => $body['url'] ) );
	}

	public static function stripe_webhook( \WP_REST_Request $r ) {
		$payload = $r->get_body();
		$event   = json_decode( $payload );

		// Handle the event
		switch ( $event->type ) {
			case 'checkout.session.completed':
				$session = $event->data->object;

				// The order ID should be in the client_reference_id
				$order_id          = absint( $session->client_reference_id ?? 0 );
				$payment_intent_id = sanitize_text_field( $session->payment_intent ?? '' );

				if ( $order_id > 0 ) {
					global $wpdb;
					$table = $wpdb->prefix . 'aiohm_booking_mvp_order';

					// Check if order exists and is pending
					// Safe SQL: Table name is sanitized via wpdb->prefix concatenation
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status = 'pending'", $order_id ) );

					if ( $order ) {
						// Update order status to 'paid'
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update(
							$table,
							array(
								'status'         => 'paid',
								'payment_method' => 'stripe',
								'payment_id'     => $payment_intent_id,
							),
							array( 'id' => $order_id )
						);

						// Trigger action for successful payment
						do_action( 'aiohm_booking_mvp_payment_completed', $order_id, 'stripe' );
					}
				}
				break;
			// ... handle other event types
			default:
				// Unexpected event type
		}

		return rest_ensure_response( array( 'status' => 'success' ) );
	}

	public static function paypal_capture( \WP_REST_Request $r ) {
		// PayPal capture handler with server-side verification
		$p               = $r->get_json_params();
		$order_id        = absint( $p['order_id'] ?? 0 );
		$paypal_order_id = sanitize_text_field( $p['paypal_order_id'] ?? '' );

		if ( ! $order_id || ! $paypal_order_id ) {
			return new \WP_Error( 'missing_data', 'Missing order ID or PayPal order ID', array( 'status' => 400 ) );
		}

		// Get PayPal settings
		$settings             = get_option( 'aiohm_booking_mvp_settings', array() );
		$paypal_client_id     = sanitize_text_field( $settings['paypal_client_id'] ?? '' );
		$paypal_client_secret = sanitize_text_field( $settings['paypal_client_secret'] ?? '' );
		$paypal_environment   = sanitize_text_field( $settings['paypal_environment'] ?? 'sandbox' );

		if ( empty( $paypal_client_id ) || empty( $paypal_client_secret ) ) {
			return new \WP_Error( 'paypal_config', 'PayPal configuration incomplete', array( 'status' => 500 ) );
		}

		// Get access token from PayPal
		$access_token = self::get_paypal_access_token( $paypal_client_id, $paypal_client_secret, $paypal_environment );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		// Verify payment with PayPal API
		$verification_result = self::verify_paypal_payment( $paypal_order_id, $access_token, $paypal_environment );
		if ( is_wp_error( $verification_result ) ) {
			return $verification_result;
		}

		// Check if payment is actually completed and capture if necessary
		if ( $verification_result['status'] === 'APPROVED' ) {
			// Capture the payment
			$capture_result = self::capture_paypal_payment( $paypal_order_id, $access_token, $paypal_environment );
			if ( is_wp_error( $capture_result ) ) {
				return $capture_result;
			}
			$verification_result = $capture_result;
		}

		if ( $verification_result['status'] !== 'COMPLETED' ) {
			return new \WP_Error( 'payment_not_completed', 'Payment not completed in PayPal', array( 'status' => 400 ) );
		}

		// Payment verified - update order in database
		global $wpdb;
		$payment_id = sanitize_text_field( $verification_result['payment_id'] ?? $paypal_order_id );

		$wpdb->update(
			$wpdb->prefix . 'aiohm_booking_mvp_order',
			array(
				'status'         => 'paid',
				'payment_method' => 'paypal',
				'payment_id'     => $payment_id,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $order_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		// Log successful payment verification
		error_log( "AIOHM PayPal payment verified successfully: Order {$order_id}, PayPal ID {$paypal_order_id}" );

		return rest_ensure_response(
			array(
				'success'    => true,
				'payment_id' => $payment_id,
				'status'     => 'completed',
			)
		);
	}

	/**
	 * Return dates that are fully unavailable (all rooms blocked) for the period
	 * A date is considered "occupied" if all available rooms are booked by customers or blocked by an admin.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error The REST response.
	 */
	public static function availability( \WP_REST_Request $request ) {
		global $wpdb;

		$from_str = sanitize_text_field( $request->get_param( 'from' ) );
		$to_str   = sanitize_text_field( $request->get_param( 'to' ) );

		try {
			$from_dt = new \DateTime( $from_str );
			$to_dt   = new \DateTime( $to_str );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'invalid_date', 'Invalid date format.', array( 'status' => 400 ) );
		}

		// Load settings
		$settings    = get_option( 'aiohm_booking_mvp_settings', array() );
		$total_rooms = intval( $settings['available_rooms'] ?? 0 );

		if ( $total_rooms <= 0 ) {
			// If no rooms are configured, all dates are occupied.
			$occupied_dates = array();
			$period         = new \DatePeriod( $from_dt, new \DateInterval( 'P1D' ), ( clone $to_dt )->modify( '+1 day' ) );
			foreach ( $period as $day ) {
				$occupied_dates[] = $day->format( 'Y-m-d' );
			}
			return rest_ensure_response(
				array(
					'occupied_dates' => $occupied_dates,
					'custom_prices'  => array(),
				)
			);
		}

		// 1. Tally rooms occupied by customer bookings per day
		$order_table = $wpdb->prefix . 'aiohm_booking_mvp_order';
		// Safe SQL: Table name is sanitized via wpdb->prefix concatenation
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bookings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT check_in_date, check_out_date, private_all, rooms_qty FROM {$order_table} WHERE status IN ('paid', 'pending') AND check_in_date <= %s AND check_out_date > %s",
				$to_dt->format( 'Y-m-d' ),
				$from_dt->format( 'Y-m-d' )
			)
		);

		$daily_booked_rooms = array();
		if ( $bookings ) {
			foreach ( $bookings as $booking ) {
				try {
					$start          = new \DateTime( $booking->check_in_date );
					$end            = new \DateTime( $booking->check_out_date );
					$booking_period = new \DatePeriod( $start, new \DateInterval( 'P1D' ), $end );

					foreach ( $booking_period as $day ) {
						$date_key = $day->format( 'Y-m-d' );
						if ( ! isset( $daily_booked_rooms[ $date_key ] ) ) {
							$daily_booked_rooms[ $date_key ] = 0;
						}
						if ( $booking->private_all ) {
							$daily_booked_rooms[ $date_key ] = $total_rooms; // Mark all rooms as booked
						} else {
							$daily_booked_rooms[ $date_key ] += intval( $booking->rooms_qty );
						}
					}
				} catch ( \Exception $e ) {
					// Skip invalid booking dates
				}
			}
		}

		// 2. Tally rooms blocked by admin per day
		$admin_blocked_dates       = get_option( 'aiohm_booking_mvp_blocked_dates', array() );
		$daily_admin_blocked_rooms = array();
		if ( ! empty( $admin_blocked_dates ) ) {
			foreach ( $admin_blocked_dates as $room_id => $dates ) {
				if ( ! is_array( $dates ) ) {
					continue;
				}
				foreach ( $dates as $date_str => $details ) {
					$status = is_array( $details ) ? ( $details['status'] ?? 'blocked' ) : 'blocked';
					if ( in_array( $status, array( 'blocked', 'booked', 'pending', 'external' ) ) ) {
						try {
							$current_date = new \DateTime( $date_str );
							if ( $current_date >= $from_dt && $current_date <= $to_dt ) {
								if ( ! isset( $daily_admin_blocked_rooms[ $date_str ] ) ) {
									$daily_admin_blocked_rooms[ $date_str ] = array();
								}
								// Use room_id as key to prevent double counting a room on a given day
								$daily_admin_blocked_rooms[ $date_str ][ $room_id ] = true;
							}
						} catch ( \Exception $e ) {
							// Skip invalid date
						}
					}
				}
			}
		}

		// 3. Get admin-set custom prices for the date range
		$admin_custom_prices = array();
		if ( ! empty( $admin_blocked_dates ) ) {
			$price_period = new \DatePeriod( $from_dt, new \DateInterval( 'P1D' ), ( clone $to_dt )->modify( '+1 day' ) );
			foreach ( $price_period as $day ) {
				$date_key            = $day->format( 'Y-m-d' );
				$day_specific_prices = array();
				foreach ( $admin_blocked_dates as $room_id => $dates ) {
					if ( isset( $dates[ $date_key ] ) && ! empty( $dates[ $date_key ]['price'] ) ) {
						$day_specific_prices[] = floatval( $dates[ $date_key ]['price'] );
					}
				}
				if ( ! empty( $day_specific_prices ) ) {
					// If multiple rooms have custom prices on the same day, the frontend will use the lowest one.
					$admin_custom_prices[ $date_key ] = min( $day_specific_prices );
				}
			}
		}

		// 4. Calculate daily prices for frontend display
		$accommodation_details = get_option( 'aiohm_booking_mvp_accommodations_details', array() );
		$all_prices            = array();
		$default_price         = floatval( $settings['room_price'] ?? 0 );

		// Collect all configured standard prices to find the minimum "starting from" price
		if ( $total_rooms > 0 ) {
			for ( $i = 0; $i < $total_rooms; $i++ ) {
				$details = $accommodation_details[ $i ] ?? array();
				$price   = ! empty( $details['price'] ) ? floatval( $details['price'] ) : $default_price;
				if ( $price > 0 ) {
					$all_prices[] = $price;
				}
			}
		}
		$base_price = ! empty( $all_prices ) ? min( $all_prices ) : $default_price;

		$daily_prices        = array();
		$private_events_info = array();
		$private_events      = get_option( 'aiohm_booking_mvp_private_events', array() );

		$price_period_final = new \DatePeriod( $from_dt, new \DateInterval( 'P1D' ), ( clone $to_dt )->modify( '+1 day' ) );
		foreach ( $price_period_final as $day ) {
			$date_key = $day->format( 'Y-m-d' );

			// Check for private events
			if ( isset( $private_events[ $date_key ] ) ) {
				$event      = $private_events[ $date_key ];
				$event_mode = $event['mode'] ?? 'private_only';

				// Store event info for frontend
				$private_events_info[ $date_key ] = array(
					'mode'  => $event_mode,
					'name'  => $event['name'] ?? 'Private Event',
					'price' => floatval( $event['price'] ?? 0 ),
				);

				// For special pricing mode, use the event price
				if ( $event_mode === 'special_pricing' ) {
					$daily_prices[ $date_key ] = floatval( $event['price'] );
				} else {
					// For private_only, still show the custom price or base price for reference
					$daily_prices[ $date_key ] = $admin_custom_prices[ $date_key ] ?? $base_price;
				}
			} else {
				// Use admin custom price if set, otherwise use the calculated base price
				$daily_prices[ $date_key ] = $admin_custom_prices[ $date_key ] ?? $base_price;
			}
		}

		// 5. Combine and determine fully occupied dates
		$occupied_dates = array();
		$period         = new \DatePeriod( $from_dt, new \DateInterval( 'P1D' ), ( clone $to_dt )->modify( '+1 day' ) );
		foreach ( $period as $day ) {
			$date_key            = $day->format( 'Y-m-d' );
			$booked_count        = $daily_booked_rooms[ $date_key ] ?? 0;
			$admin_blocked_count = isset( $daily_admin_blocked_rooms[ $date_key ] ) ? count( $daily_admin_blocked_rooms[ $date_key ] ) : 0;

			if ( ( $booked_count + $admin_blocked_count ) >= $total_rooms ) {
				$occupied_dates[] = $date_key;
			}
		}

		$response_data = array(
			'occupied_dates' => array_values( array_unique( $occupied_dates ) ),
			'daily_prices'   => $daily_prices,
			'private_events' => $private_events_info,
		);

		// Add detailed room blocking information if requested
		$detailed = $request->get_param( 'detailed' );
		if ( $detailed ) {
			$response_data['blocked_rooms'] = $admin_blocked_dates;
		}

		return rest_ensure_response( $response_data );
	}

	/**
	 * Admin: Toggle block/unblock a specific room/date
	 */
	public static function toggle_block_date( \WP_REST_Request $r ) {
		// Security: require REST nonce header and capability via permission_callback
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
		}
		$params  = $r->get_json_params();
		$room_id = absint( $params['room_id'] ?? 0 );
		$date    = sanitize_text_field( $params['date'] ?? '' );
		$block   = ! empty( $params['block'] ) ? 1 : 0;

		if ( $room_id <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new \WP_Error( 'invalid_params', 'Invalid room or date', array( 'status' => 400 ) );
		}

		$blocked = get_option( 'aiohm_booking_mvp_blocked_dates', array() );
		if ( $block ) {
			if ( ! isset( $blocked[ $room_id ] ) ) {
				$blocked[ $room_id ] = array();
			}
			$blocked[ $room_id ][ $date ] = array(
				'status'     => 'blocked',
				'reason'     => 'Set via API',
				'blocked_at' => current_time( 'mysql' ),
				'blocked_by' => get_current_user_id(),
			);
		} elseif ( isset( $blocked[ $room_id ][ $date ] ) ) {
				unset( $blocked[ $room_id ][ $date ] );
		}
		update_option( 'aiohm_booking_mvp_blocked_dates', $blocked );

		return rest_ensure_response(
			array(
				'success' => true,
				'room_id' => $room_id,
				'date'    => $date,
				'blocked' => $block,
			)
		);
	}

	/**
	 * Get array of dates for a booking period
	 *
	 * @param string $check_in_date Check-in date (Y-m-d format)
	 * @param string $check_out_date Check-out date (Y-m-d format)
	 * @return array Array of date strings in Y-m-d format
	 */
	private static function get_booking_date_range( $check_in_date, $check_out_date ) {
		$dates = array();

		if ( ! $check_in_date || ! $check_out_date ) {
			return $dates;
		}

		try {
			$start  = new \DateTime( $check_in_date );
			$end    = new \DateTime( $check_out_date );
			$period = new \DatePeriod( $start, new \DateInterval( 'P1D' ), $end );

			foreach ( $period as $day ) {
				$dates[] = $day->format( 'Y-m-d' );
			}
		} catch ( \Exception $e ) {
			// Return empty array on error
		}

		return $dates;
	}

	/**
	 * Calculate accommodation pricing based on actual selected rooms or private all
	 *
	 * @param array $room_ids Array of selected room IDs (0-based from frontend)
	 * @param int   $rooms_qty Quantity of rooms
	 * @param bool  $private_all Whether private all rooms is selected
	 * @param array $opts Pricing options from settings
	 * @return float Total accommodation cost
	 */
	private static function calculate_accommodation_total( $room_ids, $rooms_qty, $private_all, $opts, $check_in_str = null, $check_out_str = null, $private_event_info = null ) {
		$accommodation_details = get_option( 'aiohm_booking_mvp_accommodations_details', array() );
		$admin_blocked_dates   = get_option( 'aiohm_booking_mvp_blocked_dates', array() );
		$default_room_price    = $opts['room_price'];
		$available_rooms       = $opts['available_rooms'];

		// Fallback to simple calculation if dates are not provided
		if ( ! $check_in_str || ! $check_out_str ) {
			// Use private event price if applicable
			if ( $private_event_info && $private_all ) {
				return floatval( $private_event_info['price'] );
			}
			return floatval( $rooms_qty * $default_room_price );
		}

		try {
			$start  = new \DateTime( $check_in_str );
			$end    = new \DateTime( $check_out_str );
			$period = new \DatePeriod( $start, new \DateInterval( 'P1D' ), $end );
		} catch ( \Exception $e ) {
			return floatval( $rooms_qty * $default_room_price );
		}

		$total          = 0.0;
		$rooms_to_price = array();

		if ( $private_all ) {
			for ( $i = 1; $i <= $available_rooms; $i++ ) {
				$rooms_to_price[] = $i;
			}
		} else {
			$rooms_to_price = $room_ids;
		}

		// Get private events for checking dates
		$private_events = get_option( 'aiohm_booking_mvp_private_events', array() );

		foreach ( $period as $day ) {
			$date_key    = $day->format( 'Y-m-d' );
			$daily_total = 0;

			// Check if this date has a private event
			$event_on_date = $private_events[ $date_key ] ?? null;
			$event_mode    = $event_on_date['mode'] ?? null;

			if ( $event_on_date && $event_mode === 'private_only' && $private_all ) {
				// Private event with full property booking - use event price
				$daily_total = floatval( $event_on_date['price'] );
			} else {
				// Regular room-by-room pricing (including special pricing days)
				foreach ( $rooms_to_price as $room_id ) {
					$accommodation_index = intval( $room_id ) - 1;

					$custom_price = null;

					// Check for admin blocked dates with custom prices first
					if ( isset( $admin_blocked_dates[ $room_id ][ $date_key ] ) && ! empty( $admin_blocked_dates[ $room_id ][ $date_key ]['price'] ) ) {
						$custom_price = floatval( $admin_blocked_dates[ $room_id ][ $date_key ]['price'] );
					}
					// Check for special pricing events (not private_only)
					elseif ( $event_on_date && $event_mode === 'special_pricing' ) {
						// For special pricing, use the event price per room
						$custom_price = floatval( $event_on_date['price'] );
					}

					if ( $custom_price !== null && $custom_price >= 0 ) {
						$daily_total += $custom_price;
					} else {
						$details      = $accommodation_details[ $accommodation_index ] ?? array();
						$price        = ! empty( $details['price'] ) ? floatval( $details['price'] ) : $default_room_price;
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
	 *
	 * @param int   $index 0-based accommodation index
	 * @param array $accommodation_details Accommodation details array
	 * @param float $default_price Default price fallback
	 * @return float Price for this accommodation
	 */
	private static function get_accommodation_price( $index, $accommodation_details, $default_price ) {
		$details = $accommodation_details[ $index ] ?? array();

		// Use standard price first, then early bird as fallback, then default
		if ( ! empty( $details['price'] ) && floatval( $details['price'] ) > 0 ) {
			return floatval( $details['price'] );
		} elseif ( ! empty( $details['earlybird_price'] ) && floatval( $details['earlybird_price'] ) > 0 ) {
			return floatval( $details['earlybird_price'] );
		} else {
			return floatval( $default_price );
		}
	}

	/**
	 * Verify nonce for public endpoints that accept user input
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public static function verify_public_nonce( $request ) {
		// Check for nonce in header or request parameter
		$nonce = $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' );

		if ( empty( $nonce ) ) {
			return new \WP_Error( 'missing_nonce', 'Security nonce is required', array( 'status' => 403 ) );
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'invalid_nonce', 'Invalid security nonce', array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Verify webhook requests (minimal verification for payment webhooks)
	 *
	 * @param \WP_REST_Request $request
	 * @return bool
	 */
	public static function verify_webhook( $request ) {
		$settings       = get_option( 'aiohm_booking_mvp_settings', array() );
		$webhook_secret = trim( $settings['stripe_webhook_secret'] ?? '' );

		if ( empty( $webhook_secret ) ) {
			// If no secret is configured, deny access
			return false;
		}

		$signature_header = $request->get_header( 'stripe_signature' );
		if ( empty( $signature_header ) ) {
			return false; // No signature, deny.
		}

		$payload = $request->get_body();

		// Parse the signature header
		$timestamp    = '';
		$signature_v1 = '';
		$parts        = explode( ',', $signature_header );
		foreach ( $parts as $part ) {
			list($key, $value) = explode( '=', $part, 2 );
			if ( $key === 't' ) {
				$timestamp = $value;
			} elseif ( $key === 'v1' ) {
				$signature_v1 = $value;
			}
		}

		if ( empty( $timestamp ) || empty( $signature_v1 ) ) {
			return false; // Malformed header
		}

		// Check if the timestamp is too old (e.g., more than 5 minutes) to prevent replay attacks
		if ( abs( time() - $timestamp ) > 300 ) {
			return false;
		}

		$signed_payload     = $timestamp . '.' . $payload;
		$expected_signature = hash_hmac( 'sha256', $signed_payload, $webhook_secret );

		// Compare signatures securely to prevent timing attacks
		return hash_equals( $expected_signature, $signature_v1 );
	}

	/**
	 * PayPal webhook handler - processes PayPal payment notifications
	 *
	 * @param \WP_REST_Request $r The request object
	 * @return \WP_REST_Response The REST response
	 */
	public static function paypal_webhook( \WP_REST_Request $r ) {
		$payload = $r->get_body();
		$event   = json_decode( $payload, true );

		if ( ! $event || ! isset( $event['event_type'] ) ) {
			return new \WP_Error( 'invalid_webhook', 'Invalid PayPal webhook payload', array( 'status' => 400 ) );
		}

		// Log webhook for debugging (remove in production)
		error_log( 'AIOHM PayPal webhook received: ' . $event['event_type'] );

		// Handle different PayPal webhook events
		switch ( $event['event_type'] ) {
			case 'CHECKOUT.ORDER.COMPLETED':
			case 'PAYMENT.CAPTURE.COMPLETED':
				return self::handle_paypal_payment_completed( $event );

			case 'PAYMENT.CAPTURE.DENIED':
			case 'PAYMENT.CAPTURE.REFUNDED':
				return self::handle_paypal_payment_failed( $event );

			default:
				// Log unknown event types but don't fail
				error_log( 'AIOHM PayPal webhook: Unknown event type: ' . $event['event_type'] );
				return rest_ensure_response( array( 'status' => 'ignored' ) );
		}
	}

	/**
	 * Handle completed PayPal payments from webhooks
	 *
	 * @param array $event PayPal webhook event data
	 * @return \WP_REST_Response
	 */
	private static function handle_paypal_payment_completed( $event ) {
		// Extract order information from webhook
		$resource   = $event['resource'] ?? array();
		$custom_id  = $resource['custom_id'] ?? '';
		$payment_id = $resource['id'] ?? '';

		if ( empty( $custom_id ) ) {
			error_log( 'AIOHM PayPal webhook: No custom_id in payment completed event' );
			return rest_ensure_response( array( 'status' => 'ignored' ) );
		}

		// Custom ID should contain our order ID
		$order_id = absint( $custom_id );
		if ( ! $order_id ) {
			error_log( 'AIOHM PayPal webhook: Invalid order ID in custom_id: ' . $custom_id );
			return rest_ensure_response( array( 'status' => 'error' ) );
		}

		// Update order status
		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'aiohm_booking_mvp_order',
			array(
				'status'         => 'paid',
				'payment_method' => 'paypal',
				'payment_id'     => sanitize_text_field( $payment_id ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $order_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( $updated ) {
			error_log( "AIOHM PayPal webhook: Successfully updated order {$order_id} to paid status" );
			return rest_ensure_response( array( 'status' => 'processed' ) );
		} else {
			error_log( "AIOHM PayPal webhook: Failed to update order {$order_id}" );
			return rest_ensure_response( array( 'status' => 'error' ) );
		}
	}

	/**
	 * Handle failed PayPal payments from webhooks
	 *
	 * @param array $event PayPal webhook event data
	 * @return \WP_REST_Response
	 */
	private static function handle_paypal_payment_failed( $event ) {
		$resource  = $event['resource'] ?? array();
		$custom_id = $resource['custom_id'] ?? '';

		if ( empty( $custom_id ) ) {
			return rest_ensure_response( array( 'status' => 'ignored' ) );
		}

		$order_id = absint( $custom_id );
		if ( ! $order_id ) {
			return rest_ensure_response( array( 'status' => 'error' ) );
		}

		// Update order status to failed
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'aiohm_booking_mvp_order',
			array(
				'status'     => 'failed',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $order_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		error_log( "AIOHM PayPal webhook: Updated order {$order_id} to failed status" );
		return rest_ensure_response( array( 'status' => 'processed' ) );
	}

	/**
	 * Verify PayPal webhook signatures
	 *
	 * @param \WP_REST_Request $request The request object
	 * @return bool True if valid, false otherwise
	 */
	public static function verify_paypal_webhook( $request ) {
		// For PayPal webhook verification, you would typically:
		// 1. Get the webhook ID from your PayPal app settings
		// 2. Use PayPal's webhook verification API
		// 3. Verify the signature headers

		// For MVP/demo purposes, we'll implement basic verification
		// In production, implement full PayPal webhook signature verification

		$headers          = $request->get_headers();
		$required_headers = array( 'paypal_auth_algo', 'paypal_transmission_id', 'paypal_cert_id', 'paypal_transmission_sig', 'paypal_transmission_time' );

		// Check if all required PayPal headers are present
		foreach ( $required_headers as $header ) {
			if ( empty( $headers[ $header ] ) ) {
				error_log( "AIOHM PayPal webhook: Missing required header: {$header}" );
				return false;
			}
		}

		// TODO: Implement full PayPal webhook signature verification using PayPal SDK
		// For now, we accept webhooks with proper headers (demo/MVP mode)
		// In production, verify against PayPal's public key

		return true;
	}

	/**
	 * Get PayPal access token for API calls
	 *
	 * @param string $client_id PayPal client ID
	 * @param string $client_secret PayPal client secret
	 * @param string $environment 'sandbox' or 'production'
	 * @return string|WP_Error Access token or error
	 */
	private static function get_paypal_access_token( $client_id, $client_secret, $environment ) {
		$base_url = ( $environment === 'production' )
			? 'https://api.paypal.com'
			: 'https://api.sandbox.paypal.com';

		$token_url = $base_url . '/v1/oauth2/token';

		$response = wp_remote_post(
			$token_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'          => 'application/json',
					'Accept-Language' => 'en_US',
					'Authorization'   => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
					'Content-Type'    => 'application/x-www-form-urlencoded',
				),
				'body'    => 'grant_type=client_credentials',
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'paypal_connection', 'Failed to connect to PayPal API: ' . $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code !== 200 || empty( $body['access_token'] ) ) {
			$error_msg = ! empty( $body['error_description'] ) ? $body['error_description'] : 'Authentication failed';
			return new \WP_Error( 'paypal_auth', 'PayPal authentication failed: ' . $error_msg );
		}

		return $body['access_token'];
	}

	/**
	 * Verify PayPal payment by checking order status
	 *
	 * @param string $paypal_order_id PayPal order ID
	 * @param string $access_token PayPal access token
	 * @param string $environment 'sandbox' or 'production'
	 * @return array|WP_Error Payment details or error
	 */
	private static function verify_paypal_payment( $paypal_order_id, $access_token, $environment ) {
		$base_url = ( $environment === 'production' )
			? 'https://api.paypal.com'
			: 'https://api.sandbox.paypal.com';

		$order_url = $base_url . '/v2/checkout/orders/' . $paypal_order_id;

		$response = wp_remote_get(
			$order_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'Authorization'     => 'Bearer ' . $access_token,
					'PayPal-Request-Id' => wp_generate_uuid4(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'paypal_verification', 'Failed to verify payment with PayPal: ' . $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code !== 200 ) {
			$error_msg = ! empty( $body['message'] ) ? $body['message'] : 'Verification failed';
			return new \WP_Error( 'paypal_verification', 'PayPal verification failed: ' . $error_msg );
		}

		// Extract relevant payment information
		$payment_info = array(
			'status'      => $body['status'] ?? 'UNKNOWN',
			'payment_id'  => $paypal_order_id,
			'payer_email' => $body['payer']['email_address'] ?? '',
			'amount'      => $body['purchase_units'][0]['amount']['value'] ?? '0',
			'currency'    => $body['purchase_units'][0]['amount']['currency_code'] ?? 'USD',
		);

		return $payment_info;
	}

	/**
	 * Capture PayPal payment (for approved orders)
	 *
	 * @param string $paypal_order_id PayPal order ID
	 * @param string $access_token PayPal access token
	 * @param string $environment 'sandbox' or 'production'
	 * @return array|WP_Error Payment capture result or error
	 */
	private static function capture_paypal_payment( $paypal_order_id, $access_token, $environment ) {
		$base_url = ( $environment === 'production' )
			? 'https://api.paypal.com'
			: 'https://api.sandbox.paypal.com';

		$capture_url = $base_url . '/v2/checkout/orders/' . $paypal_order_id . '/capture';

		$response = wp_remote_post(
			$capture_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'Authorization'     => 'Bearer ' . $access_token,
					'PayPal-Request-Id' => wp_generate_uuid4(),
				),
				'body'    => json_encode( array( 'payment_source' => array() ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'paypal_capture', 'Failed to capture PayPal payment: ' . $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code !== 201 ) {
			$error_msg = ! empty( $body['message'] ) ? $body['message'] : 'Capture failed';
			return new \WP_Error( 'paypal_capture', 'PayPal capture failed: ' . $error_msg );
		}

		// Extract capture information
		$capture_info = array(
			'status'      => 'COMPLETED',
			'payment_id'  => $body['purchase_units'][0]['payments']['captures'][0]['id'] ?? $paypal_order_id,
			'payer_email' => $body['payer']['email_address'] ?? '',
			'amount'      => $body['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? '0',
			'currency'    => $body['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code'] ?? 'USD',
		);

		return $capture_info;
	}
}
