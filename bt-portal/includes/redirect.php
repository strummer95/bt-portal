<?php
/**
 * BT Portal — Redirect module (ported from BT-Sched-5-Redirect v3).
 *
 * Chipply redirect portal: creates WordPress pages under /stores/ that 301-redirect
 * to any destination URL. Renders inside the employee portal via [bt_redirect_tab].
 *
 * Port notes:
 *  - PHP function names prefixed btp_ so this can coexist with the old snippet.
 *  - Every option name, post-meta key, AJAX action, nonce and shortcode tag is
 *    UNCHANGED from the snippet, so all existing redirect pages and click counts
 *    keep working with zero migration.
 *  - Copy button on the URL Path column copies the full https link.
 */

if (!defined('ABSPATH')) { exit; }

/* ============================================================
 * 0. STORES PARENT PAGE — auto-create + cache its ID
 * ============================================================ */
function btp_redirect_get_stores_parent_id() {
    $cached = get_option('bt_redirect_stores_parent_id');
    if ($cached) {
        $p = get_post($cached);
        if ($p && $p->post_type === 'page' && $p->post_status === 'publish') {
            return (int) $cached;
        }
    }

    // Try to find by slug first
    $existing = get_page_by_path('stores', OBJECT, 'page');
    if ($existing) {
        update_option('bt_redirect_stores_parent_id', $existing->ID);
        return (int) $existing->ID;
    }

    // Create it
    $id = wp_insert_post(array(
        'post_title'   => 'Stores',
        'post_name'    => 'stores',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => '<!-- Parent page for BT Redirect Portal. Do not delete. -->',
        'post_author'  => get_current_user_id(),
    ), true);

    if (is_wp_error($id) || !$id) {
        return 0;
    }

    update_option('bt_redirect_stores_parent_id', $id);
    return (int) $id;
}

/* ============================================================
 * 0b. ONE-TIME CLEANUP — strip any leftover legacy-slug meta from
 * a previous v2 migration. v2 originally kept old root URLs alive;
 * we no longer want that. Run once, then never again.
 * ============================================================ */
add_action('init', 'btp_redirect_cleanup_legacy_meta', 20);
function btp_redirect_cleanup_legacy_meta() {
    if (get_option('bt_redirect_legacy_cleaned')) { return; }

    $q = new WP_Query(array(
        'post_type'      => 'page',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array('key' => '_bt_chipply_legacy_slug', 'compare' => 'EXISTS'),
        ),
        'no_found_rows'  => true,
        'fields'         => 'ids',
    ));

    foreach ($q->posts as $pid) {
        delete_post_meta($pid, '_bt_chipply_legacy_slug');
    }

    update_option('bt_redirect_legacy_cleaned', 1);
}

/* ============================================================
 * 1a. REDIRECT LISTENER — fires when someone hits the redirect page itself
 *     (either at /stores/{slug}/ or, post-migration, the old /{slug}/ via the
 *     legacy listener below)
 * ============================================================ */
add_action('template_redirect', 'btp_redirect_chipply_listener', 1);
function btp_redirect_chipply_listener() {
    if (is_admin() || !is_singular('page')) { return; }

    $post_id = get_queried_object_id();
    if (!$post_id) { return; }

    $dest = get_post_meta($post_id, '_bt_chipply_url', true);
    if (empty($dest)) { return; }

    // Bump click counter + timestamp
    $clicks = (int) get_post_meta($post_id, '_bt_chipply_clicks', true);
    update_post_meta($post_id, '_bt_chipply_clicks', $clicks + 1);
    update_post_meta($post_id, '_bt_chipply_last_click', current_time('mysql'));

    wp_redirect(esc_url_raw($dest), 301);
    exit;
}

/**
 * Who may use the Redirect tool.
 *
 * Default is 'portal': anyone who can open the portal page, which is how every
 * other tab already works — the portal has no login of its own, and the staff
 * dropdown in the header is a name picker in localStorage, not an identity.
 * Whatever guards the portal page guards this too.
 *
 * The WordPress capabilities remain available for anyone who wants Redirect
 * held to a higher bar than the rest of the portal, but requiring one meant
 * staff needed a WordPress account on top of the portal, which is not how the
 * shop works.
 */
