<?php
/**
 * BT Portal — Online Store → Schedule cards.
 *
 * A store carries three kinds of date that production needs to see on the
 * board: the day it opens, the day it closes to orders (cutoff), and the day
 * each bulk delivery goes out (ship). This file turns those dates into job
 * cards in the Online Stores department and keeps them in step with the store.
 *
 * The cards are ordinary rows in bt_jobs, so they drag, take a status and
 * carry a note like anything else on the board. Three columns tie one back to
 * the store that generated it:
 *
 *   store_id   the store it belongs to
 *   auto_kind  which date it represents: open | cutoff | ship#0 | ship#1 | ...
 *   auto_sig   md5 of the note this file last wrote
 *
 * auto_sig is what lets a note typed by hand survive. If the note on the card
 * still matches what was generated, the sync is free to rewrite it. The moment
 * someone edits it, the signature stops matching and the note is left alone
 * from then on. Title and date are always kept true to the store, since those
 * are the whole point of the card.
 */
if (!defined('ABSPATH')) exit;

const BTP_STORE_DEPT = 'Online Stores';

/** Short date for note text: 9/25, not 09/25/2026. */
function btp_ss_short_date( $ymd ) {
    if ( empty($ymd) || $ymd === '0000-00-00' ) return '';
    $ts = strtotime($ymd);
    return $ts ? date('n/j', $ts) : '';
}

/** A store's delivery dates, decoded, cleaned and in order. */
function btp_ss_delivery_dates( $store ) {
    $raw = is_object($store) ? ($store->delivery_dates ?? '') : ($store['delivery_dates'] ?? '');
    $out = json_decode( (string) $raw, true );
    if ( ! is_array($out) ) return [];
    $out = array_values(array_filter(array_map('trim', $out), function($d) {
        return $d && $d !== '0000-00-00' && strtotime($d);
    }));
    sort($out);
    return $out;
}

/**
 * Which of the three card types this store wants.
 * Stored on the store as JSON. A store that has never been saved since this
 * feature shipped has nothing recorded, and gets no cards until someone ticks
 * the boxes — no existing board grows cards on its own from an update.
 */
function btp_ss_opts( $store ) {
    $raw = is_object($store) ? ($store->schedule_opts ?? '') : ($store['schedule_opts'] ?? '');
    $o   = json_decode( (string) $raw, true );
    if ( ! is_array($o) ) return ['open'=>false, 'cutoff'=>false, 'ship'=>false];
    return [
        'open'   => ! empty($o['open']),
        'cutoff' => ! empty($o['cutoff']),
        'ship'   => ! empty($o['ship']),
    ];
}

/**
 * The set of cards this store should have right now.
 * Returns [ auto_kind => ['due_date'=>…, 'customer'=>…, 'notes'=>…] ].
 */
function btp_ss_plan( $store ) {
    $opts = btp_ss_opts($store);
    $name = trim( is_object($store) ? ($store->name ?? '') : ($store['name'] ?? '') );
    if ( $name === '' ) return [];

    $open  = is_object($store) ? ($store->open_date  ?? '') : ($store['open_date']  ?? '');
    $close = is_object($store) ? ($store->close_date ?? '') : ($store['close_date'] ?? '');
    $open  = ( $open  && $open  !== '0000-00-00' ) ? $open  : '';
    $close = ( $close && $close !== '0000-00-00' ) ? $close : '';
    $deliveries = btp_ss_delivery_dates($store);

    $closeShort = btp_ss_short_date($close);
    $plan = [];

    if ( $opts['open'] && $open ) {
        $plan['open'] = [
            'due_date' => $open,
            'customer' => $name . ' OPENS',
            'notes'    => $closeShort
                ? 'Store opens today. Closes ' . $closeShort . '.'
                : 'Store opens today.',
        ];
    }

    if ( $opts['cutoff'] && $close ) {
        $note = 'Cutoff date. Orders accepted up to midnight.';
        if ( ! empty($deliveries) ) {
            $note .= ' Deliver ' . btp_ss_short_date($deliveries[0]) . '.';
        }
        $plan['cutoff'] = [
            'due_date' => $close,
            'customer' => $name . ' CUTOFF',
            'notes'    => $note,
        ];
    }

    if ( $opts['ship'] ) {
        foreach ( $deliveries as $i => $date ) {
            $label = count($deliveries) > 1 ? ' SHIP ' . ($i + 1) : ' SHIP';
            $plan['ship#' . $i] = [
                'due_date' => $date,
                'customer' => $name . $label,
                'notes'    => $closeShort
                    ? 'SHIP orders up to midnight ' . $closeShort . '.'
                    : 'SHIP date.',
            ];
        }
    }

    return $plan;
}

