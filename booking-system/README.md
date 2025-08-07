# Simple Booking System

This plugin provides a minimal booking system compatible with WordPress and the Divi theme. It registers a `[sbs_booking_form]` shortcode that can be used inside Divi's text module or any post content to display an HTML form for booking morning, afternoon, or evening time slots with live capacity tracking and simple pricing.

## Installation
1. Copy the `booking-system` directory into your site's `wp-content/plugins/` directory.
2. Activate **Simple Booking System** through the WordPress *Plugins* screen.
3. Insert the `[sbs_booking_form]` shortcode where you want the form to appear.

## Usage
- The plugin creates two custom tables on activation: `wp_sbs_slots` for time slots and `wp_sbs_bookings` for reservations.
- Populate the slots table with the dates and times you want to offer. Each slot has a capacity of 32 seats.
- Visitors can select how many adults and children are attending and optionally donate £1 to charity.
- The form updates the total price as people are added: adults are £2 each, children over one year old are £1, and children under one are free.
- The booking form displays how many seats remain for each slot and disables selections that are full or in the past.
- When a visitor submits the form, their contact details, attendee counts, donation choice, and chosen slot are stored in the bookings table.

## Divi Compatibility
Divi can render standard WordPress shortcodes, so adding `[sbs_booking_form]` inside a Divi **Code** or **Text** module will display the booking form.
