<?php
if (!defined('ABSPATH')) { exit; }

$product_names = \AIOHM\BookingMVP\Core\Config::get_product_names();
?>
<div class="wrap aiohm-booking-mvp-admin">
    <div class="aiohm-header">
        <div class="aiohm-header-content">
            <div class="aiohm-logo">
                <img src="<?php echo esc_url( \AIOHM\BookingMVP\Core\Assets::get_url('images/aiohm-booking-OHM_logo-black.svg') ); ?>" alt="AIOHM" class="aiohm-header-logo">
            </div>
            <div class="aiohm-header-text">
                <h1>AIOHM Booking Calendar</h1>
                <p class="aiohm-tagline">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s is the product type (room, accommodation, etc.) */
                        __('Visual booking calendar showing %s availability and reservations in real-time.', 'aiohm-booking-mvp'),
                        strtolower($product_names['singular'])
                    ));
                    ?>
                </p>
            </div>
        </div>
    </div>

    <?php $calendar->render_calendar(); ?>
</div>
