<?php
/**
 * BT Portal - Follow Up email: the template, and sending through Microsoft 365.
 *
 * orders@boomerts.com is a shared mailbox, not a user with a password, so this
 * sends through the Microsoft Graph sendMail call on an app registration:
 * client credentials for a token, application permission Mail.Send, and an
 * ApplicationAccessPolicy in Exchange Online narrowing that permission to the
 * one mailbox. saveToSentItems puts a copy in orders@'s own Sent Items, so
 * everyone sharing the mailbox can see what went out and reply in thread.
 *
 * Why not wp_mail(): this site already sends exchange notices that way, and
 * for a transactional notice to someone expecting it that is fine. Outreach is
 * different. It goes to people who have not written in for a year, from an
 * address customers reply to, and anything that lands in spam is worse than
 * not sending. Graph sends from the mailbox itself, so SPF, DKIM and DMARC all
 * line up with no server-side mail configuration on Lightsail at all.
 *
 * SMTP AUTH would be fewer setup steps, and it is a fair question. It is the
 * worse default here because Microsoft now disables SMTP AUTH per tenant by
 * default, a shared mailbox has no password so it needs a licensed account to
 * send as it, that account's password would sit in the database, and basic
 * auth cannot be scoped to one mailbox the way an ApplicationAccessPolicy can.
 * The setup is longer once; the permission is narrower forever.
 *
 * -- The email -------------------------------------------------------------
 *
 * The template is a setting. The first line is "Subject: ..." and everything
 * after the first blank line is the body. Placeholders:
 *
 *   {First}        first name, or the greeting falls back to "Hi there,"
 *   {Event}        the event, or "A, B and C" when several are due
 *   {Month}        the month the last one ran, or "season" for several
 *   {STORE_LINE}   the online-store offer, only when there is time for it
 *
 * Several events reuse the single-event wording rather than needing their own
 * template: "your {Event} shirts last {Month}" becomes "your A, B and C shirts
 * last season" purely through what those two placeholders expand to. The
 * subject is the one special case, because "A, B and C shirts" is a poor
 * subject line, so it becomes "Your shirts from last year".
 */
if (!defined('ABSPATH')) exit;

define('BTP_FU_GRAPH_LOGIN', 'https://login.microsoftonline.com/');
define('BTP_FU_GRAPH_API',   'https://graph.microsoft.com/v1.0');

/* =========================================================================
   TEMPLATE
   ========================================================================= */

function btp_fu_default_template() {
    return "Subject: {Event} shirts\n"
         . "\n"
         . "Hi {First},\n"
         . "\n"
         . "Hope all is well! We had a lot of fun making your {Event} shirts last {Month}, so I wanted to check in.\n"
         . "\n"
         . "If you need them again this year, we still have your art on file. We can run it the same, update the year or colors, or try something new. Just reply with a rough quantity and date and we'll handle the rest.{STORE_LINE}\n"
         . "\n"
         . "No worries if not, just wanted you to know we're here to help!\n"
         . "\n"
         . "Thanks,\n"
         . "Dillon Johnson\n"
         . "Boomer T's Ink & Thread\n"
         . "1505 Mitchell Dr, Oswego IL\n"
         . "www.boomerts.com";
}

function btp_fu_template() {
    $t = (string) get_option('btp_fu_template', '');
    return trim($t) !== '' ? $t : btp_fu_default_template();
}

/** "A", "A and B", "A, B and C". */
function btp_fu_join_list($items) {
    $items = array_values(array_filter(array_map('trim', (array) $items), 'strlen'));
    $n     = count($items);
    if ($n === 0) return '';
    if ($n === 1) return $items[0];
    if ($n === 2) return $items[0] . ' and ' . $items[1];
    return implode(', ', array_slice($items, 0, $n - 1)) . ' and ' . $items[$n - 1];
}

/**
 * Subject and body for a card.
 *
 * $card is one entry from btp_fu_build_cards(). Returns array(subject, body).
 */
