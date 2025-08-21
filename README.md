# AIOHM Booking MVP

**Transform your WordPress site into a seamless accommodation and event booking platform.**

AIOHM Booking MVP is a WordPress plugin designed for conscious venues, creators, and event organizers. It provides a flexible and transparent booking experience, focusing on room and accommodation bookings with integrated deposits and payments. Built with AIOHM's signature attention to conscious business flow, this plugin adapts to your specific needs.

## Description

AIOHM Booking brings modular event management to conscious venues and creators. Whether you're hosting intimate workshops, large conferences, or retreat experiences, this plugin adapts to your exact needs.

### Three Flexible Booking Modes:
- **Rooms Only**: Perfect for retreats, private events, and property rentals with deposit support.
- **Seats Only**: A clean ticketing system for workshops, concerts, and classes.
- **Combined**: Sophisticated bookings that merge accommodation and event tickets seamlessly.

### Built for Conscious Business:
- Transparent deposit and pricing display.
- Modular design that grows with your vision.
- Streamlined admin experience.
- Payment flexibility (Stripe & PayPal ready).
- Automatic booking hold management.

### Perfect For:
- Conscious venues and retreat centers
- Workshop facilitators and coaches
- Event producers and festival organizers
- Any business wanting a transparent, flexible booking system.

## Features

- **Flexible Booking Modes**: Choose between "Rooms Only", "Seats Only", or a "Combined" mode.
- **Deposit Management**: Easily manage deposits for your bookings.
- **Payment Integration**: Ready for Stripe and PayPal payments.
- **Customization**: Highly customizable to fit your business needs.
- **Shortcode Support**: Easily embed booking widgets and checkout forms anywhere on your site.
- **Developer Friendly**: Includes a REST API for custom integrations.
- **Secure**: Built with security best practices in mind.
- **Translation Ready**: Comes with translation files for different languages.

## Installation

1.  Upload the `aiohm-booking-mvp` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Navigate to the AIOHM Booking MVP settings page to configure the plugin.

## How to Use

### Settings

The plugin's settings allow you to:
- Enable/disable booking modules (Rooms / Seats).
- Set the number of available rooms.
- Allow "Private (all rooms)" bookings.
- Configure prices for rooms and seats.
- Set the deposit percentage.
- Choose your currency.

### Shortcodes

- `[aiohm_booking_mvp]` — This will display the unified booking widget, which automatically adapts to the modules you have enabled.
- `[aiohm_booking_mvp_checkout]` — This will display the checkout step.

## For Developers

The plugin exposes several REST API endpoints for advanced integrations:

- `/aiohm-booking-mvp/v1/hold`: Create a soft-hold on a booking and compute totals/deposit.
- `/aiohm-booking-mvp/v1/stripe/session`: Create a Stripe Checkout session.
- `/aiohm-booking-mvp/v1/stripe-webhook`: Webhook for Stripe events.
- `/aiohm-booking-mvp/v1/paypal/capture`: Capture a payment with PayPal.

## Changelog

### 1.0.0
- **MILESTONE RELEASE** - Complete production-ready booking system
- ✅ **Professional Code Architecture**: Complete code beautification with modular design patterns
- ✅ **Enhanced Calendar System**: Improved half-cell coloring, status management, and filtering
- ✅ **Comprehensive Help System**: Built-in support center with booking-specific troubleshooting
- ✅ **Smart Module Dependencies**: Payment modules automatically hide when accommodation is disabled
- ✅ **Production-Ready Features**: Full booking flow, order management, and admin interface
- ✅ **Security Enhancements**: SQL injection prevention and secure data handling
- ✅ **Performance Optimizations**: Cleaned debug code and optimized asset loading
- ✅ **Professional Documentation**: Complete PHPDoc comments and organized code structure

## License

This plugin is licensed under the GPLv2 or later.
License URI: https://www.gnu.org/licenses/gpl-2.0.html
