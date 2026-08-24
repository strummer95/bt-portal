<?php
/**
 * BT Portal — Portal users, login gate, password setup, and identity.
 *
 * Replaces two things that used to stand in for a login:
 *   1. The WordPress page password on /employees/  -> a real login screen.
 *   2. The header "Who are you?" dropdown          -> the signed-in user.
 *
 * The dropdown wrote a free-text name into localStorage and shipped it to the
 * REST layer as user_name, which is what landed in created_by, woo_completed_by
 * and the day-note author columns. Those rows already exist and hold plain
 * strings like "Dillon" and "Boomer", so every portal account carries a
 * btp_legacy_name meta field. btp_actor_name() prefers it, which keeps new
 * rows matching the old ones instead of splitting the history in two.
 *
 * Two portal roles:
 *   bt_portal_user  — can open the portal.
 *   bt_portal_admin — can open the portal AND create/manage portal logins,
 *                     without being a WordPress administrator.
 */

if (!defined('ABSPATH')) exit;

define('BTP_ROLE', 'bt_portal_user');
define('BTP_ROLE_ADMIN', 'bt_portal_admin');
define('BTP_ROLES_VERSION', '2');
define('BTP_RP_COOKIE', 'btp-setpass-' . COOKIEHASH);

/** The names that were hardcoded in the header dropdown before v0.21.0. */
function btp_legacy_names() {
    return array('Boomer', 'Dillon', 'Alissa', 'Brock', 'Maria', 'Brenda', 'Julie');
}

/* ─────────────────────────────────────────────────────────────────────────
   ROLES + CAPABILITIES
   ───────────────────────────────────────────────────────────────────── */

function btp_register_roles() {
    if (get_option('btp_roles_version') === BTP_ROLES_VERSION) return;

    remove_role(BTP_ROLE);
    add_role(BTP_ROLE, 'Portal User', array(
        'read'             => true,
        'bt_portal_access' => true,
    ));

    remove_role(BTP_ROLE_ADMIN);
    add_role(BTP_ROLE_ADMIN, 'Portal Admin', array(
        'read'                   => true,
        'bt_portal_access'       => true,
        'bt_manage_portal_users' => true,
    ));

    if ($admin = get_role('administrator')) {
        $admin->add_cap('bt_portal_access');
        $admin->add_cap('bt_manage_portal_users');
    }

    update_option('btp_roles_version', BTP_ROLES_VERSION);
}
add_action('init', 'btp_register_roles', 1);

/** True if the current visitor may open the portal. */
function btp_user_can_access() {
    return is_user_logged_in() && current_user_can('bt_portal_access');
}

/** Both portal roles, for user queries. */
function btp_portal_roles() {
    return array(BTP_ROLE, BTP_ROLE_ADMIN);
}

/**
 * The name to stamp on jobs, day notes, exchanges and Woo completions.
 * Falls back to display_name for accounts with no legacy mapping.
 */
function btp_actor_name() {
    if (!is_user_logged_in()) return '';
    $user   = wp_get_current_user();
    $legacy = get_user_meta($user->ID, 'btp_legacy_name', true);
    return $legacy ? $legacy : $user->display_name;
}

function btp_client_ip() {
    return isset($_SERVER['REMOTE_ADDR'])
        ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
        : '0.0.0.0';
}

function btp_current_url() {
    global $wp;
    return home_url(add_query_arg(array(), $wp->request));
}

/* ─────────────────────────────────────────────────────────────────────────
   INVITE + RESET EMAILS — point at the portal, not wp-login.php
   ───────────────────────────────────────────────────────────────────── */

/** The portal URL that opens the "pick a password" screen for this key. */
function btp_setpass_url($user_login, $key) {
    return add_query_arg(array(
        'btp_login' => rawurlencode($user_login),
        'btp_key'   => rawurlencode($key),
    ), btp_portal_base_url());
}

/**
 * New portal account -> "set your password" email pointing at the portal.
 * Non-portal accounts keep WordPress's default email untouched.
 */
function btp_new_user_email($email, $user, $blogname) {
    if (!is_a($user, 'WP_User') || !user_can($user, 'bt_portal_access')) return $email;

    $key = get_password_reset_key($user);
    if (is_wp_error($key)) return $email;

    $url  = btp_setpass_url($user->user_login, $key);
    $name = $user->display_name ? $user->display_name : $user->user_login;

    $email['subject'] = sprintf('[%s] Set up your portal login', $blogname);
    $email['message'] =
        "Hi $name,\n\n" .
        "An account has been created for you on the Boomer T's employee portal.\n\n" .
        "Your username: {$user->user_login}\n\n" .
        "Click below to pick your password and sign in. The link is good for 24 hours:\n\n" .
        $url . "\n\n" .
        "If the link has expired, open the portal, click \"Forgot your password?\" and a fresh one will be sent.\n";

    return $email;
}
add_filter('wp_new_user_notification_email', 'btp_new_user_email', 10, 3);

