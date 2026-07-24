<?php
/**
 * BT Portal — admin page (status + update check).
 */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function() {
    add_menu_page(
        'BT Portal',
        'BT Portal',
        'manage_options',
        'bt-portal',
        'btp_admin_page',
        'dashicons-clipboard',
        58
    );
});

function btp_admin_page() {
    if ( isset($_POST['btp_check_update']) && check_admin_referer('btp_check_update') ) {
        delete_transient('btp_manifest');
        delete_site_transient('update_plugins');
        wp_update_plugins();
        echo '<div class="notice notice-success"><p>Update check complete. See the Plugins page if an update is available.</p></div>';
    }

    global $wpdb;
    $jobs_count   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bt_jobs");
    $stores_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bt_stores");
    $db_version   = get_option('bt_schedule_db_version', '—');

    $manifest = function_exists('btp_update_manifest') ? btp_update_manifest() : null;
    $latest   = ($manifest && !empty($manifest['version'])) ? $manifest['version'] : '—';
    ?>
    <div class="wrap">
      <h1>BT Portal</h1>
      <table class="widefat striped" style="max-width:640px;margin-top:12px;">
        <tbody>
          <tr><td><strong>Installed version</strong></td><td><?php echo esc_html(BTP_VERSION); ?></td></tr>
          <tr><td><strong>Latest version</strong></td><td><?php echo esc_html($latest); ?></td></tr>
          <tr><td><strong>Portal DB version</strong></td><td><?php echo esc_html($db_version); ?></td></tr>
          <tr><td><strong>Jobs</strong></td><td><?php echo esc_html($jobs_count); ?></td></tr>
          <tr><td><strong>Online stores</strong></td><td><?php echo esc_html($stores_count); ?></td></tr>
          <tr><td><strong>Shortcode</strong></td><td><code>[bt_schedule]</code></td></tr>
          <tr><td><strong>REST namespace</strong></td><td><code>boomerts/v1</code> (jobs, stores, store-categories, backups, contacts, day-notes, closed-days)</td></tr>
        </tbody>
      </table>
      <form method="post" style="margin-top:16px;">
        <?php wp_nonce_field('btp_check_update'); ?>
        <button type="submit" name="btp_check_update" value="1" class="button button-primary">Check for updates now</button>
      </form>
    </div>
    <?php
}
