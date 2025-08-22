<?php
/**
 * Admin Help page template for AIOHM Booking - Support Center with Booking Journey
 * OHM branded design with debug tools and booking-specific user journey
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get current user info for pre-filling forms
$current_user = wp_get_current_user();
$user_email = $current_user->user_email;
$site_url = get_site_url();
$plugin_version = defined('AIOHM_BOOKING_VERSION') ? AIOHM_BOOKING_VERSION : '1.0.0';
$wp_version = get_bloginfo('version');
$php_version = PHP_VERSION;

// Get plugin settings for debugging
$settings = \AIOHM\BookingMVP\Core\Settings::get_all();
$enabled_modules = [];
if (!empty($settings['enable_rooms'])) $enabled_modules[] = 'Rooms';
$enabled_modules_str = !empty($enabled_modules) ? implode(', ', $enabled_modules) : 'None';
?>

<div class="wrap aiohm-booking-mvp-admin aiohm-booking-mvp-help-page">

    <div class="aiohm-help-container">

        <!-- Left Column: Debug & Support Tools -->
        <div class="aiohm-help-main">

            <!-- System Information -->
            <div class="support-card">
                <div class="card-header">
                    <span class="dashicons dashicons-info card-icon"></span>
                    <h2><?php esc_html_e('System Information', 'aiohm-booking-mvp'); ?></h2>
                </div>
                <div class="card-content">
                    <div class="system-info-grid">
                        <div class="info-item">
                            <strong><?php esc_html_e('Plugin Version:', 'aiohm-booking-mvp'); ?></strong>
                            <span><?php echo esc_html($plugin_version); ?></span>
                        </div>
                        <div class="info-item">
                            <strong><?php esc_html_e('WordPress Version:', 'aiohm-booking-mvp'); ?></strong>
                            <span><?php echo esc_html($wp_version); ?></span>
                        </div>
                        <div class="info-item">
                            <strong><?php esc_html_e('PHP Version:', 'aiohm-booking-mvp'); ?></strong>
                            <span><?php echo esc_html($php_version); ?></span>
                        </div>
                        <div class="info-item">
                            <strong><?php esc_html_e('Enabled Modules:', 'aiohm-booking-mvp'); ?></strong>
                            <span><?php echo esc_html($enabled_modules_str); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Report Issue Section -->
            <div class="support-card">
                <div class="card-header">
                    <span class="dashicons dashicons-admin-tools card-icon"></span>
                    <h2><?php esc_html_e('Report Issue', 'aiohm-booking-mvp'); ?></h2>
                </div>
                <div class="card-content">
                    <form id="combined-support-form" class="support-form">
                        <!-- Basic Information Section -->
                        <h3><?php esc_html_e('Basic Information', 'aiohm-booking-mvp'); ?></h3>
                        <div class="form-row-columns">
                            <div class="form-column">
                                <div class="form-row">
                                    <label for="support-email"><?php esc_html_e('Your Email:', 'aiohm-booking-mvp'); ?></label>
                                    <input type="email" id="support-email" name="email" value="<?php echo esc_attr($user_email); ?>" required>
                                </div>
                            </div>
                            <div class="form-column">
                                <div class="form-row">
                                    <label for="report-type"><?php esc_html_e('Type:', 'aiohm-booking-mvp'); ?></label>
                                    <select id="report-type" name="type" required>
                                        <option value=""><?php esc_html_e('Select type...', 'aiohm-booking-mvp'); ?></option>
                                        <option value="bug-report"><?php esc_html_e('🐛 Bug Report', 'aiohm-booking-mvp'); ?></option>
                                        <option value="feature-request"><?php esc_html_e('💡 Feature Request', 'aiohm-booking-mvp'); ?></option>
                                        <option value="setup-help"><?php esc_html_e('🔧 Setup Help', 'aiohm-booking-mvp'); ?></option>
                                        <option value="booking-issues"><?php esc_html_e('📅 Booking System Issues', 'aiohm-booking-mvp'); ?></option>
                                        <option value="payment-issues"><?php esc_html_e('💳 Payment Issues', 'aiohm-booking-mvp'); ?></option>
                                        <option value="calendar-issues"><?php esc_html_e('🗓️ Calendar Issues', 'aiohm-booking-mvp'); ?></option>
                                        <option value="widget-issues"><?php esc_html_e('🎛️ Widget Issues', 'aiohm-booking-mvp'); ?></option>
                                        <option value="performance"><?php esc_html_e('⚡ Performance Issues', 'aiohm-booking-mvp'); ?></option>
                                        <option value="other"><?php esc_html_e('❓ Other', 'aiohm-booking-mvp'); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                    <!-- Title Section -->
                    <div class="support-form-section">
                        <h3><?php esc_html_e('Title', 'aiohm-booking-mvp'); ?></h3>
                        <div class="form-row">
                            <input type="text" id="support-title" name="title" placeholder="Brief description of the issue or feature..." required>
                        </div>
                    </div>

                    <!-- Description and Debug Information Section -->
                    <div class="support-form-section">
                        <h3><?php esc_html_e('Description and Debug Information', 'aiohm-booking-mvp'); ?></h3>
                        <div class="form-row form-row-columns">
                            <div class="form-column">
                                <label for="debug-information"><?php esc_html_e('Debug Information:', 'aiohm-booking-mvp'); ?></label>
                                <textarea id="debug-information" name="debug_info" rows="8" readonly>This will be automatically filled when you click the "Collect Debug Information" button above.</textarea>
                            </div>
                            <div class="form-column">
                                <label for="support-description"><?php esc_html_e('Detailed Description:', 'aiohm-booking-mvp'); ?></label>
                                <textarea id="support-description" name="description" rows="8" placeholder="For Bug Reports:
- What you were trying to do
- What happened instead
- Any error messages you saw
- Steps to reproduce the issue

For Feature Requests:
- What problem would this solve?
- How would it work?
- Who would benefit from this feature?
- Any examples or references?" required></textarea>
                            </div>
                        </div>

                        <div class="form-row checkbox-row checkbox-with-padding">
                            <label class="checkbox-label">
                                <input type="checkbox" id="include-debug-info" name="include_debug" checked>
                                <?php esc_html_e('Include debug information with this report', 'aiohm-booking-mvp'); ?>
                            </label>
                        </div>

                        <!-- Debug Action Buttons -->
                        <div class="debug-buttons-section">
                            <div class="debug-buttons inline-buttons">
                                <button id="collect-debug-info" class="button button-primary debug-action-btn">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php esc_html_e('Collect Debug Info', 'aiohm-booking-mvp'); ?>
                                </button>
                                <button type="submit" class="button button-secondary debug-action-btn send-report-btn">
                                    <span class="dashicons dashicons-email-alt"></span>
                                    <?php esc_html_e('Send Report', 'aiohm-booking-mvp'); ?>
                                </button>
                            </div>
                        </div>

                        <!-- Debug Output Area -->
                        <div id="debug-output" class="debug-output-area">
                            <h4><?php esc_html_e('Debug Information', 'aiohm-booking-mvp'); ?></h4>
                            <textarea id="debug-text" rows="12" readonly></textarea>
                            <div class="debug-actions-bottom">
                                <button id="copy-debug-info" class="button button-secondary">
                                    <span class="dashicons dashicons-clipboard"></span>
                                    <?php esc_html_e('Copy to Clipboard', 'aiohm-booking-mvp'); ?>
                                </button>
                                <button id="download-debug-info" class="button button-secondary">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php esc_html_e('Download as File', 'aiohm-booking-mvp'); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                    </div>
                </div>
            </div>

            <!-- Quick Resources -->
            <div class="support-card">
                <div class="card-header">
                    <span class="dashicons dashicons-media-document card-icon"></span>
                    <h2><?php esc_html_e('Quick Resources', 'aiohm-booking-mvp'); ?></h2>
                </div>
                <div class="card-content">
                    <div class="resource-links">
                        <a href="https://www.aiohm.app/docs/booking" target="_blank" class="resource-link">
                            <span class="dashicons dashicons-media-document"></span>
                            <div>
                                <strong><?php esc_html_e('Documentation', 'aiohm-booking-mvp'); ?></strong>
                                <p><?php esc_html_e('Complete booking system setup and usage guides', 'aiohm-booking-mvp'); ?></p>
                            </div>
                        </a>
                        <a href="https://chat.whatsapp.com/I9A1LfBfW4i5dv4UWS27qD" target="_blank" class="resource-link">
                            <span class="dashicons dashicons-groups"></span>
                            <div>
                                <strong><?php esc_html_e('WhatsApp Support Group', 'aiohm-booking-mvp'); ?></strong>
                                <p><?php esc_html_e('Join our community for quick help and tips', 'aiohm-booking-mvp'); ?></p>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: User Journey -->
        <div class="aiohm-help-sidebar">
            <div class="journey-card">
                <div class="journey-header">
                    <h2><?php esc_html_e('Your AIOHM Booking Journey', 'aiohm-booking-mvp'); ?></h2>
                    <p><?php esc_html_e('From basic setup to seamless booking experiences - your path to booking transformation', 'aiohm-booking-mvp'); ?></p>
                </div>

                <div class="journey-steps">
                    <!-- Step 1: Installation & Setup -->
                    <div class="journey-step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h3><?php esc_html_e('Installation & Setup', 'aiohm-booking-mvp'); ?></h3>
                            <p><?php esc_html_e('Install AIOHM Booking plugin and configure your basic booking settings.', 'aiohm-booking-mvp'); ?></p>
                            <div class="step-features">
                                <span class="feature-tag">✓ Plugin Installation</span>
                                <span class="feature-tag">✓ Module Configuration</span>
                                <span class="feature-tag">✓ Basic Settings</span>
                            </div>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=aiohm-booking-mvp-settings')); ?>" class="step-button"><?php esc_html_e('Configure Settings', 'aiohm-booking-mvp'); ?></a>
                        </div>
                    </div>

                    <!-- Step 2: Choose Your Booking Mode -->
                    <div class="journey-step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h3><?php esc_html_e('Choose Your Booking Mode', 'aiohm-booking-mvp'); ?></h3>
                            <p><?php esc_html_e('Select Rooms-only, Seats-only, or Both modes based on your business needs.', 'aiohm-booking-mvp'); ?></p>
                            <div class="step-features">
                                <span class="feature-tag">✓ Rooms Module</span>
                                <span class="feature-tag">✓ Seats Module</span>
                                <span class="feature-tag">✓ Flexible Configuration</span>
                            </div>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=aiohm-booking-mvp-settings')); ?>" class="step-button"><?php esc_html_e('Configure Modules', 'aiohm-booking-mvp'); ?></a>
                        </div>
                    </div>

                    <!-- Step 3: Set Up Your Inventory -->
                    <div class="journey-step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h3><?php esc_html_e('Set Up Your Inventory', 'aiohm-booking-mvp'); ?></h3>
                            <p><?php esc_html_e('Configure rooms, events, and capacity settings for your booking system.', 'aiohm-booking-mvp'); ?></p>
                            <div class="step-features">
                                <span class="feature-tag">✓ Room Management</span>
                                <span class="feature-tag">✓ Event Creation</span>
                                <span class="feature-tag">✓ Capacity Settings</span>
                            </div>
                            <?php if (!empty($settings['enable_rooms'])): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=aiohm-booking-mvp-accommodations')); ?>" class="step-button"><?php esc_html_e('Manage Inventory', 'aiohm-booking-mvp'); ?></a>
                            <?php else: ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=aiohm-booking-mvp-settings')); ?>" class="step-button"><?php esc_html_e('Enable Modules First', 'aiohm-booking-mvp'); ?></a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Step 4: Deploy Booking Widgets -->
                    <div class="journey-step">
                        <div class="step-number">4</div>
                        <div class="step-content">
                            <h3><?php esc_html_e('Deploy Booking Widgets', 'aiohm-booking-mvp'); ?></h3>
                            <p><?php esc_html_e('Add auto-adapting booking widgets to your site with shortcodes and manage bookings.', 'aiohm-booking-mvp'); ?></p>
                            <div class="step-features">
                                <span class="feature-tag">✓ Auto-Adapting Widget</span>
                                <span class="feature-tag">✓ Payment Integration</span>
                                <span class="feature-tag">✓ Order Management</span>
                            </div>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=aiohm-booking-mvp-orders')); ?>" class="step-button"><?php esc_html_e('Manage Bookings', 'aiohm-booking-mvp'); ?></a>
                        </div>
                    </div>
                </div>

                <!-- Translation Instructions -->
                <div class="translation-guide">
                    <div class="card-header">
                        <span class="dashicons dashicons-translation card-icon"></span>
                        <h2><?php esc_html_e('Translation Instructions', 'aiohm-booking-mvp'); ?></h2>
                    </div>
                    <div class="card-content">
                        <h3><?php esc_html_e('Easy Plugin Translation', 'aiohm-booking-mvp'); ?></h3>
                        <p><?php esc_html_e('Want to translate this plugin into your language? Follow these simple steps:', 'aiohm-booking-mvp'); ?></p>
                        
                        <ol class="translation-steps">
                            <li>
                                <strong><?php esc_html_e('Download a translation tool like Poedit (free) from', 'aiohm-booking-mvp'); ?></strong> 
                                <a href="https://poedit.net/" target="_blank">poedit.net</a>
                            </li>
                            <li>
                                <strong><?php esc_html_e('Copy the file', 'aiohm-booking-mvp'); ?></strong> 
                                <code>/wp-content/plugins/aiohm-booking-mvp/languages/aiohm-booking-mvp.pot</code><br>
                                <?php esc_html_e('to create your language file (e.g.,', 'aiohm-booking-mvp'); ?> 
                                <code>aiohm-booking-mvp-de_DE.po</code> 
                                <?php esc_html_e('for German)', 'aiohm-booking-mvp'); ?>
                            </li>
                            <li>
                                <strong><?php esc_html_e('Open your new .po file in Poedit and translate all text strings', 'aiohm-booking-mvp'); ?></strong>
                            </li>
                            <li>
                                <strong><?php esc_html_e('Save the file - Poedit will automatically create the .mo file', 'aiohm-booking-mvp'); ?></strong>
                            </li>
                            <li>
                                <strong><?php esc_html_e('Upload both .po and .mo files to your', 'aiohm-booking-mvp'); ?></strong> 
                                <code>/wp-content/plugins/aiohm-booking-mvp/languages/</code> 
                                <?php esc_html_e('folder', 'aiohm-booking-mvp'); ?>
                            </li>
                            <li>
                                <strong><?php esc_html_e('Your plugin will automatically use the new language!', 'aiohm-booking-mvp'); ?></strong>
                            </li>
                        </ol>

                        <div class="translation-note">
                            <p><strong><?php esc_html_e('Need help? Contact us at', 'aiohm-booking-mvp'); ?></strong> 
                            <a href="mailto:info@ohm.events">info@ohm.events</a> 
                            <?php esc_html_e('for translation assistance.', 'aiohm-booking-mvp'); ?></p>
                            
                            <p><strong><?php esc_html_e('Language Settings:', 'aiohm-booking-mvp'); ?></strong> 
                            <?php esc_html_e('You can also choose from available languages in', 'aiohm-booking-mvp'); ?> 
                            <a href="<?php echo esc_url(admin_url('admin.php?page=aiohm-booking-mvp-settings')); ?>"><?php esc_html_e('Plugin Settings', 'aiohm-booking-mvp'); ?></a>.</p>
                        </div>
                    </div>
                </div>

                <div class="journey-footer">
                    <p><?php esc_html_e('Need help with any step? Use the support form to get personalized assistance.', 'aiohm-booking-mvp'); ?></p>
                    <div class="shortcode-info">
                        <strong><?php esc_html_e('Quick Shortcodes:', 'aiohm-booking-mvp'); ?></strong>
                        <code>[aiohm_booking_mvp]</code> - <?php esc_html_e('Booking widget', 'aiohm-booking-mvp'); ?><br>
                        <code>[aiohm_booking_mvp_checkout]</code> - <?php esc_html_e('Checkout page', 'aiohm-booking-mvp'); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <div id="support-messages" class="support-messages"></div>
</div>

<!-- Hidden fields for system information -->
<input type="hidden" id="system-info" value="<?php echo esc_attr(wp_json_encode([
    'plugin_version' => $plugin_version,
    'wp_version' => $wp_version,
    'php_version' => $php_version,
    'site_url' => $site_url,
    'enabled_modules' => $enabled_modules_str,
    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : 'Unknown'
])); ?>">