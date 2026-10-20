<?php
/**
 * BT Portal - Blank Orders (Other > Blank Orders).
 *
 * Turns a stack of OrderMyGear and Chipply order sheets into a blank order.
 * Someone circles the lines that need blanks in red, then flips through the
 * stack in front of a phone. Each page is photographed, read, and the red
 * circled lines come back as qty / item code / product / color / size /
 * order / store. Item codes map to the real blank (vendor + style), and the
 * page ends in one tally per vendor plus a per-order checklist to verify it.
 *
 * ── Capture ───────────────────────────────────────────────────────────────
 *
 * The phone camera runs in the page (getUserMedia). Auto mode watches a tiny
 * 96x72 grayscale copy of the video: a page flip is a burst of movement, and
 * a page that has landed is a stretch of stillness. When the picture has been
 * still long enough, one full size frame is taken. It will not take another
 * until the picture moves again, and a still frame that matches the last
 * capture is skipped, so a bumped table does not photograph the same sheet
 * twice. The shutter button and "Add photos" (the phone's own camera app,
 * full resolution) are always there as well.
 *
 * The thresholds were measured, not picked. Order sheets are mostly white
 * paper on the same template, so a plain pixel difference cannot tell a new
 * sheet from the same sheet nudged a few millimetres: both score about the
 * same. The same-page test therefore compares with small shifts allowed and
 * the brightness levelled, which puts different sheets at 7 and up and the
 * same sheet moved up to ~2% of its width under 4. A page slid over a near
 * identical one moves the picture by only ~8, so the motion that re-arms the
 * camera is set at 8. A hand flipping paper scores far higher. Behind both,
 * two pages that read back as the same order with the same lines are flagged
 * as a likely double photo, since that would double the blanks ordered.
 *
 * ── Reading ───────────────────────────────────────────────────────────────
 *
 * Each photo is sent from the SERVER to the Claude API with a JSON schema for
 * the answer. The API key lives in wp-config (BTP_ANTHROPIC_KEY) or is saved
 * encrypted under BT Portal > Blank Orders, using the vendor password key. It
 * never reaches the browser. Raw HTTP through wp_remote_post rather than the
 * PHP SDK, because the plugin ships as a zip with no Composer and the shop has
 * no shell to install one.
 *
 * One page per request, driven by the browser, because a read takes 10 to 60
 * seconds and PHP on Lightsail will not sit through a whole stack in one
 * request. A page is claimed before it is read (status 'reading'), so two
 * screens open on the same batch never pay to read the same page twice. A
 * claim older than five minutes is treated as dead.
 *
 * Nothing is guessed. A line the reader is unsure of comes back flagged low
 * with a note, a code with no mapping lands in Need mapping, and every line
 * can be corrected by hand. Reading a page again replaces every line that
 * came from that page, corrections included, because there is no reliable
 * way to pair a corrected line with its fresh reading; the screen warns
 * first. Lines added by hand belong to no page and are never touched.
 *
 * Photos are stored under uploads/btp-blanks/<batch>/ with random names and
 * served only through the signed-in REST route. Bitnami turns .htaccess off,
 * so the deny file there is a second line of defence, not the first.
 */
if (!defined('ABSPATH')) exit;

define('BTP_BLANKS_DB_VERSION', '1');
if (!defined('BTP_BLANKS_MODEL')) define('BTP_BLANKS_MODEL', 'claude-opus-5-5');

/* ─────────────────────────────────────────────────────────────────────────
   TABLES
   ───────────────────────────────────────────────────────────────────── */

function btp_blanks_table($name) { global $wpdb; return $wpdb->prefix . 'bt_blank_' . $name; }

function btp_blanks_install() {
    if (get_option('btp_blanks_db_version') === BTP_BLANKS_DB_VERSION) return;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $GLOBALS['wpdb']->get_charset_collate();
    $b = btp_blanks_table('batches');
    $p = btp_blanks_table('pages');
    $i = btp_blanks_table('items');

    dbDelta("CREATE TABLE IF NOT EXISTS $b (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(190) NOT NULL DEFAULT '',
        created_by VARCHAR(120) NOT NULL DEFAULT '',
        created_at DATETIME NULL,
        PRIMARY KEY (id)
    ) $charset;");

    dbDelta("CREATE TABLE IF NOT EXISTS $p (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        batch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        file VARCHAR(190) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        form VARCHAR(30) NOT NULL DEFAULT '',
        order_id VARCHAR(64) NOT NULL DEFAULT '',
        store VARCHAR(190) NOT NULL DEFAULT '',
        note TEXT NULL,
        error TEXT NULL,
        created_by VARCHAR(120) NOT NULL DEFAULT '',
        created_at DATETIME NULL,
        read_started DATETIME NULL,
        read_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY batch_id (batch_id)
    ) $charset;");

    dbDelta("CREATE TABLE IF NOT EXISTS $i (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        batch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        qty INT NOT NULL DEFAULT 0,
        code VARCHAR(64) NOT NULL DEFAULT '',
        product VARCHAR(255) NOT NULL DEFAULT '',
        color VARCHAR(120) NOT NULL DEFAULT '',
        size VARCHAR(40) NOT NULL DEFAULT '',
        order_id VARCHAR(64) NOT NULL DEFAULT '',
        store VARCHAR(190) NOT NULL DEFAULT '',
        confidence VARCHAR(10) NOT NULL DEFAULT 'high',
        note TEXT NULL,
        checked TINYINT(1) NOT NULL DEFAULT 0,
        excluded TINYINT(1) NOT NULL DEFAULT 0,
        manual TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY batch_id (batch_id),
        KEY page_id (page_id)
    ) $charset;");

    update_option('btp_blanks_db_version', BTP_BLANKS_DB_VERSION);
}
add_action('init', 'btp_blanks_install', 2);

/* ─────────────────────────────────────────────────────────────────────────
   CODE MAP: store item code -> the real blank
   ───────────────────────────────────────────────────────────────────── */

/** "[5100]", " 5100 ", "#5100" all key the same. */
function btp_blanks_norm_code($s) {
    return strtoupper(preg_replace('/[\s\[\]#]+/', '', (string) $s));
}

function btp_blanks_codes() {
    $codes = get_option('btp_blank_codes', null);
    if (!is_array($codes)) {
        $codes = array(
            '5100' => array('vendor' => 'S&S', 'style' => 'C2 Sport 5100', 'name' => 'C2 Sport performance tee'),
        );
        update_option('btp_blank_codes', $codes, false);
    }
    return $codes;
}

/* ─────────────────────────────────────────────────────────────────────────
   API KEY (server only)
   ───────────────────────────────────────────────────────────────────── */

function btp_blanks_api_key() {
    if (defined('BTP_ANTHROPIC_KEY') && BTP_ANTHROPIC_KEY) return trim(BTP_ANTHROPIC_KEY);
    $blob = get_option('btp_blanks_api_key', '');
    if (!$blob || !function_exists('btp_vendor_decrypt')) return '';
    return trim(btp_vendor_decrypt($blob));
}

/* ─────────────────────────────────────────────────────────────────────────
   PHOTO FILES
   ───────────────────────────────────────────────────────────────────── */

function btp_blanks_dir($batch_id) {
    $up   = wp_upload_dir();
    $root = trailingslashit($up['basedir']) . 'btp-blanks';
    if (!is_dir($root)) {
        wp_mkdir_p($root);
        @file_put_contents($root . '/index.php', "<?php // Silence.\n");
        @file_put_contents($root . '/.htaccess', "Require all denied\n");
    }
    $dir = $root . '/' . (int) $batch_id;
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
        @file_put_contents($dir . '/index.php', "<?php // Silence.\n");
    }
    return $dir;
}

function btp_blanks_file_path($page) {
    return btp_blanks_dir($page['batch_id']) . '/' . basename($page['file']);
}

/* ─────────────────────────────────────────────────────────────────────────
   READING A PAGE
   ───────────────────────────────────────────────────────────────────── */

function btp_blanks_prompt() {
    return <<<TXT
You read photographed order sheets for Boomer T's Ink & Thread, a screen print and embroidery shop. The sheets are printouts from OrderMyGear (OMG) or Chipply online stores.

Someone has circled certain line items in red (pen or marker; occasionally a red check, bracket or underline instead of a circle). Only those red-marked lines matter. Ignore every line that is not marked in red, and ignore marks in any other color.

For each red-marked line item return:
- qty: the quantity ordered on that line, as an integer.
- code: the store item code exactly as printed, without the brackets. Item codes are usually in square brackets in or next to the product name or in a SKU column, so "[5100]" becomes "5100".
- product: the product name as printed.
- color and size: as printed (for example "Navy", "Adult Medium", "YL", "2XL").
- order_id: the order number this line belongs to. On a sheet for a single order it is in the header, so copy it onto each line. On a sheet that lists several orders, use the order the line sits under.
- store: the sale or store name.
- confidence: "low" if any field on the line is hard to read, blurry, cut off, partly covered by the red mark, ambiguous (for example a circle that touches two lines), or changed by hand. Otherwise "high".
- note: one short plain sentence explaining a low confidence or anything odd, such as a crossed out line or a handwritten quantity. Empty string otherwise.

Never guess or invent a value. If a field cannot be read, return an empty string (or 0 for qty) and say so in the note. If one circle clearly covers several lines, return each of them.

Also return, for the page as a whole:
- form: "OrderMyGear", "Chipply", "Other" or "Unknown".
- order_id and store: from the page header, or empty strings if the header is not on this page (for example a continuation page).
- page_note: anything about the whole photo that the person should know, such as blurry, cut off, two pages in one photo, or no red marks found. Empty string if the page read cleanly.

If there are no red marks on the page, return an empty items list and say so in page_note.
TXT;
}

function btp_blanks_schema() {
    $str = array('type' => 'string');
    return array(
        'type' => 'object',
        'additionalProperties' => false,
        'required' => array('form', 'order_id', 'store', 'page_note', 'items'),
        'properties' => array(
            'form'      => array('type' => 'string', 'enum' => array('OrderMyGear', 'Chipply', 'Other', 'Unknown')),
            'order_id'  => $str,
            'store'     => $str,
            'page_note' => $str,
            'items'     => array(
                'type'  => 'array',
                'items' => array(
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => array('qty', 'code', 'product', 'color', 'size', 'order_id', 'store', 'confidence', 'note'),
                    'properties' => array(
                        'qty'        => array('type' => 'integer'),
                        'code'       => $str,
                        'product'    => $str,
                        'color'      => $str,
                        'size'       => $str,
                        'order_id'   => $str,
                        'store'      => $str,
                        'confidence' => array('type' => 'string', 'enum' => array('high', 'low')),
                        'note'       => $str,
                    ),
                ),
            ),
        ),
    );
}

/** One Messages API call. Returns the decoded answer array or a WP_Error. */
function btp_blanks_call_claude($key, $jpeg_bytes) {
    $body = array(
        'model'         => BTP_BLANKS_MODEL,
        'max_tokens'    => 16000,
        'fallbacks'     => 'default',
        'system'        => btp_blanks_prompt(),
        'output_config' => array(
            'effort' => 'medium',
            'format' => array('type' => 'json_schema', 'schema' => btp_blanks_schema()),
        ),
        'messages' => array(array(
            'role'    => 'user',
            'content' => array(
                array('type' => 'image', 'source' => array(
                    'type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($jpeg_bytes),
                )),
                array('type' => 'text', 'text' => 'Read the red-marked line items on this order sheet.'),
            ),
        )),
    );

    $post = function ($body) use ($key) {
        $headers = array(
            'content-type'      => 'application/json',
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
        );
        if (isset($body['fallbacks'])) $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
        return wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 170,
            'headers' => $headers,
            'body'    => wp_json_encode($body),
        ));
    };

    $res = $post($body);
    // The refusal fallback is a beta. If the API ever turns it away, read
    // without it rather than failing every page.
    if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 400
        && stripos(wp_remote_retrieve_body($res), 'fallback') !== false) {
        unset($body['fallbacks']);
        $res = $post($body);
    }

    if (is_wp_error($res)) {
        return new WP_Error('btp_blanks_net', 'Could not reach the reader: ' . $res->get_error_message(), array('status' => 502));
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $json = json_decode(wp_remote_retrieve_body($res), true);

    if ($code !== 200) {
        $msg = (is_array($json) && isset($json['error']['message'])) ? $json['error']['message'] : ('HTTP ' . $code);
        if ($code === 401 || $code === 403) $msg = 'The API key was refused. Check it under BT Portal > Blank Orders.';
        elseif ($code === 429 || $code === 529 || $code >= 500) $msg = 'The reader is busy right now (' . $code . '). Try this page again in a minute.';
        return new WP_Error('btp_blanks_api', $msg, array('status' => 502));
    }

    $stop = isset($json['stop_reason']) ? $json['stop_reason'] : '';
    if ($stop === 'refusal') {
        return new WP_Error('btp_blanks_refused', 'The reader declined this photo. Retake it and try again.', array('status' => 502));
    }
    if ($stop === 'max_tokens') {
        return new WP_Error('btp_blanks_long', 'This page had more than the reader could finish. Split it into two photos.', array('status' => 502));
    }

    $text = '';
    foreach ((array) (isset($json['content']) ? $json['content'] : array()) as $block) {
        if (isset($block['type']) && $block['type'] === 'text') { $text = $block['text']; break; }
    }
    $data = json_decode($text, true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        return new WP_Error('btp_blanks_parse', 'The reader answered but not in the expected shape. Try this page again.', array('status' => 502));
    }
    return $data;
}

