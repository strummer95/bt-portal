<?php
/**
 * BT Portal — Vendors (Other > Vendors).
 *
 * Replaces the shared vendor spreadsheet: phone, fax, account number, login,
 * password, website, address, rep notes, one row per vendor.
 *
 * ── On storing the passwords ──────────────────────────────────────────────
 * These are third-party logins that a human has to be able to read back, so
 * they cannot be hashed the way portal account passwords are. They are
 * encrypted at rest with libsodium (AES-256-GCM as a fallback) and the key
 * lives in a file OUTSIDE the database, so a stolen database dump on its own
 * is useless.
 *
 * The key file is written to wp-content/uploads on first use because the shop
 * has no shell access to edit wp-config.php. If that ever changes, define
 * BT_VENDOR_KEY in wp-config.php and it takes precedence — that is the
 * stronger placement, since backups routinely include uploads.
 *
 * Every reveal is written to the audit table. That is the point of doing this
 * here rather than in a shared note: you can tell who looked at what.
 */

if (!defined('ABSPATH')) exit;

define('BTP_VENDOR_DB_VERSION', '1');

/* ─────────────────────────────────────────────────────────────────────────
   TABLES
   ───────────────────────────────────────────────────────────────────── */

function btp_vendor_table()     { global $wpdb; return $wpdb->prefix . 'bt_vendors'; }
function btp_vendor_log_table() { global $wpdb; return $wpdb->prefix . 'bt_vendor_log'; }

function btp_vendor_install() {
    if (get_option('btp_vendor_db_version') === BTP_VENDOR_DB_VERSION) return;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $GLOBALS['wpdb']->get_charset_collate();
    $t   = btp_vendor_table();
    $log = btp_vendor_log_table();

    dbDelta("CREATE TABLE IF NOT EXISTS $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(190) NOT NULL DEFAULT '',
        category VARCHAR(60) NOT NULL DEFAULT '',
        phone VARCHAR(120) NOT NULL DEFAULT '',
        fax VARCHAR(120) NOT NULL DEFAULT '',
        account_no VARCHAR(190) NOT NULL DEFAULT '',
        login VARCHAR(190) NOT NULL DEFAULT '',
        secret TEXT NULL,
        website VARCHAR(255) NOT NULL DEFAULT '',
        address TEXT NULL,
        notes TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY category (category),
        KEY name (name)
    ) $charset;");

    dbDelta("CREATE TABLE IF NOT EXISTS $log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        vendor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        vendor_name VARCHAR(190) NOT NULL DEFAULT '',
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        user_name VARCHAR(120) NOT NULL DEFAULT '',
        action VARCHAR(20) NOT NULL DEFAULT 'reveal',
        seen_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY vendor_id (vendor_id),
        KEY seen_at (seen_at)
    ) $charset;");

    update_option('btp_vendor_db_version', BTP_VENDOR_DB_VERSION);

    btp_vendor_seed();   // one-time import of the old spreadsheet
}
add_action('init', 'btp_vendor_install', 2);

/* Later additions run on their own, after the table exists, so they reach
   sites where the first import already happened. */
function btp_vendor_seed_later() {
    global $wpdb;
    if ( ! get_option('btp_vendor_db_version') ) return;
    if ( function_exists('btp_vendor_seed_2') ) btp_vendor_seed_2();
}
add_action('init', 'btp_vendor_seed_later', 3);

/* ─────────────────────────────────────────────────────────────────────────
   ENCRYPTION
   ───────────────────────────────────────────────────────────────────── */

/**
 * The key, as raw bytes. Prefers a wp-config.php constant; otherwise a file
 * in uploads that is created once, chmod 0600, and shielded from the web.
 */
function btp_vendor_key() {
    static $key = null;
    if ($key !== null) return $key;

    if (defined('BT_VENDOR_KEY') && BT_VENDOR_KEY) {
        $key = base64_decode(BT_VENDOR_KEY);
        return $key;
    }

    $dir  = wp_upload_dir();
    $path = trailingslashit($dir['basedir']) . '.bt-vendor-key.php';

    if (file_exists($path)) {
        $stored = include $path;
        if (is_string($stored) && $stored !== '') {
            $key = base64_decode($stored);
            return $key;
        }
    }

    // A key already parked in the database by an earlier run, because the
    // uploads directory could not be written to.
    $fallback = get_option('btp_vendor_key_fallback');
    if ($fallback) {
        $key = base64_decode($fallback);
        return $key;
    }

    $raw = function_exists('sodium_crypto_secretbox_keygen')
        ? sodium_crypto_secretbox_keygen()
        : random_bytes(32);

    $written = @file_put_contents($path,
        "<?php\n// BT Portal vendor credential key. Do not delete — the stored\n"
        . "// vendor passwords cannot be read without it.\nreturn '" . base64_encode($raw) . "';\n");

    if ($written === false) {
        /* If uploads is not writable, the key would be regenerated on every
           single request and every stored password would decrypt to nothing —
           silently, and only noticed when someone pressed SHOW. Parking it in
           the database is weaker than a file outside the database, but a key
           that survives is worth more than a stronger one that doesn't. The
           admin notice says so plainly. */
        update_option('btp_vendor_key_fallback', base64_encode($raw), false);
        update_option('btp_vendor_key_in_db', 1);
    } else {
        @chmod($path, 0600);
        // Belt and braces: uploads is web-readable on most hosts.
        $ht = trailingslashit($dir['basedir']) . '.htaccess';
        if (!file_exists($ht)) @file_put_contents($ht, "<FilesMatch \"^\\.bt-vendor-key\\.php$\">\nRequire all denied\n</FilesMatch>\n");
    }

    $key = $raw;
    return $key;
}

