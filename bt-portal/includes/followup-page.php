<?php
/**
 * BT Portal - Follow Up: endpoints, admin screen, and the [bt_follow_up] page.
 *
 * The rules live in followup.php and the email in followup-mail.php. This file
 * is the way in: the REST routes the page calls, the WordPress admin screen for
 * the settings and the sync, and the page itself.
 *
 * Layout note, because it is a requirement and not a preference: one card per
 * customer, stacked, never a wide table. The shop reads this on a phone and on
 * a shop-floor monitor, and anything that scrolls sideways does not get read.
 */
if (!defined('ABSPATH')) exit;

/* =========================================================================
   REST
   ========================================================================= */

add_action('rest_api_init', function () {
    $ns   = 'boomerts/v1';
    $perm = 'btp_rest_can_access';

    register_rest_route($ns, '/followup/cards', array(
        'methods' => 'GET', 'permission_callback' => $perm, 'callback' => 'btp_rest_fu_cards',
    ));
    register_rest_route($ns, '/followup/cards/status', array(
        'methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'btp_rest_fu_status',
    ));
    register_rest_route($ns, '/followup/cards/notes', array(
        'methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'btp_rest_fu_notes',
    ));
    register_rest_route($ns, '/followup/preview', array(
        'methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'btp_rest_fu_preview',
    ));
    register_rest_route($ns, '/followup/send', array(
        'methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'btp_rest_fu_send',
    ));
    register_rest_route($ns, '/followup/orders/(?P<visual_id>[^/]+)/event', array(
        'methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'btp_rest_fu_rename_event',
    ));
    register_rest_route($ns, '/followup/sync', array(
        array('methods' => 'GET',  'permission_callback' => $perm, 'callback' => 'btp_rest_fu_sync_state'),
        array('methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'btp_rest_fu_sync_run'),
    ));
});

/** Cards, each with its saved tracking state folded in. */
function btp_rest_fu_cards() {
    $cards = btp_fu_build_cards();
    $out   = array();

    foreach ($cards as $card) {
        $state = btp_fu_card_state($card['contact_email'], $card['cycle_year']);
        $card['status']       = $card['reorder'] ? 'reordered' : $state['status'];
        $card['emailed_at']   = $state['emailed_at'];
        $card['emailed_by']   = $state['emailed_by'];
        $card['snooze_until'] = $state['snooze_until'];
        $card['notes']        = (string) $state['notes'];
        $card['log']          = isset($state['log']) ? $state['log'] : array();

        /* Snoozed past its date is just due again. */
        if ($card['status'] === 'snoozed' && $card['snooze_until'] && $card['snooze_until'] < btp_fu_today()) {
            $card['status'] = 'to_contact';
        }
        $out[] = $card;
    }

    return new WP_REST_Response(array(
        'cards'      => $out,
        'today'      => btp_fu_today(),
        'window'     => btp_fu_window_days(),
        'mail_ready' => btp_fu_mail_ready(),
        'sync'       => btp_fu_sync_public_state(),
    ), 200);
}

function btp_fu_sync_public_state() {
    $s = btp_fu_sync_state();
    global $wpdb;
    $s['orders'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . btp_fu_table('orders'));
    $s['enabled'] = (bool) get_option('btp_fu_sync_enabled', 0);
    return $s;
}

function btp_fu_req_card($req) {
    $email = btp_fu_norm($req->get_param('contact_email'));
    $year  = (int) $req->get_param('cycle_year');
    if ($email === '' || $year < 2000) {
        return new WP_Error('btp_fu_bad_card', 'Which card?', array('status' => 400));
    }
    return array($email, $year);
}

function btp_rest_fu_status($req) {
    $card = btp_fu_req_card($req);
    if (is_wp_error($card)) return $card;
    list($email, $year) = $card;

    $status = (string) $req->get_param('status');
    if (!in_array($status, btp_fu_statuses(), true)) {
        return new WP_Error('btp_fu_bad_status', 'Not a status this card can have.', array('status' => 400));
    }

    $snooze = (string) $req->get_param('snooze_until');
    if ($status === 'snoozed') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $snooze) || $snooze <= btp_fu_today()) {
            return new WP_Error('btp_fu_bad_snooze', 'Snoozing needs a date in the future.', array('status' => 400));
        }
    } else {
        $snooze = null;
    }

    global $wpdb;
    $id = btp_fu_card_id($email, $year);
    $wpdb->update(btp_fu_table('cards'), array(
        'status'       => $status,
        'snooze_until' => $snooze,
        'updated_at'   => current_time('mysql'),
    ), array('id' => $id));

    btp_fu_log($id, 'status', $status . ($snooze ? ' until ' . $snooze : ''));
    return new WP_REST_Response(btp_fu_card_state($email, $year), 200);
}

function btp_rest_fu_notes($req) {
    $card = btp_fu_req_card($req);
    if (is_wp_error($card)) return $card;
    list($email, $year) = $card;

    global $wpdb;
    $id    = btp_fu_card_id($email, $year);
    $notes = (string) $req->get_param('notes');
    $wpdb->update(btp_fu_table('cards'), array(
        'notes' => $notes, 'updated_at' => current_time('mysql'),
    ), array('id' => $id));

    btp_fu_log($id, 'note', mb_substr(trim($notes), 0, 200));
    return new WP_REST_Response(btp_fu_card_state($email, $year), 200);
}

/** The card as the page knows it, for rendering or sending. */
function btp_fu_find_card($email, $year) {
    foreach (btp_fu_build_cards() as $c) {
        if (btp_fu_norm($c['contact_email']) === $email && (int) $c['cycle_year'] === $year) return $c;
    }
    return null;
}

function btp_rest_fu_preview($req) {
    $card = btp_fu_req_card($req);
    if (is_wp_error($card)) return $card;
    list($email, $year) = $card;

    $found = btp_fu_find_card($email, $year);
    if (!$found) return new WP_Error('btp_fu_no_card', 'That card is no longer in the window.', array('status' => 404));

    list($subject, $body) = btp_fu_render_email($found);
    return new WP_REST_Response(array(
        'to'      => $found['contact_email'],
        'subject' => $subject,
        'body'    => $body,
        'ready'   => btp_fu_mail_ready(),
        'sender'  => btp_fu_sender(),
    ), 200);
}

