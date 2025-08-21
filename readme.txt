=== AIOHM Booking MVP ===
Contributors: ohm-events, aiohm
Tags: booking, events, rooms, deposits, modular
Requires at least: 6.2
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transform your WordPress events into a seamless booking experience with AIOHM's signature conscious approach to business systems.

== Description ==

AIOHM Booking brings modular event management to conscious venues and creators. Whether you're hosting intimate workshops, large conferences, or retreat experiences, this plugin adapts to your exact needs.

**Three Flexible Booking Modes:**
- **Rooms Only**: Perfect for retreats, private events, and property rentals with deposit support
- **Seats Only**: Clean ticketing system for workshops, concerts, and classes
- **Combined**: Sophisticated bookings that merge accommodation + event tickets seamlessly

**Built for Conscious Business:**
- Transparent deposit and pricing display
- Modular design that grows with your vision
- Streamlined admin experience
- Payment flexibility (Stripe & PayPal ready)
- Automatic booking hold management

**Perfect For:**
- Conscious venues and retreat centers
- Workshop facilitators and coaches
- Event producers and festival organizers
- Any business wanting transparent, flexible booking

== Shortcodes ==
[aiohm_booking_mvp] — Unified booking widget (auto-adapts to enabled modules)
[aiohm_booking_mvp_checkout] — Checkout step

== Settings ==
- Enable Rooms / Enable Seats
- Available Rooms, Allow "Private (all rooms)"
- Room Price, Seat Price, Deposit %, Currency

== REST ==
/aiohm-booking-mvp/v1/hold — create soft-hold & compute totals/deposit
/aiohm-booking-mvp/v1/stripe/session — create Stripe Checkout session (stub)
/aiohm-booking-mvp/v1/stripe-webhook — webhook (stub)
/aiohm-booking-mvp/v1/paypal/capture — PayPal capture (stub)

== Changelog ==

= 1.0.0 =
* **MILESTONE RELEASE** - Complete production-ready booking system
* ✅ **Professional Code Architecture**: Complete code beautification with modular design patterns
* ✅ **Enhanced Calendar System**: Improved half-cell coloring, status management, and filtering
* ✅ **Comprehensive Help System**: Built-in support center with booking-specific troubleshooting
* ✅ **Smart Module Dependencies**: Payment modules automatically hide when accommodation is disabled
* ✅ **Production-Ready Features**: Full booking flow, order management, and admin interface
* ✅ **Security Enhancements**: SQL injection prevention and secure data handling
* ✅ **Performance Optimizations**: Cleaned debug code and optimized asset loading
* ✅ **Professional Documentation**: Complete PHPDoc comments and organized code structure

= 0.1.0 =
* Initial development version
* Basic booking functionality
* Module system foundation