/** Reads one stored page and replaces its machine-read lines. */
function btp_blanks_read_page($page_id) {
    global $wpdb;
    $pt = btp_blanks_table('pages');
    $it = btp_blanks_table('items');

    $page = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pt WHERE id = %d", $page_id), ARRAY_A);
    if (!$page) return new WP_Error('btp_blanks_missing', 'That page is gone.', array('status' => 404));

    $key = btp_blanks_api_key();
    if ($key === '') {
        return new WP_Error('btp_blanks_nokey', 'Reading is not set up yet. A WordPress admin needs to add the API key under BT Portal > Blank Orders.', array('status' => 503));
    }

    $now   = gmdate('Y-m-d H:i:s');
    $stale = gmdate('Y-m-d H:i:s', time() - 300);
    $claimed = $wpdb->query($wpdb->prepare(
        "UPDATE $pt SET status = 'reading', read_started = %s, error = ''
         WHERE id = %d AND (status <> 'reading' OR read_started IS NULL OR read_started < %s)",
        $now, $page_id, $stale
    ));
    if (!$claimed) return new WP_Error('btp_blanks_busy', 'This page is already being read.', array('status' => 409));

    $fail = function ($err) use ($wpdb, $pt, $page_id) {
        $wpdb->update($pt, array('status' => 'error', 'error' => $err->get_error_message()), array('id' => $page_id));
        return $err;
    };

    $bytes = @file_get_contents(btp_blanks_file_path($page));
    if ($bytes === false || $bytes === '') {
        return $fail(new WP_Error('btp_blanks_nofile', 'The photo for this page is missing. Delete it and retake it.', array('status' => 410)));
    }

    if (function_exists('set_time_limit')) @set_time_limit(240);
    $data = btp_blanks_call_claude($key, $bytes);
    if (is_wp_error($data)) return $fail($data);

    $p_order = trim((string) (isset($data['order_id']) ? $data['order_id'] : ''));
    $p_store = trim((string) (isset($data['store']) ? $data['store'] : ''));

    $wpdb->query($wpdb->prepare("DELETE FROM $it WHERE page_id = %d", $page_id));
    $n = 0;
    foreach ($data['items'] as $row) {
        if (!is_array($row)) continue;
        $order = trim((string) (isset($row['order_id']) ? $row['order_id'] : ''));
        $store = trim((string) (isset($row['store']) ? $row['store'] : ''));
        $wpdb->insert($it, array(
            'batch_id'   => (int) $page['batch_id'],
            'page_id'    => (int) $page_id,
            'qty'        => max(0, (int) (isset($row['qty']) ? $row['qty'] : 0)),
            'code'       => substr(btp_blanks_norm_code(isset($row['code']) ? $row['code'] : ''), 0, 64),
            'product'    => substr(trim((string) (isset($row['product']) ? $row['product'] : '')), 0, 255),
            'color'      => substr(trim((string) (isset($row['color']) ? $row['color'] : '')), 0, 120),
            'size'       => substr(trim((string) (isset($row['size']) ? $row['size'] : '')), 0, 40),
            'order_id'   => substr($order !== '' ? $order : $p_order, 0, 64),
            'store'      => substr($store !== '' ? $store : $p_store, 0, 190),
            'confidence' => (isset($row['confidence']) && $row['confidence'] === 'low') ? 'low' : 'high',
            'note'       => trim((string) (isset($row['note']) ? $row['note'] : '')),
            'sort_order' => $n++,
        ));
    }

    $wpdb->update($pt, array(
        'status'   => 'done',
        'form'     => substr((string) (isset($data['form']) ? $data['form'] : ''), 0, 30),
        'order_id' => substr($p_order, 0, 64),
        'store'    => substr($p_store, 0, 190),
        'note'     => trim((string) (isset($data['page_note']) ? $data['page_note'] : '')),
        'error'    => '',
        'read_at'  => gmdate('Y-m-d H:i:s'),
    ), array('id' => $page_id));

    return true;
}

/* ─────────────────────────────────────────────────────────────────────────
   REST
   ───────────────────────────────────────────────────────────────────── */

function btp_blanks_iso($dt) { return $dt ? str_replace(' ', 'T', $dt) . 'Z' : ''; }

function btp_blanks_page_out($r) {
    $nonce = wp_create_nonce('wp_rest');
    $status = $r['status'];
    if ($status === 'reading' && $r['read_started'] && strtotime($r['read_started'] . ' UTC') < time() - 300) $status = 'error';
    return array(
        'id'       => (int) $r['id'],
        'batch_id' => (int) $r['batch_id'],
        'status'   => $status,
        'form'     => $r['form'],
        'order_id' => $r['order_id'],
        'store'    => $r['store'],
        'note'     => (string) $r['note'],
        'error'    => $status === 'error' && !$r['error'] ? 'The read did not finish. Try again.' : (string) $r['error'],
        'img'      => rest_url('boomerts/v1/blanks/pages/' . (int) $r['id'] . '/image') . '?_wpnonce=' . $nonce,
        'created'  => btp_blanks_iso($r['created_at']),
    );
}

function btp_blanks_item_out($r) {
    return array(
        'id'         => (int) $r['id'],
        'page_id'    => (int) $r['page_id'],
        'qty'        => (int) $r['qty'],
        'code'       => $r['code'],
        'product'    => $r['product'],
        'color'      => $r['color'],
        'size'       => $r['size'],
        'order_id'   => $r['order_id'],
        'store'      => $r['store'],
        'confidence' => $r['confidence'],
        'note'       => (string) $r['note'],
        'checked'    => (int) $r['checked'],
        'excluded'   => (int) $r['excluded'],
        'manual'     => (int) $r['manual'],
    );
}

function btp_blanks_batch_payload($id) {
    global $wpdb;
    $b = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . btp_blanks_table('batches') . " WHERE id = %d", $id), ARRAY_A);
    if (!$b) return new WP_Error('btp_blanks_missing', 'That batch is gone.', array('status' => 404));
    $pages = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . btp_blanks_table('pages') . " WHERE batch_id = %d ORDER BY id", $id), ARRAY_A);
    $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . btp_blanks_table('items') . " WHERE batch_id = %d ORDER BY page_id, sort_order, id", $id), ARRAY_A);
    return array(
        'batch' => array('id' => (int) $b['id'], 'name' => $b['name'], 'created_by' => $b['created_by'], 'created' => btp_blanks_iso($b['created_at'])),
        'pages' => array_map('btp_blanks_page_out', $pages),
        'items' => array_map('btp_blanks_item_out', $items),
        'codes' => btp_blanks_codes(),
    );
}

function btp_blanks_actor() {
    return function_exists('btp_actor_name') ? btp_actor_name() : wp_get_current_user()->display_name;
}

add_action('rest_api_init', function () {
    $ns   = 'boomerts/v1';
    $perm = 'btp_rest_can_access';

    register_rest_route($ns, '/blanks/batches', array(
        array('methods' => 'GET',  'permission_callback' => $perm, 'callback' => 'btp_rest_blanks_batches'),
        array('methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'btp_rest_blanks_batch_create'),
    ));
    register_rest_route($ns, '/blanks/batches/(?P<id>\d+)', array(
        array('methods' => 'GET',    'permission_callback' => $perm, 'callback' => function ($r) {
            $out = btp_blanks_batch_payload((int) $r['id']);
            return is_wp_error($out) ? $out : new WP_REST_Response($out, 200);
        }),
        array('methods' => 'PUT',    'permission_callback' => $perm, 'callback' => 'btp_rest_blanks_batch_rename'),
        array('methods' => 'DELETE', 'permission_callback' => $perm, 'callback' => 'btp_rest_blanks_batch_delete'),
    ));
    register_rest_route($ns, '/blanks/batches/(?P<id>\d+)/pages', array(
        'methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'btp_rest_blanks_page_upload',
    ));
    register_rest_route($ns, '/blanks/pages/(?P<id>\d+)', array(
        'methods' => 'DELETE', 'permission_callback' => $perm, 'callback' => 'btp_rest_blanks_page_delete',
    ));
    register_rest_route($ns, '/blanks/pages/(?P<id>\d+)/read', array(
        'methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'btp_rest_blanks_page_read',
    ));
    register_rest_route($ns, '/blanks/pages/(?P<id>\d+)/image', array(
        'methods' => 'GET', 'permission_callback' => $perm, 'callback' => 'btp_rest_blanks_page_image',
    ));
    register_rest_route($ns, '/blanks/items', array(
        'methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'btp_rest_blanks_item_create',
    ));
    register_rest_route($ns, '/blanks/items/(?P<id>\d+)', array(
        array('methods' => 'PUT',    'permission_callback' => $perm, 'callback' => 'btp_rest_blanks_item_save'),
        array('methods' => 'DELETE', 'permission_callback' => $perm, 'callback' => 'btp_rest_blanks_item_delete'),
    ));
    register_rest_route($ns, '/blanks/codes', array(
        array('methods' => 'GET',  'permission_callback' => $perm, 'callback' => function () { return new WP_REST_Response(btp_blanks_codes(), 200); }),
        array('methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'btp_rest_blanks_code_save'),
    ));
    register_rest_route($ns, '/blanks/codes/(?P<code>[^/]+)', array(
        'methods' => 'DELETE', 'permission_callback' => $perm, 'callback' => 'btp_rest_blanks_code_delete',
    ));
});

function btp_rest_blanks_batches() {
    global $wpdb;
    $b = btp_blanks_table('batches');
    $p = btp_blanks_table('pages');
    $i = btp_blanks_table('items');
    $rows = $wpdb->get_results(
        "SELECT b.*, (SELECT COUNT(*) FROM $p WHERE batch_id = b.id) AS pages,
                (SELECT COALESCE(SUM(qty),0) FROM $i WHERE batch_id = b.id AND excluded = 0) AS qty
         FROM $b b ORDER BY b.id DESC LIMIT 40", ARRAY_A);
    $out = array();
    foreach ($rows as $r) {
        $out[] = array('id' => (int) $r['id'], 'name' => $r['name'], 'created_by' => $r['created_by'],
            'created' => btp_blanks_iso($r['created_at']), 'pages' => (int) $r['pages'], 'qty' => (int) $r['qty']);
    }
    return new WP_REST_Response(array('batches' => $out, 'key_set' => btp_blanks_api_key() !== ''), 200);
}

function btp_rest_blanks_batch_create($req) {
    global $wpdb;
    $name = trim(sanitize_text_field((string) $req->get_param('name')));
    if ($name === '') $name = 'Blanks ' . gmdate('M j');
    $wpdb->insert(btp_blanks_table('batches'), array(
        'name' => substr($name, 0, 190), 'created_by' => btp_blanks_actor(), 'created_at' => gmdate('Y-m-d H:i:s'),
    ));
    return new WP_REST_Response(btp_blanks_batch_payload((int) $wpdb->insert_id), 200);
}

function btp_rest_blanks_batch_rename($req) {
    global $wpdb;
    $name = trim(sanitize_text_field((string) $req->get_param('name')));
    if ($name === '') return new WP_Error('btp_blanks_name', 'Give the batch a name.', array('status' => 400));
    $wpdb->update(btp_blanks_table('batches'), array('name' => substr($name, 0, 190)), array('id' => (int) $req['id']));
    return new WP_REST_Response(array('ok' => true), 200);
}

function btp_rest_blanks_batch_delete($req) {
    global $wpdb;
    $id  = (int) $req['id'];
    $pt  = btp_blanks_table('pages');
    $dir = btp_blanks_dir($id);
    foreach ($wpdb->get_col($wpdb->prepare("SELECT file FROM $pt WHERE batch_id = %d", $id)) as $f) {
        @unlink($dir . '/' . basename($f));
    }
    @unlink($dir . '/index.php');
    @rmdir($dir);
    $wpdb->delete(btp_blanks_table('items'), array('batch_id' => $id));
    $wpdb->delete($pt, array('batch_id' => $id));
    $wpdb->delete(btp_blanks_table('batches'), array('id' => $id));
    return new WP_REST_Response(array('ok' => true), 200);
}

function btp_rest_blanks_page_upload($req) {
    global $wpdb;
    $batch_id = (int) $req['id'];
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . btp_blanks_table('batches') . " WHERE id = %d", $batch_id));
    if (!$exists) return new WP_Error('btp_blanks_missing', 'That batch is gone.', array('status' => 404));

    $b64 = (string) $req->get_param('image');
    if (strpos($b64, ',') !== false) $b64 = substr($b64, strpos($b64, ',') + 1);
    $bytes = base64_decode($b64, true);
    if ($bytes === false || strlen($bytes) < 1000) return new WP_Error('btp_blanks_img', 'That photo did not come through. Try again.', array('status' => 400));
    if (strlen($bytes) > 12 * 1024 * 1024) return new WP_Error('btp_blanks_big', 'That photo is too large.', array('status' => 413));
    $info = @getimagesizefromstring($bytes);
    if (!$info || $info['mime'] !== 'image/jpeg') return new WP_Error('btp_blanks_type', 'Photos must be JPEG.', array('status' => 400));

    $file = bin2hex(random_bytes(16)) . '.jpg';
    if (@file_put_contents(btp_blanks_dir($batch_id) . '/' . $file, $bytes) === false) {
        return new WP_Error('btp_blanks_write', 'The server could not save the photo.', array('status' => 500));
    }
    $wpdb->insert(btp_blanks_table('pages'), array(
        'batch_id' => $batch_id, 'file' => $file, 'status' => 'new',
        'created_by' => btp_blanks_actor(), 'created_at' => gmdate('Y-m-d H:i:s'),
    ));
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . btp_blanks_table('pages') . " WHERE id = %d", $wpdb->insert_id), ARRAY_A);
    return new WP_REST_Response(btp_blanks_page_out($row), 200);
}

