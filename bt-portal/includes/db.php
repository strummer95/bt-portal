<?php
/**
 * BT Portal — DB setup, migrations, nightly CSV export + DB backup.
 * Ported verbatim from BT-Sched-1-Template-DB. Same tables, option gates,
 * and cron hook names, so it coexists cleanly with the old snippet and
 * inherits existing schedules/data with zero migration.
 */
if (!defined('ABSPATH')) exit;

// 1. Register blank template
add_filter( 'theme_page_templates', function( $templates ) {
    $templates['bt-schedule-blank'] = 'BT Schedule (Blank)';
    return $templates;
});

// 2. Load blank template for pages that use it
add_filter( 'template_include', function( $template ) {
    if ( is_page() && get_page_template_slug() === 'bt-schedule-blank' ) {
        $blank = get_stylesheet_directory() . '/bt-schedule-blank.php';
        if ( ! file_exists( $blank ) ) {
            $blank = get_template_directory() . '/bt-schedule-blank.php';
        }
        if ( file_exists( $blank ) ) return $blank;
        // Fallback: output minimal page inline
        add_filter( 'the_content', function( $c ) {
            return $c;
        });
    }
    return $template;
});

// 3. Create/migrate database tables
add_action( 'init', function() {
    $current = get_option( 'bt_schedule_db_version', '0' );
    if ( version_compare( $current, '1.5', '>=' ) ) return;

    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $jobs_table       = $wpdb->prefix . 'bt_jobs';
    $stores_table     = $wpdb->prefix . 'bt_stores';
    $cats_table       = $wpdb->prefix . 'bt_store_categories';
    $backups_table    = $wpdb->prefix . 'bt_backups';
    $contacts_table   = $wpdb->prefix . 'bt_contacts';

    $sql_jobs = "CREATE TABLE IF NOT EXISTS $jobs_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        order_num varchar(50) NOT NULL DEFAULT '',
        customer varchar(255) NOT NULL DEFAULT '',
        qty int(11) NOT NULL DEFAULT 0,
        location varchar(255) NOT NULL DEFAULT '',
        dept varchar(100) NOT NULL DEFAULT '',
        status varchar(100) NOT NULL DEFAULT 'None',
        due_date date NOT NULL,
        art_link text,
        notes text,
        garment_type varchar(100) DEFAULT '',
        caution tinyint(1) DEFAULT 0,
        sort_order int(11) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";

    $sql_stores = "CREATE TABLE IF NOT EXISTS $stores_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL DEFAULT '',
        open_date date,
        close_date date,
        fulfillment varchar(100) NOT NULL DEFAULT '',
        status varchar(100) NOT NULL DEFAULT 'Upcoming',
        link varchar(500),
        contact_name varchar(255) DEFAULT '',
        contact_email varchar(255) DEFAULT '',
        notes text,
        category_id bigint(20) DEFAULT NULL,
        sort_order int(11) DEFAULT 0,
        delivery_dates text DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";

    $sql_cats = "CREATE TABLE IF NOT EXISTS $cats_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL DEFAULT '',
        sort_order int(11) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";

    $sql_backups = "CREATE TABLE IF NOT EXISTS $backups_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        label varchar(255) NOT NULL DEFAULT '',
        data longtext NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";

    $sql_contacts = "CREATE TABLE IF NOT EXISTS $contacts_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        first_name varchar(100) NOT NULL DEFAULT '',
        last_name varchar(100) NOT NULL DEFAULT '',
        school_org varchar(255) NOT NULL DEFAULT '',
        city_state varchar(150) NOT NULL DEFAULT '',
        email varchar(255) NOT NULL DEFAULT '',
        phone varchar(50) NOT NULL DEFAULT '',
        message text,
        source varchar(100) DEFAULT 'website_form',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql_jobs );
    dbDelta( $sql_stores );
    dbDelta( $sql_cats );
    dbDelta( $sql_backups );
    dbDelta( $sql_contacts );

    update_option( 'bt_schedule_db_version', '1.5' );
});

