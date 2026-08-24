<?php
/**
 * BT Portal — REST API endpoints (boomerts/v1 portal routes).
 * Ported from BT-Sched-2-API. Callback functions carry the btp_ prefix so the
 * plugin can run alongside the old snippet without redeclare fatals; routes,
 * params, and behavior are identical.
 */
if (!defined('ABSPATH')) exit;

add_action( 'rest_api_init', function() {
    $ns = 'boomerts/v1';

    // ── JOBS ─────────────────────────────────────────────────────────────
    register_rest_route( $ns, '/jobs', ['methods'=>'GET','callback'=>'btp_get_jobs','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/jobs', ['methods'=>'POST','callback'=>'btp_create_job','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/jobs/(?P<id>\d+)', ['methods'=>'PUT','callback'=>'btp_update_job','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/jobs/(?P<id>\d+)', ['methods'=>'DELETE','callback'=>'btp_delete_job','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/jobs/(?P<id>\d+)/status', ['methods'=>'POST','callback'=>'btp_update_job_status','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/jobs/reorder', ['methods'=>'POST','callback'=>'btp_reorder_jobs','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/jobs/sort', ['methods'=>'POST','callback'=>'btp_sort_jobs','permission_callback'=>'__return_true']);

    // ── STORES ───────────────────────────────────────────────────────────
    register_rest_route( $ns, '/stores', ['methods'=>'GET','callback'=>'btp_get_stores','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/stores', ['methods'=>'POST','callback'=>'btp_create_store','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/stores/(?P<id>\d+)', ['methods'=>'PUT','callback'=>'btp_update_store','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/stores/(?P<id>\d+)', ['methods'=>'DELETE','callback'=>'btp_delete_store','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/stores/reorder', ['methods'=>'POST','callback'=>'btp_reorder_stores','permission_callback'=>'__return_true']);

    // ── STORE CATEGORIES ─────────────────────────────────────────────────
    register_rest_route( $ns, '/store-categories', ['methods'=>'GET','callback'=>'btp_get_store_cats','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/store-categories', ['methods'=>'POST','callback'=>'btp_create_store_cat','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/store-categories/(?P<id>\d+)', ['methods'=>'PUT','callback'=>'btp_update_store_cat','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/store-categories/(?P<id>\d+)', ['methods'=>'DELETE','callback'=>'btp_delete_store_cat','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/store-categories/reorder', ['methods'=>'POST','callback'=>'btp_reorder_store_cats','permission_callback'=>'__return_true']);

    // ── BACKUPS ───────────────────────────────────────────────────────────
    register_rest_route( $ns, '/backups', ['methods'=>'GET','callback'=>'btp_get_backups','permission_callback'=>'btp_rest_can_backup']);
    register_rest_route( $ns, '/backups', ['methods'=>'POST','callback'=>'btp_create_backup','permission_callback'=>'btp_rest_can_backup']);
    register_rest_route( $ns, '/backups/(?P<id>\d+)', ['methods'=>'GET','callback'=>'btp_get_backup','permission_callback'=>'btp_rest_can_backup']);
    register_rest_route( $ns, '/backups/(?P<id>\d+)', ['methods'=>'DELETE','callback'=>'btp_delete_backup','permission_callback'=>'btp_rest_can_backup']);
    register_rest_route( $ns, '/backups/(?P<id>\d+)/restore', ['methods'=>'POST','callback'=>'btp_restore_backup','permission_callback'=>'btp_rest_can_backup']);

    // ── CONTACTS ──────────────────────────────────────────────────────────
    register_rest_route( $ns, '/contacts', ['methods'=>'GET','callback'=>'btp_get_contacts','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/contacts', ['methods'=>'POST','callback'=>'btp_create_contact','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/contacts/(?P<id>\d+)', ['methods'=>'DELETE','callback'=>'btp_delete_contact','permission_callback'=>'__return_true']);

    // ── DAY NOTES (shared across all employees) ──────────────────────────
    register_rest_route( $ns, '/day-notes', ['methods'=>'GET','callback'=>'btp_get_day_notes','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/day-notes', ['methods'=>'POST','callback'=>'btp_save_day_note','permission_callback'=>'__return_true']);

    // ── CLOSED DAYS (shared day capacity / closures) ─────────────────────
    register_rest_route( $ns, '/closed-days', ['methods'=>'GET','callback'=>'btp_get_closed_days','permission_callback'=>'__return_true']);
    register_rest_route( $ns, '/closed-days', ['methods'=>'POST','callback'=>'btp_save_closed_day','permission_callback'=>'__return_true']);
});

// ── JOB CALLBACKS ────────────────────────────────────────────────────────
function btp_get_jobs( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_jobs';
    $week_start = $request->get_param('week_start');
    if ( $week_start ) {
        $week_end_param = $request->get_param('week_end');
        $week_end = $week_end_param
            ? sanitize_text_field($week_end_param)
            : date('Y-m-d', strtotime($week_start.' +6 days'));
        $jobs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE due_date BETWEEN %s AND %s ORDER BY due_date ASC, sort_order ASC, id ASC",$week_start,$week_end));
    } else {
        $jobs = $wpdb->get_results("SELECT * FROM $table ORDER BY due_date ASC, sort_order ASC, id ASC");
    }
    return rest_ensure_response($jobs);
}

function btp_create_job( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_jobs';
    $p = $request->get_json_params();
    $result = $wpdb->insert($table,[
        'order_num'    => sanitize_text_field($p['order_num']??''),
        'customer'     => sanitize_text_field($p['customer']??''),
        'qty'          => intval($p['qty']??0),
        'location'     => sanitize_text_field($p['location']??''),
        'dept'         => sanitize_text_field($p['dept']??''),
        'status'       => sanitize_text_field($p['status']??'None'),
        'due_date'     => sanitize_text_field($p['due_date']??''),
        'art_link'     => sanitize_text_field($p['art_link']??''),
        'notes'        => sanitize_textarea_field($p['notes']??''),
        'garment_type' => sanitize_text_field($p['garment_type']??''),
        'caution'      => intval($p['caution']??0),
        'created_by'   => sanitize_text_field($p['user_name']??''),
    ]);

    if ($result===false) return new WP_Error('db_error','Could not create job',['status'=>500]);
    return rest_ensure_response($wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$wpdb->insert_id)));
}

function btp_update_job( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_jobs'; $id = intval($request['id']);
    $p = $request->get_json_params();
    if ( !empty($p['_partial']) ) {
        $fields = [];
        if ( array_key_exists('caution',     $p) ) $fields['caution']     = intval($p['caution']);
        if ( array_key_exists('status',      $p) ) $fields['status']      = sanitize_text_field($p['status']);
        if ( array_key_exists('sort_order',  $p) ) $fields['sort_order']  = intval($p['sort_order']);
        if ( array_key_exists('dept',        $p) ) $fields['dept']        = sanitize_text_field($p['dept']);
        if ( array_key_exists('notes',       $p) ) $fields['notes']       = sanitize_textarea_field($p['notes']);
        if ( !empty($fields) ) $wpdb->update($table, $fields, ['id'=>$id]);
        return rest_ensure_response($wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id)));
    }
    $result = $wpdb->update($table,[
        'order_num'    => sanitize_text_field($p['order_num']??''),
        'customer'     => sanitize_text_field($p['customer']??''),
        'qty'          => intval($p['qty']??0),
        'location'     => sanitize_text_field($p['location']??''),
        'dept'         => sanitize_text_field($p['dept']??''),
        'status'       => sanitize_text_field($p['status']??'None'),
        'due_date'     => sanitize_text_field($p['due_date']??''),
        'art_link'     => sanitize_text_field($p['art_link']??''),
        'notes'        => sanitize_textarea_field($p['notes']??''),
        'garment_type' => sanitize_text_field($p['garment_type']??''),
        'caution'      => intval($p['caution']??0),
    ],['id'=>$id]);
    if ( $result === false ) return new WP_Error('db_error','Could not update job',['status'=>500]);
    return rest_ensure_response($wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id)));
}

function btp_delete_job( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_jobs'; $id = intval($request['id']);
    $wpdb->delete($table,['id'=>$id]);
    return rest_ensure_response(['deleted'=>true,'id'=>$id]);
}

function btp_update_job_status( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_jobs'; $id = intval($request['id']);
    $p = $request->get_json_params();
    $wpdb->update($table,['status'=>sanitize_text_field($p['status']??'None')],['id'=>$id]);
    return rest_ensure_response($wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id)));
}

function btp_reorder_jobs( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_jobs';
    $items = $request->get_json_params()['items'] ?? [];
    foreach ($items as $item) {
        $wpdb->update($table,['sort_order'=>intval($item['sort_order']??0)],['id'=>intval($item['id'])]);
    }
    return rest_ensure_response(['ok'=>true]);
}

// Accepts {order:[id1,id2,...]} — called by drag-to-reorder in the frontend
function btp_sort_jobs( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_jobs';
    $order = $request->get_json_params()['order'] ?? [];
    if ( empty($order) || !is_array($order) ) {
        return new WP_Error('bad_request','Invalid order',['status'=>400]);
    }
    foreach ( $order as $i => $id ) {
        $wpdb->update($table,['sort_order' => $i + 1],['id' => intval($id)]);
    }
    return rest_ensure_response(['saved'=>true]);
}

// ── STORE CALLBACKS ───────────────────────────────────────────────────────
function btp_get_stores( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_stores';
    $stores = $wpdb->get_results("SELECT * FROM $table ORDER BY sort_order ASC, name ASC");
    return rest_ensure_response($stores);
}

function btp_create_store( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_stores';
    $p = $request->get_json_params();
    $result = $wpdb->insert($table,[
        'name'           => sanitize_text_field($p['name']??''),
        'open_date'      => sanitize_text_field($p['open_date']??'') ?: null,
        'close_date'     => sanitize_text_field($p['close_date']??'') ?: null,
        'fulfillment'    => sanitize_text_field($p['fulfillment']??''),
        'status'         => sanitize_text_field($p['status']??'Upcoming'),
        'link'           => sanitize_text_field($p['link']??''),
        'contact_name'   => sanitize_text_field($p['contact_name']??''),
        'contact_email'  => sanitize_email($p['contact_email']??''),
        'notes'          => sanitize_textarea_field($p['notes']??''),
        'category_id'    => !empty($p['category_id']) ? intval($p['category_id']) : null,
        'sort_order'     => intval($p['sort_order']??0),
        'delivery_dates' => sanitize_text_field($p['delivery_dates']??'[]'),
    ]);
    if ($result===false) return new WP_Error('db_error','Could not create store',['status'=>500]);
    return rest_ensure_response($wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$wpdb->insert_id)));
}

function btp_update_store( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_stores'; $id = intval($request['id']);
    $p = $request->get_json_params();
    $wpdb->update($table,[
        'name'           => sanitize_text_field($p['name']??''),
        'open_date'      => sanitize_text_field($p['open_date']??'') ?: null,
        'close_date'     => sanitize_text_field($p['close_date']??'') ?: null,
        'fulfillment'    => sanitize_text_field($p['fulfillment']??''),
        'status'         => sanitize_text_field($p['status']??'Upcoming'),
        'link'           => sanitize_text_field($p['link']??''),
        'contact_name'   => sanitize_text_field($p['contact_name']??''),
        'contact_email'  => sanitize_email($p['contact_email']??''),
        'notes'          => sanitize_textarea_field($p['notes']??''),
        'category_id'    => !empty($p['category_id']) ? intval($p['category_id']) : null,
        'sort_order'     => intval($p['sort_order']??0),
        'delivery_dates' => sanitize_text_field($p['delivery_dates']??'[]'),
    ],['id'=>$id]);
    return rest_ensure_response($wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id)));
}

function btp_delete_store( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_stores'; $id = intval($request['id']);
    $wpdb->delete($table,['id'=>$id]);
    return rest_ensure_response(['deleted'=>true,'id'=>$id]);
}

function btp_reorder_stores( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_stores';
    $items = $request->get_json_params()['items'] ?? [];
    foreach ($items as $item) {
        $wpdb->update($table,[
            'sort_order'  => intval($item['sort_order']??0),
            'category_id' => !empty($item['category_id']) ? intval($item['category_id']) : null,
        ],['id'=>intval($item['id'])]);
    }
    return rest_ensure_response(['ok'=>true]);
}

// ── STORE CATEGORY CALLBACKS ──────────────────────────────────────────────
function btp_get_store_cats( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_store_categories';
    return rest_ensure_response($wpdb->get_results("SELECT * FROM $table ORDER BY sort_order ASC, id ASC"));
}

function btp_create_store_cat( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_store_categories';
    $p = $request->get_json_params();
    $maxOrder = $wpdb->get_var("SELECT MAX(sort_order) FROM $table") ?? 0;
    $wpdb->insert($table,[
        'name'       => sanitize_text_field($p['name']??'New Category'),
        'sort_order' => intval($maxOrder)+1,
    ]);
    return rest_ensure_response($wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$wpdb->insert_id)));
}

function btp_update_store_cat( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_store_categories'; $id = intval($request['id']);
    $p = $request->get_json_params();
    $wpdb->update($table,['name'=>sanitize_text_field($p['name']??'')],['id'=>$id]);
    return rest_ensure_response($wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id)));
}

function btp_delete_store_cat( $request ) {
    global $wpdb;
    $table  = $wpdb->prefix.'bt_store_categories';
    $stores = $wpdb->prefix.'bt_stores';
    $id     = intval($request['id']);
    $wpdb->update($stores,['category_id'=>null],['category_id'=>$id]);
    $wpdb->delete($table,['id'=>$id]);
    return rest_ensure_response(['deleted'=>true,'id'=>$id]);
}

function btp_reorder_store_cats( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_store_categories';
    $items = $request->get_json_params()['items'] ?? [];
    foreach ($items as $item) {
        $wpdb->update($table,['sort_order'=>intval($item['sort_order']??0)],['id'=>intval($item['id'])]);
    }
    return rest_ensure_response(['ok'=>true]);
}

// ── BACKUP CALLBACKS ──────────────────────────────────────────────────────
function btp_get_backups( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_backups';
    return rest_ensure_response($wpdb->get_results("SELECT id,label,type,created_at FROM $table ORDER BY created_at DESC LIMIT 20"));
}

function btp_create_backup( $request ) {
    global $wpdb;
    $wpdb->suppress_errors(true);
    $table  = $wpdb->prefix.'bt_backups';
    $jobs   = $wpdb->prefix.'bt_jobs';
    $stores = $wpdb->prefix.'bt_stores';
    $p      = $request->get_json_params();
    $label  = sanitize_text_field($p['label'] ?? ('Manual — ' . date('M j, Y g:i a')));
    $type   = sanitize_text_field($p['type']  ?? 'manual');
    $cols = $wpdb->get_col("SHOW COLUMNS FROM $table", 0);
    if (!in_array('type', $cols)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN type varchar(50) DEFAULT 'manual' AFTER label");
    }
    $data = wp_json_encode([
        'jobs'   => $wpdb->get_results("SELECT * FROM $jobs",   ARRAY_A) ?: [],
        'stores' => $wpdb->get_results("SELECT * FROM $stores", ARRAY_A) ?: [],
    ]);
    $result = $wpdb->insert($table, ['label' => $label, 'type' => $type, 'snapshot' => $data]);
    if ($result === false) {
        $wpdb->suppress_errors(false);
        return new WP_Error('db_error', 'Could not create backup: ' . $wpdb->last_error, ['status' => 500]);
    }
    $new_id = $wpdb->insert_id;
    $keep_ids = $wpdb->get_col("SELECT id FROM $table ORDER BY created_at DESC LIMIT 30");
    if (!empty($keep_ids)) {
        $id_list = implode(',', array_map('intval', $keep_ids));
        $wpdb->query("DELETE FROM $table WHERE id NOT IN ($id_list)");
    }
    $wpdb->suppress_errors(false);
    return rest_ensure_response(['id' => $new_id, 'label' => $label, 'type' => $type]);
}

function btp_get_backup( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_backups'; $id = intval($request['id']);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id));
    if (!$row) return new WP_Error('not_found','Backup not found',['status'=>404]);
    $row->snapshot = json_decode($row->snapshot, true);
    return rest_ensure_response($row);
}

function btp_delete_backup( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_backups'; $id = intval($request['id']);
    $wpdb->delete($table,['id'=>$id]);
    return rest_ensure_response(['deleted'=>true,'id'=>$id]);
}

function btp_restore_backup( $request ) {
    global $wpdb;
    $table  = $wpdb->prefix.'bt_backups';
    $jobs   = $wpdb->prefix.'bt_jobs';
    $stores = $wpdb->prefix.'bt_stores';
    $id     = intval($request['id']);
    $row    = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
    if (!$row) return new WP_Error('not_found','Backup not found',['status'=>404]);
    $snap = json_decode($row->snapshot, true);
    if (!$snap) return new WP_Error('bad_data','Backup data corrupt',['status'=>500]);
    $pre = wp_json_encode([
        'jobs'   => $wpdb->get_results("SELECT * FROM $jobs",   ARRAY_A),
        'stores' => $wpdb->get_results("SELECT * FROM $stores", ARRAY_A),
    ]);
    $wpdb->insert($table, ['label'=>'Pre-restore — '.date('M j, Y g:i a'), 'type'=>'pre_restore', 'snapshot'=>$pre]);
    $wpdb->query("TRUNCATE TABLE $jobs");
    foreach ($snap['jobs'] ?? [] as $job) {
        unset($job['id']);
        $wpdb->insert($jobs, $job);
    }
    $wpdb->query("TRUNCATE TABLE $stores");
    foreach ($snap['stores'] ?? [] as $store) {
        unset($store['id']);
        $wpdb->insert($stores, $store);
    }
    return rest_ensure_response(['restored'=>true, 'label'=>$row->label]);
}

// ── CONTACT CALLBACKS ─────────────────────────────────────────────────────
function btp_get_contacts( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_contacts';
    return rest_ensure_response($wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC"));
}
function btp_create_contact( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_contacts';
    $p = $request->get_json_params();
    $result = $wpdb->insert($table,[
        'first_name' => sanitize_text_field($p['first_name']??''),
        'last_name'  => sanitize_text_field($p['last_name']??''),
        'school_org' => sanitize_text_field($p['school_org']??''),
        'city_state' => sanitize_text_field($p['city_state']??''),
        'email'      => sanitize_email($p['email']??''),
        'phone'      => sanitize_text_field($p['phone']??''),
        'message'    => sanitize_textarea_field($p['message']??''),
        'source'     => sanitize_text_field($p['source']??'website_form'),
    ]);
    if ($result===false) return new WP_Error('db_error','Could not save contact',['status'=>500]);
    return rest_ensure_response($wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$wpdb->insert_id)));
}
function btp_delete_contact( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_contacts'; $id = intval($request['id']);
    $wpdb->delete($table,['id'=>$id]);
    return rest_ensure_response(['deleted'=>true,'id'=>$id]);
}

