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
        'awaiting'     => 'Awaiting Items',
        'received'     => 'Received',
        'shipped'      => 'Shipped',
        'ready_pickup' => 'Ready for Pickup',
    );
}

/**
 * Moving an exchange forward in the portal moves the Woo order with it, so the
 * two never disagree — someone looking at wp-admin sees the same truth as
 * someone looking at the portal.
 *
 * Shipped and Ready for Pickup both mean the work is done and it has left the
 * bench, so both complete the order. Awaiting and Received are work in
 * progress. Cancelled, refunded and failed orders are left alone: reopening
 * one of those from a status dropdown would be a surprise.
 */
function btp_exchange_woo_status_for( $status ) {
    $map = array(
        'awaiting'     => 'processing',
        'received'     => 'processing',
        'shipped'      => 'completed',
        'ready_pickup' => 'completed',
    );
    $to = isset( $map[ $status ] ) ? $map[ $status ] : '';
    return (string) apply_filters( 'btp_exchange_woo_status', $to, $status );
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

    // Orders that have previously crashed the request, kept out of the query
    // entirely rather than being tried and killing the page again.
    $skip = get_option('btp_ex_skip');
    $skip = is_array($skip) ? array_map('intval', $skip) : array();
    $not  = $skip ? ' AND oi.order_id NOT IN (' . implode( ',', $skip ) . ') ' : '';

    /* meta_value is a LONGTEXT column. Comparing it to an unquoted number
       (meta_value = 4441) makes MySQL cast every row in the table to a number
       before it can compare, which rules out the meta_key index and turns this
       into a full scan of a table that grows with every order ever placed.
       Quoting it — meta_value = '4441' — compares string to string, uses the
       index, and is the difference between milliseconds and half a minute.

       This is why the failure looked so strange: it had nothing to do with how
       many orders were requested, because the scan happens before LIMIT is
       ever applied. It simply got slower every time an order was placed, until
       it crossed PHP's 30 second execution limit and the request was killed
       outright. */

    $cache_key = 'btp_ex_ids_' . md5( $pid . '|' . $limit . '|' . implode( ',', $skip ) );
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) ) return $cached;

    $started = microtime( true );

    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT oi.order_id
           FROM {$wpdb->prefix}woocommerce_order_items oi
           JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
             ON oim.order_item_id = oi.order_item_id
          WHERE oi.order_item_type = 'line_item'
            AND oim.meta_key = '_product_id'
            AND oim.meta_value = %s
            $not
          ORDER BY oi.order_id DESC
          LIMIT %d",
        (string) $pid, $limit
    ) );

    update_option( 'btp_ex_query_ms', round( ( microtime( true ) - $started ) * 1000 ), false );

    // Five minutes is long enough to make repeat loads instant and short
    // enough that a new exchange shows up without anyone clearing anything.
    set_transient( $cache_key, $ids, 5 * MINUTE_IN_SECONDS );

    return $ids;
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
/**
 * Parses the run-on customer note the exchange form used to write.
 *
 * Every pattern here is bounded, and deliberately so. The earlier versions used
 * unbounded lazy groups — (.+?) followed by another (.*?) — which are fine on a
 * two-line note and catastrophic on a long one: the regex engine retries an
 * exponential number of splits and the request runs until PHP's 30 second
 * execution limit kills it outright. That is not an error any code can catch;
 * the process simply stops, which is why this surfaced as a bare "critical
 * error" with nothing in the response.
 */
