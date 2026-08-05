<?php
/**
 * BT Portal — exchange order tracking.
 *
 * The exchange form on the site is a cart flow: it clears the cart, drops the
 * "Exchange Shipping" product in, and sends the customer through normal
 * checkout. So an exchange order is simply any WooCommerce order containing
 * that product — there is no separate record to hunt for.
 *
 * What Woo does NOT track is where the exchange physically is: waiting on the
 * customer's box to arrive, on the bench, or back out the door. That state
 * lives here, in wp_bt_exchanges, keyed one row per order.
 *
 * The product id is an option (default 4441) rather than a constant so the
 * shipping product can be rebuilt in Woo without a plugin release.
 */
if (!defined('ABSPATH')) exit;

/* ============================================================
 * 1. SCHEMA — one row per exchange order
 *    UNIQUE on order_id: two staff hitting the same dropdown at
 *    once must not be able to produce two rows for one order.
 * ============================================================ */
add_action('init', function() {
    if ( get_option('btp_exchanges_migrated_v1') ) return;
    global $wpdb;
    $t = $wpdb->prefix . 'bt_exchanges';
    $charset = $wpdb->get_charset_collate();

    $wpdb->query("CREATE TABLE IF NOT EXISTS $t (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        status varchar(30) NOT NULL DEFAULT 'awaiting',
        tracking varchar(120) NOT NULL DEFAULT '',
        notes text,
        updated_by varchar(100) NOT NULL DEFAULT '',
        updated_at datetime DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY order_id (order_id)
    ) $charset;");

    update_option('btp_exchanges_migrated_v1', '1');
});

/* Hidden flag — test orders and mistakes get tucked away rather than deleted,
   so nothing is ever actually lost and staff can hide the next one without a
   plugin release. */
add_action('init', function() {
    if ( get_option('btp_exchanges_migrated_v2') ) return;
    global $wpdb;
    $t = $wpdb->prefix . 'bt_exchanges';

    $cols = $wpdb->get_col("SHOW COLUMNS FROM $t", 0);
    if ( ! is_array($cols) || empty($cols) ) return; // table not built yet; retry next load
    if ( ! in_array('hidden', $cols) )
        $wpdb->query("ALTER TABLE $t ADD COLUMN hidden tinyint(1) NOT NULL DEFAULT 0");

    update_option('btp_exchanges_migrated_v2', '1');
});

/**
 * Orders Dillon identified as tests on 2026-08-05. Hidden once, on the first
 * load after this update, then the seed switches itself off — so un-hiding one
 * in the portal sticks and this list never fights him.
 *
 * Matched on order number AND order id, because a sequential-order-number
 * plugin can make those two different and the numbers above are what the
 * portal displayed.
 */
function btp_exchange_seed_hidden() {
    return array('4980', '4457', '4456', '4455', '4454', '4453', '4452');
}

/* ============================================================
 * 2. CONFIG
 * ============================================================ */
function btp_exchange_product_id() {
    $id = (int) get_option('btp_exchange_product_id', 4441);
    if ($id <= 0) $id = 4441;
    return (int) apply_filters('btp_exchange_product_id', $id);
}

function btp_exchange_statuses() {
    return array(
        'awaiting' => 'Awaiting Items',
        'received' => 'Received',
        'shipped'  => 'Shipped',
    );
}

/* ============================================================
 * 3. PERMISSION — this route set reads customer names, emails and
 *    addresses and writes order notes, so unlike the job/store
 *    routes it is not open. Same nonce gate as woo.php.
 * ============================================================ */
function btp_ex_perm( $request ) {
    $nonce = $request->get_header('X-WP-Nonce');
    if ( $nonce && wp_verify_nonce($nonce, 'wp_rest') ) return true;
    return new WP_Error('btp_forbidden', 'Portal session required.', ['status' => 403]);
}

