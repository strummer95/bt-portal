<?php
/*
Plugin Name: BT Portal
Plugin URI: https://boomerts.com
Description: Boomer T's employee portal — schedule board, online stores, contacts, day notes/capacity, backups, and the [bt_schedule] shortcode. Ported from the BT-Sched WPCode snippets.
Version: 0.3.1
Author: Duck and Rabbit Co.
*/

if (!defined('ABSPATH')) exit;

define('BTP_VERSION', '0.3.1');
define('BTP_DIR', plugin_dir_path(__FILE__));
define('BTP_URL', plugin_dir_url(__FILE__));
define('BTP_FILE', __FILE__);

require_once BTP_DIR . 'includes/db.php';        // tables, migrations, nightly CSV + DB backup crons (Snippet 1)
require_once BTP_DIR . 'includes/rest.php';      // all boomerts/v1 portal endpoints (Snippet 2)
require_once BTP_DIR . 'includes/shortcode.php'; // [bt_schedule] frontend app (Snippet 3)
require_once BTP_DIR . 'includes/head.php';      // admin-bar hide + modal CSS on the portal page (Snippet 4)
require_once BTP_DIR . 'includes/redirect.php';  // /stores/ redirect portal + [bt_redirect_tab] (Snippet 5)
require_once BTP_DIR . 'includes/woo.php';      // WooCommerce order completion from Transfers job cards
require_once BTP_DIR . 'includes/admin.php';
require_once BTP_DIR . 'includes/updater.php';
