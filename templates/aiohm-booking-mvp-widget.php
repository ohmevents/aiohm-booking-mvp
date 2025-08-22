<?php
$opts = \AIOHM\BookingMVP\Core\Settings::get_all();

// Determine brand color from settings with sane defaults
$brand_color = isset($opts['form_primary_color']) ? sanitize_hex_color($opts['form_primary_color']) : '#457d58';
if (empty($brand_color)) { $brand_color = '#457d58'; }

// Helper to convert hex to rgba with alpha for light backgrounds
$hex = ltrim($brand_color, '#');
$r = strlen($hex) === 6 ? hexdec(substr($hex, 0, 2)) : 69;
$g = strlen($hex) === 6 ? hexdec(substr($hex, 2, 2)) : 125;
$b = strlen($hex) === 6 ? hexdec(substr($hex, 4, 2)) : 88;
$brand_light = 'rgba(' . intval($r) . ', ' . intval($g) . ', ' . intval($b) . ', 0.1)'; // For backgrounds
$brand_border = 'rgba(' . intval($r) . ', ' . intval($g) . ', ' . intval($b) . ', 0.4)'; // For borders

// Determine text color
$text_color_raw = $opts['form_text_color'] ?? '';
$text_color = sanitize_hex_color($text_color_raw);

// Accommodation booking only
$rooms_enabled = true;

$P = \AIOHM\BookingMVP\Core\Config::get_prices();

// Get dynamic product names
$product_names = \AIOHM\BookingMVP\Core\Config::get_product_names();
$singular = $product_names['singular_cap'];
$plural = $product_names['plural_cap'];
?>
<style>
.accommodation-wheel-container {
    height: 170px; /* Shows roughly 1 full item and 2 partials */
    overflow-y: scroll;
    border: 1px solid var(--ohm-gray-200, #e9ecef);
    border-radius: 12px;
    position: relative;
    -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
    scroll-snap-type: y mandatory;
    scrollbar-width: none; /* Hide scrollbar for Firefox */
}

.accommodation-wheel-container::-webkit-scrollbar {
    display: none; /* Hide scrollbar for Chrome, Safari, and Opera */
}

/* Add a gradient overlay to fade top and bottom, indicating scrollability */
.accommodation-wheel-container::before,
.accommodation-wheel-container::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    height: 60px; /* Adjust fade height */
    z-index: 1;
    pointer-events: none;
}

.accommodation-wheel-container::before {
    top: 0;
    background: linear-gradient(to bottom, white 20%, rgba(255, 255, 255, 0));
}

.accommodation-wheel-container::after {
    bottom: 0;
    background: linear-gradient(to top, white 20%, rgba(255, 255, 255, 0));
}

.accommodation-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 45px 12px; /* (container_height - item_height) / 2 */
}

