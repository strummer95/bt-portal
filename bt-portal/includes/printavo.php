<?php
/**
 * BT Portal — Printavo deep links.
 *
 * Resolves a visible order number (visualId) to a Printavo record and returns
 * the deep-link URL. The frontend IIFE in shortcode.php calls this endpoint.
 *
 * Ported from the standalone "Printavo Connection - Backend" WPCode snippet.
 * Fix on port: the old lookup only searched `quotes`, so once a quote was
 * converted to an invoice it fell out of that collection and the click failed
 * with "not found in Printavo". Now searches both.
 *
 * GET /wp-json/boomerts/v1/printavo-link/{order_number}
 *   ?fresh=1     bypass cache
 *   ?debug=1     diagnostic payload
 *   ?redirect=1  302 to Printavo instead of JSON
 */
if (!defined('ABSPATH')) exit;

define('BTP_PRINTAVO_GQL',      'https://www.printavo.com/api/v2');
define('BTP_PRINTAVO_BASE',       'https://www.printavo.com/invoices/');
// Printavo serves quotes under /invoices/{id} as well - verified against a real
// quote (visualId 36825 -> id 22855032). Do not 'fix' this to /quotes/.
define('BTP_PRINTAVO_QUOTE_BASE', 'https://www.printavo.com/invoices/');
define('BTP_PRINTAVO_SEARCH',     'https://www.printavo.com/search?query=');
define('BTP_PRINTAVO_TTL_HIT',  DAY_IN_SECONDS);
define('BTP_PRINTAVO_TTL_MISS', 5 * MINUTE_IN_SECONDS);

/* ── credentials ─────────────────────────────────────────────────────── */

/**
 * Reads credentials, falling back to the option names the old WPCode snippet
 * may have used so nothing has to be re-entered after the port.
 */
function btp_printavo_creds() {
    $email_keys = array('btp_printavo_email', 'btpc_email', 'bt_printavo_email', 'btpc_printavo_email', 'boomerts_printavo_email', 'printavo_email');
    $token_keys = array('btp_printavo_token', 'btpc_token', 'bt_printavo_token', 'btpc_printavo_token', 'boomerts_printavo_token', 'printavo_token');

    $email = '';
    $token = '';

    $email_key = '';
    $token_key = '';

    foreach ($email_keys as $k) {
        $v = get_option($k);
        if (!empty($v)) { $email = trim($v); $email_key = $k; break; }
    }
    foreach ($token_keys as $k) {
        $v = get_option($k);
        if (!empty($v)) { $token = trim($v); $token_key = $k; break; }
    }

    return array('email' => $email, 'token' => $token, 'email_key' => $email_key, 'token_key' => $token_key);
}

/* ── transport ───────────────────────────────────────────────────────── */

function btp_printavo_gql($query, $vars = array()) {
    $creds = btp_printavo_creds();

    if (empty($creds['email']) || empty($creds['token'])) {
        return new WP_Error('btp_printavo_no_creds', 'Printavo email/token are not set (BT Portal > Printavo).');
    }

    $res = wp_remote_post(BTP_PRINTAVO_GQL, array(
        'timeout' => 15,
        'headers' => array(
            'Content-Type' => 'application/json',
            'email'        => $creds['email'],
            'token'        => $creds['token'],
        ),
        'body'    => wp_json_encode(array('query' => $query, 'variables' => $vars)),
    ));

    if (is_wp_error($res)) return $res;

    $code = wp_remote_retrieve_response_code($res);
    $body = json_decode(wp_remote_retrieve_body($res), true);

    if ($code === 401 || $code === 403) {
        return new WP_Error('btp_printavo_auth', 'Printavo rejected the credentials (HTTP ' . $code . '). The token may have expired.');
    }
    if (!is_array($body)) {
        return new WP_Error('btp_printavo_bad_body', 'Unexpected response from Printavo (HTTP ' . $code . ').');
    }

    return $body;
}

/**
 * The query argument is fuzzy, so only accept a node whose visualId matches
 * exactly. Returns the node with its __typename so the caller can build the
 * right URL — quotes live at /quotes/{id}, invoices at /invoices/{id}.
 */