function btp_parse_exchange_note( $note ) {
    $note = trim( (string) $note );
    $out  = array( 'original_order' => '', 'store' => '', 'items' => array(), 'raw' => $note, 'parsed' => false );
    if ( $note === '' ) return $out;

    /* A genuine exchange request is a few hundred characters. Anything past
       this is a paste accident or a form loop, and no useful field lives out
       there — but it is exactly what makes the patterns below expensive. */
    if ( strlen( $note ) > 8000 ) {
        $note = substr( $note, 0, 8000 );
    }

    if ( preg_match( '/Original Order\s*#?\s*:\s*([^\s]+)/i', $note, $m ) ) {
        $out['original_order'] = trim( $m[1], " \t,." );
    }

    // School/Team, however the form ends up labelling it. Stops at the next
    // known label so the rest of the sentence doesn't get swallowed.
    if ( preg_match(
        '/(?:School\s*\/\s*Team|School or Team|School|Team|Store|Organization)\s*:\s*(.{0,200}?)\s*(?=Customer\s*:|Ship\s*to\s*:|Original Order|Item\s*\d+\s*:|$)/is',
        $note, $m
    ) ) {
        $out['store'] = trim( $m[1], " \t,.-" );
    }

    /* Current note wording, one line per item:
         2x Performance Shorts, Royal - AM to AS
       (written with a multiplication sign and an em dash). Only used when the
       structured meta is missing, e.g. an order placed through a checkout that
       never ran the form's hooks. */
    /* Split first and match per line. The name is [^,] rather than (.+?), which
       removes the backtracking entirely: there is only one way to reach the
       comma, so the engine cannot try thousands of them. */
    foreach ( preg_split( '/\r\n|\r|\n/', $note, 400 ) as $line ) {
        if ( strlen( $line ) > 400 ) continue;   // not an item line
        if ( preg_match(
            '/^\s*(\d+)\s*[x\x{00d7}]\s*([^,]{1,200}),\s*([^\x{2014}\x{2013}-]{0,120})\s*[\x{2014}\x{2013}-]\s*(\S+)\s+to\s+(\S+)\s*$/iu',
            $line, $l
        ) ) {
            $out['items'][] = array(
                'name'  => trim($l[2]),
                'size'  => trim($l[4]),
                'color' => trim($l[3]),
                'qty'   => max( 1, (int) $l[1] ),
                'want'  => trim($l[5]),
            );
        }
    }

    // Older wording: split on "Item N:" so multi-item requests separate.
    if ( empty( $out['items'] ) && preg_match_all( '/Item\s*\d+\s*:\s*(.{0,1000}?)(?=Item\s*\d+\s*:|$)/is', $note, $chunks ) ) {
        foreach ( $chunks[1] as $chunk ) {
            $chunk = trim( $chunk );
            if ( $chunk === '' ) continue;

            $ordered = '';
            $wants   = '';
            if ( preg_match( '/Ordered\s*:\s*(.{0,400}?)\s*Wants\s*:\s*(.{0,400})$/is', $chunk, $mm ) ) {
                $ordered = trim( $mm[1] );
                $wants   = trim( $mm[2] );
            } elseif ( preg_match( '/Ordered\s*:\s*(.{0,400})$/is', $chunk, $mm ) ) {
                $ordered = trim( $mm[1] );
            } else {
                $ordered = $chunk;
            }

            // "Performance Shorts (TT11SHY) | AM | Royal" -> product | size | color
            $parts = array_values( array_filter( array_map( 'trim', explode( '|', $ordered ) ), 'strlen' ) );
            $name  = array_shift( $parts );

            // "New size AS (same product & color)" -> AS
            $want_size = $wants;
            if ( preg_match( '/New size\s+(\S+)/i', $wants, $ws ) ) $want_size = trim( $ws[1], " .,()" );

            $out['items'][] = array(
                'name'  => (string) $name,
                'size'  => isset($parts[0]) ? $parts[0] : '',
                'color' => isset($parts[1]) ? $parts[1] : '',
                'qty'   => 1,
                'want'  => $want_size,
            );
        }
    }

    $out['parsed'] = ( $out['original_order'] !== '' || $out['store'] !== '' || ! empty( $out['items'] ) );
    return $out;
}

/**
 * Order meta is a junk drawer — tax plugins, shipping plugins and the like all
 * park flags there (is_vat_exempt, tefw_exempt). None of it means anything to
 * someone processing an exchange, so it is filtered out before display.
 *
 * The rule is deliberately blunt: a bare flag tells staff nothing useful in a
 * table whatever it is called, and anything prefixed like a system field is
 * not a human-entered exchange detail. Add keys via the filter if something
 * else creeps in; no release needed for that.
 */