function btp_redirect_capability() {
    $allowed = array('portal', 'edit_posts', 'publish_posts', 'manage_options', 'read');
    $cap = (string) get_option('btp_redirect_cap', 'portal');
    if (!in_array($cap, $allowed, true)) $cap = 'portal';
    return apply_filters('btp_redirect_capability', $cap);
}

/** True if the current visitor may use the tool. */
function btp_redirect_can() {
    $cap = btp_redirect_capability();
    if ($cap === 'portal') return true;
    return current_user_can($cap);
}

function btp_redirect_denied_html() {
    return '<div style="padding:30px;text-align:center;color:#5a6380;font-family:Barlow,sans-serif;font-size:16px;line-height:1.6;max-width:520px;margin:0 auto;">'
         . 'The Redirect tool is restricted to certain WordPress accounts on this site. '
         . 'Ask Dillon to change it under <strong>BT Portal &rarr; Redirect access</strong>.'
         . '</div>';
}

/* ============================================================
 * 2. AJAX HANDLERS
 * ============================================================ */
add_action('wp_ajax_bt_redirect_create', 'btp_redirect_ajax_create');
add_action('wp_ajax_nopriv_bt_redirect_create', 'btp_redirect_ajax_create');
function btp_redirect_ajax_create() {
    if (!btp_redirect_can()) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }
    check_ajax_referer('bt_redirect_nonce', 'nonce');

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    $url   = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';

    if (empty($title) || empty($url)) {
        wp_send_json_error(array('message' => 'Both Page Name and Destination URL are required.'));
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        wp_send_json_error(array('message' => 'Destination URL is not valid.'));
    }

    $parent_id = btp_redirect_get_stores_parent_id();
    if (!$parent_id) {
        wp_send_json_error(array('message' => 'Could not find or create the Stores parent page.'));
    }

    $slug = sanitize_title($title);

    // Check collision under /stores/
    $existing = get_page_by_path('stores/' . $slug, OBJECT, 'page');
    if ($existing) {
        wp_send_json_error(array('message' => 'A page already exists at /stores/' . esc_html($slug) . '/. Pick a different name.'));
    }

    $post_id = wp_insert_post(array(
        'post_title'   => $title,
        'post_name'    => $slug,
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_parent'  => $parent_id,
        'post_content' => '<!-- Redirect page managed by BT-Sched-5-Redirect. Do not edit. -->',
        'post_author'  => get_current_user_id(),
    ), true);

    if (is_wp_error($post_id)) {
        wp_send_json_error(array('message' => 'Could not create page: ' . $post_id->get_error_message()));
    }

    update_post_meta($post_id, '_bt_chipply_url', $url);
    update_post_meta($post_id, '_bt_chipply_clicks', 0);
    update_post_meta($post_id, '_bt_redirect_created_by', get_current_user_id());

    wp_send_json_success(array(
        'id'       => $post_id,
        'title'    => $title,
        'slug'     => $slug,
        'url'      => $url,
        'view_url' => get_permalink($post_id),
        'clicks'   => 0,
        'last'     => '',
    ));
}

add_action('wp_ajax_bt_redirect_update', 'btp_redirect_ajax_update');
add_action('wp_ajax_nopriv_bt_redirect_update', 'btp_redirect_ajax_update');
function btp_redirect_ajax_update() {
    if (!btp_redirect_can()) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }
    check_ajax_referer('bt_redirect_nonce', 'nonce');

    $id  = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';

    if (!$id || empty($url)) {
        wp_send_json_error(array('message' => 'Missing id or url.'));
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        wp_send_json_error(array('message' => 'Destination URL is not valid.'));
    }

    $post = get_post($id);
    if (!$post || $post->post_type !== 'page') {
        wp_send_json_error(array('message' => 'Page not found.'));
    }
    if (!get_post_meta($id, '_bt_chipply_url', true)) {
        wp_send_json_error(array('message' => 'That page is not a redirect page.'));
    }

    update_post_meta($id, '_bt_chipply_url', $url);

    wp_send_json_success(array('id' => $id, 'url' => $url));
}

