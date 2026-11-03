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
        'mail_ready' => btp_fu_graph_ready(),
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
        'ready'   => btp_fu_graph_ready(),
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

    $sent = btp_fu_graph_send($to, $subject, $body, btp_fu_sender());
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
            <p>orders@ is a Microsoft 365 shared mailbox, so mail goes out through a Graph app registration. Setup steps are in the plugin's CLAUDE.md and in the pull request that added this page.</p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="btp_fu_sender">Send from</label></th>
                    <td><input type="email" class="regular-text" id="btp_fu_sender" name="btp_fu_sender" value="<?php echo esc_attr(btp_fu_sender()); ?>"></td>
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
            <p><button type="submit" name="btp_fu_test_mail" class="button">Test the connection</button></p>

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
      <button type="button" class="fu-pill active" data-filter="due_now">Due now</button>
      <button type="button" class="fu-pill" data-filter="two_weeks">Next 2 weeks</button>
      <button type="button" class="fu-pill" data-filter="later">Later</button>
      <button type="button" class="fu-pill" data-filter="contacted">Contacted</button>
      <button type="button" class="fu-pill" data-filter="not_this_year">Not this year</button>
      <button type="button" class="fu-pill" data-filter="all">All</button>
    </div>
  </div>

  <div class="fu-status" id="fuStatus"></div>
  <div class="fu-warn" id="fuMailWarn" hidden>
    Sending is not set up yet, so the Send button will not work. A WordPress admin adds the Microsoft 365 details under <b>BT Portal &gt; Follow Up</b>.
  </div>

  <div id="fuList" class="fu-list"></div>
  <div id="fuNone" class="fu-none" hidden>Nobody to chase here.</div>
</div>

<div class="fu-modal" id="fuSendModal" hidden>
  <div class="fu-modal-box">
    <div class="fu-modal-head">
      <span id="fuSendTitle">Send</span>
      <button type="button" class="fu-x" id="fuSendClose" aria-label="Close">&times;</button>
    </div>
    <div class="fu-modal-body">
      <label class="fu-lab">To</label>
      <div class="fu-to" id="fuSendTo"></div>
      <label class="fu-lab" for="fuSendSubject">Subject</label>
      <input type="text" id="fuSendSubject" class="fu-input">
      <label class="fu-lab" for="fuSendBody">Message</label>
      <textarea id="fuSendBody" class="fu-input" rows="16"></textarea>
      <div class="fu-err" id="fuSendErr" hidden></div>
    </div>
    <div class="fu-modal-foot">
      <button type="button" class="fu-btn" id="fuSendCancel">Cancel</button>
      <button type="button" class="fu-btn primary" id="fuSendGo">Send</button>
    </div>
  </div>
</div>

<style>
#btp-fu { --navy:#27267e; --navy-d:#1a1a4e; --pink:#e535ab; --line:#e2e5ee; --muted:#6b7389;
  font-family:'Barlow',sans-serif; padding:10px 12px 40px; box-sizing:border-box; }
#btp-fu * { box-sizing:border-box; }
#btp-fu .fu-head { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:8px; }
#btp-fu .fu-title { font-family:'Oswald',sans-serif; font-size:22px; font-weight:700; letter-spacing:.04em;
  text-transform:uppercase; color:var(--navy-d); }
#btp-fu .fu-title span { color:var(--pink); }
#btp-fu .fu-filters { display:flex; gap:6px; flex-wrap:wrap; }
#btp-fu .fu-pill { border:1.5px solid var(--line); background:#fff; border-radius:20px; padding:5px 13px;
  font-family:'Barlow Condensed',sans-serif; font-size:14px; font-weight:600; letter-spacing:.04em;
  text-transform:uppercase; color:#5a6380; cursor:pointer; }
#btp-fu .fu-pill:hover { border-color:var(--navy); color:var(--navy); }
#btp-fu .fu-pill.active { background:var(--navy); border-color:var(--navy); color:#fff; }
#btp-fu .fu-status { font-size:13px; color:var(--muted); margin-bottom:8px; }
#btp-fu .fu-warn { background:#fff6e5; border:1px solid #f0c986; border-radius:6px; padding:8px 11px;
  font-size:14px; margin-bottom:10px; }
#btp-fu .fu-none { padding:30px 10px; text-align:center; color:var(--muted); font-size:15px; }
#btp-fu .fu-list { display:flex; flex-direction:column; gap:10px; }