function btp_exchange_meta_is_noise( $key, $value ) {
    $k = strtolower( trim( (string) $key ) );
    $v = strtolower( trim( (string) $value ) );

    $blocked = apply_filters( 'btp_exchange_meta_blocklist', array(
        'is_vat_exempt', 'tefw_exempt', 'vat_number', 'vat_exempt',
        'shipping_phone', 'billing_phone', 'order_key', 'cart_hash',
        'customer_ip_address', 'customer_user_agent', 'created_via',
    ) );

    if ( in_array( $k, $blocked, true ) ) return true;
    if ( preg_match( '/^(is_|has_|use_|wc_|woocommerce_|ppcp|mailchimp|yith|wpo_|tefw)/', $k ) ) return true;
    if ( preg_match( '/(_exempt|_hash|_key|_id)$/', $k ) ) return true;

    // Bare yes/no flags carry no information here.
    return in_array( $v, array('', '0', '1', 'no', 'yes', 'true', 'false'), true );
}

/** underscore_key -> "Underscore Key" for display. */
function btp_exchange_meta_label( $key ) {
    return ucwords( trim( str_replace( array('_', '-'), ' ', (string) $key ) ) );
}

/**
 * How the exchange travels, in each direction. Read off the order, falling back
 * to the shipping line, then to the old defaults: everything placed before the
 * customer got a choice was mail in, ship back.
 */
function btp_exchange_methods( $order ) {
    $send   = (string) $order->get_meta( '_bt_send_method' );
    $return = (string) $order->get_meta( '_bt_return_method' );

    if ( $send === '' || $return === '' ) {
        foreach ( btp_exchange_line_meta( $order ) as $m ) {
            if ( $m['key'] === '_bt_send_method' )   { if ( $send === '' )   $send   = $m['value']; }
            if ( $m['key'] === '_bt_return_method' ) { if ( $return === '' ) $return = $m['value']; }
        }
    }

    if ( ! in_array( $send, array( 'mail', 'dropoff' ), true ) )  $send   = 'mail';
    if ( ! in_array( $return, array( 'ship', 'pickup' ), true ) ) $return = 'ship';

    return array( 'send' => $send, 'return' => $return );
}

/**
 * Which platform the customer's original order number came from.
 *
 * The two stores Boomer T's runs against number their orders differently and
 * neither prints the platform name on the confirmation email, so staff were
 * eyeballing the digits. The rule is the length and the leading digit:
 *   9 digits starting 1 -> OrderMyGear
 *   7 digits starting 8 -> Chipply
 * Anything else is left blank rather than guessed at — a wrong badge is worse
 * than no badge when someone is about to go looking for the order.
 */
function btp_exchange_source( $number ) {
    $d = preg_replace( '/\D+/', '', (string) $number );
    $src = '';
    if ( strlen($d) === 9 && $d[0] === '1' ) $src = 'OMG';
    if ( strlen($d) === 7 && $d[0] === '8' ) $src = 'Chipply';
    return (string) apply_filters( 'btp_exchange_source', $src, $number );
}

/**
 * Every meta pair on the Exchange Shipping line, hidden keys included.
 *
 * The portal's display pass hides underscore-prefixed keys, which is right for
 * showing to staff but wrong for parsing: if the form ever writes its fields
 * as system meta they still need reading. Woo's admin screen passes an empty
 * hide-prefix for the same reason, which is why _btgsb_combined shows up there
 * and not here.
 */
function btp_exchange_line_meta( $order ) {
    $pid = btp_exchange_product_id();
    $out = array();
    foreach ( $order->get_items() as $item ) {
        if ( (int) $item->get_product_id() !== $pid ) continue;
        foreach ( $item->get_formatted_meta_data( '', true ) as $m ) {
            $out[] = array(
                'key'   => wp_strip_all_tags( (string) $m->key ),
                'label' => wp_strip_all_tags( (string) $m->display_key ),
                'value' => trim( wp_strip_all_tags( (string) $m->display_value ) ),
            );
        }
    }
    return $out;
}

/**
 * One item written as a single string, however the form chooses to join it:
 *   "2x Performance Shorts (TT11SHY) | AM | Royal -> AS"
 * Quantity may lead or trail, the wanted size may be arrowed or worded, and
 * product/size/colour are pipe separated. Anything missing stays empty rather
 * than shifting the remaining values one column left.
 */