function btp_rest_blanks_page_read($req) {
    global $wpdb;
    $id = (int) $req['id'];
    $ok = btp_blanks_read_page($id);
    if (is_wp_error($ok) && $ok->get_error_code() === 'btp_blanks_busy') return $ok;
    $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . btp_blanks_table('pages') . " WHERE id = %d", $id), ARRAY_A);
    if (!$row) return is_wp_error($ok) ? $ok : new WP_Error('btp_blanks_missing', 'That page is gone.', array('status' => 404));
    $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . btp_blanks_table('items') . " WHERE page_id = %d ORDER BY sort_order, id", $id), ARRAY_A);
    // A failed read still answers 200 with the page, so the screen shows the
    // reason on that page instead of a generic request failure.
    return new WP_REST_Response(array(
        'page'  => btp_blanks_page_out($row),
        'items' => array_map('btp_blanks_item_out', $items),
    ), 200);
}

function btp_rest_blanks_page_delete($req) {
    global $wpdb;
    $id  = (int) $req['id'];
    $pt  = btp_blanks_table('pages');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pt WHERE id = %d", $id), ARRAY_A);
    if ($row) @unlink(btp_blanks_file_path($row));
    $wpdb->delete(btp_blanks_table('items'), array('page_id' => $id));
    $wpdb->delete($pt, array('id' => $id));
    return new WP_REST_Response(array('ok' => true), 200);
}

function btp_rest_blanks_page_image($req) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . btp_blanks_table('pages') . " WHERE id = %d", (int) $req['id']), ARRAY_A);
    $path = $row ? btp_blanks_file_path($row) : '';
    if (!$path || !is_file($path)) return new WP_Error('btp_blanks_missing', 'No photo.', array('status' => 404));
    nocache_headers();
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

function btp_blanks_item_fields($req) {
    $f = array();
    foreach (array('code', 'product', 'color', 'size', 'order_id', 'store', 'note') as $k) {
        if ($req->has_param($k)) {
            $v = trim(sanitize_text_field((string) $req->get_param($k)));
            $f[$k] = $k === 'code' ? btp_blanks_norm_code($v) : $v;
        }
    }
    if ($req->has_param('qty'))      $f['qty']      = max(0, (int) $req->get_param('qty'));
    if ($req->has_param('checked'))  $f['checked']  = $req->get_param('checked') ? 1 : 0;
    if ($req->has_param('excluded')) $f['excluded'] = $req->get_param('excluded') ? 1 : 0;
    if ($req->has_param('confidence')) $f['confidence'] = $req->get_param('confidence') === 'low' ? 'low' : 'high';
    return $f;
}

function btp_rest_blanks_item_create($req) {
    global $wpdb;
    $f = btp_blanks_item_fields($req);
    $f['batch_id']   = (int) $req->get_param('batch_id');
    $f['page_id']    = (int) $req->get_param('page_id');
    $f['manual']     = 1;
    $f['confidence'] = 'high';
    $f['sort_order'] = 9999;
    if (!$f['batch_id']) return new WP_Error('btp_blanks_batch', 'No batch.', array('status' => 400));
    $wpdb->insert(btp_blanks_table('items'), $f);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . btp_blanks_table('items') . " WHERE id = %d", $wpdb->insert_id), ARRAY_A);
    return new WP_REST_Response(btp_blanks_item_out($row), 200);
}

function btp_rest_blanks_item_save($req) {
    global $wpdb;
    $id = (int) $req['id'];
    $f  = btp_blanks_item_fields($req);
    // A line someone has corrected is no longer a low-confidence guess.
    $edited = array_intersect_key($f, array_flip(array('qty', 'code', 'product', 'color', 'size', 'order_id', 'store')));
    if ($edited) { $f['manual'] = 1; $f['confidence'] = 'high'; }
    if ($f) $wpdb->update(btp_blanks_table('items'), $f, array('id' => $id));
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . btp_blanks_table('items') . " WHERE id = %d", $id), ARRAY_A);
    if (!$row) return new WP_Error('btp_blanks_missing', 'That line is gone.', array('status' => 404));
    return new WP_REST_Response(btp_blanks_item_out($row), 200);
}

function btp_rest_blanks_item_delete($req) {
    global $wpdb;
    $wpdb->delete(btp_blanks_table('items'), array('id' => (int) $req['id']));
    return new WP_REST_Response(array('ok' => true), 200);
}

function btp_rest_blanks_code_save($req) {
    $code = btp_blanks_norm_code($req->get_param('code'));
    if ($code === '') return new WP_Error('btp_blanks_code', 'Enter the item code.', array('status' => 400));
    $vendor = trim(sanitize_text_field((string) $req->get_param('vendor')));
    $style  = trim(sanitize_text_field((string) $req->get_param('style')));
    if ($vendor === '' || $style === '') return new WP_Error('btp_blanks_code', 'Vendor and style are both needed.', array('status' => 400));
    $codes = btp_blanks_codes();
    $codes[$code] = array('vendor' => $vendor, 'style' => $style, 'name' => trim(sanitize_text_field((string) $req->get_param('name'))));
    update_option('btp_blank_codes', $codes, false);
    return new WP_REST_Response($codes, 200);
}

function btp_rest_blanks_code_delete($req) {
    $codes = btp_blanks_codes();
    unset($codes[btp_blanks_norm_code(urldecode($req['code']))]);
    update_option('btp_blank_codes', $codes, false);
    return new WP_REST_Response($codes, 200);
}

/* ─────────────────────────────────────────────────────────────────────────
   ADMIN: BT Portal > Blank Orders (the API key)
   ───────────────────────────────────────────────────────────────────── */

add_action('admin_menu', function () {
    add_submenu_page('bt-portal', 'Blank Orders', 'Blank Orders', 'manage_options', 'bt-portal-blanks', 'btp_blanks_admin_page');
}, 20);

