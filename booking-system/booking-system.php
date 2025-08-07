<?php
/**
 * Plugin Name: Simple Booking System
 * Description: Booking system compatible with WordPress and Divi. Supports morning, afternoon, and evening slots with live capacity tracking, age-based child pricing, optional Gift Aid donation and staff check-in view.
 * Version: 1.3.0
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
                address text NOT NULL,
                adults smallint unsigned NOT NULL DEFAULT 0,
                party_size smallint unsigned NOT NULL DEFAULT 0,
                children text NOT NULL,
                donation tinyint(1) NOT NULL DEFAULT 1,
                total decimal(8,2) NOT NULL DEFAULT 0,
                checked_in tinyint(1) NOT NULL DEFAULT 0,
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

    $current = current_time('mysql');
    $week    = date('Y-m-d H:i:s', strtotime('+7 days', strtotime($current)));

    $query = $wpdb->prepare(
        "SELECT s.id, s.start_time, s.end_time, COALESCE(SUM(b.party_size),0) AS booked
         FROM $slots_table s
         LEFT JOIN $bookings_table b ON s.id = b.slot_id
         WHERE s.start_time BETWEEN %s AND %s
         GROUP BY s.id
         ORDER BY s.start_time ASC",
        $current,
        $week
    );

    return $wpdb->get_results($query);
}

/**
 * Calculate age in years from a date of birth string.
 *
 * @param string $dob Date of birth in Y-m-d format.
 * @return int Age in years.
 */