.accommodation-item {
    display: flex;
    align-items: center;
    background-color: #f8f9fa;
    border: 2px solid var(--ohm-border, #cbddd1);
    border-radius: 8px;
    padding: 16px;
    cursor: pointer;
    transition: all 0.2s ease-in-out;
    position: relative;
    height: 80px;
    scroll-snap-align: center;
    flex-shrink: 0; /* Prevent items from shrinking */
}

.accommodation-item:hover {
    border-color: var(--ohm-primary, #457d58);
    background-color: #fff;
}

.accommodation-item input[type="checkbox"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}

.accommodation-item-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    padding-left: 32px; /* Space for custom checkbox */
}

.accommodation-title {
    font-weight: 500;
    color: #343a40;
}

.accommodation-price {
    font-weight: 600;
    color: var(--ohm-primary, #457d58);
}

/* Custom checkbox */
.accommodation-item::before {
    content: '';
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    border: 2px solid #adb5bd;
    border-radius: 4px;
    background-color: #fff;
    transition: all 0.2s ease;
}

.accommodation-item:has(input:checked) {
    background-color: var(--ohm-primary-light, rgba(69, 125, 88, 0.1));
    border-color: var(--ohm-primary, #457d58);
}

.accommodation-item:has(input:checked)::before {
    background-color: var(--ohm-primary, #457d58);
    border-color: var(--ohm-primary, #457d58);
}

/* Checkmark */
.accommodation-item::after {
    content: '✔';
    position: absolute;
    left: 19px;
    top: 50%;
    transform: translateY(-50%) scale(0);
    font-size: 14px;
    color: white;
    transition: transform 0.2s ease;
    line-height: 1;
}

.accommodation-item:has(input:checked)::after {
    transform: translateY(-50%) scale(1);
}

.input-help {
    font-size: 13px !important;
    color: #6c757d !important;
    margin-top: 8px !important;
}

/* Private Property Exclusive Option Styling */
.private-property-option {
    position: relative;
    background: linear-gradient(135deg, var(--ohm-primary-light, rgba(69, 125, 88, 0.05)) 0%, rgba(255, 255, 255, 0.8) 100%);
    border: 2px solid var(--ohm-primary, #457d58);
    border-radius: 12px;
    padding: 20px;
    margin-top: 24px !important;
    box-shadow: 0 4px 12px rgba(69, 125, 88, 0.1);
    transition: all 0.3s ease;
}

.private-property-option:hover {
    box-shadow: 0 6px 20px rgba(69, 125, 88, 0.15);
    transform: translateY(-2px);
}

.private-property-label {
    position: relative;
    display: flex !important;
    align-items: center;
    gap: 12px;
    margin-bottom: 0 !important;
    cursor: pointer;
}

.private-checkbox {
    width: 20px !important;
    height: 20px !important;
    border: 2px solid var(--ohm-primary, #457d58) !important;
    border-radius: 4px;
    background: white;
    accent-color: var(--ohm-primary, #457d58);
    cursor: pointer;
}

.private-label-text {
    font-weight: 600 !important;
    font-size: 16px !important;
    color: var(--ohm-primary, #457d58) !important;
    margin: 0 !important;
}

.private-badge {
    background: var(--ohm-primary, #457d58);
    color: white;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 4px 8px;
    border-radius: 12px;
    margin-left: auto;
}

.private-help-text {
    color: var(--ohm-primary, #457d58) !important;
    font-weight: 500 !important;
    margin-top: 12px !important;
    font-size: 14px !important;
}

.private-property-option.selected {
    background: linear-gradient(135deg, var(--ohm-primary, #457d58) 0%, rgba(69, 125, 88, 0.9) 100%);
    color: white;
}

.private-property-option.selected .private-label-text,
.private-property-option.selected .private-help-text {
    color: white !important;
}

.private-property-option.selected .private-badge {
    background: rgba(255, 255, 255, 0.2);
    color: white;
}
</style>
<div id="<?php echo esc_attr($instance_id); ?>" class="aiohm-booking-mvp-modern" style="--ohm-primary: <?php echo esc_attr($brand_color); ?>; --ohm-primary-hover: <?php echo esc_attr($brand_color); ?>; --ohm-primary-light: <?php echo esc_attr($brand_light); ?>; --ohm-border: <?php echo esc_attr($brand_border); ?>;">
  <?php if(!$rooms_enabled): ?>
    <div class="aiohm-booking-mvp-notice">
        <div class="notice-icon">⚙️</div>
        <h3><?php esc_html_e('Ready to Start Booking', 'aiohm-booking-mvp'); ?></h3>
        <p><?php esc_html_e('Enable accommodation booking in Settings to begin accepting reservations', 'aiohm-booking-mvp'); ?></p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=aiohm-booking-mvp-settings')); ?>" class="notice-btn"><?php esc_html_e('Configure Modules', 'aiohm-booking-mvp'); ?></a>
    </div>
  <?php else: ?>
    <div class="aiohm-booking-mvp-card">
      <div class="booking-header">
        <h2 class="booking-title">
          <?php if(!empty($opts['form_title'])): ?>
            <?php echo esc_html($opts['form_title']); ?>
          <?php else: ?>
            Reserve Your Stay
          <?php endif; ?>
        </h2>
        <p class="booking-subtitle">
          <?php if(!empty($opts['form_subtitle'])): ?>
            <?php echo esc_html($opts['form_subtitle']); ?>
          <?php else: ?>
            Conscious booking made simple
          <?php endif; ?>
      </div>

      <form id="aiohm-booking-mvp-form" class="booking-form">

        <!-- Date Selection Section -->
        <div class="form-section">

          <?php if($rooms_enabled): ?>
            <!-- Visual Calendar for Room Bookings -->
            <div class="booking-calendar-container">
              <div class="calendar-header">
                <button type="button" class="calendar-nav" id="prevMonth">‹</button>
                <h4 class="calendar-month-year" id="currentMonth"></h4>
                <button type="button" class="calendar-nav" id="nextMonth">›</button>
              </div>

              <div class="calendar-legend">
                <div class="legend-item">
                  <span class="legend-color available"></span>
                  <span><?php esc_html_e('Available', 'aiohm-booking-mvp'); ?></span>
                </div>
                <div class="legend-item">
                  <span class="legend-color occupied"></span>
                  <span><?php esc_html_e('Occupied', 'aiohm-booking-mvp'); ?></span>
                </div>
                <div class="legend-item">
                  <span class="legend-color selected"></span>
                  <span><?php esc_html_e('Selected', 'aiohm-booking-mvp'); ?></span>
                </div>
                <div class="legend-item">
                  <span class="legend-color special-pricing"></span>
                  <span><?php esc_html_e('Special Pricing', 'aiohm-booking-mvp'); ?></span>
                </div>
                <div class="legend-item">
                  <span class="legend-color private-only"></span>
                  <span><?php esc_html_e('Private Event', 'aiohm-booking-mvp'); ?></span>
                </div>
              </div>

              <div class="calendar-grid" id="calendarGrid">
                <!-- Calendar will be generated by JavaScript -->
              </div>
            </div>

            <div class="selected-dates-info">
              <div class="dates-display-inline">
                <div class="checkin-display">
                  <strong><?php esc_html_e('Check-in:', 'aiohm-booking-mvp'); ?></strong> <span id="checkinDisplayText"><?php esc_html_e('Select check-in first', 'aiohm-booking-mvp'); ?></span>
                </div>
                <div class="checkout-display">
                  <strong><?php esc_html_e('Check-out:', 'aiohm-booking-mvp'); ?></strong> <span id="checkoutDisplay"><?php esc_html_e('Select check-in first', 'aiohm-booking-mvp'); ?></span>
                </div>
              </div>
              
              <div class="form-row form-row-2">
                <div class="input-group">
                  <label class="input-label"><?php esc_html_e('Duration (nights)', 'aiohm-booking-mvp'); ?></label>
                  <div class="quantity-selector">
                    <button type="button" class="qty-btn qty-minus" data-target="stay_duration">-</button>
                    <input type="number" name="stay_duration" id="stay_duration" min="1" max="30" value="1" class="qty-input" aria-label="Duration in nights">
                    <button type="button" class="qty-btn qty-plus" data-target="stay_duration">+</button>
                  </div>
                </div>
                <div class="input-group">
                  <label class="input-label" for="guests_qty"><?php esc_html_e('Number of Guests', 'aiohm-booking-mvp'); ?></label>
                  <div class="quantity-selector">
                    <button type="button" class="qty-btn qty-minus" data-target="guests_qty">-</button>
                    <input type="number" name="guests_qty" id="guests_qty" min="1" max="20" value="1" class="qty-input" aria-label="Number of guests">
                    <button type="button" class="qty-btn qty-plus" data-target="guests_qty">+</button>
                  </div>
                </div>
              </div>
              
              <!-- Hidden inputs for form submission -->
              <input type="date" name="checkin_date" id="checkinDisplay" style="display: none;">
              <input type="date" name="checkout_date" id="checkoutHidden" style="display: none;">
            </div>

          <?php else: ?>
            <!-- Simple Date Picker for Tickets -->
            <div class="form-row">
              <div class="input-group">
                <label class="input-label">Event Date *</label>
                <input type="date" name="checkin_date" required class="form-input date-input" min="<?php echo esc_attr(current_time('Y-m-d')); ?>">
              </div>
            </div>
          <?php endif; ?>

          <!-- Hidden inputs for form processing -->
          <input type="hidden" name="checkout_date" id="checkoutHidden">
        </div>

        <?php if($rooms_enabled): ?>
          <div class="form-section">
            <h3 class="section-title">
<?php echo esc_html($singular); ?> Selection
            </h3>

            <div class="input-group">
                <div class="accommodation-wheel-container">
                    <div class="accommodation-list" id="accommodation-list">
                        <?php
                        $accommodation_details = get_option('aiohm_booking_mvp_accommodations_details', []);
                        $available_rooms = intval($P['available_rooms'] ?? 0);
                        for ($i = 0; $i < $available_rooms; $i++) :
                            $details = $accommodation_details[$i] ?? ['title' => $singular . ' ' . ($i + 1), 'description' => '', 'price' => $P['room_price'], 'earlybird_price' => ''];
                            $price = !empty($details['price']) ? floatval($details['price']) : $P['room_price'];
                            $early = !empty($details['earlybird_price']) ? floatval($details['earlybird_price']) : 0;
                        ?>
                            <label class="accommodation-item">
                                <input type="checkbox" name="accommodations[]" class="accommodation-checkbox" 
                                       value="<?php echo esc_attr($i); ?>" 
                                       data-price="<?php echo esc_attr($price); ?>" 
                                       data-earlybird="<?php echo esc_attr($early); ?>">
                                <div class="accommodation-item-content">
                                    <span class="accommodation-title"><?php echo esc_html($details['title']); ?></span>
                                    <span class="accommodation-price"><?php echo esc_html($P['currency']); ?> <?php echo number_format($price, 2); ?></span>
                                </div>
                            </label>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <?php if($P['allow_private_all']): ?>
              <div class="input-group aiohm-mt-20 private-property-option">
                <label class="private-property-label">
                  <input type="checkbox" name="private_all" id="private_all_checkbox" value="1" 
                         aria-describedby="private-help" class="private-checkbox">
                  <span class="private-label-text"><?php esc_html_e('Book Entire Property', 'aiohm-booking-mvp'); ?></span>
                  <span class="private-badge">Exclusive</span>
                </label>
                <div id="private-help" class="input-help private-help-text">
                    <?php esc_html_e('Select this option to book the entire property exclusively.', 'aiohm-booking-mvp'); ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>


        <div class="form-section">
          <h3 class="section-title">
            Contact Information
          </h3>

          <div class="form-row form-row-2">
            <div class="input-group">
              <label class="input-label" for="guest-name"><?php esc_html_e('Full Name', 'aiohm-booking-mvp'); ?> *</label>
              <input type="text" name="name" id="guest-name" required class="form-input" 
                     placeholder="<?php esc_attr_e('Enter your name', 'aiohm-booking-mvp'); ?>" 
                     aria-required="true" aria-describedby="name-help">
              <div id="name-help" class="input-help">
                  <?php esc_html_e('Please enter your full name as it appears on your ID.', 'aiohm-booking-mvp'); ?>
              </div>
            </div>
            <div class="input-group">
              <label class="input-label" for="guest-email"><?php esc_html_e('Email Address', 'aiohm-booking-mvp'); ?> *</label>
              <input type="email" name="email" id="guest-email" required class="form-input" 
                     placeholder="<?php esc_attr_e('Enter your email', 'aiohm-booking-mvp'); ?>" 
                     aria-required="true" aria-describedby="email-help">
              <div id="email-help" class="input-help">
                  <?php esc_html_e('We will send booking confirmations to this email address.', 'aiohm-booking-mvp'); ?>
              </div>
            </div>
          </div>


          <div class="form-row form-row-2">
            <?php $is_visible = !empty($opts['form_field_arrival_time']); ?>
            <div class="input-group arrival_time-field" <?php if (!$is_visible) echo 'style="display: none;"'; ?>>
              <label class="input-label" for="arrival-time"><?php esc_html_e('Estimated Arrival Time', 'aiohm-booking-mvp'); ?></label>
              <input type="text" name="estimated_arrival_time" id="arrival-time" class="form-input" placeholder="e.g., 15:00 - 17:00">
            </div>

          </div>

          <?php $is_visible = !empty($opts['form_field_pets']); ?>
          <div class="form-row pets-field" <?php if (!$is_visible) echo 'style="display: none;"'; ?>>
            <div class="input-group">
                <label class="checkbox-container">
                  <input type="checkbox" name="bringing_pets" id="bringing_pets_checkbox" value="1">
                  <span class="checkmark"></span>
                  <span class="checkbox-label"><?php esc_html_e('I am bringing pets', 'aiohm-booking-mvp'); ?></span>
                </label>
            </div>
            <div class="input-group pet-details-field" style="display: none;">
              <label class="input-label" for="pet-details"><?php esc_html_e('Pet Details', 'aiohm-booking-mvp'); ?></label>
              <textarea name="pet_details" id="pet-details" class="form-input" placeholder="<?php esc_attr_e('Please specify type and number of pets', 'aiohm-booking-mvp'); ?>"></textarea>
            </div>
          </div>

          <!-- Additional Contact Fields - Dynamic Order -->
          <?php
          // Get field order from settings
          $field_order = $opts['field_order'] ?? ['address', 'age', 'company', 'country', 'arrival_time', 'phone', 'special_requests', 'vat'];
          $min_age = intval($opts['min_age'] ?? 0);
          
          // Define field definitions
          $field_definitions = [
            'address' => [
              'type' => 'text',
              'label' => 'Address',
              'placeholder' => 'Enter your address',
              'class' => 'address-field'
            ],
            'country' => [
              'type' => 'text', 
              'label' => 'Country',
              'placeholder' => 'Enter your country',
              'class' => 'country-field'
            ],
            'age' => [
              'type' => 'number',
              'label' => 'Age' . ($min_age > 0 ? ' (minimum ' . esc_html($min_age) . ')' : ''),
              'placeholder' => 'Enter your age' . ($min_age > 0 ? ' (minimum ' . esc_attr($min_age) . ')' : ''),
              'class' => 'age-field',
              'attributes' => ($min_age > 0 ? 'min="' . esc_attr($min_age) . '"' : '') . ' max="99"'
            ],
            'vat' => [
              'type' => 'text',
              'label' => __('VAT Number', 'aiohm-booking-mvp'),
              'placeholder' => __('For business invoices', 'aiohm-booking-mvp'),
              'class' => 'vat-field',
              'id' => 'vat-number'
            ],
            'company' => [
              'type' => 'text',
              'label' => 'Company / Organization', 
              'placeholder' => 'Enter company name',
              'class' => 'company-field'
            ],
            'phone' => [
              'type' => 'tel',
              'label' => 'Phone Number',
              'placeholder' => 'Enter your phone number',
              'class' => 'phone-field'
            ],
            'special_requests' => [
              'type' => 'textarea',
              'label' => 'Special Requests',
              'placeholder' => 'Any special requests or notes...',
              'class' => 'special_requests-field',
              'rows' => 3
            ],
            'arrival_time' => [
              'type' => 'text',
              'label' => __('Estimated Arrival Time', 'aiohm-booking-mvp'),
              'placeholder' => 'e.g., 15:00 - 17:00',
              'class' => 'arrival-time-field',
              'id' => 'arrival-time'
            ]
          ];
          
          // Render fields in the specified order
          foreach ($field_order as $field_key) {
            if (!isset($field_definitions[$field_key])) continue;
            
            $field = $field_definitions[$field_key];
            $is_visible = !empty($opts['form_field_' . $field_key]);
            $is_required = !empty($opts['form_field_' . $field_key . '_required']);
            
            if (!$is_visible) continue; // Skip if field is not enabled
            ?>
            <div class="form-row <?php echo esc_attr($field['class']); ?>">
              <div class="input-group">
                <label class="input-label" <?php if (!empty($field['id'])) echo 'for="' . esc_attr($field['id']) . '"'; ?>><?php echo esc_html($field['label']); ?><?php if ($is_required) echo ' *'; ?></label>
                <?php if ($field['type'] === 'textarea'): ?>
                  <textarea name="<?php echo esc_attr(str_replace('_', '_', $field_key)); ?>" 
                           <?php if ($is_required) echo 'required'; ?> 
                           class="form-input" 
                           rows="<?php echo esc_attr($field['rows'] ?? 3); ?>" 
                           placeholder="<?php echo esc_attr($field['placeholder']); ?>"></textarea>
                <?php else: ?>
                  <input type="<?php echo esc_attr($field['type']); ?>" 
                         name="<?php echo esc_attr($field_key === 'vat' ? 'vat_number' : ($field_key === 'arrival_time' ? 'estimated_arrival_time' : $field_key)); ?>" 
                         <?php if (!empty($field['id'])) echo 'id="' . esc_attr($field['id']) . '"'; ?>
                         <?php if ($is_required) echo 'required'; ?> 
                         class="form-input" 
                         placeholder="<?php echo esc_attr($field['placeholder']); ?>"
                         <?php if (!empty($field['attributes'])) echo wp_kses_post($field['attributes']); ?>>
                <?php endif; ?>
              </div>
            </div>
            <?php
          }
          ?>

        </div>

        <div class="pricing-section">
          <?php $earlybird_days = isset($opts['earlybird_days']) ? intval($opts['earlybird_days']) : 30; ?>
          <div class="pricing-summary" data-currency="<?php echo esc_attr($P['currency']); ?>" data-deposit-percent="<?php echo esc_attr($P['deposit_percent']); ?>" data-earlybird-days="<?php echo esc_attr($earlybird_days); ?>">
            <div class="price-row">
              <span class="price-label"><?php esc_html_e('Total:', 'aiohm-booking-mvp'); ?></span>
              <span class="price-value total-amount"><?php echo esc_html($P['currency']); ?> 0.00</span>
            </div>
            <?php // Early Bird row controlled by JS; hidden by default ?>
            <div class="price-row earlybird-row aiohm-hidden">
              <span class="price-label"><?php
                if (!empty($earlybird_days) && $earlybird_days > 0) {
                  /* translators: %d: number of days for early bird discount */
                  printf(esc_html__('Early Bird (%d days):', 'aiohm-booking-mvp'), esc_html($earlybird_days));
                } else {
                  esc_html_e('Early Bird:', 'aiohm-booking-mvp');
                }
              ?></span>
              <span class="price-value earlybird-amount"><?php echo esc_html($P['currency']); ?> 0.00</span>
            </div>
            <div class="price-row deposit-row">
              <span class="price-label"><?php esc_html_e('Deposit Required:', 'aiohm-booking-mvp'); ?></span>
              <span class="price-value deposit-amount"><?php echo esc_html($P['currency']); ?> 0.00</span>
            </div>
            <div class="price-row saving-row aiohm-hidden">
              <span class="price-label"><?php esc_html_e('You save:', 'aiohm-booking-mvp'); ?></span>
              <span class="price-value saving-amount"><?php echo esc_html($P['currency']); ?> 0.00</span>
            </div>
          </div>
        </div>

        <?php wp_nonce_field('wp_rest', '_wpnonce', false); ?>
        
        <div class="form-actions">
          <button type="submit" class="booking-btn">
            <span class="btn-text">Continue to Booking</span>
            <span class="btn-icon">→</span>
          </button>
          <p class="form-note">Secure booking • No hidden fees • Cancel anytime</p>
        </div>

        <input type="hidden" name="mode" value="rooms">
      </form>
    </div>
  <?php endif; ?>
</div>