/* ============================================================
 * 4. FINDING EXCHANGE ORDERS
 *
 *    Read the order-items tables directly instead of looping
 *    wc_get_orders() over the whole shop. Those two tables are
 *    identical under HPOS and legacy post storage, so this works
 *    either way, and _product_id holds the parent id so a
 *    variation of the shipping product still matches.
 * ============================================================ */
function btp_exchange_order_ids( $limit = 100 ) {
    global $wpdb;
    $limit = max(1, min(300, (int) $limit));
    $pid   = btp_exchange_product_id();

    return $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT oi.order_id
           FROM {$wpdb->prefix}woocommerce_order_items oi
           JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
             ON oim.order_item_id = oi.order_item_id
          WHERE oi.order_item_type = 'line_item'
            AND oim.meta_key = '_product_id'
            AND oim.meta_value = %d
          ORDER BY oi.order_id DESC
          LIMIT %d",
        $pid, $limit
    ) );
}

/** Tracking row for an order, defaulted when none has been saved yet. */
function btp_exchange_row( $order_id ) {
    global $wpdb;
    $t   = $wpdb->prefix . 'bt_exchanges';
    $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $t WHERE order_id = %d", $order_id), ARRAY_A );
    if ( ! $row ) {
        return array(
            'status'     => 'awaiting',
            'tracking'   => '',
            'notes'      => '',
            'hidden'     => 0,
            'updated_by' => '',
            'updated_at' => '',
        );
    }
    return array(
        'status'     => $row['status'],
        'tracking'   => $row['tracking'],
        'notes'      => (string) $row['notes'],
        'hidden'     => isset($row['hidden']) ? (int) $row['hidden'] : 0,
        'updated_by' => $row['updated_by'],
        'updated_at' => $row['updated_at'] ? $row['updated_at'] : '',
    );
}

/**
 * The exchange form writes its request as one run-on customer note:
 *
 *   EXCHANGE REQUEST Original Order #: 186976289 Customer: Rachelle Sahni
 *   Ship to: 311 Phillippa St, Hinsdale, IL 60521 Item 1: Ordered: Performance
 *   Shorts (TT11SHY) | AM | Royal Wants: New size AS (same product & color)
 *
 * Unreadable at a glance in a table, so it gets pulled apart here rather than
 * in the browser: parsing server-side means one place to fix when the form's
 * wording changes, and the raw note is always returned as a fallback so a
 * format this doesn't recognise still shows up in full.
 *
 * Customer and Ship to are dropped — the table already has both columns.
 */
function btp_parse_exchange_note( $note ) {
    $note = trim( (string) $note );
    $out  = array( 'original_order' => '', 'items' => array(), 'raw' => $note, 'parsed' => false );
    if ( $note === '' ) return $out;

    if ( preg_match( '/Original Order\s*#?\s*:\s*([^\s]+)/i', $note, $m ) ) {
        $out['original_order'] = trim( $m[1], " \t,." );
    }

    // Split on "Item N:" so multi-item requests come through as separate rows.
    if ( preg_match_all( '/Item\s*\d+\s*:\s*(.*?)(?=Item\s*\d+\s*:|$)/is', $note, $chunks ) ) {
        foreach ( $chunks[1] as $chunk ) {
            $chunk = trim( $chunk );
            if ( $chunk === '' ) continue;

            $ordered = '';
            $wants   = '';
            if ( preg_match( '/Ordered\s*:\s*(.*?)\s*Wants\s*:\s*(.*)$/is', $chunk, $mm ) ) {
                $ordered = trim( $mm[1] );
                $wants   = trim( $mm[2] );
            } elseif ( preg_match( '/Ordered\s*:\s*(.*)$/is', $chunk, $mm ) ) {
                $ordered = trim( $mm[1] );
            } else {
                $ordered = $chunk;
            }

            // "Performance Shorts (TT11SHY) | AM | Royal" -> name + attributes
            $parts = array_values( array_filter( array_map( 'trim', explode( '|', $ordered ) ), 'strlen' ) );
            $name  = array_shift( $parts );

            $out['items'][] = array(
                'name'  => (string) $name,
                'attrs' => $parts,
                'wants' => $wants,
            );
        }
    }

    $out['parsed'] = ( $out['original_order'] !== '' || ! empty( $out['items'] ) );
    return $out;
}