/** Forgot-password and "Send reset" mails also land on the portal screen. */
function btp_reset_message($message, $key, $user_login, $user_data) {
    if (!is_a($user_data, 'WP_User') || !user_can($user_data, 'bt_portal_access')) return $message;

    $url = btp_setpass_url($user_login, $key);
    return "Hi,\n\n" .
        "Someone asked to reset the password for your Boomer T's portal account.\n\n" .
        "Your username: {$user_login}\n\n" .
        "If that wasn't you, ignore this email and nothing changes. To pick a new password:\n\n" .
        $url . "\n\n" .
        "The link is good for 24 hours.\n";
}
add_filter('retrieve_password_message', 'btp_reset_message', 10, 4);

/* ─────────────────────────────────────────────────────────────────────────
   LOGIN + SET PASSWORD
   Both run on template_redirect so cookies go out before any page output.
   ───────────────────────────────────────────────────────────────────── */

function btp_handle_login() {
    if (empty($_POST['btp_login_submit'])) return;
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(wp_unslash($_POST['_wpnonce']), 'btp_login')) return;

    $login = sanitize_user(wp_unslash($_POST['btp_user'] ?? ''));
    $key   = 'btp_fail_' . md5($login . '|' . btp_client_ip());

    // Five bad tries on the same user+device buys a 15 minute cooldown.
    if ((int) get_transient($key) >= 5) {
        $GLOBALS['btp_login_error'] = 'Too many attempts. Try again in 15 minutes.';
        return;
    }

    $user = wp_signon(array(
        'user_login'    => $login,
        'user_password' => (string) ($_POST['btp_pass'] ?? ''),
        'remember'      => !empty($_POST['btp_remember']),
    ), is_ssl());

    if (is_wp_error($user)) {
        set_transient($key, (int) get_transient($key) + 1, 15 * MINUTE_IN_SECONDS);
        // Deliberately vague: don't confirm whether the username exists.
        $GLOBALS['btp_login_error'] = 'Wrong username or password.';
        return;
    }

    if (!user_can($user, 'bt_portal_access')) {
        wp_logout();
        $GLOBALS['btp_login_error'] = 'That account does not have portal access.';
        return;
    }

    delete_transient($key);
    wp_set_current_user($user->ID);
    wp_safe_redirect(btp_current_url());
    exit;
}
add_action('template_redirect', 'btp_handle_login', 5);

/**
 * The set-a-password flow.
 *
 * Arriving from the email link, the key is moved straight out of the address
 * bar into a short-lived cookie — same approach core uses — so it can't leak
 * through a referrer header, a shoulder, or a shared browser history.
 */