function btp_exchange_parse_combined( $value ) {
    $out = array( 'name' => '', 'size' => '', 'color' => '', 'qty' => 1, 'want' => '' );
    $v = trim( (string) $value );
    if ( $v === '' ) return $out;

    if ( preg_match( '/^\s*(\d+)\s*[x\x{00d7}]\s*(.+)$/iu', $v, $m ) ) {
        $out['qty'] = max( 1, (int) $m[1] ); $v = trim( $m[2] );
    } elseif ( preg_match( '/^(.+?)\s*[x\x{00d7}]\s*(\d+)\s*$/iu', $v, $m ) ) {
        $out['qty'] = max( 1, (int) $m[2] ); $v = trim( $m[1] );
    }

    // Arrow first — an explicit arrow is unambiguous where the word "to" is not.
    if ( preg_match( '/^(.*?)\s*(?:->|=>|\x{2192}|\x{27a1})\s*(\S+)\s*$/u', $v, $m ) ) {
        $v = trim( $m[1] ); $out['want'] = trim( $m[2], " .,()" );
    } elseif ( preg_match( '/^(.*?)\s+to\s+([A-Za-z0-9]{1,5})\s*$/i', $v, $m ) ) {
        $v = trim( $m[1] ); $out['want'] = trim( $m[2], " .,()" );
    }

    /* Positions are kept, empties included: an item with a colour but no size
       writes "Beanie |  | Black", and dropping the blank would slide Black up
       into the Size column. */
    $parts = array_map( 'trim', explode( '|', $v ) );
    if ( empty( $parts ) ) $parts = array( $v );

    $out['name']  = (string) array_shift( $parts );
    $out['size']  = isset($parts[0]) ? $parts[0] : '';
    $out['color'] = isset($parts[1]) ? $parts[1] : '';
    return $out;
}

/**
 * Read the request off the Exchange Shipping line's meta.
 *
 * The form moved from writing one run-on customer note to hanging labelled
 * fields on the cart item, which is better — except the portal only knew how
 * to read the note, so the labelled fields fell through to the raw-meta
 * fallback and the Product cell filled up with the order number and the school
 * name it was already showing two columns to the left.
 *
 * Field names are matched, not hardcoded: "Item 1 Product", "Item 1: Size",
 * "Item 2 New Size" and so on, grouped by the number. So whichever of those
 * the form ends up writing, the columns fill in without a plugin release to
 * match it.
 */
function btp_exchange_request_from_line_meta( $order ) {
    $meta = btp_exchange_line_meta( $order );
    if ( empty( $meta ) ) return null;

    $out  = array( 'original_order' => '', 'store' => '', 'items' => array(), 'raw' => '', 'parsed' => false );
    $rows = array();

    foreach ( $meta as $m ) {
        $label = trim( $m['label'] );
        $value = trim( $m['value'] );
        if ( $value === '' || $label === '' ) continue;
        if ( $label[0] === '_' ) continue;            // system flag, not a human field

        if ( $out['original_order'] === '' && preg_match( '/original\s*order|order\s*(number|#|no\b)/i', $label ) ) {
            $out['original_order'] = trim( $value, " \t#,." );
            continue;
        }
        if ( $out['store'] === '' && preg_match( '/school|team|store|organi[sz]ation|group/i', $label ) ) {
            $out['store'] = $value;
            continue;
        }

        if ( ! preg_match( '/^item\s*(\d+)\s*[:\-]?\s*(.*)$/i', $label, $mm ) ) continue;

        $i     = (int) $mm[1];
        $field = strtolower( trim( $mm[2] ) );
        if ( ! isset( $rows[$i] ) ) $rows[$i] = array( 'name' => '', 'size' => '', 'color' => '', 'qty' => 1, 'want' => '' );

        if ( $field === '' ) {
            $rows[$i] = btp_exchange_parse_combined( $value );
        } elseif ( preg_match( '/new\s*size|want|exchange\s*for|replacement/', $field ) ) {
            $rows[$i]['want'] = $value;
        } elseif ( preg_match( '/size/', $field ) ) {
            $rows[$i]['size'] = $value;
        } elseif ( preg_match( '/colou?r/', $field ) ) {
            $rows[$i]['color'] = $value;
        } elseif ( preg_match( '/qty|quantity/', $field ) ) {
            $rows[$i]['qty'] = max( 1, (int) $value );
        } else {
            $rows[$i]['name'] = $value;               // product / item / style
        }
    }

    ksort( $rows );
    foreach ( $rows as $r ) {
        if ( $r['name'] === '' && $r['size'] === '' && $r['color'] === '' && $r['want'] === '' ) continue;
        $out['items'][] = $r;
    }

    $out['parsed'] = ( $out['original_order'] !== '' || $out['store'] !== '' || ! empty( $out['items'] ) );
    return $out['parsed'] ? $out : null;
}

