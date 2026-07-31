<?php
/**
 * BT Portal — WooCommerce order completion.
 *
 * Lets production close out a Transfers job card and have the matching
 * WooCommerce order flip to Completed, which fires Woo's own
 * "Completed order" customer email. No custom mail code — the email is
 * whatever WooCommerce > Settings > Emails > Completed order is set to.
 *
 * The order number is never taken from the browser: the endpoint reads
 * order_num off the job row itself and resolves it server-side.
 */
if (!defined('ABSPATH')) exit;

/* ============================================================
 * 1. SCHEMA — three columns on wp_bt_jobs
 * ============================================================ */
add_action('init', function() {
    if ( get_option('btp_woo_migrated_v1') ) return;
    global $wpdb;
    $t = $wpdb->prefix . 'bt_jobs';

    $cols = $wpdb->get_col("SHOW COLUMNS FROM $t", 0);
    if ( ! is_array($cols) || empty($cols) ) return; // table not built yet; try again next load

    if ( ! in_array('woo_order_id', $cols) )
        $wpdb->query("ALTER TABLE $t ADD COLUMN woo_order_id bigint(20) DEFAULT NULL");
    if ( ! in_array('woo_completed_at', $cols) )
        $wpdb->query("ALTER TABLE $t ADD COLUMN woo_completed_at datetime DEFAULT NULL");
    if ( ! in_array('woo_completed_by', $cols) )
        $wpdb->query("ALTER TABLE $t ADD COLUMN woo_completed_by varchar(100) DEFAULT ''");

    update_option('btp_woo_migrated_v1', '1');
});

/* ============================================================
 * 2. PERMISSION — this one mutates orders and emails customers,
 *    so unlike the rest of the portal routes it is not open.
 *    Requires the wp_rest nonce minted on the portal page.
 * ============================================================ */
function btp_woo_perm( $request ) {
    $nonce = $request->get_header('X-WP-Nonce');
    if ( $nonce && wp_verify_nonce($nonce, 'wp_rest') ) return true;
    return new WP_Error('btp_forbidden', 'Portal session required.', ['status' => 403]);
}

/* ============================================================
 * 3. RESOLVER — job.order_num -> WC_Order
 *    Handles "5088", "#5088", "WC-5088" and any sequential-order-number
 *    plugin that writes _order_number. Returns [order, error_message].
 * ============================================================ */
function btp_woo_resolve_order( $raw ) {
    if ( ! function_exists('wc_get_order') ) {
        return [null, 'WooCommerce is not active on this site.'];
    }

    $num = trim( (string) $raw );
    $num = ltrim($num, "# \t");
    $num = trim($num);
    if ( $num === '' ) {
        return [null, 'This job card has no order number on it.'];
    }

    // a) plain numeric = order ID / default Woo order number
    if ( ctype_digit($num) ) {
        $o = wc_get_order( (int) $num );
        if ( $o instanceof WC_Order ) return [$o, ''];
    }

    // b) custom/sequential order number stored in meta
    if ( function_exists('wc_get_orders') ) {
        $found = wc_get_orders([
            'limit'      => 2,
            'status'     => 'any',
            'return'     => 'ids',
            'meta_query' => [[ 'key' => '_order_number', 'value' => $num ]],
        ]);
        if ( is_array($found) && count($found) === 1 ) {
            $o = wc_get_order( $found[0] );
            if ( $o instanceof WC_Order ) return [$o, ''];
        }
        if ( is_array($found) && count($found) > 1 ) {
            return [null, 'More than one WooCommerce order matches "' . $num . '". Fix the order number on the card.'];
        }
    }

    // c) last resort: digits inside a prefixed number, e.g. WC-5088
    if ( preg_match('/(\d{2,})/', $num, $m) ) {
        $o = wc_get_order( (int) $m[1] );
        if ( $o instanceof WC_Order ) return [$o, ''];
    }

    return [null, 'No WooCommerce order matches "' . $num . '".'];
}

/** Summary payload for the confirm dialog. */
function btp_woo_order_summary( $order ) {
    $name = trim( $order->get_formatted_billing_full_name() );
    if ( $name === '' ) $name = trim( $order->get_billing_company() );
    if ( $name === '' ) $name = '(no name on order)';

    $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
    $key      = 'wc-' . $order->get_status();

    return [
        'found'        => true,
        'order_id'     => $order->get_id(),
        'number'       => $order->get_order_number(),
        'status'       => $order->get_status(),
        'status_label' => isset($statuses[$key]) ? $statuses[$key] : ucfirst($order->get_status()),
        'customer'     => $name,
        'email'        => $order->get_billing_email(),
        'total'        => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ) ),
        'item_count'   => $order->get_item_count(),
        'date'         => $order->get_date_created() ? $order->get_date_created()->date('M j, Y') : '',
        'edit_url'     => $order->get_edit_order_url(),
    ];
}

