<?php
if (!defined('ABSPATH')) { exit; }

class AIOHM_BOOKING_MVP_Security {
    public static function init() {
        add_filter('wp_headers', [__CLASS__, 'maybe_relax_csp_for_checkout'], 20, 1);
    }

    /**
     * Relax CSP on frontend checkout to allow PayPal SDK and API endpoints.
     */
    public static function maybe_relax_csp_for_checkout($headers) {
        if (is_admin()) return $headers;

        // Only when PayPal is enabled
        $settings = get_option('aiohm_booking_mvp_settings', []);
        $paypal_enabled = !empty($settings['enable_paypal']);
        if (!$paypal_enabled) return $headers;

        // Only on pages likely rendering checkout
        // Safe: Read-only check for checkout page detection, no data processing
        $is_checkout = false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!empty($_GET['order_id'])) {
            $is_checkout = true;
        } else {
            global $post;
            if ($post && is_a($post, 'WP_Post')) {
                $is_checkout = has_shortcode($post->post_content, 'aiohm_booking_mvp_checkout');
            }
        }
        if (!$is_checkout) return $headers;

        $csp = isset($headers['Content-Security-Policy']) ? $headers['Content-Security-Policy'] : '';
        $headers['Content-Security-Policy'] = self::augment_csp($csp);
        return $headers;
    }

    private static function augment_csp($csp) {
        $directives = [];
        if (!empty($csp)) {
            foreach (explode(';', $csp) as $part) {
                $part = trim($part);
                if ($part === '') continue;
                $bits = preg_split('/\s+/', $part);
                $name = array_shift($bits);
                $directives[strtolower($name)] = $bits;
            }
        }

        // PayPal domains
        $allow = [
            'https://www.paypal.com',
            'https://www.paypalobjects.com',
            'https://api-m.paypal.com',
            'https://api-m.sandbox.paypal.com'
        ];

        // Helper to add to directive
        $add = function($key) use (&$directives, $allow) {
            $key = strtolower($key);
            $cur = isset($directives[$key]) ? $directives[$key] : [];
            $cur = array_values(array_unique(array_merge($cur, $allow)));
            $directives[$key] = $cur;
        };

        // Ensure these directives allow PayPal
        $add('script-src');
        $add('script-src-elem');
        $add('connect-src');
        $add('frame-src');
        $add('img-src');

        // Rebuild policy
        $parts = [];
        foreach ($directives as $name => $vals) {
            if (empty($vals)) continue;
            $parts[] = $name . ' ' . implode(' ', $vals);
        }
        return implode('; ', $parts);
    }
}

