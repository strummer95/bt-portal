<?php
/**
 * BT Portal — DTF Studio orders land on the schedule board by themselves.
 *
 * A gang sheet order used to reach the board only if somebody read the new
 * order email and typed a card for it. This listens for a paid DTF Studio
 * order and writes the Transfers card itself: order number, customer, piece
 * count, sheet size, status None. From there it is an ordinary job card —
 * it drags, takes a status, and the Complete Order button on it closes the
 * WooCommerce order exactly as it does on a hand-typed one.
 *
 * Two columns already on bt_jobs do the linking work, so this adds no
 * migration of its own:
 *
 *   woo_order_id  the order the card came from (added by woo.php, and what
 *                 the Complete Order button already reads)
 *   auto_kind     'dtf', marking the card as one this file generated
 *
 * The order carries the card's id back the other way in _btp_dtf_job_id, so
 * an order that fires two of the hooks below — most do — cannot produce two
 * cards. The row is looked up by woo_order_id as well, which covers an order
 * whose meta was lost to a board restore.
 *
 * Nothing here ever updates or deletes a card after it is written. Once the
 * card is on the board it belongs to production, not to this file.
 */
if (!defined('ABSPATH')) exit;

const BTP_DTF_DEPT      = 'Transfers';
const BTP_DTF_JOB_META  = '_btp_dtf_job_id';
const BTP_DTF_AUTO_KIND = 'dtf';

/** Orders in before this hour go on today's column; after it, the next day. */
const BTP_DTF_CUTOFF_HOUR = 14;   // 2pm, shop clock

/* ============================================================
 * 1. IS THIS A DTF STUDIO ORDER?
 *    Recognised from the gang sheet meta BT Transfers writes on the
 *    line items at checkout. The last three are the public keys orders
 *    carried before those production URLs were made hidden.
 * ============================================================ */
function btp_dtf_is_dtf_order( $order ) {
    if ( ! is_object($order) || ! method_exists($order, 'get_items') ) return false;
    $keys = ['_btgsb_sheet_url', '_btgsb_zip_url', '_btgsb_manifest', '_btgsb_layout',
             'Sheet File', 'Sheet Size', 'Design Files'];
    foreach ( $order->get_items() as $item ) {
        if ( ! is_object($item) || ! method_exists($item, 'get_meta') ) continue;
        foreach ( $keys as $k ) if ( $item->get_meta($k) ) return true;
    }
    return false;
}

/* ============================================================
 * 2. WHAT GOES ON THE CARD
 * ============================================================ */

/** Pieces and sheet sizes across every gang sheet line on the order. */
function btp_dtf_sheet_summary( $order ) {
    $pieces = 0;
    $sizes  = [];

    foreach ( $order->get_items() as $item ) {
        if ( ! is_object($item) || ! method_exists($item, 'get_meta') ) continue;

        $size = trim( (string) $item->get_meta('Sheet Size') );
        if ( $size === '' ) continue;   // not a gang sheet line

        $qty = method_exists($item, 'get_quantity') ? max(1, intval($item->get_quantity())) : 1;
        $pieces += intval($item->get_meta('Total Pieces')) * $qty;
        $sizes[] = $qty > 1 ? $size . ' x' . $qty : $size;
    }

    return [
        'pieces' => $pieces,
        'notes'  => $sizes ? implode(' + ', $sizes) : '',
    ];
}

/** Billing name, falling back to the company and then the email. */
function btp_dtf_customer_name( $order ) {
    $name = '';
    if ( method_exists($order, 'get_formatted_billing_full_name') )
        $name = trim( (string) $order->get_formatted_billing_full_name() );
    if ( $name === '' && method_exists($order, 'get_billing_company') )
        $name = trim( (string) $order->get_billing_company() );
    if ( $name === '' && method_exists($order, 'get_billing_email') )
        $name = trim( (string) $order->get_billing_email() );
    return $name !== '' ? $name : 'DTF Studio order';
}

/* ============================================================
 * 3. WHICH DAY THE CARD GOES ON
 * ============================================================ */

/**
 * Today if the order is in before the cutoff, otherwise the next day —
 * on the site's own clock, not the server's, so 2pm means 2pm in the shop.
 */
function btp_dtf_due_date() {
    $today = current_time('Y-m-d');
    $hour  = intval( current_time('G') );
    $date  = $hour < BTP_DTF_CUTOFF_HOUR
        ? $today
        : date('Y-m-d', strtotime($today . ' +1 day'));
    return btp_dtf_next_working_day($date);
}

