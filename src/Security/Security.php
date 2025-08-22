<?php

namespace AIOHM\BookingMVP\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * AIOHM Booking MVP Security Handler
 *
 * Handles security configurations for the booking system including
 * CSP (Content Security Policy) adjustments for payment processing.
 *
 * @package AIOHM\BookingMVP\Security
 * @since   1.0.0
 */
class Security {

	/**
	 * Initialize security features
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_headers', array( __CLASS__, 'maybe_relax_csp_for_checkout' ), 20, 1 );

		// SSL and mixed content fixes
		add_filter( 'style_loader_src', array( __CLASS__, 'force_ssl_for_assets' ), 999 );
		add_filter( 'script_loader_src', array( __CLASS__, 'force_ssl_for_assets' ), 999 );

		if ( is_ssl() && ! is_admin() ) {
			add_action( 'template_redirect', array( __CLASS__, 'fix_mixed_content_buffer' ), 1 );
		}
	}

	/**
	 * Force SSL for all enqueued assets to prevent mixed content errors.
	 *
	 * @param string $url The asset URL.
	 * @return string The corrected asset URL.
	 */
	public static function force_ssl_for_assets( $url ) {
		if ( is_ssl() ) {
			$url = str_replace( 'http://', 'https://', $url );
		}
		return $url;
	}

	/**
	 * Fix mixed content issues by starting an output buffer.
	 */
	public static function fix_mixed_content_buffer() {
		if ( is_ssl() && ! is_admin() ) {
			ob_start( array( __CLASS__, 'force_https_in_content' ) );
		}
	}

	/**
	 * Force HTTPS in the final buffered content.
	 *
	 * @param string $content The buffered content.
	 * @return string The modified content.
	 */
	public static function force_https_in_content( $content ) {
		if ( is_ssl() ) {
			// Fix font URLs and other asset URLs that might be loaded over HTTP
			$content = preg_replace( '/http:\/\/([^\/]+)\/wp-content\/uploads\/elementor\/google-fonts\/fonts\//i', 'https://$1/wp-content/uploads/elementor/google-fonts/fonts/', $content );

			// More comprehensive fix for any HTTP asset URLs in content
			$content = preg_replace( '/http:\/\/([^\/]+)\/wp-content\//i', 'https://$1/wp-content/', $content );
		}
		return $content;
	}

	/**
	 * Relax CSP on frontend checkout to allow PayPal SDK and API endpoints.
	 *
	 * @since 1.0.0
	 * @param array $headers HTTP headers array
	 * @return array Modified headers array
	 */
	public static function maybe_relax_csp_for_checkout( $headers ) {
		if ( is_admin() ) {
			return $headers;
		}

		// Only when PayPal is enabled
		$settings       = get_option( 'aiohm_booking_mvp_settings', array() );
		$paypal_enabled = ! empty( $settings['enable_paypal'] );
		if ( ! $paypal_enabled ) {
			return $headers;
		}

		// Only on pages likely rendering checkout
		// Safe: Read-only check for checkout page detection, no data processing
		$is_checkout = false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['order_id'] ) ) {
			$is_checkout = true;
		} else {
			global $post;
			if ( $post && is_a( $post, 'WP_Post' ) ) {
				$is_checkout = has_shortcode( $post->post_content, 'aiohm_booking_mvp_checkout' );
			}
		}
		if ( ! $is_checkout ) {
			return $headers;
		}

		$csp                                = isset( $headers['Content-Security-Policy'] ) ? $headers['Content-Security-Policy'] : '';
		$headers['Content-Security-Policy'] = self::augment_csp( $csp );
		return $headers;
	}

	/**
	 * Augment Content Security Policy to allow PayPal domains
	 *
	 * @since 1.0.0
	 * @param string $csp Current CSP string
	 * @return string Modified CSP string
	 */
	private static function augment_csp( $csp ) {
		$directives = array();
		if ( ! empty( $csp ) ) {
			foreach ( explode( ';', $csp ) as $part ) {
				$part = trim( $part );
				if ( $part === '' ) {
					continue;
				}
				$bits                              = preg_split( '/\s+/', $part );
				$name                              = array_shift( $bits );
				$directives[ strtolower( $name ) ] = $bits;
			}
		}

		// PayPal domains
		$allow = array(
			'https://www.paypal.com',
			'https://www.paypalobjects.com',
			'https://api-m.paypal.com',
			'https://api-m.sandbox.paypal.com',
		);

		// Helper to add to directive
		$add = function ( $key ) use ( &$directives, $allow ) {
			$key                = strtolower( $key );
			$cur                = isset( $directives[ $key ] ) ? $directives[ $key ] : array();
			$cur                = array_values( array_unique( array_merge( $cur, $allow ) ) );
			$directives[ $key ] = $cur;
		};

		// Ensure these directives allow PayPal
		$add( 'script-src' );
		$add( 'script-src-elem' );
		$add( 'connect-src' );
		$add( 'frame-src' );
		$add( 'img-src' );

		// Rebuild policy
		$parts = array();
		foreach ( $directives as $name => $vals ) {
			if ( empty( $vals ) ) {
				continue;
			}
			$parts[] = $name . ' ' . implode( ' ', $vals );
		}
		return implode( '; ', $parts );
	}
}
