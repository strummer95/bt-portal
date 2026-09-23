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
 *
 * 0.55.1 — three holes that between them duplicated every hand-typed card:
 *
 *   1. It listened for woocommerce_order_status_completed. The portal's own
 *      Complete Order button calls $order->update_status('completed'), which
 *      fires that hook synchronously, before woo.php gets to stamp
 *      woo_order_id on the card being completed. So finishing a job created
 *      a fresh card for the same order. Completion is the end of a job and
 *      is no longer a reason to schedule one.
 *   2. It only recognised cards it had written itself, by woo_order_id or
 *      _btp_dtf_job_id. A card somebody typed by hand carries neither, so an
 *      order that was already on the board was invisible to it. It now also
 *      matches an existing Transfers card on the order number.
 *   3. It had no cutoff, so any order predating the feature got a card the
 *      next time its status moved. It now ignores orders placed before the
 *      release that introduced it.
 */
if (!defined('ABSPATH')) exit;

const BTP_DTF_DEPT      = 'Transfers';
const BTP_DTF_JOB_META  = '_btp_dtf_job_id';
const BTP_DTF_AUTO_KIND = 'dtf';

/** Orders in before this hour go on today's column; after it, the next day. */
const BTP_DTF_CUTOFF_HOUR = 14;   // 2pm, shop clock

/**
 * The shop's own clock.
 *
 * 0.55.1 read the cutoff off WordPress's timezone, and this site is still
 * set to UTC, which is how Lightsail ships it. So "2pm" was firing at 9am in
 * the shop, and an order placed at 11:41am went on tomorrow's column. Pinned
 * here so the rule means 2pm where the shop is whatever that setting says,
 * and it stays right if somebody corrects the setting later.
 *
 * Override with define('BTP_DTF_TZ', ...) or the btp_dtf_timezone filter.
 */
if ( ! defined('BTP_DTF_TZ') ) define('BTP_DTF_TZ', 'America/Chicago');

function btp_dtf_timezone() {
    $tz = apply_filters('btp_dtf_timezone', BTP_DTF_TZ);
    try {
        return new DateTimeZone($tz);
    } catch ( Exception $e ) {
        return new DateTimeZone('America/Chicago');
    }
}

/** Orders placed before this feature went live are none of its business. */
const BTP_DTF_SINCE_OPT = 'btp_dtf_jobs_since';

/**
 * The moment this file first ran on the site, as a UTC timestamp.
 *
 * Without it, an order from any time in the past lands on today's board the
 * next time anything nudges its status — which is exactly what happened to
 * the orders already sitting on the board when 0.55.0 shipped.
 */
function btp_dtf_since_ts() {
    $ts = intval( get_option(BTP_DTF_SINCE_OPT, 0) );
    if ( ! $ts ) {
        $ts = time();
        add_option(BTP_DTF_SINCE_OPT, $ts, '', false);
    }
    return $ts;
}
add_action('init', 'btp_dtf_since_ts');

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

/**
 * Whose name goes on the card.
 *
 * An order the shop placed itself is shop work, not a customer's, so the
 * card says so rather than carrying an owner's name through production.
 * Add more names with the btp_dtf_inhouse_names filter.
 */
const BTP_DTF_INHOUSE_LABEL = 'In House Transfers';

function btp_dtf_is_inhouse( $name ) {
    $names = apply_filters('btp_dtf_inhouse_names', ['dillon johnson']);
    $needle = strtolower( preg_replace('/\s+/', ' ', trim($name)) );
    if ( $needle === '' ) return false;
    foreach ( (array) $names as $n ) {
        if ( $needle === strtolower( preg_replace('/\s+/', ' ', trim($n)) ) ) return true;
    }
    return false;
}

/** Billing name, falling back to the company and then the email. */
function btp_dtf_customer_name( $order ) {
    $name = '';
    if ( method_exists($order, 'get_formatted_billing_full_name') )
        $name = trim( (string) $order->get_formatted_billing_full_name() );
    if ( btp_dtf_is_inhouse($name) ) return BTP_DTF_INHOUSE_LABEL;

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
 * Today if the order is in before the cutoff, otherwise the next day, read
 * off the shop's clock rather than the site's.
 *
 * The window this got wrong was 9am to 2pm in the shop, which is 2pm to 7pm
 * UTC: those orders were read as past the cutoff and pushed to the next day.
 * Evening orders happened to come out right, because under UTC the date had
 * already rolled forward while the hour read as early, and the two errors
 * cancelled. That is why this only showed up on a late-morning order.
 */
function btp_dtf_due_date() {
    $now = new DateTime('now', btp_dtf_timezone());
    return btp_dtf_due_date_for( $now->format('Y-m-d'), intval($now->format('G')) );
}

/** The rule itself, given a local date and hour. Split out so it can be tested. */
function btp_dtf_due_date_for( $local_date, $local_hour ) {
    $date = $local_hour < BTP_DTF_CUTOFF_HOUR
        ? $local_date
        : date('Y-m-d', strtotime($local_date . ' +1 day'));
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

    // A job that is over, dead or refunded is not work to be scheduled. This
    // also stands between the portal's Complete Order button and a new card,
    // since the order reads completed by the time any hook of ours could run.
    if ( in_array($order->get_status(), ['completed','cancelled','refunded','failed','trash'], true) ) return;

    // Nothing from before this feature existed.
    $created = $order->get_date_created();
    if ( ! $created || $created->getTimestamp() < btp_dtf_since_ts() ) return;

    global $wpdb;
    $table = $wpdb->prefix . 'bt_jobs';
    $cols  = $wpdb->get_col("SHOW COLUMNS FROM $table", 0);
    if ( ! is_array($cols) || empty($cols) ) return;        // table not built yet

    // Is this order already on the board? Three ways it can be, and all three
    // have to be checked or a second card appears beside the first.
    //   a) a card this file wrote, which carries woo_order_id
    //   b) a card somebody typed, which carries only the order number
    // The order's own _btp_dtf_job_id covers the common case above; these
    // cover a board restore that dropped the meta, and a hand-typed card that
    // never had it to begin with.
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $table WHERE woo_order_id=%d LIMIT 1", $order_id
    ) );

    $num = ltrim( trim( (string) $order->get_order_number() ), '#' );
    if ( ! $existing && $num !== '' ) {
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE dept=%s AND (order_num=%s OR order_num=%s) LIMIT 1",
            BTP_DTF_DEPT, $num, '#' . $num
        ) );
    }

    if ( $existing ) {
        // Remember which card, so this order is never looked at again.
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
 *    On payment, which is when the shop considers an order in. Both hooks
 *    are wired because different gateways reach that point by different
 *    routes; the guards above make the second run a no-op.
 *
 *    NOT on completion. woocommerce_order_status_completed was wired here
 *    in 0.55.0 to catch a gateway that goes straight to completed, and it
 *    cost three duplicate cards on the first day: the portal's own Complete
 *    Order button runs update_status('completed'), which fired this and
 *    built a new card for the job that had just been finished. An order
 *    that reaches completed without ever passing through payment is an
 *    order nobody needs to print.
 *
 *    An order still pending or on hold has not been paid for and stays off
 *    the board until it is.
 * ============================================================ */
add_action('woocommerce_payment_complete',          'btp_dtf_schedule_order', 20);
add_action('woocommerce_order_status_processing',   'btp_dtf_schedule_order', 20);