function btp_handle_setpass() {
    // Step 1 — hand-off from the email link.
    if (isset($_GET['btp_key'], $_GET['btp_login'])) {
        // Whoever is signed in on this browser isn't necessarily the person the
        // link was mailed to — a shared shop computer usually isn't. Clear the
        // session so the screen actually shows.
        if (is_user_logged_in()) wp_logout();

        $value = sanitize_text_field(wp_unslash($_GET['btp_login'])) . ':' .
                 sanitize_text_field(wp_unslash($_GET['btp_key']));
        setcookie(BTP_RP_COOKIE, $value, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        wp_safe_redirect(add_query_arg('btp_action', 'setpass', btp_portal_base_url()));
        exit;
    }

    if (($_GET['btp_action'] ?? '') !== 'setpass') return;
    if (empty($_POST['btp_setpass_submit'])) return;
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(wp_unslash($_POST['_wpnonce']), 'btp_setpass')) return;

    $user = btp_setpass_user();
    if (is_wp_error($user)) {
        $GLOBALS['btp_setpass_error'] = 'That link has expired. Request a new one below.';
        return;
    }

    $p1 = (string) ($_POST['btp_pass1'] ?? '');
    $p2 = (string) ($_POST['btp_pass2'] ?? '');

    if ($p1 !== $p2)      { $GLOBALS['btp_setpass_error'] = 'Those two passwords don\'t match.'; return; }
    if (strlen($p1) < 8)  { $GLOBALS['btp_setpass_error'] = 'Use at least 8 characters.'; return; }

    reset_password($user, $p1);
    setcookie(BTP_RP_COOKIE, ' ', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

    // Straight into the portal — no second login screen right after setting it.
    wp_set_auth_cookie($user->ID, true, is_ssl());
    wp_set_current_user($user->ID);
    wp_safe_redirect(btp_portal_base_url());
    exit;
}
add_action('template_redirect', 'btp_handle_setpass', 4);

/** Resolve the pending reset cookie to a user, or a WP_Error. */
function btp_setpass_user() {
    if (empty($_COOKIE[BTP_RP_COOKIE]) || strpos($_COOKIE[BTP_RP_COOKIE], ':') === false) {
        return new WP_Error('btp_no_key', 'No password reset in progress.');
    }
    list($login, $key) = explode(':', wp_unslash($_COOKIE[BTP_RP_COOKIE]), 2);
    return check_password_reset_key($key, $login);
}

/** True when the current request is the set-a-password screen. */
function btp_is_setpass_request() {
    return ($_GET['btp_action'] ?? '') === 'setpass';
}

/* ─────────────────────────────────────────────────────────────────────────
   THE GATE SCREENS
   ───────────────────────────────────────────────────────────────────── */

/** Entry point for the shortcode when the visitor has no portal access. */
function btp_login_form_html() {
    return btp_is_setpass_request() ? btp_setpass_form_html() : btp_login_screen_html();
}

function btp_login_screen_html() {
    $error  = $GLOBALS['btp_login_error'] ?? '';
    $notice = isset($_GET['btp_reset']) ? 'Password saved. Sign in with it below.' : '';
    ob_start();
    ?>
<div id="btp-login">
  <form method="post" class="btp-login-card">
    <div class="btp-login-brand">EMPLOYEE <span>PORTAL</span></div>

    <?php if ($error) : ?><div class="btp-login-error"><?php echo esc_html($error); ?></div><?php endif; ?>
    <?php if ($notice) : ?><div class="btp-login-ok"><?php echo esc_html($notice); ?></div><?php endif; ?>

    <?php wp_nonce_field('btp_login'); ?>

    <label for="btp_user">Username</label>
    <input type="text" name="btp_user" id="btp_user" required autofocus
           autocapitalize="none" autocorrect="off" spellcheck="false" autocomplete="username"
           value="<?php echo esc_attr(wp_unslash($_POST['btp_user'] ?? '')); ?>">

    <label for="btp_pass">Password</label>
    <input type="password" name="btp_pass" id="btp_pass" required autocomplete="current-password">

    <label class="btp-login-remember">
      <input type="checkbox" name="btp_remember" value="1" checked>
      Keep me signed in on this device
    </label>

    <button type="submit" name="btp_login_submit" value="1">LOG IN</button>

    <a class="btp-login-forgot"
       href="<?php echo esc_url(wp_lostpassword_url(btp_current_url())); ?>">Forgot your password?</a>
  </form>
</div>
<?php echo btp_login_styles(); ?>
    <?php
    return ob_get_clean();
}

function btp_setpass_form_html() {
    $user  = btp_setpass_user();
    $error = $GLOBALS['btp_setpass_error'] ?? '';
    $dead  = is_wp_error($user);

    ob_start();
    ?>
<div id="btp-login">
  <form method="post" class="btp-login-card">
    <div class="btp-login-brand">EMPLOYEE <span>PORTAL</span></div>

    <?php if ($dead) : ?>
      <div class="btp-login-error">That link has expired or has already been used.</div>
      <p class="btp-login-help">Password links are good for 24 hours. Ask for a fresh one and it will
        arrive by email in a minute or two.</p>
      <a class="btp-login-btnlink"
         href="<?php echo esc_url(wp_lostpassword_url(btp_portal_base_url())); ?>">SEND ME A NEW LINK</a>
    <?php else : ?>
      <p class="btp-login-help">Welcome, <strong><?php echo esc_html($user->display_name); ?></strong>.
        Pick a password and you&rsquo;re in. Your username is
        <strong><?php echo esc_html($user->user_login); ?></strong>.</p>

      <?php if ($error) : ?><div class="btp-login-error"><?php echo esc_html($error); ?></div><?php endif; ?>

      <?php wp_nonce_field('btp_setpass'); ?>

      <label for="btp_pass1">New password</label>
      <input type="password" name="btp_pass1" id="btp_pass1" required autofocus
             autocomplete="new-password" minlength="8">

      <label for="btp_pass2">Confirm password</label>
      <input type="password" name="btp_pass2" id="btp_pass2" required
             autocomplete="new-password" minlength="8">

      <label class="btp-login-remember">
        <input type="checkbox" onclick="
          var t = this.checked ? 'text' : 'password';
          document.getElementById('btp_pass1').type = t;
          document.getElementById('btp_pass2').type = t;">
        Show password
      </label>

      <button type="submit" name="btp_setpass_submit" value="1">SAVE AND SIGN IN</button>
      <p class="btp-login-help btp-login-fine">At least 8 characters. Anything you&rsquo;ll actually
        remember beats something clever you won&rsquo;t.</p>
    <?php endif; ?>
  </form>
</div>
<?php echo btp_login_styles(); ?>
    <?php
    return ob_get_clean();
}

/** Shared styling for both gate screens. */
function btp_login_styles() {
    return <<<'CSS'
<style>
#btp-login { font-family:'Barlow',sans-serif; background:#f5f5f5; min-height:70vh;
  display:flex; align-items:center; justify-content:center; padding:40px 16px; }
#btp-login .btp-login-card { width:100%; max-width:340px; background:#fff; padding:32px 28px;
  border-radius:8px; box-shadow:0 4px 24px rgba(26,31,94,.12); }
#btp-login .btp-login-brand { font-family:'Oswald',sans-serif; font-size:22px; font-weight:700;
  letter-spacing:.04em; color:#1a1f5e; text-align:center; margin-bottom:22px; }