add_action('wp_ajax_bt_redirect_delete', 'btp_redirect_ajax_delete');
add_action('wp_ajax_nopriv_bt_redirect_delete', 'btp_redirect_ajax_delete');
function btp_redirect_ajax_delete() {
    if (!btp_redirect_can()) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }
    check_ajax_referer('bt_redirect_nonce', 'nonce');

    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (!$id) {
        wp_send_json_error(array('message' => 'Missing id.'));
    }

    $post = get_post($id);
    if (!$post || $post->post_type !== 'page') {
        wp_send_json_error(array('message' => 'Page not found.'));
    }
    if (!get_post_meta($id, '_bt_chipply_url', true)) {
        wp_send_json_error(array('message' => 'That page is not a redirect page.'));
    }

    $deleted = wp_delete_post($id, true);
    if (!$deleted) {
        wp_send_json_error(array('message' => 'Could not delete page.'));
    }

    wp_send_json_success(array('id' => $id));
}

/* ============================================================
 * 3. HELPER: list all redirect pages
 * ============================================================ */
function btp_redirect_get_all() {
    $q = new WP_Query(array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array('key' => '_bt_chipply_url', 'compare' => 'EXISTS'),
        ),
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ));

    $out = array();
    foreach ($q->posts as $p) {
        $out[] = array(
            'id'          => $p->ID,
            'title'       => $p->post_title,
            'slug'        => $p->post_name,
            'url'         => get_post_meta($p->ID, '_bt_chipply_url', true),
            'view_url'    => get_permalink($p->ID),
            'clicks'      => (int) get_post_meta($p->ID, '_bt_chipply_clicks', true),
            'last'        => get_post_meta($p->ID, '_bt_chipply_last_click', true) ?: '',
            'created'     => get_the_date('M j, Y', $p),
        );
    }
    wp_reset_postdata();
    return $out;
}

/* ============================================================
 * 4. SHORTCODE [bt_redirect_tab]
 * ============================================================ */