function btp_fu_render_email($card, $template = '') {
    $template = $template !== '' ? $template : btp_fu_template();

    $events = array();
    foreach ((array) $card['events'] as $e) {
        $label = trim((string) $e['event']);
        if ($label !== '' && $label !== 'Last order' && !in_array($label, $events, true)) $events[] = $label;
    }
    $several = count($events) > 1;

    $event_text = $events ? btp_fu_join_list($events) : 'last year\'s';

    /* The month the soonest event last ran. With several, no single month is
       true, and "last season" is what a person would actually say. */
    $month = 'season';
    if (!$several && !empty($card['events'][0]['last_due'])) {
        $t = strtotime($card['events'][0]['last_due']);
        if ($t) $month = date('F', $t);
    }

    $first = function_exists('btp_fu_first_name') ? btp_fu_first_name($card['contact_name']) : '';

    /* Online stores take time to set up, so only offer one when there is time. */
    $weeks      = btp_fu_store_weeks();
    $days_out   = isset($card['days_out']) ? (int) $card['days_out'] : 0;
    $store_line = ($weeks > 0 && $days_out >= $weeks * 7) ? btp_fu_store_line() : '';

    list($subject_tpl, $body_tpl) = btp_fu_split_template($template);

    $map = array(
        '{Event}'      => $event_text,
        '{Month}'      => $month,
        '{STORE_LINE}' => $store_line,
    );

    $subject = strtr($subject_tpl, $map);
    if ($several) $subject = 'Your shirts from last year';

    $body = strtr($body_tpl, $map);

    /* "Hi {First}," with no name on file has to read "Hi there," not "Hi ,". */
    if ($first === '') {
        $body = preg_replace('/\bHi\s*\{First\}\s*,/u', 'Hi there,', $body);
        $body = str_replace('{First}', 'there', $body);
    } else {
        $body = str_replace('{First}', $first, $body);
    }

    return array(trim($subject), rtrim($body));
}

/**
 * First line is "Subject: ...". Everything after the first blank line is the
 * body. A template with no Subject: line is all body, with a plain default.
 */
function btp_fu_split_template($template) {
    $template = str_replace("\r\n", "\n", (string) $template);
    if (preg_match('/^\s*Subject:\s*(.*)$/mi', $template, $m, PREG_OFFSET_CAPTURE)) {
        if ($m[0][1] <= 2) {
            $subject = trim($m[1][0]);
            $rest    = substr($template, $m[0][1] + strlen($m[0][0]));
            return array($subject, ltrim($rest, "\n"));
        }
    }
    return array('{Event} shirts', trim($template));
}

/** Paragraph breaks have to survive into the sent mail. */
function btp_fu_body_html($body) {
    $safe = esc_html((string) $body);
    return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222;">'
         . nl2br($safe)
         . '</div>';
}

/* =========================================================================
   WHICH WAY THE MAIL GOES OUT
   ========================================================================= */

/**
 * 'wp' (default) or 'graph'.
 *
 * WordPress is the default because the portal already sends the exchange
 * notices this way and they arrive, so there is nothing to set up and the
 * page is usable the day it lands. Microsoft Graph is the upgrade for when
 * outreach starts landing in spam, which is a real risk this mail has and the
 * exchange notices do not: an exchange notice goes to somebody who is waiting
 * for it, and this goes to somebody who has not heard from the shop in a year.
 *
 * Whether WordPress is good enough here comes down to what is behind wp_mail()
 * on this site. An SMTP plugin pointed at Microsoft 365 is already properly
 * authenticated and Graph would add nothing. Raw PHP mail() straight off the
 * Lightsail box is not authenticated for boomerts.com at all, and sending
 * outreach that way is how a domain's reputation gets damaged. Checking that
 * is a job for the WordPress admin, not something this file can tell.
 */
function btp_fu_transport() {
    $t = (string) get_option('btp_fu_transport', 'wp');
    return $t === 'graph' ? 'graph' : 'wp';
}

/** Can we send at all right now? */
function btp_fu_mail_ready() {
    return btp_fu_transport() === 'graph' ? btp_fu_graph_ready() : true;
}

function btp_fu_from_name() {
    return (string) get_option('btp_fu_from_name', "Boomer T's Ink & Thread");
}

/** Send, whichever way is turned on. Returns true or WP_Error. */
function btp_fu_send_mail($to, $subject, $body_text) {
    if (btp_fu_transport() === 'graph') {
        return btp_fu_graph_send($to, $subject, $body_text, btp_fu_sender());
    }
    return btp_fu_wp_send($to, $subject, $body_text);
}

/**
 * The same wp_mail() the exchange notices use.
 *
 * Reply-To is set as well as From: if this site turns out to send through a
 * relay that rewrites the From address, the reply still has to reach orders@,
 * because a reply is the entire point of the email.
 */
function btp_fu_wp_send($to, $subject, $body_text) {
    $from = btp_fu_sender();
    $sent = wp_mail(
        $to,
        $subject,
        btp_fu_body_html($body_text),
        array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . btp_fu_from_name() . ' <' . $from . '>',
            'Reply-To: ' . btp_fu_from_name() . ' <' . $from . '>',
        )
    );
    if (!$sent) {
        return new WP_Error('btp_fu_wp_mail_failed',
            'WordPress could not send the email. The same thing would stop the exchange notices going out, so check the site mail settings.');
    }
    return true;
}

/* =========================================================================
   MICROSOFT GRAPH
   ========================================================================= */