#btp-login .btp-login-brand span { color:#e91e8c; }
#btp-login .btp-login-error { background:#fdecea; border-left:3px solid #c0392b; color:#7d2018;
  padding:9px 12px; font-size:13px; margin-bottom:16px; border-radius:3px; }
#btp-login .btp-login-ok { background:#eaf7ee; border-left:3px solid #2e7d32; color:#1b5e20;
  padding:9px 12px; font-size:13px; margin-bottom:16px; border-radius:3px; }
#btp-login .btp-login-help { font-size:13px; line-height:1.5; color:#5a6380; margin:0 0 18px; }
#btp-login .btp-login-fine { margin:14px 0 0; font-size:12px; color:#9ca3b8; }
#btp-login label { display:block; font-family:'Barlow Condensed',sans-serif; font-size:12px;
  font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#5a6380; margin:14px 0 5px; }
#btp-login input[type=text], #btp-login input[type=password] { width:100%; box-sizing:border-box;
  padding:11px 12px; font-size:16px; font-family:'Barlow',sans-serif; border:1px solid #e8eaf0;
  border-radius:5px; background:#f4f5f9; outline:none; }
#btp-login input[type=text]:focus, #btp-login input[type=password]:focus { border-color:#1a1f5e; background:#fff; }
#btp-login .btp-login-remember { display:flex; align-items:center; gap:7px; margin-top:16px;
  text-transform:none; letter-spacing:0; font-family:'Barlow',sans-serif; font-size:13px;
  font-weight:400; color:#5a6380; }
#btp-login .btp-login-remember input { margin:0; }
#btp-login button, #btp-login .btp-login-btnlink { display:block; width:100%; box-sizing:border-box;
  margin-top:20px; padding:12px; border:none; border-radius:5px; background:#1a1f5e; color:#fff;
  font-family:'Barlow Condensed',sans-serif; font-size:14px; font-weight:700; letter-spacing:.08em;
  text-align:center; text-decoration:none; cursor:pointer; transition:background .15s; }
#btp-login button:hover, #btp-login .btp-login-btnlink:hover { background:#232875; }
#btp-login .btp-login-forgot { display:block; text-align:center; margin-top:16px; font-size:12px;
  color:#9ca3b8; text-decoration:none; }
#btp-login .btp-login-forgot:hover { color:#e91e8c; }
</style>
CSS;
}

/* ─────────────────────────────────────────────────────────────────────────
   ADMIN SCREEN — Portal Users
   Under BT Portal for WordPress admins; its own top-level menu for portal
   admins, who can manage logins without seeing the rest of the settings.
   ───────────────────────────────────────────────────────────────────── */

function btp_users_menu() {
    if (current_user_can('manage_options')) {
        add_submenu_page('bt-portal', 'Portal Users', 'Portal Users',
            'bt_manage_portal_users', 'bt-portal-users', 'btp_users_page');
    } elseif (current_user_can('bt_manage_portal_users')) {
        add_menu_page('Portal Users', 'Portal Users', 'bt_manage_portal_users',
            'bt-portal-users', 'btp_users_page', 'dashicons-groups', 58);
    }
}
add_action('admin_menu', 'btp_users_menu', 20);

/**
 * Everyone who can actually open the portal — the two portal roles plus any
 * WordPress administrator, since administrator carries bt_portal_access too.
 *
 * Administrators were invisible on this screen before v0.23.0, which meant the
 * name shown in the portal header for an admin account could not be corrected
 * here — the most common way to end up signed in as yourself but stamped with
 * somebody else's name.
 */
function btp_all_portal_users() {
    $users = get_users(array(
        'role__in' => array_merge(btp_portal_roles(), array('administrator')),
        'orderby'  => 'display_name',
        'number'   => 200,
    ));
    return $users;
}

/** How this account gets its portal access. */
function btp_access_label($user) {
    if (in_array('administrator', (array) $user->roles, true)) return 'wpadmin';
    if (in_array(BTP_ROLE_ADMIN, (array) $user->roles, true))  return 'portaladmin';
    return 'portaluser';
}

