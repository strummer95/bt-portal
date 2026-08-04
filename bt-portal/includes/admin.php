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
    global $wpdb;
    $jobs_count   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bt_jobs");
    $stores_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bt_stores");
    $db_version   = get_option('bt_schedule_db_version', '—');

    ?>
    <div class="wrap">
      <h1>BT Portal</h1>
      <h2>Status</h2>
      <table class="widefat striped" style="max-width:640px;margin-top:12px;">
        <tbody>
          <tr><td><strong>Portal DB version</strong></td><td><?php echo esc_html($db_version); ?></td></tr>
          <tr><td><strong>Jobs</strong></td><td><?php echo esc_html($jobs_count); ?></td></tr>
          <tr><td><strong>Online stores</strong></td><td><?php echo esc_html($stores_count); ?></td></tr>
          <tr><td><strong>Shortcode</strong></td><td><code>[bt_schedule]</code></td></tr>
          <tr><td><strong>REST namespace</strong></td><td><code>boomerts/v1</code> (jobs, stores, store-categories, backups, contacts, day-notes, closed-days)</td></tr>
        </tbody>
      </table>
      <?php
      // Shared BT panel — same layout, wording and button on every BT plugin.
      bt_admin_updates_panel(array(
          'slug'     => 'bt-portal',
          'version'  => BTP_VERSION,
          'manifest' => 'btp_update_manifest',
          'flush'    => 'btp_force_update_check',
      ));
      ?>
    </div>
    <?php
}
