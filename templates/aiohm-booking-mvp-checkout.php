<?php
// Secure order access with email verification
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$order_id = absint($_GET['order_id'] ?? 0);
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$order_email = sanitize_email($_GET['email'] ?? '');

global $wpdb;
$order = null;
if($order_id && $order_email){
  // Verify order belongs to the provided email
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
  $order = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}aiohm_booking_mvp_order WHERE id=%d AND buyer_email=%s", 
    $order_id, 
    $order_email
  ) );
}

// Get payment settings
$settings = get_option('aiohm_booking_mvp_settings', []);
$stripe_enabled = !empty($settings['enable_stripe']);
$paypal_enabled = !empty($settings['enable_paypal']);
$paypal_client_id = trim((string)($settings['paypal_client_id'] ?? ''));
$paypal_ready = $paypal_enabled && !empty($paypal_client_id);

// Accommodation booking only
$rooms_enabled = true;

// Get accommodation type for dynamic display
$accommodation_type = esc_attr($settings['accommodation_product_name'] ?? 'room');
$accommodation_label = ucfirst($accommodation_type) . 's'; // rooms, houses, apartments, etc.

// Create dynamic booking mode label
$booking_mode_label = $order->mode ?? 'booking';
if ($booking_mode_label === 'rooms') {
    $booking_mode_label = $accommodation_label;
} elseif ($booking_mode_label === 'both') {
    $booking_mode_label = 'Mixed';
} else {
    $booking_mode_label = ucfirst($booking_mode_label);
}

// Get selected room details for breakdown
$selected_rooms = [];
$accommodation_details = get_option('aiohm_booking_mvp_accommodations_details', []);
$order_rooms_map = get_option('aiohm_booking_mvp_order_rooms', []);
$room_ids = $order_rooms_map[intval($order_id)] ?? [];