function btp_users_page() {
    if (!current_user_can('bt_manage_portal_users')) {
        wp_die('You do not have permission to manage portal users.');
    }

    btp_users_handle_post();

    $users = btp_all_portal_users();

    $claimed = array();
    foreach ($users as $u) {
        $l = get_user_meta($u->ID, 'btp_legacy_name', true);
        if ($l) $claimed[] = $l;
    }
    $unclaimed = array_diff(btp_legacy_names(), $claimed);
    ?>
    <div class="wrap">
      <h1>Portal Users</h1>
      <p class="description" style="max-width:680px;">
        Each person gets their own login instead of the shared page password and the
        &ldquo;Who are you?&rdquo; dropdown. Leave the password blank and they get an email
        to pick their own on the portal login page. <strong>Old name</strong> ties the account
        to the name already stored on that person&rsquo;s jobs and notes &mdash; set it, or their
        history splits in two.
      </p>

      <?php settings_errors('btp_users'); ?>

      <?php if ($unclaimed) : ?>
        <div class="notice notice-warning inline" style="margin:16px 0;">
          <p><strong>Not set up yet:</strong> <?php echo esc_html(implode(', ', $unclaimed)); ?>.
          Anyone without an account cannot open the portal.</p>
        </div>
      <?php endif; ?>

      <h2>Add a user</h2>
      <form method="post">
        <?php wp_nonce_field('btp_add_user'); ?>
        <input type="hidden" name="btp_admin_action" value="add">
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="btp_new_login">Username</label></th>
            <td><input name="btp_new_login" id="btp_new_login" type="text" class="regular-text" required autocomplete="off">
              <p class="description">What they type to log in. Cannot be changed later.</p></td>
          </tr>
          <tr>
            <th scope="row"><label for="btp_new_email">Email</label></th>
            <td><input name="btp_new_email" id="btp_new_email" type="email" class="regular-text" required autocomplete="off">
              <p class="description">The invite goes here, so it has to be an inbox they can open.</p></td>
          </tr>
          <tr>
            <th scope="row"><label for="btp_new_role">Access</label></th>
            <td>
              <select name="btp_new_role" id="btp_new_role">
                <option value="<?php echo esc_attr(BTP_ROLE); ?>">Portal user &mdash; can use the portal</option>
                <option value="<?php echo esc_attr(BTP_ROLE_ADMIN); ?>">Portal admin &mdash; can also add and remove logins</option>
              </select>
              <p class="description">A portal admin manages logins only. It is not a WordPress
                administrator and gives no access to the site, plugins or settings.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="btp_new_legacy">Old name</label></th>
            <td>
              <select name="btp_new_legacy" id="btp_new_legacy">
                <option value="">&mdash; none / new employee &mdash;</option>
                <?php foreach (btp_legacy_names() as $n) : ?>
                  <option value="<?php echo esc_attr($n); ?>" <?php disabled(in_array($n, $claimed, true)); ?>>
                    <?php echo esc_html($n); ?><?php echo in_array($n, $claimed, true) ? ' (taken)' : ''; ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="description">The name from the old header dropdown. Their new work keeps
                landing under this name.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="btp_new_name">Display name</label></th>
            <td><input name="btp_new_name" id="btp_new_name" type="text" class="regular-text">
              <p class="description">Optional. Shown in the portal header. Defaults to the username.</p></td>
          </tr>
          <tr>
            <th scope="row"><label for="btp_new_pass">Password</label></th>
            <td><input name="btp_new_pass" id="btp_new_pass" type="text" class="regular-text" autocomplete="new-password">
              <p class="description"><strong>Leave blank</strong> and they get an email link to pick
                their own on the portal &mdash; nothing readable is stored or sent. Fill it in only if
                you are handing someone a password in person.</p></td>
          </tr>
        </table>
        <?php submit_button('Create Portal User'); ?>
      </form>

      <hr>

      <h2>Who can open the portal (<?php echo count($users); ?>)</h2>
      <p class="description" style="max-width:680px;">
        <strong>Shown as</strong> is the name that appears in the portal header and gets stamped on
        jobs, day notes and completions. It uses <em>Old name</em> when one is set, otherwise the
        display name. Change either one here and hit Save.
      </p>
      <table class="wp-list-table widefat fixed striped">
        <thead><tr>
          <th style="width:12%;">Username</th>
          <th style="width:16%;">Display name</th>
          <th style="width:12%;">Old name</th>
          <th style="width:10%;">Shown as</th>
          <th style="width:12%;">Access</th>
          <th style="width:14%;">Email</th>
          <th style="width:10%;">Last login</th>
          <th>Actions</th>
        </tr></thead>
        <tbody>
        <?php if (!$users) : ?>
          <tr><td colspan="8">Nobody can open the portal yet.</td></tr>
        <?php else : foreach ($users as $u) :
          $last    = get_user_meta($u->ID, 'btp_last_login', true);
          $legacy  = get_user_meta($u->ID, 'btp_legacy_name', true);
          $access  = btp_access_label($u);
          $is_self = ((int) $u->ID === get_current_user_id());
          $fid     = 'btp-row-' . (int) $u->ID;
          $shown   = $legacy ? $legacy : $u->display_name; ?>
          <tr>
            <td><strong><?php echo esc_html($u->user_login); ?></strong></td>
            <td><input type="text" form="<?php echo esc_attr($fid); ?>" name="btp_edit_name"
                       value="<?php echo esc_attr($u->display_name); ?>" style="width:100%;"></td>
            <td>
              <select form="<?php echo esc_attr($fid); ?>" name="btp_edit_legacy" style="width:100%;">
                <option value="">&mdash; none &mdash;</option>
                <?php
                $opts = btp_legacy_names();
                if ($legacy && !in_array($legacy, $opts, true)) $opts[] = $legacy;
                foreach ($opts as $n) :
                    printf('<option value="%s"%s>%s</option>',
                        esc_attr($n), selected($legacy, $n, false), esc_html($n));
                endforeach; ?>
              </select>
            </td>
            <td><strong><?php echo esc_html($shown); ?></strong></td>
            <td>
              <?php if ($access === 'wpadmin') : ?>
                <span title="Access comes from the WordPress administrator role">WordPress admin</span>
              <?php elseif ($access === 'portaladmin') : ?>
                <span style="color:#1a1f5e;font-weight:600;">Portal admin</span>
              <?php else : ?>
                Portal user
              <?php endif; ?>
            </td>
            <td style="word-break:break-all;"><?php echo esc_html($u->user_email); ?></td>
            <td><?php echo $last ? esc_html(date_i18n('M j, g:i a', (int) $last)) : '&mdash;'; ?></td>
            <td>
              <form id="<?php echo esc_attr($fid); ?>" method="post" style="display:flex;gap:4px;flex-wrap:wrap;">
                <?php wp_nonce_field('btp_user_row_' . $u->ID); ?>
                <input type="hidden" name="btp_user_id" value="<?php echo (int) $u->ID; ?>">
                <button class="button button-primary button-small" name="btp_admin_action" value="save">Save</button>
                <button class="button button-small" name="btp_admin_action" value="reset">Send reset</button>
                <?php if ($access === 'wpadmin') : ?>
                  <span class="description" style="align-self:center;">Role managed in Users</span>
                <?php elseif ($access === 'portaladmin') : ?>
                  <button class="button button-small" name="btp_admin_action" value="demote"
                    <?php disabled($is_self); ?>>Remove admin</button>
                  <button class="button button-small" name="btp_admin_action" value="remove"
                    <?php disabled($is_self); ?>
                    onclick="return confirm('Remove portal access for <?php echo esc_js($u->user_login); ?>?')">Remove access</button>
                <?php else : ?>
                  <button class="button button-small" name="btp_admin_action" value="promote">Make admin</button>
                  <button class="button button-small" name="btp_admin_action" value="remove"
                    <?php disabled($is_self); ?>
                    onclick="return confirm('Remove portal access for <?php echo esc_js($u->user_login); ?>?')">Remove access</button>
                <?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}

