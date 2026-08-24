<?php
/**
 * BT Portal — Portal users, login gate, and identity.
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
 */

if (!defined('ABSPATH')) exit;

define('BTP_ROLE', 'bt_portal_user');
define('BTP_ROLES_VERSION', '1');

/** The names that were hardcoded in the header dropdown before v0.21.0. */
function btp_legacy_names() {
    return array('Boomer', 'Dillon', 'Alissa', 'Brock', 'Maria', 'Brenda', 'Julie');
}

/* ─────────────────────────────────────────────────────────────────────────
   ROLE + CAPABILITIES
   ───────────────────────────────────────────────────────────────────── */

function btp_register_roles() {
    if (get_option('btp_roles_version') === BTP_ROLES_VERSION) return;

    remove_role(BTP_ROLE);
    add_role(BTP_ROLE, 'Portal User', array(
        'read'             => true,
        'bt_portal_access' => true,
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

/* ─────────────────────────────────────────────────────────────────────────
   LOGIN — handled on template_redirect so cookies go out before any output
   ───────────────────────────────────────────────────────────────────── */

function btp_handle_login() {
    if (empty($_POST['btp_login_submit'])) return;
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(wp_unslash($_POST['_wpnonce']), 'btp_login')) return;

    $login = sanitize_user(wp_unslash($_POST['btp_user'] ?? ''));
    $key   = 'btp_fail_' . md5($login . '|' . btp_client_ip());

    // Five bad tries on the same user+IP buys a 15 minute cooldown.
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

function btp_client_ip() {
    return isset($_SERVER['REMOTE_ADDR'])
        ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
        : '0.0.0.0';
}

function btp_current_url() {
    global $wp;
    return home_url(add_query_arg(array(), $wp->request));
}

/** The login screen, styled to match the portal header. Returns HTML. */
function btp_login_form_html() {
    $error = $GLOBALS['btp_login_error'] ?? '';
    ob_start();
    ?>
<div id="btp-login">
  <form method="post" class="btp-login-card">
    <div class="btp-login-brand">EMPLOYEE <span>PORTAL</span></div>

    <?php if ($error) : ?>
      <div class="btp-login-error"><?php echo esc_html($error); ?></div>
    <?php endif; ?>

    <?php wp_nonce_field('btp_login'); ?>

    <label for="btp_user">Username</label>
    <input type="text" name="btp_user" id="btp_user" required autofocus
           autocapitalize="none" autocorrect="off" spellcheck="false"
           autocomplete="username"
           value="<?php echo esc_attr(wp_unslash($_POST['btp_user'] ?? '')); ?>">

    <label for="btp_pass">Password</label>
    <input type="password" name="btp_pass" id="btp_pass" required
           autocomplete="current-password">

    <label class="btp-login-remember">
      <input type="checkbox" name="btp_remember" value="1" checked>
      Keep me signed in on this device
    </label>

    <button type="submit" name="btp_login_submit" value="1">LOG IN</button>

    <a class="btp-login-forgot"
       href="<?php echo esc_url(wp_lostpassword_url(btp_current_url())); ?>">Forgot your password?</a>
  </form>
</div>
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
#btp-login label { display:block; font-family:'Barlow Condensed',sans-serif; font-size:12px;
  font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#5a6380;
  margin:14px 0 5px; }
#btp-login input[type=text], #btp-login input[type=password] { width:100%; box-sizing:border-box;
  padding:11px 12px; font-size:16px; font-family:'Barlow',sans-serif; border:1px solid #e8eaf0;
  border-radius:5px; background:#f4f5f9; outline:none; }
#btp-login input[type=text]:focus, #btp-login input[type=password]:focus { border-color:#1a1f5e; background:#fff; }
#btp-login .btp-login-remember { display:flex; align-items:center; gap:7px; margin-top:16px;
  text-transform:none; letter-spacing:0; font-family:'Barlow',sans-serif; font-size:13px;
  font-weight:400; color:#5a6380; }
#btp-login .btp-login-remember input { margin:0; }
#btp-login button { width:100%; margin-top:20px; padding:12px; border:none; border-radius:5px;
  background:#1a1f5e; color:#fff; font-family:'Barlow Condensed',sans-serif; font-size:14px;
  font-weight:700; letter-spacing:.08em; cursor:pointer; transition:background .15s; }
#btp-login button:hover { background:#232875; }
#btp-login .btp-login-forgot { display:block; text-align:center; margin-top:16px; font-size:12px;
  color:#9ca3b8; text-decoration:none; }
#btp-login .btp-login-forgot:hover { color:#e91e8c; }
</style>
    <?php
    return ob_get_clean();
}

/* ─────────────────────────────────────────────────────────────────────────
   ADMIN SCREEN — BT Portal > Portal Users
   ───────────────────────────────────────────────────────────────────── */

function btp_users_menu() {
    add_submenu_page(
        'bt-portal',
        'Portal Users',
        'Portal Users',
        'bt_manage_portal_users',
        'bt-portal-users',
        'btp_users_page'
    );
}
add_action('admin_menu', 'btp_users_menu', 20);

function btp_users_page() {
    if (!current_user_can('bt_manage_portal_users')) {
        wp_die('You do not have permission to manage portal users.');
    }

    btp_users_handle_post();

    $users = get_users(array('role' => BTP_ROLE, 'orderby' => 'display_name', 'number' => 200));

    // Which of the old dropdown names nobody has claimed yet.
    $claimed = array();
    foreach ($users as $u) {
        $l = get_user_meta($u->ID, 'btp_legacy_name', true);
        if ($l) $claimed[] = $l;
    }
    $unclaimed = array_diff(btp_legacy_names(), $claimed);
    ?>
    <div class="wrap">
      <h1>Portal Users</h1>
      <p class="description" style="max-width:660px;">
        Each person gets their own login instead of the shared page password and the
        &ldquo;Who are you?&rdquo; dropdown. <strong>Old name</strong> ties the account to the
        name already stored on that person&rsquo;s existing jobs and notes &mdash; set it or their
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
            <td><input name="btp_new_login" id="btp_new_login" type="text" class="regular-text"
                       required autocomplete="off">
              <p class="description">What they type to log in. Cannot be changed later.</p></td>
          </tr>
          <tr>
            <th scope="row"><label for="btp_new_email">Email</label></th>
            <td><input name="btp_new_email" id="btp_new_email" type="email" class="regular-text"
                       required autocomplete="off"></td>
          </tr>
          <tr>
            <th scope="row"><label for="btp_new_legacy">Old name</label></th>
            <td>
              <select name="btp_new_legacy" id="btp_new_legacy">
                <option value="">&mdash; none / new employee &mdash;</option>
                <?php foreach (btp_legacy_names() as $n) : ?>
                  <option value="<?php echo esc_attr($n); ?>"
                    <?php disabled(in_array($n, $claimed, true)); ?>>
                    <?php echo esc_html($n); ?><?php echo in_array($n, $claimed, true) ? ' (taken)' : ''; ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="description">The name from the old header dropdown. Their new work
                will keep landing under this name.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="btp_new_name">Display name</label></th>
            <td><input name="btp_new_name" id="btp_new_name" type="text" class="regular-text">
              <p class="description">Optional. Shown in the portal header. Defaults to the username.</p></td>
          </tr>
          <tr>
            <th scope="row"><label for="btp_new_pass">Password</label></th>
            <td><input name="btp_new_pass" id="btp_new_pass" type="text" class="regular-text"
                       autocomplete="new-password">
              <p class="description"><strong>Leave blank</strong> and they get an email to set their
                own &mdash; nothing readable is ever stored or sent. Fill it in only if you are
                handing someone a password in person.</p></td>
          </tr>
        </table>
        <?php submit_button('Create Portal User'); ?>
      </form>

      <hr>

      <h2>Portal users (<?php echo count($users); ?>)</h2>
      <table class="wp-list-table widefat fixed striped">
        <thead><tr>
          <th>Username</th><th>Display name</th><th>Old name</th>
          <th>Email</th><th>Last login</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php if (!$users) : ?>
          <tr><td colspan="6">No portal users yet. Nobody but an administrator can open the portal.</td></tr>
        <?php else : foreach ($users as $u) :
          $last   = get_user_meta($u->ID, 'btp_last_login', true);
          $legacy = get_user_meta($u->ID, 'btp_legacy_name', true); ?>
          <tr>
            <td><strong><?php echo esc_html($u->user_login); ?></strong></td>
            <td><?php echo esc_html($u->display_name); ?></td>
            <td><?php echo $legacy ? esc_html($legacy) : '&mdash;'; ?></td>
            <td><?php echo esc_html($u->user_email); ?></td>
            <td><?php echo $last ? esc_html(date_i18n('M j, g:i a', (int) $last)) : '&mdash;'; ?></td>
            <td>
              <form method="post" style="display:inline">
                <?php wp_nonce_field('btp_user_row_' . $u->ID); ?>
                <input type="hidden" name="btp_user_id" value="<?php echo (int) $u->ID; ?>">
                <button class="button button-small" name="btp_admin_action" value="reset">Send reset</button>
                <button class="button button-small" name="btp_admin_action" value="remove"
                  onclick="return confirm('Remove portal access for <?php echo esc_js($u->user_login); ?>?')">
                  Remove access</button>
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

    if ($action === 'reset')  btp_users_send_reset($user_id);
    if ($action === 'remove') btp_users_remove($user_id);
}

function btp_users_add() {
    $login  = sanitize_user(wp_unslash($_POST['btp_new_login'] ?? ''), true);
    $email  = sanitize_email(wp_unslash($_POST['btp_new_email'] ?? ''));
    $name   = sanitize_text_field(wp_unslash($_POST['btp_new_name'] ?? ''));
    $legacy = sanitize_text_field(wp_unslash($_POST['btp_new_legacy'] ?? ''));
    $pass   = (string) ($_POST['btp_new_pass'] ?? '');   // not sanitized, on purpose

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
        'role'         => BTP_ROLE,
    ));

    // Don't keep the plain text around any longer than the insert needs it.
    $pass = null;
    unset($_POST['btp_new_pass']);

    if (is_wp_error($user_id)) {
        btp_users_notice('Could not create user: ' . $user_id->get_error_message(), 'error');
        return;
    }

    if ($legacy) update_user_meta($user_id, 'btp_legacy_name', $legacy);

    if ($self_set) {
        wp_new_user_notification($user_id, null, 'user');   // set-password link, no plain text
        btp_users_notice('User created. A set-your-password email is on its way to ' . $email . '.');
    } else {
        btp_users_notice('User created with the password you typed. Hand it over in person and have them change it.');
    }
}

function btp_users_send_reset($user_id) {
    if (!($user = get_userdata($user_id))) return;
    retrieve_password($user->user_login);
    btp_users_notice('Password reset email sent to ' . $user->user_email . '.');
}

/**
 * Pulls portal access but leaves the account alone, so any rows tied to that
 * user ID stay intact. Deliberately not wp_delete_user().
 */
function btp_users_remove($user_id) {
    if (!($user = get_userdata($user_id))) return;
    $user->remove_role(BTP_ROLE);
    btp_users_notice('Portal access removed for ' . $user->user_login . '. The account still exists.');
}

function btp_users_notice($message, $type = 'success') {
    add_settings_error('btp_users', 'btp_users_notice', $message, $type);
}

/* ─────────────────────────────────────────────────────────────────────────
   LAST LOGIN STAMP
   ───────────────────────────────────────────────────────────────────── */

function btp_stamp_login($user_login, $user) {
    update_user_meta($user->ID, 'btp_last_login', time());
}
add_action('wp_login', 'btp_stamp_login', 10, 2);