/**
 * The exchange form stores the request structurally on the order:
 *   _bt_exchange_request  yes
 *   _bt_original_order    the customer's original order number
 *   _bt_school_team       the store they ordered from
 *   _bt_exchange_items    [ ['ordered'=>['name','size','color'],'want'=>['size'],'qty'=>n], ... ]
 *
 * That is the source of truth — exact fields, no guessing. Note parsing stays
 * as the fallback for orders placed before a given field existed, and for the
 * older note wording.
 */
function btp_exchange_request_from_meta( $order ) {
    $items = $order->get_meta( '_bt_exchange_items' );
    if ( ! is_array( $items ) || empty( $items ) ) return null;

    $out = array(
        'original_order' => (string) $order->get_meta( '_bt_original_order' ),
        'store'          => (string) $order->get_meta( '_bt_school_team' ),
        'items'          => array(),
        'raw'            => '',
        'parsed'         => true,
    );

    foreach ( $items as $it ) {
        $o = isset($it['ordered']) && is_array($it['ordered']) ? $it['ordered'] : array();
        $w = isset($it['want'])    && is_array($it['want'])    ? $it['want']    : array();
        $qty = max( 1, (int) ( isset($it['qty']) ? $it['qty'] : 1 ) );

        $out['items'][] = array(
            'name'  => isset($o['name'])  ? (string) $o['name']  : '',
            'size'  => isset($o['size'])  ? (string) $o['size']  : '',
            'color' => isset($o['color']) ? (string) $o['color'] : '',
            'qty'   => $qty,
            'want'  => isset($w['size'])  ? (string) $w['size']  : '',
        );
    }

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

    // Order-level custom fields, minus Woo's own internals and the flags other
    // plugins leave lying around.
    foreach ( $order->get_meta_data() as $m ) {
        $d = $m->get_data();
        $k = isset($d['key']) ? (string) $d['key'] : '';
        if ( $k === '' || $k[0] === '_' ) continue;
        $v = $d['value'];
        if ( ! is_scalar($v) ) continue;
        if ( btp_exchange_meta_is_noise( $k, $v ) ) continue;
        $extra[] = array( 'key' => btp_exchange_meta_label( $k ), 'value' => trim( wp_strip_all_tags( (string) $v ) ) );
    }

    // Woo joins address lines with <br/>; strip the tags without gluing the
    // words together ("1 Main StOswego IL").
    $ship_raw = $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address();
    $ship     = preg_replace( '/<br\s*\/?>/i', ', ', (string) $ship_raw );
    $ship     = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $ship ) ), " ,\t\n" );

    /* Three places the request can live, best first: structured order meta,
       labelled meta on the shipping line, then the old run-on customer note.
       They are merged rather than raced, because the form has changed shape
       twice — an order can carry the order number one way and the item rows
       another, and taking only the first hit loses whatever the other held. */
    $request = null;
    $candidates = array(
        btp_exchange_request_from_meta( $order ),
        btp_exchange_request_from_line_meta( $order ),
        btp_parse_exchange_note( $order->get_customer_note() ),
    );
    foreach ( $candidates as $cand ) {
        if ( ! $cand ) continue;
        if ( ! $request ) { $request = $cand; continue; }
        if ( empty( $request['items'] ) && ! empty( $cand['items'] ) )       $request['items']          = $cand['items'];
        if ( $request['original_order'] === '' )                              $request['original_order'] = $cand['original_order'];
        if ( $request['store'] === '' )                                       $request['store']          = $cand['store'];
        if ( $request['raw'] === '' && ! empty( $cand['raw'] ) )              $request['raw']            = $cand['raw'];
    }
    if ( ! $request ) $request = array( 'original_order' => '', 'store' => '', 'items' => array(), 'raw' => '', 'parsed' => false );

    if ( $request['original_order'] === '' ) {
        $request['original_order'] = trim( (string) $order->get_meta( '_bt_original_order' ), " \t#" );
    }
    $request['parsed'] = ( $request['original_order'] !== '' || $request['store'] !== '' || ! empty( $request['items'] ) );

    /* School/Team can arrive three ways depending on how the form is wired:
       inside the request note, as order meta, or as meta on the shipping line.
       Whichever exists wins, in that order, so the portal keeps working
       through a change to the form rather than needing a release to match. */
    $store = (string) $order->get_meta( '_bt_school_team' );
    if ( $store === '' ) $store = $request['store'];
    if ( $store === '' ) {
        foreach ( $extra as $m ) {
            if ( preg_match( '/(school|team|store|organization|group)/i', $m['key'] ) && $m['value'] !== '' ) {
                $store = $m['value'];
                break;
            }
        }
    }

    /* Original Order # and School/Team have their own columns now. Left in
       here they came back a second time in the Product cell whenever there
       were no item rows to show — the order number and the school name
       repeated one column to the right of themselves. */
    $extra = array_values( array_filter( $extra, function( $m ) {
        return ! preg_match( '/original\s*order|order\s*(number|#|no\b)|school|team|store|organi[sz]ation|group|sending\s*items|new\s*items\s*back/i', $m['key'] );
    } ) );

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
        'request'        => $request,
        'store'          => $store,
        // The number the customer typed in from their confirmation email, and
        // which platform it points at. Own column — staff go looking for the
        // original order more often than anything else on the row.
        'original_order' => $request['original_order'],
        'source'         => btp_exchange_source( $request['original_order'] ),
        // Mail in or drop off, ship back or hold for pickup. Staff need this at
        // a glance — a finished pickup exchange must not go in a mailer.
        'methods'        => btp_exchange_methods( $order ),
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

    /* Deliberately loads nothing — no orders, no Woo. It has to answer even
       when /exchanges is killing the request, because that is exactly when
       its answer matters. */
    /* Clears the quarantine list — for after the offending order has been
       fixed or deleted in WooCommerce. */
    register_rest_route( $ns, '/exchanges/unskip', [
        'methods'             => 'POST',
        'callback'            => function() {
            delete_option('btp_ex_skip');
            delete_option('btp_ex_last_fatal');
            return rest_ensure_response( array( 'ok' => true ) );
        },
        'permission_callback' => 'btp_ex_perm',
    ]);

    register_rest_route( $ns, '/exchanges/diag', [
        'methods'             => 'GET',
        'callback'            => 'btp_exchanges_diag',
        'permission_callback' => 'btp_ex_perm',
    ]);

    register_rest_route( $ns, '/exchanges/(?P<order_id>\d+)', [
        'methods'             => 'POST',
        'callback'            => 'btp_update_exchange',
        'permission_callback' => 'btp_ex_perm',
    ]);
});