function btp_vendor_encrypt($plain) {
    if ($plain === '' || $plain === null) return '';
    $key = btp_vendor_key();

    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'o1:' . base64_encode($iv . $tag . $ct);
}

function btp_vendor_decrypt($blob) {
    if (!$blob) return '';
    $key = btp_vendor_key();

    if (strpos($blob, 'v1:') === 0 && function_exists('sodium_crypto_secretbox_open')) {
        $raw   = base64_decode(substr($blob, 3));
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct    = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $out   = sodium_crypto_secretbox_open($ct, $nonce, $key);
        return $out === false ? '' : $out;
    }

    if (strpos($blob, 'o1:') === 0) {
        $raw = base64_decode(substr($blob, 3));
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $out = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $out === false ? '' : $out;
    }

    return '';
}

/* ─────────────────────────────────────────────────────────────────────────
   CAPABILITIES
   ───────────────────────────────────────────────────────────────────── */

/** Anyone in the portal can look a vendor up — that is the whole point. */
function btp_rest_can_vendors() {
    return is_user_logged_in() && current_user_can('bt_portal_access');
}

/** Editing the list is portal-admin work. */
function btp_rest_can_edit_vendors() {
    return is_user_logged_in() && current_user_can('bt_manage_portal_users');
}

/* ─────────────────────────────────────────────────────────────────────────
   REST
   ───────────────────────────────────────────────────────────────────── */

add_action('rest_api_init', function() {
    $ns = 'boomerts/v1';

    register_rest_route($ns, '/vendors', array(
        'methods' => 'GET', 'callback' => 'btp_rest_vendors',
        'permission_callback' => 'btp_rest_can_vendors'));

    register_rest_route($ns, '/vendors', array(
        'methods' => 'POST', 'callback' => 'btp_rest_vendor_create',
        'permission_callback' => 'btp_rest_can_edit_vendors'));

    register_rest_route($ns, '/vendors/(?P<id>\d+)', array(
        'methods' => 'POST', 'callback' => 'btp_rest_vendor_save',
        'permission_callback' => 'btp_rest_can_edit_vendors'));

    register_rest_route($ns, '/vendors/(?P<id>\d+)', array(
        'methods' => 'DELETE', 'callback' => 'btp_rest_vendor_delete',
        'permission_callback' => 'btp_rest_can_edit_vendors'));

    register_rest_route($ns, '/vendors/(?P<id>\d+)/secret', array(
        'methods' => 'POST', 'callback' => 'btp_rest_vendor_secret',
        'permission_callback' => 'btp_rest_can_vendors'));

    register_rest_route($ns, '/vendors/log', array(
        'methods' => 'GET', 'callback' => 'btp_rest_vendor_log',
        'permission_callback' => 'btp_rest_can_edit_vendors'));
});

/** Row shape for the list. Never carries the decrypted password. */
function btp_vendor_row($r) {
    return array(
        'id'         => (int) $r->id,
        'name'       => $r->name,
        'category'   => $r->category,
        'phone'      => $r->phone,
        'fax'        => $r->fax,
        'account_no' => $r->account_no,
        'login'      => $r->login,
        'has_secret' => !empty($r->secret),
        'website'    => $r->website,
        'address'    => $r->address,
        'notes'      => $r->notes,
    );
}

function btp_rest_vendors() {
    global $wpdb;
    $t    = btp_vendor_table();
    $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY name ASC");
    $out  = array();
    foreach ($rows as $r) $out[] = btp_vendor_row($r);

    return rest_ensure_response(array(
        'vendors'    => $out,
        'categories' => btp_vendor_categories(),
        'can_edit'   => current_user_can('bt_manage_portal_users'),
    ));
}

