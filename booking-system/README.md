# Simple Booking System

This plugin provides a minimal booking system compatible with WordPress and the Divi theme. It registers a `[sbs_booking_form]` shortcode that can be used inside Divi's text module or any post content to display an HTML form for booking morning, afternoon, or evening time slots with live capacity tracking and age‑based pricing. A staff view is also included to check visitors in on arrival.

## Installation
1. Copy the `booking-system` directory into your site's `wp-content/plugins/` directory.
2. Activate **Simple Booking System** through the WordPress *Plugins* screen.
3. Insert the `[sbs_booking_form]` shortcode where you want the form to appear.

## Usage
- The plugin creates two custom tables on activation: `wp_sbs_slots` for time slots and `wp_sbs_bookings` for reservations.
- Populate the slots table with the dates and times you want to offer. Each slot has a capacity of 32 seats.
- Visitors can select how many adults are attending and enter each child's name and date of birth. Children under 1 are free, ages 1–4 cost £5.45, and ages 5–15 cost £6.45.
- A £1 booking fee is always applied. A £1 Gift Aid donation is ticked by default but can be unticked.
- The form updates the total price as children are added and shows remaining capacity for each slot. Dates more than a week in advance and sessions that are full or in the past are disabled.
- When a visitor submits the form, their contact details, address, attendee details, donation choice, and chosen slot are stored in the bookings table. Staff can access the **SBS Bookings** admin page to mark visitors as checked in.

## Divi Compatibility
Divi can render standard WordPress shortcodes, so adding `[sbs_booking_form]` inside a Divi **Code** or **Text** module will display the booking form.