#btp-fu .fu-card { background:#fff; border:1.5px solid var(--line); border-radius:8px; overflow:hidden; }
#btp-fu .fu-card.is-reordered { opacity:.62; }
#btp-fu .fu-card-top { display:flex; align-items:flex-start; gap:10px; padding:9px 12px; background:var(--navy-d); color:#fff; flex-wrap:wrap; }
#btp-fu .fu-when { font-family:'Oswald',sans-serif; font-size:17px; font-weight:700; letter-spacing:.03em; flex:1; min-width:200px; }
#btp-fu .fu-when em { font-style:normal; color:#ffd6f1; }
#btp-fu .fu-chip { font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.07em;
  text-transform:uppercase; padding:3px 9px; border-radius:4px; background:rgba(255,255,255,.16); white-space:nowrap; }
#btp-fu .fu-chip.s-emailed { background:#2e7d32; }
#btp-fu .fu-chip.s-replied { background:#1565c0; }
#btp-fu .fu-chip.s-reordered { background:var(--pink); }
#btp-fu .fu-chip.s-not_this_year { background:#6b7389; }
#btp-fu .fu-chip.s-snoozed { background:#ad6800; }

#btp-fu .fu-who { padding:9px 12px; border-bottom:1px solid var(--line); display:flex; gap:16px; flex-wrap:wrap; font-size:15px; }
#btp-fu .fu-who b { font-size:16px; color:var(--navy-d); }
#btp-fu .fu-who a { color:var(--navy); }
#btp-fu .fu-ev { padding:8px 12px; border-bottom:1px solid var(--line); }
#btp-fu .fu-ev-name { font-family:'Oswald',sans-serif; font-size:15px; font-weight:600; color:var(--navy-d); margin-bottom:5px; }
#btp-fu .fu-rename { border:none; background:none; color:var(--muted); cursor:pointer; font-size:13px; text-decoration:underline; padding:0 0 0 7px; }
#btp-fu .fu-ord { display:flex; gap:14px; flex-wrap:wrap; align-items:baseline; font-size:14px; padding:3px 0; color:#333; }
#btp-fu .fu-ord a { font-weight:700; color:var(--navy); text-decoration:none; border-bottom:1px solid rgba(39,38,126,.3); }
#btp-fu .fu-ord span { color:var(--muted); }
#btp-fu .fu-foot { padding:9px 12px; display:flex; gap:7px; flex-wrap:wrap; align-items:center; }
#btp-fu .fu-btn { border:1.5px solid var(--line); background:#fff; border-radius:6px; padding:6px 13px;
  font-family:'Barlow Condensed',sans-serif; font-size:14px; font-weight:700; letter-spacing:.05em;
  text-transform:uppercase; color:#5a6380; cursor:pointer; }
#btp-fu .fu-btn:hover { border-color:var(--navy); color:var(--navy); }
#btp-fu .fu-btn.primary { background:var(--pink); border-color:var(--pink); color:#fff; }
#btp-fu .fu-btn.primary:disabled { opacity:.45; cursor:not-allowed; }
#btp-fu .fu-notes { width:100%; border:1.5px solid var(--line); border-radius:6px; padding:6px 8px;
  font-family:'Barlow',sans-serif; font-size:14px; margin-top:6px; resize:vertical; }
#btp-fu .fu-log { padding:0 12px 10px; font-size:13px; color:var(--muted); }
#btp-fu .fu-log div { padding:1px 0; }

#btp-fu .fu-reorder { background:#fde9f6; color:#8c1361; padding:6px 12px; font-size:14px; font-weight:600; }

.fu-modal { position:fixed; inset:0; background:rgba(16,18,36,.55); z-index:999999; display:flex;
  align-items:center; justify-content:center; padding:14px; }
.fu-modal[hidden] { display:none; }
.fu-modal-box { background:#fff; border-radius:10px; width:min(680px,100%); max-height:92vh; display:flex; flex-direction:column;
  font-family:'Barlow',sans-serif; }