function btp_users_handle_post() {
    if (empty($_POST['btp_admin_action'])) return;
    $action = sanitize_key(wp_unslash($_POST['btp_admin_action']));

    if ($action === 'add') {
        check_admin_referer('btp_add_user');
        btp_users_add();
        return;
    }

    $user_id = isset($_POST['btp_user_id']) ? (int) $_POST['btp_user_id'] : 0;
    if (!$user_id) return;
    check_admin_referer('btp_user_row_' . $user_id);

    // Nobody talks themselves out of their own access by accident.
    if (in_array($action, array('demote', 'remove'), true) && $user_id === get_current_user_id()) {
        btp_users_notice('You cannot change your own access here.', 'error');
        return;
    }

    // An administrator's portal access comes from the administrator role. It is
    // not this screen's to give or take away — that happens under Users.
    $target = get_userdata($user_id);
    if ($target && in_array('administrator', (array) $target->roles, true)
        && in_array($action, array('promote', 'demote', 'remove'), true)) {
        btp_users_notice('That is a WordPress administrator. Change their role under Users instead.', 'error');
        return;
    }

    if ($action === 'save')    btp_users_save_identity($user_id);
    if ($action === 'reset')   btp_users_send_reset($user_id);
    if ($action === 'remove')  btp_users_remove($user_id);
    if ($action === 'promote') btp_users_set_role($user_id, BTP_ROLE_ADMIN);
    if ($action === 'demote')  btp_users_set_role($user_id, BTP_ROLE);
}

function btp_users_add() {
    $login  = sanitize_user(wp_unslash($_POST['btp_new_login'] ?? ''), true);
    $email  = sanitize_email(wp_unslash($_POST['btp_new_email'] ?? ''));
    $name   = sanitize_text_field(wp_unslash($_POST['btp_new_name'] ?? ''));
    $legacy = sanitize_text_field(wp_unslash($_POST['btp_new_legacy'] ?? ''));
    $role   = sanitize_key(wp_unslash($_POST['btp_new_role'] ?? BTP_ROLE));
    $pass   = (string) ($_POST['btp_new_pass'] ?? '');   // not sanitized, on purpose

    if (!in_array($role, btp_portal_roles(), true)) $role = BTP_ROLE;

    if (!$login || !is_email($email)) {
        btp_users_notice('A valid username and email are both required.', 'error');
        return;
    }
    if (username_exists($login)) { btp_users_notice('That username is already taken.', 'error'); return; }
    if (email_exists($email))    { btp_users_notice('That email already belongs to an account.', 'error'); return; }
    if ($legacy && !in_array($legacy, btp_legacy_names(), true)) $legacy = '';

    $self_set = (trim($pass) === '');
    if ($self_set) $pass = wp_generate_password(24, true, true);

    $user_id = wp_insert_user(array(
        'user_login'   => $login,
        'user_email'   => $email,
        'user_pass'    => $pass,
        'display_name' => $name ? $name : ($legacy ? $legacy : $login),
        'role'         => $role,
    ));

    // Don't keep the plain text around any longer than the insert needs it.
    $pass = null;
    unset($_POST['btp_new_pass']);

    if (is_wp_error($user_id)) {
        btp_users_notice('Could not create user: ' . $user_id->get_error_message(), 'error');
        return;
    }

    if ($legacy) update_user_meta($user_id, 'btp_legacy_name', $legacy);

    $label = ($role === BTP_ROLE_ADMIN) ? 'Portal admin' : 'Portal user';

    if ($self_set) {
        wp_new_user_notification($user_id, null, 'user');
        btp_users_notice($label . ' created. An invite is on its way to ' . $email .
            ' — the link opens the portal and lets them pick their own password.');
    } else {
        btp_users_notice($label . ' created with the password you typed. Hand it over in person and have them change it.');
    }
}

