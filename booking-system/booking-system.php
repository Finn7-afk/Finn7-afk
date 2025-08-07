<?php
/**
 * Plugin Name: Simple Booking System
 * Description: Booking system compatible with WordPress and Divi. Supports morning, afternoon, and evening slots with live capacity tracking, pricing for adults/children, and optional charity donation.
 * Version: 1.2.0
 * Author: ChatGPT
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Create required database tables on plugin activation.
 */
function sbs_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $slots_table    = $wpdb->prefix . 'sbs_slots';
    $bookings_table = $wpdb->prefix . 'sbs_bookings';

    $sql = "CREATE TABLE $slots_table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                start_time datetime NOT NULL,
                end_time datetime NOT NULL,
                PRIMARY KEY  (id)
            ) $charset_collate;

            CREATE TABLE $bookings_table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                slot_id bigint(20) unsigned NOT NULL,
                name varchar(100) NOT NULL,
                email varchar(100) NOT NULL,
                phone varchar(50) DEFAULT '',
                adults smallint unsigned NOT NULL DEFAULT 1,
                children text NOT NULL,
                donation tinyint(1) NOT NULL DEFAULT 1,
                total decimal(8,2) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY slot_id (slot_id)
            ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'sbs_activate');

/**
 * Fetch slots with their booking counts.
 *
 * @return array List of slot objects including `booked` count.
 */
function sbs_get_slots() {
    global $wpdb;
    $slots_table    = $wpdb->prefix . 'sbs_slots';
    $bookings_table = $wpdb->prefix . 'sbs_bookings';

    $query = "SELECT s.id, s.start_time, s.end_time, COUNT(b.id) AS booked
              FROM $slots_table s
              LEFT JOIN $bookings_table b ON s.id = b.slot_id
              GROUP BY s.id
              ORDER BY s.start_time ASC";

    return $wpdb->get_results($query);
}

/**
 * Handle booking form submission.
 */
function sbs_handle_booking() {
    if (empty($_POST['sbs_booking_nonce']) || !wp_verify_nonce($_POST['sbs_booking_nonce'], 'sbs_booking')) {
        return;
    }

    if (empty($_POST['sbs_slot']) || empty($_POST['sbs_name']) || empty($_POST['sbs_email']) || empty($_POST['sbs_adults'])) {
        return;
    }

    global $wpdb;
    $slots_table    = $wpdb->prefix . 'sbs_slots';
    $bookings_table = $wpdb->prefix . 'sbs_bookings';
    $slot_id = intval($_POST['sbs_slot']);
    $name    = sanitize_text_field($_POST['sbs_name']);
    $email   = sanitize_email($_POST['sbs_email']);
    $phone   = sanitize_text_field($_POST['sbs_phone'] ?? '');
    $adults  = max(1, intval($_POST['sbs_adults']));

    $children = [];
    $child_names   = isset($_POST['sbs_child_name']) && is_array($_POST['sbs_child_name']) ? $_POST['sbs_child_name'] : [];
    $child_under1s = isset($_POST['sbs_child_under1']) && is_array($_POST['sbs_child_under1']) ? $_POST['sbs_child_under1'] : [];
    foreach ($child_names as $i => $child_name) {
        $child_name = sanitize_text_field($child_name);
        $under1     = !empty($child_under1s[$i]);
        $children[] = [
            'name'   => $child_name,
            'under1' => $under1,
        ];
    }

    $donation = !empty($_POST['sbs_donation']) ? 1 : 0;

    $total = $adults * 2;
    foreach ($children as $child) {
        if (!$child['under1']) {
            $total += 1;
        }
    }
    if ($donation) {
        $total += 1;
    }

    $slot = $wpdb->get_row($wpdb->prepare("SELECT start_time FROM $slots_table WHERE id = %d", $slot_id));
    if (!$slot) {
        return;
    }

    $current = current_time('mysql');
    if (strtotime($slot->start_time) < strtotime($current)) {
        return; // Past slot
    }

    $booked = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $bookings_table WHERE slot_id = %d", $slot_id));
    if ($booked >= 32) {
        return; // Slot full
    }

    $wpdb->insert($bookings_table, [
        'slot_id'  => $slot_id,
        'name'     => $name,
        'email'    => $email,
        'phone'    => $phone,
        'adults'   => $adults,
        'children' => wp_json_encode($children),
        'donation' => $donation,
        'total'    => $total,
    ]);
}
add_action('init', 'sbs_handle_booking');

/**
 * Render booking form.
 *
 * @return string HTML form output.
 */