/**
 * Roll a date forward to a day the board actually shows.
 *
 * The board is Monday to Friday — btGetWeekDays() builds five columns from
 * Monday — so a card dated Saturday or Sunday would exist in the table and
 * appear nowhere on the board. Days set to 0% capacity are skipped for the
 * same reason: the column is drawn closed and nothing is meant to run on it.
 * A day at reduced capacity is still open and is left alone.
 */
function btp_dtf_next_working_day( $date ) {
    global $wpdb;

    $table  = $wpdb->prefix . 'bt_closed_days';
    $exists = ( $wpdb->get_var( $wpdb->prepare('SHOW TABLES LIKE %s', $table) ) === $table );

    // Two weeks is far past any run of closures the shop would set; the guard
    // is only there so a bad table can never spin this forever.
    for ( $i = 0; $i < 14; $i++ ) {
        $dow = intval( date('N', strtotime($date)) );   // 1 Mon … 7 Sun
        if ( $dow >= 6 ) {
            $date = date('Y-m-d', strtotime($date . ' +1 day'));
            continue;
        }
        if ( $exists ) {
            $cap = $wpdb->get_var( $wpdb->prepare(
                "SELECT capacity FROM $table WHERE day_date=%s", $date
            ) );
            if ( $cap !== null && intval($cap) === 0 ) {
                $date = date('Y-m-d', strtotime($date . ' +1 day'));
                continue;
            }
        }
        return $date;
    }
    return $date;
}

/* ============================================================
 * 4. WRITE THE CARD
 * ============================================================ */
function btp_dtf_schedule_order( $order_id ) {
    if ( ! function_exists('wc_get_order') ) return;

    $order_id = intval($order_id);
    $order    = wc_get_order($order_id);
    if ( ! $order ) return;

    if ( $order->get_meta(BTP_DTF_JOB_META) ) return;      // already carded
    if ( ! btp_dtf_is_dtf_order($order) ) return;

    global $wpdb;
    $table = $wpdb->prefix . 'bt_jobs';
    $cols  = $wpdb->get_col("SHOW COLUMNS FROM $table", 0);
    if ( ! is_array($cols) || empty($cols) ) return;        // table not built yet

    // Second guard: a card may already point at this order even when the
    // order's own meta does not, which is what a board restore leaves behind.
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table WHERE woo_order_id=%d LIMIT 1", $order_id
    ) );
    if ( $existing ) {
        $order->update_meta_data(BTP_DTF_JOB_META, intval($existing));
        $order->save_meta_data();
        return;
    }

    $sum = btp_dtf_sheet_summary($order);

    $row = [
        'order_num'    => (string) $order->get_order_number(),
        'customer'     => btp_dtf_customer_name($order),
        'qty'          => $sum['pieces'],
        'location'     => '',
        'dept'         => BTP_DTF_DEPT,
        'status'       => 'None',
        'due_date'     => btp_dtf_due_date(),
        'art_link'     => '',
        'notes'        => $sum['notes'],
        'garment_type' => 'Gang Sheet',
        'caution'      => 0,
        'woo_order_id' => $order_id,
        'auto_kind'    => BTP_DTF_AUTO_KIND,
        'created_by'   => 'DTF Studio',
    ];
    // Only write columns this install actually has, so an older table still
    // gets its card rather than failing the whole insert.
    $row = array_intersect_key($row, array_flip($cols));

    if ( $wpdb->insert($table, $row) === false ) return;

    $job_id = intval($wpdb->insert_id);
    $order->update_meta_data(BTP_DTF_JOB_META, $job_id);
    $order->save_meta_data();   // meta only — never touches status, never mails
    $order->add_order_note( sprintf(
        'Scheduled on the production board for %s (Transfers, card #%d).',
        $row['due_date'], $job_id
    ) );
}

/* ============================================================
 * 5. WHEN IT FIRES
 *    On payment, which is when the shop considers an order in. All three
 *    hooks are wired because different gateways reach that point by
 *    different routes; the guards above make the extra runs no-ops.
 *    An order still pending or on hold has not been paid for and stays off
 *    the board until it is.
 * ============================================================ */
add_action('woocommerce_payment_complete',          'btp_dtf_schedule_order', 20);
add_action('woocommerce_order_status_processing',   'btp_dtf_schedule_order', 20);
add_action('woocommerce_order_status_completed',    'btp_dtf_schedule_order', 20);