// ── DAY NOTE CALLBACKS (shared across all employees) ──────────────────────
function btp_get_day_notes( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_day_notes';
    $start = $request->get_param('start');
    $end   = $request->get_param('end');
    if ( $start && $end ) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT note_date, note FROM $table WHERE note_date BETWEEN %s AND %s",
            sanitize_text_field($start),
            sanitize_text_field($end)
        ));
    } else {
        $rows = $wpdb->get_results("SELECT note_date, note FROM $table");
    }
    $out = new stdClass();
    foreach ( $rows as $r ) {
        $out->{$r->note_date} = $r->note;
    }
    return rest_ensure_response($out);
}

function btp_save_day_note( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_day_notes';
    $p = $request->get_json_params();
    $date = sanitize_text_field($p['date'] ?? '');
    $note = isset($p['note']) ? sanitize_textarea_field($p['note']) : '';
    $user = sanitize_text_field($p['user_name'] ?? '');
    if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ) {
        return new WP_Error('bad_request','Invalid date',['status'=>400]);
    }
    if ( $note === '' ) {
        $wpdb->delete($table, ['note_date' => $date]);
        return rest_ensure_response(['date'=>$date, 'note'=>'', 'deleted'=>true]);
    }
    // Upsert (REPLACE INTO works because note_date is the primary key)
    $wpdb->query($wpdb->prepare(
        "REPLACE INTO $table (note_date, note, updated_by) VALUES (%s, %s, %s)",
        $date, $note, $user
    ));
    return rest_ensure_response(['date'=>$date, 'note'=>$note, 'updated_by'=>$user]);
}

