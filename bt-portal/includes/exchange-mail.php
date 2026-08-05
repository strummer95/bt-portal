<?php
/**
 * BT Portal — customer emails when an exchange moves.
 *
 * Two moments matter to a customer who has posted a box off and heard nothing:
 * we've got it, and it's on its way back. Both are sent from the portal, in
 * Boomer T's colours, matching the printed slip they already have.
 *
 * Every send is recorded on the order and guarded by a meta flag, because the
 * status dropdown is a one-click control on a table anyone in the shop can
 * reach — clicking Received twice, or bouncing Shipped -> Received -> Shipped,
 * must not mail the customer again.
 */
if (!defined('ABSPATH')) exit;

/* Boomer T's palette — the navy and pink the portal and the site use, not the
   flat blue on the exchange slip. All three are options so the logo can move
   or the brand can shift without a plugin release. */
function btp_brand( $key ) {
    $defaults = array(
        'navy' => '#1a1f5e',
        'dark' => '#0f1240',
        'pink' => '#e91e8c',
        'logo' => 'https://www.boomerts.com/wp-content/uploads/2024/11/BTs-new-AUgust-2024-logo-1-1.png',
    );
    $val = get_option( 'btp_brand_' . $key, '' );
    if ( $val === '' ) $val = isset($defaults[$key]) ? $defaults[$key] : '';
    return $val;
}

/**
 * Should this status change email the customer, and has it already?
 *
 * Shipped is the awkward one: staff may set the status before pasting the
 * tracking number, or after. Sending immediately means a "here's your parcel"
 * with no number in it; waiting for a number means never sending if one is
 * never entered. So it sends on Shipped either way, and if that first mail
 * went out without a number, adding one later sends exactly one follow-up.
 */
function btp_exchange_mail_decision( $order, $status, $tracking, $prev_status ) {
    if ( ! $order ) return '';
    if ( in_array( $order->get_status(), array('cancelled','refunded','failed','pending','on-hold','trash'), true ) ) return '';

    $tracking = trim( (string) $tracking );

    if ( $status === 'received' ) {
        if ( $order->get_meta('_btp_ex_mailed_received') === 'yes' ) return '';
        return 'received';
    }

    if ( $status === 'shipped' ) {
        $sent = (string) $order->get_meta('_btp_ex_mailed_shipped');
        if ( $sent === '' )                                    return 'shipped';
        if ( $sent === 'no-tracking' && $tracking !== '' )      return 'shipped-tracking';
        return '';
    }

    return '';
}

/** Mark what went out so it cannot go out twice. */
function btp_exchange_mail_record( $order, $kind, $tracking ) {
    if ( $kind === 'received' ) {
        $order->update_meta_data('_btp_ex_mailed_received', 'yes');
    } elseif ( $kind === 'shipped' || $kind === 'shipped-tracking' ) {
        $order->update_meta_data('_btp_ex_mailed_shipped', trim((string) $tracking) === '' ? 'no-tracking' : 'tracking');
    }
    $order->save();
}

/** Items for the email, from the same structured meta the portal reads. */
function btp_exchange_mail_items( $order ) {
    $req = btp_exchange_request_from_meta( $order );
    if ( ! $req ) $req = btp_parse_exchange_note( $order->get_customer_note() );
    return isset($req['items']) ? $req['items'] : array();
}