// ── MIGRATIONS: add columns if they don't exist ───────────────────────────
add_action( 'init', function() {
    if ( get_option( 'bt_schedule_db_migrated_v3' ) ) return;
    global $wpdb;

    $jobs_table   = $wpdb->prefix . 'bt_jobs';
    $stores_table = $wpdb->prefix . 'bt_stores';
    $cats_table   = $wpdb->prefix . 'bt_store_categories';

    // Jobs migrations
    $cols = $wpdb->get_col("SHOW COLUMNS FROM $jobs_table", 0);
    if ( ! in_array('garment_type', $cols) )
        $wpdb->query("ALTER TABLE $jobs_table ADD COLUMN garment_type varchar(100) DEFAULT ''");
    if ( ! in_array('caution', $cols) )
        $wpdb->query("ALTER TABLE $jobs_table ADD COLUMN caution tinyint(1) DEFAULT 0");
    if ( ! in_array('sort_order', $cols) )
        $wpdb->query("ALTER TABLE $jobs_table ADD COLUMN sort_order int(11) DEFAULT 0");

    // Stores migrations
    $scols = $wpdb->get_col("SHOW COLUMNS FROM $stores_table", 0);
    if ( ! in_array('category_id', $scols) )
        $wpdb->query("ALTER TABLE $stores_table ADD COLUMN category_id bigint(20) DEFAULT NULL");
    if ( ! in_array('sort_order', $scols) )
        $wpdb->query("ALTER TABLE $stores_table ADD COLUMN sort_order int(11) DEFAULT 0");

    // Categories table
    $wpdb->query("CREATE TABLE IF NOT EXISTS $cats_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL DEFAULT '',
        sort_order int(11) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) " . $wpdb->get_charset_collate() . ";");

    update_option( 'bt_schedule_db_migrated_v3', '1' );
});

// ── MIGRATION: add contact columns to bt_stores ───────────────────────────
add_action( 'init', function() {
    if ( get_option( 'bt_schedule_db_migrated_v4' ) ) return;
    global $wpdb;
    $stores_table = $wpdb->prefix . 'bt_stores';
    $scols = $wpdb->get_col("SHOW COLUMNS FROM $stores_table", 0);
    if ( ! in_array('contact_name', $scols) )
        $wpdb->query("ALTER TABLE $stores_table ADD COLUMN contact_name varchar(255) DEFAULT ''");
    if ( ! in_array('contact_email', $scols) )
        $wpdb->query("ALTER TABLE $stores_table ADD COLUMN contact_email varchar(255) DEFAULT ''");
    update_option( 'bt_schedule_db_migrated_v4', '1' );
});

// ── MIGRATION: add type column to bt_backups ──────────────────────────────
add_action( 'init', function() {
    if ( get_option( 'bt_schedule_backup_type_col' ) ) return;
    global $wpdb;
    $table = $wpdb->prefix . 'bt_backups';
    $cols  = $wpdb->get_col("SHOW COLUMNS FROM $table", 0);
    if ( ! in_array('type', $cols) )
        $wpdb->query("ALTER TABLE $table ADD COLUMN type varchar(50) DEFAULT 'manual' AFTER label");
    update_option( 'bt_schedule_backup_type_col', '1' );
});

// ── MIGRATION: rename 'data' column to 'snapshot' in bt_backups ──────────
add_action( 'init', function() {
    if ( get_option( 'bt_backups_snapshot_col' ) ) return;
    global $wpdb;
    $table = $wpdb->prefix . 'bt_backups';
    $cols  = $wpdb->get_col("SHOW COLUMNS FROM $table", 0);
    if ( in_array('data', $cols) && ! in_array('snapshot', $cols) ) {
        $wpdb->query("ALTER TABLE $table CHANGE COLUMN `data` `snapshot` longtext NOT NULL");
    } elseif ( ! in_array('snapshot', $cols) ) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN `snapshot` longtext NOT NULL DEFAULT ''");
    }
    update_option( 'bt_backups_snapshot_col', '1' );
});

// ── MIGRATION: add delivery_dates column to bt_stores ────────────────────
add_action( 'init', function() {
    if ( get_option( 'bt_schedule_db_migrated_v5' ) ) return;
    global $wpdb;
    $table = $wpdb->prefix . 'bt_stores';
    $cols  = $wpdb->get_col("SHOW COLUMNS FROM $table", 0);
    if ( ! in_array('delivery_dates', $cols) )
        $wpdb->query("ALTER TABLE $table ADD COLUMN delivery_dates text DEFAULT NULL");
    update_option( 'bt_schedule_db_migrated_v5', '1' );
});

// ── MIGRATION: create bt_day_notes table (shared day-header notes) ───────
add_action( 'init', function() {
    if ( get_option( 'bt_day_notes_v1' ) ) return;
    global $wpdb;
    $table   = $wpdb->prefix . 'bt_day_notes';
    $charset = $wpdb->get_charset_collate();
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( "CREATE TABLE IF NOT EXISTS $table (
        note_date date NOT NULL,
        note text,
        updated_by varchar(100) DEFAULT '',
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (note_date)
    ) $charset;" );
    update_option( 'bt_day_notes_v1', '1' );
});

