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

    // Exchange Shipping product id — saved here so the product can be rebuilt
    // in Woo without a plugin release.
    $saved_notice = '';
    if ( isset($_POST['btp_exchange_product_id']) && check_admin_referer('btp_save_settings') ) {
        $new = (int) $_POST['btp_exchange_product_id'];
        if ( $new > 0 ) {
            update_option('btp_exchange_product_id', $new);
            $saved_notice = 'Saved.';
        } else {
            $saved_notice = 'Product ID must be a positive number — nothing changed.';
        }
    }

    $jobs_count   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bt_jobs");
    $stores_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bt_stores");
    $db_version   = get_option('bt_schedule_db_version', '—');
    $ex_count     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bt_exchanges");
    $ex_pid       = btp_exchange_product_id();

    ?>
    <div class="wrap">
      <h1>BT Portal</h1>
      <h2>Status</h2>
      <table class="widefat striped" style="max-width:640px;margin-top:12px;">
        <tbody>
          <tr><td><strong>Portal DB version</strong></td><td><?php echo esc_html($db_version); ?></td></tr>
          <tr><td><strong>Jobs</strong></td><td><?php echo esc_html($jobs_count); ?></td></tr>
          <tr><td><strong>Online stores</strong></td><td><?php echo esc_html($stores_count); ?></td></tr>
          <tr><td><strong>Exchanges tracked</strong></td><td><?php echo esc_html($ex_count); ?></td></tr>
          <tr><td><strong>Shortcode</strong></td><td><code>[bt_schedule]</code></td></tr>
          <tr><td><strong>REST namespace</strong></td><td><code>boomerts/v1</code> (jobs, stores, store-categories, backups, contacts, exchanges, day-notes, closed-days)</td></tr>
        </tbody>
      </table>

      <h2>Exchanges</h2>
      <?php if ($saved_notice) : ?>
        <div class="notice notice-info inline"><p><?php echo esc_html($saved_notice); ?></p></div>
      <?php endif; ?>
      <p style="max-width:640px;">An order counts as an exchange when it contains this product. It is the
        <strong>Exchange Shipping</strong> product the exchange form adds to the cart.</p>
      <form method="post" style="margin-bottom:24px;">
        <?php wp_nonce_field('btp_save_settings'); ?>
        <label for="btp_exchange_product_id"><strong>Exchange Shipping product ID</strong></label>
        <input type="number" min="1" step="1" id="btp_exchange_product_id" name="btp_exchange_product_id"
               value="<?php echo esc_attr($ex_pid); ?>" style="width:120px;margin:0 8px;">
        <?php submit_button('Save', 'secondary', 'submit', false); ?>
      </form>
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