function btp_blanks_admin_page() {
    if (!current_user_can('manage_options')) return;
    $notice = '';
    $err    = '';

    if (isset($_POST['btp_blanks_save']) && check_admin_referer('btp_blanks_save')) {
        $k = trim(wp_unslash($_POST['btp_blanks_key']));
        if ($k !== '') { update_option('btp_blanks_api_key', btp_vendor_encrypt($k), false); $notice = 'Key saved.'; }
    }
    if (isset($_POST['btp_blanks_clear']) && check_admin_referer('btp_blanks_save')) {
        delete_option('btp_blanks_api_key');
        $notice = 'Key removed.';
    }
    if (isset($_POST['btp_blanks_test']) && check_admin_referer('btp_blanks_save')) {
        $res = wp_remote_get('https://api.anthropic.com/v1/models/' . BTP_BLANKS_MODEL, array('timeout' => 20, 'headers' => array(
            'x-api-key' => btp_blanks_api_key(), 'anthropic-version' => '2023-06-01',
        )));
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        if ($code === 200) $notice = 'The key works.';
        else $err = 'The key did not work (' . ($code ? 'HTTP ' . $code : $res->get_error_message()) . ').';
    }

    $from_config = defined('BTP_ANTHROPIC_KEY') && BTP_ANTHROPIC_KEY;
    $set = btp_blanks_api_key() !== '';
    ?>
    <div class="wrap">
        <h1>Blank Orders</h1>
        <p>Photos of order sheets are read by Claude on this server. The key is stored encrypted and is never sent to anyone's browser.
           Each page costs a few cents to read.</p>
        <?php if ($notice): ?><div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <?php if ($err): ?><div class="notice notice-error"><p><?php echo esc_html($err); ?></p></div><?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('btp_blanks_save'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="btp_blanks_key">Anthropic API key</label></th>
                    <td>
                        <?php if ($from_config): ?>
                            <p><em>Set in wp-config.php (BTP_ANTHROPIC_KEY).</em></p>
                        <?php else: ?>
                            <input type="password" class="regular-text" id="btp_blanks_key" name="btp_blanks_key" value="" autocomplete="off"
                                placeholder="<?php echo $set ? 'saved, leave blank to keep' : 'sk-ant-...'; ?>">
                            <p class="description">From console.anthropic.com, API keys.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <?php if (!$from_config): ?><button type="submit" name="btp_blanks_save" class="button button-primary">Save</button><?php endif; ?>
                <?php if ($set): ?><button type="submit" name="btp_blanks_test" class="button">Test the key</button><?php endif; ?>
                <?php if ($set && !$from_config): ?><button type="submit" name="btp_blanks_clear" class="button">Remove key</button><?php endif; ?>
            </p>
        </form>
    </div>
    <?php
}

/* ─────────────────────────────────────────────────────────────────────────
   THE TAB
   ───────────────────────────────────────────────────────────────────── */

add_shortcode('bt_blank_orders', 'btp_blank_orders_shortcode');

function btp_blank_orders_shortcode() {
    if (function_exists('btp_user_can_access') && !btp_user_can_access()) return '';

    $cfg = wp_json_encode(array(
        'api'   => esc_url_raw(rest_url('boomerts/v1')),
        'nonce' => wp_create_nonce('wp_rest'),
    ));

    ob_start();
    ?>
<div id="btp-blanks">

  <div class="bo-head">
    <div class="bo-title">BLANK <span>Orders</span></div>
    <div class="bo-batchbar">
      <select id="boBatchSel" aria-label="Batch"></select>
      <button type="button" class="bo-btn primary" id="boNewBatch">New batch</button>
      <button type="button" class="bo-btn" id="boRename" hidden>Rename</button>
    </div>
  </div>

  <div class="bo-warn" id="boKeyWarn" hidden>
    Reading is not set up yet. Photos will save, and they will be read once a WordPress admin adds the API key under
    <b>BT Portal &gt; Blank Orders</b>.
  </div>

  <div id="boEmpty" class="bo-none">Start a batch, then flip through your circled order sheets in front of the camera.</div>

  <div id="boBody" hidden>

    <section class="bo-card">
      <div class="bo-cam" id="boCam">
        <video id="boVideo" playsinline muted autoplay></video>
        <div class="bo-flash" id="boFlash"></div>
        <div class="bo-camidle" id="boCamIdle">
          <button type="button" class="bo-btn primary big" id="boCamStart">Start camera</button>
          <div class="bo-small">Or use <b>Add photos</b> below to pick shots from your camera app.</div>
        </div>
        <div class="bo-camhud" id="boHud" hidden>
          <div class="bo-hudmsg" id="boHudMsg">Hold the first page still</div>
          <div class="bo-meter"><i id="boMeter"></i></div>
        </div>
        <div class="bo-shots" id="boShotCount" hidden>0</div>
      </div>
      <div class="bo-camctl">
        <button type="button" class="bo-btn" id="boAuto" aria-pressed="true">Auto: on</button>
        <button type="button" class="bo-btn primary" id="boShutter" disabled>Snap</button>
        <button type="button" class="bo-btn" id="boTorch" hidden>Light</button>
        <label class="bo-btn" for="boFiles">Add photos</label>
        <input type="file" id="boFiles" accept="image/*" multiple hidden>
        <button type="button" class="bo-btn" id="boCamStop" hidden>Stop</button>
        <label class="bo-opt">Hold
          <select id="boHold">
            <option value="500">Quick</option>
            <option value="800" selected>Normal</option>
            <option value="1300">Careful</option>
          </select>
        </label>
      </div>
      <div class="bo-progress" id="boProgress"></div>
      <div class="bo-thumbs" id="boThumbs"></div>
    </section>

    <div id="boResults"></div>

    <details class="bo-extras" id="boCodesWrap">
      <summary>Item code map</summary>
      <div class="bo-card" id="boCodes"></div>
    </details>

    <div class="bo-row bo-foot">
      <button type="button" class="bo-btn danger" id="boDelBatch">Delete this batch</button>
    </div>
  </div>

  <div class="bo-modal" id="boModal" hidden>
    <div class="bo-modalbox">
      <div class="bo-modalhead">
        <div class="bo-label" id="boModalTitle">Page</div>
        <button type="button" class="bo-x" id="boModalClose" aria-label="Close">&times;</button>
      </div>
      <div class="bo-modalimg" id="boModalImgWrap"><img id="boModalImg" alt="Order sheet photo"></div>
      <div id="boModalInfo"></div>
      <div class="bo-row">
        <button type="button" class="bo-btn primary" id="boModalReread">Read again</button>
        <button type="button" class="bo-btn danger" id="boModalDelete">Delete page</button>
      </div>
    </div>
  </div>

  <div class="bo-toast" id="boToast"></div>
</div>
<style>
#btp-blanks { padding:18px 16px 50px; max-width:1100px; margin:0 auto; font-family:'Barlow',sans-serif; font-size:15px; color:#1a1f5e; }
#btp-blanks [hidden] { display:none !important; }
#btp-blanks .bo-head { display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding-bottom:12px; border-bottom:2px solid #e8eaf0; }
#btp-blanks .bo-title { font-family:'Oswald',sans-serif; font-size:22px; font-weight:600; letter-spacing:.02em; text-transform:uppercase; }
#btp-blanks .bo-title span { color:#e91e8c; }
#btp-blanks .bo-batchbar { margin-left:auto; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
#btp-blanks select, #btp-blanks input[type=text], #btp-blanks input[type=number] { font-family:'Barlow',sans-serif; font-size:15px; color:#1a1f5e; background:#fff; border:1px solid #d6dbe8; border-radius:7px; padding:7px 9px; max-width:100%; }
#btp-blanks .bo-batchbar select { min-width:200px; }
#btp-blanks .bo-warn { margin-top:12px; padding:11px 14px; background:#fff8e6; border:1px solid #f0d9a0; border-left:4px solid #FFC53D; border-radius:9px; font-size:15px; line-height:1.5; color:#6b5410; }
#btp-blanks .bo-none { margin-top:14px; padding:24px 16px; text-align:center; color:#8b93a8; background:#fff; border:1px dashed #d6dbe8; border-radius:10px; }
#btp-blanks .bo-card { margin-top:12px; padding:12px; background:#fff; border:1px solid #dfe3ed; border-radius:12px; box-shadow:0 2px 8px rgba(26,31,94,.06); }
#btp-blanks .bo-label { font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:#8b93a8; }
#btp-blanks h3.bo-h { margin:0 0 8px; font-family:'Oswald',sans-serif; font-size:17px; font-weight:600; text-transform:uppercase; letter-spacing:.03em; color:#1a1f5e; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
#btp-blanks h3.bo-h .bo-n { font-family:'Barlow',sans-serif; font-size:14px; font-weight:600; text-transform:none; letter-spacing:0; color:#8b93a8; }
#btp-blanks .bo-btn { display:inline-flex; align-items:center; justify-content:center; font-family:'Barlow Condensed',sans-serif; font-size:14px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; padding:8px 14px; border-radius:7px; cursor:pointer; background:#fff; color:#1a1f5e; border:1px solid #d6dbe8; line-height:1.2; }
#btp-blanks .bo-btn:hover { background:#f4f5f9; }
#btp-blanks .bo-btn:disabled { opacity:.45; cursor:default; }
#btp-blanks .bo-btn.primary { background:#1a1f5e; color:#fff; border-color:#1a1f5e; }
#btp-blanks .bo-btn.pink { background:#e91e8c; color:#fff; border-color:#e91e8c; }
#btp-blanks .bo-btn.danger:hover { color:#d32f2f; border-color:#d32f2f; background:#fff; }
#btp-blanks .bo-btn.small { padding:5px 9px; font-size:13px; }
#btp-blanks .bo-btn.big { padding:14px 26px; font-size:17px; }
#btp-blanks .bo-row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:10px; }
#btp-blanks .bo-opt { display:inline-flex; align-items:center; gap:6px; font-size:14px; color:#5a6380; }
#btp-blanks .bo-small { font-size:14px; color:#8b93a8; margin-top:8px; }

/* camera */
#btp-blanks .bo-cam { position:relative; background:#0f1240; border-radius:10px; overflow:hidden; aspect-ratio:3/4; max-height:62vh; margin:0 auto; width:100%; max-width:520px; }
#btp-blanks .bo-cam video { width:100%; height:100%; object-fit:cover; display:block; }
#btp-blanks .bo-camidle { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:20px; color:#c9cde0; }
#btp-blanks .bo-camidle .bo-small { color:#c9cde0; }
#btp-blanks .bo-flash { position:absolute; inset:0; background:#fff; opacity:0; pointer-events:none; }
#btp-blanks .bo-flash.on { animation:boFlash .35s ease-out; }
@keyframes boFlash { 0%{opacity:.75} 100%{opacity:0} }
#btp-blanks .bo-camhud { position:absolute; left:0; right:0; bottom:0; padding:10px 12px 12px; background:linear-gradient(transparent,rgba(15,18,64,.85)); color:#fff; }
#btp-blanks .bo-hudmsg { font-weight:700; font-size:16px; text-shadow:0 1px 2px rgba(0,0,0,.5); }
#btp-blanks .bo-meter { margin-top:6px; height:6px; background:rgba(255,255,255,.25); border-radius:3px; overflow:hidden; }
#btp-blanks .bo-meter i { display:block; height:100%; width:0; background:#e91e8c; transition:width .1s linear; }
#btp-blanks .bo-shots { position:absolute; top:10px; right:10px; min-width:38px; padding:4px 10px; border-radius:999px; background:#e91e8c; color:#fff; font-weight:700; font-size:17px; text-align:center; }
#btp-blanks .bo-camctl { display:flex; gap:8px; flex-wrap:wrap; justify-content:center; margin-top:10px; }
#btp-blanks .bo-camctl #boShutter { min-width:96px; }
#btp-blanks .bo-progress { margin-top:10px; font-size:15px; color:#5a6380; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
#btp-blanks .bo-progress b { color:#1a1f5e; }
#btp-blanks .bo-thumbs { display:grid; grid-template-columns:repeat(auto-fill,minmax(78px,1fr)); gap:8px; margin-top:10px; }
#btp-blanks .bo-thumb { position:relative; aspect-ratio:3/4; border-radius:7px; overflow:hidden; background:#e8eaf0; cursor:pointer; border:2px solid transparent; padding:0; }
#btp-blanks .bo-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
#btp-blanks .bo-thumb .bo-tn { position:absolute; top:3px; left:3px; background:rgba(15,18,64,.8); color:#fff; font-size:12px; font-weight:700; padding:1px 6px; border-radius:4px; }
#btp-blanks .bo-thumb .bo-ts { position:absolute; left:0; right:0; bottom:0; font-family:'Barlow Condensed',sans-serif; font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#fff; padding:2px 4px; text-align:center; background:#8b93a8; }
#btp-blanks .bo-thumb.s-done .bo-ts { background:#2E7D32; }
#btp-blanks .bo-thumb.s-reading .bo-ts, #btp-blanks .bo-thumb.s-uploading .bo-ts { background:#1565c0; }
#btp-blanks .bo-thumb.s-error, #btp-blanks .bo-thumb.s-failed { border-color:#d32f2f; }
#btp-blanks .bo-thumb.s-error .bo-ts, #btp-blanks .bo-thumb.s-failed .bo-ts { background:#d32f2f; }
#btp-blanks .bo-thumb.s-flag { border-color:#f0a020; }

/* results */
#btp-blanks table.bo-t { width:100%; border-collapse:collapse; font-size:15px; }
#btp-blanks table.bo-t th { font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#8b93a8; text-align:left; padding:5px 7px; border-bottom:2px solid #e8eaf0; white-space:nowrap; }
#btp-blanks table.bo-t td { padding:6px 7px; border-bottom:1px solid #eef0f5; vertical-align:top; }
#btp-blanks table.bo-t td.n, #btp-blanks table.bo-t th.n { text-align:right; font-variant-numeric:tabular-nums; }
#btp-blanks table.bo-t tr.tot td { font-weight:700; border-top:2px solid #e8eaf0; }
#btp-blanks .bo-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
#btp-blanks .bo-vendor { margin-top:12px; }
#btp-blanks .bo-vendor:first-child { margin-top:0; }
#btp-blanks .bo-vhead { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:7px 10px; background:#1a1f5e; color:#fff; border-radius:8px 8px 0 0; }
#btp-blanks .bo-vhead .bo-vname { font-family:'Oswald',sans-serif; font-size:17px; font-weight:600; text-transform:uppercase; }
#btp-blanks .bo-vhead .bo-vqty { font-weight:600; opacity:.85; }
#btp-blanks .bo-vhead .bo-btn { margin-left:auto; }
#btp-blanks .bo-vhead .bo-btn + .bo-btn { margin-left:0; }
#btp-blanks .bo-style { border:1px solid #e8eaf0; border-top:none; padding:8px 10px 10px; }
#btp-blanks .bo-stylename { font-weight:700; font-size:16px; }
#btp-blanks .bo-stylename small { font-weight:500; color:#8b93a8; font-size:14px; margin-left:6px; }
#btp-blanks .bo-badge { display:inline-block; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; padding:1px 7px; border-radius:4px; border:1px solid; margin-left:4px; vertical-align:1px; }
#btp-blanks .bo-badge.low { color:#a05a00; border-color:#f0c070; background:#fff8e6; }
#btp-blanks .bo-badge.map { color:#d32f2f; border-color:#f0b0b0; background:#fdf0f0; }
#btp-blanks .bo-badge.ex { color:#8b93a8; border-color:#d6dbe8; }
#btp-blanks .bo-badge.hand { color:#1565c0; border-color:#b8d0f0; }
#btp-blanks .bo-alert { border-left:4px solid #d32f2f; }
#btp-blanks .bo-alert.amber { border-left-color:#f0a020; }
#btp-blanks .bo-flag { padding:7px 0; border-bottom:1px solid #eef0f5; display:flex; gap:8px; align-items:flex-start; flex-wrap:wrap; }
#btp-blanks .bo-flag:last-child { border-bottom:none; }
#btp-blanks .bo-flag .bo-ft { flex:1 1 240px; line-height:1.45; }
#btp-blanks .bo-flag .bo-why { color:#a05a00; }
#btp-blanks .bo-mapform { display:flex; gap:6px; flex-wrap:wrap; align-items:center; padding:8px 0; border-bottom:1px solid #eef0f5; }
#btp-blanks .bo-mapform:last-child { border-bottom:none; }
#btp-blanks .bo-mapform .bo-mcode { font-weight:700; font-size:16px; min-width:70px; }
#btp-blanks .bo-mapform .bo-mdesc { flex:1 1 100%; color:#5a6380; font-size:14px; }
#btp-blanks .bo-mapform input[type=text] { width:150px; }
#btp-blanks .bo-order { border:1px solid #e8eaf0; border-radius:9px; margin-top:8px; overflow:hidden; }
#btp-blanks .bo-ohead { display:flex; gap:8px; align-items:center; flex-wrap:wrap; padding:7px 10px; background:#f4f5f9; }
#btp-blanks .bo-ohead .bo-oid { font-weight:700; font-size:16px; }
#btp-blanks .bo-ohead .bo-ostore { color:#5a6380; }
#btp-blanks .bo-ohead .bo-ocount { margin-left:auto; font-size:14px; color:#8b93a8; }
#btp-blanks .bo-ohead.alldone { background:#e8f5e9; }
#btp-blanks .bo-line { display:flex; gap:10px; align-items:flex-start; padding:7px 10px; border-top:1px solid #eef0f5; }
#btp-blanks .bo-line input[type=checkbox] { width:20px; height:20px; margin-top:1px; flex:none; }
#btp-blanks .bo-line .bo-lt { flex:1; line-height:1.45; }
#btp-blanks .bo-line .bo-lq { font-weight:700; }
#btp-blanks .bo-line .bo-lsub { color:#5a6380; font-size:14px; }
#btp-blanks .bo-line.ex .bo-lt { text-decoration:line-through; color:#9ca3b8; }
#btp-blanks .bo-line.ck .bo-lt { color:#5a6380; }
#btp-blanks .bo-edit { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:6px; padding:8px 10px; background:#fbfbfd; border-top:1px solid #eef0f5; }
#btp-blanks .bo-edit label { display:flex; flex-direction:column; gap:2px; font-size:13px; color:#8b93a8; font-weight:600; }
#btp-blanks .bo-edit input { width:100%; box-sizing:border-box; }
#btp-blanks .bo-edit .bo-row { grid-column:1/-1; margin-top:2px; }
#btp-blanks .bo-extras { margin-top:12px; }
#btp-blanks .bo-extras > summary { cursor:pointer; padding:9px 13px; border:1px dashed #d6dbe8; border-radius:9px; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#8b93a8; }
#btp-blanks .bo-foot { margin-top:18px; }

/* page viewer */
#btp-blanks .bo-modal { position:fixed; inset:0; z-index:100000; background:rgba(15,18,64,.6); display:flex; align-items:flex-start; justify-content:center; overflow:auto; padding:12px; }
#btp-blanks .bo-modalbox { background:#fff; border-radius:12px; width:100%; max-width:820px; padding:12px; }
#btp-blanks .bo-modalhead { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
#btp-blanks .bo-x { margin-left:auto; background:none; border:none; font-size:28px; line-height:1; cursor:pointer; color:#5a6380; padding:0 6px; }
#btp-blanks .bo-modalimg { overflow:auto; max-height:70vh; border-radius:8px; background:#e8eaf0; }
#btp-blanks .bo-modalimg img { display:block; width:100%; cursor:zoom-in; }
#btp-blanks .bo-modalimg.zoom img { width:220%; max-width:none; cursor:zoom-out; }
#btp-blanks .bo-pnote { margin-top:8px; padding:8px 10px; border-radius:7px; background:#fff8e6; color:#6b5410; }
#btp-blanks .bo-pnote.err { background:#fdf0f0; color:#b71c1c; }
#btp-blanks .bo-toast { position:fixed; left:50%; bottom:24px; transform:translate(-50%,80px); z-index:100001; background:#1a1f5e; color:#fff; font-weight:600; font-size:15px; padding:10px 18px; border-radius:999px; opacity:0; pointer-events:none; transition:transform .2s, opacity .2s; max-width:90vw; text-align:center; }
#btp-blanks .bo-toast.show { transform:translate(-50%,0); opacity:1; }
@media (max-width:700px) {
  #btp-blanks { padding:12px 10px 40px; }
  #btp-blanks .bo-batchbar { margin-left:0; width:100%; }
  #btp-blanks .bo-batchbar select { flex:1; min-width:0; }
}
@media (prefers-reduced-motion: reduce) { #btp-blanks * { animation:none !important; transition:none !important; } }
</style>
<script>
(function () {
  "use strict";
  var CFG  = <?php echo $cfg; ?>;
  var PANE = 'bt-tab-blanks';
  var LS   = 'btpBlanksBatch';
  var SIZE_ORDER = ['YXS','YS','YM','YL','YXL','XS','S','M','L','XL','2XL','3XL','4XL','5XL','6XL','OSFA'];
  var VENDOR_ORDER = ['S&S', 'SanMar'];

  var S = {
    batches: [], keySet: true, batch: null, pages: [], items: [], codes: {},
    local: [],          // captures not on the server yet
    editing: null,      // item id (or 'new:<order>') with its editor open
    loaded: false
  };

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function paneActive() { var p = $(PANE); return !!(p && p.classList.contains('active')); }

  var toastTimer = null;
  function toast(msg) {
    var t = $('boToast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove('show'); }, 3200);
  }

  function api(path, method, body, timeoutMs) {
    var opts = { method: method || 'GET', headers: { 'X-WP-Nonce': CFG.nonce }, credentials: 'same-origin' };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var ac = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = null;
    if (ac) { opts.signal = ac.signal; timer = setTimeout(function () { ac.abort(); }, timeoutMs || 30000); }
    return fetch(CFG.api + path, opts).then(function (r) {
      if (timer) clearTimeout(timer);
      return r.text().then(function (txt) {
        var data = null;
        try { data = txt ? JSON.parse(txt) : null; } catch (e) {}
        if (!r.ok) {
          var err = new Error((data && data.message) || ('The server answered ' + r.status + '.'));
          err.status = r.status;
          throw err;
        }
        return data;
      });
    }, function (e) {
      if (timer) clearTimeout(timer);
      var err = new Error(e && e.name === 'AbortError' ? 'The server took too long to answer.' : 'Could not reach the server.');
      err.status = 0;
      throw err;
    });
  }

  /* ── normalizing ────────────────────────────────────────────────────── */

  function normCode(s) { return String(s || '').replace(/[\s\[\]#]+/g, '').toUpperCase(); }

  /* "Adult Medium", "Med", "M" are one column; "Youth Large" and "YL" too.
     Anything not recognized is kept as printed rather than forced into a
     column it may not belong to. */
  function normSize(s) {
    var raw = String(s || '').trim();
    var t = raw.toUpperCase().replace(/[._]/g, ' ').replace(/\s+/g, ' ').trim();
    if (!t) return '';
    var youth = false;
    if (/^(YOUTH|YTH|KIDS?|CHILD)\b/.test(t)) { youth = true; t = t.replace(/^(YOUTH|YTH|KIDS?|CHILD)\b\s*/, ''); }
    t = t.replace(/^(ADULT|ADLT|ADL|UNISEX|MENS|MEN'S|WOMENS|WOMEN'S|LADIES)\b\s*/, '');
    var base = {
      'X SMALL':'XS','X-SMALL':'XS','XSMALL':'XS','EXTRA SMALL':'XS','XS':'XS',
      'SMALL':'S','SM':'S','S':'S',
      'MEDIUM':'M','MED':'M','MD':'M','M':'M',
      'LARGE':'L','LG':'L','L':'L',
      'X LARGE':'XL','X-LARGE':'XL','XLARGE':'XL','EXTRA LARGE':'XL','XL':'XL','1X':'XL',
      'XX LARGE':'2XL','XX-LARGE':'2XL','XXLARGE':'2XL','2X LARGE':'2XL','2X-LARGE':'2XL','XXL':'2XL','2XL':'2XL','2X':'2XL',
      'XXX LARGE':'3XL','XXX-LARGE':'3XL','3X LARGE':'3XL','3X-LARGE':'3XL','XXXL':'3XL','3XL':'3XL','3X':'3XL',
      '4X LARGE':'4XL','XXXXL':'4XL','4XL':'4XL','4X':'4XL',
      '5X LARGE':'5XL','XXXXXL':'5XL','5XL':'5XL','5X':'5XL',
      '6XL':'6XL','6X':'6XL',
      'ONE SIZE':'OSFA','OSFA':'OSFA','OS':'OSFA','ONE SIZE FITS ALL':'OSFA'
    };
    if (!youth) {
      var m = /^Y(XS|S|M|L|XL)$/.exec(t);
      if (m) return 'Y' + m[1];
    }
    var b = base[t];
    if (!b) return raw;
    if (youth) return ['XS','S','M','L','XL'].indexOf(b) > -1 ? 'Y' + b : raw;
    return b;
  }
  function sizeRank(s) { var i = SIZE_ORDER.indexOf(s); return i < 0 ? 100 : i; }

  function colorKey(s) { return String(s || '').trim().replace(/\s+/g, ' ').toLowerCase(); }
  function colorLabel(s) {
    var t = String(s || '').trim().replace(/\s+/g, ' ');
    if (!t) return '(no color)';
    return t === t.toUpperCase() || t === t.toLowerCase()
      ? t.toLowerCase().replace(/\b[a-z]/g, function (c) { return c.toUpperCase(); })
      : t;
  }
  function vendorRank(v) { var i = VENDOR_ORDER.indexOf(v); return i < 0 ? 50 : i; }

  /* ── the tally ──────────────────────────────────────────────────────── */

  /** Everything the results need, from the current items and code map. */
  function compute(items, codes) {
    var vendors = {}, unmapped = {}, flags = [], dupes = [];

    // The same sheet photographed twice reads back as the same order with the
    // same lines. That would double the blanks, so it goes to the top.
    var seen = {}, byPage = {};
    items.forEach(function (it) { if (it.page_id && !+it.manual) (byPage[it.page_id] = byPage[it.page_id] || []).push(it); });
    Object.keys(byPage).map(Number).sort(function (a, b) { return a - b; }).forEach(function (pid) {
      var key = byPage[pid].map(function (it) {
        return [it.order_id, normCode(it.code), it.qty, colorKey(it.color), normSize(it.size)].join('|');
      }).sort().join('~');
      if (!byPage[pid].some(function (it) { return it.order_id; })) return;
      if (seen[key]) dupes.push({ page: pid, of: seen[key] }); else seen[key] = pid;
    });

    items.forEach(function (it) {
      if (+it.excluded) return;
      var why = [];
      if (it.confidence === 'low') why.push(it.note || 'Hard to read');
      if (!(+it.qty > 0)) why.push('No quantity');
      if (!String(it.size || '').trim()) why.push('No size');
      if (!String(it.color || '').trim()) why.push('No color');
      if (!String(it.order_id || '').trim()) why.push('No order number');
      if (it.confidence !== 'low' && it.note && why.length) why.push(it.note);
      if (why.length) flags.push({ item: it, why: why });

      var code = normCode(it.code);
      var m = code ? codes[code] : null;
      if (!m) {
        var k = code || '(no code)';
        var u = unmapped[k] || (unmapped[k] = { code: k, qty: 0, lines: 0, products: {} });
        u.qty += +it.qty || 0;
        u.lines++;
        if (it.product) u.products[it.product] = 1;
        return;
      }
      var v = vendors[m.vendor] || (vendors[m.vendor] = { vendor: m.vendor, qty: 0, styles: {} });
      var st = v.styles[m.style] || (v.styles[m.style] = { style: m.style, name: m.name || '', qty: 0, colors: {}, sizes: {} });
      var ck = colorKey(it.color);
      var c = st.colors[ck] || (st.colors[ck] = { label: colorLabel(it.color), qty: 0, sizes: {} });
      var sz = normSize(it.size) || '(no size)';
      var q = +it.qty || 0;
      c.sizes[sz] = (c.sizes[sz] || 0) + q;
      c.qty += q; st.qty += q; v.qty += q;
      st.sizes[sz] = 1;
    });

    var vlist = Object.keys(vendors).map(function (k) { return vendors[k]; })
      .sort(function (a, b) { return vendorRank(a.vendor) - vendorRank(b.vendor) || a.vendor.localeCompare(b.vendor); });
    vlist.forEach(function (v) {
      v.list = Object.keys(v.styles).map(function (k) { return v.styles[k]; })
        .sort(function (a, b) { return a.style.localeCompare(b.style, undefined, { numeric: true }); });
      v.list.forEach(function (st) {
        st.sizeList = Object.keys(st.sizes).sort(function (a, b) { return sizeRank(a) - sizeRank(b) || a.localeCompare(b); });
        st.colorList = Object.keys(st.colors).map(function (k) { return st.colors[k]; })
          .sort(function (a, b) { return a.label.localeCompare(b.label); });
      });
    });
    var ulist = Object.keys(unmapped).map(function (k) { return unmapped[k]; })
      .sort(function (a, b) { return a.code.localeCompare(b.code, undefined, { numeric: true }); });
    return { vendors: vlist, unmapped: ulist, flags: flags, dupes: dupes };
  }

  /** Tab separated lines, one per style + color + size, for a cart or a sheet. */
  function vendorLines(v) {
    var out = [];
    v.list.forEach(function (st) {
      st.colorList.forEach(function (c) {
        st.sizeList.forEach(function (sz) {
          if (c.sizes[sz]) out.push([st.style, c.label, sz, c.sizes[sz]].join('\t'));
        });
      });
    });
    return out;
  }

  /* ── loading ────────────────────────────────────────────────────────── */

  function loadBatches(selectId) {
    return api('/blanks/batches').then(function (d) {
      S.batches = d.batches || [];
      S.keySet = !!d.key_set;
      $('boKeyWarn').hidden = S.keySet;
      renderBatchSel(selectId);
      var want = selectId || (function () { try { return +localStorage.getItem(LS) || 0; } catch (e) { return 0; } })();
      if (want && S.batches.some(function (b) { return b.id === want; })) return openBatch(want);
      if (!S.batch) showBody(false);
    }).catch(function (e) { toast(e.message); });
  }

  function renderBatchSel(selected) {
    var sel = $('boBatchSel');
    var cur = selected || (S.batch && S.batch.id) || 0;
    var h = '<option value="">' + (S.batches.length ? 'Pick a batch' : 'No batches yet') + '</option>';
    S.batches.forEach(function (b) {
      h += '<option value="' + b.id + '"' + (b.id === cur ? ' selected' : '') + '>' + esc(b.name) +
        ' (' + b.pages + ' pg' + (b.qty ? ', ' + b.qty + ' pcs' : '') + ')</option>';
    });
    sel.innerHTML = h;
  }

  function showBody(on) {
    $('boBody').hidden = !on;
    $('boEmpty').hidden = !!on;
    $('boRename').hidden = !on;
  }

  function openBatch(id) {
    return api('/blanks/batches/' + id).then(function (d) {
      applyPayload(d, true);
      try { localStorage.setItem(LS, String(id)); } catch (e) {}
      showBody(true);
      renderAll();
      // Pages someone uploaded but nobody has read yet, e.g. the phone was
      // closed mid-stack. The server claim stops two screens double reading.
      S.pages.forEach(function (p) { if (p.status === 'new') queueRead(p.id); });
    }).catch(function (e) { toast(e.message); });
  }

  function applyPayload(d, full) {
    var keepLocal = {};
    S.pages.forEach(function (p) { if (p.localUrl) keepLocal[p.id] = p.localUrl; });
    if (full && (!S.batch || S.batch.id !== d.batch.id)) { S.local = []; keepLocal = {}; }
    S.batch = d.batch;
    S.pages = d.pages.map(function (p) {
      if (keepLocal[p.id]) p.localUrl = keepLocal[p.id];
      if (readsActive[p.id] && p.status !== 'done' && p.status !== 'error') p.status = 'reading';
      return p;
    });
    S.items = d.items;
    S.codes = d.codes || {};
  }

  function newBatch() {
    var d = new Date();
    var def = 'Blanks ' + d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    var name = window.prompt('Name this batch', def);
    if (name === null) return Promise.resolve(false);
    return api('/blanks/batches', 'POST', { name: name || def }).then(function (p) {
      applyPayload(p, true);
      try { localStorage.setItem(LS, String(p.batch.id)); } catch (e) {}
      showBody(true);
      renderAll();
      return loadBatches(p.batch.id).then(function () { return true; });
    }).catch(function (e) { toast(e.message); return false; });
  }

  /* ── uploading and reading ──────────────────────────────────────────── */

  var uploading = false;
  function addCapture(blob) {
    if (!S.batch) return;
    var l = { lid: 'l' + Date.now() + Math.random().toString(36).slice(2, 6), blob: blob, url: URL.createObjectURL(blob), status: 'uploading', batch: S.batch.id };
    S.local.push(l);
    renderPages();
    pumpUploads();
  }

  function blobToBase64(blob) {
    return new Promise(function (res, rej) {
      var r = new FileReader();
      r.onload = function () { res(String(r.result).split(',')[1] || ''); };
      r.onerror = function () { rej(new Error('Could not read the photo.')); };
      r.readAsDataURL(blob);
    });
  }

  function pumpUploads() {
    if (uploading) return;
    var next = S.local.find(function (l) { return l.status === 'uploading'; });
    if (!next) return;
    uploading = true;
    blobToBase64(next.blob).then(function (b64) {
      return api('/blanks/batches/' + next.batch + '/pages', 'POST', { image: b64 }, 90000);
    }).then(function (page) {
      S.local = S.local.filter(function (l) { return l !== next; });
      if (S.batch && S.batch.id === next.batch) {
        page.localUrl = next.url;
        S.pages.push(page);
        queueRead(page.id);
      }
    }).catch(function (e) {
      next.status = 'failed';
      next.error = e.message;
      toast('A photo did not upload: ' + e.message);
    }).then(function () {
      uploading = false;
      renderPages();
      pumpUploads();
    });
  }

  var readQueue = [], readsActive = {}, READ_SLOTS = 2;
  function queueRead(id) {
    if (readsActive[id] || readQueue.indexOf(id) > -1) return;
    readQueue.push(id);
    pumpReads();
  }
  function pumpReads() {
    while (Object.keys(readsActive).length < READ_SLOTS && readQueue.length) {
      (function (id) {
        readsActive[id] = true;
        var p = pageById(id);
        if (p) { p.status = 'reading'; p.error = ''; }
        renderPages();
        api('/blanks/pages/' + id + '/read', 'POST', {}, 200000).then(function (d) {
          var pg = pageById(id);
          if (pg) { var keep = pg.localUrl; Object.assign(pg, d.page); if (keep) pg.localUrl = keep; }
          S.items = S.items.filter(function (it) { return it.page_id !== id; }).concat(d.items);
          if (d.page.status === 'error') toast('Page ' + pageNo(id) + ': ' + d.page.error);
        }).catch(function (e) {
          var pg = pageById(id);
          if (e.status === 409) return;   // another screen has it
          if (pg) { pg.status = 'error'; pg.error = e.message; }
        }).then(function () {
          delete readsActive[id];
          renderAll();
          pumpReads();
        });
      })(readQueue.shift());
    }
  }

  function pageById(id) { for (var i = 0; i < S.pages.length; i++) if (S.pages[i].id === id) return S.pages[i]; return null; }
  function pageNo(id) { for (var i = 0; i < S.pages.length; i++) if (S.pages[i].id === id) return i + 1; return '?'; }

  /* ── rendering ──────────────────────────────────────────────────────── */

  function renderAll() {
    renderPages();
    renderResults();
    renderCodes();
  }

  function renderPages() {
    var flagged = {};
    compute(S.items, S.codes).flags.forEach(function (f) { flagged[f.item.page_id] = 1; });
    var h = '';
    S.pages.forEach(function (p, i) {
      var label = { 'new': 'Waiting', reading: 'Reading', done: 'Read', error: 'Failed' }[p.status] || p.status;
      var n = S.items.filter(function (it) { return it.page_id === p.id && !+it.excluded; }).length;
      if (p.status === 'done') label = n + ' line' + (n === 1 ? '' : 's');
      var cls = 's-' + p.status + ((p.status === 'done' && (flagged[p.id] || p.note || !n)) ? ' s-flag' : '');
      h += '<button type="button" class="bo-thumb ' + cls + '" data-page="' + p.id + '">' +
        '<img loading="lazy" src="' + esc(p.localUrl || p.img) + '" alt="">' +
        '<span class="bo-tn">' + (i + 1) + '</span><span class="bo-ts">' + esc(label) + '</span></button>';
    });
    S.local.forEach(function (l) {
      h += '<button type="button" class="bo-thumb s-' + l.status + '" data-local="' + l.lid + '">' +
        '<img src="' + esc(l.url) + '" alt=""><span class="bo-ts">' + (l.status === 'failed' ? 'Retry' : 'Saving') + '</span></button>';
    });
    $('boThumbs').innerHTML = h;

    var c = { 'new': 0, reading: 0, done: 0, error: 0 };
    S.pages.forEach(function (p) { c[p.status] = (c[p.status] || 0) + 1; });
    var up = S.local.filter(function (l) { return l.status === 'uploading'; }).length;
    var parts = ['<b>' + S.pages.length + '</b> page' + (S.pages.length === 1 ? '' : 's')];
    if (c.done) parts.push(c.done + ' read');
    if (up) parts.push(up + ' saving');
    if (c.reading) parts.push(c.reading + ' reading');
    if (c['new']) parts.push(c['new'] + ' waiting');
    if (c.error) parts.push('<span style="color:#d32f2f">' + c.error + ' failed</span>');
    var btns = '';
    if (c.error) btns += '<button type="button" class="bo-btn small" data-act="retry">Retry failed</button>';
    if (c['new'] && !c.reading) btns += '<button type="button" class="bo-btn small" data-act="readwaiting">Read waiting</button>';
    $('boProgress').innerHTML = S.pages.length || S.local.length ? parts.join(' &middot; ') + btns : '';
    $('boShotCount').textContent = S.pages.length + S.local.length;

    // Keep the picker's page and piece counts current for the open batch.
    var b = S.batch && S.batches.find(function (x) { return x.id === S.batch.id; });
    if (b) {
      b.pages = S.pages.length;
      b.qty = S.items.reduce(function (a, it) { return a + (+it.excluded ? 0 : (+it.qty || 0)); }, 0);
      renderBatchSel(S.batch.id);
    }
  }

  function renderResults() {
    var el = $('boResults');
    if (!S.batch) { el.innerHTML = ''; return; }
    if (!S.items.length) {
      el.innerHTML = S.pages.length
        ? '<div class="bo-card bo-small">Lines will show up here as pages are read.</div>'
        : '';
      return;
    }
    var R = compute(S.items, S.codes);
    var h = '';

    // Need mapping
    if (R.unmapped.length) {
      h += '<section class="bo-card bo-alert"><h3 class="bo-h">Need mapping <span class="bo-n">' +
        'These codes are not tied to a blank yet, so they are left out of the tally below.</span></h3>';
      R.unmapped.forEach(function (u) {
        var prods = Object.keys(u.products).slice(0, 3).join(' / ');
        h += '<div class="bo-mapform" data-code="' + esc(u.code) + '">' +
          '<span class="bo-mcode">' + esc(u.code) + '</span>' +
          '<span class="bo-mdesc">' + u.qty + ' pcs on ' + u.lines + ' line' + (u.lines === 1 ? '' : 's') + (prods ? ': ' + esc(prods) : '') + '</span>';
        if (u.code === '(no code)') {
          h += '<span class="bo-small">Fix the code on these lines in the order list below.</span>';
        } else {
          h += '<select data-f="vendor"><option>S&amp;S</option><option>SanMar</option><option>Other</option></select>' +
            '<input type="text" data-f="style" placeholder="Style, e.g. PC54">' +
            '<input type="text" data-f="name" placeholder="Blank name (optional)">' +
            '<button type="button" class="bo-btn small primary" data-act="mapsave">Save</button>';
        }
        h += '</div>';
      });
      h += '</section>';
    }

    // Flags
    var pageFlags = S.pages.filter(function (p) {
      var n = S.items.filter(function (it) { return it.page_id === p.id; }).length;
      return p.status === 'error' || (p.status === 'done' && (p.note || !n));
    });
    if (R.flags.length || pageFlags.length || R.dupes.length) {
      h += '<section class="bo-card bo-alert amber"><h3 class="bo-h">Check these <span class="bo-n">' +
        (R.flags.length + pageFlags.length + R.dupes.length) + '</span></h3>';
      R.dupes.forEach(function (d) {
        h += '<div class="bo-flag"><span class="bo-ft"><b>Page ' + pageNo(d.page) + '</b> <span class="bo-why">Reads exactly like page ' +
          pageNo(d.of) + ', so it is probably the same sheet photographed twice. Both are counted until you delete one.</span></span>' +
          '<button type="button" class="bo-btn small" data-act="view" data-page="' + d.page + '">View page</button></div>';
      });
      pageFlags.forEach(function (p) {
        var msg = p.status === 'error' ? p.error : (p.note || 'No red-circled lines found on this page.');
        h += '<div class="bo-flag"><span class="bo-ft"><b>Page ' + pageNo(p.id) + '</b> <span class="bo-why">' + esc(msg) + '</span></span>' +
          '<button type="button" class="bo-btn small" data-act="view" data-page="' + p.id + '">View page</button></div>';
      });
      R.flags.forEach(function (f) {
        var it = f.item;
        h += '<div class="bo-flag"><span class="bo-ft"><b>' + esc(it.order_id || 'No order #') + '</b> ' + lineText(it) +
          '<br><span class="bo-why">' + esc(f.why.join('. ')) + '</span></span>' +
          '<button type="button" class="bo-btn small" data-act="edit" data-item="' + it.id + '">Fix</button>' +
          (it.page_id ? '<button type="button" class="bo-btn small" data-act="view" data-page="' + it.page_id + '">View page</button>' : '') +
          '</div>';
      });
      h += '</section>';
    }

    // Tally
    var grand = R.vendors.reduce(function (a, v) { return a + v.qty; }, 0);
    h += '<section class="bo-card"><h3 class="bo-h">Tally <span class="bo-n">' + grand + ' pcs to order</span>' +
      (R.vendors.length ? '<button type="button" class="bo-btn small" data-act="csv" style="margin-left:auto">Download CSV</button>' : '') + '</h3>';
    if (!R.vendors.length) h += '<div class="bo-small">Nothing mapped yet.</div>';
    R.vendors.forEach(function (v, vi) {
      h += '<div class="bo-vendor"><div class="bo-vhead"><span class="bo-vname">' + esc(v.vendor) + '</span>' +
        '<span class="bo-vqty">' + v.qty + ' pcs</span>' +
        '<button type="button" class="bo-btn small" data-act="copy" data-v="' + vi + '">Copy list</button></div>';
      v.list.forEach(function (st) {
        h += '<div class="bo-style"><div class="bo-stylename">' + esc(st.style) + (st.name ? '<small>' + esc(st.name) + '</small>' : '') +
          ' <small>' + st.qty + ' pcs</small></div><div class="bo-scroll"><table class="bo-t"><thead><tr><th>Color</th>';
        st.sizeList.forEach(function (sz) { h += '<th class="n">' + esc(sz) + '</th>'; });
        h += '<th class="n">Total</th></tr></thead><tbody>';
        st.colorList.forEach(function (c) {
          h += '<tr><td>' + esc(c.label) + '</td>';
          st.sizeList.forEach(function (sz) { h += '<td class="n">' + (c.sizes[sz] || '') + '</td>'; });
          h += '<td class="n"><b>' + c.qty + '</b></td></tr>';
        });
        if (st.colorList.length > 1) {
          h += '<tr class="tot"><td>All colors</td>';
          st.sizeList.forEach(function (sz) {
            var t = st.colorList.reduce(function (a, c) { return a + (c.sizes[sz] || 0); }, 0);
            h += '<td class="n">' + (t || '') + '</td>';
          });
          h += '<td class="n">' + st.qty + '</td></tr>';
        }
        h += '</tbody></table></div></div>';
      });
      h += '</div>';
    });
    h += '</section>';

    // Per-order checklist
    var orders = {}, order = [];
    S.items.forEach(function (it) {
      var k = it.order_id || '';
      if (!orders[k]) { orders[k] = { id: k, store: it.store, items: [] }; order.push(k); }
      if (!orders[k].store && it.store) orders[k].store = it.store;
      orders[k].items.push(it);
    });
    order.sort(function (a, b) { return (a === '') - (b === '') || a.localeCompare(b, undefined, { numeric: true }); });
    var ckd = S.items.filter(function (it) { return +it.checked; }).length;
    h += '<section class="bo-card"><h3 class="bo-h">Orders <span class="bo-n">' + order.length + ' orders, ' + ckd + ' of ' +
      S.items.length + ' lines checked</span>' +
      '<button type="button" class="bo-btn small" data-act="add" style="margin-left:auto">Add a line</button></h3>';
    if (S.editing === 'new') h += editorHtml(null);
    order.forEach(function (k) {
      var o = orders[k];
      var live = o.items.filter(function (it) { return !+it.excluded; });
      var qty = live.reduce(function (a, it) { return a + (+it.qty || 0); }, 0);
      var done = o.items.every(function (it) { return +it.checked || +it.excluded; });
      h += '<div class="bo-order"><div class="bo-ohead' + (done ? ' alldone' : '') + '"><span class="bo-oid">' + esc(o.id || 'No order number') + '</span>' +
        '<span class="bo-ostore">' + esc(o.store || '') + '</span>' +
        '<span class="bo-ocount">' + qty + ' pcs, ' + o.items.filter(function (it) { return +it.checked; }).length + '/' + o.items.length + ' checked</span></div>';
      o.items.forEach(function (it) {
        var code = normCode(it.code);
        var badges = '';
        if (+it.excluded) badges += '<span class="bo-badge ex">Left out</span>';
        else if (it.confidence === 'low') badges += '<span class="bo-badge low">Check</span>';
        if (!+it.excluded && (!code || !S.codes[code])) badges += '<span class="bo-badge map">No mapping</span>';
        if (+it.manual) badges += '<span class="bo-badge hand">' + (it.page_id ? 'Edited' : 'Added') + '</span>';
        h += '<div class="bo-line' + (+it.excluded ? ' ex' : '') + (+it.checked ? ' ck' : '') + '">' +
          '<input type="checkbox" data-act="check" data-item="' + it.id + '"' + (+it.checked ? ' checked' : '') + ' aria-label="Checked against the sheet">' +
          '<div class="bo-lt">' + lineText(it) + badges +
          (it.note ? '<div class="bo-lsub">' + esc(it.note) + '</div>' : '') + '</div>' +
          '<button type="button" class="bo-btn small" data-act="edit" data-item="' + it.id + '">Edit</button>' +
          (it.page_id ? '<button type="button" class="bo-btn small" data-act="view" data-page="' + it.page_id + '">Pg ' + pageNo(it.page_id) + '</button>' : '') +
          '</div>';
        if (S.editing === it.id) h += editorHtml(it);
      });
      h += '</div>';
    });
    h += '</section>';
    el.innerHTML = h;
  }

  function lineText(it) {
    var code = normCode(it.code);
    // OMG prints the code inside the product name too; show it once.
    var prod = String(it.product || '');
    if (code) prod = prod.replace(/\s*\[\s*([^\]]*)\]\s*/g, function (m, c) { return normCode(c) === code ? ' ' : m; }).trim();
    return '<span class="bo-lq">' + (+it.qty || 0) + ' &times;</span> ' +
      (code ? '[' + esc(code) + '] ' : '') + esc(prod) +
      '<span class="bo-lsub"> &middot; ' + esc(it.color || 'no color') + ' &middot; ' + esc(it.size || 'no size') + '</span>';
  }

  function editorHtml(it) {
    var v = it || { qty: 1, code: '', product: '', color: '', size: '', order_id: '', store: '' };
    function f(k, label, type) {
      return '<label>' + label + '<input type="' + (type || 'text') + '" data-f="' + k + '" value="' + esc(v[k]) + '"' +
        (type === 'number' ? ' min="0" inputmode="numeric"' : '') + '></label>';
    }
    return '<div class="bo-edit" data-edit="' + (it ? it.id : 'new') + '">' +
      f('qty', 'Qty', 'number') + f('code', 'Item code') + f('product', 'Product') + f('color', 'Color') +
      f('size', 'Size') + f('order_id', 'Order #') + f('store', 'Store / sale') +
      '<div class="bo-row"><button type="button" class="bo-btn small primary" data-act="save">Save</button>' +
      (it ? '<button type="button" class="bo-btn small" data-act="exclude">' + (+it.excluded ? 'Put back in' : 'Leave out') + '</button>' : '') +
      (it && +it.manual && !it.page_id ? '<button type="button" class="bo-btn small danger" data-act="delete">Delete</button>' : '') +
      '<button type="button" class="bo-btn small" data-act="cancel">Cancel</button></div></div>';
  }

  function renderCodes() {
    var keys = Object.keys(S.codes).sort(function (a, b) { return a.localeCompare(b, undefined, { numeric: true }); });
    var h = '<div class="bo-scroll"><table class="bo-t"><thead><tr><th>Code</th><th>Vendor</th><th>Style</th><th>Blank</th><th></th></tr></thead><tbody>';
    keys.forEach(function (k) {
      var m = S.codes[k];
      h += '<tr><td><b>' + esc(k) + '</b></td><td>' + esc(m.vendor) + '</td><td>' + esc(m.style) + '</td><td>' + esc(m.name || '') + '</td>' +
        '<td><button type="button" class="bo-btn small danger" data-act="mapdel" data-code="' + esc(k) + '">Remove</button></td></tr>';
    });
    h += '</tbody></table></div>' +
      '<div class="bo-mapform" data-code=""><input type="text" data-f="code" placeholder="Item code">' +
      '<select data-f="vendor"><option>S&amp;S</option><option>SanMar</option><option>Other</option></select>' +
      '<input type="text" data-f="style" placeholder="Style">' +
      '<input type="text" data-f="name" placeholder="Blank name (optional)">' +
      '<button type="button" class="bo-btn small primary" data-act="mapsave">Add</button></div>';
    $('boCodes').innerHTML = h;
  }

  /* ── page viewer ────────────────────────────────────────────────────── */

  var viewing = null;
  function openPage(id) {
    var p = pageById(id);
    if (!p) return;
    viewing = id;
    $('boModalTitle').textContent = 'Page ' + pageNo(id) + (p.form ? ' · ' + p.form : '') + (p.order_id ? ' · ' + p.order_id : '');
    $('boModalImg').src = p.localUrl || p.img;
    $('boModalImgWrap').classList.remove('zoom');
    var lines = S.items.filter(function (it) { return it.page_id === id; });
    var h = '';
    if (p.status === 'error') h += '<div class="bo-pnote err">' + esc(p.error) + '</div>';
    if (p.note) h += '<div class="bo-pnote">' + esc(p.note) + '</div>';
    if (p.status === 'reading') h += '<div class="bo-pnote">Reading this page now.</div>';
    if (lines.length) {
      h += '<table class="bo-t" style="margin-top:8px"><tbody>';
      lines.forEach(function (it) { h += '<tr><td>' + lineText(it) + (it.confidence === 'low' ? '<span class="bo-badge low">Check</span>' : '') + '</td></tr>'; });
      h += '</tbody></table>';
    } else if (p.status === 'done') {
      h += '<div class="bo-small">No red-circled lines found.</div>';
    }
    $('boModalInfo').innerHTML = h;
    $('boModalReread').disabled = p.status === 'reading';
    $('boModal').hidden = false;
  }
  function closePage() { $('boModal').hidden = true; viewing = null; }

  /* ── actions ────────────────────────────────────────────────────────── */

  function readEditor(box) {
    var o = {};
    box.querySelectorAll('[data-f]').forEach(function (inp) { o[inp.dataset.f] = inp.value; });
    if ('qty' in o) o.qty = parseInt(o.qty, 10) || 0;
    return o;
  }
  function itemById(id) { for (var i = 0; i < S.items.length; i++) if (S.items[i].id === id) return S.items[i]; return null; }
  function putItem(id, fields) {
    return api('/blanks/items/' + id, 'PUT', fields).then(function (row) {
      S.items = S.items.map(function (it) { return it.id === id ? row : it; });
    });
  }

  function onClick(e) {
    var t = e.target.closest('[data-act],[data-page],[data-local]');
    if (!t || !$('btp-blanks').contains(t)) return;
    var act = t.dataset.act;

    if (!act && t.dataset.local) {
      var l = S.local.find(function (x) { return x.lid === t.dataset.local; });
      if (l && l.status === 'failed') { l.status = 'uploading'; renderPages(); pumpUploads(); }
      return;
    }
    if (!act && t.dataset.page) { openPage(+t.dataset.page); return; }

    if (act === 'view') { openPage(+t.dataset.page); return; }
    if (act === 'retry') { S.pages.forEach(function (p) { if (p.status === 'error') queueRead(p.id); }); return; }
    if (act === 'readwaiting') { S.pages.forEach(function (p) { if (p.status === 'new') queueRead(p.id); }); return; }

    if (act === 'edit') { S.editing = +t.dataset.item; renderResults(); scrollToEditor(); return; }
    if (act === 'add') { S.editing = 'new'; renderResults(); scrollToEditor(); return; }
    if (act === 'cancel') { S.editing = null; renderResults(); return; }

    if (act === 'save' || act === 'exclude' || act === 'delete') {
      var box = t.closest('[data-edit]');
      var key = box.dataset.edit;
      var p;
      if (act === 'save' && key === 'new') {
        var o = readEditor(box);
        o.batch_id = S.batch.id;
        p = api('/blanks/items', 'POST', o).then(function (row) { S.items.push(row); });
      } else if (act === 'save') {
        p = putItem(+key, readEditor(box));
      } else if (act === 'exclude') {
        var it = itemById(+key);
        p = putItem(+key, { excluded: +it.excluded ? 0 : 1 });
      } else {
        if (!window.confirm('Delete this line?')) return;
        p = api('/blanks/items/' + key, 'DELETE').then(function () {
          S.items = S.items.filter(function (x) { return x.id !== +key; });
        });
      }
      t.disabled = true;
      p.then(function () { S.editing = null; renderAll(); }).catch(function (err) { t.disabled = false; toast(err.message); });
      return;
    }

    if (act === 'mapsave') {
      var row = t.closest('.bo-mapform');
      var f = {};
      row.querySelectorAll('[data-f]').forEach(function (x) { f[x.dataset.f] = x.value.trim(); });
      f.code = f.code || row.dataset.code;
      if (!f.code || !f.style) { toast('Enter the code and the style.'); return; }
      t.disabled = true;
      api('/blanks/codes', 'POST', f).then(function (codes) { S.codes = codes; renderAll(); toast(f.code + ' mapped to ' + f.vendor + ' ' + f.style); })
        .catch(function (err) { t.disabled = false; toast(err.message); });
      return;
    }
    if (act === 'mapdel') {
      var c = t.dataset.code;
      if (!window.confirm('Remove the mapping for ' + c + '?')) return;
      api('/blanks/codes/' + encodeURIComponent(c), 'DELETE').then(function (codes) { S.codes = codes; renderAll(); })
        .catch(function (err) { toast(err.message); });
      return;
    }

    if (act === 'copy') {
      var R = compute(S.items, S.codes);
      var v = R.vendors[+t.dataset.v];
      if (!v) return;
      var text = ['Style\tColor\tSize\tQty'].concat(vendorLines(v)).join('\n');
      copyText(text).then(function () { toast(v.vendor + ' list copied, ' + v.qty + ' pcs'); });
      return;
    }
    if (act === 'csv') { downloadCsv(); return; }
  }

  function onChange(e) {
    var t = e.target;
    if (t.dataset && t.dataset.act === 'check') {
      var id = +t.dataset.item;
      putItem(id, { checked: t.checked ? 1 : 0 }).then(renderResults).catch(function (err) { t.checked = !t.checked; toast(err.message); });
    }
  }

  function scrollToEditor() {
    var ed = document.querySelector('#btp-blanks .bo-edit');
    if (ed) { ed.scrollIntoView({ block: 'center', behavior: 'smooth' }); var i = ed.querySelector('input'); if (i) i.focus(); }
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    return Promise.resolve();
  }

  function csvCell(s) { s = String(s == null ? '' : s); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; }
  function downloadCsv() {
    var R = compute(S.items, S.codes);
    var rows = [['Vendor', 'Style', 'Blank', 'Color', 'Size', 'Qty']];
    R.vendors.forEach(function (v) {
      v.list.forEach(function (st) {
        st.colorList.forEach(function (c) {
          st.sizeList.forEach(function (sz) { if (c.sizes[sz]) rows.push([v.vendor, st.style, st.name, c.label, sz, c.sizes[sz]]); });
        });
      });
    });
    R.unmapped.forEach(function (u) { rows.push(['NEED MAPPING', u.code, Object.keys(u.products).join(' / '), '', '', u.qty]); });
    var csv = rows.map(function (r) { return r.map(csvCell).join(','); }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    a.download = (S.batch ? S.batch.name : 'blanks').replace(/[^\w\- ]+/g, '') + '.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
  }

  /* ── camera ─────────────────────────────────────────────────────────── */

  var SIG_W = 96, SIG_H = 72;
  var STILL = 3.5;   // mean pixel change (0-255) between samples that counts as holding still
  var MOVE  = 8;     // ... and as a page being flipped
  var SAME  = 5;     // shift-tolerant distance under which a still frame is the last shot again

  var cam = { stream: null, track: null, timer: null, auto: true, prev: null, lastSig: null,
              stillSince: 0, armed: true, busy: false, torch: false, wake: null, audio: null, sigCanvas: null };

  function camSupported() { return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia); }

  function camStart() {
    if (!camSupported()) { toast('This browser cannot open the camera here. Use Add photos instead.'); return; }
    // The beep has to be unlocked inside the tap itself or iPhones keep it silent.
    try { if (!cam.audio && (window.AudioContext || window.webkitAudioContext)) cam.audio = new (window.AudioContext || window.webkitAudioContext)(); if (cam.audio) cam.audio.resume(); } catch (e) {}
    var ready = S.batch ? Promise.resolve(true) : newBatch();
    ready.then(function (ok) {
      if (!ok || !S.batch) return;
      return navigator.mediaDevices.getUserMedia({
        audio: false,
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 3840 }, height: { ideal: 2160 } }
      }).then(function (stream) {
        cam.stream = stream;
        cam.track = stream.getVideoTracks()[0];
        var v = $('boVideo');
        v.srcObject = stream;
        return v.play().catch(function () {});
      }).then(function () {
        try {
          var caps = cam.track.getCapabilities ? cam.track.getCapabilities() : {};
          if (caps.focusMode && caps.focusMode.indexOf('continuous') > -1) cam.track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function () {});
          $('boTorch').hidden = !caps.torch;
        } catch (e) { $('boTorch').hidden = true; }
        try { if (navigator.wakeLock) navigator.wakeLock.request('screen').then(function (w) { cam.wake = w; }).catch(function () {}); } catch (e) {}
        cam.prev = null; cam.lastSig = null; cam.stillSince = 0; cam.armed = true;
        $('boCamIdle').hidden = true; $('boHud').hidden = false; $('boShotCount').hidden = false;
        $('boCamStop').hidden = false; $('boShutter').disabled = false;
        hud(cam.auto ? 'Hold the first page still' : 'Tap Snap for each page', 0);
        cam.timer = setInterval(tick, 120);
      }).catch(function (e) {
        var m = e && e.name === 'NotAllowedError'
          ? 'Camera permission was turned down. Allow it in the browser settings, or use Add photos.'
          : 'Could not open the camera (' + (e && e.message ? e.message : e) + '). Use Add photos instead.';
        toast(m);
        camStop();
      });
    });
  }

  function camStop() {
    if (cam.timer) clearInterval(cam.timer);
    cam.timer = null;
    if (cam.stream) cam.stream.getTracks().forEach(function (t) { t.stop(); });
    cam.stream = null; cam.track = null; cam.torch = false;
    if (cam.wake) { try { cam.wake.release(); } catch (e) {} cam.wake = null; }
    var v = $('boVideo'); if (v) v.srcObject = null;
    if ($('boCamIdle')) {
      $('boCamIdle').hidden = false; $('boHud').hidden = true; $('boCamStop').hidden = true;
      $('boShutter').disabled = true; $('boTorch').hidden = true; $('boTorch').textContent = 'Light';
    }
  }

  function hud(msg, frac) {
    $('boHudMsg').textContent = msg;
    $('boMeter').style.width = Math.round(Math.max(0, Math.min(1, frac)) * 100) + '%';
  }

  /** Small grayscale copy of the current frame, plus its brightness and contrast. */
  function signature() {
    var v = $('boVideo');
    if (!v.videoWidth) return null;
    var c = cam.sigCanvas || (cam.sigCanvas = document.createElement('canvas'));
    c.width = SIG_W; c.height = SIG_H;
    var ctx = c.getContext('2d', { willReadFrequently: true });
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(v, 0, 0, SIG_W, SIG_H);
    var d = ctx.getImageData(0, 0, SIG_W, SIG_H).data;
    var g = new Uint8Array(SIG_W * SIG_H), sum = 0, sq = 0;
    for (var i = 0, j = 0; i < d.length; i += 4, j++) {
      var y = (d[i] * 3 + d[i + 1] * 6 + d[i + 2]) / 10;
      g[j] = y; sum += y; sq += y * y;
    }
    var mean = sum / g.length;
    g.std = Math.sqrt(Math.max(0, sq / g.length - mean * mean));
    g.mean = mean;
    return g;
  }
  function sigDiff(a, b) {
    var t = 0;
    for (var i = 0; i < a.length; i++) t += Math.abs(a[i] - b[i]);
    return t / a.length;
  }
  /** How different two frames are once a nudge of up to 2 samples and a
      change in exposure are forgiven. Used only to ask "same sheet?". */
  function pageDiff(a, b, w, h) {
    var best = Infinity, R = 2;
    for (var dy = -R; dy <= R; dy++) {
      for (var dx = -R; dx <= R; dx++) {
        var x0 = Math.max(0, dx), x1 = w + Math.min(0, dx), y0 = Math.max(0, dy), y1 = h + Math.min(0, dy);
        var sa = 0, sb = 0, n = 0, x, y;
        for (y = y0; y < y1; y++) for (x = x0; x < x1; x++) { sa += a[y * w + x]; sb += b[(y - dy) * w + x - dx]; n++; }
        var off = (sa - sb) / n, t = 0;
        for (y = y0; y < y1; y++) for (x = x0; x < x1; x++) t += Math.abs(a[y * w + x] - b[(y - dy) * w + x - dx] - off);
        if (t / n < best) best = t / n;
      }
    }
    return best;
  }

  function tick() {
    if (!cam.stream || cam.busy) return;
    if (!paneActive() || document.hidden) { camStop(); return; }
    if (!cam.auto) return;
    var sig = signature();
    if (!sig) return;
    if (!cam.prev) { cam.prev = sig; return; }
    var d = sigDiff(sig, cam.prev);
    cam.prev = sig;
    var hold = parseInt($('boHold').value, 10) || 800;
    var now = performance.now();

    if (d > MOVE) {
      cam.stillSince = 0;
      cam.armed = true;
      hud('Flipping…', 0);
      return;
    }
    if (d > STILL) { cam.stillSince = 0; hud(cam.armed ? 'Hold it still' : 'Flip to the next page', 0); return; }

    if (!cam.stillSince) cam.stillSince = now;
    var held = now - cam.stillSince;
    if (!cam.armed) { hud('Got it. Flip to the next page', 0); return; }
    if (sig.std < 18 || sig.mean < 40) { hud('Point the camera at a page', 0); return; }
    hud('Hold it still', held / hold);
    if (held < hold) return;

    cam.armed = false;
    if (cam.lastSig && pageDiff(sig, cam.lastSig, SIG_W, SIG_H) < SAME) { hud('Same page as the last shot. Flip to the next one, or tap Snap', 0); return; }
    snap(sig);
  }

  function snap(sig) {
    var v = $('boVideo');
    if (!v.videoWidth || cam.busy) return;
    cam.busy = true;
    var w = v.videoWidth, h = v.videoHeight, k = Math.min(1, 2400 / Math.max(w, h));
    var c = document.createElement('canvas');
    c.width = Math.round(w * k); c.height = Math.round(h * k);
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    cam.lastSig = sig || signature();
    feedback();
    c.toBlob(function (blob) {
      cam.busy = false;
      if (blob) addCapture(blob);
      hud(cam.auto ? 'Got it. Flip to the next page' : 'Tap Snap for each page', 0);
    }, 'image/jpeg', 0.88);
  }

  function feedback() {
    var f = $('boFlash');
    f.classList.remove('on'); void f.offsetWidth; f.classList.add('on');
    try { if (navigator.vibrate) navigator.vibrate(60); } catch (e) {}
    try {
      if (cam.audio) {
        var o = cam.audio.createOscillator(), g = cam.audio.createGain();
        o.frequency.value = 1320; g.gain.value = 0.08;
        o.connect(g); g.connect(cam.audio.destination);
        o.start(); o.stop(cam.audio.currentTime + 0.08);
      }
    } catch (e) {}
  }

  function toggleTorch() {
    if (!cam.track) return;
    cam.torch = !cam.torch;
    cam.track.applyConstraints({ advanced: [{ torch: cam.torch }] }).then(function () {
      $('boTorch').textContent = cam.torch ? 'Light: on' : 'Light';
    }).catch(function () { cam.torch = false; toast('This camera has no light the page can use.'); });
  }

  /** Photos from the phone's own camera app or library, shrunk to the same size as a snap. */
  function addFiles(files) {
    var ready = S.batch ? Promise.resolve(true) : newBatch();
    ready.then(function (ok) {
      if (!ok || !S.batch) return;
      Array.prototype.reduce.call(files, function (p, file) {
        return p.then(function () { return shrink(file).then(addCapture).catch(function () { toast(file.name + ' could not be opened.'); }); });
      }, Promise.resolve());
    });
  }
  function shrink(file) {
    return new Promise(function (res, rej) {
      var img = new Image();
      var url = URL.createObjectURL(file);
      img.onload = function () {
        var k = Math.min(1, 2400 / Math.max(img.naturalWidth, img.naturalHeight));
        var c = document.createElement('canvas');
        c.width = Math.round(img.naturalWidth * k); c.height = Math.round(img.naturalHeight * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function (b) { b ? res(b) : rej(new Error('encode')); }, 'image/jpeg', 0.88);
      };
      img.onerror = function () { URL.revokeObjectURL(url); rej(new Error('decode')); };
      img.src = url;
    });
  }

  /* ── keeping a second screen current ────────────────────────────────── */

  var lastSeen = '';
  function poll() {
    if (!S.batch || !paneActive() || document.hidden || S.editing || viewing) return;
    var id = S.batch.id;
    api('/blanks/batches/' + id).then(function (d) {
      if (!S.batch || S.batch.id !== id || S.editing) return;
      var sig = JSON.stringify([d.pages.map(function (p) { return [p.id, p.status]; }), d.items, d.codes]);
      if (sig === lastSeen) return;
      lastSeen = sig;
      applyPayload(d, false);
      renderAll();
    }).catch(function () {});
  }

  /* ── wiring ─────────────────────────────────────────────────────────── */

  function wire() {
    var root = $('btp-blanks');
    root.addEventListener('click', onClick);
    root.addEventListener('change', onChange);

    $('boBatchSel').addEventListener('change', function () {
      var id = +this.value;
      if (!id) return;
      S.editing = null;
      openBatch(id);
    });
    $('boNewBatch').addEventListener('click', function () { newBatch(); });
    $('boRename').addEventListener('click', function () {
      if (!S.batch) return;
      var n = window.prompt('Rename this batch', S.batch.name);
      if (!n) return;
      api('/blanks/batches/' + S.batch.id, 'PUT', { name: n }).then(function () { S.batch.name = n; return loadBatches(S.batch.id); })
        .catch(function (e) { toast(e.message); });
    });
    $('boDelBatch').addEventListener('click', function () {
      if (!S.batch) return;
      if (!window.confirm('Delete "' + S.batch.name + '" with all of its photos and lines? This cannot be undone.')) return;
      camStop();
      api('/blanks/batches/' + S.batch.id, 'DELETE').then(function () {
        S.batch = null; S.pages = []; S.items = []; S.local = [];
        try { localStorage.removeItem(LS); } catch (e) {}
        showBody(false);
        return loadBatches();
      }).catch(function (e) { toast(e.message); });
    });

    $('boCamStart').addEventListener('click', camStart);
    $('boCamStop').addEventListener('click', camStop);
    $('boShutter').addEventListener('click', function () { cam.armed = false; snap(null); });
    $('boVideo').addEventListener('click', function () { if (cam.stream && !cam.auto) snap(null); });
    $('boTorch').addEventListener('click', toggleTorch);
    $('boAuto').addEventListener('click', function () {
      cam.auto = !cam.auto;
      this.textContent = 'Auto: ' + (cam.auto ? 'on' : 'off');
      this.setAttribute('aria-pressed', cam.auto ? 'true' : 'false');
      cam.stillSince = 0; cam.armed = true;
      if (cam.stream) hud(cam.auto ? 'Hold the page still' : 'Tap Snap for each page', 0);
    });
    $('boFiles').addEventListener('change', function () {
      if (this.files && this.files.length) addFiles(this.files);
      this.value = '';
    });

    $('boModalClose').addEventListener('click', closePage);
    $('boModal').addEventListener('click', function (e) { if (e.target === this) closePage(); });
    $('boModalImg').addEventListener('click', function () { $('boModalImgWrap').classList.toggle('zoom'); });
    $('boModalReread').addEventListener('click', function () {
      if (!viewing) return;
      var id = viewing;
      var fixed = S.items.some(function (it) { return it.page_id === id && +it.manual; });
      if (fixed && !window.confirm('Reading this page again replaces its lines, including the ones you corrected. Go ahead?')) return;
      closePage();
      queueRead(id);
    });
    $('boModalDelete').addEventListener('click', function () {
      if (!viewing || !window.confirm('Delete this page and the lines read from it?')) return;
      var id = viewing;
      closePage();
      api('/blanks/pages/' + id, 'DELETE').then(function () {
        S.pages = S.pages.filter(function (p) { return p.id !== id; });
        S.items = S.items.filter(function (it) { return it.page_id !== id; });
        renderAll();
      }).catch(function (e) { toast(e.message); });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && viewing) closePage(); });
    document.addEventListener('visibilitychange', function () { if (document.hidden && cam.stream) camStop(); });
    setInterval(poll, 10000);
  }

  // Called by btSwitchTab() each time the tab opens.
  window.btpBlanksLoad = function () {
    if (!S.loaded) { S.loaded = true; wire(); }
    loadBatches();
  };
  // ... and when it is left, so the camera never runs behind another tab.
  window.btpBlanksLeave = function () { if (cam.stream) camStop(); };

  // For tests.
  window.btpBlanksLogic = { normSize: normSize, normCode: normCode, compute: compute, vendorLines: vendorLines, colorLabel: colorLabel };
})();
</script>
    <?php
    return ob_get_clean();
}