function btp_vendor_categories() {
    return array('Apparel', 'Decoration', 'Equipment', 'Supplies', 'Promo',
                 'Shipping', 'Software', 'Social', 'Marketing', 'Utilities',
                 'Financial', 'Internal', 'Other');
}

function btp_vendor_fields_from_request($req) {
    $p = $req->get_json_params();
    return array(
        'name'       => sanitize_text_field($p['name'] ?? ''),
        'category'   => sanitize_text_field($p['category'] ?? 'Other'),
        'phone'      => sanitize_text_field($p['phone'] ?? ''),
        'fax'        => sanitize_text_field($p['fax'] ?? ''),
        'account_no' => sanitize_text_field($p['account_no'] ?? ''),
        'login'      => sanitize_text_field($p['login'] ?? ''),
        'website'    => esc_url_raw($p['website'] ?? ''),
        'address'    => sanitize_textarea_field($p['address'] ?? ''),
        'notes'      => sanitize_textarea_field($p['notes'] ?? ''),
        '_secret'    => array_key_exists('secret', $p) ? (string) $p['secret'] : null,
    );
}

function btp_rest_vendor_create($req) {
    global $wpdb;
    $f = btp_vendor_fields_from_request($req);
    if ($f['name'] === '') return new WP_Error('btp_bad', 'A vendor name is required.', array('status' => 400));

    $secret = $f['_secret'];
    unset($f['_secret']);
    $f['secret']     = ($secret === null || $secret === '') ? '' : btp_vendor_encrypt($secret);
    $f['updated_at'] = current_time('mysql');

    $wpdb->insert(btp_vendor_table(), $f);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . btp_vendor_table() . " WHERE id=%d", $wpdb->insert_id));
    return rest_ensure_response(btp_vendor_row($row));
}

function btp_rest_vendor_save($req) {
    global $wpdb;
    $id = (int) $req['id'];
    $t  = btp_vendor_table();
    if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE id=%d", $id))) {
        return new WP_Error('btp_no_vendor', 'No such vendor.', array('status' => 404));
    }

    $f      = btp_vendor_fields_from_request($req);
    $secret = $f['_secret'];
    unset($f['_secret']);

    // null means "left alone"; empty string means "clear it".
    if ($secret !== null) {
        $f['secret'] = ($secret === '') ? '' : btp_vendor_encrypt($secret);
    }
    $f['updated_at'] = current_time('mysql');

    $wpdb->update($t, $f, array('id' => $id));
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id));
    return rest_ensure_response(btp_vendor_row($row));
}

function btp_rest_vendor_delete($req) {
    global $wpdb;
    $id = (int) $req['id'];
    $wpdb->delete(btp_vendor_table(), array('id' => $id));
    return rest_ensure_response(array('ok' => true, 'id' => $id));
}

/**
 * Hand back one password, and write down who asked. POST rather than GET so
 * it never lands in a server access log or a browser history entry.
 */
function btp_rest_vendor_secret($req) {
    global $wpdb;
    $id  = (int) $req['id'];
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . btp_vendor_table() . " WHERE id=%d", $id));
    if (!$row) return new WP_Error('btp_no_vendor', 'No such vendor.', array('status' => 404));

    $wpdb->insert(btp_vendor_log_table(), array(
        'vendor_id'   => $id,
        'vendor_name' => $row->name,
        'user_id'     => get_current_user_id(),
        'user_name'   => btp_actor_name(),
        'action'      => 'reveal',
        'seen_at'     => current_time('mysql'),
    ));

    return rest_ensure_response(array('id' => $id, 'secret' => btp_vendor_decrypt($row->secret)));
}

function btp_rest_vendor_log() {
    global $wpdb;
    $log  = btp_vendor_log_table();
    $rows = $wpdb->get_results("SELECT * FROM $log ORDER BY seen_at DESC LIMIT 200");
    $out  = array();
    foreach ($rows as $r) {
        $out[] = array(
            'vendor' => $r->vendor_name,
            'who'    => $r->user_name,
            'when'   => mysql2date('M j, g:i a', $r->seen_at),
        );
    }
    return rest_ensure_response(array('log' => $out));
}

/** Say so if the credential key had to fall back into the database. */
function btp_vendor_key_notice() {
    if (!current_user_can('manage_options')) return;
    if (!get_option('btp_vendor_key_in_db')) return;

    echo '<div class="notice notice-warning"><p><strong>BT Portal:</strong> the vendor password key '
       . 'could not be written to the uploads folder, so it is stored in the database instead. The '
       . 'passwords still work, but a database backup now contains both the locked box and its key. '
       . 'Fixing the uploads folder permissions, or adding a BT_VENDOR_KEY line to wp-config.php, '
       . 'restores the stronger setup.</p></div>';
}
add_action('admin_notices', 'btp_vendor_key_notice');
