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

define( 'BTP_EX_BLUE', '#0B4F8F' );

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
    $blue     = BTP_EX_BLUE;
    $tracking = trim( (string) $tracking );
    $first    = $order->get_billing_first_name();
    $orig     = (string) $order->get_meta('_bt_original_order');

    if ( $kind === 'received' ) {
        $headline = 'We received your exchange';
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
            . '<td style="border:1px solid #ddd;padding:11px;font-size:14px;">' . esc_html( $left )
            . ( (int) $it['qty'] > 1 ? ' <span style="color:#666;">&times;' . (int) $it['qty'] . '</span>' : '' ) . '</td>'
            . '<td style="border:1px solid #ddd;padding:11px;font-size:17px;text-align:center;">' . esc_html( $it['size'] ) . '</td>'
            . '<td style="border:1px solid #ddd;padding:11px;font-size:17px;text-align:center;font-weight:bold;color:' . $blue . ';">' . esc_html( $it['want'] ) . '</td>'
            . '</tr>';
    }

    $table = $rows === '' ? '' : '
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:20px 0;">
        <tr>
          <th align="left" style="background:' . $blue . ';padding:7px 11px;font-size:11px;color:#fff;letter-spacing:.4px;">ITEM</th>
          <th align="center" width="22%" style="background:' . $blue . ';padding:7px 11px;font-size:11px;color:#fff;letter-spacing:.4px;">SENT IN</th>
          <th align="center" width="22%" style="background:' . $blue . ';padding:7px 11px;font-size:11px;color:#fff;letter-spacing:.4px;">GOING OUT</th>
        </tr>' . $rows . '</table>';

    $track_block = ( $tracking !== '' ) ? '
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:20px 0;">
        <tr><td style="background:#f7f7f5;border-left:4px solid ' . $blue . ';padding:14px 16px;">
          <span style="font-size:11px;color:#5F5E5A;letter-spacing:.5px;">TRACKING NUMBER</span><br>
          <span style="font-size:20px;font-weight:bold;color:' . $blue . ';">' . esc_html( $tracking ) . '</span>
        </td></tr>
      </table>' : '';

    return '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;color:#1a1a1a;max-width:640px;margin:0 auto;padding:8px;">
      <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
        <tr><td style="border-bottom:3px solid ' . $blue . ';padding-bottom:9px;">
          <span style="font-size:20px;font-weight:bold;color:' . $blue . ';">Boomer T\'s Ink &amp; Thread</span><br>
          <span style="font-size:13px;color:#444;">Exchange WC-' . esc_html( $order->get_id() ) . ( $orig !== '' ? ' &middot; original order ' . esc_html( $orig ) : '' ) . '</span>
        </td></tr>
      </table>

      <h1 style="font-size:22px;color:' . $blue . ';margin:22px 0 10px;">' . esc_html( $headline ) . '</h1>
      <p style="font-size:15px;line-height:1.6;margin:0 0 4px;">' . ( $first ? 'Hi ' . esc_html( $first ) . ',' : 'Hi,' ) . '</p>
      <p style="font-size:15px;line-height:1.6;margin:0;">' . $lead . '</p>

      ' . $track_block . $table . '

      <p style="font-size:14px;line-height:1.6;color:#444;margin:18px 0 0;">' . $foot . '</p>
      <div style="border-top:1px solid #eee;margin-top:24px;padding-top:12px;font-size:12px;color:#888;line-height:1.6;">
        Questions? Reply to this email or call ' . esc_html( get_option('btp_exchange_phone', '630-851-0000') ) . '.<br>
        Boomer T\'s Ink &amp; Thread &middot; Oswego, IL
      </div>
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