function sbs_booking_form_shortcode() {
    $slots = sbs_get_slots();

    if (empty($slots)) {
        return '<p>' . esc_html__('No time slots available', 'simple-booking-system') . '</p>';
    }

    $slot_data = [];
    foreach ($slots as $slot) {
        $date = date_i18n('Y-m-d', strtotime($slot->start_time));
        $hour = (int) date_i18n('H', strtotime($slot->start_time));
        if ($hour < 12) {
            $period = 'morning';
        } elseif ($hour < 17) {
            $period = 'afternoon';
        } else {
            $period = 'evening';
        }
        $slot_data[$date][$period] = [
            'id'        => $slot->id,
            'start'     => $slot->start_time,
            'remaining' => 32 - (int) $slot->booked,
        ];
    }

    ob_start();
    ?>
    <form method="post" class="sbs-form">
        <?php wp_nonce_field('sbs_booking', 'sbs_booking_nonce'); ?>

        <label for="sbs_name"><?php esc_html_e('Name', 'simple-booking-system'); ?></label>
        <input type="text" name="sbs_name" id="sbs_name" required />

        <label for="sbs_email"><?php esc_html_e('Email', 'simple-booking-system'); ?></label>
        <input type="email" name="sbs_email" id="sbs_email" required />

        <label for="sbs_phone"><?php esc_html_e('Phone', 'simple-booking-system'); ?></label>
        <input type="text" name="sbs_phone" id="sbs_phone" required />

        <label for="sbs_adults"><?php esc_html_e('Number of adults', 'simple-booking-system'); ?></label>
        <input type="number" name="sbs_adults" id="sbs_adults" min="1" value="1" required />

        <div id="sbs_children">
            <h4><?php esc_html_e('Children', 'simple-booking-system'); ?></h4>
            <div id="sbs_children_list"></div>
            <button type="button" id="sbs_add_child"><?php esc_html_e('Add Child', 'simple-booking-system'); ?></button>
        </div>

        <label>
            <input type="checkbox" name="sbs_donation" id="sbs_donation" checked />
            <?php esc_html_e('Donate £1 to charity', 'simple-booking-system'); ?>
        </label>

        <p id="sbs_total"><?php esc_html_e('Total: £0', 'simple-booking-system'); ?></p>

        <label for="sbs_date"><?php esc_html_e('Date', 'simple-booking-system'); ?></label>
        <input type="date" name="sbs_date" id="sbs_date" required min="<?php echo esc_attr( date_i18n('Y-m-d') ); ?>" />

        <div id="sbs_slots" class="sbs-slots"></div>
        <input type="hidden" name="sbs_slot" id="sbs_slot" />

        <button type="submit"><?php esc_html_e('Book', 'simple-booking-system'); ?></button>
    </form>
    <style>
        .sbs-slots button.selected { background: #333; color: #fff; }
        .sbs-slots button { margin-right: 4px; }
    </style>
    <script>
    (function(){
        const slotData = <?php echo wp_json_encode($slot_data); ?>;
        const adultsEl = document.getElementById('sbs_adults');
        const donationEl = document.getElementById('sbs_donation');
        const totalEl = document.getElementById('sbs_total');
        const childrenList = document.getElementById('sbs_children_list');

        function calculateTotal(){
            let adults = parseInt(adultsEl.value) || 0;
            let total = adults * 2;
            document.querySelectorAll('.sbs-child-row').forEach(function(row){
                const under1 = row.querySelector('.sbs-child-under1').checked;
                if(!under1){ total += 1; }
            });
            if(donationEl.checked){ total += 1; }
            totalEl.textContent = 'Total: £' + total;
        }

        document.getElementById('sbs_add_child').addEventListener('click', function(){
            const div = document.createElement('div');
            div.className = 'sbs-child-row';
            div.innerHTML = `<input type="text" name="sbs_child_name[]" placeholder="<?php echo esc_js(__('Child name', 'simple-booking-system')); ?>" required /> <label><input type="checkbox" class="sbs-child-under1" name="sbs_child_under1[]" /> <?php echo esc_js(__('Under 1', 'simple-booking-system')); ?></label>`;
            childrenList.appendChild(div);
            div.querySelector('.sbs-child-under1').addEventListener('change', calculateTotal);
            calculateTotal();
        });

        adultsEl.addEventListener('input', calculateTotal);
        donationEl.addEventListener('change', calculateTotal);
        calculateTotal();

        const dateEl = document.getElementById('sbs_date');
        const slotsDiv = document.getElementById('sbs_slots');
        dateEl.addEventListener('change', function(){
            const date = this.value;
            slotsDiv.innerHTML = '';
            document.getElementById('sbs_slot').value = '';
            if(!slotData[date]){ return; }
            const now = new Date();
            Object.keys(slotData[date]).forEach(function(period){
                const data = slotData[date][period];
                const start = new Date(data.start.replace(' ', 'T'));
                const isPast = start < now;
                const disabled = isPast || data.remaining <= 0;
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = period.charAt(0).toUpperCase()+period.slice(1)+' ('+data.remaining+' left)';
                btn.disabled = disabled;
                btn.dataset.slot = data.id;
                btn.addEventListener('click', function(){
                    document.getElementById('sbs_slot').value = this.dataset.slot;
                    Array.from(slotsDiv.querySelectorAll('button')).forEach(b=>b.classList.remove('selected'));
                    this.classList.add('selected');
                });
                slotsDiv.appendChild(btn);
            });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('sbs_booking_form', 'sbs_booking_form_shortcode');