function btp_fu_graph_config() {
    $secret = (string) get_option('btp_fu_graph_secret', '');
    if ($secret !== '' && function_exists('btp_vendor_decrypt')) $secret = btp_vendor_decrypt($secret);
    return array(
        'tenant' => trim((string) get_option('btp_fu_graph_tenant', '')),
        'client' => trim((string) get_option('btp_fu_graph_client', '')),
        'secret' => $secret,
        'sender' => btp_fu_sender(),
    );
}

function btp_fu_graph_ready() {
    $c = btp_fu_graph_config();
    return $c['tenant'] !== '' && $c['client'] !== '' && $c['secret'] !== '' && $c['sender'] !== '';
}

/**
 * App-only access token, cached until shortly before it expires.
 *
 * Cached in a transient rather than re-fetched per send, because a card with
 * several events is still one send but a working session is many.
 */
function btp_fu_graph_token($fresh = false) {
    $c = btp_fu_graph_config();
    if (!btp_fu_graph_ready()) {
        return new WP_Error('btp_fu_graph_unset', 'Microsoft 365 sending is not set up yet (BT Portal > Follow Up).');
    }

    if (!$fresh) {
        $cached = get_transient('btp_fu_graph_token');
        if (is_string($cached) && $cached !== '') return $cached;
    }

    $res = wp_remote_post(BTP_FU_GRAPH_LOGIN . rawurlencode($c['tenant']) . '/oauth2/v2.0/token', array(
        'timeout' => 20,
        'body'    => array(
            'client_id'     => $c['client'],
            'client_secret' => $c['secret'],
            'scope'         => 'https://graph.microsoft.com/.default',
            'grant_type'    => 'client_credentials',
        ),
    ));
    if (is_wp_error($res)) return $res;

    $code = (int) wp_remote_retrieve_response_code($res);
    $body = json_decode(wp_remote_retrieve_body($res), true);

    if ($code !== 200 || empty($body['access_token'])) {
        $detail = isset($body['error_description']) ? $body['error_description'] : wp_remote_retrieve_body($res);
        /* Never let a secret ride along in an error shown in the browser. */
        return new WP_Error('btp_fu_graph_token', 'Microsoft rejected the app credentials (HTTP ' . $code . '). ' . wp_strip_all_tags((string) $detail));
    }

    $ttl = isset($body['expires_in']) ? max(60, (int) $body['expires_in'] - 120) : 1800;
    set_transient('btp_fu_graph_token', $body['access_token'], $ttl);
    return $body['access_token'];
}

/**
 * Send one message as the shared mailbox.
 *
 * Returns true, or WP_Error. A 403 here almost always means the
 * ApplicationAccessPolicy does not cover this mailbox, which is worth saying
 * plainly rather than leaving as a status code.
 */
function btp_fu_graph_send($to, $subject, $body_text, $reply_to = '') {
    $token = btp_fu_graph_token();
    if (is_wp_error($token)) return $token;

    $c   = btp_fu_graph_config();
    $msg = array(
        'message' => array(
            'subject'      => (string) $subject,
            'body'         => array('contentType' => 'HTML', 'content' => btp_fu_body_html($body_text)),
            'toRecipients' => array(array('emailAddress' => array('address' => (string) $to))),
        ),
        'saveToSentItems' => true,
    );
    if ($reply_to !== '') {
        $msg['message']['replyTo'] = array(array('emailAddress' => array('address' => $reply_to)));
    }

    $url = BTP_FU_GRAPH_API . '/users/' . rawurlencode($c['sender']) . '/sendMail';
    $res = wp_remote_post($url, array(
        'timeout' => 25,
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ),
        'body' => wp_json_encode($msg),
    ));
    if (is_wp_error($res)) return $res;

    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code === 202) return true;

    $body = json_decode(wp_remote_retrieve_body($res), true);
    $why  = isset($body['error']['message']) ? $body['error']['message'] : wp_remote_retrieve_body($res);

    if ($code === 401) {
        /* A token can go stale early if the secret was rotated. One retry. */
        delete_transient('btp_fu_graph_token');
        return new WP_Error('btp_fu_send_401', 'Microsoft rejected the token. If the client secret was just rotated, save the new one and try again.');
    }
    if ($code === 403) {
        return new WP_Error('btp_fu_send_403',
            'Microsoft refused to send as ' . $c['sender'] . '. The app has Mail.Send but is not allowed this mailbox: check the ApplicationAccessPolicy in Exchange Online. (' . wp_strip_all_tags((string) $why) . ')');
    }
    return new WP_Error('btp_fu_send_fail', 'Microsoft Graph returned HTTP ' . $code . '. ' . wp_strip_all_tags((string) $why));
}