/**
 * Send, then record it. The subject and body come back from the browser
 * because staff can edit the preview; the recipient does not, so an edited
 * preview can never be aimed somewhere else.
 */
function btp_rest_fu_send($req) {
    $card = btp_fu_req_card($req);
    if (is_wp_error($card)) return $card;
    list($email, $year) = $card;

    $found = btp_fu_find_card($email, $year);
    if (!$found) return new WP_Error('btp_fu_no_card', 'That card is no longer in the window.', array('status' => 404));

    $subject = trim((string) $req->get_param('subject'));
    $body    = rtrim((string) $req->get_param('body'));
    if ($subject === '' || trim($body) === '') {
        return new WP_Error('btp_fu_empty', 'The email needs a subject and a message.', array('status' => 400));
    }

    $to = $found['contact_email'];
    if (!is_email($to)) {
        return new WP_Error('btp_fu_bad_to', 'That contact has no usable email address.', array('status' => 400));
    }

    $sent = btp_fu_send_mail($to, $subject, $body);
    if (is_wp_error($sent)) {
        $id = btp_fu_card_id($email, $year);
        btp_fu_log($id, 'error', $sent->get_error_message());
        return new WP_Error('btp_fu_send_failed', $sent->get_error_message(), array('status' => 502));
    }

    global $wpdb;
    $id  = btp_fu_card_id($email, $year);
    $who = btp_fu_actor();
    $wpdb->update(btp_fu_table('cards'), array(
        'status'     => 'emailed',
        'emailed_at' => current_time('mysql'),
        'emailed_by' => $who,
        'updated_at' => current_time('mysql'),
    ), array('id' => $id));

    btp_fu_log($id, 'email', 'Sent to ' . $to . ' - ' . $subject);
    return new WP_REST_Response(btp_fu_card_state($email, $year), 200);
}

/**
 * A rename sticks: event_manual is a column the sync never writes.
 *
 * It also moves every order of this customer's that currently reads the same,
 * because a person is naming the event and not the invoice. See
 * btp_fu_rename_targets() for why renaming one on its own is wrong.
 */
function btp_rest_fu_rename_event($req) {
    global $wpdb;
    $visual = trim((string) $req->get_param('visual_id'));
    $event  = trim((string) $req->get_param('event'));
    if ($visual === '') return new WP_Error('btp_fu_bad_order', 'Which order?', array('status' => 400));
    if (mb_strlen($event) > 190) $event = mb_substr($event, 0, 190);

    $t     = btp_fu_table('orders');
    $email = $wpdb->get_var($wpdb->prepare("SELECT contact_email FROM $t WHERE visual_id = %s", $visual));
    if ($email === null) return new WP_Error('btp_fu_no_order', 'No such order.', array('status' => 404));

    $rows    = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE contact_email = %s", $email), ARRAY_A);
    $targets = btp_fu_rename_targets($rows ?: array(), $visual);
    if (!$targets) $targets = array($visual);

    foreach ($targets as $vid) {
        $wpdb->update($t, array('event_manual' => $event), array('visual_id' => $vid));
    }

    return new WP_REST_Response(array('event' => $event, 'orders' => $targets), 200);
}

function btp_rest_fu_sync_state() {
    return new WP_REST_Response(btp_fu_sync_public_state(), 200);
}

function btp_rest_fu_sync_run() {
    $res = btp_fu_sync_tick(2);
    $res['state'] = btp_fu_sync_public_state();
    return new WP_REST_Response($res, isset($res['ok']) && $res['ok'] ? 200 : 502);
}

/* =========================================================================
   ADMIN: BT Portal > Follow Up
   ========================================================================= */

add_action('admin_menu', function () {
    add_submenu_page('bt-portal', 'Follow Up', 'Follow Up', 'manage_options', 'bt-portal-followup', 'btp_fu_admin_page');
}, 20);

