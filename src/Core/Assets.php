<?php

namespace AIOHM\BookingMVP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Asset management class for AIOHM Booking MVP.
 *
 * Handles generating URLs and paths for plugin assets.
 *
 * @package AIOHM\BookingMVP\Core
 * @since   1.0.0
 */
class Assets {

	/**
	 * Build a URL for a plugin asset (CSS, JS, image).
	 *
	 * @since 1.0.0
	 * @param string $relative The relative path to the asset from the 'assets' directory.
	 * @return string The full URL to the asset.
	 */
	public static function get_url( $relative ) {
		$relative = ltrim( (string) $relative, '/' );
		$url      = AIOHM_BOOKING_MVP_URL . 'assets/' . $relative;

		if ( is_ssl() ) {
			$url = set_url_scheme( $url, 'https' );
		}

		return $url;
	}

	/**
	 * Build a filesystem path for a plugin asset.
	 *
	 * @since 1.0.0
	 * @param string $relative The relative path to the asset from the 'assets' directory.
	 * @return string The full filesystem path to the asset.
	 */
	public static function get_path( $relative ) {
		$relative = ltrim( (string) $relative, '/' );
		return AIOHM_BOOKING_MVP_DIR . 'assets/' . $relative;
	}
}
