# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview
AIOHM Booking MVP transforms WordPress into seamless accommodation booking experiences with conscious business values. Built with AIOHM's signature attention to modular design and transparent user experience, it focuses exclusively on room and accommodation bookings. Perfect for conscious venues, retreat centers, hotels, vacation rentals, and accommodation providers.

## WordPress Plugin Structure
This is a standard WordPress plugin with the main file `aiohm-booking-mvp.php` that loads all components. The plugin creates custom post types, database tables, REST API endpoints, and admin interfaces.

## Core Architecture

### Main Components
- **AIOHM_BOOKING_MVP_Activator** (`includes/aiohm-booking-mvp-activator.php`): Plugin activation/deactivation, database setup, custom post type registration
- **AIOHM_BOOKING_MVP_Events** (`includes/aiohm-booking-mvp-events.php`): Event management with meta boxes for dates and capacity
- **AIOHM_BOOKING_MVP_API** (`includes/aiohm-booking-mvp-api.php`): REST API endpoints for booking operations and payment stubs
- **AIOHM_BOOKING_MVP_Shortcodes** (`includes/aiohm-booking-mvp-shortcodes.php`): Frontend shortcodes and asset loading
- **AIOHM_BOOKING_MVP_Admin** (`includes/aiohm-booking-mvp-admin.php`): WordPress admin interface, settings, and orders management
- **AIOHM_BOOKING_MVP_AI_Client** (`includes/aiohm-booking-mvp-ai-client.php`): AI integration for enhanced booking features
- **AIOHM_BOOKING_MVP_Security** (`includes/aiohm-booking-mvp-security.php`): Security enhancements and SQL injection prevention
- **AIOHM_BOOKING_MVP_Calendar** (`includes/aiohm-booking-mvp-calendar.php`): Calendar system with half-cell coloring and status management

### Database Schema
Two custom tables are created:
- `wp_aiohm_booking_mvp_order`: Main order table with booking details, pricing, status (pending|paid|cancelled|expired)
- `wp_aiohm_booking_mvp_item`: Order line items for rooms (future-proofing)

### REST API Endpoints
- `POST /wp-json/aiohm-booking-mvp/v1/hold`: Create booking hold with validation and calculate totals/deposits
- `POST /wp-json/aiohm-booking-mvp/v1/stripe/session`: Stripe checkout session creation (stub)
- `POST /wp-json/aiohm-booking-mvp/v1/stripe-webhook`: Stripe webhook handler (stub)
- `POST /wp-json/aiohm-booking-mvp/v1/paypal/capture`: PayPal capture handler (stub)
- `GET /wp-json/aiohm-booking-mvp/v1/availability`: Calendar availability data

### Accommodation Booking Widget
- **Room Booking**: Shows room quantity selector + optional "Private (all rooms)" checkbox
- **Flexible Configuration**: Supports individual rooms or entire property booking

### Helper Functions
Core utility functions in `includes/aiohm-booking-mvp-helpers.php`:
- `aiohm_booking_mvp_opts()`: Get all settings as array
- `aiohm_booking_mvp_opt($key, $default)`: Get specific setting value
- `aiohm_booking_mvp_enabled_rooms()`: Check enabled rooms module
- `aiohm_booking_mvp_asset_url($relative)` / `aiohm_booking_mvp_asset_path($relative)`: Asset URL/path builders

### Frontend Integration
- Shortcodes: `[aiohm_booking_mvp]` (auto-adapting booking widget), `[aiohm_booking_mvp_checkout]` (order summary & payment)
- JavaScript: Enhanced `assets/js/aiohm-booking-mvp-app.js` with form validation, error handling, and better UX
- CSS: Professional styling in `assets/css/aiohm-booking-mvp-style.css`
- Templates: Located in `templates/` directory for widget, checkout, calendar, and help pages

### Order Management
- Full orders admin screen with bulk actions (mark paid, cancel)
- Real-time status tracking and payment method logging
- Automatic hold expiry via cron job (`includes/aiohm-booking-mvp-cron.php`) with 15-minute timeout

## Development Commands
This plugin has no build process - it's standard PHP/JavaScript. WordPress handles asset loading and caching.

## Testing MVP Features

### Admin Settings Test:
1. Go to "AIOHM Booking → Settings"
2. Configure room settings
3. Verify widget adapts immediately on frontend
4. Configure pricing, deposit %, currency
5. Test "Allow Private (all rooms)" setting

### Booking Features Test:
1. **Accommodation Booking**: Widget shows room quantity selector + private option
2. **Room Configuration**: Test different room quantities and private booking options

### API Validation Test:
1. Submit forms with missing required fields (name, email)
2. Test room quantity validation
3. Test quantity validation (negative numbers, zero values)
4. Verify error messages display properly

### Order Flow Test:
1. Complete booking form → Check order created in admin
2. View "AIOHM Booking → Orders" screen
3. Test order actions: Mark Paid, Cancel, Delete
4. Test bulk actions on multiple orders

### Payment Stubs Test:
1. Complete booking → Go to checkout page
2. Click "Pay with Stripe" → Should redirect to stub URL
3. PayPal capture endpoint returns success (stub)

## Key Configuration
All settings stored in WordPress options table as `aiohm_booking_mvp_settings` array:
- Module settings (`enable_rooms`)
- Pricing (`room_price`, `deposit_percent`)
- Capacity (`available_rooms`, `allow_private_all`)
- Currency and payment settings

## WordPress Integration Points
- Custom post type: `aiohm_booking_event` for event management with date/capacity meta
- Admin menu: "AIOHM Booking" with Dashboard, Events, Orders, Settings, Help
- Cron job: `aiohm_booking_mvp_cleanup_holds` runs hourly to expire pending orders
- Hooks: Uses standard WordPress activation hooks, REST API, shortcode system
- Database: Uses WordPress DB class (`$wpdb`) for custom table operations

## File Structure
```
aiohm-booking-mvp.php               # Main plugin file
includes/
├── aiohm-booking-mvp-helpers.php   # Core utility functions
├── aiohm-booking-mvp-cron.php      # Cron job for order cleanup
├── aiohm-booking-mvp-*.php   # Main component classes
assets/
├── css/                            # Stylesheets for admin and frontend
├── js/                             # JavaScript for booking functionality
├── images/                         # Plugin icons and images
templates/                          # PHP templates for frontend display
```

## Security Features
- SQL injection prevention via prepared statements
- Nonce verification for admin actions
- Input sanitization and validation
- Secure file access checks (`ABSPATH` guards)