/**
 * Update the two fields that decide what the portal header says and what gets
 * stamped on new rows. Old name wins when it is set.
 */
function btp_users_save_identity($user_id) {
    if (!($user = get_userdata($user_id))) return;

    $name   = sanitize_text_field(wp_unslash($_POST['btp_edit_name'] ?? ''));
    $legacy = sanitize_text_field(wp_unslash($_POST['btp_edit_legacy'] ?? ''));

    if ($name !== '' && $name !== $user->display_name) {
        wp_update_user(array('ID' => $user_id, 'display_name' => $name));
    }

    $current = get_user_meta($user_id, 'btp_legacy_name', true);
    if ($legacy === '') {
        delete_user_meta($user_id, 'btp_legacy_name');
    } elseif ($legacy !== $current) {
        update_user_meta($user_id, 'btp_legacy_name', $legacy);
    }

    $shown = $legacy !== '' ? $legacy : ($name !== '' ? $name : $user->display_name);
    btp_users_notice($user->user_login . ' now shows in the portal as "' . $shown . '".');
}

function btp_users_send_reset($user_id) {
    if (!($user = get_userdata($user_id))) return;
    retrieve_password($user->user_login);
    btp_users_notice('Password link sent to ' . $user->user_email . '. Good for 24 hours.');
}

function btp_users_set_role($user_id, $role) {
    if (!($user = get_userdata($user_id))) return;
    if (!in_array($role, btp_portal_roles(), true)) return;

    foreach (btp_portal_roles() as $r) $user->remove_role($r);
    $user->add_role($role);

    btp_users_notice($role === BTP_ROLE_ADMIN
        ? $user->user_login . ' can now add and remove portal logins.'
        : $user->user_login . ' is back to a regular portal user.');
}

/**
 * Pulls portal access but leaves the account alone, so any rows tied to that
 * user ID stay intact. Deliberately not wp_delete_user().
 */
function btp_users_remove($user_id) {
    if (!($user = get_userdata($user_id))) return;
    foreach (btp_portal_roles() as $r) $user->remove_role($r);
    btp_users_notice('Portal access removed for ' . $user->user_login . '. The account still exists.');
}

function btp_users_notice($message, $type = 'success') {
    add_settings_error('btp_users', 'btp_users_notice', $message, $type);
}

/* ─────────────────────────────────────────────────────────────────────────
   REST — the in-portal account panel

   Everything here is capability-checked. Note that the older boomerts/v1
   routes in rest.php still use __return_true, from back when the portal had
   no login at all; these new routes deliberately do not follow that pattern.
   ───────────────────────────────────────────────────────────────────── */

function btp_rest_can_access() {
    return is_user_logged_in() && current_user_can('bt_portal_access');
}

function btp_rest_can_manage() {
    return is_user_logged_in() && current_user_can('bt_manage_portal_users');
}

add_action('rest_api_init', function() {
    $ns = 'boomerts/v1';

    register_rest_route($ns, '/account', array(
        'methods' => 'GET', 'callback' => 'btp_rest_account',
        'permission_callback' => 'btp_rest_can_access'));

    register_rest_route($ns, '/account/reset', array(
        'methods' => 'POST', 'callback' => 'btp_rest_account_reset',
        'permission_callback' => 'btp_rest_can_access'));

    register_rest_route($ns, '/account/users', array(
        'methods' => 'GET', 'callback' => 'btp_rest_users',
        'permission_callback' => 'btp_rest_can_manage'));

    register_rest_route($ns, '/account/users/(?P<id>\d+)', array(
        'methods' => 'POST', 'callback' => 'btp_rest_user_save',
        'permission_callback' => 'btp_rest_can_manage'));

    register_rest_route($ns, '/account/users/(?P<id>\d+)/reset', array(
        'methods' => 'POST', 'callback' => 'btp_rest_user_reset',
        'permission_callback' => 'btp_rest_can_manage'));
});