function btp_fu_admin_page() {
    if (!current_user_can('manage_options')) return;
    $notice = '';
    $err    = '';

    if (isset($_POST['btp_fu_save']) && check_admin_referer('btp_fu_save')) {
        update_option('btp_fu_window_days', max(1, (int) $_POST['btp_fu_window_days']));
        update_option('btp_fu_store_weeks', max(0, (int) $_POST['btp_fu_store_weeks']));
        update_option('btp_fu_sender', sanitize_email(wp_unslash($_POST['btp_fu_sender'])));
        update_option('btp_fu_template', wp_unslash($_POST['btp_fu_template']));
        update_option('btp_fu_store_line', wp_unslash($_POST['btp_fu_store_line']));
        update_option('btp_fu_transport', $_POST['btp_fu_transport'] === 'graph' ? 'graph' : 'wp');
        update_option('btp_fu_from_name', sanitize_text_field(wp_unslash($_POST['btp_fu_from_name'])));
        update_option('btp_fu_graph_tenant', sanitize_text_field(wp_unslash($_POST['btp_fu_graph_tenant'])));
        update_option('btp_fu_graph_client', sanitize_text_field(wp_unslash($_POST['btp_fu_graph_client'])));

        $secret = trim(wp_unslash($_POST['btp_fu_graph_secret']));
        if ($secret !== '') {
            update_option('btp_fu_graph_secret',
                function_exists('btp_vendor_encrypt') ? btp_vendor_encrypt($secret) : $secret, false);
        }
        delete_transient('btp_fu_graph_token');
        $notice = 'Saved.';
    }

    if (isset($_POST['btp_fu_reset_tpl']) && check_admin_referer('btp_fu_save')) {
        delete_option('btp_fu_template');
        $notice = 'Email put back to the approved wording.';
    }

    if (isset($_POST['btp_fu_test_mail']) && check_admin_referer('btp_fu_save')) {
        $token = btp_fu_graph_token(true);
        if (is_wp_error($token)) $err = $token->get_error_message();
        else $notice = 'Microsoft accepted the app credentials. A token came back.';
    }

    /* Worth more than any amount of explaining: send one and go look at it. */
    if (isset($_POST['btp_fu_send_test']) && check_admin_referer('btp_fu_save')) {
        $to = sanitize_email(wp_unslash($_POST['btp_fu_test_to']));
        if (!is_email($to)) {
            $err = 'That is not an email address.';
        } else {
            $sample = array(
                'contact_name' => 'Test Person', 'contact_email' => $to, 'days_out' => 30,
                'events' => array(array('event' => 'Test Event', 'last_due' => gmdate('Y-m-d', strtotime('-1 year')))),
            );
            list($subj, $body) = btp_fu_render_email($sample);
            $res = btp_fu_send_mail($to, $subj, $body);
            if (is_wp_error($res)) $err = $res->get_error_message();
            else $notice = 'Sent to ' . $to . ' the ' . (btp_fu_transport() === 'graph' ? 'Microsoft 365' : 'WordPress') . ' way. Check it arrived, and check the junk folder too.';
        }
    }

    if (isset($_POST['btp_fu_sync_toggle']) && check_admin_referer('btp_fu_save')) {
        $on = !get_option('btp_fu_sync_enabled', 0);
        update_option('btp_fu_sync_enabled', $on ? 1 : 0);
        $ts = wp_next_scheduled('btp_fu_sync_event');
        if ($ts) wp_unschedule_event($ts, 'btp_fu_sync_event');
        btp_fu_schedule();
        $notice = $on ? 'Sync turned on. The first backfill runs within the hour.' : 'Sync turned off.';
    }

    if (isset($_POST['btp_fu_sync_now']) && check_admin_referer('btp_fu_save')) {
        $res = btp_fu_sync_tick(2);
        if (empty($res['ok'])) $err = $res['error'];
        else $notice = 'Pulled ' . (int) $res['seen'] . ' invoice(s), saved ' . (int) $res['saved'] . '.';
    }

    if (isset($_POST['btp_fu_sync_reset']) && check_admin_referer('btp_fu_save')) {
        delete_option('btp_fu_sync_state');
        $notice = 'Backfill will start again from today and walk back to ' . BTP_FU_BACKFILL_FROM . '.';
    }

    $state  = btp_fu_sync_public_state();
    $cfg    = btp_fu_graph_config();
    $creds  = function_exists('btp_printavo_creds') ? btp_printavo_creds() : array('email' => '', 'token' => '');
    ?>
    <div class="wrap">
        <h1>Follow Up</h1>
        <p>Past Printavo orders, pulled on a schedule, turned into a reorder list about a month before each event comes round again.</p>

        <?php if ($notice): ?><div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <?php if ($err): ?><div class="notice notice-error"><p><?php echo esc_html($err); ?></p></div><?php endif; ?>

        <h2>Printavo</h2>
        <?php if (empty($creds['email']) || empty($creds['token'])): ?>
            <div class="notice notice-warning inline"><p>
                Printavo is not connected, so nothing can sync. Set the email and token under
                <a href="<?php echo esc_url(admin_url('admin.php?page=bt-portal-printavo')); ?>">BT Portal &gt; Printavo</a>.
                This page reuses that same connection.
            </p></div>
        <?php else: ?>
            <p>Using the connection from <a href="<?php echo esc_url(admin_url('admin.php?page=bt-portal-printavo')); ?>">BT Portal &gt; Printavo</a> (<code><?php echo esc_html($creds['email']); ?></code>).</p>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('btp_fu_save'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Sync</th>
                    <td>
                        <p>
                            <strong><?php echo $state['enabled'] ? 'On' : 'Off'; ?></strong> &middot;
                            <?php echo esc_html($state['mode'] === 'backfill' ? 'Backfilling' : 'Up to date, daily'); ?> &middot;
                            <?php echo (int) $state['orders']; ?> order(s) stored
                            <?php if ($state['last_run']): ?> &middot; last run <?php echo esc_html($state['last_run']); ?><?php endif; ?>
                        </p>
                        <?php if (!empty($state['last_error'])): ?>
                            <p style="color:#b32d2e"><strong>Last error:</strong> <?php echo esc_html($state['last_error']); ?></p>
                        <?php endif; ?>
                        <p>
                            <button type="submit" name="btp_fu_sync_toggle" class="button"><?php echo $state['enabled'] ? 'Turn sync off' : 'Turn sync on'; ?></button>
                            <button type="submit" name="btp_fu_sync_now" class="button">Run one batch now</button>
                            <button type="submit" name="btp_fu_sync_reset" class="button">Start the backfill over</button>
                        </p>
                        <p class="description">The first pass walks back to <?php echo esc_html(BTP_FU_BACKFILL_FROM); ?> a year at a time, a few pages per run. After that it only asks for what changed.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="btp_fu_window_days">Contact them this many days ahead</label></th>
                    <td>
                        <input type="number" min="1" max="365" id="btp_fu_window_days" name="btp_fu_window_days" value="<?php echo esc_attr(btp_fu_window_days()); ?>" class="small-text">
                        <p class="description">A customer appears when last year's due date is this many days away or closer.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="btp_fu_store_weeks">Offer an online store when the event is at least</label></th>
                    <td>
                        <input type="number" min="0" max="52" id="btp_fu_store_weeks" name="btp_fu_store_weeks" value="<?php echo esc_attr(btp_fu_store_weeks()); ?>" class="small-text"> weeks out
                        <p class="description">Stores take time to set up, so the offer is left out of the email when the event is sooner than this. 0 turns the line off entirely.</p>
                    </td>
                </tr>
            </table>

            <h2>Sending</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">How the email goes out</th>
                    <td>
                        <p><label><input type="radio" name="btp_fu_transport" value="wp" <?php checked(btp_fu_transport(), 'wp'); ?>>
                            <strong>WordPress</strong> &mdash; the same way the exchange emails already go. Nothing to set up.</label></p>
                        <p><label><input type="radio" name="btp_fu_transport" value="graph" <?php checked(btp_fu_transport(), 'graph'); ?>>
                            <strong>Microsoft 365</strong> &mdash; sends from the orders@ mailbox itself. Needs the app registration below.</label></p>
                        <p class="description">Start with WordPress. Move to Microsoft 365 if these start landing in people's junk folders: this is outreach to somebody who has not heard from the shop in a year, so it gets filtered harder than an exchange notice does.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="btp_fu_sender">Send from</label></th>
                    <td>
                        <input type="email" class="regular-text" id="btp_fu_sender" name="btp_fu_sender" value="<?php echo esc_attr(btp_fu_sender()); ?>">
                        <input type="text" class="regular-text" name="btp_fu_from_name" value="<?php echo esc_attr(btp_fu_from_name()); ?>" placeholder="Name it comes from">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Try it</th>
                    <td>
                        <input type="email" name="btp_fu_test_to" class="regular-text" placeholder="your own address">
                        <button type="submit" name="btp_fu_send_test" class="button">Send me a test</button>
                        <p class="description">Sends the real email with made-up details, the way it is set to send right now.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="btp_fu_graph_tenant">Directory (tenant) ID</label></th>
                    <td><input type="text" class="regular-text" id="btp_fu_graph_tenant" name="btp_fu_graph_tenant" value="<?php echo esc_attr($cfg['tenant']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="btp_fu_graph_client">Application (client) ID</label></th>
                    <td><input type="text" class="regular-text" id="btp_fu_graph_client" name="btp_fu_graph_client" value="<?php echo esc_attr($cfg['client']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="btp_fu_graph_secret">Client secret</label></th>
                    <td>
                        <input type="password" class="regular-text" id="btp_fu_graph_secret" name="btp_fu_graph_secret" value=""
                               placeholder="<?php echo $cfg['secret'] ? 'saved - leave blank to keep' : 'not set'; ?>">
                        <p class="description">Stored encrypted, like the vendor passwords. It is never sent to the browser.</p>
                    </td>
                </tr>
            </table>
            <p><button type="submit" name="btp_fu_test_mail" class="button">Test the Microsoft 365 connection</button>
               <span class="description">Only needed if you picked Microsoft 365 above.</span></p>

            <h2>The email</h2>
            <p>First line is the subject. Placeholders: <code>{First}</code>, <code>{Event}</code>, <code>{Month}</code>, <code>{STORE_LINE}</code>.
               With several events <code>{Event}</code> becomes "A, B and C", <code>{Month}</code> becomes "season", and the subject is replaced with "Your shirts from last year".</p>
            <textarea name="btp_fu_template" rows="18" class="large-text code"><?php echo esc_textarea(btp_fu_template()); ?></textarea>
            <p>
                <label for="btp_fu_store_line"><strong>The online store line</strong></label><br>
                <input type="text" class="large-text" id="btp_fu_store_line" name="btp_fu_store_line" value="<?php echo esc_attr(btp_fu_store_line()); ?>">
            </p>

            <p class="submit">
                <button type="submit" name="btp_fu_save" class="button button-primary">Save</button>
                <button type="submit" name="btp_fu_reset_tpl" class="button">Put the email back to the approved wording</button>
            </p>
        </form>
    </div>
    <?php
}

/* =========================================================================
   THE PAGE
   ========================================================================= */

add_shortcode('bt_follow_up', 'btp_follow_up_shortcode');

/**
 * Laid out like Online Stores on purpose: a fixed table with the condensed
 * uppercase headers, collapsible dark group headers, and the same card
 * fallback under 640px. Everything a person needs to decide "chase this one?"
 * is on the row; clicking opens the email and the buttons underneath it, so
 * there is no modal and no second page to come back from.
 */
function btp_follow_up_shortcode() {
    if (function_exists('btp_user_can_access') && !btp_user_can_access()) return '';

    $cfg = wp_json_encode(array(
        'api'      => esc_url_raw(rest_url('boomerts/v1')),
        'nonce'    => wp_create_nonce('wp_rest'),
        'printavo' => 'https://www.printavo.com/invoices/',
        'search'   => 'https://www.printavo.com/search?query=',
    ));

    ob_start();
    ?>
<div id="btp-fu">
  <div class="fu-head">
    <div class="fu-title">FOLLOW <span>Up</span></div>
    <div class="fu-filters" id="fuFilters">
      <button type="button" class="fu-pill active" data-filter="open">To chase</button>
      <button type="button" class="fu-pill" data-filter="due_now">Due now</button>
      <button type="button" class="fu-pill" data-filter="two_weeks">Next 2 weeks</button>
      <button type="button" class="fu-pill" data-filter="later">Later</button>
      <button type="button" class="fu-pill" data-filter="contacted">Contacted</button>
      <button type="button" class="fu-pill" data-filter="not_this_year">Not this year</button>
      <button type="button" class="fu-pill" data-filter="all">All</button>
    </div>
  </div>

  <div class="fu-status" id="fuStatus"></div>
  <div class="fu-warn" id="fuMailWarn" hidden>
    Sending is not set up yet, so the Send button will not work. A WordPress admin sorts it under <b>BT Portal &gt; Follow Up</b>.
  </div>

  <div class="fu-panel">
    <table class="fu-table">
      <thead><tr>
        <th>Order #</th><th>Last Year</th><th>Customer</th><th>Organization</th>
        <th>Nickname</th><th>Event</th><th>Status</th><th></th>
      </tr></thead>
      <tbody id="fuBody"></tbody>
    </table>
    <div class="fu-cards" id="fuCards"></div>
    <div id="fuNone" class="fu-none" hidden>Nobody to chase here.</div>
  </div>
</div>

<style>
#btp-fu { --navy:#27267e; --navy-d:#1a1f5e; --pink:#e535ab; --line:#e8eaf0; --g100:#f4f5f9;
  --g400:#9ca3b8; --g600:#5a6380; font-family:'Barlow',sans-serif; padding:10px 12px 40px; }
#btp-fu * { box-sizing:border-box; }
#btp-fu .fu-head { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:7px; }
#btp-fu .fu-title { font-family:'Oswald',sans-serif; font-size:22px; font-weight:700; letter-spacing:.04em;
  text-transform:uppercase; color:var(--navy-d); }
#btp-fu .fu-title span { color:var(--pink); }
#btp-fu .fu-filters { display:flex; gap:6px; flex-wrap:wrap; }
#btp-fu .fu-pill { border:1.5px solid var(--line); background:#fff; border-radius:20px; padding:4px 13px;
  font-family:'Barlow Condensed',sans-serif; font-size:14px; font-weight:700; letter-spacing:.06em;
  text-transform:uppercase; color:var(--g600); cursor:pointer; }
