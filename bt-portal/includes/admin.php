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

    if ( isset($_POST['btp_redirect_cap']) && check_admin_referer('btp_save_settings') ) {
        $cap = sanitize_text_field( wp_unslash($_POST['btp_redirect_cap']) );
        if ( in_array($cap, array('edit_posts','publish_posts','manage_options','read'), true) ) {
            update_option('btp_redirect_cap', $cap);
        }
    }

    if ( isset($_POST['btp_brand_logo']) && check_admin_referer('btp_save_settings') ) {
        update_option('btp_brand_logo', esc_url_raw( wp_unslash($_POST['btp_brand_logo']) ));
        update_option('btp_brand_navy', sanitize_hex_color( wp_unslash($_POST['btp_brand_navy']) ) ?: '');
        update_option('btp_brand_pink', sanitize_hex_color( wp_unslash($_POST['btp_brand_pink']) ) ?: '');
    }

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

      <h2>Redirect access</h2>
      <p style="max-width:640px;">Everything else in the portal is open to anyone who can load the page. The Redirect
        tool creates real published pages on the site, so it asks for a capability. Pick the lowest one that covers
        the people who actually need it.</p>
      <form method="post" style="margin-bottom:24px;">
        <?php wp_nonce_field('btp_save_settings'); ?>
        <select name="btp_redirect_cap">
          <?php
          $caps = array(
            'read'          => 'Any signed-in user (Subscriber and up)',
            'edit_posts'    => 'Contributor and up — default',
            'publish_posts' => 'Author and up',
            'manage_options'=> 'Administrators only',
          );
          $cur = btp_redirect_capability();
          foreach ( $caps as $k => $label ) {
              printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($cur, $k, false), esc_html($label));
          }
          ?>
        </select>
        <?php submit_button('Save', 'secondary', 'submit_cap', false); ?>
        <p class="description">Signed-out visitors are never given access, whatever this is set to.</p>
      </form>

      <h2>Customer email branding</h2>
      <p style="max-width:640px;">Used on the emails sent when an exchange is marked Received or Shipped.</p>
      <form method="post" style="margin-bottom:24px;">
        <?php wp_nonce_field('btp_save_settings'); ?>
        <table class="form-table"><tbody>
          <tr>
            <th scope="row"><label for="btp_brand_logo">Logo URL</label></th>
            <td><input type="url" class="regular-text" id="btp_brand_logo" name="btp_brand_logo"
                       value="<?php echo esc_attr( btp_brand('logo') ); ?>">
                <p class="description">Leave blank to print the shop name as text instead.</p></td>
          </tr>
          <tr>
            <th scope="row"><label for="btp_brand_navy">Navy</label></th>
            <td><input type="text" id="btp_brand_navy" name="btp_brand_navy" value="<?php echo esc_attr( btp_brand('navy') ); ?>" style="width:110px;"></td>
          </tr>
          <tr>
            <th scope="row"><label for="btp_brand_pink">Pink</label></th>
            <td><input type="text" id="btp_brand_pink" name="btp_brand_pink" value="<?php echo esc_attr( btp_brand('pink') ); ?>" style="width:110px;"></td>
          </tr>
        </tbody></table>
        <?php submit_button('Save branding', 'secondary', 'submit_brand', false); ?>
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