/* ============================================================
 * 4. ROUTES
 * ============================================================ */
add_action('rest_api_init', function() {
    $ns = 'boomerts/v1';

    // Preview: what order will "Complete" actually touch?
    register_rest_route( $ns, '/jobs/(?P<id>\d+)/woo-order', [
        'methods'             => 'GET',
        'callback'            => 'btp_woo_lookup',
        'permission_callback' => 'btp_woo_perm',
    ]);

    // Do it.
    register_rest_route( $ns, '/jobs/(?P<id>\d+)/woo-complete', [
        'methods'             => 'POST',
        'callback'            => 'btp_woo_complete',
        'permission_callback' => 'btp_woo_perm',
    ]);
});

function btp_woo_get_job( $id ) {
    global $wpdb;
    $t = $wpdb->prefix . 'bt_jobs';
    return $wpdb->get_row( $wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id) );
}

function btp_woo_lookup( $request ) {
    $job = btp_woo_get_job( intval($request['id']) );
    if ( ! $job ) return rest_ensure_response(['found'=>false,'message'=>'Job not found.']);

    list($order, $err) = btp_woo_resolve_order( $job->order_num );
    if ( ! $order ) return rest_ensure_response(['found'=>false,'message'=>$err]);

    return rest_ensure_response( btp_woo_order_summary($order) );
}

function btp_woo_complete( $request ) {
    global $wpdb;
    $t   = $wpdb->prefix . 'bt_jobs';
    $id  = intval($request['id']);
    $job = btp_woo_get_job( $id );
    if ( ! $job ) return rest_ensure_response(['ok'=>false,'message'=>'Job not found.']);

    list($order, $err) = btp_woo_resolve_order( $job->order_num );
    if ( ! $order ) return rest_ensure_response(['ok'=>false,'message'=>$err]);

    $p   = $request->get_json_params();
    $who = sanitize_text_field( isset($p['user_name']) ? $p['user_name'] : '' );
    $st  = $order->get_status();

    // Refuse to touch orders that shouldn't be completed from the floor.
    if ( in_array($st, ['cancelled','refunded','failed','trash'], true) ) {
        return rest_ensure_response([
            'ok'      => false,
            'message' => 'Order #' . $order->get_order_number() . ' is ' . $st . '. Complete it from WooCommerce if that is really what you want.',
        ]);
    }

    // Already completed — just sync the card, never re-fire the email.
    if ( $st === 'completed' ) {
        $wpdb->update($t, [
            'woo_order_id'     => $order->get_id(),
            'woo_completed_at' => $job->woo_completed_at ? $job->woo_completed_at : current_time('mysql'),
            'woo_completed_by' => $job->woo_completed_by ? $job->woo_completed_by : $who,
            'status'           => 'Complete/Notify Customer',
        ], ['id'=>$id]);
        return rest_ensure_response([
            'ok'      => true,
            'already' => true,
            'message' => 'Order #' . $order->get_order_number() . ' was already Completed. No email sent.',
            'order'   => btp_woo_order_summary($order),
            'job'     => btp_woo_get_job($id),
        ]);
    }

    $note = 'Marked complete from the employee portal'
          . ( $who ? ' by ' . $who : '' )
          . ' (job card #' . $id . ').';

    // This is what sends the customer their Completed order email.
    $order->update_status( 'completed', $note );

    $fresh = wc_get_order( $order->get_id() );
    if ( ! $fresh || $fresh->get_status() !== 'completed' ) {
        return rest_ensure_response([
            'ok'      => false,
            'message' => 'WooCommerce refused the status change on order #' . $order->get_order_number() . '. Nothing was sent.',
        ]);
    }

    $wpdb->update($t, [
        'woo_order_id'     => $fresh->get_id(),
        'woo_completed_at' => current_time('mysql'),
        'woo_completed_by' => $who,
        'status'           => 'Complete/Notify Customer',
    ], ['id'=>$id]);

    return rest_ensure_response([
        'ok'      => true,
        'already' => false,
        'message' => 'Order #' . $fresh->get_order_number() . ' completed. Customer emailed.',
        'order'   => btp_woo_order_summary($fresh),
        'job'     => btp_woo_get_job($id),
    ]);
}