#btp-fu .fu-pill:hover { border-color:var(--navy); color:var(--navy); }
#btp-fu .fu-pill.active { background:var(--navy); border-color:var(--navy); color:#fff; }
#btp-fu .fu-status { font-size:14px; color:var(--g600); margin-bottom:7px; }
#btp-fu .fu-warn { background:#fff6e5; border:1px solid #f0c986; border-radius:6px; padding:8px 11px;
  font-size:15px; margin-bottom:9px; }
#btp-fu .fu-panel { background:#fff; border:1.5px solid var(--line); border-radius:8px; overflow:hidden; }
#btp-fu .fu-none { padding:30px 10px; text-align:center; color:var(--g600); font-size:15px; }

/* ── the table, same shape as Online Stores ── */
#btp-fu .fu-table { width:100%; border-collapse:collapse; font-family:'Barlow',sans-serif; font-size:16px; table-layout:fixed; }
#btp-fu .fu-table th { font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700;
  letter-spacing:.1em; text-transform:uppercase; color:var(--g400); padding:8px 10px; text-align:left;
  background:var(--g100); border-bottom:2px solid var(--line); white-space:nowrap; }
#btp-fu .fu-table th:nth-child(1) { width:8%; }
#btp-fu .fu-table th:nth-child(2) { width:11%; }
#btp-fu .fu-table th:nth-child(3) { width:16%; }
#btp-fu .fu-table th:nth-child(4) { width:17%; }
#btp-fu .fu-table th:nth-child(5) { width:14%; }
#btp-fu .fu-table th:nth-child(6) { width:18%; }
#btp-fu .fu-table th:nth-child(7) { width:12%; }
#btp-fu .fu-table th:nth-child(8) { width:4%; }
#btp-fu .fu-table td { padding:10px; border-bottom:1px solid var(--line); color:var(--navy-d);
  vertical-align:middle; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