.fu-modal-head { display:flex; align-items:center; justify-content:space-between; padding:11px 14px;
  background:#1a1a4e; color:#fff; border-radius:10px 10px 0 0; font-family:'Oswald',sans-serif; font-size:17px;
  font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
.fu-x { background:none; border:none; color:#fff; font-size:24px; line-height:1; cursor:pointer; }
.fu-modal-body { padding:12px 14px; overflow:auto; }
.fu-lab { display:block; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700;
  letter-spacing:.08em; text-transform:uppercase; color:#6b7389; margin:9px 0 3px; }
.fu-to { font-size:15px; font-weight:600; color:#1a1a4e; }
.fu-input { width:100%; border:1.5px solid #e2e5ee; border-radius:6px; padding:8px 9px;
  font-family:'Barlow',sans-serif; font-size:15px; }
textarea.fu-input { line-height:1.5; resize:vertical; }
.fu-err { margin-top:9px; background:#fdecea; border:1px solid #f3b3ad; color:#8c1d18; border-radius:6px;
  padding:8px 10px; font-size:14px; }
.fu-modal-foot { padding:11px 14px; border-top:1px solid #e2e5ee; display:flex; gap:8px; justify-content:flex-end; }

@media (max-width:640px) {
  #btp-fu .fu-who { gap:6px; }
  #btp-fu .fu-ord { gap:9px; }
}
</style>

<script>
(function () {
  var CFG   = <?php echo $cfg; ?>;
  var cards = [];
  var filter = 'due_now';
  var sending = null;

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

  function money(n) {
    var v = Number(n || 0);
    return '$' + v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function prettyDate(ymd) {
    if (!ymd) return '';
    var p = String(ymd).split('-');
    if (p.length !== 3) return ymd;
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[parseInt(p[1], 10) - 1] + ' ' + parseInt(p[2], 10);
  }

  var STATUS_TEXT = {
    to_contact: 'To contact', emailed: 'Emailed', replied: 'Replied',
    reordered: 'Reordered', not_this_year: 'Not this year', snoozed: 'Snoozed'
  };

  /* Reordered and "not this year" are done with; they only show under All and
     their own filter, so the working list stays the people still to chase. */
  function matches(card) {
    if (filter === 'all') return true;
    if (card.status === 'reordered') return false;
    if (filter === 'contacted') return card.status === 'emailed' || card.status === 'replied';
    if (filter === 'not_this_year') return card.status === 'not_this_year';
    if (card.status === 'not_this_year' || card.status === 'snoozed') return false;
    return card.bucket === filter;
  }

  function orderUrl(o) {
    return o.printavo_id ? (CFG.printavo + encodeURIComponent(o.printavo_id))
                         : (CFG.search + encodeURIComponent(o.visual_id));
  }

  function cardHtml(c, i) {
    var head = prettyDate(c.next_due) + ': ' + esc(c.events[0].event);
    var st   = c.status || 'to_contact';

    var events = c.events.map(function (e) {
      var orders = e.orders.map(function (o) {
        return '<div class="fu-ord">'
          + '<a href="' + esc(orderUrl(o)) + '" target="_blank" rel="noopener">#' + esc(o.visual_id) + '</a>'
          + '<span>' + esc(o.event) + '</span>'
          + '<span>due ' + esc(o.due_at) + '</span>'
          + '<span>' + (o.qty || 0) + ' pcs</span>'
          + '<span>' + esc(money(o.total)) + '</span>'
          + '<button type="button" class="fu-rename" data-order="' + esc(o.visual_id) + '">rename</button>'
          + '</div>';
      }).join('');
      return '<div class="fu-ev"><div class="fu-ev-name">' + esc(e.event)
           + ' <span style="font-weight:400;color:#6b7389">last year ' + esc(e.last_due) + '</span></div>'
           + orders + '</div>';
    }).join('');

    var reorder = c.reorder
      ? '<div class="fu-reorder">Reordered #' + esc(c.reorder.visual_id) + ' (due ' + esc(c.reorder.due_at) + ')</div>'
      : '';

    var log = (c.log || []).slice(0, 4).map(function (l) {
      return '<div>' + esc(l.at) + ' &middot; ' + esc(l.who) + ' &middot; ' + esc(l.kind) + ': ' + esc(l.detail) + '</div>';
    }).join('');

    return '<div class="fu-card' + (c.status === 'reordered' ? ' is-reordered' : '') + '" data-i="' + i + '">'
      + '<div class="fu-card-top">'
        + '<div class="fu-when">' + head + ' <em>(' + c.days_out + ' days)</em></div>'
        + '<span class="fu-chip s-' + esc(st) + '">' + esc(STATUS_TEXT[st] || st) + '</span>'
      + '</div>'
      + reorder
      + '<div class="fu-who">'
        + '<b>' + esc(c.contact_name || 'No name on file') + '</b>'
        + (c.company ? '<span>' + esc(c.company) + '</span>' : '')
        + '<a href="mailto:' + esc(c.contact_email) + '">' + esc(c.contact_email) + '</a>'
        + (c.contact_phone ? '<a href="tel:' + esc(c.contact_phone) + '">' + esc(c.contact_phone) + '</a>' : '')
      + '</div>'
      + events
      + '<div class="fu-foot">'
        + '<button type="button" class="fu-btn primary" data-act="send">Send email</button>'
        + '<button type="button" class="fu-btn" data-act="replied">Replied</button>'
        + '<button type="button" class="fu-btn" data-act="not_this_year">Not this year</button>'
        + '<button type="button" class="fu-btn" data-act="snooze">Snooze</button>'
        + '<button type="button" class="fu-btn" data-act="to_contact">Reset</button>'
      + '</div>'
      + '<div style="padding:0 12px 10px"><textarea class="fu-notes" rows="2" placeholder="Notes">' + esc(c.notes || '') + '</textarea></div>'
      + (log ? '<div class="fu-log">' + log + '</div>' : '')
      + '</div>';
  }

  function render() {
    var list = document.getElementById('fuList');
    var shown = cards.filter(matches);
    list.innerHTML = shown.map(function (c) { return cardHtml(c, cards.indexOf(c)); }).join('');
    document.getElementById('fuNone').hidden = shown.length > 0;
  }

  function setStatusLine(d) {
    var s = d.sync || {};
    var bits = [];
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

  function cardOf(el) {
    var box = el.closest('.fu-card');
    return box ? cards[parseInt(box.dataset.i, 10)] : null;
  }

  function saveStatus(c, status, snooze) {
    return api('/followup/cards/status', 'POST', {
      contact_email: c.contact_email, cycle_year: c.cycle_year, status: status, snooze_until: snooze || ''
    }).then(load).catch(function (e) { alert(e.message); });
  }

  document.getElementById('fuFilters').addEventListener('click', function (ev) {
    var b = ev.target.closest('.fu-pill');
    if (!b) return;
    filter = b.dataset.filter;
    [].forEach.call(this.querySelectorAll('.fu-pill'), function (p) { p.classList.toggle('active', p === b); });
    render();
  });

  document.getElementById('fuList').addEventListener('click', function (ev) {
    var rename = ev.target.closest('.fu-rename');
    if (rename) {
      var cur = rename.parentNode.querySelector('span') ? rename.parentNode.querySelectorAll('span')[0].textContent : '';
      var next = prompt('What is this event called?', cur);
      if (next === null) return;
      api('/followup/orders/' + encodeURIComponent(rename.dataset.order) + '/event', 'POST', { event: next })
        .then(load).catch(function (e) { alert(e.message); });
      return;
    }

    var btn = ev.target.closest('.fu-btn');
    if (!btn) return;
    var c = cardOf(btn);
    if (!c) return;
    var act = btn.dataset.act;

    if (act === 'send') { openSend(c); return; }
    if (act === 'snooze') {
      var when = prompt('Snooze until when? (YYYY-MM-DD)', '');
      if (!when) return;
      saveStatus(c, 'snoozed', when);
      return;
    }
    saveStatus(c, act);
  });

  document.getElementById('fuList').addEventListener('change', function (ev) {
    var ta = ev.target.closest('.fu-notes');
    if (!ta) return;
    var c = cardOf(ta);
    if (!c) return;
    api('/followup/cards/notes', 'POST', {
      contact_email: c.contact_email, cycle_year: c.cycle_year, notes: ta.value
    }).catch(function (e) { alert(e.message); });
  });

  /* ── the send preview ─────────────────────────────────────────────── */

  function openSend(c) {
    sending = c;
    var modal = document.getElementById('fuSendModal');
    document.getElementById('fuSendErr').hidden = true;
    document.getElementById('fuSendSubject').value = '';
    document.getElementById('fuSendBody').value = 'Loading...';
    document.getElementById('fuSendTo').textContent = c.contact_email;
    document.getElementById('fuSendTitle').textContent = 'Send to ' + (c.contact_name || c.contact_email);
    modal.hidden = false;

    api('/followup/preview', 'POST', { contact_email: c.contact_email, cycle_year: c.cycle_year })
      .then(function (d) {
        document.getElementById('fuSendSubject').value = d.subject || '';
        document.getElementById('fuSendBody').value = d.body || '';
        document.getElementById('fuSendGo').disabled = !d.ready;
        if (!d.ready) showSendErr('Sending is not set up yet, so this cannot go out. See BT Portal > Follow Up.');
      })
      .catch(function (e) { showSendErr(e.message); });
  }

  function showSendErr(msg) {
    var box = document.getElementById('fuSendErr');
    box.textContent = msg;
    box.hidden = false;
  }

  function closeSend() {
    document.getElementById('fuSendModal').hidden = true;
    sending = null;
  }

  document.getElementById('fuSendClose').addEventListener('click', closeSend);
  document.getElementById('fuSendCancel').addEventListener('click', closeSend);

  document.getElementById('fuSendGo').addEventListener('click', function () {
    if (!sending) return;
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Sending...';
    api('/followup/send', 'POST', {
      contact_email: sending.contact_email,
      cycle_year: sending.cycle_year,
      subject: document.getElementById('fuSendSubject').value,
      body: document.getElementById('fuSendBody').value
    }).then(function () {
      btn.disabled = false; btn.textContent = 'Send';
      closeSend();
      return load();
    }).catch(function (e) {
      btn.disabled = false; btn.textContent = 'Send';
      showSendErr(e.message);
    });
  });

  window.btpFollowUpLoad = load;
})();
</script>
    <?php
    return ob_get_clean();
}