if ($order && $rooms_enabled) {
    if ($order->private_all) {
        // Private all - include all available rooms
        $available_rooms = intval($settings['available_rooms'] ?? 0);
        for ($i = 0; $i < $available_rooms; $i++) {
            $details = $accommodation_details[$i] ?? [];
            $title = !empty($details['title']) ? $details['title'] : ucfirst($accommodation_type) . ' ' . ($i + 1);
            $price = 0;
            if (!empty($details['price']) && floatval($details['price']) > 0) {
                $price = floatval($details['price']);
            } elseif (!empty($details['earlybird_price']) && floatval($details['earlybird_price']) > 0) {
                $price = floatval($details['earlybird_price']);
            } else {
                $price = floatval($settings['room_price'] ?? 0);
            }
            $selected_rooms[] = ['title' => $title, 'price' => $price];
        }
    } else {
        // Specific room selections
        foreach ($room_ids as $room_id) {
            $index = intval($room_id) - 1; // Convert from 1-based to 0-based
            if ($index >= 0) {
                $details = $accommodation_details[$index] ?? [];
                $title = !empty($details['title']) ? $details['title'] : ucfirst($accommodation_type) . ' ' . $room_id;
                $price = 0;
                if (!empty($details['price']) && floatval($details['price']) > 0) {
                    $price = floatval($details['price']);
                } elseif (!empty($details['earlybird_price']) && floatval($details['earlybird_price']) > 0) {
                    $price = floatval($details['earlybird_price']);
                } else {
                    $price = floatval($settings['room_price'] ?? 0);
                }
                $selected_rooms[] = ['title' => $title, 'price' => $price];
            }
        }
    }
}
?>
<div class="aiohm-booking-mvp" id="checkout">
  <?php if(!$order): ?>
    <div class="aiohm-booking-mvp-notice">
      <h3>Booking Not Found</h3>
      <p>We couldn't locate your booking. Please try again or contact us if you need assistance.</p>
    </div>
  <?php else: ?>
    <div class="checkout-content">
      <div class="order-summary">
        <h4>Order Summary</h4>
        <p><strong>Order #<?php echo (int)$order->id; ?></strong> (<?php echo esc_html($booking_mode_label); ?> Booking)</p>
        <ul>
          <?php if ($rooms_enabled && !empty($selected_rooms)): ?>
          <!-- Detailed accommodation breakdown -->
          <?php if ($order->private_all): ?>
          <li class="accommodation-header">
            <span class="item-label"><?php 
              /* translators: %s: accommodation type (rooms, houses, apartments, etc.) */
              printf(esc_html__('Private Event (All %s):', 'aiohm-booking-mvp'), esc_html($accommodation_label)); 
            ?></span>
            <span class="item-value"></span>
          </li>
          <?php else: ?>
          <li class="accommodation-header">
            <span class="item-label"><?php 
              /* translators: %s: accommodation type (rooms, houses, apartments, etc.) */
              printf(esc_html__('Selected %s:', 'aiohm-booking-mvp'), esc_html($accommodation_label)); 
            ?></span>
            <span class="item-value"></span>
          </li>
          <?php endif; ?>
          <?php foreach ($selected_rooms as $room): ?>
          <li class="accommodation-item">
            <span class="item-label"><?php echo esc_html($room['title']); ?></span>
            <span class="item-value"><?php echo esc_html(number_format_i18n($room['price'], 2)); ?> <?php echo esc_html($order->currency); ?></span>
          </li>
          <?php endforeach; ?>
          <?php elseif ($rooms_enabled): ?>
          <li>
            <span class="item-label"><?php echo esc_html($accommodation_label); ?>:</span>
            <span class="item-value">
              <?php if($order->private_all): ?>
                <?php 
                  /* translators: %s: accommodation type (rooms, houses, apartments, etc.) */
                  printf(esc_html__('Private Event (All %s)', 'aiohm-booking-mvp'), esc_html($accommodation_label)); 
                ?>
              <?php else: ?>
                <?php echo (int)$order->rooms_qty; ?>
              <?php endif; ?>
            </span>
          </li>
          <?php endif; ?>

          <li>
            <span class="item-label"><?php esc_html_e('Total Amount:', 'aiohm-booking-mvp'); ?></span>
            <span class="item-value"><?php echo esc_html(number_format_i18n($order->total_amount,2)); ?> <?php echo esc_html($order->currency); ?></span>
          </li>
          <li class="deposit-row">
            <span class="item-label"><?php esc_html_e('Deposit Due:', 'aiohm-booking-mvp'); ?></span>
            <span class="item-value"><strong><?php echo esc_html(number_format_i18n($order->deposit_amount,2)); ?> <?php echo esc_html($order->currency); ?></strong></span>
          </li>
        </ul>
      </div>

      <div class="payment-section">
        <h4 class="payment-title">
          <span class="payment-icon">🔒</span>
          Secure Payment
        </h4>

        <?php if ($stripe_enabled || $paypal_ready): ?>
          <p class="payment-subtitle">Choose your preferred payment method to complete your booking securely:</p>

          <div class="payment-methods">
            <?php
            $payment_count = ($stripe_enabled ? 1 : 0) + ($paypal_ready ? 1 : 0);
            $payment_class = $payment_count == 1 ? 'single-method' : 'multiple-methods';
            ?>

            <div class="payment-grid <?php echo esc_attr($payment_class); ?>">

              <?php if ($stripe_enabled): ?>
              <div class="payment-method stripe-method">
                <div class="payment-header">
                  <div class="payment-logo">
                    <img src="<?php echo esc_url( aiohm_booking_mvp_asset_url('images/aiohm-booking-stripe.png') ); ?>" alt="Stripe" class="stripe-logo">
                  </div>
                  <div class="payment-info">
                    <h5>Credit Card</h5>
                    <p>Visa, Mastercard, American Express</p>
                  </div>
                </div>
                <button class="payment-btn stripe-btn" data-order-id="<?php echo (int)$order->id; ?>">
                  <span class="btn-text">Pay with Card</span>
                  <span class="btn-icon">→</span>
                </button>
              </div>
              <?php endif; ?>

              <?php if ($paypal_ready): ?>
              <div class="payment-method paypal-method">
                <div class="payment-header">
                  <div class="payment-logo">
                    <img src="<?php echo esc_url( aiohm_booking_mvp_asset_url('images/aiohm-booking-mvp-paypal.svg') ); ?>" alt="PayPal" class="paypal-logo">
                  </div>
                  <div class="payment-info">
                    <h5>PayPal</h5>
                    <p>Pay with your PayPal account</p>
                  </div>
                </div>
                <div id="paypal-button-container" class="paypal-container">
                  <!-- PayPal button will be rendered here -->
                </div>
                <div id="paypal-csp-warning" class="paypal-csp-warning aiohm-hidden"></div>
              </div>
              <?php endif; ?>

            </div>

            <?php if ($stripe_enabled && $paypal_ready): ?>
            <div class="payment-divider">
              <span>or</span>
            </div>
            <?php endif; ?>

          </div>

          <div class="payment-security">
            <div class="security-badges">
              <span class="security-badge">🔒 SSL Encrypted</span>
              <span class="security-badge">🛡️ Secure Checkout</span>
              <span class="security-badge">✅ PCI Compliant</span>
            </div>
          </div>

        <?php else: ?>
          <div class="payment-notice">
            <div class="notice-icon">⚙️</div>
            <h4>Payment Methods Not Configured</h4>
            <p>Payment methods are currently being set up. Please contact us to complete your booking or try again later.</p>
            <?php if ($paypal_enabled && empty($paypal_client_id)): ?>
            <p class="aiohm-notice-spacing">PayPal is enabled, but no Client ID is set in Settings → AIOHM Booking → Payments.</p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