/**
 * Order -> payload for the portal table.
 * Line items come back with their formatted meta so whatever the exchange
 * form attached (size wanted, original order, reason) shows up without this
 * file having to know the field names.
 */
function btp_exchange_payload( $order ) {
    $pid   = btp_exchange_product_id();
    $items = array();
    $extra = array();

    foreach ( $order->get_items() as $item ) {
        $meta = array();
        foreach ( $item->get_formatted_meta_data('_', true) as $m ) {
            $meta[] = array(
                'key'   => wp_strip_all_tags( $m->display_key ),
                'value' => trim( wp_strip_all_tags( $m->display_value ) ),
            );
        }

        // The shipping line is not something being exchanged, but the form may
        // have hung the exchange details on it — keep that meta rather than
        // throwing the whole line away.
        if ( (int) $item->get_product_id() === $pid ) {
            $extra = array_merge( $extra, $meta );
            continue;
        }

        $items[] = array(
            'name' => $item->get_name(),
            'qty'  => (int) $item->get_quantity(),
            'meta' => $meta,
        );
    }

    // Order-level custom fields, minus Woo's own internals.
    foreach ( $order->get_meta_data() as $m ) {
        $d = $m->get_data();
        $k = isset($d['key']) ? (string) $d['key'] : '';
        if ( $k === '' || $k[0] === '_' ) continue;
        $v = $d['value'];
        if ( ! is_scalar($v) ) continue;
        $extra[] = array( 'key' => $k, 'value' => trim( wp_strip_all_tags( (string) $v ) ) );
    }

    // Woo joins address lines with <br/>; strip the tags without gluing the
    // words together ("1 Main StOswego IL").
    $ship_raw = $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address();
    $ship     = preg_replace( '/<br\s*\/?>/i', ', ', (string) $ship_raw );
    $ship     = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $ship ) ), " ,\t\n" );

    return array(
        'order_id'      => $order->get_id(),
        'number'        => $order->get_order_number(),
        'date'          => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
        'woo_status'    => $order->get_status(),
        'woo_status_lbl'=> wc_get_order_status_name( $order->get_status() ),
        // An order that was cancelled, refunded or failed is not work in
        // progress — nobody is waiting on a box for it. The portal derives
        // this from Woo every load rather than storing it, so reinstating the
        // order in wp-admin puts it straight back in the queue.
        'cancelled'     => in_array( $order->get_status(), array('cancelled', 'refunded', 'failed'), true ),
        // Not paid for yet, so not a live exchange either — nobody should be
        // expecting a box for an order that never went through. Derived from
        // Woo each load, so it clears itself the moment payment lands.
        'unpaid'        => in_array( $order->get_status(), array('pending', 'on-hold'), true ),
        'total'         => (float) $order->get_total(),
        'customer'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
        'email'         => $order->get_billing_email(),
        'phone'         => $order->get_billing_phone(),
        'address'       => $ship,
        'customer_note' => $order->get_customer_note(),
        'request'       => btp_parse_exchange_note( $order->get_customer_note() ),
        'extra'         => $extra,
        'edit_url'      => $order->get_edit_order_url(),
        'items'         => $items,
    );
}

/* ============================================================
 * 5. ROUTES
 * ============================================================ */
add_action('rest_api_init', function() {
    $ns = 'boomerts/v1';

    register_rest_route( $ns, '/exchanges', [
        'methods'             => 'GET',
        'callback'            => 'btp_get_exchanges',
        'permission_callback' => 'btp_ex_perm',
    ]);

    register_rest_route( $ns, '/exchanges/(?P<order_id>\d+)', [
        'methods'             => 'POST',
        'callback'            => 'btp_update_exchange',
        'permission_callback' => 'btp_ex_perm',
    ]);
});

