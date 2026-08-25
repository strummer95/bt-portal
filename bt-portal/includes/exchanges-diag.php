<?php
/**
 * BT Portal — Exchanges diagnostics.
 *
 * The exchanges endpoint dies in a way no error handling can intercept, so
 * nothing useful ever reaches the browser: the request just stops and PHP
 * prints "critical error". Guessing at the cause from the outside has not
 * worked.
 *
 * This page does the same work as the endpoint, one order at a time, printing
 * a line and flushing after each. Whatever kills the request, the output
 * stops at that order — so the last line printed is the culprit, and the
 * memory column shows whether it was a slow climb (a limit that is simply too
 * low for the volume) or one order jumping the number off a cliff.
 */

if (!defined('ABSPATH')) exit;

function btp_ex_diag_menu() {
    add_submenu_page(
        'bt-portal',
        'Exchanges Diagnostics',
        'Exchanges Diag',
        'manage_options',
        'bt-portal-ex-diag',
        'btp_ex_diag_page'
    );
}
add_action('admin_menu', 'btp_ex_diag_menu', 21);

function btp_ex_diag_page() {
    if (!current_user_can('manage_options')) wp_die('Nope.');

    $run = isset($_GET['run']) && $_GET['run'] === '1';
    ?>
    <div class="wrap">
      <h1>Exchanges Diagnostics</h1>

      <table class="widefat" style="max-width:760px;margin:16px 0;">
        <tbody>
          <tr><td><strong>PHP version</strong></td><td><?php echo esc_html(PHP_VERSION); ?></td></tr>
          <tr><td><strong>Memory limit</strong></td><td><?php echo esc_html(ini_get('memory_limit')); ?></td></tr>
          <tr><td><strong>Memory already used loading this page</strong></td>
              <td><?php echo esc_html(round(memory_get_usage(true) / 1048576, 1)); ?> MB</td></tr>
          <tr><td><strong>Max execution time</strong></td>
              <td><?php echo esc_html(ini_get('max_execution_time')); ?> s</td></tr>
          <tr><td><strong>WooCommerce</strong></td>
              <td><?php echo function_exists('wc_get_order') ? 'active' : '<strong>NOT ACTIVE</strong>'; ?></td></tr>
          <tr><td><strong>Exchange shipping product id</strong></td>
              <td><?php echo esc_html(btp_exchange_product_id()); ?></td></tr>
          <tr><td><strong>Plugin version</strong></td>
              <td><?php echo esc_html(defined('BTP_VERSION') ? BTP_VERSION : '?'); ?></td></tr>
          <tr><td><strong>Last order-lookup query time</strong></td>
              <td><?php
                $ms = (int) get_option('btp_ex_query_ms');
                echo $ms ? esc_html(number_format($ms)) . ' ms' : 'not measured yet';
              ?></td></tr>
          <tr><td><strong>Orders currently set aside</strong></td>
              <td><?php
                $skip = (array) get_option('btp_ex_skip');
                echo $skip ? esc_html(implode(', ', $skip)) : 'none';
              ?></td></tr>
        </tbody>
      </table>

      <?php
      $fatal = get_option('btp_ex_last_fatal');
      if ($fatal) : ?>
        <div class="notice notice-error inline" style="max-width:760px;">
          <p><strong>Last recorded crash</strong><br>
          <?php echo esc_html($fatal['message']); ?><br>
          <code><?php echo esc_html($fatal['file']); ?></code> &mdash;
          order <?php echo esc_html($fatal['order_id']); ?>,
          after <?php echo esc_html($fatal['done']); ?> loaded,
          peak <?php echo esc_html($fatal['peak_mb']); ?> MB of <?php echo esc_html($fatal['limit']); ?>
          </p>
        </div>
      <?php endif; ?>

      <p>
        <a href="<?php echo esc_url(add_query_arg('sizes', '1')); ?>" class="button button-primary">
          Measure the orders (fast, safe)
        </a>
        <a href="<?php echo esc_url(add_query_arg('run', '1')); ?>" class="button">
          Run the order-by-order build test
        </a>
        <a href="<?php echo esc_url(add_query_arg('clear', '1')); ?>" class="button">
          Clear set-aside list
        </a>
      </p>
      <p class="description" style="max-width:760px;">
        The test builds each exchange row the same way the portal does, printing a line per order.
        If the page stops partway, <strong>the last order shown is the one that kills it</strong>.
        Watch the memory column: a steady climb to the limit means the limit is too low for the
        number of orders; a sudden jump on one order means that order is the problem.
      </p>

      <?php
      if (isset($_GET['clear'])) {
          delete_option('btp_ex_skip');
          delete_option('btp_ex_last_fatal');
          echo '<div class="notice notice-success inline"><p>Cleared.</p></div>';
      }

      btp_ex_diag_sizes();   // always, it is cheap and cannot hang
      if ($run) btp_ex_diag_run();
      ?>
    </div>
    <?php
}

