<?php

namespace AIOHM\BookingMVP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Settings management class for AIOHM Booking MVP.
 *
 * Handles fetching, updating, and caching of plugin settings.
 *
 * @package AIOHM\BookingMVP\Core
 * @since   1.0.0
 */
class Settings {

	/**
	 * Cache for the settings to avoid multiple database calls.
	 *
	 * @var array|null
	 */
	private static $cached_settings = null;

	/**
	 * Flag to indicate if the cache should be cleared.
	 *
	 * @var bool
	 */
	private static $cache_cleared = false;

	/**
	 * Get all plugin settings.
	 *
	 * @since 1.0.0
	 * @return array Plugin settings array.
	 */
	public static function getAll() {
		if ( self::$cache_cleared || self::$cached_settings === null ) {
			self::$cached_settings = get_option( 'aiohm_booking_mvp_settings', array() );
			self::$cache_cleared   = false;
		}
		return self::$cached_settings;
	}

	/**
	 * Get a specific plugin setting.
	 *
	 * @since 1.0.0
	 * @param string $key     The setting key.
	 * @param mixed  $default The default value if the key is not found.
	 * @return mixed The setting value or default.
	 */
	public static function get( $key, $default = '' ) {
		$settings = self::getAll();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Update plugin settings.
	 *
	 * @since 1.0.0
	 * @param array $settings The new settings array.
	 * @return bool True on success, false on failure.
	 */
	public static function update( $settings ) {
		self::clearCache();
		$result = update_option( 'aiohm_booking_mvp_settings', $settings );
		self::clearCache(); // Clear again after update
		return $result;
	}

	/**
	 * Clear the settings cache.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function clearCache() {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'aiohm_booking_mvp_settings', 'options' );
		}
		self::$cached_settings = null;
		self::$cache_cleared   = true;
	}
}