function sbs_get_age($dob) {
    try {
        $birth = new DateTime($dob);
        $today = new DateTime(current_time('Y-m-d'));
        return (int) $birth->diff($today)->y;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Handle booking form submission.
 */
function sbs_handle_booking() {
    if (empty($_POST['sbs_booking_nonce']) || !wp_verify_nonce($_POST['sbs_booking_nonce'], 'sbs_booking')) {
        return;
    }

    if (empty($_POST['sbs_slot']) || empty($_POST['sbs_name']) || empty($_POST['sbs_email']) || !isset($_POST['sbs_adults']) || empty($_POST['sbs_address'])) {
        return;
    }

    global $wpdb;
    $slots_table    = $wpdb->prefix . 'sbs_slots';
    $bookings_table = $wpdb->prefix . 'sbs_bookings';
    $slot_id = intval($_POST['sbs_slot']);
    $name    = sanitize_text_field($_POST['sbs_name']);
    $email   = sanitize_email($_POST['sbs_email']);
    $phone   = sanitize_text_field($_POST['sbs_phone'] ?? '');
    $address = sanitize_textarea_field($_POST['sbs_address']);
    $adults  = max(0, intval($_POST['sbs_adults']));

    $children = [];
    $child_names = isset($_POST['sbs_child_name']) && is_array($_POST['sbs_child_name']) ? $_POST['sbs_child_name'] : [];
    $child_dobs  = isset($_POST['sbs_child_dob']) && is_array($_POST['sbs_child_dob']) ? $_POST['sbs_child_dob'] : [];
    foreach ($child_names as $i => $child_name) {
        $child_name = sanitize_text_field($child_name);
        $dob        = sanitize_text_field($child_dobs[$i] ?? '');
        $age        = sbs_get_age($dob);
        $children[] = [
            'name' => $child_name,
            'dob'  => $dob,
            'age'  => $age,
        ];
    }

    $donation = !empty($_POST['sbs_donation']) ? 1 : 0;

    $total = 1; // booking fee
    foreach ($children as $child) {
        if ($child['age'] >= 1 && $child['age'] <= 4) {
            $total += 5.45;
        } elseif ($child['age'] >= 5 && $child['age'] <= 15) {
            $total += 6.45;
        }
    }
    if ($donation) {
        $total += 1; // gift aid donation
    }
    $total = round($total, 2);

    $party_size = $adults + count($children);

    $slot = $wpdb->get_row($wpdb->prepare("SELECT start_time FROM $slots_table WHERE id = %d", $slot_id));
    if (!$slot) {
        return;
    }

    $current = current_time('mysql');
    if (strtotime($slot->start_time) < strtotime($current) || strtotime($slot->start_time) > strtotime('+7 days', strtotime($current))) {
        return; // Past or too far in future
    }

    $booked = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(party_size),0) FROM $bookings_table WHERE slot_id = %d", $slot_id));
    if ($booked + $party_size > 32) {
        return; // Slot full
    }

    $wpdb->insert($bookings_table, [
        'slot_id'    => $slot_id,
        'name'       => $name,
        'email'      => $email,
        'phone'      => $phone,
        'address'    => $address,
        'adults'     => $adults,
        'party_size' => $party_size,
        'children'   => wp_json_encode($children),
        'donation'   => $donation,
        'total'      => $total,
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
    <h2><?php esc_html_e('Booking', 'simple-booking-system'); ?></h2>
    <p><?php esc_html_e('Please complete your details below. Gift Aid lets us claim extra at no cost to you.', 'simple-booking-system'); ?></p>
    <form method="post" class="sbs-form">
        <?php wp_nonce_field('sbs_booking', 'sbs_booking_nonce'); ?>

        <label>
            <input type="checkbox" name="sbs_donation" id="sbs_donation" checked />
            <?php esc_html_e('Add £1 Gift Aid donation', 'simple-booking-system'); ?>
        </label>
        <p><?php esc_html_e('Booking fee £1.00', 'simple-booking-system'); ?></p>

        <label for="sbs_name"><?php esc_html_e('Name', 'simple-booking-system'); ?></label>
        <input type="text" name="sbs_name" id="sbs_name" required />

        <label for="sbs_address"><?php esc_html_e('Address', 'simple-booking-system'); ?></label>
        <textarea name="sbs_address" id="sbs_address" required></textarea>

        <label for="sbs_phone"><?php esc_html_e('Phone', 'simple-booking-system'); ?></label>
        <input type="text" name="sbs_phone" id="sbs_phone" required />

        <label for="sbs_email"><?php esc_html_e('Email', 'simple-booking-system'); ?></label>
        <input type="email" name="sbs_email" id="sbs_email" required />

        <label for="sbs_adults"><?php esc_html_e('Number of adults', 'simple-booking-system'); ?></label>
        <input type="number" name="sbs_adults" id="sbs_adults" min="0" value="0" required />

        <div id="sbs_children">
            <h4><?php esc_html_e('Children', 'simple-booking-system'); ?></h4>
            <div id="sbs_children_list"></div>
            <button type="button" id="sbs_add_child"><?php esc_html_e('Add Child', 'simple-booking-system'); ?></button>
        </div>

        <p id="sbs_total"><?php esc_html_e('Total: £1.00', 'simple-booking-system'); ?></p>

        <label for="sbs_date"><?php esc_html_e('Date', 'simple-booking-system'); ?></label>
        <input type="date" name="sbs_date" id="sbs_date" required min="<?php echo esc_attr( date_i18n('Y-m-d') ); ?>" max="<?php echo esc_attr( date_i18n('Y-m-d', strtotime('+7 days')) ); ?>" />

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
        const donationEl = document.getElementById('sbs_donation');
        const totalEl = document.getElementById('sbs_total');
        const childrenList = document.getElementById('sbs_children_list');

        function calcAge(dob){
            const birth = new Date(dob);
            const today = new Date();
            let age = today.getFullYear() - birth.getFullYear();
            const m = today.getMonth() - birth.getMonth();
            if(m < 0 || (m === 0 && today.getDate() < birth.getDate())){ age--; }
            return age;
        }

        function calculateTotal(){
            let total = 1; // booking fee
            document.querySelectorAll('.sbs-child-row').forEach(function(row){
                const dob = row.querySelector('.sbs-child-dob').value;
                let age = 0;
                if(dob){ age = calcAge(dob); }
                row.querySelector('.sbs-child-age').textContent = age ? age+' yrs' : '';
                if(age >=1 && age <=4){ total += 5.45; }
                else if(age >=5 && age <=15){ total += 6.45; }
            });
            if(donationEl.checked){ total += 1; }
            totalEl.textContent = 'Total: £' + total.toFixed(2);
        }

        document.getElementById('sbs_add_child').addEventListener('click', function(){
            const div = document.createElement('div');
            div.className = 'sbs-child-row';
            div.innerHTML = `<input type="text" name="sbs_child_name[]" placeholder="<?php echo esc_js(__('Child name', 'simple-booking-system')); ?>" required /> <input type="date" name="sbs_child_dob[]" class="sbs-child-dob" required /> <span class="sbs-child-age"></span>`;
            childrenList.appendChild(div);
            div.querySelector('.sbs-child-dob').addEventListener('change', calculateTotal);
            calculateTotal();
        });

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

/**
 * Admin bookings page for staff check-in.
 */
function sbs_admin_menu() {
    add_menu_page(
        __('SBS Bookings', 'simple-booking-system'),
        __('SBS Bookings', 'simple-booking-system'),
        'manage_options',
        'sbs_bookings',
        'sbs_admin_bookings_page'
    );
}
add_action('admin_menu', 'sbs_admin_menu');

function sbs_admin_bookings_page() {
    global $wpdb;
    $slots_table    = $wpdb->prefix . 'sbs_slots';
    $bookings_table = $wpdb->prefix . 'sbs_bookings';

    if (isset($_GET['checkin'], $_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'sbs_checkin_' . $_GET['checkin'])) {
        $wpdb->update($bookings_table, ['checked_in' => 1], ['id' => intval($_GET['checkin'])]);
        echo '<div class="updated"><p>' . esc_html__('Checked in', 'simple-booking-system') . '</p></div>';
    }

    $date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : date_i18n('Y-m-d');
    echo '<div class="wrap"><h1>' . esc_html__('Bookings', 'simple-booking-system') . '</h1>';
    echo '<form method="get"><input type="hidden" name="page" value="sbs_bookings" />';
    echo '<input type="date" name="date" value="' . esc_attr($date) . '" />';
    submit_button(__('Filter', 'simple-booking-system'), 'secondary', '', false);
    echo '</form>';

    $bookings = $wpdb->get_results($wpdb->prepare(
        "SELECT b.*, s.start_time FROM $bookings_table b JOIN $slots_table s ON b.slot_id = s.id WHERE DATE(s.start_time) = %s ORDER BY s.start_time",
        $date
    ));

    if ($bookings) {
        echo '<table class="widefat"><thead><tr><th>' . esc_html__('Name', 'simple-booking-system') . '</th><th>' . esc_html__('Slot', 'simple-booking-system') . '</th><th>' . esc_html__('Adults', 'simple-booking-system') . '</th><th>' . esc_html__('Children', 'simple-booking-system') . '</th><th>' . esc_html__('Checked in', 'simple-booking-system') . '</th></tr></thead><tbody>';
        foreach ($bookings as $b) {
            $children = json_decode($b->children, true) ?: [];
            $child_list = [];
            foreach ($children as $c) {
                $child_list[] = esc_html($c['name'] . ' (' . $c['age'] . ')');
            }
            $slot_time = date_i18n('H:i', strtotime($b->start_time));
            $checkin_url = wp_nonce_url(add_query_arg(['page' => 'sbs_bookings', 'date' => $date, 'checkin' => $b->id], admin_url('admin.php')), 'sbs_checkin_' . $b->id);
            $checked = $b->checked_in ? esc_html__('Yes', 'simple-booking-system') : '<a href="' . esc_url($checkin_url) . '">' . esc_html__('Check in', 'simple-booking-system') . '</a>';
            echo '<tr><td>' . esc_html($b->name) . '</td><td>' . esc_html($slot_time) . '</td><td>' . intval($b->adults) . '</td><td>' . implode(', ', $child_list) . '</td><td>' . $checked . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>' . esc_html__('No bookings for this date', 'simple-booking-system') . '</p>';
    }
    echo '</div>';
}