function btp_ex_diag_run() {
    if (!function_exists('wc_get_order')) {
        echo '<div class="notice notice-error"><p>WooCommerce is not active — nothing to test.</p></div>';
        return;
    }

    global $wpdb;
    @set_time_limit(120);

    // Deliberately NOT using btp_exchange_order_ids() — the set-aside list must
    // not hide the very order we are trying to identify.
    $pid = btp_exchange_product_id();
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT oi.order_id
           FROM {$wpdb->prefix}woocommerce_order_items oi
           JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
             ON oim.order_item_id = oi.order_item_id
          WHERE oi.order_item_type = 'line_item'
            AND oim.meta_key = '_product_id'
            AND oim.meta_value = %s
          ORDER BY oi.order_id DESC
          LIMIT 200",
        (string) $pid
    ));

    printf('<h2>%d exchange orders found</h2>', count($ids));

    echo '<table class="widefat striped" style="max-width:900px;"><thead><tr>'
       . '<th style="width:40px;">#</th><th style="width:90px;">Order id</th>'
       . '<th style="width:90px;">Number</th><th style="width:110px;">Memory</th>'
       . '<th style="width:90px;">Row bytes</th><th>Result</th>'
       . '</tr></thead><tbody>';

    // Push output to the browser as it happens, so a fatal still leaves a trail.
    while (ob_get_level() > 0) ob_end_flush();

    $i = 0;
    foreach ($ids as $oid) {
        $i++;
        $oid = (int) $oid;

        echo '<tr><td>' . $i . '</td><td>' . $oid . '</td>';
        flush();

        try {
            $order = wc_get_order($oid);
            if (!$order) {
                echo '<td>&mdash;</td><td>' . round(memory_get_usage(true) / 1048576, 1)
                   . ' MB</td><td>&mdash;</td><td>order not found</td></tr>';
                flush();
                continue;
            }

            echo '<td>' . esc_html($order->get_order_number()) . '</td>';
            flush();

            $row   = array_merge(btp_exchange_payload($order), btp_exchange_row($oid));
            $bytes = strlen((string) wp_json_encode($row));

            echo '<td>' . round(memory_get_usage(true) / 1048576, 1) . ' MB</td>'
               . '<td>' . number_format($bytes) . '</td>'
               . '<td style="color:#1b5e20;">ok</td></tr>';
        } catch (Throwable $e) {
            echo '<td>' . round(memory_get_usage(true) / 1048576, 1) . ' MB</td><td>&mdash;</td>'
               . '<td style="color:#b71c1c;">THREW: ' . esc_html($e->getMessage())
               . ' <code>' . esc_html(basename($e->getFile()) . ':' . $e->getLine()) . '</code></td></tr>';
        }

        unset($order, $row);
        flush();
    }

    echo '</tbody></table>';
    printf(
        '<p style="margin-top:14px;"><strong>Completed all %d orders.</strong> Peak memory %s MB of %s.</p>',
        count($ids),
        round(memory_get_peak_usage(true) / 1048576, 1),
        esc_html(ini_get('memory_limit'))
    );
    echo '<p>If this finished but the portal tab still fails, the fault is not in building the rows '
       . '&mdash; it is in sending them, which points at the response size rather than any one order.</p>';
}

