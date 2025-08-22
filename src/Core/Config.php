<?php

namespace AIOHM\BookingMVP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use AIOHM\BookingMVP\Core\Settings;

/**
 * Configuration class for AIOHM Booking MVP.
 *
 * Handles business logic and configuration values.
 *
 * @package AIOHM\BookingMVP\Core
 * @since   1.0.0
 */
class Config {

	/**
	 * Check if rooms are enabled.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function areRoomsEnabled() {
		return ! empty( Settings::get( 'enable_rooms', 1 ) );
	}

	/**
	 * Get pricing information.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public static function getPrices() {
		return array(
			'room_price'        => floatval( Settings::get( 'room_price', 0.00 ) ),
			'deposit_percent'   => floatval( Settings::get( 'deposit_percent', 30.0 ) ),
			'currency'          => Settings::get( 'currency', 'EUR' ),
			'available_rooms'   => intval( Settings::get( 'available_rooms', 7 ) ),
			'allow_private_all' => ! empty( Settings::get( 'allow_private_all', 1 ) ),
		);
	}

	/**
	 * Get the customized product names for rooms.
	 *
	 * @since 1.0.0
	 * @return array Array with singular and plural product names.
	 */
	public static function getProductNames() {
		$settings = Settings::getAll();

		// Check new field name first, then fall back to old name for backward compatibility
		$accommodation_type_name = $settings['accommodation_product_name'] ?? $settings['room_product_name'] ?? 'room';

		$plurals = array(
			'accommodation' => 'accommodations',
			'room'          => 'rooms',
			'house'         => 'houses',
			'apartment'     => 'apartments',
			'villa'         => 'villas',
			'bungalow'      => 'bungalows',
			'cabin'         => 'cabins',
			'cottage'       => 'cottages',
			'suite'         => 'suites',
			'studio'        => 'studios',
			'unit'          => 'units',
			'space'         => 'spaces',
			'venue'         => 'venues',
		);

		return array(
			'singular'     => $accommodation_type_name,
			'plural'       => $plurals[ $accommodation_type_name ] ?? $accommodation_type_name . 's',
			'singular_cap' => ucfirst( $accommodation_type_name ),
			'plural_cap'   => ucfirst( $plurals[ $accommodation_type_name ] ?? $accommodation_type_name . 's' ),
		);
	}
}
