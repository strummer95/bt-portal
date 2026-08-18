<?php
/*
Plugin Name: BT Portal
Plugin URI: https://boomerts.com
Description: Boomer T's employee portal — schedule board, online stores, quote, redirect, contacts, exchange tracking, OMG scanner, Chipply barcoder + scanner, day notes/capacity, backups, and the [bt_schedule] shortcode.
Version: 0.15.0
Author: Duck and Rabbit Co.
*/

if (!defined('ABSPATH')) exit;

define('BTP_VERSION', '0.15.0');
define('BTP_DIR', plugin_dir_path(__FILE__));
define('BTP_URL', plugin_dir_url(__FILE__));
define('BTP_FILE', __FILE__);

require_once BTP_DIR . 'includes/db.php';        // tables, migrations, nightly CSV + DB backup crons (Snippet 1)
require_once BTP_DIR . 'includes/rest.php';      // all boomerts/v1 portal endpoints (Snippet 2)
require_once BTP_DIR . 'includes/shortcode.php'; // [bt_schedule] frontend app (Snippet 3)
require_once BTP_DIR . 'includes/head.php';      // admin-bar hide + modal CSS on the portal page (Snippet 4)
require_once BTP_DIR . 'includes/redirect.php';  // /stores/ redirect portal + [bt_redirect_tab] (Snippet 5)
require_once BTP_DIR . 'includes/woo.php';       // WooCommerce order completion from Transfers job cards
require_once BTP_DIR . 'includes/exchanges.php'; // exchange order tracking (Other > Exchanges)
require_once BTP_DIR . 'includes/exchange-mail.php'; // customer emails on received / shipped
require_once BTP_DIR . 'includes/omg-scanner.php'; // OMG packing-slip scanner (Other > OMG Scanner)
require_once BTP_DIR . 'includes/chipply-barcoder.php'; // Chipply PDF barcoder (Other > Chipply Barcoder)
require_once BTP_DIR . 'includes/chipply-scanner.php';  // Chipply order scanner (Other > Chipply Scanner)
require_once BTP_DIR . 'includes/routing.php';   // /employees/<tab> deep links
require_once BTP_DIR . 'includes/bt-admin.php';
require_once BTP_DIR . 'includes/admin.php';
require_once BTP_DIR . 'includes/updater.php';