// ── CLOSED DAY CALLBACKS (shared across all employees) ────────────────────
function btp_get_closed_days( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_closed_days';
    $start = $request->get_param('start');
    $end   = $request->get_param('end');
    if ( $start && $end ) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT day_date, capacity, reason FROM $table WHERE day_date BETWEEN %s AND %s",
            sanitize_text_field($start),
            sanitize_text_field($end)
        ));
    } else {
        $rows = $wpdb->get_results("SELECT day_date, capacity, reason FROM $table");
    }
    $out = new stdClass();
    foreach ( $rows as $r ) {
        $out->{$r->day_date} = [
            'capacity' => intval($r->capacity),
            'reason'   => $r->reason,
        ];
    }
    return rest_ensure_response($out);
}

function btp_save_closed_day( $request ) {
    global $wpdb; $table = $wpdb->prefix.'bt_closed_days';
    $p = $request->get_json_params();
    $date = sanitize_text_field($p['date'] ?? '');
    $user = sanitize_text_field($p['user_name'] ?? '');
    if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ) {
        return new WP_Error('bad_request','Invalid date',['status'=>400]);
    }
    $capacity = isset($p['capacity']) ? intval($p['capacity']) : 100;
    $reason   = isset($p['reason']) ? sanitize_textarea_field($p['reason']) : '';
    // capacity 100 = fully open = no override; remove the row
    if ( $capacity >= 100 ) {
        $wpdb->delete($table, ['day_date' => $date]);
        return rest_ensure_response(['date'=>$date, 'capacity'=>100, 'deleted'=>true]);
    }
    // Upsert (REPLACE INTO works because day_date is the primary key)
    $wpdb->query($wpdb->prepare(
        "REPLACE INTO $table (day_date, capacity, reason, updated_by) VALUES (%s, %d, %s, %s)",
        $date, $capacity, $reason, $user
    ));
    return rest_ensure_response(['date'=>$date, 'capacity'=>$capacity, 'reason'=>$reason, 'updated_by'=>$user]);
}