/**
 * Counts only — no WooCommerce objects, no row building, so it cannot hang.
 *
 * The build test tells you WHERE it dies. This tells you WHY: an order with
 * thousands of line items or meta rows is expensive to assemble in a way that
 * has nothing to do with how many orders you ask for, which matches the
 * symptom exactly (dying just as readily at five orders as at forty).
 */
function btp_ex_diag_sizes() {
    global $wpdb;

    $pid = btp_exchange_product_id();
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT oi.order_id
           FROM {$wpdb->prefix}woocommerce_order_items oi
           JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
             ON oim.order_item_id = oi.order_item_id
          WHERE oi.order_item_type = 'line_item'
            AND oim.meta_key = '_product_id'
            AND oim.meta_value = %s
          ORDER BY oi.order_id DESC
          LIMIT 40",
        (string) $pid
    ) );

    if ( ! $ids ) { echo '<p>No exchange orders found.</p>'; return; }

    $in = implode( ',', array_map( 'intval', $ids ) );

    $items = $wpdb->get_results(
        "SELECT order_id, COUNT(*) AS n
           FROM {$wpdb->prefix}woocommerce_order_items
          WHERE order_id IN ($in)
          GROUP BY order_id", OBJECT_K );

    $imeta = $wpdb->get_results(
        "SELECT oi.order_id AS order_id, COUNT(*) AS n
           FROM {$wpdb->prefix}woocommerce_order_items oi
           JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
             ON oim.order_item_id = oi.order_item_id
          WHERE oi.order_id IN ($in)
          GROUP BY oi.order_id", OBJECT_K );

    // Order meta lives in postmeta on classic storage, or the HPOS meta table.
    $ometa = array();
    $hpos  = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wc_orders_meta'" );
    if ( $hpos ) {
        $ometa = $wpdb->get_results(
            "SELECT order_id, COUNT(*) AS n FROM {$wpdb->prefix}wc_orders_meta
              WHERE order_id IN ($in) GROUP BY order_id", OBJECT_K );
    } else {
        $ometa = $wpdb->get_results(
            "SELECT post_id AS order_id, COUNT(*) AS n FROM {$wpdb->postmeta}
              WHERE post_id IN ($in) GROUP BY post_id", OBJECT_K );
    }

    $notes = array();
    if ( $hpos ) {
        $rows = $wpdb->get_results( "SELECT id AS order_id, LENGTH(customer_note) AS n
                                       FROM {$wpdb->prefix}wc_orders WHERE id IN ($in)", OBJECT_K );
    } else {
        $rows = $wpdb->get_results( "SELECT ID AS order_id, LENGTH(post_excerpt) AS n
                                       FROM {$wpdb->posts} WHERE ID IN ($in)", OBJECT_K );
    }
    $notes = $rows ?: array();

    echo '<h2>Order sizes &mdash; newest 40</h2>';
    echo '<p class="description">Look for the row that is wildly bigger than the others. '
       . 'That is the one taking 30 seconds to assemble.</p>';
    echo '<table class="widefat striped" style="max-width:760px;"><thead><tr>'
       . '<th>Order id</th><th>Line items</th><th>Item meta rows</th>'
       . '<th>Order meta rows</th><th>Note length</th></tr></thead><tbody>';

    foreach ( $ids as $oid ) {
        $oid = (int) $oid;
        $li  = isset($items[$oid]) ? (int) $items[$oid]->n : 0;
        $im  = isset($imeta[$oid]) ? (int) $imeta[$oid]->n : 0;
        $om  = isset($ometa[$oid]) ? (int) $ometa[$oid]->n : 0;
        $nl  = isset($notes[$oid]) ? (int) $notes[$oid]->n : 0;

        $hot = ( $li > 100 || $im > 500 || $om > 300 || $nl > 20000 );

        printf(
            '<tr%s><td><strong>%d</strong></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
            $hot ? ' style="background:#fdecea;font-weight:600;"' : '',
            $oid,
            number_format($li), number_format($im), number_format($om), number_format($nl)
        );
    }
    echo '</tbody></table>';
}