/**
 * Fatals that try/catch cannot see — memory exhaustion and execution timeouts —
 * used to surface as nothing but WordPress's "critical error" page. This
 * records which order was in hand when PHP died, and how much memory it had
 * taken, so the next load can say what happened instead of guessing.
 */
function btp_ex_watch_fatal() {
    register_shutdown_function( function() {
        $e = error_get_last();
        if ( ! $e || ! in_array( $e['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ), true ) ) {
            delete_option('btp_ex_last_fatal');
            return;
        }
        /* Whatever this order is, loading it kills PHP outright — the kind of
           fault no try/catch can intercept. Quarantine it so the next request
           gets past it: one unreadable order must not cost the whole tab. */
        $bad = isset($GLOBALS['btp_ex_current']) ? (int) $GLOBALS['btp_ex_current'] : 0;
        if ( $bad ) {
            $skip = get_option('btp_ex_skip');
            if ( ! is_array($skip) ) $skip = array();
            if ( ! in_array( $bad, $skip, true ) ) {
                $skip[] = $bad;
                update_option( 'btp_ex_skip', $skip, false );
            }
        }

        update_option( 'btp_ex_last_fatal', array(
            'message'  => $e['message'],
            'file'     => basename( $e['file'] ) . ':' . $e['line'],
            'order_id' => isset($GLOBALS['btp_ex_current']) ? $GLOBALS['btp_ex_current'] : 0,
            'done'     => isset($GLOBALS['btp_ex_done']) ? $GLOBALS['btp_ex_done'] : 0,
            'peak_mb'  => round( memory_get_peak_usage(true) / 1048576, 1 ),
            'limit'    => ini_get('memory_limit'),
            'when'     => current_time('mysql'),
        ), false );
    } );
}

function btp_get_exchanges( $request ) {
    if ( ! function_exists('wc_get_order') ) {
        return new WP_Error('btp_no_woo', 'WooCommerce is not active on this site.', ['status' => 400]);
    }

    btp_ex_watch_fatal();
    $GLOBALS['btp_ex_current'] = 0;
    $GLOBALS['btp_ex_done']    = 0;

    /* Was 100. Each WooCommerce order pulls its items, item meta, order meta
       and addresses into memory, and a hundred of them at once is what tips a
       modest PHP memory limit over into a fatal that no try/catch can catch. */
    $limit = (int) $request->get_param('limit');
    if ( $limit <= 0 ) $limit = 40;

    global $wpdb;
    $seeding = ! get_option('btp_exchanges_seeded_hidden_v1');
    $seed    = $seeding ? btp_exchange_seed_hidden() : array();

    /* Finding the orders is the other place this can die outright — a Woo
       upgrade to high-performance order storage moves these tables. Say so
       plainly instead of returning a bare 500. */
    try {
        $order_ids = btp_exchange_order_ids( $limit );
    } catch ( Throwable $e ) {
        return new WP_Error(
            'btp_ex_lookup_failed',
            'Could not look up exchange orders: ' . $e->getMessage(),
            ['status' => 500]
        );
    }

    $out      = array();
    $problems = array();

    /* Whatever is slow about a given order, the request must still answer.
       PHP is killed at 30 seconds and a killed request returns nothing at all,
       so stop well short of that and send back however many rows were built.
       A short list is a working tab; a dead request is not. */
    $deadline = microtime( true ) + 12.0;
    $ran_out  = false;

    foreach ( $order_ids as $oid ) {
        if ( microtime( true ) > $deadline ) { $ran_out = true; break; }

        $oid = (int) $oid;
        $GLOBALS['btp_ex_current'] = $oid;

        $order = wc_get_order( $oid );
        if ( ! $order ) continue;
        if ( in_array( $order->get_status(), array('trash', 'checkout-draft'), true ) ) continue;

        if ( $seeding && ( in_array( (string) $order->get_order_number(), $seed, true )
                        || in_array( (string) $oid, $seed, true ) ) ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}bt_exchanges (order_id, status, hidden, updated_by, updated_at, created_at)
                 VALUES (%d, 'awaiting', 1, 'system', %s, %s)
                 ON DUPLICATE KEY UPDATE hidden = 1",
                $oid, current_time('mysql'), current_time('mysql')
            ) );
        }

        /* One malformed order used to take the whole tab down with a 500 —
           an order whose product was deleted, whose meta went strange, or
           which a Woo update left half-migrated. The list is far more useful
           missing one row than missing all of them, so a bad order is skipped
           and named instead of thrown. */
        try {
            $out[] = array_merge( btp_exchange_payload( $order ), btp_exchange_row( $oid ) );
        } catch ( Throwable $e ) {
            $problems[] = array(
                'order_id' => $oid,
                'number'   => (string) $order->get_order_number(),
                'error'    => $e->getMessage(),
                'where'    => basename( $e->getFile() ) . ':' . $e->getLine(),
            );
        }

        $GLOBALS['btp_ex_done']++;

        /* Woo caches every order it hands out. Over a long list that cache is
           the thing that runs the request out of memory, so let it go. */
        if ( function_exists('wp_cache_flush_group') ) {
            // no-op on most installs, but cheap where it exists
        }
        unset( $order );
    }

    if ( $seeding ) update_option('btp_exchanges_seeded_hidden_v1', '1');

    $last_fatal = get_option('btp_ex_last_fatal');

    return rest_ensure_response( array(
        'product_id' => btp_exchange_product_id(),
        'statuses'   => btp_exchange_statuses(),
        'exchanges'  => $out,
        'problems'   => $problems,
        'limit'      => $limit,
        'total'      => count( $order_ids ),
        'last_fatal' => $last_fatal ? $last_fatal : null,
        'skipped'    => array_values( array_map( 'intval', (array) get_option('btp_ex_skip') ) ),
        'ran_out'    => $ran_out,
        'query_ms'   => (int) get_option('btp_ex_query_ms'),
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

    $emailed = '';
    if ( function_exists('wc_get_order') ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            // Leave a trail, but only when the state actually moved.
            if ( $status !== $current['status'] ) {
                $labels = btp_exchange_statuses();
                $note   = 'Exchange marked ' . $labels[$status];
                if ( $status === 'shipped' && $tracking !== '' ) $note .= ' (tracking ' . $tracking . ')';
                if ( $user !== '' ) $note .= ' by ' . $user;
                $order->add_order_note( $note . ' from the BT Portal.' );

                /* Carry the move onto the Woo order itself. Skipped for hidden
                   rows (tests and mistakes) and for orders that are cancelled,
                   refunded, failed or not yet paid — none of those should be
                   marched forward by a dropdown. */
                $woo_now = $order->get_status();
                $locked  = array( 'cancelled', 'refunded', 'failed', 'pending', 'on-hold', 'trash', 'checkout-draft' );
                if ( ! $hidden && ! in_array( $woo_now, $locked, true ) ) {
                    $woo_to = btp_exchange_woo_status_for( $status );
                    if ( $woo_to !== '' && $woo_to !== $woo_now ) {
                        /* Our own email says whether it shipped or is ready to
                           collect; Woo's stock completed notice says neither.
                           Hold that one back so only the useful one goes out. */
                        if ( $woo_to === 'completed' && ! $hidden ) {
                            btp_exchange_suppress_woo_completed( $order );
                        }
                        $order->update_status( $woo_to, 'Exchange ' . $labels[$status] . ' in the BT Portal. ' );
                    }
                }
            }

            /* Tell the customer. Runs on every save, not just status changes,
               because adding a tracking number after the fact is exactly when
               the follow-up is owed. The decision function is what stops it
               repeating — hidden rows are skipped entirely, since those are
               tests and mistakes. */
            if ( ! $hidden ) {
                $kind = btp_exchange_mail_decision( $order, $status, $tracking, $current['status'] );
                if ( $kind && btp_exchange_send_status_email( $order, $kind, $tracking ) ) {
                    $emailed = $kind;
                }
            }
        }
    }

    return rest_ensure_response( array_merge(
        array('order_id' => $order_id, 'emailed' => $emailed),
        btp_exchange_row( $order_id )
    ) );
}

/**
 * What the server can say about itself without loading a single order.
 */
function btp_exchanges_diag() {
    global $wpdb;

    $count = null;
    if ( function_exists('wc_get_order') ) {
        $pid = btp_exchange_product_id();
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT oi.order_id)
               FROM {$wpdb->prefix}woocommerce_order_items oi
               JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
                 ON oim.order_item_id = oi.order_item_id
              WHERE oi.order_item_type = 'line_item'
                AND oim.meta_key = '_product_id'
                AND oim.meta_value = %s",
            (string) $pid
        ) );
    }

    return rest_ensure_response( array(
        'last_fatal'      => get_option('btp_ex_last_fatal') ?: null,
        'skipped'         => array_values( array_map( 'intval', (array) get_option('btp_ex_skip') ) ),
        'exchange_orders' => $count,
        'product_id'      => btp_exchange_product_id(),
        'woo_active'      => function_exists('wc_get_order'),
        'hpos'            => class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')
                             && method_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled')
                             ? \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
                             : null,
        'memory_limit'    => ini_get('memory_limit'),
        'max_exec'        => ini_get('max_execution_time'),
        'php'             => PHP_VERSION,
        'plugin'          => defined('BTP_VERSION') ? BTP_VERSION : '',
    ) );
}