function btp_printavo_match($body, $path, $order_number) {
    if (empty($body['data'][$path]['nodes']) || !is_array($body['data'][$path]['nodes'])) return null;
    foreach ($body['data'][$path]['nodes'] as $node) {
        if (empty($node['id'])) continue;
        if (isset($node['visualId']) && (string) $node['visualId'] === (string) $order_number) return $node;
    }
    return null;
}

/**
 * Quotes and Invoices are different URLs in the Printavo UI.
 */
function btp_printavo_url_for($node, $fallback_type = '') {
    $type = '';
    if (!empty($node['__typename'])) {
        $type = strtolower($node['__typename']);
    } elseif ($fallback_type) {
        $type = strtolower($fallback_type);
    }
    $path = ($type === 'quote') ? BTP_PRINTAVO_QUOTE_BASE : BTP_PRINTAVO_BASE;
    return $path . rawurlencode($node['id']);
}

/* ── lookup ──────────────────────────────────────────────────────────── */

function btp_printavo_lookup($order_number, $fresh = false) {
    $order_number = preg_replace('/[^0-9]/', '', (string) $order_number);
    if ($order_number === '') {
        return array('found' => false, 'source' => 'none', 'url' => '', 'order_number' => '', 'cached' => false, 'error' => 'Empty order number.');
    }

    $cache_key = 'btp_pv_' . $order_number;

    if (!$fresh) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            $cached['cached'] = true;
            return $cached;
        }
    }

    $out = array(
        'found'        => false,
        'source'       => 'none',
        'url'          => BTP_PRINTAVO_SEARCH . rawurlencode($order_number),
        'order_number' => $order_number,
        'cached'       => false,
        'error'        => '',
    );

    /*
     * Quotes and Invoices are joined into the OrderUnion type, so one `orders`
     * query covers both — which is the point, since an order moves from one to
     * the other when it gets invoiced.
     *
     * The search argument is `query`, NOT `searchTerm`. Getting that wrong
     * makes the whole request an "unknown argument" error and every lookup
     * silently returns nothing.
     */
    $combined = 'query BTLookup($num: String!) {
        orders(first: 10, query: $num) {
            nodes {
                __typename
                ... on Quote { id visualId }
                ... on Invoice { id visualId }
            }
        }
    }';

    $body = btp_printavo_gql($combined, array('num' => $order_number));

    if (is_wp_error($body)) {
        $out['error'] = $body->get_error_message();
        set_transient($cache_key, $out, BTP_PRINTAVO_TTL_MISS);
        return $out;
    }

    $node = btp_printavo_match($body, 'orders', $order_number);
    if ($node) {
        $out['source'] = !empty($node['__typename']) ? strtolower($node['__typename']) : 'order';
        $out['url']    = btp_printavo_url_for($node);
    }

    // If the union query errored, fall back to each collection on its own.
    if (!$node && !empty($body['errors'])) {
        $singles = array(
            'invoice' => array('invoices', 'query BTInvoice($num: String!) { invoices(first: 10, query: $num) { nodes { id visualId } } }'),
            'quote'   => array('quotes',   'query BTQuote($num: String!) { quotes(first: 10, query: $num) { nodes { id visualId } } }'),
        );
        foreach ($singles as $label => $pair) {
            list($path, $q) = $pair;
            $single = btp_printavo_gql($q, array('num' => $order_number));
            if (is_wp_error($single)) continue;
            $hit = btp_printavo_match($single, $path, $order_number);
            if ($hit) {
                $node          = $hit;
                $out['source'] = $label;
                $out['url']    = btp_printavo_url_for($hit, $label);
                break;
            }
        }
        if (!$node) $out['error'] = 'Printavo returned errors: ' . wp_json_encode($body['errors']);
    }

    if ($node) {
        $out['found'] = true;
        set_transient($cache_key, $out, BTP_PRINTAVO_TTL_HIT);
    } else {
        set_transient($cache_key, $out, BTP_PRINTAVO_TTL_MISS);
    }

    return $out;
}

function btp_printavo_flush_cache() {
    global $wpdb;
    return (int) $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\_transient\_btp\_pv\_%'
            OR option_name LIKE '\_transient\_timeout\_btp\_pv\_%'
            OR option_name LIKE '\_transient\_bt\_pv\_%'
            OR option_name LIKE '\_transient\_timeout\_bt\_pv\_%'
            OR option_name LIKE '\_transient\_btpc\_link\_%'
            OR option_name LIKE '\_transient\_timeout\_btpc\_link\_%'"
    );
}

