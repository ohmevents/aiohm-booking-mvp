<?php
$opts = \AIOHM\BookingMVP\Core\Settings::get_all();

// Accommodation booking only
$rooms_enabled = true;

$P = \AIOHM\BookingMVP\Core\Config::get_prices();
?>
<div class="aiohm-booking-mvp">
  <?php if(!$rooms_enabled): ?>
    <div class="aiohm-booking-mvp-notice">
        <h3>Booking System Configuration Needed</h3>
        <p>Your AIOHM Booking system is ready to go! Please enable room booking in <em>AIOHM Booking → Settings</em> to start accepting accommodation bookings.</p>
    </div>
  <?php else: ?>
    <form id="aiohm-booking-mvp-form">
      <input type="hidden" name="mode" value="rooms">
      <p><label><?php esc_html_e('Rooms', 'aiohm-booking-mvp'); ?><br><input type="number" name="rooms_qty" min="0" max="<?php echo (int)$P['available_rooms']; ?>" value="1"></label></p>
      <?php if($P['allow_private_all']): ?>
        <p><label><input type="checkbox" name="private_all" value="1"> <?php esc_html_e('Private event (book all rooms)', 'aiohm-booking-mvp'); ?></label></p>
      <?php endif; ?>

      <hr>
      <p><label><?php esc_html_e('Name', 'aiohm-booking-mvp'); ?><br><input type="text" name="name" required></label></p>
      <p><label><?php esc_html_e('Email', 'aiohm-booking-mvp'); ?><br><input type="email" name="email" required></label></p>

      <?php wp_nonce_field('wp_rest', '_wpnonce', false); ?>

      <p class="muted">
        <?php 
          /* translators: 1: formatted room price, 2: currency code, 3: deposit percentage */
          printf(esc_html__('Room price: %1$s %2$s, Deposit: %3$s%', 'aiohm-booking-mvp'), 
            esc_html(number_format_i18n($P['room_price'],2)), 
            esc_html($P['currency']), 
            esc_html($P['deposit_percent'])
          ); 
        ?>
      </p>

      <p><button type="submit"><?php esc_html_e('Hold & Continue', 'aiohm-booking-mvp'); ?></button></p>
    </form>

  <?php endif; ?>
</div>