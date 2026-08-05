<?php
/**
 * BT Portal — one URL per tab.
 *
 * The portal is a single page with six panes, so every view shared the same
 * address: you could not send someone to Exchanges, and a refresh always
 * dumped you back on Schedule.
 *
 * /employees/exchanges is a rewrite onto the same page with a query var, so
 * there is still exactly one page in WordPress and no new templates.
 *
 * Three things have to line up or this silently does nothing:
 *   1. the rewrite rule, built from whichever page actually holds the
 *      shortcode rather than a hardcoded "employees"
 *   2. the query var, or WordPress drops it before the page renders
 *   3. redirect_canonical, which otherwise "helpfully" bounces
 *      /employees/exchanges straight back to /employees/
 */
if (!defined('ABSPATH')) exit;

/** URL slug => tab id used by btSwitchTab(). */
function btp_tab_map() {
    return apply_filters( 'btp_tab_map', array(
        'schedule'      => 'schedule',
        'online-stores' => 'stores',
        'stores'        => 'stores',      // convenience alias
        'quote'         => 'quote',
        'redirect'      => 'redirect',
        'contacts'      => 'contacts',
        'exchanges'     => 'exchanges',
    ) );
}

/** Tab id => canonical slug (the one written into the address bar). */
function btp_tab_slugs() {
    return array(
        'schedule'  => 'schedule',
        'stores'    => 'online-stores',
        'quote'     => 'quote',
        'redirect'  => 'redirect',
        'contacts'  => 'contacts',
        'exchanges' => 'exchanges',
    );
}

/**
 * The page holding [bt_schedule]. Recorded by the shortcode the first time it
 * renders rather than hardcoded, so moving or renaming the portal page keeps
 * working — the slug is 'employees' today but nothing here depends on that.
 */
function btp_portal_page_id() {
    return (int) get_option('btp_portal_page_id', 0);
}

add_action('init', function() {
    $page_id = btp_portal_page_id();
    if ( ! $page_id ) return;

    $uri = get_page_uri( $page_id );
    if ( ! $uri ) return;

    add_rewrite_rule(
        '^' . preg_quote( $uri, '/' ) . '/([a-z0-9-]+)/?$',
        'index.php?page_id=' . $page_id . '&btp_tab=$matches[1]',
        'top'
    );

    // Flush only when the page or the plugin version changed — never per load.
    $stamp = $page_id . '|' . ( defined('BTP_VERSION') ? BTP_VERSION : '0' );
    if ( get_option('btp_rewrite_stamp') !== $stamp ) {
        flush_rewrite_rules( false );
        update_option('btp_rewrite_stamp', $stamp);
    }
}, 20);

add_filter('query_vars', function( $vars ) {
    $vars[] = 'btp_tab';
    return $vars;
});

/**
 * Without this, WordPress sees /employees/exchanges resolving to the
 * /employees/ page and 301s to the "correct" URL, taking the tab with it.
 */
add_filter('redirect_canonical', function( $redirect ) {
    return get_query_var('btp_tab') ? false : $redirect;
});

/** Which tab this request asked for, as a tab id. Empty string if none. */
function btp_requested_tab() {
    $map  = btp_tab_map();
    $slug = get_query_var('btp_tab');

    // ?tab=exchanges also works, and is the fallback when permalinks are plain
    // or the rewrite rules have not been flushed yet.
    if ( ! $slug && isset($_GET['tab']) ) $slug = sanitize_title( wp_unslash($_GET['tab']) );

    $slug = sanitize_title( (string) $slug );
    return isset($map[$slug]) ? $map[$slug] : '';
}

/**
 * Pretty URLs need a permalink structure; on a plain-permalinks site the JS
 * falls back to ?tab= so deep links still work, just uglier.
 */
function btp_pretty_urls() {
    return (bool) get_option('permalink_structure') && btp_portal_page_id();
}

/** Base URL of the portal page, trailing-slashed. */
function btp_portal_base_url() {
    $id = btp_portal_page_id();
    return $id ? trailingslashit( get_permalink( $id ) ) : trailingslashit( home_url('/') );
}

/**
 * Called by the shortcode: remembers which page it lives on, and clears the
 * rewrite stamp when that changes so the rules rebuild on the next request.
 */
function btp_note_portal_page() {
    $id = get_the_ID();
    if ( ! $id || is_admin() ) return;
    if ( (int) get_option('btp_portal_page_id', 0) === (int) $id ) return;

    update_option('btp_portal_page_id', (int) $id);
    delete_option('btp_rewrite_stamp');
}