function btp_get_exchanges( $request ) {
    if ( ! function_exists('wc_get_order') ) {
        return new WP_Error('btp_no_woo', 'WooCommerce is not active on this site.', ['status' => 400]);
    }

    $limit = (int) $request->get_param('limit');
    if ( $limit <= 0 ) $limit = 100;

    global $wpdb;
    $seeding = ! get_option('btp_exchanges_seeded_hidden_v1');
    $seed    = $seeding ? btp_exchange_seed_hidden() : array();

    $out = array();
    foreach ( btp_exchange_order_ids( $limit ) as $oid ) {
        $order = wc_get_order( (int) $oid );
        if ( ! $order ) continue;
        if ( in_array( $order->get_status(), array('trash', 'checkout-draft'), true ) ) continue;

        $oid = (int) $oid;

        if ( $seeding && ( in_array( (string) $order->get_order_number(), $seed, true )
                        || in_array( (string) $oid, $seed, true ) ) ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}bt_exchanges (order_id, status, hidden, updated_by, updated_at, created_at)
                 VALUES (%d, 'awaiting', 1, 'system', %s, %s)
                 ON DUPLICATE KEY UPDATE hidden = 1",
                $oid, current_time('mysql'), current_time('mysql')
            ) );
        }

        $out[] = array_merge( btp_exchange_payload( $order ), btp_exchange_row( $oid ) );
    }

    if ( $seeding ) update_option('btp_exchanges_seeded_hidden_v1', '1');

    return rest_ensure_response( array(
        'product_id' => btp_exchange_product_id(),
        'statuses'   => btp_exchange_statuses(),
        'exchanges'  => $out,
    ) );
}

function btp_update_exchange( $request ) {
    global $wpdb;

    $order_id = (int) $request['order_id'];
    if ( $order_id <= 0 ) {
        return new WP_Error('btp_bad_order', 'Missing order.', ['status' => 400]);
    }

    $body    = $request->get_json_params();
    $current = btp_exchange_row( $order_id );

    $status = isset($body['status']) ? sanitize_text_field($body['status']) : $current['status'];
    if ( ! array_key_exists( $status, btp_exchange_statuses() ) ) {
        return new WP_Error('btp_bad_status', 'Unknown exchange status.', ['status' => 400]);
    }

    $tracking = isset($body['tracking']) ? sanitize_text_field($body['tracking']) : $current['tracking'];
    $notes    = isset($body['notes'])    ? sanitize_textarea_field($body['notes']) : $current['notes'];
    $hidden   = isset($body['hidden'])   ? ( $body['hidden'] ? 1 : 0 ) : (int) $current['hidden'];
    $user     = isset($body['user_name'])? sanitize_text_field($body['user_name']) : '';
    $now      = current_time('mysql');

    $t = $wpdb->prefix . 'bt_exchanges';
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO $t (order_id, status, tracking, notes, hidden, updated_by, updated_at, created_at)
         VALUES (%d, %s, %s, %s, %d, %s, %s, %s)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            tracking = VALUES(tracking),
            notes = VALUES(notes),
            hidden = VALUES(hidden),
            updated_by = VALUES(updated_by),
            updated_at = VALUES(updated_at)",
        $order_id, $status, $tracking, $notes, $hidden, $user, $now, $now
    ) );

    // Leave a trail on the order itself, but only when the state actually moved.
    if ( $status !== $current['status'] && function_exists('wc_get_order') ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $labels = btp_exchange_statuses();
            $note   = 'Exchange marked ' . $labels[$status];
            if ( $status === 'shipped' && $tracking !== '' ) $note .= ' (tracking ' . $tracking . ')';
            if ( $user !== '' ) $note .= ' by ' . $user;
            $order->add_order_note( $note . ' from the BT Portal.' );
        }
    }

    return rest_ensure_response( array_merge(
        array('order_id' => $order_id),
        btp_exchange_row( $order_id )
    ) );
}