#btp-fu .fu-row { cursor:pointer; }
#btp-fu .fu-row:hover td { background:var(--g100); }
#btp-fu .fu-row.open td { background:#eef0fb; }
#btp-fu .fu-row.is-done td { color:var(--g600); }
#btp-fu .fu-ordno { font-family:'Barlow Condensed',sans-serif; font-weight:700; font-size:16px;
  letter-spacing:.03em; color:var(--navy); text-decoration:none; border-bottom:1.5px solid rgba(26,31,94,.28); }
#btp-fu .fu-ordno:hover { color:var(--pink); border-bottom-color:var(--pink); }
#btp-fu .fu-cust { font-weight:600; }
#btp-fu .fu-sub { color:var(--g400); font-size:14px; }
#btp-fu .fu-chev { color:var(--g400); font-size:11px; transition:transform .18s; display:inline-block; }
#btp-fu .fu-row.open .fu-chev { transform:rotate(90deg); }
#btp-fu .fu-badge { display:inline-block; padding:3px 11px; border-radius:12px;
  font-family:'Barlow Condensed',sans-serif; font-size:14px; font-weight:700; letter-spacing:.06em;
  background:#eceff5; color:var(--g600); white-space:nowrap; }
#btp-fu .fu-badge.s-to_contact { background:#e3f2fd; color:#1a1f5e; }
#btp-fu .fu-badge.s-emailed { background:#e8f5e9; color:#1b5e20; }
#btp-fu .fu-badge.s-replied { background:#e3f2fd; color:#0d47a1; }
#btp-fu .fu-badge.s-reordered { background:#fde9f6; color:#8c1361; }
#btp-fu .fu-badge.s-not_this_year { background:#f5f5f5; color:var(--g600); }
#btp-fu .fu-badge.s-snoozed { background:#fff3e0; color:#8a5300; }

/* ── collapsible group headers, same as the stores categories ── */
#btp-fu .fu-grp { background:var(--navy-d); cursor:pointer; user-select:none; }
#btp-fu .fu-grp td { padding:7px 10px; border-bottom:2px solid var(--navy); border-top:3px solid var(--line); }
#btp-fu .fu-grp:first-child td { border-top:none; }
#btp-fu .fu-grp-label { font-family:'Oswald',sans-serif; font-size:13px; font-weight:700; letter-spacing:.1em;
  text-transform:uppercase; color:#fff; display:inline-flex; align-items:center; gap:8px; }
#btp-fu .fu-grp-chev { font-size:10px; color:rgba(255,255,255,.5); transition:transform .2s; display:inline-block; }
#btp-fu .fu-grp.collapsed .fu-grp-chev { transform:rotate(-90deg); }
#btp-fu .fu-grp-count { font-family:'Barlow Condensed',sans-serif; font-size:12px; font-weight:600;
  color:rgba(255,255,255,.45); letter-spacing:.06em; }

/* ── what opens underneath a row ── */
#btp-fu .fu-detail > td { padding:0; background:#fbfbfd; border-bottom:2px solid var(--line);
  white-space:normal; overflow:visible; text-overflow:clip; }
#btp-fu .fu-detail-in { display:flex; gap:18px; padding:14px 14px 16px; align-items:flex-start; }
#btp-fu .fu-left { flex:1 1 42%; min-width:0; }
#btp-fu .fu-right { flex:1 1 58%; min-width:0; }
#btp-fu .fu-lab { font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700;
  letter-spacing:.09em; text-transform:uppercase; color:var(--g400); margin:0 0 4px; }
#btp-fu .fu-meta { font-size:15px; line-height:1.5; margin-bottom:11px; }
#btp-fu .fu-meta a { color:var(--navy); }
#btp-fu .fu-ev { margin-bottom:9px; }
#btp-fu .fu-ev-name { font-family:'Oswald',sans-serif; font-size:15px; font-weight:600; color:var(--navy-d); }
#btp-fu .fu-rename { border:none; background:none; color:var(--g400); cursor:pointer; font-size:13px;
  text-decoration:underline; padding:0 0 0 6px; }
#btp-fu .fu-ord { display:flex; gap:12px; flex-wrap:wrap; font-size:14px; padding:2px 0; color:#333; }
#btp-fu .fu-ord span { color:var(--g600); }
#btp-fu .fu-input { width:100%; border:1.5px solid var(--line); border-radius:6px; padding:8px 9px;
  font-family:'Barlow',sans-serif; font-size:15px; background:#fff; }