/* ── REST ────────────────────────────────────────────────────────────── */

add_action('rest_api_init', function() {
    register_rest_route('boomerts/v1', '/printavo-link/(?P<order_number>[0-9]+)', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function($request) {
            $result = btp_printavo_lookup(
                $request->get_param('order_number'),
                (bool) $request->get_param('fresh')
            );

            if ($request->get_param('debug')) {
                $creds = btp_printavo_creds();
                $result['creds_email_set'] = !empty($creds['email']);
                $result['creds_token_set'] = !empty($creds['token']);
                $result['creds_email_key'] = $creds['email_key'];
                $result['creds_token_key'] = $creds['token_key'];
                return new WP_REST_Response($result, 200);
            }

            if ($request->get_param('redirect')) {
                wp_redirect($result['url'], 302);
                exit;
            }

            // Always 200 with a usable url — a miss falls back to Printavo search
            // rather than dead-ending the click.
            return new WP_REST_Response(array(
                'found'        => (bool) $result['found'],
                'url'          => $result['url'],
                'source'       => $result['source'],
                'order_number' => $result['order_number'],
                'cached'       => !empty($result['cached']),
            ), 200);
        },
    ));
});

/* ── admin: BT Portal > Printavo ─────────────────────────────────────── */

add_action('admin_menu', function() {
    add_submenu_page(
        'bt-portal',
        'Printavo',
        'Printavo',
        'manage_options',
        'bt-portal-printavo',
        'btp_printavo_admin_page'
    );
}, 20);

function btp_printavo_admin_page() {
    if (!current_user_can('manage_options')) return;

    $notice = '';

    if (isset($_POST['btp_printavo_save']) && check_admin_referer('btp_printavo_save')) {
        update_option('btp_printavo_email', sanitize_text_field(wp_unslash($_POST['btp_printavo_email'])));
        $token = trim(wp_unslash($_POST['btp_printavo_token']));
        if ($token !== '') update_option('btp_printavo_token', sanitize_text_field($token));
        btp_printavo_flush_cache();
        $notice = 'Saved. Cached lookups flushed.';
    }

    if (isset($_POST['btp_printavo_flush']) && check_admin_referer('btp_printavo_save')) {
        $notice = 'Flushed ' . btp_printavo_flush_cache() . ' cached row(s).';
    }

    $test = null;
    if (isset($_POST['btp_printavo_test']) && check_admin_referer('btp_printavo_save')) {
        $num = preg_replace('/[^0-9]/', '', wp_unslash($_POST['btp_printavo_test_number']));
        if ($num) $test = btp_printavo_lookup($num, true);
    }

    $creds = btp_printavo_creds();
    ?>
    <div class="wrap">
        <h1>Printavo</h1>
        <p>Order numbers on job cards resolve through this connection. Tokens expire yearly.</p>
        <p><em>Reading credentials from:
            <code><?php echo esc_html($creds['email_key'] ?: 'nothing — email not set'); ?></code> /
            <code><?php echo esc_html($creds['token_key'] ?: 'nothing — token not set'); ?></code></em></p>

        <?php if ($notice): ?><div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('btp_printavo_save'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="btp_printavo_email">Printavo email</label></th>
                    <td><input type="text" class="regular-text" id="btp_printavo_email" name="btp_printavo_email"
                        value="<?php echo esc_attr($creds['email']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="btp_printavo_token">API token</label></th>
                    <td>
                        <input type="password" class="regular-text" id="btp_printavo_token" name="btp_printavo_token" value=""
                            placeholder="<?php echo $creds['token'] ? 'saved — leave blank to keep' : 'not set'; ?>">
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" name="btp_printavo_save" class="button button-primary">Save</button>
                <button type="submit" name="btp_printavo_flush" class="button">Flush cached lookups</button>
            </p>

            <hr>
            <h2>Test a lookup</h2>
            <p>
                <input type="text" name="btp_printavo_test_number" placeholder="36879">
                <button type="submit" name="btp_printavo_test" class="button">Test</button>
            </p>
        </form>

        <?php if (is_array($test)): ?>
            <h3>Result</h3>
            <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;overflow:auto"><?php echo esc_html(print_r($test, true)); ?></pre>
        <?php endif; ?>
    </div>
    <?php
}