function btp_exchange_email_html( $order, $kind, $tracking ) {
    $navy     = btp_brand('navy');
    $dark     = btp_brand('dark');
    $pink     = btp_brand('pink');
    $logo     = btp_brand('logo');
    $tracking = trim( (string) $tracking );
    $first    = $order->get_billing_first_name();
    $orig     = (string) $order->get_meta('_bt_original_order');

    if ( $kind === 'received' ) {
        $headline = 'We got your exchange';
        $lead     = 'Your package arrived at the shop. We\'re working on it now and will ship your replacement out as soon as we can.';
        $foot     = 'Exchanges usually take 7&ndash;10 days to process once they reach us. We\'ll email you again the moment yours ships.';
    } else {
        $headline = 'Your exchange is on its way';
        $lead     = 'Your replacement has shipped and is heading back to you.';
        $foot     = $tracking !== ''
            ? 'Tracking can take a day to start showing movement with the carrier.'
            : 'If you need a tracking number, just reply to this email and we\'ll send it over.';
    }

    $rows = '';
    foreach ( btp_exchange_mail_items( $order ) as $it ) {
        $left = trim( $it['name'] . ( $it['color'] !== '' ? ', ' . $it['color'] : '' ) );
        $rows .= '<tr>'
            . '<td style="border-bottom:1px solid #e8eaf0;padding:13px 14px;font-size:15px;color:' . $dark . ';">' . esc_html( $left )
            . ( (int) $it['qty'] > 1 ? ' <span style="color:#5a6380;">&times;' . (int) $it['qty'] . '</span>' : '' ) . '</td>'
            . '<td style="border-bottom:1px solid #e8eaf0;padding:13px 14px;font-size:17px;text-align:center;color:#5a6380;">' . esc_html( $it['size'] ) . '</td>'
            . '<td style="border-bottom:1px solid #e8eaf0;padding:13px 14px;font-size:19px;text-align:center;font-weight:bold;color:' . $pink . ';">' . esc_html( $it['want'] ) . '</td>'
            . '</tr>';
    }

    $table = $rows === '' ? '' : '
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:24px 0;border:1px solid #e8eaf0;border-radius:6px;overflow:hidden;">
        <tr>
          <th align="left" style="background:' . $navy . ';padding:10px 14px;font-size:11px;color:#fff;letter-spacing:1.2px;text-transform:uppercase;">Item</th>
          <th align="center" width="22%" style="background:' . $navy . ';padding:10px 14px;font-size:11px;color:#fff;letter-spacing:1.2px;text-transform:uppercase;">Sent In</th>
          <th align="center" width="22%" style="background:' . $navy . ';padding:10px 14px;font-size:11px;color:#fff;letter-spacing:1.2px;text-transform:uppercase;">Going Out</th>
        </tr>' . $rows . '</table>';

    $track_block = ( $tracking !== '' ) ? '
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:24px 0;">
        <tr><td style="background:#fdf2f8;border:1px solid #f7c6e2;border-left:5px solid ' . $pink . ';border-radius:6px;padding:16px 18px;">
          <span style="font-size:11px;color:' . $navy . ';letter-spacing:1.2px;text-transform:uppercase;">Tracking Number</span><br>
          <span style="font-size:21px;font-weight:bold;color:' . $dark . ';letter-spacing:.5px;">' . esc_html( $tracking ) . '</span>
        </td></tr>
      </table>' : '';

    $logo_img = $logo !== ''
        ? '<img src="' . esc_url( $logo ) . '" alt="Boomer T\'s Ink &amp; Thread" width="190" style="display:block;border:0;max-width:190px;height:auto;">'
        : '<span style="font-size:21px;font-weight:bold;color:#fff;">Boomer T\'s Ink &amp; Thread</span>';

    return '<div style="background:#f4f5f9;padding:24px 12px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;">
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;max-width:640px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(15,18,64,.08);">

        <tr><td style="background:' . $dark . ';padding:22px 28px 20px;border-bottom:4px solid ' . $pink . ';" align="center">
          ' . $logo_img . '
        </td></tr>

        <tr><td style="background:' . $navy . ';padding:9px 28px;">
          <span style="font-size:11px;color:#fff;letter-spacing:1.4px;text-transform:uppercase;opacity:.85;">Exchange WC-' . esc_html( $order->get_id() ) . ( $orig !== '' ? ' &nbsp;&middot;&nbsp; Original order ' . esc_html( $orig ) : '' ) . '</span>
        </td></tr>

        <tr><td style="padding:30px 28px 32px;">
          <h1 style="font-size:25px;line-height:1.25;color:' . $dark . ';margin:0 0 16px;font-weight:800;">' . esc_html( $headline ) . '</h1>
          <p style="font-size:16px;line-height:1.6;color:' . $dark . ';margin:0 0 6px;">' . ( $first ? 'Hi ' . esc_html( $first ) . ',' : 'Hi,' ) . '</p>
          <p style="font-size:16px;line-height:1.6;color:#3a4066;margin:0;">' . $lead . '</p>

          ' . $track_block . $table . '

          <p style="font-size:15px;line-height:1.6;color:#5a6380;margin:20px 0 0;">' . $foot . '</p>
        </td></tr>

        <tr><td style="background:#f4f5f9;border-top:1px solid #e8eaf0;padding:20px 28px;">
          <p style="margin:0 0 6px;font-size:14px;color:' . $dark . ';font-weight:600;">Questions? Just reply to this email.</p>
          <p style="margin:0;font-size:13px;line-height:1.7;color:#5a6380;">
            Boomer T\'s Ink &amp; Thread &nbsp;&middot;&nbsp; ' . esc_html( get_option('btp_exchange_phone', '630-851-0000') ) . '<br>
            1505 Mitchell Dr, Oswego, IL 60543
          </p>
        </td></tr>

      </table>
    </div>';
}

/** Send, record, and note it on the order. Returns true if a mail went out. */
function btp_exchange_send_status_email( $order, $kind, $tracking ) {
    $to = $order->get_billing_email();
    if ( ! is_email( $to ) ) return false;

    $orig  = (string) $order->get_meta('_bt_original_order');
    $ref   = $orig !== '' ? ' (order ' . $orig . ')' : '';
    $subject = ( $kind === 'received' )
        ? "We received your exchange" . $ref
        : "Your exchange has shipped" . $ref;

    $from_name  = get_option('woocommerce_email_from_name', get_bloginfo('name'));
    $from_email = get_option('woocommerce_email_from_address', get_option('admin_email'));

    $sent = wp_mail(
        $to,
        $subject,
        btp_exchange_email_html( $order, $kind, $tracking ),
        array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        )
    );

    if ( ! $sent ) {
        $order->add_order_note( 'Exchange status email to the customer FAILED to send. Check the mail log.' );
        return false;
    }

    btp_exchange_mail_record( $order, $kind, $tracking );
    $order->add_order_note( sprintf(
        'Exchange %s email sent to %s%s.',
        ( $kind === 'received' ? 'received' : 'shipped' ),
        $to,
        ( $kind !== 'received' && trim((string) $tracking) !== '' ? ' with tracking ' . $tracking : '' )
    ) );
    return true;
}