// ── MIGRATION: create bt_closed_days table (shared day capacity / closures) ──
add_action( 'init', function() {
    if ( get_option( 'bt_closed_days_v1' ) ) return;
    global $wpdb;
    $table   = $wpdb->prefix . 'bt_closed_days';
    $charset = $wpdb->get_charset_collate();
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( "CREATE TABLE IF NOT EXISTS $table (
        day_date date NOT NULL,
        capacity int(11) NOT NULL DEFAULT 100,
        reason text,
        updated_by varchar(100) DEFAULT '',
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (day_date)
    ) $charset;" );
    update_option( 'bt_closed_days_v1', '1' );
});

// ── NIGHTLY CSV EXPORT ────────────────────────────────────────────────────
add_action( 'wp', function() {
    if ( ! wp_next_scheduled( 'bt_nightly_csv_export' ) ) {
        wp_schedule_event( strtotime('tomorrow 2:00 am'), 'daily', 'bt_nightly_csv_export' );
    }
});

add_action( 'bt_nightly_csv_export', 'btp_run_nightly_csv_export' );

function btp_run_nightly_csv_export() {
    global $wpdb;
    $jobs_table = $wpdb->prefix . 'bt_jobs';
    $today      = date('Y-m-d');
    $jobs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $jobs_table WHERE due_date >= %s ORDER BY due_date ASC, sort_order ASC, id ASC",
            $today
        ),
        ARRAY_A
    );
    $headers = ['Order #','Customer','Qty','Garment Type','Location','Dept','Status','Due Date','Caution','Art Link','Notes'];
    $rows = [ $headers ];
    foreach ( $jobs as $job ) {
        $rows[] = [
            $job['order_num']    ?? '',
            $job['customer']     ?? '',
            $job['qty']          ?? '',
            $job['garment_type'] ?? '',
            $job['location']     ?? '',
            $job['dept']         ?? '',
            $job['status']       ?? '',
            $job['due_date']     ?? '',
            !empty($job['caution']) ? 'YES' : '',
            $job['art_link']     ?? '',
            $job['notes']        ?? '',
        ];
    }
    $csv = '';
    foreach ( $rows as $row ) {
        $escaped = array_map( function($cell) {
            $cell = str_replace('"', '""', $cell);
            if ( strpbrk($cell, '",\n\r') !== false ) $cell = '"' . $cell . '"';
            return $cell;
        }, $row );
        $csv .= implode(',', $escaped) . "\r\n";
    }
    $upload_dir = wp_upload_dir();
    $export_dir = $upload_dir['basedir'] . '/bt-schedule-exports';
    if ( ! file_exists($export_dir) ) {
        wp_mkdir_p($export_dir);
        file_put_contents( $export_dir . '/.htaccess', "Options -Indexes\nDeny from all\n" );
    }
    $filename = 'schedule-' . $today . '.csv';
    file_put_contents( $export_dir . '/' . $filename, $csv );
    $files = glob( $export_dir . '/schedule-*.csv' );
    if ( $files && count($files) > 10 ) {
        sort($files);
        $to_delete = array_slice($files, 0, count($files) - 10);
        foreach ( $to_delete as $old_file ) { @unlink($old_file); }
    }
}

// ── NIGHTLY DB BACKUP ─────────────────────────────────────────────────────
add_action( 'wp', function() {
    if ( ! wp_next_scheduled( 'bt_nightly_db_backup' ) ) {
        wp_schedule_event( strtotime('tomorrow 2:00 am'), 'daily', 'bt_nightly_db_backup' );
    }
});

add_action( 'bt_nightly_db_backup', function() {
    global $wpdb;
    $table  = $wpdb->prefix . 'bt_backups';
    $jobs   = $wpdb->prefix . 'bt_jobs';
    $stores = $wpdb->prefix . 'bt_stores';
    $data   = wp_json_encode([
        'jobs'   => $wpdb->get_results("SELECT * FROM $jobs",   ARRAY_A),
        'stores' => $wpdb->get_results("SELECT * FROM $stores", ARRAY_A),
    ]);
    $label  = 'Auto — ' . date('M j, Y');
    $wpdb->insert($table, ['label' => $label, 'type' => 'auto', 'snapshot' => $data]);
    // Keep only last 30 auto backups
    $wpdb->query("DELETE FROM $table WHERE type='auto' AND id NOT IN (
        SELECT id FROM (SELECT id FROM $table WHERE type='auto' ORDER BY created_at DESC LIMIT 30) t
    )");
});