/** True once the three linking columns are present on bt_jobs. */
function btp_ss_ready() {
    global $wpdb;
    static $ready = null;
    if ( $ready !== null ) return $ready;
    $cols  = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}bt_jobs", 0 );
    $ready = in_array('store_id', $cols, true)
          && in_array('auto_kind', $cols, true)
          && in_array('auto_sig', $cols, true);
    return $ready;
}

/** True once bt_stores can hold the three checkboxes. */
function btp_ss_opts_col() {
    global $wpdb;
    static $has = null;
    if ( $has !== null ) return $has;
    $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}bt_stores", 0 );
    $has  = in_array('schedule_opts', $cols, true);
    return $has;
}

/**
 * Bring a store's cards in line with the store. Adds what is missing, moves
 * what has changed date, drops what no longer applies, and leaves hand-typed
 * notes and every status alone.
 */
function btp_store_schedule_sync( $store_id ) {
    global $wpdb;
    if ( ! btp_ss_ready() ) return;

    $store_id = intval($store_id);
    $stores   = $wpdb->prefix . 'bt_stores';
    $jobs     = $wpdb->prefix . 'bt_jobs';

    $store = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $stores WHERE id=%d", $store_id) );
    if ( ! $store ) { btp_store_schedule_purge($store_id); return; }

    $plan     = btp_ss_plan($store);
    $existing = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $jobs WHERE store_id=%d AND auto_kind<>''", $store_id)
    );

    $byKind = [];
    foreach ( $existing as $row ) $byKind[ $row->auto_kind ] = $row;

    foreach ( $plan as $kind => $card ) {
        if ( isset($byKind[$kind]) ) {
            $row    = $byKind[$kind];
            $fields = [
                'due_date' => $card['due_date'],
                'customer' => $card['customer'],
            ];
            // Only rewrite the note if nobody has touched it since we wrote it.
            $untouched = ( trim((string) $row->notes) === '' )
                      || ( md5((string) $row->notes) === (string) $row->auto_sig );
            if ( $untouched ) {
                $fields['notes']    = $card['notes'];
                $fields['auto_sig'] = md5($card['notes']);
            }
            $wpdb->update($jobs, $fields, ['id' => intval($row->id)]);
        } else {
            $wpdb->insert($jobs, [
                'order_num'  => '',
                'customer'   => $card['customer'],
                'qty'        => 0,
                'location'   => '',
                'dept'       => BTP_STORE_DEPT,
                'status'     => 'None',
                'due_date'   => $card['due_date'],
                'notes'      => $card['notes'],
                'store_id'   => $store_id,
                'auto_kind'  => $kind,
                'auto_sig'   => md5($card['notes']),
            ]);
        }
    }

    // Anything the store no longer calls for goes.
    foreach ( $byKind as $kind => $row ) {
        if ( ! isset($plan[$kind]) ) {
            $wpdb->delete($jobs, ['id' => intval($row->id)]);
        }
    }
}

/** Remove every generated card for a store. Used when the store is deleted. */
function btp_store_schedule_purge( $store_id ) {
    global $wpdb;
    if ( ! btp_ss_ready() ) return;
    $jobs = $wpdb->prefix . 'bt_jobs';
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM $jobs WHERE store_id=%d AND auto_kind<>''", intval($store_id)
    ) );
}

/** Re-run every store. Used after a backup restore renumbers the stores. */
function btp_store_schedule_sync_all() {
    global $wpdb;
    if ( ! btp_ss_ready() ) return;
    $stores = $wpdb->prefix . 'bt_stores';
    $ids    = $wpdb->get_col("SELECT id FROM $stores");
    foreach ( $ids as $id ) btp_store_schedule_sync( intval($id) );
}