/** Shape one user for the panel. */
function btp_rest_user_row($u) {
    $legacy = get_user_meta($u->ID, 'btp_legacy_name', true);
    $last   = get_user_meta($u->ID, 'btp_last_login', true);
    return array(
        'id'       => (int) $u->ID,
        'login'    => $u->user_login,
        'email'    => $u->user_email,
        'name'     => $u->display_name,
        'legacy'   => $legacy ? $legacy : '',
        'shown'    => $legacy ? $legacy : $u->display_name,
        'access'   => btp_access_label($u),
        'last'     => $last ? date_i18n('M j, g:i a', (int) $last) : '',
        'is_self'  => ((int) $u->ID === get_current_user_id()),
    );
}

function btp_rest_account() {
    $u = wp_get_current_user();
    $row = btp_rest_user_row($u);
    $row['can_manage']    = current_user_can('bt_manage_portal_users');
    $row['legacy_names']  = btp_legacy_names();
    return rest_ensure_response($row);
}

function btp_rest_account_reset() {
    $u = wp_get_current_user();
    $sent = retrieve_password($u->user_login);
    if (is_wp_error($sent)) {
        return new WP_Error('btp_reset_failed', 'Could not send the email. Try again shortly.', array('status' => 500));
    }
    return rest_ensure_response(array('ok' => true, 'email' => $u->user_email));
}

function btp_rest_users() {
    $out = array();
    foreach (btp_all_portal_users() as $u) $out[] = btp_rest_user_row($u);
    return rest_ensure_response(array('users' => $out, 'legacy_names' => btp_legacy_names()));
}

function btp_rest_user_save($req) {
    $id = (int) $req['id'];
    if (!($user = get_userdata($id))) {
        return new WP_Error('btp_no_user', 'No such user.', array('status' => 404));
    }

    $p      = $req->get_json_params();
    $name   = sanitize_text_field($p['name'] ?? '');
    $email  = sanitize_email($p['email'] ?? '');
    $legacy = sanitize_text_field($p['legacy'] ?? '');

    if ($name === '') {
        return new WP_Error('btp_bad_name', 'A name is required.', array('status' => 400));
    }
    if ($email === '' || !is_email($email)) {
        return new WP_Error('btp_bad_email', 'That email address is not valid.', array('status' => 400));
    }

    // email_exists returns the owner's id, so an unchanged address is fine.
    $owner = email_exists($email);
    if ($owner && (int) $owner !== $id) {
        return new WP_Error('btp_email_taken', 'Another account already uses that email.', array('status' => 409));
    }

    $update = array('ID' => $id);
    if ($name !== $user->display_name)   $update['display_name'] = $name;
    if ($email !== $user->user_email)    $update['user_email']   = $email;

    if (count($update) > 1) {
        $res = wp_update_user($update);
        if (is_wp_error($res)) {
            return new WP_Error('btp_save_failed', $res->get_error_message(), array('status' => 400));
        }
    }

    $current = get_user_meta($id, 'btp_legacy_name', true);
    if ($legacy === '') {
        delete_user_meta($id, 'btp_legacy_name');
    } elseif ($legacy !== $current) {
        update_user_meta($id, 'btp_legacy_name', $legacy);
    }

    return rest_ensure_response(btp_rest_user_row(get_userdata($id)));
}

function btp_rest_user_reset($req) {
    $id = (int) $req['id'];
    if (!($user = get_userdata($id))) {
        return new WP_Error('btp_no_user', 'No such user.', array('status' => 404));
    }
    $sent = retrieve_password($user->user_login);
    if (is_wp_error($sent)) {
        return new WP_Error('btp_reset_failed', 'Could not send the email. Try again shortly.', array('status' => 500));
    }
    return rest_ensure_response(array('ok' => true, 'email' => $user->user_email));
}

/* ─────────────────────────────────────────────────────────────────────────
   LAST LOGIN STAMP
   ───────────────────────────────────────────────────────────────────── */

function btp_stamp_login($user_login, $user) {
    update_user_meta($user->ID, 'btp_last_login', time());
}
add_action('wp_login', 'btp_stamp_login', 10, 2);

/* ─────────────────────────────────────────────────────────────────────────
   NO CACHING ON THE PORTAL PAGE

   Before v0.21.0 the portal rendered identically for everybody, so a page
   cache was harmless. It now carries the signed-in name in the header and in
   btUserName, so a cached copy would show one person's name to the next
   person who opened it — and stamp their work with it.
   ───────────────────────────────────────────────────────────────────── */

function btp_no_cache_portal() {
    $portal_id = (int) get_option('btp_portal_page_id', 0);
    if (!$portal_id || get_queried_object_id() !== $portal_id) return;

    if (!defined('DONOTCACHEPAGE'))   define('DONOTCACHEPAGE', true);
    if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
    if (!defined('DONOTCACHEDB'))     define('DONOTCACHEDB', true);
    nocache_headers();
}
add_action('template_redirect', 'btp_no_cache_portal', 1);