#btp-fu textarea.fu-input { line-height:1.5; resize:vertical; }
#btp-fu .fu-acts { display:flex; gap:7px; flex-wrap:wrap; margin-top:9px; align-items:center; }
#btp-fu .fu-btn { border:1.5px solid var(--line); background:#fff; border-radius:6px; padding:7px 14px;
  font-family:'Barlow Condensed',sans-serif; font-size:15px; font-weight:700; letter-spacing:.05em;
  text-transform:uppercase; color:var(--g600); cursor:pointer; }
#btp-fu .fu-btn:hover { border-color:var(--navy); color:var(--navy); }
#btp-fu .fu-btn.primary { background:var(--pink); border-color:var(--pink); color:#fff; }
#btp-fu .fu-btn.primary:disabled { opacity:.45; cursor:not-allowed; }
#btp-fu .fu-err { margin-top:9px; background:#fdecea; border:1px solid #f3b3ad; color:#8c1d18;
  border-radius:6px; padding:8px 10px; font-size:14px; }
#btp-fu .fu-ok { margin-top:9px; background:#e8f5e9; border:1px solid #a5d6a7; color:#1b5e20;
  border-radius:6px; padding:8px 10px; font-size:14px; }
#btp-fu .fu-log { margin-top:11px; font-size:13px; color:var(--g600); }
#btp-fu .fu-log div { padding:1px 0; }

/* ── cards instead of a table on a phone, same switch as Online Stores ── */
#btp-fu .fu-cards { display:none; }
@media (max-width:640px) {
  #btp-fu .fu-table { display:none; }
  #btp-fu .fu-cards { display:flex !important; flex-direction:column; }
  #btp-fu .fu-detail-in { flex-direction:column; gap:12px; }
  #btp-fu .fu-card { border-bottom:1px solid var(--line); padding:11px 12px; cursor:pointer; }
  #btp-fu .fu-card.open { background:#eef0fb; }
  #btp-fu .fu-card-top { display:flex; justify-content:space-between; gap:10px; align-items:baseline; }
  #btp-fu .fu-card-name { font-family:'Oswald',sans-serif; font-size:17px; font-weight:700; color:var(--navy-d); }
  #btp-fu .fu-card-row { font-size:15px; color:var(--g600); margin-top:3px; }
  #btp-fu .fu-card-det { padding:0; }
}
</style>