add_shortcode('bt_redirect_tab', 'btp_redirect_tab_shortcode');
function btp_redirect_tab_shortcode() {
    if (!btp_redirect_can()) {
        return btp_redirect_denied_html();
    }

    // Make sure the parent page exists when the tab is opened
    btp_redirect_get_stores_parent_id();

    $nonce     = wp_create_nonce('bt_redirect_nonce');
    $ajax_url  = admin_url('admin-ajax.php');
    $rows      = btp_redirect_get_all();
    $rows_json = wp_json_encode($rows);
    $stores_base = trailingslashit(home_url('/stores/'));

    ob_start();
    ?>
    <style>
    #bt-schedule-app #btrp-panel { padding: 32px 40px 40px !important; font-family: 'Barlow', sans-serif !important; max-width: 1400px !important; margin: 0 auto !important; }
    #bt-schedule-app #btrp-panel * { box-sizing: border-box; }
    #bt-schedule-app #btrp-panel .btrp-card { background: #fff !important; border: 1.5px solid #e8eaf0 !important; border-radius: 10px !important; padding: 24px 28px !important; margin: 0 0 22px 0 !important; box-shadow: 0 1px 3px rgba(15,18,64,.04) !important; }
    #bt-schedule-app #btrp-panel .btrp-card-title { font-family: 'Oswald', sans-serif !important; font-size: 17px !important; font-weight: 700 !important; color: #0f1240 !important; letter-spacing: .07em !important; text-transform: uppercase !important; margin: 0 0 18px 0 !important; padding: 0 0 14px 0 !important; display: flex !important; align-items: center !important; gap: 8px !important; border-bottom: 1.5px solid #eef0f5 !important; }
    #bt-schedule-app #btrp-panel .btrp-card-title span { color: #e91e8c !important; }
    #bt-schedule-app #btrp-panel .btrp-form-stack { display: flex !important; flex-direction: column !important; gap: 18px !important; max-width: 720px !important; }
    #bt-schedule-app #btrp-panel .btrp-submit-row { display: flex !important; justify-content: flex-start !important; margin-top: 4px !important; }
    #bt-schedule-app #btrp-panel .btrp-field { display: flex !important; flex-direction: column !important; gap: 6px !important; min-width: 0 !important; }
    #bt-schedule-app #btrp-panel .btrp-field label { font-family: 'Barlow Condensed', sans-serif !important; font-size: 13px !important; font-weight: 700 !important; color: #5a6380 !important; text-transform: uppercase !important; letter-spacing: .1em !important; margin: 0 !important; padding: 0 !important; }
    #bt-schedule-app #btrp-panel .btrp-field input { padding: 11px 14px !important; border: 1.5px solid #e8eaf0 !important; border-radius: 6px !important; font-size: 15px !important; font-family: 'Barlow', sans-serif !important; color: #0f1240 !important; outline: none !important; transition: border-color .15s !important; width: 100% !important; margin: 0 !important; }
    #bt-schedule-app #btrp-panel .btrp-field input:focus { border-color: #1a1f5e !important; }
    #bt-schedule-app #btrp-panel .btrp-name-line { display: flex !important; align-items: center !important; gap: 6px !important; }
    #bt-schedule-app #btrp-panel .btrp-name-prefix { font-family: monospace !important; font-size: 14px !important; color: #5a6380 !important; white-space: nowrap !important; flex-shrink: 0 !important; }
    #bt-schedule-app #btrp-panel .btrp-name-line input { font-family: monospace !important; flex: 1 !important; min-width: 0 !important; max-width: 360px !important; }
    #bt-schedule-app #btrp-panel .btrp-btn { background: #e91e8c !important; color: #fff !important; border: none !important; padding: 12px 22px !important; border-radius: 6px !important; font-family: 'Oswald', sans-serif !important; font-size: 14px !important; font-weight: 600 !important; letter-spacing: .07em !important; text-transform: uppercase !important; cursor: pointer !important; transition: background .15s !important; white-space: nowrap !important; margin: 0 !important; }
    #bt-schedule-app #btrp-panel .btrp-btn:hover { background: #cc2a96 !important; }
    #bt-schedule-app #btrp-panel .btrp-btn:disabled { opacity: .55 !important; cursor: not-allowed !important; }
    #bt-schedule-app #btrp-panel .btrp-btn-sm { padding: 7px 14px !important; font-size: 12px !important; letter-spacing: .06em !important; }
    #bt-schedule-app #btrp-panel .btrp-btn-ghost { background: #fff !important; color: #0f1240 !important; border: 1.5px solid #d4d7e0 !important; }
    #bt-schedule-app #btrp-panel .btrp-btn-ghost:hover { background: #0f1240 !important; color: #fff !important; border-color: #0f1240 !important; }
    #bt-schedule-app #btrp-panel .btrp-btn-danger { background: #d32f2f !important; }
    #bt-schedule-app #btrp-panel .btrp-btn-danger:hover { background: #b71c1c !important; }
    #bt-schedule-app #btrp-panel .btrp-url-hint { font-size: 12px !important; color: #7a8299 !important; margin-top: 12px !important; font-family: monospace !important; }
    #bt-schedule-app #btrp-panel .btrp-url-hint strong { color: #0f1240 !important; font-weight: 700 !important; }
    #bt-schedule-app #btrp-panel .btrp-msg { margin-top: 14px !important; padding: 11px 14px !important; border-radius: 6px !important; font-size: 14px !important; display: none; }
    #bt-schedule-app #btrp-panel .btrp-msg.ok { background: #e6f7ed !important; color: #146c43 !important; border: 1.5px solid #b8e3c8 !important; display: block; }
    #bt-schedule-app #btrp-panel .btrp-msg.err { background: #fdecec !important; color: #a52929 !important; border: 1.5px solid #f5c5c5 !important; display: block; }
    #bt-schedule-app #btrp-panel .btrp-table-wrap { overflow-x: auto !important; margin: 0 -4px !important; padding: 0 4px !important; }
    #bt-schedule-app #btrp-panel .btrp-table { width: 100% !important; border-collapse: separate !important; border-spacing: 0 !important; font-size: 14px !important; margin: 0 !important; }
    #bt-schedule-app #btrp-panel .btrp-table thead th { background: #0f1240 !important; color: #fff !important; text-align: left !important; padding: 12px 16px !important; font-family: 'Barlow Condensed', sans-serif !important; font-size: 13px !important; font-weight: 700 !important; letter-spacing: .08em !important; text-transform: uppercase !important; margin: 0 !important; }
    #bt-schedule-app #btrp-panel .btrp-table thead th:first-child { border-radius: 6px 0 0 0 !important; }
    #bt-schedule-app #btrp-panel .btrp-table thead th:last-child { border-radius: 0 6px 0 0 !important; }
    #bt-schedule-app #btrp-panel .btrp-table tbody td { padding: 16px !important; border-bottom: 1px solid #eef0f5 !important; vertical-align: middle !important; color: #0f1240 !important; margin: 0 !important; }
    #bt-schedule-app #btrp-panel .btrp-table tbody tr:hover td { background: #fafbfd !important; }
    #bt-schedule-app #btrp-panel .btrp-table tbody tr:last-child td { border-bottom: none !important; }
    #bt-schedule-app #btrp-panel .btrp-name { font-weight: 700 !important; font-size: 15px !important; color: #0f1240 !important; }
    #bt-schedule-app #btrp-panel .btrp-slug { font-family: monospace !important; background: #f4f5f9 !important; padding: 5px 10px !important; border-radius: 5px !important; font-size: 13px !important; color: #0f1240 !important; text-decoration: none !important; display: inline-block !important; transition: all .15s !important; }
    #bt-schedule-app #btrp-panel .btrp-slug:hover { background: #e91e8c !important; color: #fff !important; }
    #bt-schedule-app #btrp-panel .btrp-path-cell { display: inline-flex !important; align-items: center !important; gap: 8px !important; }
    #bt-schedule-app #btrp-panel .btrp-copy-btn { display: inline-flex !important; align-items: center !important; justify-content: center !important; width: 28px !important; height: 26px !important; padding: 0 !important; background: #f4f5f9 !important; border: 1.5px solid #e8eaf0 !important; border-radius: 5px !important; color: #5a6380 !important; cursor: pointer !important; flex-shrink: 0 !important; transition: all .15s !important; }
    #bt-schedule-app #btrp-panel .btrp-copy-btn:hover { background: #e91e8c !important; border-color: #e91e8c !important; color: #fff !important; }
    #bt-schedule-app #btrp-panel .btrp-copy-btn.copied { background: #e6f7ed !important; border-color: #b8e3c8 !important; color: #146c43 !important; }
    #bt-schedule-app #btrp-panel .btrp-copy-btn svg { pointer-events: none !important; display: block !important; }
    #bt-schedule-app #btrp-panel .btrp-legacy { font-family: monospace !important; font-size: 11px !important; color: #9ca3b8 !important; margin-top: 5px !important; display: block !important; line-height: 1.4 !important; }
    #bt-schedule-app #btrp-panel .btrp-url-edit { width: 100% !important; max-width: 380px !important; padding: 7px 10px !important; border: 1.5px solid transparent !important; border-radius: 5px !important; font-size: 13px !important; font-family: monospace !important; color: #5a6380 !important; background: transparent !important; outline: none !important; box-shadow: none !important; cursor: default !important; }
    #bt-schedule-app #btrp-panel .btrp-url-edit:read-only { background: transparent !important; }
    #bt-schedule-app #btrp-panel .btrp-url-edit.editing { border-color: #1a1f5e !important; background: #fff !important; color: #0f1240 !important; cursor: text !important; }
    #bt-schedule-app #btrp-panel .btrp-pill { display: inline-block !important; background: #0f1240 !important; color: #fff !important; padding: 4px 12px !important; border-radius: 12px !important; font-family: 'Barlow Condensed', sans-serif !important; font-size: 13px !important; font-weight: 700 !important; min-width: 36px !important; text-align: center !important; letter-spacing: .04em !important; }
    #bt-schedule-app #btrp-panel .btrp-pill.zero { background: #c0c4d0 !important; }
    #bt-schedule-app #btrp-panel .btrp-last { font-size: 13px !important; color: #7a8299 !important; white-space: nowrap !important; }
    #bt-schedule-app #btrp-panel .btrp-actions { display: flex !important; gap: 8px !important; flex-wrap: wrap !important; justify-content: flex-end !important; }
    #bt-schedule-app #btrp-panel .btrp-empty { text-align: center !important; padding: 40px 20px !important; color: #9ca3b8 !important; font-style: italic !important; font-size: 14px !important; }
    #bt-schedule-app #btrp-panel .btrp-count { font-family: 'Barlow Condensed', sans-serif !important; font-size: 13px !important; color: #9ca3b8 !important; font-weight: 600 !important; letter-spacing: .06em !important; margin-left: auto !important; text-transform: none !important; }
    @media (max-width: 900px) {
        #bt-schedule-app #btrp-panel { padding: 20px 16px 24px !important; }
        #bt-schedule-app #btrp-panel .btrp-card { padding: 18px 18px !important; }
    }
    @media (max-width: 720px) {
        #bt-schedule-app #btrp-panel .btrp-table thead { display: none !important; }
        #bt-schedule-app #btrp-panel .btrp-table tbody td { display: block !important; border: none !important; padding: 6px 0 !important; }
        #bt-schedule-app #btrp-panel .btrp-table tbody tr { display: block !important; padding: 16px 0 !important; border-bottom: 1px solid #e8eaf0 !important; }
        #bt-schedule-app #btrp-panel .btrp-table tbody td::before { content: attr(data-label); display: block; font-family: 'Barlow Condensed', sans-serif; font-size: 11px; font-weight: 700; color: #5a6380; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 4px; }
        #bt-schedule-app #btrp-panel .btrp-url { max-width: 100% !important; }
        #bt-schedule-app #btrp-panel .btrp-actions { justify-content: flex-start !important; }
    }
    </style>

    <div id="btrp-panel">

        <div class="btrp-card">
            <div class="btrp-card-title">CREATE CUSTOM <span>REDIRECT</span></div>
            <div style="font-size:13px;color:#5a6380;margin-bottom:18px;line-height:1.5;">
                Creates a short BoomerTs.com link that automatically forwards visitors to any destination URL (Chipply store, OrderMyGear, OMS, anywhere).<br>
                Use it for easy-to-remember team and customer links.
            </div>
            <div class="btrp-form-stack">
                <div class="btrp-field btrp-field-pagename">
                    <label for="btrpTitle">Page Name</label>
                    <div class="btrp-name-line">
                        <span class="btrp-name-prefix">www.BoomerTs.com/stores/</span>
                        <input type="text" id="btrpTitle" placeholder="customname" maxlength="120" />
                    </div>
                </div>
                <div class="btrp-field">
                    <label for="btrpUrl">Destination URL</label>
                    <input type="url" id="btrpUrl" placeholder="https://..." />
                </div>
                <div class="btrp-submit-row">
                    <button type="button" class="btrp-btn" id="btrpCreate">+ Create Redirect</button>
                </div>
            </div>
            <div class="btrp-msg" id="btrpMsg"></div>
        </div>

        <div class="btrp-card">
            <div class="btrp-card-title">
                ACTIVE <span>REDIRECTS</span>
                <span class="btrp-count"><span id="btrpCount">0</span> total</span>
            </div>
            <div class="btrp-table-wrap">
                <table class="btrp-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>URL Path</th>
                            <th>Destination</th>
                            <th style="text-align:center;">Clicks</th>
                            <th>Last Click</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="btrpBody"></tbody>
                </table>
            </div>
        </div>

    </div>

    <script>
    (function(){
        var AJAX  = <?php echo wp_json_encode($ajax_url); ?>;
        var NONCE = <?php echo wp_json_encode($nonce); ?>;
        var HOME  = <?php echo wp_json_encode(home_url('/')); ?>;
        var rows  = <?php echo $rows_json ? $rows_json : '[]'; ?>;

        var COPY_ICON  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
        var CHECK_ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';

        var elBody    = document.getElementById('btrpBody');
        var elCount   = document.getElementById('btrpCount');
        var elTitle   = document.getElementById('btrpTitle');
        var elUrl     = document.getElementById('btrpUrl');
        var elCreate  = document.getElementById('btrpCreate');
        var elMsg     = document.getElementById('btrpMsg');

        function escHtml(s) {
            if (s === null || s === undefined) { return ''; }
            return String(s)
                .replace(/&/g,'&amp;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;')
                .replace(/'/g,'&#39;');
        }

        function showMsg(text, ok) {
            elMsg.textContent = text;
            elMsg.className = 'btrp-msg ' + (ok ? 'ok' : 'err');
            if (ok) { setTimeout(function(){ elMsg.className = 'btrp-msg'; }, 4000); }
        }

        function copyLink(btn) {
            var url = btn.getAttribute('data-copy');
            if (!url) { return; }

            function flash() {
                btn.classList.add('copied');
                btn.innerHTML = CHECK_ICON;
                btn.setAttribute('title', 'Copied!');
                if (btn.btrpTimer) { clearTimeout(btn.btrpTimer); }
                btn.btrpTimer = setTimeout(function(){
                    btn.classList.remove('copied');
                    btn.innerHTML = COPY_ICON;
                    btn.setAttribute('title', 'Copy full link');
                }, 1500);
            }

            function legacy() {
                var ta = document.createElement('textarea');
                ta.value = url;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.top = '0';
                ta.style.left = '-9999px';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                ta.setSelectionRange(0, url.length);
                var ok = false;
                try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
                document.body.removeChild(ta);
                if (ok) { flash(); } else { window.prompt('Copy this link:', url); }
            }

            if (navigator.clipboard) {
                if (window.isSecureContext) {
                    navigator.clipboard.writeText(url).then(flash, legacy);
                    return;
                }
            }
            legacy();
        }

        function formatLast(s) {
            if (!s) { return '\u2014'; }
            var d = new Date(s.replace(' ', 'T'));
            if (isNaN(d.getTime())) { return s; }
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            var hh = d.getHours();
            var ap = hh >= 12 ? 'pm' : 'am';
            hh = hh % 12; if (hh === 0) { hh = 12; }
            var mm = d.getMinutes(); if (mm < 10) { mm = '0' + mm; }
            return months[d.getMonth()] + ' ' + d.getDate() + ', ' + hh + ':' + mm + ap;
        }

        function render() {
            elCount.textContent = rows.length;
            if (rows.length === 0) {
                elBody.innerHTML = '<tr><td colspan="6" class="btrp-empty">No redirects yet. Create one above.</td></tr>';
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i];
                var clicksCls = r.clicks > 0 ? 'btrp-pill' : 'btrp-pill zero';
                var pathDisplay = '/stores/' + escHtml(r.slug) + '/';
                html += '<tr data-id="' + r.id + '">';
                html += '<td data-label="Name"><span class="btrp-name">' + escHtml(r.title) + '</span></td>';
                html += '<td data-label="URL Path"><span class="btrp-path-cell">';
                html +=   '<a href="' + escHtml(r.view_url) + '" target="_blank" rel="noopener" class="btrp-slug">' + pathDisplay + '</a>';
                html +=   '<button type="button" class="btrp-copy-btn" title="Copy full link" data-copy="' + escHtml(r.view_url) + '">' + COPY_ICON + '</button>';
                html += '</span></td>';
                html += '<td data-label="Destination">';
                html +=   '<input type="url" class="btrp-url-edit" value="' + escHtml(r.url) + '" readonly />';
                html += '</td>';
                html += '<td data-label="Clicks" style="text-align:center;"><span class="' + clicksCls + '">' + r.clicks + '</span></td>';
                html += '<td data-label="Last Click"><span class="btrp-last">' + escHtml(formatLast(r.last)) + '</span></td>';
                html += '<td data-label="Actions"><div class="btrp-actions">';
                html +=   '<button type="button" class="btrp-btn btrp-btn-sm btrp-btn-ghost btrp-edit">Edit URL</button>';
                html +=   '<button type="button" class="btrp-btn btrp-btn-sm btrp-edit-save" style="display:none;">Save</button>';
                html +=   '<button type="button" class="btrp-btn btrp-btn-sm btrp-btn-ghost btrp-edit-cancel" style="display:none;">Cancel</button>';
                html +=   '<button type="button" class="btrp-btn btrp-btn-sm btrp-btn-danger btrp-delete">Delete</button>';
                html += '</div></td>';
                html += '</tr>';
            }
            elBody.innerHTML = html;
        }

        function apiCall(action, data, cb) {
            var body = 'action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(NONCE);
            for (var k in data) {
                if (Object.prototype.hasOwnProperty.call(data, k)) {
                    body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
                }
            }
            fetch(AJAX, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            }).then(function(r){ return r.json(); })
              .then(function(j){ cb(j); })
              .catch(function(){ cb({ success: false, data: { message: 'Network error.' } }); });
        }

        elCreate.addEventListener('click', function(){
            var t = elTitle.value.trim();
            var u = elUrl.value.trim();
            if (!t || !u) { showMsg('Please fill in both fields.', false); return; }
            elCreate.disabled = true;
            apiCall('bt_redirect_create', { title: t, url: u }, function(res){
                elCreate.disabled = false;
                if (res && res.success) {
                    rows.unshift({
                        id: res.data.id,
                        title: res.data.title,
                        slug: res.data.slug,
                        url: res.data.url,
                        view_url: res.data.view_url,
                        legacy_slug: '',
                        clicks: 0,
                        last: ''
                    });
                    elTitle.value = '';
                    elUrl.value = '';
                    render();
                    showMsg('Redirect created: ' + res.data.view_url, true);
                } else {
                    var m = (res && res.data && res.data.message) ? res.data.message : 'Could not create redirect.';
                    showMsg(m, false);
                }
            });
        });

        elBody.addEventListener('click', function(e){
            var btn = e.target;
            if (!btn || !btn.closest) { return; }
            var tr = btn.closest('tr');
            if (!tr) { return; }
            var id = tr.getAttribute('data-id');

            var copyBtn = btn.closest('.btrp-copy-btn');
            if (copyBtn) { copyLink(copyBtn); return; }

            if (btn.classList.contains('btrp-edit')) {
                var input = tr.querySelector('.btrp-url-edit');
                input.dataset.original = input.value;
                input.readOnly = false;
                input.classList.add('editing');
                input.focus();
                input.select();
                tr.querySelector('.btrp-edit').style.display = 'none';
                tr.querySelector('.btrp-edit-save').style.display = 'inline-block';
                tr.querySelector('.btrp-edit-cancel').style.display = 'inline-block';
                return;
            }

            if (btn.classList.contains('btrp-edit-cancel')) {
                var input = tr.querySelector('.btrp-url-edit');
                input.value = input.dataset.original || '';
                input.readOnly = true;
                input.classList.remove('editing');
                tr.querySelector('.btrp-edit').style.display = 'inline-block';
                tr.querySelector('.btrp-edit-save').style.display = 'none';
                tr.querySelector('.btrp-edit-cancel').style.display = 'none';
                return;
            }

            if (btn.classList.contains('btrp-edit-save')) {
                var input = tr.querySelector('.btrp-url-edit');
                var newUrl = input.value.trim();
                if (!newUrl) { showMsg('Destination URL cannot be empty.', false); return; }
                btn.disabled = true;
                apiCall('bt_redirect_update', { id: id, url: newUrl }, function(res){
                    btn.disabled = false;
                    if (res && res.success) {
                        for (var i = 0; i < rows.length; i++) {
                            if (String(rows[i].id) === String(id)) { rows[i].url = res.data.url; break; }
                        }
                        render();
                        showMsg('Destination updated.', true);
                    } else {
                        var m = (res && res.data && res.data.message) ? res.data.message : 'Could not update.';
                        showMsg(m, false);
                    }
                });
                return;
            }

            if (btn.classList.contains('btrp-delete')) {
                if (!confirm('Delete this redirect page? This cannot be undone.')) { return; }
                btn.disabled = true;
                apiCall('bt_redirect_delete', { id: id }, function(res){
                    if (res && res.success) {
                        rows = rows.filter(function(r){ return String(r.id) !== String(id); });
                        render();
                        showMsg('Deleted.', true);
                    } else {
                        btn.disabled = false;
                        var m = (res && res.data && res.data.message) ? res.data.message : 'Could not delete.';
                        showMsg(m, false);
                    }
                });
                return;
            }
        });

        render();
    })();
    </script>
    <?php
    return ob_get_clean();
}