<script>
(function () {
  var CFG = <?php echo $cfg; ?>;
  var cards = [];
  var filter = 'open';
  var openKey = null;                 /* which row is expanded */
  var collapsed = {};                 /* which groups are rolled up */
  var drafts = {};                    /* unsent edits, kept across a re-render */

  function api(path, method, body) {
    var opts = { method: method || 'GET', headers: { 'X-WP-Nonce': CFG.nonce }, credentials: 'same-origin' };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    return fetch(CFG.api + path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) throw new Error((j && j.message) || ('Request failed (' + r.status + ')'));
        return j;
      });
    });
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function money(n) { return '$' + Number(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  function prettyDate(ymd) {
    if (!ymd) return '';
    var p = String(ymd).split('-');
    if (p.length !== 3) return ymd;
    return MONTHS[parseInt(p[1], 10) - 1] + ' ' + parseInt(p[2], 10) + ', ' + p[0];
  }

  var STATUS_TEXT = {
    to_contact: 'To contact', emailed: 'Emailed', replied: 'Replied',
    reordered: 'Reordered', not_this_year: 'Not this year', snoozed: 'Snoozed'
  };
  var GROUPS = [
    { key: 'due_now',   label: 'Due now' },
    { key: 'two_weeks', label: 'Next 2 weeks' },
    { key: 'later',     label: 'Later' }
  ];

  function keyOf(c) { return c.contact_email + '|' + c.cycle_year; }
  function isDone(c) { return c.status === 'reordered' || c.status === 'not_this_year'; }

  function matches(c) {
    if (filter === 'all') return true;
    if (filter === 'contacted') return c.status === 'emailed' || c.status === 'replied';
    if (filter === 'not_this_year') return c.status === 'not_this_year';
    if (isDone(c) || c.status === 'snoozed') return false;
    if (filter === 'open') return true;
    return c.bucket === filter;
  }

  function orderUrl(o) {
    return o.printavo_id ? (CFG.printavo + encodeURIComponent(o.printavo_id))
                         : (CFG.search + encodeURIComponent(o.visual_id));
  }

  /* The order whose number and nickname stand for the whole row. */
  function leadOrder(c) { return c.events[0].orders[0]; }

  /* ── the expanded panel, shared by the table and the phone cards ── */
  function detailHtml(c) {
    var k = esc(keyOf(c));
    var d = drafts[keyOf(c)] || {};

    var events = c.events.map(function (e) {
      var orders = e.orders.map(function (o) {
        return '<div class="fu-ord">'
          + '<a class="fu-ordno" href="' + esc(orderUrl(o)) + '" target="_blank" rel="noopener">#' + esc(o.visual_id) + '</a>'
          + '<span>' + esc(prettyDate(o.due_at)) + '</span>'
          + '<span>' + (o.qty || 0) + ' pcs</span>'
          + '<span>' + esc(money(o.total)) + '</span>'
          + (o.nickname ? '<span>' + esc(o.nickname) + '</span>' : '')
          + '</div>';
      }).join('');
      return '<div class="fu-ev"><div class="fu-ev-name">' + esc(e.event)
        + '<button type="button" class="fu-rename" data-order="' + esc(e.orders[0].visual_id) + '">rename</button>'
        + '</div>' + orders + '</div>';
    }).join('');

    var log = (c.log || []).slice(0, 5).map(function (l) {
      return '<div>' + esc(l.at) + ' &middot; ' + esc(l.who) + ' &middot; ' + esc(l.detail) + '</div>';
    }).join('');

    return '<div class="fu-detail-in" data-key="' + k + '">'
      + '<div class="fu-left">'
        + '<p class="fu-lab">Contact</p>'
        + '<div class="fu-meta">'
          + '<b>' + esc(c.contact_name || 'No name on file') + '</b>'
          + (c.company ? '<br>' + esc(c.company) : '')
          + '<br><a href="mailto:' + esc(c.contact_email) + '">' + esc(c.contact_email) + '</a>'
          + (c.contact_phone ? '<br><a href="tel:' + esc(c.contact_phone) + '">' + esc(c.contact_phone) + '</a>' : '')
        + '</div>'
        + '<p class="fu-lab">Past orders</p>'
        + events
        + '<p class="fu-lab" style="margin-top:11px">Notes</p>'
        + '<textarea class="fu-input fu-notes" rows="2">' + esc(c.notes || '') + '</textarea>'
        + (log ? '<div class="fu-log"><p class="fu-lab">History</p>' + log + '</div>' : '')
      + '</div>'
      + '<div class="fu-right">'
        + '<p class="fu-lab">Email to ' + esc(c.contact_email) + '</p>'
        + '<input type="text" class="fu-input fu-subject" value="' + esc(d.subject || '') + '" placeholder="Loading...">'
        + '<textarea class="fu-input fu-body" rows="15" style="margin-top:7px" placeholder="Loading...">' + esc(d.body || '') + '</textarea>'
        + '<div class="fu-acts">'
          + '<button type="button" class="fu-btn primary" data-act="send">Send</button>'
          + '<button type="button" class="fu-btn" data-act="replied">Replied</button>'
          + '<button type="button" class="fu-btn" data-act="not_this_year">Not this year</button>'
          + '<button type="button" class="fu-btn" data-act="snoozed">Snooze</button>'
          + '<button type="button" class="fu-btn" data-act="to_contact">Reset</button>'
        + '</div>'
        + '<div class="fu-err" hidden></div>'
        + '<div class="fu-ok" hidden></div>'
      + '</div>'
      + '</div>';
  }

  function rowHtml(c) {
    var o = leadOrder(c);
    var more = c.events.length > 1 ? ' <span class="fu-sub">+' + (c.events.length - 1) + ' more</span>' : '';
    var open = keyOf(c) === openKey;
    return '<tr class="fu-row' + (open ? ' open' : '') + (isDone(c) ? ' is-done' : '') + '" data-key="' + esc(keyOf(c)) + '">'
      + '<td><a class="fu-ordno" href="' + esc(orderUrl(o)) + '" target="_blank" rel="noopener" onclick="event.stopPropagation()">#' + esc(o.visual_id) + '</a></td>'
      + '<td>' + esc(prettyDate(o.due_at)) + '</td>'
      + '<td class="fu-cust">' + esc(c.contact_name || '-') + '</td>'
      + '<td>' + esc(c.company || '-') + '</td>'
      + '<td>' + esc(o.nickname || '-') + '</td>'
      + '<td>' + esc(c.events[0].event) + more + '</td>'
      + '<td><span class="fu-badge s-' + esc(c.status) + '">' + esc(STATUS_TEXT[c.status] || c.status) + '</span></td>'
      + '<td><span class="fu-chev">&#9654;</span></td>'
      + '</tr>'
      + (open ? '<tr class="fu-detail"><td colspan="8">' + detailHtml(c) + '</td></tr>' : '');
  }

  function cardHtml(c) {
    var o = leadOrder(c);
    var open = keyOf(c) === openKey;
    return '<div class="fu-card' + (open ? ' open' : '') + '" data-key="' + esc(keyOf(c)) + '">'
      + '<div class="fu-card-top">'
        + '<span class="fu-card-name">' + esc(c.contact_name || c.contact_email) + '</span>'
        + '<span class="fu-badge s-' + esc(c.status) + '">' + esc(STATUS_TEXT[c.status] || c.status) + '</span>'
      + '</div>'
      + '<div class="fu-card-row">#' + esc(o.visual_id) + ' &middot; ' + esc(prettyDate(o.due_at)) + '</div>'
      + '<div class="fu-card-row">' + esc(c.company || '') + '</div>'
      + '<div class="fu-card-row">' + esc(c.events[0].event) + (o.nickname ? ' &middot; ' + esc(o.nickname) : '') + '</div>'
      + '</div>'
      + (open ? '<div class="fu-card-det">' + detailHtml(c) + '</div>' : '');
  }

  function render() {
    var shown = cards.filter(matches);
    var body = document.getElementById('fuBody');
    var cardWrap = document.getElementById('fuCards');
    var html = '', cardsHtml = '';

    GROUPS.forEach(function (g) {
      var inGroup = shown.filter(function (c) { return c.bucket === g.key; });
      if (!inGroup.length) return;
      var isUp = !!collapsed[g.key];
      html += '<tr class="fu-grp' + (isUp ? ' collapsed' : '') + '" data-grp="' + g.key + '"><td colspan="8">'
        + '<span class="fu-grp-label"><span class="fu-grp-chev">&#9660;</span>' + g.label
        + ' <span class="fu-grp-count">' + inGroup.length + '</span></span></td></tr>';
      cardsHtml += '<div class="fu-grp-card" style="background:#1a1f5e;color:#fff;padding:7px 12px;'
        + 'font-family:Oswald,sans-serif;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;">'
        + g.label + ' <span style="color:rgba(255,255,255,.45)">' + inGroup.length + '</span></div>';
      if (isUp) return;
      inGroup.forEach(function (c) { html += rowHtml(c); cardsHtml += cardHtml(c); });
    });

    body.innerHTML = html;
    cardWrap.innerHTML = cardsHtml;
    document.getElementById('fuNone').hidden = shown.length > 0;
    if (openKey) loadDraft(openKey);
  }

  function setStatusLine(d) {
    var s = d.sync || {}, bits = [];
    bits.push(cards.length + ' customer(s) in the next ' + d.window + ' days');
    if (s.orders != null) bits.push(s.orders + ' past orders synced');
    if (!s.enabled) bits.push('sync is OFF');
    else if (s.mode === 'backfill') bits.push('still backfilling from Printavo');
    if (s.last_error) bits.push('last sync error: ' + s.last_error);
    document.getElementById('fuStatus').textContent = bits.join(' · ');
  }

  function load() {
    return api('/followup/cards').then(function (d) {
      cards = d.cards || [];
      document.getElementById('fuMailWarn').hidden = !!d.mail_ready;
      setStatusLine(d);
      render();
    }).catch(function (e) {
      document.getElementById('fuStatus').textContent = 'Could not load the list: ' + e.message;
    });
  }

  function cardByKey(k) {
    for (var i = 0; i < cards.length; i++) if (keyOf(cards[i]) === k) return cards[i];
    return null;
  }
  /* The table and the phone cards each render their own copy of the panel, so
     anything written into one has to be written into both. */
  function panelsOf(k) {
    var sel = '.fu-detail-in[data-key="' + (window.CSS && CSS.escape ? CSS.escape(k) : k) + '"]';
    var found = document.querySelectorAll(sel);
    return found.length ? [].slice.call(found) : [].slice.call(document.querySelectorAll('.fu-detail-in'));
  }

  /* The draft is only fetched once per open, so an edit is never overwritten
     by its own preview arriving late. */
  function loadDraft(k) {
    var c = cardByKey(k);
    var panels = panelsOf(k);
    if (!c || !panels.length) return;
    if (drafts[k]) return;
    api('/followup/preview', 'POST', { contact_email: c.contact_email, cycle_year: c.cycle_year })
      .then(function (d) {
        drafts[k] = { subject: d.subject || '', body: d.body || '' };
        panelsOf(k).forEach(function (p) {
          p.querySelector('.fu-subject').value = drafts[k].subject;
          p.querySelector('.fu-body').value = drafts[k].body;
          if (!d.ready) say(p, 'err', 'Sending is not set up yet, so this cannot go out. See BT Portal > Follow Up.');
        });
      })
      .catch(function (e) { panelsOf(k).forEach(function (p) { say(p, 'err', e.message); }); });
  }

  function say(panel, kind, msg) {
    if (!panel) return;
    var box = panel.querySelector(kind === 'err' ? '.fu-err' : '.fu-ok');
    var other = panel.querySelector(kind === 'err' ? '.fu-ok' : '.fu-err');
    if (other) other.hidden = true;
    if (!box) return;
    box.textContent = msg;
    box.hidden = false;
  }

  function toggle(k) {
    openKey = (openKey === k) ? null : k;
    render();
  }

  /* ── clicks ── */
  document.getElementById('fuFilters').addEventListener('click', function (ev) {
    var b = ev.target.closest('.fu-pill');
    if (!b) return;
    filter = b.dataset.filter;
    [].forEach.call(this.querySelectorAll('.fu-pill'), function (p) { p.classList.toggle('active', p === b); });
    render();
  });

  document.getElementById('btp-fu').addEventListener('click', function (ev) {
    var grp = ev.target.closest('.fu-grp');
    if (grp) { collapsed[grp.dataset.grp] = !collapsed[grp.dataset.grp]; render(); return; }

    var rename = ev.target.closest('.fu-rename');
    if (rename) {
      ev.stopPropagation();
      var next = prompt('What is this event called?', '');
      if (next === null || next === '') return;
      api('/followup/orders/' + encodeURIComponent(rename.dataset.order) + '/event', 'POST', { event: next })
        .then(load).catch(function (e) { alert(e.message); });
      return;
    }

    var btn = ev.target.closest('.fu-btn');
    if (btn) {
      ev.stopPropagation();
      var panel = btn.closest('.fu-detail-in');
      var c = cardByKey(panel.dataset.key);
      if (!c) return;
      if (btn.dataset.act === 'send') { doSend(c, panel, btn); return; }
      if (btn.dataset.act === 'snoozed') {
        var when = prompt('Snooze until when? (YYYY-MM-DD)', '');
        if (!when) return;
        saveStatus(c, 'snoozed', when, panel);
        return;
      }
      saveStatus(c, btn.dataset.act, '', panel);
      return;
    }

    if (ev.target.closest('.fu-detail-in')) return;   /* typing in the panel */
    var row = ev.target.closest('.fu-row') || ev.target.closest('.fu-card');
    if (row) toggle(row.dataset.key);
  });

  document.getElementById('btp-fu').addEventListener('change', function (ev) {
    var ta = ev.target.closest('.fu-notes');
    if (!ta) return;
    var panel = ta.closest('.fu-detail-in');
    var c = cardByKey(panel.dataset.key);
    if (!c) return;
    api('/followup/cards/notes', 'POST', {
      contact_email: c.contact_email, cycle_year: c.cycle_year, notes: ta.value
    }).then(function () { say(panel, 'ok', 'Note saved.'); })
      .catch(function (e) { say(panel, 'err', e.message); });
  });

  /* Keep what has been typed, so a re-render does not throw it away. */
  document.getElementById('btp-fu').addEventListener('input', function (ev) {
    var f = ev.target.closest('.fu-subject, .fu-body');
    if (!f) return;
    var panel = f.closest('.fu-detail-in');
    var k = panel.dataset.key;
    var which = f.classList.contains('fu-subject') ? 'subject' : 'body';
    drafts[k] = drafts[k] || { subject: '', body: '' };
    drafts[k][which] = f.value;
    panelsOf(k).forEach(function (p) {
      var other = p.querySelector(which === 'subject' ? '.fu-subject' : '.fu-body');
      if (other && other !== f) other.value = f.value;
    });
  });

  function saveStatus(c, status, snooze, panel) {
    return api('/followup/cards/status', 'POST', {
      contact_email: c.contact_email, cycle_year: c.cycle_year, status: status, snooze_until: snooze || ''
    }).then(load).catch(function (e) { say(panel, 'err', e.message); });
  }

  function doSend(c, panel, btn) {
    var subject = panel.querySelector('.fu-subject').value;
    var body    = panel.querySelector('.fu-body').value;
    btn.disabled = true;
    btn.textContent = 'Sending...';
    api('/followup/send', 'POST', {
      contact_email: c.contact_email, cycle_year: c.cycle_year, subject: subject, body: body
    }).then(function () {
      delete drafts[keyOf(c)];
      openKey = null;
      return load();
    }).catch(function (e) {
      btn.disabled = false; btn.textContent = 'Send';
      say(panel, 'err', e.message);
    });
  }

  window.btpFollowUpLoad = load;
})();
</script>
    <?php
    return ob_get_clean();
}
