<?php
/**
 * BT Portal — [bt_schedule] frontend app.
 * Ported verbatim from BT-Sched-3-Frontend. Same shortcode tag; when both the
 * plugin and the old snippet are active, the later registration wins and both
 * render identically, so activation order doesn't matter.
 */
if (!defined('ABSPATH')) exit;

add_shortcode( 'bt_schedule', function() {
    // ── LOGIN GATE ──
    // Nothing below runs for a visitor who isn't signed in with portal access.
    // Replaces the page password that used to guard /employees/.
    if ( ! btp_user_can_access() ) {
        return btp_login_form_html();
    }

    // Signed in, but still on a password an admin handed out. Asks once per
    // sign-in; "keep this one for now" gets them straight through.
    if ( btp_should_prompt_change() ) {
        return btp_change_password_html();
    }

// --- Self-healing: ensure created_by column exists ---
    global $wpdb;
    $bt_jobs_tbl = $wpdb->prefix . 'bt_jobs';
    if ( ! $wpdb->get_results( "SHOW COLUMNS FROM `{$bt_jobs_tbl}` LIKE 'created_by'" ) ) {
        $wpdb->query( "ALTER TABLE `{$bt_jobs_tbl}` ADD COLUMN `created_by` VARCHAR(100) NULL DEFAULT NULL" );
    }

    $api_base = rest_url( 'boomerts/v1' );

    // Remember which page this is, so /employees/exchanges can be routed to it.
    if ( function_exists('btp_note_portal_page') ) btp_note_portal_page();
    $btp_initial_tab = function_exists('btp_requested_tab') ? btp_requested_tab() : '';
    $btp_routing = wp_json_encode( array(
        'base'   => function_exists('btp_portal_base_url') ? btp_portal_base_url() : '',
        'pretty' => function_exists('btp_pretty_urls') ? btp_pretty_urls() : false,
        'slugs'  => function_exists('btp_tab_slugs') ? btp_tab_slugs() : new stdClass(),
        'initial'=> $btp_initial_tab ? $btp_initial_tab : 'schedule',
    ) );

    ob_start();
    ?>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Barlow:wght@300;400;500;600&family=Barlow+Condensed:wght@400;600;700&display=swap" rel="stylesheet">
<style>
/* The portal reset deliberately does NOT reach inside .bt-tool — that is BT
   Quote's [bt_quick_quote] markup, rendered in the Quote tab. It ships its own
   stylesheet (bt-quote/assets/quick-quote.css) with all of its spacing in class
   rules; this reset is ID + universal (specificity 1,0,0) and would outrank
   every one of them and flatten the tool. Excluding the subtree lets BT Quote
   style its own tool, so the portal and the public /quote/ page stay identical
   with no rules duplicated here to fall out of sync. */
#bt-schedule-app *:where(:not(.bt-tool, .bt-tool *)) { box-sizing: border-box; margin: 0; padding: 0; }
#bt-schedule-app .bt-tool, #bt-schedule-app .bt-tool * { box-sizing: border-box; }



#bt-schedule-app {
  --navy:#1a1f5e; --navy-dark:#0f1240; --navy-mid:#232875;
  --pink:#e91e8c; --pink-light:#ff47a8;
  --white:#ffffff; --gray-100:#f4f5f9; --gray-200:#e8eaf0; --gray-400:#9ca3b8; --gray-600:#5a6380;
  --dept-digi:#2196F3; --dept-emb:#FFD600; --dept-stores:#7B1FA2;
  --dept-custom:#2E7D32; --dept-transfers:#0D47A1; --dept-out:#E65100;
  font-family:'Barlow',sans-serif; background:#f5f5f5; color:var(--navy-dark);
  min-height:100vh; position:relative;
}
			  
			  
			  
#bt-schedule-app #bt-tab-schedule { background:#d1d1d1; }

/* ── HEADER ── */
#bt-schedule-app header {
  background:var(--navy-dark); display:flex; flex-direction:column;
  box-shadow:0 3px 12px rgba(0,0,0,0.4); position:sticky; top:0; z-index:100;
  border-bottom:3px solid var(--pink);
}
#bt-schedule-app .bt-header-row { display:flex; align-items:stretch; }
#bt-schedule-app .bt-header-row-top { min-height:48px; position:relative; align-items:center; }
#bt-schedule-app .bt-header-row-top .header-page-title { flex-shrink:0; }
#bt-schedule-app .bt-header-row-top .bt-tools-center {
  position:absolute; left:50%; top:50%; transform:translate(-50%,-50%);
  z-index:1;
}
#bt-schedule-app .bt-header-row-top .header-actions { margin-left:auto; border-left:1px solid rgba(255,255,255,.1) !important; padding-left:16px; }
#bt-schedule-app .bt-header-user { display:flex; align-items:center; padding:8px 16px; flex-shrink:0; }
#bt-schedule-app .btp-whoami { display:flex; align-items:center; gap:5px; color:rgba(255,255,255,.85);
  font-family:'Barlow Condensed',sans-serif; font-size:11px; font-weight:700; letter-spacing:.06em;
  text-transform:uppercase; background:#1a1f5e; border:1px solid rgba(255,255,255,.25);
  border-radius:5px; padding:4px 8px; white-space:nowrap; }
#bt-schedule-app .btp-signout { display:flex; align-items:center; gap:4px; margin-left:6px;
  color:rgba(255,255,255,.45); font-family:'Barlow Condensed',sans-serif; font-size:10px;
  font-weight:700; letter-spacing:.06em; text-decoration:none; transition:color .15s; }
#bt-schedule-app .btp-signout:hover { color:var(--pink-light); }
#bt-schedule-app .btp-whoami { cursor:pointer; }
#bt-schedule-app .btp-whoami:hover { border-color:var(--pink-light); color:#fff; }

/* ── VENDORS ── */
#bt-schedule-app .btv-top { display:flex; align-items:center; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
#bt-schedule-app .btv-h2 { margin:0; font-family:'Oswald',sans-serif; font-size:1.3em; color:#0f1240;
  letter-spacing:.04em; text-transform:uppercase; }
#bt-schedule-app #btvSearch { flex:1; min-width:200px; max-width:360px; padding:8px 11px; font-size:14px;
  font-family:'Barlow',sans-serif; border:1px solid #e8eaf0; border-radius:5px; background:#fff; outline:none; }
#bt-schedule-app #btvSearch:focus { border-color:#1a1f5e; }
#bt-schedule-app #btvCat { padding:8px 10px; font-size:13px; font-family:'Barlow',sans-serif;
  border:1px solid #e8eaf0; border-radius:5px; background:#fff; outline:none; }
#bt-schedule-app #btvCount { font-size:12px; color:#9ca3b8; font-family:'Barlow Condensed',sans-serif;
  letter-spacing:.05em; }
#bt-schedule-app .btv-add { margin-left:auto; padding:8px 14px; border:none; border-radius:5px;
  background:#e91e8c; color:#fff; font-family:'Barlow Condensed',sans-serif; font-size:12px;
  font-weight:700; letter-spacing:.07em; cursor:pointer; }
#bt-schedule-app .btv-add:hover { background:#ff47a8; }
#bt-schedule-app .btv-list { border:1px solid #e8eaf0; border-radius:8px; overflow:hidden; background:#fff; }
#bt-schedule-app .btv-table { width:100%; border-collapse:collapse; font-size:13px; }
#bt-schedule-app .btv-table thead th { background:#f4f5f9; text-align:left; padding:8px 12px;
  font-family:'Barlow Condensed',sans-serif; font-size:10px; font-weight:700; letter-spacing:.07em;
  text-transform:uppercase; color:#5a6380; border-bottom:1px solid #e8eaf0; white-space:nowrap; }
#bt-schedule-app .btv-table tbody td { padding:7px 12px; border-bottom:1px solid #f4f5f9;
  vertical-align:middle; color:#0f1240; }
#bt-schedule-app .btv-table tbody tr:hover td { background:#fafbfd; }
#bt-schedule-app .btv-table tbody tr.btv-open td { background:#f7f8fc; border-bottom-color:transparent; }
#bt-schedule-app .btv-name { font-weight:600; color:#0f1240; }
#bt-schedule-app .btv-cat { display:inline-block; margin-left:7px; font-family:'Barlow Condensed',sans-serif;
  font-size:9px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#5a6380;
  background:#f4f5f9; border-radius:20px; padding:1px 7px; vertical-align:1px; }
#bt-schedule-app .btv-table a { color:#1a1f5e; }
#bt-schedule-app .btv-mono { font-family:'Barlow',sans-serif; color:#5a6380; white-space:nowrap; }
#bt-schedule-app .btv-detail td { background:#f7f8fc; padding:0 12px 12px; }
#bt-schedule-app .btv-detail-in { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
  gap:14px; padding:4px 0 2px; }
#bt-schedule-app .btv-detail h4 { margin:0 0 3px; font-family:'Barlow Condensed',sans-serif; font-size:10px;
  font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#9ca3b8; }
#bt-schedule-app .btv-detail p { margin:0; font-size:13px; line-height:1.5; color:#5a6380; white-space:pre-line; }
#bt-schedule-app .btv-caret { background:none; border:none; cursor:pointer; color:#9ca3b8; padding:2px 4px;
  font-size:12px; line-height:1; }
#bt-schedule-app .btv-caret:hover { color:#1a1f5e; }
#bt-schedule-app .btv-acts { display:flex; gap:4px; justify-content:flex-end; white-space:nowrap; }
#bt-schedule-app .btv-form { grid-column:1/-1; border:2px solid #1a1f5e; border-radius:8px; padding:16px;
  background:#fff; }
#bt-schedule-app .btv-form .btv-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr));
  gap:10px; }
#bt-schedule-app .btv-form label { display:block; font-family:'Barlow Condensed',sans-serif; font-size:10px;
  font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#9ca3b8; margin-bottom:3px; }
#bt-schedule-app .btv-form input, #bt-schedule-app .btv-form select, #bt-schedule-app .btv-form textarea {
  width:100%; box-sizing:border-box; padding:7px 9px; font-size:13px; font-family:'Barlow',sans-serif;
  border:1px solid #e8eaf0; border-radius:4px; background:#fff; outline:none; }
#bt-schedule-app .btv-form textarea { min-height:64px; resize:vertical; }
#bt-schedule-app .btv-msg { padding:9px 12px; border-radius:4px; font-size:13px; margin-bottom:12px; }
#bt-schedule-app .btv-msg.ok { background:#eaf7ee; border-left:3px solid #2e7d32; color:#1b5e20; }
#bt-schedule-app .btv-msg.err { background:#fdecea; border-left:3px solid #c0392b; color:#7d2018; }

/* ── ACCOUNT PANEL ── */
#btpAcctBg { display:none; position:fixed; inset:0; background:rgba(15,18,64,.55); z-index:999998; }
#btpAcctPanel { display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
  width:min(860px,96vw); max-height:88vh; background:#fff; border-radius:8px; z-index:999999;
  box-shadow:0 18px 60px rgba(15,18,64,.4); font-family:'Barlow',sans-serif; overflow:hidden;
  flex-direction:column; }
#btpAcctPanel .btp-acct-head { display:flex; align-items:center; justify-content:space-between;
  background:#1a1f5e; color:#fff; padding:14px 18px; flex-shrink:0; }
#btpAcctPanel .btp-acct-head h3 { margin:0; font-family:'Oswald',sans-serif; font-size:15px;
  font-weight:600; letter-spacing:.05em; text-transform:uppercase; }
#btpAcctPanel .btp-acct-head button { background:none; border:none; color:rgba(255,255,255,.7);
  font-size:26px; line-height:1; cursor:pointer; padding:0 4px; }
#btpAcctPanel .btp-acct-head button:hover { color:#fff; }
#btpAcctPanel .btp-acct-body { padding:20px; overflow-y:auto; }
#btpAcctPanel .btp-acct-field { margin-bottom:14px; }
#btpAcctPanel label { display:block; font-family:'Barlow Condensed',sans-serif; font-size:11px;
  font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#9ca3b8; margin-bottom:4px; }
#btpAcctPanel .btp-acct-fixed { font-size:15px; color:#0f1240; background:#f4f5f9;
  border:1px solid #e8eaf0; border-radius:5px; padding:9px 11px; }
#btpAcctPanel .btp-acct-note { font-size:12px; color:#9ca3b8; margin:0 0 14px; }
#btpAcctPanel .btp-acct-btn { display:block; width:100%; padding:11px; border:none; border-radius:5px;
  background:#1a1f5e; color:#fff; font-family:'Barlow Condensed',sans-serif; font-size:13px;
  font-weight:700; letter-spacing:.07em; cursor:pointer; transition:background .15s; }
#btpAcctPanel .btp-acct-btn:hover { background:#232875; }
#btpAcctPanel .btp-acct-btn[disabled] { opacity:.5; cursor:default; }
#btpAcctPanel .btp-acct-rule { display:flex; align-items:center; gap:10px; margin:26px 0 16px;
  font-family:'Barlow Condensed',sans-serif; font-size:11px; font-weight:700; letter-spacing:.07em;
  text-transform:uppercase; color:#9ca3b8; }
#btpAcctPanel .btp-acct-rule:before, #btpAcctPanel .btp-acct-rule:after { content:''; flex:1;
  height:1px; background:#e8eaf0; }
#btpAcctPanel .btp-acct-head-row, #btpAcctPanel .btp-acct-line {
  display:grid; grid-template-columns:132px minmax(0,1fr) minmax(0,1.4fr) 104px 104px;
  gap:6px; align-items:center; }
#btpAcctPanel .btp-acct-head-row { padding:0 2px 5px; border-bottom:1px solid #e8eaf0;
  font-family:'Barlow Condensed',sans-serif; font-size:10px; font-weight:700; letter-spacing:.07em;
  text-transform:uppercase; color:#9ca3b8; position:sticky; top:0; background:#fff; z-index:2; }
#btpAcctPanel .btp-acct-line { padding:4px 2px; border-bottom:1px solid #f4f5f9; }
#btpAcctPanel .btp-acct-line:hover { background:#fafbfd; }
#btpAcctPanel .btp-acct-line.is-dirty { background:#fffdf2; }
#btpAcctPanel .btp-acct-line.is-gone { opacity:.4; }
#btpAcctPanel .btp-acct-who { display:flex; align-items:center; gap:5px; min-width:0; }
#btpAcctPanel .btp-acct-login { font-family:'Barlow Condensed',sans-serif; font-weight:700;
  font-size:13px; letter-spacing:.03em; text-transform:uppercase; color:#1a1f5e;
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
#btpAcctPanel .btp-acct-dot { flex-shrink:0; width:6px; height:6px; border-radius:50%;
  background:#c9cde0; }
#btpAcctPanel .btp-acct-dot.is-admin { background:#e91e8c; }
#btpAcctPanel .btp-acct-dot.is-wp { background:#1a1f5e; }
#btpAcctPanel .btp-acct-line input, #btpAcctPanel .btp-acct-line select,
#btpAcctPanel .btp-acct-new input, #btpAcctPanel .btp-acct-new select {
  width:100%; min-width:0; box-sizing:border-box; font-family:'Barlow',sans-serif; font-size:13px;
  padding:5px 7px; border:1px solid transparent; border-radius:4px; background:transparent;
  color:#0f1240; outline:none; }
#btpAcctPanel .btp-acct-line input:hover, #btpAcctPanel .btp-acct-line select:hover { background:#f4f5f9; }
#btpAcctPanel .btp-acct-line input:focus, #btpAcctPanel .btp-acct-line select:focus {
  border-color:#1a1f5e; background:#fff; }
#btpAcctPanel .btp-acct-ops { display:flex; gap:3px; justify-content:flex-end; }
#btpAcctPanel .btp-acct-ic { width:26px; height:26px; display:flex; align-items:center;
  justify-content:center; border:1px solid #e8eaf0; border-radius:4px; background:#fff;
  color:#5a6380; cursor:pointer; padding:0; flex-shrink:0; }
#btpAcctPanel .btp-acct-ic:hover { border-color:#1a1f5e; color:#1a1f5e; }
#btpAcctPanel .btp-acct-ic.save { background:#1a1f5e; border-color:#1a1f5e; color:#fff; }
#btpAcctPanel .btp-acct-ic.danger:hover { border-color:#c0392b; color:#c0392b; }
#btpAcctPanel .btp-acct-ic[disabled] { opacity:.28; cursor:default; }
#btpAcctPanel .btp-acct-new { display:grid;
  grid-template-columns:132px minmax(0,1fr) minmax(0,1.4fr) 104px 104px; gap:6px;
  align-items:center; padding:8px 2px; background:#f4f5f9; border-radius:5px; margin-bottom:10px; }
#btpAcctPanel .btp-acct-new input, #btpAcctPanel .btp-acct-new select { background:#fff;
  border-color:#e8eaf0; }
#btpAcctPanel .btp-acct-foot { display:flex; justify-content:space-between; align-items:center;
  margin-top:10px; font-size:11px; color:#9ca3b8; }
#btpAcctPanel .btp-acct-key { display:flex; gap:12px; align-items:center; }
#btpAcctPanel .btp-acct-key span { display:flex; align-items:center; gap:4px; }
@media (max-width:760px) {
  #btpAcctPanel .btp-acct-head-row { display:none; }
  #btpAcctPanel .btp-acct-line, #btpAcctPanel .btp-acct-new {
    grid-template-columns:1fr 1fr; gap:5px; padding:9px 2px; }
  #btpAcctPanel .btp-acct-who { grid-column:1 / -1; }
  #btpAcctPanel .btp-acct-ops { grid-column:1 / -1; justify-content:flex-start; }
  #btpAcctPanel .btp-acct-line input, #btpAcctPanel .btp-acct-line select { border-color:#e8eaf0; }
}
#bt-schedule-app .header-logo {
  background:var(--navy); padding:10px 20px; display:flex;
  align-items:center; border-right:3px solid var(--pink); flex-shrink:0;
}
#bt-schedule-app .logo-text { font-family:'Oswald',sans-serif; font-size:20px; font-weight:700; color:var(--white); letter-spacing:.05em; line-height:1.1; }
#bt-schedule-app .logo-text span { color:var(--pink); }
#bt-schedule-app .logo-sub { font-family:'Barlow Condensed',sans-serif; font-size:10px; color:var(--gray-400); letter-spacing:.15em; text-transform:uppercase; }
#bt-schedule-app .header-page-title { padding:0; display:flex; flex-direction:row; align-items:stretch; border-left:1px solid rgba(255,255,255,.15); border-right:1px solid rgba(255,255,255,.15); flex-shrink:0; }
#bt-schedule-app .header-page-title h1 { font-family:'Oswald',sans-serif; font-size:18px; font-weight:700; color:var(--white); letter-spacing:.08em; text-transform:uppercase; line-height:1; padding:0 20px; display:flex; align-items:center; }
#bt-schedule-app .header-page-title h1 span { color:var(--pink); }
#bt-schedule-app .header-week-row { font-family:'Oswald',sans-serif; font-size:20px; font-weight:400; color:rgba(255,255,255,.5); letter-spacing:.06em; text-transform:uppercase; display:flex; align-items:center; padding:0 20px; border-left:1px solid rgba(255,255,255,.15); flex-shrink:0; }
#bt-schedule-app .header-center { flex:1; display:flex; align-items:center; justify-content:center; gap:10px; padding:8px 16px; position:relative; }
#bt-schedule-app .header-tabs { display:flex; align-items:center; gap:6px; }
#bt-schedule-app .tab { padding:6px 16px; font-family:'Oswald',sans-serif; font-size:14px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:var(--gray-400); cursor:pointer; border-radius:20px; border:1.5px solid transparent; display:flex; align-items:center; transition:all .15s; }
#bt-schedule-app .tab:hover { color:var(--white); }
#bt-schedule-app .tab.active { color:var(--pink); border-color:var(--pink); background:rgba(233,30,140,.1); }

/* ── OTHER dropdown ── */
#bt-schedule-app .tab-more { position:relative; gap:5px; }
#bt-schedule-app .tab-more .caret { transition:transform .15s; }
#bt-schedule-app .tab-more.open .caret { transform:rotate(180deg); }
#bt-schedule-app .tab-menu { position:absolute; top:calc(100% + 8px); right:0; min-width:200px; background:var(--navy-dark); border:1px solid rgba(255,255,255,.15); border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.4); z-index:600; display:none; padding:6px; }
#bt-schedule-app .tab-more.open .tab-menu { display:block; }
#bt-schedule-app .tab-menu-item { display:flex; align-items:center; gap:8px; padding:9px 12px; border-radius:6px; font-family:'Oswald',sans-serif; font-size:13px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--gray-400); cursor:pointer; white-space:nowrap; transition:all .15s; }
#bt-schedule-app .tab-menu-item:hover { background:rgba(255,255,255,.07); color:var(--white); }
#bt-schedule-app .tab-menu-item.active { color:var(--pink); background:rgba(233,30,140,.12); }

/* ── EXCHANGES ── */
#bt-schedule-app .ex-filters { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
#bt-schedule-app .ex-filter { padding:5px 14px; border-radius:20px; border:1.5px solid #d8dbe6; background:#fff; color:#5a6079; font-family:'Barlow Condensed',sans-serif; font-size:14px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; cursor:pointer; transition:all .15s; }
#bt-schedule-app .ex-filter:hover { border-color:#0f1240; color:#0f1240; }
#bt-schedule-app .ex-filter.active { background:#0f1240; border-color:#0f1240; color:#fff; }
#bt-schedule-app .ex-table { width:100%; border-collapse:collapse; font-family:'Barlow',sans-serif; font-size:16px; min-width:1620px; }
#bt-schedule-app .ex-table th { padding:10px 12px; text-align:left; background:#0f1240; color:#fff; font-family:'Barlow Condensed',sans-serif; font-weight:700; letter-spacing:.07em; text-transform:uppercase; font-size:15px; white-space:nowrap; }
#bt-schedule-app .ex-table td { padding:12px; border-bottom:1px solid #e8eaf0; color:#0f1240; vertical-align:top; }
#bt-schedule-app .ex-table tr:hover td { background:#f7f8fc; }
#bt-schedule-app .ex-pill { display:inline-block; padding:3px 10px; border-radius:12px; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
#bt-schedule-app .ex-pill.awaiting { background:#fff3e0; color:#b26a00; }
#bt-schedule-app .ex-pill.received { background:#e3f2fd; color:#0d47a1; }
#bt-schedule-app .ex-pill.shipped  { background:#e8f5e9; color:#1b5e20; }
#bt-schedule-app .ex-pill.ready_pickup { background:#fff3e0; color:#8a4b00; }
#bt-schedule-app .ex-pill.cancelled { background:#fdecea; color:#8c1d18; }
#bt-schedule-app .ex-pill.unpaid { background:#fff3e0; color:#8a4b00; border:1px solid #f0c78a; }
#bt-schedule-app .ex-action { background:#f2f3f8; border:1.5px solid #d8dbe6; border-radius:6px; padding:5px 10px; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#5a6079; cursor:pointer; white-space:nowrap; transition:all .15s; }
#bt-schedule-app .ex-action:hover { border-color:#0f1240; color:#0f1240; }
#bt-schedule-app .ex-orig { font-family:'Barlow Condensed',sans-serif; font-size:14px; letter-spacing:.06em; text-transform:uppercase; color:#8a90a6; margin-bottom:8px; }
#bt-schedule-app .ex-orig strong { color:#0f1240; letter-spacing:0; }
#bt-schedule-app .ex-req-item { border-left:3px solid #e8eaf0; padding:2px 0 2px 12px; margin-bottom:10px; }
#bt-schedule-app .ex-req-item:last-child { margin-bottom:0; }
#bt-schedule-app .ex-ordered { font-size:16px; font-weight:600; color:#0f1240; display:flex; align-items:center; flex-wrap:wrap; gap:6px; }
#bt-schedule-app .ex-chip { font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; background:#f2f3f8; border:1px solid #e0e3ee; border-radius:4px; padding:1px 7px; color:#5a6079; }
#bt-schedule-app .ex-wants { font-size:16px; font-weight:600; color:#1b5e20; }
#bt-schedule-app .ex-arrow { color:#9ca3b8; font-weight:400; }
#bt-quote-warn-anchor{display:none}
.bt-quote-warn { display:flex; gap:12px; align-items:flex-start; background:#fff4e5; border:1.5px solid #f0c78a; border-left:5px solid #d97706; border-radius:8px; padding:14px 16px; margin-bottom:20px; font-family:'Barlow',sans-serif; font-size:16px; line-height:1.5; color:#7a4a00; }
.bt-quote-warn strong { color:#5c3600; }
.bt-quote-warn a { color:#1a1f5e; font-weight:600; }
.bt-toast { position:fixed; left:50%; bottom:28px; transform:translateX(-50%) translateY(12px); z-index:99999; max-width:min(560px,92vw); padding:14px 18px; border-radius:10px; font-family:'Barlow',sans-serif; font-size:16px; line-height:1.45; box-shadow:0 10px 30px rgba(0,0,0,.28); opacity:0; transition:opacity .18s, transform .18s; pointer-events:none; }
.bt-toast.show { opacity:1; transform:translateX(-50%) translateY(0); pointer-events:auto; }
.bt-toast.warn { background:#7a1f1f; color:#fff; }
.bt-toast.good { background:#14532d; color:#fff; }
.bt-toast a { color:#ffd9d9; font-weight:700; }
.bt-toast.good a { color:#c9f7d5; }
#bt-schedule-app .ex-qty { font-weight:700; color:#b26a00; }
#bt-schedule-app .ex-table td.ex-c { text-align:center; white-space:nowrap; }
/* School/Team through New Size read as one block — everything about what is
   being exchanged — ruled off from who the customer is on the left and the
   workflow controls on the right. */
#bt-schedule-app .ex-table td.ex-g { background:#f7f8fd; }
#bt-schedule-app .ex-table tr:hover td.ex-g { background:#eef0fa; }
#bt-schedule-app .ex-table th.ex-g1, #bt-schedule-app .ex-table td.ex-g1 { border-left:2px solid #c9cee4; }
#bt-schedule-app .ex-table th.ex-g2, #bt-schedule-app .ex-table td.ex-g2 { border-right:2px solid #c9cee4; }
#bt-schedule-app .ex-table td.ex-c .ex-wants { color:#1b5e20; font-weight:700; }
#bt-schedule-app .ex-store { font-weight:600; color:#0f1240; font-size:16px; }
#bt-schedule-app .ex-orignum { font-weight:700; font-size:16px; color:#0f1240; white-space:nowrap; letter-spacing:.01em; }
#bt-schedule-app .ex-src { display:inline-block; margin-top:5px; padding:2px 9px; border-radius:4px; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; background:#f2f3f8; border:1px solid #e0e3ee; color:#5a6079; }
#bt-schedule-app .ex-src.omg  { background:#e8eefc; border-color:#bfd0f4; color:#17398f; }
#bt-schedule-app .ex-src.chip { background:#fdeaf5; border-color:#f3bedd; color:#9c1266; }
#bt-schedule-app .ex-way { display:inline-block; padding:3px 10px; border-radius:4px; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; background:#f2f3f8; border:1px solid #e0e3ee; color:#5a6079; white-space:nowrap; }
#bt-schedule-app .ex-way.pickup { background:#fff3e0; border-color:#f0c78a; color:#8a4b00; }
#bt-schedule-app .ex-way.drop { background:#eef7ee; border-color:#c3e0c3; color:#1b5e20; }
#bt-schedule-app .ex-table td.ex-out { white-space:normal; }
#bt-schedule-app .ex-track { margin-top:6px; text-align:center; }
#bt-schedule-app .ex-none { color:#9ca3b8; font-style:italic; font-size:15px; }
#bt-schedule-app .ex-pair { min-height:26px; margin-bottom:8px; }
#bt-schedule-app .ex-pair:last-child { margin-bottom:0; }
#bt-schedule-app .ex-raw { padding:8px 10px; background:#fff8e1; border-radius:4px; font-size:14px; color:#6b5200; line-height:1.5; }
#bt-schedule-app .ex-select { font-family:'Barlow',sans-serif; font-size:15px; padding:6px 8px; border:1.5px solid #d8dbe6; border-radius:6px; background:#fff; color:#0f1240; cursor:pointer; }
#bt-schedule-app .ex-input { font-family:'Barlow',sans-serif; font-size:15px; padding:6px 8px; border:1.5px solid #d8dbe6; border-radius:6px; width:100%; box-sizing:border-box; color:#0f1240; }
#bt-schedule-app .ex-input:focus, #bt-schedule-app .ex-select:focus { outline:none; border-color:var(--pink); }
#bt-schedule-app .header-actions { display:flex; align-items:center; gap:10px; padding:8px 16px; flex-shrink:0; border-left:1px solid rgba(255,255,255,.1); }
#bt-schedule-app .filter-toggle-btn { background:rgba(255,255,255,.07); border:1.5px solid rgba(255,255,255,.12); color:var(--gray-400); font-family:'Barlow Condensed',sans-serif; font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; cursor:pointer; padding:6px 12px; border-radius:6px; display:flex; align-items:center; gap:5px; transition:all .15s; white-space:nowrap; }
#bt-schedule-app .filter-toggle-btn:hover { color:var(--white); border-color:rgba(255,255,255,.3); background:rgba(255,255,255,.1); }
#bt-schedule-app .filter-toggle-btn.open { color:var(--pink); border-color:var(--pink); background:rgba(233,30,140,.1); }

/* ── SEARCH (header) ── */
#bt-schedule-app .bt-tools-left { display:flex; align-items:center; gap:8px; flex-wrap:nowrap; white-space:nowrap; }
#bt-schedule-app .bt-tools-center { display:flex; align-items:center; gap:10px; flex-wrap:nowrap; white-space:nowrap; }
#bt-schedule-app .bt-tools-right { display:flex; align-items:center; gap:8px; flex-wrap:nowrap; white-space:nowrap; }
#bt-schedule-app .bt-search-wrap { position:relative; display:flex; align-items:center; }
#bt-schedule-app .bt-search-toggle { background:rgba(255,255,255,.07); border:1.5px solid rgba(255,255,255,.12); color:var(--gray-400); cursor:pointer; padding:6px 8px; border-radius:6px; display:flex; align-items:center; justify-content:center; transition:all .15s; position:relative; z-index:1; }
#bt-schedule-app .bt-search-toggle:hover { color:var(--white); border-color:rgba(255,255,255,.3); background:rgba(255,255,255,.1); }
#bt-schedule-app .bt-search-wrap.expanded .bt-search-toggle { color:var(--pink); border-color:var(--pink); background:rgba(233,30,140,.1); }
#bt-schedule-app .bt-search-overlay {
  display:none;
  position:absolute;
  left:calc(100% + 6px);
  top:50%;
  transform:translateY(-50%);
  width:280px;
  z-index:600;
}
#bt-schedule-app .bt-search-wrap.expanded .bt-search-overlay { display:block; }
#bt-schedule-app #btSearchInput {
  width:100%;
  background:var(--navy-dark);
  border:1.5px solid var(--pink);
  color:var(--white);
  font-family:'Barlow',sans-serif;
  font-size:12px;
  padding:7px 28px 7px 12px;
  border-radius:6px;
  outline:none;
  box-shadow:0 4px 16px rgba(0,0,0,.4);
}
#bt-schedule-app #btSearchInput::placeholder { color:var(--gray-400); font-style:italic; }
#bt-schedule-app #btSearchInput:focus { background:rgba(255,255,255,.05); }
#bt-schedule-app #btSearchClear { display:none; position:absolute; right:6px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--gray-400); font-size:18px; line-height:1; cursor:pointer; padding:2px 6px; border-radius:4px; }
#bt-schedule-app #btSearchClear:hover { color:var(--white); background:rgba(255,255,255,.08); }
#bt-schedule-app .bt-search-wrap.has-text #btSearchClear { display:block; }
#bt-schedule-app .bt-search-results { position:absolute; top:calc(100% + 6px); left:0; right:0; min-width:340px; max-height:380px; overflow-y:auto; background:var(--navy-dark); border:1px solid rgba(255,255,255,.15); border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.4); z-index:600; display:none; }
#bt-schedule-app .bt-search-wrap.open .bt-search-results { display:block; }
#bt-schedule-app .bt-search-result { padding:9px 12px; cursor:pointer; border-bottom:1px solid rgba(255,255,255,.06); transition:background .12s; display:flex; align-items:center; gap:10px; }
#bt-schedule-app .bt-search-result:last-child { border-bottom:none; }
#bt-schedule-app .bt-search-result:hover, #bt-schedule-app .bt-search-result.active { background:rgba(233,30,140,.15); }
#bt-schedule-app .bt-search-result .bsr-order { font-family:'Barlow Condensed',sans-serif; font-weight:700; color:var(--pink); font-size:13px; letter-spacing:.04em; min-width:55px; }
#bt-schedule-app .bt-search-result .bsr-customer { color:var(--white); font-size:14px; font-weight:600; flex:1; }
#bt-schedule-app .bt-search-result .bsr-week { font-family:'Barlow Condensed',sans-serif; font-size:11px; color:var(--gray-400); letter-spacing:.05em; text-transform:uppercase; }
#bt-schedule-app .bt-search-empty { padding:18px 14px; text-align:center; color:var(--gray-400); font-size:13px; font-style:italic; }
#bt-schedule-app .bt-search-result mark { background:rgba(233,30,140,.35); color:#fff; padding:0 2px; border-radius:2px; }

/* Highlight a job-card flash when jumped to from search */
#bt-schedule-app .job-card.bt-flash { animation: btFlashPulse 1.8s ease-out; }
@keyframes btFlashPulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(233,30,140,0); }
  20%      { box-shadow: 0 0 0 4px rgba(233,30,140,.6); }
  60%      { box-shadow: 0 0 0 4px rgba(233,30,140,.3); }
}
#bt-schedule-app .week-nav { display:flex; align-items:center; gap:6px; background:var(--navy); border-radius:6px; padding:6px 12px; }
#bt-schedule-app .week-nav button { background:none; border:none; color:var(--gray-400); cursor:pointer; font-size:20px; padding:2px 6px; border-radius:4px; transition:all .15s; line-height:1; }
#bt-schedule-app .week-nav button:hover { color:var(--white); background:rgba(255,255,255,.1); }
#bt-schedule-app .week-label { font-family:'Barlow Condensed',sans-serif; font-size:18px; font-weight:700; color:var(--white); letter-spacing:.05em; min-width:170px; text-align:center; }
#bt-schedule-app .btn-add { background:var(--pink); color:var(--white); border:none; padding:7px 16px; border-radius:6px; font-family:'Oswald',sans-serif; font-size:12px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; cursor:pointer; transition:all .15s; display:flex; align-items:center; gap:6px; white-space:nowrap; }
#bt-schedule-app .btn-add:hover { background:var(--pink-light); transform:translateY(-1px); }
#bt-schedule-app .saving-indicator { font-family:'Barlow Condensed',sans-serif; font-size:11px; color:var(--gray-400); letter-spacing:.06em; }

/* ── FILTER BAR ── */
/* Filter dropdown — floats below the Filters button */
#bt-schedule-app .filter-bar {
  display:none;
  position:absolute;
  top:calc(100% + 6px);
  left:50%;
  transform:translateX(-50%);
  background:var(--navy-dark);
  border:1px solid rgba(255,255,255,.15);
  border-radius:10px;
  padding:12px 14px;
  flex-direction:column;
  gap:8px;
  z-index:500;
  min-width:280px;
  box-shadow:0 8px 24px rgba(0,0,0,.4);
}
#bt-schedule-app .filter-bar.open { display:flex; }
#bt-schedule-app .filter-bar-row { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
#bt-schedule-app .filter-label { font-family:'Barlow Condensed',sans-serif; font-size:11px; font-weight:600; color:var(--gray-400); letter-spacing:.12em; text-transform:uppercase; white-space:nowrap; }
#bt-schedule-app .filter-pill { padding:3px 10px; border-radius:20px; border:1.5px solid transparent; font-family:'Barlow Condensed',sans-serif; font-size:12px; font-weight:600; letter-spacing:.06em; cursor:pointer; transition:all .15s; background:rgba(255,255,255,.07); color:var(--gray-400); }
#bt-schedule-app .filter-pill:hover { color:var(--white); border-color:rgba(255,255,255,.3); }
#bt-schedule-app .filter-pill[data-dept="all"].active { border-color:var(--pink); color:var(--pink); background:rgba(233,30,140,.12); }
#bt-schedule-app .filter-pill[data-dept="Digi"].active { border-color:var(--dept-digi); color:var(--dept-digi); background:rgba(33,150,243,.15); }
#bt-schedule-app .filter-pill[data-dept="Embroidery"].active { border-color:var(--dept-emb); color:var(--dept-emb); background:rgba(255,214,0,.15); }
#bt-schedule-app .filter-pill[data-dept="Online Stores"].active { border-color:var(--dept-stores); color:#ce93d8; background:rgba(123,31,162,.2); }
#bt-schedule-app .filter-pill[data-dept="Custom"].active { border-color:var(--dept-custom); color:#81c784; background:rgba(46,125,50,.15); }
#bt-schedule-app .filter-pill[data-dept="Transfers"].active { border-color:#5c6bc0; color:#9fa8da; background:rgba(13,71,161,.2); }
#bt-schedule-app .filter-pill[data-dept="Out of House"].active { border-color:var(--dept-out); color:#ffb74d; background:rgba(230,81,0,.15); }
#bt-schedule-app .filter-divider { width:100%; height:1px; background:rgba(255,255,255,.1); margin:2px 0; }
#bt-schedule-app .store-filter-pill { padding:3px 10px; border-radius:20px; border:1.5px solid transparent; font-family:'Barlow Condensed',sans-serif; font-size:12px; font-weight:600; letter-spacing:.06em; cursor:pointer; transition:all .15s; background:rgba(255,255,255,.07); color:var(--gray-400); }
#bt-schedule-app .store-filter-pill:hover { color:var(--white); border-color:rgba(255,255,255,.3); }
#bt-schedule-app .store-filter-pill[data-store-status="all"].active { border-color:var(--pink); color:var(--pink); background:rgba(233,30,140,.12); }
#bt-schedule-app .store-filter-pill[data-store-status="Upcoming"].active { border-color:#90caf9; color:#90caf9; background:rgba(13,71,161,.2); }
#bt-schedule-app .store-filter-pill[data-store-status="Active"].active { border-color:#81c784; color:#81c784; background:rgba(46,125,50,.15); }
#bt-schedule-app .store-filter-pill[data-store-status="Closing Soon"].active { border-color:#ffb74d; color:#ffb74d; background:rgba(230,81,0,.15); }
#bt-schedule-app .store-filter-pill[data-store-status="Closed"].active { border-color:var(--gray-400); color:var(--gray-400); background:rgba(255,255,255,.05); }
#bt-schedule-app .status-filter-pill { padding:3px 10px; border-radius:20px; border:1.5px solid transparent; font-family:'Barlow Condensed',sans-serif; font-size:12px; font-weight:600; letter-spacing:.06em; cursor:pointer; transition:all .15s; background:rgba(255,255,255,.07); color:var(--gray-400); }
#bt-schedule-app .status-filter-pill:hover { color:var(--white); }
#bt-schedule-app .status-filter-pill[data-status="all"].active { border-color:var(--pink); color:var(--pink); background:rgba(233,30,140,.12); }
#bt-schedule-app .status-filter-pill[data-status="None"].active { border-color:var(--gray-400); color:var(--gray-400); }
#bt-schedule-app .status-filter-pill[data-status="Pending Approval"].active { border-color:#F57C00; color:#F57C00; }
#bt-schedule-app .status-filter-pill[data-status="Approved/Items Ordered"].active { border-color:#4a5568; color:#a0aec0; }
#bt-schedule-app .status-filter-pill[data-status="Ready for Production"].active { border-color:#2E7D32; color:#81c784; }
#bt-schedule-app .status-filter-pill[data-status="Complete/Notify Customer"].active { border-color:#b0bec5; color:#b0bec5; }
#bt-schedule-app .status-filter-pill[data-status="On Hold"].active { border-color:#f44336; color:#f44336; }

/* ── SECTION TITLE BAR ── */
#bt-schedule-app .section-title-bar {
  padding:10px 20px 8px;
  background:var(--white);
  border-bottom:2px solid var(--gray-200);
  display:flex;
  align-items:baseline;
  gap:12px;
}
#bt-schedule-app .section-title {
  font-family:'Oswald',sans-serif;
  font-size:22px;
  font-weight:700;
  color:var(--navy-dark);
  letter-spacing:.06em;
  text-transform:uppercase;
  line-height:1;
}
#bt-schedule-app .section-title span { color:var(--pink); }
#bt-schedule-app .section-subtitle {
  font-family:'Barlow Condensed',sans-serif;
  font-size:13px;
  color:var(--gray-400);
  letter-spacing:.08em;
  text-transform:uppercase;
}

/* ── VERTICAL WEEK BOARD ── */
#bt-schedule-app .board {
  display:flex;
  flex-direction:row;
  gap:0;
  padding:0;
  min-height:calc(100vh - 120px);
  align-items:stretch;
  border-top:6px solid var(--dark navy);
}

/* ── FLUID HEADER SCALING ── */
#bt-schedule-app .logo-text { font-size: clamp(15px, 1.3vw, 20px); }
#bt-schedule-app .logo-sub { display: none; }
#bt-schedule-app .header-logo { padding: 10px clamp(10px, 1.1vw, 20px); }
#bt-schedule-app .header-page-title { flex-shrink: 1; min-width: 0; }
#bt-schedule-app .header-page-title h1 { font-size: clamp(13px, 1.2vw, 20px); padding: 0 clamp(8px, 1.1vw, 20px); white-space: nowrap; }
#bt-schedule-app .header-center { gap: clamp(4px, 0.5vw, 10px); padding: 6px clamp(6px, 0.8vw, 16px); }
#bt-schedule-app .week-label { font-size: clamp(13px, 1.1vw, 20px); min-width: clamp(90px, 9vw, 170px); }
#bt-schedule-app .week-nav { padding: 6px clamp(6px, 0.7vw, 12px); gap: clamp(3px, 0.4vw, 6px); }
#bt-schedule-app .header-actions { flex-shrink: 1; min-width: 0; gap: clamp(3px, 0.5vw, 10px); padding: 6px clamp(5px, 0.8vw, 16px); }
#bt-schedule-app .header-tabs { flex-shrink: 1; min-width: 0; }
#bt-schedule-app .tab { font-size: clamp(11px, 0.85vw, 15px); padding: 6px clamp(7px, 0.9vw, 20px); white-space: nowrap; }
#bt-schedule-app .filter-toggle-btn { padding: clamp(4px, 0.4vw, 6px) clamp(7px, 0.7vw, 12px); font-size: clamp(10px, 0.75vw, 12px); }
#bt-schedule-app .btn-add { font-size: clamp(11px, 0.8vw, 13px); padding: 7px clamp(9px, 1vw, 18px); }
#bt-schedule-app .btp-whoami { max-width: clamp(90px, 7vw, 160px); overflow:hidden; text-overflow:ellipsis;
  font-size: clamp(10px, 0.7vw, 12px); }
#bt-schedule-app .btp-signout { font-size: clamp(9px, 0.6vw, 11px); }

/* ── INLINE TOOLS-CENTER (auto-margins so it shifts smoothly with available space) ── */
@media (max-width: 2400px) {
  #bt-schedule-app .bt-header-row-top .bt-tools-center {
    position: static !important;
    transform: none !important;
    left: auto !important;
    top: auto !important;
    margin-left: auto;
  }
}

/* ── TWO-LINE HEADER (only when truly out of room) ── */
@media (max-width: 1400px) {
  #bt-schedule-app .bt-header-row-top { flex-wrap: wrap; }
  #bt-schedule-app .header-logo { flex-shrink: 0; }
  #bt-schedule-app .header-page-title { flex-shrink: 1; min-width: 0; }

  /* Tools-center drops to its own full-width row */
  #bt-schedule-app .bt-header-row-top .bt-tools-center {
    flex: 1 1 100%;
    width: 100%;
    min-width: 0;
    justify-content: center;
    padding: 6px 12px;
    border-top: 1px solid rgba(255,255,255,.12);
    order: 50;
    flex-wrap: wrap;
  }

  #bt-schedule-app .tab { font-size: 13px; padding: 6px 14px; }
  #bt-schedule-app .btn-add { font-size: 13px; padding: 7px 16px; }
}

/* ── MOBILE ── */
@media (max-width: 700px) {
  #bt-schedule-app header { flex-wrap:wrap; height:auto; }
  #bt-schedule-app .header-logo { padding:8px 14px; }
  #bt-schedule-app .logo-text { font-size:16px; }
  /* Stack title and week vertically, same row as logo */
  #bt-schedule-app .header-page-title { flex-direction:column; align-items:flex-start; justify-content:center; padding:8px 12px; border-right:none; }
  #bt-schedule-app .header-page-title h1 { font-size:13px; padding:0; }
  #bt-schedule-app .header-week-row { font-size:11px; padding:0; border-left:none; margin-top:2px; }
  #bt-schedule-app .header-center { order:3; width:100%; justify-content:flex-start; padding:6px 14px; border-top:1px solid rgba(255,255,255,.1); }
  #bt-schedule-app .header-actions { gap:6px; padding:6px 14px; }
  #bt-schedule-app .week-label { min-width:100px; font-size:13px; }
  #bt-schedule-app .filter-bar { padding:8px 12px; }

  #bt-schedule-app .board {
    flex-direction:column;
    border-top:8px solid var(--white);
  }

  #bt-schedule-app .day-col {
    width:100%;
    border-right:none;
    border-bottom:1px solid var(--gray-200);
  }
  #bt-schedule-app .day-col:last-child { border-bottom:none; }

  #bt-schedule-app .day-col-header {
    position:static;
    background:var(--navy);
  }

  #bt-schedule-app .today-tab {
    position:static;
    display:block;
  }

  #bt-schedule-app .day-col-cards {
    padding:8px;
    flex-direction:column;
    gap:8px;
  }

  #bt-schedule-app .job-card {
    width:100%;
  }

  #bt-schedule-app .bt-modal-wrap {
    max-height:100vh;
    border-radius:0;
  }

  #bt-schedule-app .bt-dept-grid { grid-template-columns:repeat(2,1fr); }
  #bt-schedule-app .bt-status-grid { grid-template-columns:repeat(2,1fr); }

  /* Fix Add Job button truncation */
  #bt-schedule-app .btn-add { font-size:11px; padding:6px 10px; gap:4px; }
  #bt-schedule-app .header-actions { gap:6px; padding:6px 10px; }

  /* Online stores — hide table, show stacked cards instead */
  #bt-schedule-app .stores-table { display:none; }
  #bt-schedule-app .stores-cards { display:flex !important; flex-direction:column; gap:12px; }
}

#bt-schedule-app .day-col {
  flex:1;
  display:flex;
  flex-direction:column;
  border-right:1px solid var(--gray-200);
  min-width:0;
}
#bt-schedule-app .day-col:last-child { border-right:none; }
#bt-schedule-app .day-scroll-arrow {
  display:none;
  position:sticky;
  bottom:10px;
  align-self:center;
  width:44px;
  height:44px;
  background:#e91e8c;
  color:#fff;
  border-radius:50%;
  font-size:22px;
  line-height:44px;
  text-align:center;
  cursor:pointer;
  box-shadow:0 3px 14px rgba(233,30,140,.5);
  z-index:20;
  margin:4px auto 8px;
  flex-shrink:0;
  user-select:none;
}
#bt-schedule-app .day-scroll-arrow.visible { display:block; }

#bt-schedule-app .day-col-header {
  background:var(--navy-dark);
  padding:0;
  display:flex;
  flex-direction:column;
  border-bottom:2px solid rgba(255,255,255,.08);
  position:sticky;
  top:52px;
  z-index:10;
  overflow:hidden;
}
#bt-schedule-app .day-col.today .day-col-header {
  border:2px solid var(--pink);
  border-top:none;
}
#bt-schedule-app .day-col.today .day-col-cards {
  border-left:2px solid var(--pink);
  border-right:2px solid var(--pink);
  border-bottom:2px solid var(--pink);
  margin-top:-2px;
}

/* TODAY tab — absolutely positioned so it doesn't affect header height */
#bt-schedule-app .day-col {
  position:relative;
}
#bt-schedule-app .today-tab {
  position:absolute;
  top:-18px;
  left:0;
  right:0;
  background:var(--pink);
  color:var(--white);
  font-family:'Barlow Condensed',sans-serif;
  font-size:10px;
  font-weight:700;
  letter-spacing:.12em;
  text-transform:uppercase;
  text-align:center;
  padding:2px 0;
  width:100%;
  z-index:11;
}

#bt-schedule-app .day-col-header-inner {
  padding:5px 10px;
  display:flex;
  align-items:baseline;
  gap:8px;
}

#bt-schedule-app .day-col-name {
  font-family:'Oswald',sans-serif;
  font-size:18px;
  font-weight:700;
  color:rgba(255,255,255,.5);
  line-height:1;
  text-transform:uppercase;
  flex-shrink:0;
}
#bt-schedule-app .day-col.today .day-col-name { color:var(--white); }

#bt-schedule-app .day-col-date {
  font-family:'Barlow Condensed',sans-serif;
  font-size:18px;
  font-weight:600;
  color:rgba(255,255,255,.35);
  letter-spacing:.03em;
}
#bt-schedule-app .day-col.today .day-col-date { color:rgba(255,255,255,.75); }

#bt-schedule-app .day-col-count {
  background:rgba(255,255,255,.12);
  color:var(--white);
  font-family:'Barlow Condensed',sans-serif;
  font-size:14px;
  font-weight:700;
  padding:3px 10px;
  border-radius:10px;
  letter-spacing:.05em;
  white-space:nowrap;
}
#bt-schedule-app .day-col.today .day-col-count { background:var(--pink); }
#bt-schedule-app .day-dept-counts { display:flex; gap:4px; align-items:center; margin-left:auto; }
#bt-schedule-app .day-dept-badge { font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; padding:3px 8px; border-radius:10px; letter-spacing:.04em; white-space:nowrap; }
#bt-schedule-app .day-dept-badge.badge-total { background:rgba(255,255,255,.12); color:var(--white); }
#bt-schedule-app .day-col.today .day-dept-badge.badge-total { background:var(--pink); }
#bt-schedule-app .day-dept-badge.badge-digi { background:rgba(33,150,243,.35); color:#90caf9; }
#bt-schedule-app .day-dept-badge.badge-emb  { background:rgba(255,214,0,.3); color:#ffe57f; }

/* Mobile store cards (hidden on desktop, shown on mobile via media query) */
#bt-schedule-app .stores-cards { display:none; }
#bt-schedule-app .store-card { background:#fff; border-radius:8px; padding:14px 16px; box-shadow:0 1px 4px rgba(0,0,0,.08); border:1.5px solid var(--gray-200); cursor:pointer; display:flex; flex-direction:column; gap:6px; }
#bt-schedule-app .store-card:hover { border-color:var(--navy); }
#bt-schedule-app .store-card-name { font-family:'Oswald',sans-serif; font-size:16px; font-weight:700; color:var(--navy-dark); }
#bt-schedule-app .store-card-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
#bt-schedule-app .store-card-label { font-family:'Barlow Condensed',sans-serif; font-size:10px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--gray-400); }
#bt-schedule-app .store-card-value { font-family:'Barlow',sans-serif; font-size:13px; color:var(--navy-dark); }
#bt-schedule-app .store-card-notes { font-size:12px; color:var(--gray-600); font-style:italic; }
#bt-schedule-app .day-note-area { padding:4px 12px 6px; cursor:text; background:rgba(255,255,255,.06); border-top:1px solid rgba(255,255,255,.08); margin-top:2px; }
#bt-schedule-app .day-note-display { font-family:'Barlow',sans-serif; font-size:11px; font-style:italic; color:rgba(255,255,255,.45); line-height:1.3; min-height:14px; }
#bt-schedule-app .day-note-display:empty::before { content:'+ add note'; color:rgba(255,255,255,.2); font-style:normal; }
#bt-schedule-app .day-note-input { display:none; width:100%; background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2); border-radius:4px; padding:4px 6px; font-family:'Barlow',sans-serif; font-size:11px; font-style:italic; color:rgba(255,255,255,.8); outline:none; box-sizing:border-box; resize:none; }
#bt-schedule-app .day-note-input::placeholder { color:rgba(255,255,255,.3); }

/* ── CALENDAR OVERLAY ── */
#btCalendarOverlay {
  display:none; position:absolute; top:calc(100% + 6px); left:50%; transform:translateX(-50%);
  background:var(--navy-dark); border:1px solid rgba(255,255,255,.15);
  border-radius:12px; padding:16px; z-index:500; width:320px;
  box-shadow:0 8px 32px rgba(0,0,0,.5);
}
#btCalendarOverlay.open { display:block; }
#btCalendarOverlay .cal-nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
#btCalendarOverlay .cal-title { font-family:'Oswald',sans-serif; font-size:16px; font-weight:700; color:var(--white); letter-spacing:.05em; text-transform:uppercase; }
#btCalendarOverlay .cal-nav-btn { background:rgba(255,255,255,.08); border:none; color:var(--gray-400); cursor:pointer; font-size:16px; padding:4px 10px; border-radius:5px; transition:all .15s; }
#btCalendarOverlay .cal-nav-btn:hover { color:var(--white); background:rgba(255,255,255,.15); }
#btCalendarOverlay .cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:3px; }
#btCalendarOverlay .cal-dow { font-family:'Barlow Condensed',sans-serif; font-size:10px; font-weight:700; letter-spacing:.08em; color:var(--gray-400); text-align:center; padding:3px 0; text-transform:uppercase; }
#btCalendarOverlay .cal-day { position:relative; border-radius:6px; padding:5px 3px 4px; cursor:pointer; text-align:center; transition:all .15s; border:1.5px solid transparent; min-height:44px; display:flex; flex-direction:column; align-items:center; gap:2px; }
#btCalendarOverlay .cal-day:hover { background:rgba(255,255,255,.1); }
#btCalendarOverlay .cal-day.other-month { opacity:.3; }
#btCalendarOverlay .cal-day.is-today { border-color:var(--pink); background:rgba(233,30,140,.1); }
#btCalendarOverlay .cal-day.is-selected-week { background:rgba(255,255,255,.08); }
#btCalendarOverlay .cal-day-num { font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:600; color:var(--white); line-height:1; }
#btCalendarOverlay .cal-day.is-today .cal-day-num { color:var(--pink); font-weight:700; }
#btCalendarOverlay .cal-dots { display:flex; gap:2px; flex-wrap:wrap; justify-content:center; max-width:36px; }
#btCalendarOverlay .cal-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
#btCalendarOverlay .cal-day.is-closed { background:rgba(180,30,30,.2); }
#btCalendarOverlay .cal-day.is-closed .cal-day-num { color:rgba(255,100,100,.7); text-decoration:line-through; }
#btCalendarOverlay .cal-day.is-restricted { background:rgba(255,152,0,.1); border-color:rgba(255,152,0,.3); }
#bt-schedule-app .day-col.closed .day-col-header-inner { opacity:.6; }
#bt-schedule-app .day-col.closed .day-closed-tab { display:block; }
#bt-schedule-app .day-closed-tab { display:none; background:#b71c1c; color:#fff; font-family:'Barlow Condensed',sans-serif; font-size:10px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; text-align:center; padding:2px 0; width:100%; }
#bt-schedule-app .day-col.closed .day-col-cards { display:none; }
#bt-schedule-app .day-closed-body {
  flex:1;
  min-height:calc(100vh - 200px);
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  gap:8px;
  padding:24px 12px;
  background:repeating-linear-gradient(135deg, rgba(0,0,0,.05) 0px, rgba(0,0,0,.05) 10px, rgba(200,200,210,.08) 10px, rgba(200,200,210,.08) 20px);
  pointer-events:none;
}
#bt-schedule-app .day-col.restricted .day-col-cards { background:inherit; flex:1; }
#bt-schedule-app .day-hatch-block {
  position:absolute;
  bottom:0;
  left:0;
  right:0;
  background:repeating-linear-gradient(135deg, rgba(0,0,0,.04) 0px, rgba(0,0,0,.04) 8px, rgba(180,180,180,.07) 8px, rgba(180,180,180,.07) 16px);
  pointer-events:none;
  border-top:1px dashed rgba(0,0,0,.12);
  display:flex;
  align-items:center;
  justify-content:center;
  padding:12px 8px;
}
#bt-schedule-app .day-hatch-reason {
  font-family:'Oswald',sans-serif;
  font-size:18px;
  font-weight:700;
  color:rgba(80,80,80,.55);
  text-align:center;
  letter-spacing:.04em;
  text-transform:uppercase;
  line-height:1.2;
} font-size:16px; font-weight:600; color:rgba(180,40,40,.7); text-align:center; letter-spacing:.04em; }
#bt-schedule-app .day-closed-reason { font-family:'Oswald',sans-serif; font-size:22px; font-weight:700; color:rgba(180,40,40,.65); text-align:center; letter-spacing:.04em; text-transform:uppercase; line-height:1.2; padding:0 8px; }
#bt-schedule-app .day-closed-jobs { font-family:'Barlow Condensed',sans-serif; font-size:11px; color:rgba(150,100,100,.6); font-style:italic; text-align:center; }
#bt-schedule-app .day-col-header-inner { cursor:default; }
#bt-schedule-app .day-col-date-area { display:flex; align-items:baseline; gap:8px; flex:1; cursor:pointer; }
#bt-schedule-app .day-add-btn {
  margin-left:auto; flex-shrink:0;
  background:rgba(255,255,255,.1); border:1.5px solid rgba(255,255,255,.2);
  color:rgba(255,255,255,.6); font-size:18px; font-weight:300; line-height:1;
  width:28px; height:28px; border-radius:6px; cursor:pointer;
  display:flex; align-items:center; justify-content:center;
  transition:all .15s;
}
#bt-schedule-app .day-add-btn:hover { background:var(--pink); border-color:var(--pink); color:#fff; transform:scale(1.1); }
#bt-schedule-app .day-col.today .day-add-btn { border-color:rgba(233,30,140,.4); }
#bt-schedule-app .day-col-hint { margin-left:auto; font-family:'Barlow Condensed',sans-serif; font-size:10px; color:rgba(255,255,255,.15); letter-spacing:.06em; }
#bt-schedule-app .day-col-hint-reopen { color:rgba(255,120,120,.5); }
#bt-schedule-app .day-add-btn { margin-left:auto; background:rgba(233,30,140,.25); border:none; color:var(--pink); border-radius:4px; width:22px; height:22px; font-size:16px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .15s; flex-shrink:0; }
#bt-schedule-app .day-add-btn:hover { background:var(--pink); color:#fff; }
.bt-close-day-pill { padding:4px 12px; border-radius:20px; border:1.5px solid #e8eaf0; font-family:'Barlow Condensed',sans-serif; font-size:12px; font-weight:600; color:#5a6380; cursor:pointer; transition:all .15s; }
.bt-close-day-pill:hover { border-color:#b71c1c; color:#b71c1c; background:#ffebee; }

#bt-schedule-app .day-col-cards {
  flex:1;
  padding:5px;
  display:flex;
  flex-direction:column;
  gap:7px;
}
#bt-schedule-app .day-col-cards.has-overflow::after {
  content:'▼ more jobs below';
  display:block;
  position:sticky;
  bottom:0;
  text-align:center;
  font-family:'Barlow Condensed',sans-serif;
  font-size:11px;
  font-weight:700;
  letter-spacing:.08em;
  text-transform:uppercase;
  color:var(--pink);
  background:linear-gradient(to bottom, transparent, var(--gray-100) 60%);
  padding:16px 0 4px;
  margin-top:-8px;
  pointer-events:none;
}

#bt-schedule-app .day-empty {
  color:var(--gray-400);
  font-size:12px;
  font-style:italic;
  padding:10px 4px;
  text-align:center;
}

/* ── JOB CARD ── */
#bt-schedule-app .job-card {
  background:var(--white);
  border-radius:6px;
  width:100%;
  box-shadow:0 1px 4px rgba(0,0,0,.08);
  overflow:hidden;
  transition:all .15s;
  cursor:pointer;
  border:1.5px solid var(--gray-200);
}
#bt-schedule-app .job-card:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.13); border-color:transparent; }
#bt-schedule-app .card-dept-bar { width:100%; padding:3px 10px; font-family:'Barlow Condensed',sans-serif; font-size:15px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:#fff; line-height:1; }
#bt-schedule-app .card-header { padding:6px 10px 3px; display:flex; align-items:flex-start; justify-content:space-between; gap:8px; }
#bt-schedule-app .card-title-block { flex:1; min-width:0; }
#bt-schedule-app .card-title-line { font-family:'Oswald',sans-serif; font-size:16px; font-weight:700; color:var(--navy-dark); line-height:1.4; white-space:normal; word-break:break-word; }
#bt-schedule-app .card-order-inline { color:var(--navy-dark); font-weight:700; font-size:18px; }
#bt-schedule-app .card-customer-inline { color:var(--gray-600); font-weight:700; font-family:'Barlow',sans-serif; font-size:16px; margin-left:8px; }
#bt-schedule-app .card-status-badge { flex-shrink:0; padding:5px 6px; border-radius:4px; font-family:'Barlow Condensed',sans-serif; font-size:11px; font-weight:700; letter-spacing:.02em; text-transform:uppercase; white-space:normal; text-align:center; width:90px; line-height:1.25; }
#bt-schedule-app .card-body { padding:2px 10px 4px; display:flex; flex-direction:column; gap:5px; }
#bt-schedule-app .card-details { display:flex; gap:0; align-items:stretch; }
#bt-schedule-app .card-detail { display:flex; flex-direction:column; gap:2px; padding:0 12px; border-left:1px solid #d0d4e8; }
#bt-schedule-app .card-detail:first-child { padding-left:0; border-left:none; }
#bt-schedule-app .card-detail-label { font-family:'Barlow Condensed',sans-serif; font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--gray-400); }
#bt-schedule-app .card-detail-value { font-family:'Barlow Condensed',sans-serif; font-size:18px; font-weight:600; color:var(--navy-dark); line-height:1; }
#bt-schedule-app .card-footer { padding:3px 10px 5px; display:flex; align-items:center; justify-content:space-between; border-top:1px solid var(--gray-100); gap:4px; }
#bt-schedule-app .card-notes { font-size:13px; color:var(--gray-600); font-style:italic; flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
#bt-schedule-app .card-art-link { display:flex; align-items:center; gap:3px; background:var(--navy-dark); color:var(--white); font-family:'Barlow Condensed',sans-serif; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; padding:4px 8px; border-radius:3px; text-decoration:none; flex-shrink:0; transition:background .15s; }
#bt-schedule-app .card-art-link:hover { background:var(--pink); }
#bt-schedule-app .card-woo-btn { display:flex; align-items:center; gap:3px; background:#2E7D32; color:#fff; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; padding:4px 9px; border:none; border-radius:3px; cursor:pointer; flex-shrink:0; transition:background .15s,transform .1s; }
#bt-schedule-app .card-woo-btn:hover { background:#1B5E20; }
#bt-schedule-app .card-woo-btn:active { transform:scale(.96); }
#bt-schedule-app .card-woo-btn[disabled] { opacity:.5; cursor:wait; }
#bt-schedule-app .card-woo-btn.is-done { background:transparent; color:#2E7D32; border:1px solid rgba(46,125,50,.45); cursor:default; }
#bt-schedule-app .card-woo-btn.is-done:hover { background:transparent; }

/* Art path live preview (job modal) */
.bt-art-preview { display:none; margin-top:6px; padding:6px 9px; border-radius:4px; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:600; letter-spacing:.02em; line-height:1.35; }
.bt-art-preview.is-ok, .bt-art-preview.is-warn, .bt-art-preview.is-bad { display:block; }
.bt-art-preview code { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; font-weight:700; word-break:break-all; }
.bt-art-preview .bt-art-preview-line { display:block; }
.bt-art-preview .bt-art-preview-note { display:block; margin-top:3px; font-weight:600; opacity:.85; }
.bt-art-preview.is-ok   { background:rgba(46,125,50,.10); color:#1B5E20; }
.bt-art-preview.is-warn { background:rgba(245,124,0,.12); color:#8a4b00; }
.bt-art-preview.is-bad  { background:rgba(244,67,54,.10); color:#b71c1c; }
#bt-schedule-app .card-creator-bar { padding:4px 10px 6px; text-align:center; font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--gray-600); border-top:1px solid var(--gray-100); }

/* dept colors */
#bt-schedule-app .dept-digi .card-dept-bar { background:var(--dept-digi); }
#bt-schedule-app .dept-digi { border-left:3px solid var(--dept-digi) !important; }
#bt-schedule-app .dept-emb .card-dept-bar { background:var(--dept-emb); color:#4a3500; }
#bt-schedule-app .dept-emb { border-left:3px solid var(--dept-emb) !important; }
#bt-schedule-app .dept-stores .card-dept-bar { background:var(--dept-stores); }
#bt-schedule-app .dept-stores { border-left:3px solid var(--dept-stores) !important; }
#bt-schedule-app .dept-custom .card-dept-bar { background:var(--dept-custom); }
#bt-schedule-app .dept-custom { border-left:3px solid var(--dept-custom) !important; }
#bt-schedule-app .dept-transfers .card-dept-bar { background:var(--dept-transfers); }
#bt-schedule-app .dept-transfers { border-left:3px solid var(--dept-transfers) !important; }
#bt-schedule-app .dept-out .card-dept-bar { background:var(--dept-out); }
#bt-schedule-app .dept-out { border-left:3px solid var(--dept-out) !important; }

/* status colors */
#bt-schedule-app .status-none     { background:#f0f0f4; color:#999; border:1px solid #ddd; }
#bt-schedule-app .status-pending  { background:#F57C00; color:#fff; }
#bt-schedule-app .status-approved { background:#4a5568; color:#fff; }
#bt-schedule-app .status-ready    { background:#2E7D32; color:#fff; }
#bt-schedule-app .status-complete { background:#b0bec5; color:#263238; }
#bt-schedule-app .status-hold     { background:#f44336; color:#fff; }
#bt-schedule-app .job-card.is-completed { opacity:.35; }

/* ── CAUTION CARD ── */
#bt-schedule-app .job-card.is-caution { border:2px solid #f44336 !important; box-shadow:0 0 0 1px #f44336 !important; }
#bt-schedule-app .job-card.is-caution .card-dept-bar { position:relative; }

/* ── DRAG HANDLE ── */
#bt-schedule-app .drag-handle { cursor:grab; padding:0 6px 0 2px; color:rgba(0,0,0,.2); font-size:14px; line-height:1; display:flex; align-items:center; flex-shrink:0; user-select:none; }
#bt-schedule-app .drag-handle:active { cursor:grabbing; }
#bt-schedule-app .job-card.dragging { opacity:.4; border:2px dashed #9ca3b8 !important; }
#bt-schedule-app .day-col-cards.drag-over { background:rgba(233,30,140,.05); }

/* ── OVERDUE BAR ── */
#btOverdueBar { display:none; background:#b71c1c; color:#fff; font-family:'Barlow',sans-serif; font-size:13px; font-weight:600; letter-spacing:.05em; text-transform:uppercase; padding:2px 8px; cursor:pointer; align-items:center; justify-content:center; gap:6px; border-bottom:2px solid rgba(255,255,255); animation:btOverduePulse 1.5s ease-in-out infinite; }
#btOverdueBar.visible { display:flex; }
@keyframes btOverduePulse { 0%,100%{background:#930f19} 50%{background:#ff1526} }
#btOverdueBar:hover { background:#c62828; }
#btOverdueBar .overdue-dismiss { margin-left:auto; font-size:12px; opacity:.7; border:1px solid rgba(255,255,255,.4); padding:2px 8px; border-radius:3px; }

#bt-schedule-app .job-card.is-completed .card-order,
#bt-schedule-app .job-card.is-completed .card-customer { text-decoration:line-through; color:#9ca3b8 !important; }
#bt-schedule-app .job-card.is-completed .card-dept-bar { filter:saturate(0.3); }

#bt-schedule-app .today-badge { display:inline-block; background:var(--pink); color:var(--white); font-family:'Barlow Condensed',sans-serif; font-size:9px; font-weight:700; letter-spacing:.1em; padding:2px 6px; border-radius:3px; margin-left:6px; text-transform:uppercase; }

/* ── TAB CONTENT ── */
#bt-schedule-app .tab-content { display:none; }
#bt-schedule-app .tab-content.active { display:block; }

/* ── STORES TABLE ── */
#bt-schedule-app .stores-panel { padding:16px 24px 24px 16px; }
#bt-schedule-app .stores-table { width:100%; border-collapse:collapse; font-family:'Barlow',sans-serif; font-size:16px; table-layout:fixed; }
#bt-schedule-app .stores-table th { font-family:'Barlow Condensed',sans-serif; font-size:13px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--gray-400); padding:8px 10px; text-align:left; background:var(--gray-100); border-bottom:2px solid var(--gray-200); overflow:hidden; white-space:nowrap; }
#bt-schedule-app .stores-table th:nth-child(1) { width:20%; }
#bt-schedule-app .stores-table th:nth-child(2) { width:7%; }
#bt-schedule-app .stores-table th:nth-child(3) { width:7%; }
#bt-schedule-app .stores-table th:nth-child(4) { width:7%; }
#bt-schedule-app .stores-table th:nth-child(5) { width:11%; }
#bt-schedule-app .stores-table th:nth-child(6) { width:11%; }
#bt-schedule-app .stores-table th:nth-child(7) { width:27%; }
#bt-schedule-app .stores-table th:nth-child(8) { width:7%; }
#bt-schedule-app .stores-table td { padding:10px 10px; border-bottom:1px solid var(--gray-200); color:var(--navy-dark); vertical-align:middle; overflow:hidden; }
#bt-schedule-app .stores-table tr:hover td { background:var(--gray-100); }
#bt-schedule-app .stores-table .stores-cat-hdr:hover td { background:var(--navy-dark) !important; }
#bt-schedule-app .store-status-badge { padding:4px 12px; border-radius:12px; font-family:'Barlow Condensed',sans-serif; font-size:14px; font-weight:700; letter-spacing:.06em; }
#bt-schedule-app .store-active   { background:#e8f5e9; color:#1a1f5e; }
#bt-schedule-app .store-closing  { background:#fff3e0; color:#1a1f5e; }
#bt-schedule-app .store-closed   { background:#f5f5f5; color:#5a6380; }
#bt-schedule-app .store-upcoming { background:#e3f2fd; color:#1a1f5e; }
#bt-schedule-app .store-link { color:var(--navy); font-size:12px; text-decoration:none; padding:3px 10px; border:1.5px solid var(--navy); border-radius:4px; font-family:'Barlow Condensed',sans-serif; font-weight:700; letter-spacing:.05em; transition:all .15s; }
#bt-schedule-app .store-link:hover { background:var(--navy); color:var(--white); }
#bt-schedule-app .store-link-cell { display:flex; align-items:center; gap:6px; }
#bt-schedule-app .store-copy-btn { display:inline-flex; align-items:center; justify-content:center; width:26px; height:24px; padding:0; background:none; border:1.5px solid var(--gray-200); border-radius:4px; color:var(--gray-400); cursor:pointer; flex-shrink:0; transition:all .15s; }
#bt-schedule-app .store-copy-btn:hover { border-color:var(--navy); color:var(--navy); background:#f4f6fb; }
#bt-schedule-app .store-copy-btn.copied { border-color:#2e7d32; color:#2e7d32; background:#e8f5e9; }

/* ── STORES CATEGORY HEADERS ── */
#bt-schedule-app .stores-cat-hdr { background:var(--navy-dark); cursor:pointer; user-select:none; }
#bt-schedule-app .stores-cat-hdr td { padding:7px 10px; border-bottom:2px solid var(--navy); border-top:3px solid var(--gray-200); }
#bt-schedule-app .stores-cat-hdr:first-child td { border-top:none; }
#bt-schedule-app .stores-cat-label { font-family:'Oswald',sans-serif; font-size:13px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#fff; display:inline-flex; align-items:center; gap:8px; }
#bt-schedule-app .stores-cat-chevron { font-size:10px; color:rgba(255,255,255,.5); transition:transform .2s; display:inline-block; }
#bt-schedule-app .stores-cat-chevron.collapsed { transform:rotate(-90deg); }
#bt-schedule-app .stores-cat-count { font-family:'Barlow Condensed',sans-serif; font-size:11px; font-weight:600; color:rgba(255,255,255,.4); letter-spacing:.06em; }
#bt-schedule-app .stores-cat-tools { float:right; display:flex; align-items:center; gap:4px; }
#bt-schedule-app .stores-cat-tool-btn { background:rgba(255,255,255,.08); border:none; color:rgba(255,255,255,.5); cursor:pointer; font-size:11px; padding:2px 8px; border-radius:4px; font-family:'Barlow Condensed',sans-serif; font-weight:700; letter-spacing:.06em; text-transform:uppercase; transition:all .15s; }
#bt-schedule-app .stores-cat-tool-btn:hover { background:rgba(255,255,255,.18); color:#fff; }
#bt-schedule-app .stores-cat-tool-btn.del:hover { background:#b71c1c; color:#fff; }
#bt-schedule-app .stores-cat-input { background:transparent; border:none; border-bottom:1.5px solid rgba(255,255,255,.4); color:#fff; font-family:'Oswald',sans-serif; font-size:13px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; outline:none; width:220px; padding:1px 4px; }
#bt-schedule-app .stores-cat-input:focus { border-bottom-color:var(--pink); }
/* ── STORES DRAG HANDLE ── */
#bt-schedule-app .store-drag-handle { color:var(--gray-300,#d1d5e0); cursor:grab; font-size:12px; padding:0 5px 0 0; user-select:none; vertical-align:middle; display:inline; }
#bt-schedule-app .store-drag-handle:hover { color:var(--gray-400); }
#bt-schedule-app tr.store-dragging td { opacity:.35; background:#f0f1fa !important; }
#bt-schedule-app tr.store-drag-over-top td { border-top:2.5px solid var(--pink) !important; }
#bt-schedule-app tr.store-drag-over-bottom td { border-bottom:2.5px solid var(--pink) !important; }
#bt-schedule-app .stores-cat-drag-over td { border-bottom:3px solid var(--pink) !important; }
/* ── STORES PANEL HEADER ── */
#bt-schedule-app .stores-panel-hdr { display:flex; align-items:center; justify-content:flex-end; margin-bottom:14px; gap:8px; }
/* ── ADD CATEGORY BUTTON — small/secondary ── */
#bt-schedule-app .btn-add-cat {
  background:transparent;
  color:var(--gray-400);
  border:1px solid var(--gray-200);
  padding:4px 10px;
  border-radius:4px;
  font-family:'Barlow Condensed',sans-serif;
  font-size:11px;
  font-weight:700;
  letter-spacing:.07em;
  text-transform:uppercase;
  cursor:pointer;
  transition:all .15s;
}
#bt-schedule-app .btn-add-cat:hover { background:var(--navy); color:#fff; border-color:var(--navy); }

/* ── CONTEXT MENU ── */
#btContextMenu { position:fixed; background:#fff; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.2); border:1.5px solid #e8eaf0; z-index:999999; min-width:220px; overflow:hidden; display:none; font-family:'Barlow',sans-serif; }
#btContextMenu.open { display:block; }
#btContextMenu .context-menu-header { padding:8px 14px; font-family:'Barlow Condensed',sans-serif; font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#9ca3b8; background:#f4f5f9; border-bottom:1px solid #e8eaf0; }
#btContextMenu .context-item { padding:9px 14px; cursor:pointer; font-size:13px; font-weight:500; display:flex; align-items:center; gap:8px; transition:background .1s; }
#btContextMenu .context-item:hover { background:#f4f5f9; }
#btContextMenu .context-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
#btContextMenu .edit-item { border-top:1px solid #e8eaf0; color:#0f1240; }
#btContextMenu .edit-item:hover { background:#0f1240; color:#fff; }

/* ── MODALS ── */
.btp-modal-overlay { display:none; position:fixed; inset:0; background:rgba(10,12,40,.75); z-index:999999; align-items:flex-start; justify-content:center; padding:16px; overflow-y:auto; }
.btp-modal-overlay.open { display:flex; }
.bt-modal-wrap { background:#fff; border-radius:12px; width:100%; max-width:720px; margin:auto; box-shadow:0 20px 60px rgba(0,0,0,.4); font-family:'Barlow',sans-serif; animation:btModalIn .2s ease; }
@keyframes btModalIn { from{opacity:0;transform:translateY(12px) scale(.98)} to{opacity:1;transform:translateY(0) scale(1)} }
.bt-modal-header { background:#0f1240; padding:14px 20px; display:flex; align-items:center; justify-content:space-between; border-radius:12px 12px 0 0; }
.bt-modal-title { font-family:'Oswald',sans-serif; font-size:18px; font-weight:700; color:#fff; letter-spacing:.06em; text-transform:uppercase; }
.bt-modal-title span { color:#e91e8c; }
.btp-modal-close { background:none; border:none; color:#9ca3b8; font-size:22px; cursor:pointer; padding:4px; line-height:1; }
.btp-modal-close:hover { color:#fff; }
.btp-modal-body { padding:16px 20px; display:flex; flex-direction:column; gap:11px; }
.bt-form-row { display:grid; grid-template-columns:1fr 1fr; gap:11px; }
.bt-form-group { display:flex; flex-direction:column; gap:4px; }
.bt-form-group label { font-family:'Barlow Condensed',sans-serif; font-size:11px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#5a6380; }
.bt-form-group input,.bt-form-group select,.bt-form-group textarea { border:1.5px solid #e8eaf0; border-radius:5px; padding:8px 10px; font-family:'Barlow',sans-serif; font-size:14px; color:#0f1240; background:#fff; outline:none; width:100%; box-sizing:border-box; }
.bt-form-group input:focus,.bt-form-group select:focus,.bt-form-group textarea:focus { border-color:#1a1f5e; }
.bt-form-group textarea { resize:vertical; min-height:55px; }
.bt-dept-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; }
.bt-status-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; }
.bt-select-option { padding:7px 8px; border-radius:5px; border:2px solid #e8eaf0; cursor:pointer; text-align:center; font-family:'Barlow Condensed',sans-serif; font-size:12px; font-weight:700; letter-spacing:.04em; color:#5a6380; user-select:none; transition:all .15s; line-height:1.2; }
.bt-select-option:hover { border-color:#9ca3b8; background:#f4f5f9; }
/* Selected states — uniform navy blue for consistency */
.bt-select-option.selected { background:#1a1f5e !important; color:#fff !important; border-color:#1a1f5e !important; }
.bt-select-option[data-val="Embroidery"].selected { background:#1a1f5e !important; color:#fff !important; border-color:#1a1f5e !important; }
.bt-select-option[data-val="None"].selected { background:#6b7280 !important; color:#fff !important; border-color:#6b7280 !important; }
.bt-select-option[data-val="Pending Approval"].selected { background:#1a1f5e !important; color:#fff !important; border-color:#1a1f5e !important; }
.bt-select-option[data-val="On Hold"].selected { background:#1a1f5e !important; color:#fff !important; border-color:#1a1f5e !important; }
.btp-modal-footer { padding:12px 20px 16px; display:flex; gap:10px; justify-content:flex-end; border-top:1px solid #e8eaf0; }
.bt-btn-cancel { background:#f4f5f9; color:#5a6380; border:none; padding:11px 22px; border-radius:6px; font-family:'Oswald',sans-serif; font-size:15px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; cursor:pointer; }
.bt-btn-save { background:#0f1240; color:#fff; border:none; padding:11px 26px; border-radius:6px; font-family:'Oswald',sans-serif; font-size:15px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; cursor:pointer; }
.bt-btn-save:hover { background:#e91e8c; }
.bt-btn-delete { background:#ffebee; color:#b71c1c; border:none; padding:11px 20px; border-radius:6px; font-family:'Oswald',sans-serif; font-size:15px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; cursor:pointer; margin-right:auto; }
.bt-btn-delete:hover { background:#f44336; color:#fff; }

/* ── FULFILLMENT BUTTONS FONT SIZE ── */
#btFulfillmentGrid .bt-select-option { font-size:13px; }
</style>

<div id="bt-schedule-app">

<!-- HEADER -->
<header>
  <div class="bt-header-row bt-header-row-top">
    <div style="display:flex;align-items:center;justify-content:center;gap:8px;padding:0 10px;border-right:1px solid rgba(255,255,255,.08);align-self:stretch;">
      <?php if ( current_user_can('bt_portal_backups') ) : ?>
      <button onclick="btOpenBackupPanel()" title="Backup &amp; Restore" style="background:none;border:none;cursor:pointer;padding:3px;opacity:.2;transition:opacity .15s;flex-shrink:0;display:flex;" onmouseover="this.style.opacity='.8'" onmouseout="this.style.opacity='.2'">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </button>
      <?php endif; ?>
      <button onclick="btDownloadArtFiles()" title="Download Art Link Installer" style="background:none;border:none;cursor:pointer;padding:3px;opacity:.2;transition:opacity .15s;flex-shrink:0;display:flex;" onmouseover="this.style.opacity='.8'" onmouseout="this.style.opacity='.2'">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      </button>
    </div>
    <div class="header-page-title">
      <h1>EMPLOYEE <span>PORTAL</span></h1>
    </div>
    <div class="bt-header-user">
      <button type="button" class="btp-whoami" onclick="btpAcctOpen()" title="Your account">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <?php echo esc_html( btp_actor_name() ); ?>
      </button>
      <a class="btp-signout" href="<?php echo esc_url( wp_logout_url( btp_current_url() ) ); ?>" title="Log out">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        LOG OUT
      </a>
    </div>

    <!-- ACCOUNT PANEL — own id prefix (btpAcct*) so nothing collides with
         BT Quote's markup when it renders inline in the Quote tab. -->
    <div id="btpAcctBg" onclick="btpAcctClose()"></div>
    <div id="btpAcctPanel" role="dialog" aria-label="Your account">
      <div class="btp-acct-head">
        <h3>Your account</h3>
        <button type="button" onclick="btpAcctClose()" aria-label="Close">&times;</button>
      </div>
      <div class="btp-acct-body">
        <div id="btpAcctMsg"></div>

        <div class="btp-acct-me">
          <div class="btp-acct-field">
            <label>Username</label>
            <div class="btp-acct-fixed" id="btpAcctLogin">&mdash;</div>
          </div>
          <div class="btp-acct-field">
            <label>Email</label>
            <div class="btp-acct-fixed" id="btpAcctEmail">&mdash;</div>
          </div>
          <p class="btp-acct-note">Your username and email can only be changed by an admin.</p>
          <button type="button" class="btp-acct-btn" id="btpAcctResetBtn" onclick="btpAcctResetSelf()">
            EMAIL ME A PASSWORD RESET LINK
          </button>
        </div>

        <div id="btpAcctAdmin" style="display:none;">
          <div class="btp-acct-rule"><span>Everyone with portal access</span></div>
          <div id="btpAcctList"></div>
        </div>
      </div>
    </div>

    <div class="bt-tools-center">
      <span class="saving-indicator" id="btSavingIndicator"></span>
      <div class="bt-search-wrap" id="btSearchWrap">
        <button type="button" class="bt-search-toggle" id="btSearchToggle" onclick="btSearchToggle()" title="Search">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </button>
        <div class="bt-search-overlay">
          <input type="text" id="btSearchInput" placeholder="Search order # or customer&hellip;" autocomplete="off" oninput="btSearchInputHandler()" onfocus="btSearchInputHandler()" />
          <button type="button" id="btSearchClear" onclick="btSearchClear()" title="Clear">&times;</button>
          <div class="bt-search-results" id="btSearchResults"></div>
        </div>
      </div>
      <button class="filter-toggle-btn" id="btFilterToggleBtn" onclick="btToggleFilters()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
        Filters
      </button>
      <button class="filter-toggle-btn" id="btCalendarBtn" onclick="btToggleCalendar()" title="Jump to date" style="padding:6px 10px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      </button>
      <div class="week-nav">
        <button onclick="btShiftWeek(-1)">&#8592;</button>
        <span class="week-label" id="btWeekLabel">Loading...</span>
        <button onclick="btShiftWeek(1)">&#8594;</button>
        <button onclick="btGoToToday()" id="btTodayBtn" style="font-family:'Barlow Condensed',sans-serif;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;background:rgba(255,255,255,.1);border:none;color:var(--gray-400);cursor:pointer;padding:3px 10px;border-radius:4px;transition:all .15s;margin-left:2px;">TODAY</button>
      </div>
      <button class="btn-add" id="btAddStoreBtn" onclick="btOpenStoreModal()" style="display:none;">&#43; ADD STORE</button>
    </div>
    <div class="header-actions">
      <div class="header-tabs">
        <div class="tab active" data-tab="schedule" onclick="btSwitchTab('schedule')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:5px;vertical-align:middle;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Schedule</div>
        <div class="tab" data-tab="stores" onclick="btSwitchTab('stores')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:5px;vertical-align:middle;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>Online Stores</div>
        <div class="tab" data-tab="quote" onclick="btSwitchTab('quote')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:5px;vertical-align:middle;"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>Quote</div>
        <div class="tab" data-tab="redirect" onclick="btSwitchTab('redirect')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:5px;vertical-align:middle;"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>Redirect</div>
        <div class="tab tab-more" id="btMoreTab" onclick="btToggleMore(event)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:5px;vertical-align:middle;"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg><span id="btMoreLabel">Other</span>
          <svg class="caret" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
          <div class="tab-menu" id="btMoreMenu">
            <div class="tab-menu-item" data-tab="contacts" onclick="btSwitchTab('contacts')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Contacts</div>
            <div class="tab-menu-item" data-tab="vendors" onclick="btSwitchTab('vendors')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l1-5h16l1 5"/><path d="M4 9v11a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/><path d="M9 21v-7h6v7"/></svg>Vendors</div>
            <div class="tab-menu-item" data-tab="exchanges" onclick="btSwitchTab('exchanges')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>Exchanges</div>
            <div class="tab-menu-item" data-tab="omgscan" onclick="btSwitchTab('omgscan')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M3 5v14"/><path d="M7 5v14"/><path d="M11 5v14"/><path d="M15 5v14"/><path d="M19 5v14"/></svg>OMG Scanner</div>
            <!-- HIDDEN: Chipply Barcoder. Chipply now stamps its own barcodes; kept for
                 continuation pages and older exports. Uncomment to restore.
            <div class="tab-menu-item" data-tab="barcoder" onclick="btSwitchTab('barcoder')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7V5a1 1 0 0 1 1-1h2"/><path d="M17 4h2a1 1 0 0 1 1 1v2"/><path d="M20 17v2a1 1 0 0 1-1 1h-2"/><path d="M7 20H5a1 1 0 0 1-1-1v-2"/><path d="M8 9v6"/><path d="M11 9v6"/><path d="M14 9v6"/><path d="M16.5 9v6"/></svg>Chipply Barcoder</div> -->
            <div class="tab-menu-item" data-tab="chipscan" onclick="btSwitchTab('chipscan')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 12h10"/></svg>Chipply Scanner</div>
          </div>
        </div>
      </div>
    </div>

    <!-- CALENDAR OVERLAY -->
    <div id="btCalendarOverlay">
      <div class="cal-nav">
        <button class="cal-nav-btn" onclick="btCalShift(-1)">&#8592;</button>
        <span class="cal-title" id="btCalTitle"></span>
        <button class="cal-nav-btn" onclick="btCalShift(1)">&#8594;</button>
      </div>
      <div class="cal-grid" id="btCalGrid"></div>
    </div>
    <div class="filter-bar" id="btFilterBar">
      <div class="filter-bar-row">
        <span class="filter-label">Dept:</span>
        <div class="filter-pill active" data-dept="all" onclick="btSetDeptFilter('all')">All</div>
        <div class="filter-pill" data-dept="Digi" onclick="btSetDeptFilter('Digi')">Digi</div>
        <div class="filter-pill" data-dept="Embroidery" onclick="btSetDeptFilter('Embroidery')">Embroidery</div>
        <div class="filter-pill" data-dept="Online Stores" onclick="btSetDeptFilter('Online Stores')">Online Stores</div>
        <div class="filter-pill" data-dept="Custom" onclick="btSetDeptFilter('Custom')">Custom</div>
        <div class="filter-pill" data-dept="Transfers" onclick="btSetDeptFilter('Transfers')">Transfers</div>
        <div class="filter-pill" data-dept="Out of House" onclick="btSetDeptFilter('Out of House')">Out of House</div>
      </div>
      <div class="filter-divider"></div>
      <div class="filter-bar-row">
        <span class="filter-label">Status:</span>
        <div class="status-filter-pill active" data-status="all" onclick="btSetStatusFilter('all')">All</div>
        <div class="status-filter-pill" data-status="None" onclick="btSetStatusFilter('None')">None</div>
        <div class="status-filter-pill" data-status="Pending Approval" onclick="btSetStatusFilter('Pending Approval')">Pending</div>
        <div class="status-filter-pill" data-status="Approved/Items Ordered" onclick="btSetStatusFilter('Approved/Items Ordered')">Approved</div>
        <div class="status-filter-pill" data-status="Ready for Production" onclick="btSetStatusFilter('Ready for Production')">Ready</div>
        <div class="status-filter-pill" data-status="Complete/Notify Customer" onclick="btSetStatusFilter('Complete/Notify Customer')">Complete</div>
        <div class="status-filter-pill" data-status="On Hold" onclick="btSetStatusFilter('On Hold')">On Hold</div>
      </div>
    </div>

    <!-- STORES FILTER DROPDOWN -->
    <div class="filter-bar" id="btStoreFilterBar">
      <div class="filter-bar-row">
        <span class="filter-label">Status:</span>
        <div class="store-filter-pill active" data-store-status="all" onclick="btSetStoreFilter('all')">All</div>
        <div class="store-filter-pill" data-store-status="Upcoming" onclick="btSetStoreFilter('Upcoming')">Upcoming</div>
        <div class="store-filter-pill" data-store-status="Active" onclick="btSetStoreFilter('Active')">Active</div>
        <div class="store-filter-pill" data-store-status="Closing Soon" onclick="btSetStoreFilter('Closing Soon')">Closing Soon</div>
        <div class="store-filter-pill" data-store-status="Closed" onclick="btSetStoreFilter('Closed')">Closed</div>
      </div>
    </div>
  </div>
</header>

<!-- BACKUP PANEL -->
<div id="btBackupPanel" style="display:none;position:fixed;top:0;left:0;bottom:0;width:380px;background:#fff;box-shadow:4px 0 32px rgba(0,0,0,.25);z-index:99999;flex-direction:column;font-family:'Barlow',sans-serif;">
  <div style="background:#0f1240;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
    <span style="font-family:'Oswald',sans-serif;font-size:17px;font-weight:700;color:#fff;letter-spacing:.06em;text-transform:uppercase;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;vertical-align:middle;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      BACKUPS
    </span>
    <button onclick="btCloseBackupPanel()" style="background:none;border:none;color:#9ca3b8;font-size:20px;cursor:pointer;">&#215;</button>
  </div>
  <div style="padding:12px 16px;border-bottom:1px solid #e8eaf0;flex-shrink:0;">
    <button onclick="btCreateManualBackup()" style="width:100%;background:#0f1240;color:#fff;border:none;border-radius:6px;padding:9px;font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;margin-bottom:6px;">+ SAVE BACKUP NOW</button>
    <button onclick="btDownloadCSV()" style="width:100%;background:#1b5e20;color:#fff;border:none;border-radius:6px;padding:9px;font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;">&#8681; DOWNLOAD SCHEDULE CSV</button>
  </div>
  <div id="btBackupList" style="flex:1;overflow-y:auto;padding:8px 0;"></div>
</div>
<div id="btBackupBg" onclick="btCloseBackupPanel()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:99998;"></div>

<!-- SCHEDULE TAB -->
<div id="bt-tab-schedule" class="tab-content active">
  <div style="height:15px;background:var(--white);"></div>
  <div id="btOverdueBar" onclick="btGoToOverdue()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span id="btOverdueText" style="flex:1;text-align:center;">0 jobs from previous weeks need attention</span><span class="overdue-dismiss">VIEW →</span></div>
  <div class="board" id="btBoard">
    <div style="padding:40px;text-align:center;color:#9ca3b8;width:100%;">Loading schedule...</div>
  </div>
</div>

<!-- STORES TAB -->
<div id="bt-tab-stores" class="tab-content">
  <div class="stores-panel">
    <div class="stores-panel-hdr">
      <button onclick="btToggleAllCategories()" style="background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:13px;padding:4px 8px;border-radius:4px;font-family:'Barlow Condensed',sans-serif;font-weight:700;letter-spacing:.06em;text-transform:uppercase;transition:all .15s;display:flex;align-items:center;gap:5px;" onmouseover="this.style.color='#1a1f5e'" onmouseout="this.style.color='var(--gray-400)'">&#8661; COLLAPSE ALL</button>
      <button class="btn-add-cat" onclick="btAddCategory()">&#43; Add Category</button>
    </div>
    <table class="stores-table">
      <thead><tr>
        <th>Store / School / Org</th><th>Open Date</th><th>Close Date</th>
        <th>Status</th><th>Fulfillment</th><th>Contact</th><th>Notes</th><th>Link</th>
      </tr></thead>
      <tbody id="btStoresBody"></tbody>
    </table>
    <div class="stores-cards" id="btStoresCards"></div>
  </div>
</div>

<!-- CONTACTS TAB -->
<div id="bt-tab-vendors" class="tab-content">
  <div style="padding:24px 28px;">
    <div class="btv-top">
      <h2 class="btv-h2">Vendors</h2>
      <input type="search" id="btvSearch" placeholder="Search name, account #, phone, rep&hellip;" oninput="btvRender()">
      <select id="btvCat" onchange="btvRender()"><option value="">All categories</option></select>
      <span id="btvCount"></span>
      <button type="button" class="btv-add" id="btvAddBtn" onclick="btvNew()" style="display:none;">+ ADD VENDOR</button>
    </div>
    <div id="btvMsg"></div>
    <div id="btvList" class="btv-list"><p class="btv-empty">Loading&hellip;</p></div>
  </div>
</div>

<div id="bt-tab-contacts" class="tab-content">
  <div style="padding:24px 28px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
      <h2 style="margin:0;font-family:'Oswald',sans-serif;font-size:1.3em;color:#0f1240;letter-spacing:.04em;text-transform:uppercase;">Contacts</h2>
      <span id="btContactCount" style="font-size:12px;color:#9ca3b8;font-family:'Barlow Condensed',sans-serif;letter-spacing:.05em;"></span>
    </div>
    <div style="overflow-x:auto;border-radius:8px;border:1px solid #e8eaf0;">
      <table id="btContactsTable" style="width:100%;border-collapse:collapse;font-family:'Barlow',sans-serif;font-size:19px;min-width:800px;">
        <thead>
          <tr style="background:#0f1240;color:#fff;">
            <th style="padding:10px 14px;text-align:left;font-family:'Barlow Condensed',sans-serif;font-weight:700;letter-spacing:.07em;text-transform:uppercase;font-size:16px;white-space:nowrap;">Name</th>
            <th style="padding:10px 14px;text-align:left;font-family:'Barlow Condensed',sans-serif;font-weight:700;letter-spacing:.07em;text-transform:uppercase;font-size:16px;white-space:nowrap;">School / Org</th>
            <th style="padding:10px 14px;text-align:left;font-family:'Barlow Condensed',sans-serif;font-weight:700;letter-spacing:.07em;text-transform:uppercase;font-size:16px;white-space:nowrap;">City, State</th>
            <th style="padding:10px 14px;text-align:left;font-family:'Barlow Condensed',sans-serif;font-weight:700;letter-spacing:.07em;text-transform:uppercase;font-size:16px;white-space:nowrap;">Email</th>
            <th style="padding:10px 14px;text-align:left;font-family:'Barlow Condensed',sans-serif;font-weight:700;letter-spacing:.07em;text-transform:uppercase;font-size:16px;white-space:nowrap;">Phone</th>
            <th style="padding:10px 14px;text-align:left;font-family:'Barlow Condensed',sans-serif;font-weight:700;letter-spacing:.07em;text-transform:uppercase;font-size:16px;white-space:nowrap;">Message</th>
            <th style="padding:10px 14px;text-align:left;font-family:'Barlow Condensed',sans-serif;font-weight:700;letter-spacing:.07em;text-transform:uppercase;font-size:16px;white-space:nowrap;">Date</th>
            <th style="padding:10px 14px;"></th>
          </tr>
        </thead>
        <tbody id="btContactsBody">
          <tr><td colspan="8" style="padding:40px;text-align:center;color:#9ca3b8;">Loading...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- EXCHANGES TAB -->
<div id="bt-tab-exchanges" class="tab-content">
  <div style="padding:24px 28px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:12px;">
      <h2 style="margin:0;font-family:'Oswald',sans-serif;font-size:1.3em;color:#0f1240;letter-spacing:.04em;text-transform:uppercase;">Exchanges</h2>
      <div class="ex-filters" id="btExFilters"></div>
      <button onclick="btLoadExchanges()" class="ex-filter" style="border-color:#0f1240;color:#0f1240;">Refresh</button>
    </div>
    <div style="overflow-x:auto;border-radius:8px;border:1px solid #e8eaf0;">
      <table class="ex-table">
        <thead>
          <tr>
            <th style="width:130px;">Order</th>
            <th style="width:135px;">Orig Order #</th>
            <th>Customer</th>
            <th class="ex-g ex-g1" style="width:160px;">School / Team</th>
            <th class="ex-g" style="width:250px;">Product</th>
            <th class="ex-g" style="width:70px;">Size</th>
            <th class="ex-g" style="width:100px;">Color</th>
            <th class="ex-g" style="width:55px;">Qty</th>
            <th class="ex-g ex-g2" style="width:90px;">New Size</th>
            <th style="width:95px;">In</th>
            <th style="width:165px;">Out</th>
            <th style="width:140px;">Status</th>
            <th style="width:180px;">Notes</th>
            <th style="width:70px;"></th>
          </tr>
        </thead>
        <tbody id="btExBody">
          <tr><td colspan="14" style="padding:40px;text-align:center;color:#9ca3b8;">Loading...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>


<!-- QUOTE TAB -->
<div id="bt-tab-quote" class="tab-content" style="width:100%;box-sizing:border-box;">
  <div style="padding:32px 24px;background:#f4f5f9;min-height:calc(100vh - 120px);width:100%;box-sizing:border-box;">
    <div id="btQuoteTool" style="max-width:900px;margin:0 auto;box-sizing:border-box;">
      <div class="bt-quote-warn">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;margin-top:1px;"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <div>
          <strong>Don't send a customer the address of this page.</strong>
          This is the employee portal &mdash; customers can't open it, and the link won't carry the quote.
          Use <strong>Copy Quote Link</strong> below, which makes a
          <a href="<?php echo esc_url( home_url('/quote/') ); ?>" target="_blank" rel="noopener">boomerts.com/quote</a>
          link the customer can actually use.
        </div>
      </div>
      <?php
      if ( shortcode_exists( 'bt_quick_quote' ) && defined( 'BTQ_URL' ) ) {
          $btq_html = do_shortcode( '[bt_quick_quote]' );

          /* BT Quote enqueues its stylesheet and script from inside its own
             shortcode callback. That works on /quote/ but not here — this page
             never printed them, so the tool rendered as bare HTML with no CSS
             and no JS (every price stuck on a dash). Rather than depend on the
             enqueue queue flushing on this template, take delivery over: drop
             the queued copies and emit the exact same files inline, in order.
             quick-quote.js is an IIFE that reads #btQuoteRoot on execution, so
             it must come after the markup. */
          wp_dequeue_style( 'btq-quick-quote' );
          wp_dequeue_script( 'btq-quick-quote' );

          $btq_ver = defined( 'BTQ_VERSION' ) ? BTQ_VERSION : null;
          $btq_cfg = wp_json_encode( array(
              'apiBase'  => home_url( '/wp-json/boomerts/v1' ),
              'defaults' => array( 'qty' => '', 'g' => '', 'loc' => '', 'm' => '', 'et' => '', 'r' => '' ),
              /* The quote tool owns the address bar on /quote/, where that is
                 the point. Here it is one tab of five, so it must not rewrite
                 the portal's URL — and a copied quote link has to point the
                 customer at /quote/, not at the employee portal. */
              'syncUrl'   => false,
              'shareBase' => home_url( '/quote/' ),
          ) );

          echo '<link rel="stylesheet" href="' . esc_url( add_query_arg( 'ver', $btq_ver, BTQ_URL . 'assets/quick-quote.css' ) ) . '">';
          echo $btq_html;
          echo '<script>window.BTQ_QQ = ' . $btq_cfg . ';</script>';
          echo '<script src="' . esc_url( add_query_arg( 'ver', $btq_ver, BTQ_URL . 'assets/quick-quote.js' ) ) . '"></script>';
      } else {
          echo '<div style="padding:40px;text-align:center;color:#9ca3b8;font-family:Barlow,sans-serif;">Quote tool unavailable — the BT Quote plugin is not active.</div>';
      }
      ?>
    </div>
  </div>
</div>
			
<!-- OMG SCANNER TAB -->
<div id="bt-tab-omgscan" class="tab-content" style="width:100%;box-sizing:border-box;">
  <div style="background:#f4f5f9;min-height:calc(100vh - 120px);width:100%;box-sizing:border-box;">
    <?php echo do_shortcode('[bt_omg_scanner]'); ?>
  </div>
</div>

<!-- CHIPPLY SCANNER TAB -->
<div id="bt-tab-chipscan" class="tab-content" style="width:100%;box-sizing:border-box;">
  <div style="background:#f4f5f9;min-height:calc(100vh - 120px);width:100%;box-sizing:border-box;">
    <?php echo do_shortcode('[bt_chipply_scanner]'); ?>
  </div>
</div>

<?php /* HIDDEN: Chipply Barcoder tab. The pane is not rendered on purpose —
   it pulls in pdf.js and pdf-lib, ~900KB, on every portal page load, and that
   cost is not worth paying for a tab that can't be opened. The shortcode, the
   include and assets/barcoder/ are all still in place, so restoring this and
   the two blocks above brings it straight back.

<div id="bt-tab-barcoder" class="tab-content" style="width:100%;box-sizing:border-box;">
  <div style="background:#f4f5f9;min-height:calc(100vh - 120px);width:100%;box-sizing:border-box;">
    <?php echo do_shortcode('[bt_chipply_barcoder]'); ?>
  </div>
</div>

*/ ?>
<!-- REDIRECT TAB -->
<div id="bt-tab-redirect" class="tab-content" style="width:100%;box-sizing:border-box;">
  <div style="background:#f4f5f9;min-height:calc(100vh - 120px);width:100%;box-sizing:border-box;">
    <?php echo do_shortcode('[bt_redirect_tab]'); ?>
  </div>
</div>
			
			

</div><!-- #bt-schedule-app -->

<!-- JOB MODAL -->
<div class="btp-modal-overlay" id="btpJobModalOverlay" onclick="if(event.target===this)btCloseModal()">
  <div class="bt-modal-wrap">
    <div class="bt-modal-header">
      <span class="bt-modal-title" id="btModalTitle">NEW <span>JOB</span></span>
      <button class="btp-modal-close" onclick="btCloseModal()">&#215;</button>
    </div>
    <div class="btp-modal-body">
      <div class="bt-form-row">
        <div class="bt-form-group"><label>Due Date</label><input type="date" id="btFDueDate"></div>
        <div class="bt-form-group"><label>Order #</label><input type="text" id="btFOrderNum" placeholder="e.g. 2658901"></div>
      </div>
      <div class="bt-form-group"><label>Customer / School / Org</label><input type="text" id="btFCustomer" placeholder="e.g. Oswego Panthers Baseball"></div>
      <div class="bt-form-group" style="border-top:2px solid #d0d4e0;padding-top:12px;margin-top:4px;">
        <label style="display:flex;align-items:center;justify-content:space-between;">
          Qty, Locations &amp; Type
          <button type="button" onclick="btAddLineItem()" style="background:#0f1240;color:#fff;border:none;border-radius:4px;padding:2px 8px;font-family:'Barlow Condensed',sans-serif;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;">+ ADD LINE</button>
        </label>
        <div id="btLineItems" style="display:flex;flex-direction:column;gap:6px;"></div>
        <input type="hidden" id="btFGarmentType">
        <input type="text" id="btFGarmentOther" placeholder="Describe garment type..." style="display:none;margin-top:6px;">
      </div>
      <div class="bt-form-group" style="border-top:2px solid #d0d4e0;padding-top:12px;margin-top:4px;">
        <label>Department</label>
        <div class="bt-dept-grid">
          <div class="bt-select-option" data-val="Digi" onclick="btToggleDept('Digi')">Digi</div>
          <div class="bt-select-option" data-val="Embroidery" onclick="btToggleDept('Embroidery')">Embroidery</div>
          <div class="bt-select-option" data-val="Online Stores" onclick="btToggleDept('Online Stores')">Online Stores</div>
          <div class="bt-select-option" data-val="Custom" onclick="btToggleDept('Custom')">Custom</div>
          <div class="bt-select-option" data-val="Transfers" onclick="btToggleDept('Transfers')">Transfers</div>
          <div class="bt-select-option" data-val="Out of House" onclick="btToggleDept('Out of House')">Out of House</div>
        </div>
        <input type="hidden" id="btFDept">
      </div>
      <div class="bt-form-group" style="border-top:2px solid #d0d4e0;padding-top:12px;margin-top:4px;">
        <label>Status</label>
        <div class="bt-status-grid">
          <div class="bt-select-option" data-val="None" onclick="btToggleStatus('None')">— None</div>
          <div class="bt-select-option" data-val="Pending Approval" onclick="btToggleStatus('Pending Approval')">⏳ Pending Approval</div>
          <div class="bt-select-option" data-val="Approved/Items Ordered" onclick="btToggleStatus('Approved/Items Ordered')">✅ Approved / Items Ordered</div>
          <div class="bt-select-option" data-val="Ready for Production" onclick="btToggleStatus('Ready for Production')">⚙️ Ready for Production</div>
          <div class="bt-select-option" data-val="Complete/Notify Customer" onclick="btToggleStatus('Complete/Notify Customer')">🏁 Complete / Notified</div>
          <div class="bt-select-option" data-val="On Hold" onclick="btToggleStatus('On Hold')">🚫 On Hold</div>
        </div>
        <input type="hidden" id="btFStatus">
      </div>
     <div class="bt-form-group" style="border-top:2px solid #d0d4e0;padding-top:12px;margin-top:4px;"><label>Art File Path / Link</label><input type="text" id="btFArtLink" oninput="btArtPreview()" onchange="btArtPreview()" placeholder="Paste from Finder or Explorer — Mac and PC paths both work"><div class="bt-art-preview" id="btFArtPreview"></div></div>
      <div class="bt-form-group"><label>Notes</label><textarea id="btFNotes" placeholder="Special instructions, garment style, color details..."></textarea></div>
      <div class="bt-form-group" style="flex-direction:row;align-items:center;gap:8px;padding:8px 0 0;">
        <input type="checkbox" id="btFCaution" style="width:16px;height:16px;accent-color:#f44336;cursor:pointer;margin:0;">
        <label for="btFCaution" style="font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#f44336;cursor:pointer;margin:0;">⚠ Caution Flag — highlight this job</label>
      </div>
    </div>
    <div class="btp-modal-footer">
      <button class="bt-btn-delete" id="btBtnDelete" onclick="btDeleteJob()" style="display:none">DELETE</button>
      <div style="flex:1;display:flex;align-items:center;justify-content:center;font-family:'Barlow Condensed',sans-serif;font-size:14px;font-weight:700;letter-spacing:.06em;color:#5a6380;text-transform:uppercase;" id="btModalAddedBy"></div>
      <button class="bt-btn-cancel" onclick="btCloseModal()">Cancel</button>
      <button class="bt-btn-save" onclick="btSaveJob()">SAVE JOB</button>
    </div>
  </div>
</div>

<!-- STORE MODAL -->
<div class="btp-modal-overlay" id="btStoreModalOverlay" onclick="if(event.target===this)btCloseStoreModal()">
  <div class="bt-modal-wrap">
    <div class="bt-modal-header">
      <span class="bt-modal-title" id="btStoreModalTitle">NEW <span>STORE</span></span>
      <button class="btp-modal-close" onclick="btCloseStoreModal()">&#215;</button>
    </div>
    <div class="btp-modal-body">
      <div class="bt-form-group"><label>Store / School / Org Name</label><input type="text" id="btSfName" placeholder="e.g. Kaneland Eagles Spring 2025"></div>
      <div class="bt-form-row" style="border-top:2px solid #d0d4e0;padding-top:12px;margin-top:4px;">
        <div class="bt-form-group"><label>Open Date</label><input type="date" id="btSfOpen"></div>
        <div class="bt-form-group">
          <label>Close Date</label>
          <input type="date" id="btSfClose">
          <label style="flex-direction:row;align-items:center;gap:6px;margin-top:5px;font-size:11px;color:#9ca3b8;cursor:pointer;text-transform:none;letter-spacing:0;display:flex;">
            <input type="checkbox" id="btSfNoCloseDate" onchange="btToggleNoCloseDate(this)" style="width:auto;padding:0;border:none;margin:0;">
            No close date
          </label>
        </div>
      </div>
      <div class="bt-form-group" style="border-top:2px solid #d0d4e0;padding-top:12px;margin-top:4px;">
        <label>Fulfillment Method</label>
        <div id="btFulfillmentGrid" style="display:grid;grid-template-columns:repeat(5,1fr);gap:6px;">
          <div class="bt-select-option" style="font-size:14.5px;" data-val="Individual- USPS/Pick-Up" onclick="btSelectFulfillment('Individual- USPS/Pick-Up')">📬 Individual-<br>USPS/Pick-Up</div>
          <div class="bt-select-option" style="font-size:14.5px;" data-val="Individual- Pickup Only" onclick="btSelectFulfillment('Individual- Pickup Only')">🏫 Individual-<br>Pickup Only</div>
          <div class="bt-select-option" style="font-size:14.5px;" data-val="Individual- Bulk Deliver" onclick="btSelectFulfillment('Individual- Bulk Deliver')">📦 Individual-<br>Bulk Deliver</div>
          <div class="bt-select-option" style="font-size:14.5px;" data-val="Group- Bulk Deliver" onclick="btSelectFulfillment('Group- Bulk Deliver')">🚚 Group-<br>Bulk Deliver</div>
          <div class="bt-select-option" style="font-size:14.5px;" data-val="Group- Bulk Pickup" onclick="btSelectFulfillment('Group- Bulk Pickup')">🏬 Group-<br>Bulk Pickup</div>
        </div>
        <input type="hidden" id="btSfFulfillment">
      </div>
      <div id="btSfDeliverySection" style="border-top:2px solid #d0d4e0;padding-top:12px;margin-top:4px;opacity:.35;pointer-events:none;transition:opacity .2s;">
        <div class="bt-form-group">
          <label style="display:flex;align-items:center;justify-content:space-between;">
            Delivery Date(s)
            <button type="button" onclick="btAddDeliveryDate()" style="background:#0f1240;color:#fff;border:none;border-radius:4px;padding:2px 8px;font-family:'Barlow Condensed',sans-serif;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;">+ ADD DATE</button>
          </label>
          <div id="btSfDeliveryDates" style="display:flex;flex-direction:column;gap:6px;"></div>
        </div>
      </div>
      <div class="bt-form-group" style="border-top:2px solid #d0d4e0;padding-top:12px;margin-top:4px;"><label>Status</label>
        <select id="btSfStatus">
          <option value="Upcoming">Upcoming</option>
          <option value="Active">Active</option>
          <option value="Closing Soon">Closing Soon</option>
          <option value="Closed">Closed</option>
        </select>
      </div>
      <div class="bt-form-group" style="border-top:2px solid #d0d4e0;padding-top:12px;margin-top:4px;"><label>Store URL / Link</label><input type="text" id="btSfLink" placeholder="https://..."></div>
      <div class="bt-form-row">
        <div class="bt-form-group"><label>Contact Name</label><input type="text" id="btSfContactName" placeholder="e.g. Jane Smith"></div>
        <div class="bt-form-group"><label>Contact Email</label><input type="email" id="btSfContactEmail" placeholder="e.g. jane@school.org"></div>
      </div>
      <div class="bt-form-group" style="border-top:2px solid #d0d4e0;padding-top:12px;margin-top:4px;"><label>Notes</label><textarea id="btSfNotes" placeholder="Linked orders, special instructions..."></textarea></div>
      <div class="bt-form-group" style="border-top:2px solid #d0d4e0;padding-top:12px;margin-top:4px;"><label>Category</label>
        <select id="btSfCategory"><option value="">— Uncategorized —</option></select>
      </div>
    </div>
    <div class="btp-modal-footer">
      <button class="bt-btn-delete" id="btBtnStoreDelete" onclick="btDeleteStore()" style="display:none">DELETE</button>
      <button class="bt-btn-cancel" onclick="btCloseStoreModal()">Cancel</button>
      <button class="bt-btn-save" onclick="btSaveStore()">SAVE STORE</button>
    </div>
  </div>
</div>

<!-- CONTEXT MENU -->
<div id="btContextMenu">
  <div class="context-menu-header">Set Status</div>
  <div class="context-item" onclick="btQuickStatus('None')"><span class="context-dot" style="background:#ccc"></span>None</div>
  <div class="context-item" onclick="btQuickStatus('Pending Approval')"><span class="context-dot" style="background:#F57C00"></span>Pending Approval</div>
  <div class="context-item" onclick="btQuickStatus('Approved/Items Ordered')"><span class="context-dot" style="background:#4a5568"></span>Approved / Items Ordered</div>
  <div class="context-item" onclick="btQuickStatus('Ready for Production')"><span class="context-dot" style="background:#2E7D32"></span>Ready for Production</div>
  <div class="context-item" onclick="btQuickStatus('Complete/Notify Customer')"><span class="context-dot" style="background:#b0bec5"></span>Complete / Notify Customer</div>
  <div class="context-item" onclick="btQuickStatus('On Hold')"><span class="context-dot" style="background:#f44336"></span>On Hold</div>
  <div class="context-item edit-item" onclick="btEditFromContext()">✏️ Edit Full Details</div>
</div>

<script>
const btAPI   = '<?php echo esc_js( $api_base ); ?>';
const btNonce = '<?php echo wp_create_nonce("wp_rest"); ?>';

/* ── URL routing ──
   Each tab gets its own address: /employees/exchanges. On a site without
   pretty permalinks, or before the rewrite rules have been flushed, this
   falls back to ?tab=exchanges so a shared link still lands on the right
   tab — it just looks worse. */
const BT_ROUTE = <?php echo $btp_routing; ?>;

function btTabUrl(tab) {
  const slug = BT_ROUTE.slugs[tab];
  if (!slug) return BT_ROUTE.base;
  if (!BT_ROUTE.pretty) return BT_ROUTE.base + '?tab=' + slug;
  // Schedule is the portal's front door; it keeps the bare URL.
  return tab === 'schedule' ? BT_ROUTE.base : BT_ROUTE.base + slug;
}

function btTabFromUrl() {
  const slugs = BT_ROUTE.slugs;
  try {
    const url = new URL(window.location.href);
    const q = url.searchParams.get('tab');
    if (q) {
      for (const t in slugs) if (slugs[t] === q || t === q) return t;
    }
    const base = new URL(BT_ROUTE.base).pathname.replace(/\/+$/, '');
    const here = url.pathname.replace(/\/+$/, '');
    if (here.length > base.length && here.indexOf(base) === 0) {
      const tail = here.slice(base.length + 1);
      for (const t in slugs) if (slugs[t] === tail || t === tail) return t;
    }
  } catch (e) { /* fall through to the default */ }
  return 'schedule';
}

/* Before 0.5.2 the quote tool wrote its selections onto the portal's own URL,
   so employees ended up on /employees/?qty=4&g=supplied&loc=2 and it stuck
   through refreshes and bookmarks. The tool no longer does that; this clears
   what it already left behind, once, on load. */
(function () {
  try {
    const url = new URL(window.location.href);
    const owned = ['qty','g','garment','loc','locations','m','method','et','embtype','emb','r','retail'];
    let dirty = false;
    owned.forEach(k => { if (url.searchParams.has(k)) { url.searchParams.delete(k); dirty = true; } });
    if (dirty) window.history.replaceState(null, '', url.pathname + (url.search || '') + (url.hash || ''));
  } catch (e) { /* older browser — the stale params are cosmetic */ }
})();

let btJobs = [], btStores = [], btStoreCategories = [];
let btCollapsedCats = new Set();
let btUserName = <?php echo wp_json_encode( btp_actor_name() ); ?>;  // signed-in user (btp_legacy_name, else display_name)
let btDayNotes = {}; // dateStr -> note text
let btOverflowCols = new Set(); // columns with jobs below the fold
let btClosedDays = {}; // dateStr -> reason string
let btActiveJob = null, btActiveStore = null, btContextJobId = null;
let btDeptFilter = 'all', btStatusFilter = 'all', btStoreFilter = 'all', btWeekOffset = 0;


/* ══ ART PATHS ═══════════════════════════════════════════════════════════
   The board stores ONE canonical form: a Windows UNC path
   (\\SERVER\SHARE\folder\file.ai), because that is what the btart:// helper
   on the production PCs opens.

   A Mac mounts \\SERVER\SHARE at /Volumes/SHARE, so the share name is already
   sitting in any Mac path — only the server name has to be configured. Change
   BT_ART_HOST if the file server is called something else. Add a line to
   BT_ART_HOST_BY_SHARE only if a particular share lives on a DIFFERENT server. */
const BT_ART_HOST = 'BoomerTs';
const BT_ART_HOST_BY_SHARE = {
  // 'share name in lower case': 'ServerName',
  'btserver':   'BoomerTs',
  'production': 'BT-NAS',
};

function btIsMac() {
  const s = (navigator.userAgent || '') + ' ' + (navigator.platform || '');
  return /Mac|iPhone|iPad|iPod/i.test(s);
}

/* Strip duplicate-mount suffixes: a share that was already mounted comes back
   as "BTServer-1" or "BTServer 2". Same share, different volume name. */
function btArtCleanShare(name) {
  return String(name || '').replace(/[-_ ]\d+$/, '').trim();
}

function btArtHostFor(share) {
  const k = String(share || '').toLowerCase();
  return BT_ART_HOST_BY_SHARE[k] || BT_ART_HOST;
}

/* Understands every form a path arrives in — Finder Copy as Pathname, a drag
   into the box, Connect to Server, Explorer's copy-as-path, a mapped drive, or
   a web link. Returns { kind, unc, ok, message }. */
function btParseArtPath(raw) {
  const out = { kind: 'empty', unc: '', ok: true, message: '' };
  let p = String(raw == null ? '' : raw).trim();
  if (!p) return out;

  // Wrapping quotes — Explorer's "Copy as path" adds them, Finder sometimes does too.
  p = p.replace(/^["'\u201C\u2018\u00AB]+/, '').replace(/["'\u201D\u2019\u00BB]+$/, '').trim();
  if (!p) return out;

  // A real web link stays exactly as it is.
  if (/^https?:\/\//i.test(p)) {
    return { kind: 'url', unc: p, ok: true, message: 'Web link — opens in the browser.' };
  }

  // Dragged files and Finder copies arrive percent-encoded (%20 for spaces).
  if (/%[0-9A-Fa-f]{2}/.test(p)) { try { p = decodeURIComponent(p); } catch (e) {} }

  // file:///Volumes/... and file://localhost/Volumes/...
  p = p.replace(/^file:\/\/(localhost)?/i, '').trim();

  // smb:// cifs:// afp:// — Finder's Connect to Server and sidebar copies.
  // An optional user@ in front of the host gets dropped.
  let m = p.match(/^(?:smb|cifs|afp):\/\/(?:[^@\/]*@)?([^\/]+)\/(.+)$/i);
  if (m) {
    const host = m[1].replace(/\.(local|lan)$/i, '');
    const rest = m[2].replace(/\/+$/, '').replace(/\//g, '\\');
    return { kind: 'share', unc: '\\\\' + host + '\\' + rest, ok: true, message: '' };
  }

  // Already a UNC path (or //server/share typed with forward slashes).
  if (/^\\\\[^\\]/.test(p) || /^\/\/[^\/]/.test(p)) {
    const unc = '\\\\' + p.replace(/^[\\\/]{2}/, '').replace(/\//g, '\\');
    return { kind: 'share', unc: unc.replace(/\\+$/, ''), ok: true, message: '' };
  }

  // Mac mount: /Volumes/SHARE/rest  ->  \\SERVER\SHARE\rest
  m = p.match(/^\/Volumes\/([^\/]+)\/?(.*)$/i);
  if (m) {
    const share = btArtCleanShare(m[1]);
    const host  = btArtHostFor(share);
    const rest  = m[2].replace(/\/+$/, '').replace(/\//g, '\\');
    const unc   = '\\\\' + host + '\\' + share + (rest ? '\\' + rest : '');
    const known = Object.prototype.hasOwnProperty.call(BT_ART_HOST_BY_SHARE, share.toLowerCase());
    return {
      kind: 'share',
      unc: unc,
      ok: true,
      message: known ? '' : 'Assuming the "' + share + '" share is on ' + host + '.'
    };
  }

  // Mapped drive letter: Z:\Art\... — fine, but only on a PC mapped the same way.
  if (/^[A-Za-z]:[\\\/]/.test(p) && !/^[A-Za-z]:[\\\/]Users[\\\/]/i.test(p)) {
    return {
      kind: 'drive',
      unc: p.replace(/\//g, '\\'),
      ok: true,
      message: 'Mapped drive — only opens on a PC with that same drive letter mapped. A \\\\server\\share path is safer.'
    };
  }

  // Somebody's own machine — nobody else can reach these.
  if (/^\/Users\//i.test(p) || /^~\//.test(p) || /^\/(Desktop|Downloads|Documents)\//i.test(p)) {
    return { kind: 'local', unc: p.replace(/\//g, '\\'), ok: false, message: 'This is a folder on your own Mac. Nobody else can open it — put the file on the server, then copy the path from there.' };
  }
  if (/^[A-Za-z]:[\\\/]Users[\\\/]/i.test(p)) {
    return { kind: 'local', unc: p.replace(/\//g, '\\'), ok: false, message: 'This is a folder on your own PC. Nobody else can open it — put the file on the server first.' };
  }
  if (/^\//.test(p)) {
    return { kind: 'local', unc: p.replace(/\//g, '\\'), ok: false, message: 'This looks like a local path, not one on the server. The production PC will not be able to open it.' };
  }

  // Anything else (a bare filename, a note) — store it, but flag it.
  return { kind: 'plain', unc: p.replace(/\//g, '\\'), ok: false, message: 'Not a recognised server path — the ART button may not open anything.' };
}

/* Canonical value that gets saved and rendered. Same name/signature as before. */
function btNormalizeArtPath(p) {
  if (!p) return '';
  const r = btParseArtPath(p);
  return r.unc || '';
}

/* \\Server\Share\a\b.ai  ->  smb://Server/Share/a/b.ai  (Macs opening art) */
function btUncToSmb(unc) {
  if (!unc) return '';
  const body = String(unc).replace(/^\\\\/, '').replace(/\\/g, '/');
  return 'smb://' + body.split('/').map(function(seg){ return encodeURIComponent(seg); }).join('/');
}

/* Live feedback under the Art field in the job modal. */
function btArtPreview() {
  const input = document.getElementById('btFArtLink');
  const box   = document.getElementById('btFArtPreview');
  if (!input || !box) return;
  const r = btParseArtPath(input.value);

  if (r.kind === 'empty') { box.className = 'bt-art-preview'; box.innerHTML = ''; return; }
  if (r.kind === 'url')   { box.className = 'bt-art-preview is-ok'; box.textContent = '🔗 ' + r.message; return; }

  const cls  = !r.ok ? 'is-bad' : (r.message ? 'is-warn' : 'is-ok');
  const icon = !r.ok ? '⚠' : (r.message ? '⚠' : '✓');
  box.className = 'bt-art-preview ' + cls;
  box.innerHTML = '<span class="bt-art-preview-line">' + icon + ' Production PC opens: <code></code></span>'
                + (r.message ? '<span class="bt-art-preview-note"></span>' : '');
  box.querySelector('code').textContent = r.unc;
  if (r.message) box.querySelector('.bt-art-preview-note').textContent = r.message;
}

function btGetLocCount(locStr) {
  if (!locStr) return 0;
  if (locStr.includes(' | ')) {
    const parts = locStr.split(' | ');
    const m = parts[0].match(/^(\d+)\s*@\s*(\d+)$/);
    return m ? parseInt(m[2]) : 1;
  }
  const atMatch = locStr.match(/^\d+\s*@\s*(\d+)$/);
  if (atMatch) return parseInt(atMatch[1]);
  if (/^\d+$/.test(locStr.trim())) return parseInt(locStr.trim());
  return 1;
}

/* ── Toast ──
   Deliberately not an alert(): a copy is a fast, half-attention action and a
   modal that has to be dismissed would just get muscle-memoried away. */
let btToastTimer = null;
function btToast(html, kind) {
  let el = document.getElementById('btToast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'btToast';
    document.body.appendChild(el);
  }
  el.className = 'bt-toast ' + (kind || 'good');
  el.innerHTML = html;
  requestAnimationFrame(() => el.classList.add('show'));
  clearTimeout(btToastTimer);
  btToastTimer = setTimeout(() => el.classList.remove('show'), kind === 'warn' ? 9000 : 4500);
}

/* Copying the portal's own address and sending it to a customer is the easy
   mistake here — the customer gets a login wall and no quote.
   This catches a copy of text selected on the page. It CANNOT see a copy made
   from the browser's address bar; nothing on a page can. The standing notice
   on the Quote tab is what covers that case. */
document.addEventListener('copy', function() {
  let sel = '';
  try { sel = String(window.getSelection()); } catch (e) { return; }
  if (!sel) return;

  let portalPath = '';
  try { portalPath = new URL(BT_ROUTE.base).pathname.replace(/\/+$/, ''); } catch (e) { return; }
  if (!portalPath || portalPath === '') return;
  if (sel.indexOf(portalPath) === -1) return;

  const quoteUrl = new URL(BT_ROUTE.base).origin + '/quote/';
  btToast('<strong>That\'s a link to the employee portal.</strong> Customers can\'t open it and it won\'t carry the quote. ' +
          'Use <strong>Copy Quote Link</strong> on the Quote tab, or send them <a href="' + quoteUrl +
          '" target="_blank" rel="noopener">' + quoteUrl.replace(/^https?:\/\//, '') + '</a>.', 'warn');
});

/* BT Quote's own share button already says "Copied!" — this confirms the
   thing staff actually need to know, which is who the link works for. */
document.addEventListener('click', function(e) {
  const btn = e.target.closest && e.target.closest('#btShareBtn');
  if (!btn) return;
  setTimeout(() => btToast('Copied a <strong>customer</strong> link to boomerts.com/quote &mdash; safe to send.', 'good'), 60);
});

function btSaving(on) {
  const el = document.getElementById('btSavingIndicator');
  if (el) el.textContent = on ? 'Saving...' : '';
}

async function btFetch(path, method, body) {
  const opts = { method:method||'GET', headers:{'Content-Type':'application/json','X-WP-Nonce':btNonce} };
  if (body) opts.body = JSON.stringify(body);
  const r = await fetch(btAPI + path, opts);
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

function btNormalizeJob(j) {
  const location = j.location||'';
  let lineItems = [];
  if (location.includes(' | ')) {
    lineItems = location.split(' | ').map(part => {
      const m = part.match(/^(\d+)\s*@\s*(.*)$/);
      return m ? {qty:m[1], location:m[2].trim()} : {qty:'', location:part.trim()};
    });
  } else {
    const m = location.match(/^(\d+)\s*@\s*(.*)$/);
    if (m) {
      lineItems = [{qty:m[1], location:m[2].trim()}];
    } else {
      lineItems = [{qty:j.qty||'', location}];
    }
  }
  const garmentType = j.garment_type||j.garmentType||'';
  lineItems = lineItems.map(li => ({...li, garment: li.garment || garmentType}));
  return { id:j.id, orderNum:j.order_num||j.orderNum||'', customer:j.customer||'', qty:parseInt(j.qty)||0, location, lineItems, garmentType, createdBy:j.created_by||j.createdBy||'', dept:j.dept||'', status:j.status||'None', dueDate:j.due_date||j.dueDate||'', artLink:j.art_link||j.artLink||'', notes:j.notes||'', caution: j.caution == 1, sortOrder: parseInt(j.sort_order||j.sortOrder)||0, wooOrderId: parseInt(j.woo_order_id)||0, wooCompletedAt: j.woo_completed_at||'', wooCompletedBy: j.woo_completed_by||'' };
}

function btNormalizeStore(s) {
  return { id:s.id, name:s.name||'', openDate:s.open_date||s.openDate||'', closeDate:s.close_date||s.closeDate||'', fulfillment:s.fulfillment||'', status:s.status||'Upcoming', link:s.link||'', contactName:s.contact_name||s.contactName||'', contactEmail:s.contact_email||s.contactEmail||'', notes:s.notes||'', categoryId: s.category_id ? parseInt(s.category_id) : null, sortOrder: parseInt(s.sort_order)||0, deliveryDates: s.delivery_dates||s.deliveryDates||'[]' };
}

async function btLoadJobs() {
  const days = btGetWeekDays();
  const weekStart = days[0].toISOString().split('T')[0];
  const raw = await btFetch('/jobs?week_start=' + weekStart);
  btJobs = raw.map(btNormalizeJob);
}

function btTodayOffset(days) {
  const d = new Date(); d.setDate(d.getDate() + days); return d.toISOString().split('T')[0];
}

function btDeptClass(dept) {
  return {'Digi':'dept-digi','Embroidery':'dept-emb','Online Stores':'dept-stores','Custom':'dept-custom','Transfers':'dept-transfers','Out of House':'dept-out'}[dept]||'';
}

function btStatusClass(status) {
  return {'None':'status-none','Pending Approval':'status-pending','Approved/Items Ordered':'status-approved','Ready for Production':'status-ready','Complete/Notify Customer':'status-complete','On Hold':'status-hold'}[status]||'';}

function btGetWeekDays() {
  const days=[], today=new Date(); today.setHours(0,0,0,0);
  const dow=today.getDay(), mondayOffset=(dow===0)?-6:1-dow;
  const monday=new Date(today); monday.setDate(today.getDate()+mondayOffset+(btWeekOffset*7));
  for(let i=0;i<5;i++){ const d=new Date(monday); d.setDate(monday.getDate()+i); days.push(d); }
  return days;
}

function btRenderBoard() {
  btOverflowCols.clear();
  const board = document.getElementById('btBoard');
  const days = btGetWeekDays();
  const today = new Date(); today.setHours(0,0,0,0);
  const first = days[0], last = days[days.length-1];
  const weekStr = first.toLocaleDateString('en-US',{month:'short',day:'numeric'}) + ' – ' + last.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
  document.getElementById('btWeekLabel').textContent = weekStr;
  const todayBtn = document.getElementById('btTodayBtn');
  if (todayBtn) {
    todayBtn.style.color = btWeekOffset !== 0 ? 'var(--pink)' : 'var(--gray-400)';
    todayBtn.style.background = btWeekOffset !== 0 ? 'rgba(233,30,140,.15)' : 'rgba(255,255,255,.1)';
  }
  const sub = document.getElementById('btWeekSubtitle');
  if (sub) sub.textContent = 'Week of ' + weekStr;

  board.innerHTML = '';

  if (document.getElementById('btCalendarOverlay').classList.contains('open')) {
    btLoadCalJobs().then(btRenderCalendar);
  }

  days.forEach(day => {
    const dateStr = day.toISOString().split('T')[0];
    const isToday = day.getTime() === today.getTime();
    const dayJobs = btJobs.filter(j => {
      if (j.dueDate !== dateStr) return false;
      if (btDeptFilter !== 'all' && j.dept !== btDeptFilter) return false;
      if (btStatusFilter !== 'all' && j.status !== btStatusFilter) return false;
      return true;
    });

    const dayData = btClosedDays[dateStr] || null;
    const capacity = dayData ? (dayData.capacity !== undefined ? dayData.capacity : 100) : 100;
    const closedReason = dayData ? (dayData.reason || '') : '';
    const isClosed = dayData && capacity === 0;
    const isRestricted = dayData && capacity > 0 && capacity < 100;
    const maxJobs = capacity === 75 ? 2 : capacity === 50 ? 4 : capacity === 25 ? 6 : 99;
    const overCapacity = isRestricted && dayJobs.length > maxJobs;

    const activeJobs = dayJobs.filter(j => j.status !== 'Complete/Notify Customer');
    function countPresses(jobs) {
      return jobs.reduce((s, j) => {
        if (j.lineItems && j.lineItems.length > 1) {
          return s + j.lineItems.reduce((ls, li) => {
            return ls + (parseInt(li.qty)||0) * (btGetLocCount(li.location)||1);
          }, 0);
        } else {
          return s + (j.qty || 0) * (btGetLocCount(j.location) || 1);
        }
      }, 0);
    }
    const digiPcs = countPresses(activeJobs.filter(j => j.dept === 'Digi'));
    const embPcs  = countPresses(activeJobs.filter(j => j.dept === 'Embroidery'));

    const completedCount = dayJobs.length - activeJobs.length;
    const jobCountStr = isRestricted
      ? `${activeJobs.length}/${maxJobs}${overCapacity?' ⚠':''}`
      : `${activeJobs.length} job${activeJobs.length!==1?'s':''}${completedCount>0?' (+'+completedCount+')':''}`;
    const totalBadge = dayJobs.length > 0
      ? `<div class="day-dept-badge badge-total">${jobCountStr}</div>`
      : '';
    const digiBadge = digiPcs > 0 ? `<div class="day-dept-badge badge-digi">${digiPcs}</div>` : '';
    const embBadge  = embPcs  > 0 ? `<div class="day-dept-badge badge-emb">${embPcs}</div>`  : '';
    const countBadgesHtml = (totalBadge || digiBadge || embBadge)
      ? `<div class="day-dept-counts">${totalBadge}${digiBadge}${embBadge}</div>` : '';

    const col = document.createElement('div');
    col.className = 'day-col' + (isToday ? ' today' : '') + (isClosed ? ' closed' : '') + (isRestricted ? ' restricted' : '');

    const dayName = day.toLocaleDateString('en-US',{weekday:'long'});
    const dayDateStr = day.toLocaleDateString('en-US',{month:'short',day:'numeric'});

    const capTabColors = {25:'#FFC107', 50:'#FF9800', 75:'#f44336'};
    const capacityTabHtml = isClosed
      ? '<div class="day-closed-tab">CLOSED</div>'
      : isRestricted ? `<div class="day-closed-tab" style="background:${capTabColors[capacity]||'#FF9800'};">${capacity}% CLOSED</div>` : '';

    const hatchHtml = isRestricted
      ? `<div class="day-hatch-block" style="height:${capacity}%;min-height:60px;"><div class="day-hatch-reason">${closedReason}</div></div>`
      : '';

    col.innerHTML = `
      <div class="day-col-header">
        ${isToday ? '<div class="today-tab">TODAY</div>' : ''}
        ${capacityTabHtml}
        <div class="day-col-header-inner">
          <div class="day-col-date-area" onclick="btOpenCapacityPopover('${dateStr}', event)" title="Click to set day capacity">
            <div class="day-col-name">${dayName}</div>
            <div class="day-col-date">${dayDateStr}</div>
            ${!isClosed ? countBadgesHtml : ''}
          </div>
          ${!isClosed ? `<button class="day-add-btn" onclick="btOpenModalForDate('${dateStr}', event)" title="Add job for ${dayDateStr}">+</button>` : ''}
        </div>
        <div class="day-note-area" onclick="btShowDayNote('${dateStr}', event)">
          <div class="day-note-display" id="btNoteDisplay-${dateStr}">${btDayNotes[dateStr]||''}</div>
          <textarea class="day-note-input" id="btNoteInput-${dateStr}" rows="1" placeholder="Add a note..." onblur="btSaveDayNote('${dateStr}')" onkeydown="btDayNoteKey(event,'${dateStr}')">${btDayNotes[dateStr]||''}</textarea>
        </div>
      </div>
      ${isClosed ? `
        <div class="day-closed-body">
          <div class="day-closed-reason">${closedReason||'Closed'}</div>
          ${dayJobs.length > 0 ? `<div class="day-closed-jobs">${dayJobs.length} job${dayJobs.length!==1?'s':''} hidden</div>` : ''}
        </div>
      ` : `
        <div class="day-col-cards" id="btCards-${dateStr}" style="position:relative;">
          ${hatchHtml}
          ${dayJobs.length === 0 ? '<span class="day-empty">—</span>' : ''}
        </div>
      `}`;

    board.appendChild(col);

    if (!isClosed && dayJobs.length > 0) {
      const cc = document.getElementById('btCards-' + dateStr);
      const sorted = [...dayJobs].sort((a,b) => {
        const aC = a.status==='Complete/Notify Customer' ? 1 : 0;
        const bC = b.status==='Complete/Notify Customer' ? 1 : 0;
        if (aC !== bC) return aC - bC;
        return (a.sortOrder||0) - (b.sortOrder||0) || (a.id - b.id);
      });
      sorted.forEach(job => cc.appendChild(btBuildCard(job)));
      btInitDrag(cc);

      const arrow = document.createElement('div');
      arrow.className = 'day-scroll-arrow';
      arrow.innerHTML = '&#8595;';
      arrow.title = 'More jobs below';
      col.appendChild(arrow);

      const checkArrow = () => {
        const lastCard = cc.querySelector('.job-card:last-child');
        if (!lastCard) { arrow.classList.remove('visible'); return; }
        const lastBottom = lastCard.getBoundingClientRect().bottom;
        const overflow = lastBottom > window.innerHeight - 20;
        arrow.classList.toggle('visible', overflow);
        if (overflow) btOverflowCols.add(dateStr);
      };

      setTimeout(checkArrow, 150);
      window.addEventListener('resize', checkArrow, {passive: true});
      arrow.addEventListener('click', () => {
        cc.lastElementChild && cc.lastElementChild.scrollIntoView({behavior:'smooth', block:'end'});
      });
    }
  });
}

function btBuildCard(job) {
  const card = document.createElement('div');
  card.className = `job-card ${btDeptClass(job.dept)} ${job.status==='Complete/Notify Customer'?'is-completed':''} ${job.caution?'is-caution':''}`.trim();
  card.dataset.id = job.id;

 const artPath = job.artLink ? btNormalizeArtPath(job.artLink) : '';
  let artBtn = '';
  if (artPath) {
    if (/^https?:\/\//i.test(artPath)) {
      artBtn = `<a class="card-art-link" href="${artPath}" target="_blank" onclick="event.stopPropagation()">🎨 ART</a>`;
    } else if (btIsMac()) {
      /* Macs have no btart:// helper — hand Finder an smb:// URL instead. */
      artBtn = `<a class="card-art-link" href="${btUncToSmb(artPath)}" title="${artPath}" onclick="event.stopPropagation()">🎨 ART</a>`;
    } else {
      artBtn = `<a class="card-art-link" href="btart://${artPath.replace(/ /g,'%20').replace(/\\/g,'%5C')}" onclick="event.stopPropagation()">🎨 ART</a>`;
    }
  }

  const orderDisplay = job.orderNum ? (/^\d+$/.test(job.orderNum.trim()) ? `#${job.orderNum}` : job.orderNum) : '';

  /* Transfers cards carry a WooCommerce order — production closes it out from here. */
  let wooBtn = '';
  if (job.dept === 'Transfers' && job.orderNum) {
    wooBtn = job.wooCompletedAt
      ? `<span class="card-woo-btn is-done" title="Completed in WooCommerce${job.wooCompletedBy ? ' by ' + job.wooCompletedBy : ''} — customer emailed">\u2713 ORDER DONE</span>`
      : `<button class="card-woo-btn" data-woo-job="${job.id}" onclick="event.stopPropagation(); btWooComplete(${job.id}, this)" title="Complete this order in WooCommerce and email the customer">\u2713 COMPLETE ORDER</button>`;
  }

  let detailsHtml = '';
  if (job.lineItems && job.lineItems.length > 1) {
    detailsHtml = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 0;">'
      + job.lineItems.map((li, i) => {
          const qty = parseInt(li.qty) || 0;
          const loc = btGetLocCount(li.location);
          const garment = li.garment || '';
          const borderLeft = (i % 2 === 1) ? 'border-left:2px solid #e0e3f0;padding-left:8px;' : '';
          const borderTop = (i >= 2) ? 'border-top:2px solid #e0e3f0;padding-top:4px;' : '';
          return `<div class="card-details" style="padding:2px 0;${borderLeft}${borderTop}">`
            + (qty ? `<div class="card-detail" style="padding-left:0;border-left:none;"><span class="card-detail-label">Qty</span><span class="card-detail-value">${qty}<span style="font-size:13px;font-weight:500;color:var(--gray-400);margin-left:2px;">pcs</span></span></div>` : '')
            + (loc ? `<div class="card-detail"><span class="card-detail-label">Loc</span><span class="card-detail-value">${loc}</span></div>` : '')
            + (garment ? `<div class="card-detail"><span class="card-detail-label">Type</span><span class="card-detail-value" style="font-size:14px;">${garment}</span></div>` : '')
            + '</div>';
        }).join('')
      + '</div>';
  } else {
    const qty = job.qty || 0;
    const loc = btGetLocCount(job.location);
    detailsHtml = `<div class="card-details">
      ${qty && job.dept!=='Online Stores' ? `<div class="card-detail"><span class="card-detail-label">Qty</span><span class="card-detail-value">${qty}<span style="font-size:13px;font-weight:500;color:var(--gray-400);margin-left:2px;">pcs</span></span></div>` : ''}
      ${loc ? `<div class="card-detail"><span class="card-detail-label">Loc</span><span class="card-detail-value">${loc}</span></div>` : ''}
      ${job.garmentType ? `<div class="card-detail"><span class="card-detail-label">Type</span><span class="card-detail-value" style="font-size:16px;">${job.garmentType}</span></div>` : ''}
    </div>`;
  }
  const statusDisplay = {
    'Pending Approval':          'Pending<br>Approval',
    'Approved/Items Ordered':    'Approved/<br>Items Ordered',
    'Ready for Production':      'Ready for<br>Production',
    'Complete/Notify Customer':  'Complete/<br>Notified',
    'On Hold':                   'On Hold',
    'None':                      'None'
  }[job.status] || job.status;
  card.innerHTML = `
    <div class="card-dept-bar" style="display:flex;align-items:center;"><span class="drag-handle" title="Drag to reorder">⠿</span>${job.dept}</div>
    <div class="card-header">
      <div class="card-title-block">
        <div class="card-title-line">
          ${orderDisplay ? `<span class="card-order-inline" data-order-number="${job.orderNum}" title="Click to open in Printavo">${orderDisplay}</span> ` : ''}
									   <span class="card-customer-inline">${job.customer}</span>
        </div>
      </div>
      <div class="card-status-badge ${btStatusClass(job.status)}">${statusDisplay}</div>
    </div>
    <div class="card-body">
      ${detailsHtml}
    </div>
    <div class="card-footer">
      ${job.notes ? `<span class="card-notes" title="${job.notes}">📝 ${job.notes}</span>` : '<span class="card-notes" style="opacity:0;">&nbsp;</span>'}
      ${wooBtn}
      ${artBtn}
    </div>`;

  card.addEventListener('click', e => { if(e.target.closest('.card-art-link') || e.target.closest('.card-woo-btn')) return; btOpenModal(job.id); });
  card.addEventListener('contextmenu', e => { e.preventDefault(); btShowContextMenu(e, job.id); });
  return card;
}

async function btLoadAndRenderStores() {
  try {
    const [rawStores, rawCats] = await Promise.all([
      btFetch('/stores'),
      btFetch('/store-categories'),
    ]);
    btStores = rawStores.map(btNormalizeStore);
    btStoreCategories = (rawCats || []).slice().sort((a,b) => (parseInt(a.sort_order)||0)-(parseInt(b.sort_order)||0));
    btRenderStores();
  } catch (e) {
    /* An empty table is indistinguishable from "all your stores are gone",
       which is a horrible thing to show someone. Say it failed instead. */
    const tbody = document.getElementById('btStoresBody');
    if (tbody && !btStores.length) {
      tbody.innerHTML = '<tr><td colspan="8" style="padding:40px;text-align:center;color:#b71c1c;">' +
        'Could not load stores &mdash; nothing has been lost. Refresh to try again.</td></tr>';
    }
    console.error('BT stores load failed:', e);
  }
}

/* ── COPY STORE URL ── */
const BTP_COPY_ICON  = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
const BTP_CHECK_ICON = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';

function btAttr(v) {
  return String(v == null ? '' : v).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function btCopyStoreLink(ev, btn) {
  ev.preventDefault(); ev.stopPropagation();
  const url = btn.dataset.copy || '';
  if (!url) return;

  const flash = () => {
    btn.classList.add('copied');
    btn.innerHTML = BTP_CHECK_ICON;
    btn.title = 'Copied!';
    clearTimeout(btn._btCopyTimer);
    btn._btCopyTimer = setTimeout(() => {
      btn.classList.remove('copied');
      btn.innerHTML = BTP_COPY_ICON;
      btn.title = 'Copy store URL';
    }, 1500);
  };

  const legacy = () => {
    const ta = document.createElement('textarea');
    ta.value = url;
    ta.setAttribute('readonly','');
    ta.style.cssText = 'position:fixed;top:0;left:-9999px;opacity:0;';
    document.body.appendChild(ta);
    ta.select(); ta.setSelectionRange(0, url.length);
    let ok = false;
    try { ok = document.execCommand('copy'); } catch(e) {}
    document.body.removeChild(ta);
    if (ok) flash(); else window.prompt('Copy this URL:', url);
  };

  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(url).then(flash).catch(legacy);
  } else {
    legacy();
  }
}

function btRenderStores() {
  const tbody = document.getElementById('btStoresBody');
  const cards = document.getElementById('btStoresCards');
  tbody.innerHTML = '';
  if (cards) cards.innerHTML = '';

  const pink = 'background:#fce4ec;color:#1a1f5e', orange = 'background:#fff3e0;color:#1a1f5e'; const fColors = {'Individual- USPS/Pick-Up':pink,'Individual- Pickup Only':pink,'Individual- Bulk Deliver':pink,'Group- Bulk Deliver':orange,'Group- Bulk Pickup':orange};
  const fmtDate = (d, fallback='—') => { if (!d || d === '0000-00-00') return fallback; const dt = new Date(d+'T12:00:00'); return isNaN(dt) ? fallback : dt.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); };

  const filtered = btStores
    .filter(s => btStoreFilter === 'all' || s.status === btStoreFilter)
    .slice().sort((a,b) => (a.sortOrder||0)-(b.sortOrder||0) || a.name.localeCompare(b.name));

  const groups = btStoreCategories.map(cat => ({
    id: cat.id,
    name: cat.name,
    stores: filtered.filter(s => s.categoryId == cat.id),
  }));
  const uncategorized = filtered.filter(s => !s.categoryId || !btStoreCategories.find(c => c.id == s.categoryId));
  if (uncategorized.length || btStoreCategories.length === 0) {
    groups.push({ id: null, name: 'Uncategorized', stores: uncategorized });
  }

  groups.forEach(group => {
    const isCollapsed = btCollapsedCats.has(group.id === null ? 'null' : group.id);
    const isUncategorized = group.id === null;

    const hdrTr = document.createElement('tr');
    hdrTr.className = 'stores-cat-hdr';
    hdrTr.dataset.catId = group.id ?? 'null';
    hdrTr.innerHTML = `<td colspan="8">
      <span class="stores-cat-label">
        <span class="stores-cat-chevron${isCollapsed?' collapsed':''}">&#9660;</span>
        ${isUncategorized
          ? `<span style="color:rgba(255,255,255,.45);font-style:italic;">${group.name}</span>`
          : `<span class="stores-cat-name-display">${group.name}</span>
             <input class="stores-cat-input" value="${group.name}" style="display:none;" onblur="btSaveCatName(this,${group.id})" onkeydown="if(event.key==='Enter')this.blur();if(event.key==='Escape'){this.style.display='none';this.previousElementSibling.style.display='';}"`
          + ` onclick="event.stopPropagation()">`
        }
        <span class="stores-cat-count">${group.stores.length} store${group.stores.length!==1?'s':''}</span>
      </span>
      ${!isUncategorized ? `
      <span class="stores-cat-tools" onclick="event.stopPropagation()">
        <button class="stores-cat-tool-btn" onclick="btStartRenameCat(this)">RENAME</button>
        <button class="stores-cat-tool-btn del" onclick="btDeleteCategory(${group.id})">✕</button>
      </span>` : ''}
    </td>`;
    hdrTr.addEventListener('click', function(e) {
      if (e.target.closest('.stores-cat-tools') || e.target.tagName === 'INPUT') return;
      const key = group.id === null ? 'null' : group.id;
      if (btCollapsedCats.has(key)) btCollapsedCats.delete(key);
      else btCollapsedCats.add(key);
      btSaveCollapsedCats();
      btRenderStores();
    });
    if (!isUncategorized) {
      hdrTr.draggable = true;
      hdrTr.addEventListener('dragstart', e => {
        e.dataTransfer.setData('btCatId', group.id);
        e.dataTransfer.effectAllowed = 'move';
        hdrTr.classList.add('store-dragging');
      });
      hdrTr.addEventListener('dragend', () => hdrTr.classList.remove('store-dragging'));
      hdrTr.addEventListener('dragover', e => { e.preventDefault(); e.dataTransfer.dropEffect='move'; hdrTr.classList.add('stores-cat-drag-over'); });
      hdrTr.addEventListener('dragleave', () => hdrTr.classList.remove('stores-cat-drag-over'));
      hdrTr.addEventListener('drop', e => {
        e.preventDefault(); e.stopPropagation();
        hdrTr.classList.remove('stores-cat-drag-over');
        const storeId = e.dataTransfer.getData('btStoreId');
        if (storeId) {
          const fromId = parseInt(storeId);
          const toCatId = group.id;
          const fromIdx = btStores.findIndex(s => s.id == fromId);
          if (fromIdx < 0) return;
          const [moved] = btStores.splice(fromIdx, 1);
          moved.categoryId = toCatId;
          const firstInCat = btStores.findIndex(s => s.categoryId == toCatId);
          if (firstInCat >= 0) btStores.splice(firstInCat, 0, moved);
          else btStores.push(moved);
          const key = toCatId === null ? 'null' : toCatId;
          btCollapsedCats.delete(key);
          btSaveCollapsedCats();
          btRecomputeSortOrders();
          btRenderStores();
          btFetch('/stores/reorder','POST',{items: btStores.map(s => ({id:s.id, sort_order:s.sortOrder, category_id:s.categoryId||null}))}).catch(()=>{});
          return;
        }
        const fromId = parseInt(e.dataTransfer.getData('btCatId'));
        const toId   = group.id;
        if (!fromId || fromId === toId) return;
        const fromIdx = btStoreCategories.findIndex(c => c.id == fromId);
        const toIdx   = btStoreCategories.findIndex(c => c.id == toId);
        if (fromIdx < 0 || toIdx < 0) return;
        const moved = btStoreCategories.splice(fromIdx, 1)[0];
        const newIdx = btStoreCategories.findIndex(c => c.id == toId);
        btStoreCategories.splice(newIdx, 0, moved);
        btStoreCategories.forEach((c,i) => c.sort_order = i+1);
        btRenderStores();
        btFetch('/store-categories/reorder','POST',{items: btStoreCategories.map((c,i) => ({id:c.id,sort_order:i+1}))}).catch(()=>{});
      });
    }
    tbody.appendChild(hdrTr);

    if (isCollapsed) return;

    group.stores.forEach(store => {
      const sCls   = {Active:'store-active','Closing Soon':'store-closing',Closed:'store-closed',Upcoming:'store-upcoming'}[store.status]||'';
      const fStyle = fColors[store.fulfillment]||'';

      const tr = document.createElement('tr');
      tr.dataset.storeId  = store.id;
      tr.dataset.catId    = group.id ?? 'null';
      tr.draggable = true;
      tr.style.cursor = 'pointer';
      tr.innerHTML = `
        <td><span class="store-drag-handle" title="Drag to reorder">&#8942;&#8942;</span><strong>${store.name}</strong></td>
        <td>${fmtDate(store.openDate)}</td><td>${fmtDate(store.closeDate,'N/A')}</td>
        <td><span class="store-status-badge ${sCls}">${store.status}</span></td>
        <td><span class="store-status-badge" style="${fStyle}">${store.fulfillment||'—'}</span></td>
        <td style="font-size:13px;line-height:1.35;">${store.contactName?`<span style="font-weight:600;color:#1a1f5e;display:block;font-size:14px;">${store.contactName}</span>`:''}${store.contactEmail?`<a href="mailto:${store.contactEmail}" onclick="event.stopPropagation()" style="color:#9ca3b8;font-size:12px;text-decoration:none;">${store.contactEmail}</a>`:(!store.contactName?'—':'')}</td>
        <td style="font-size:15px;color:#1a1f5e;line-height:1.4;">${store.notes||''}</td>
        <td>${store.link?`<span class="store-link-cell"><a href="${store.link}" class="store-link" target="_blank" onclick="event.stopPropagation()">VIEW</a><button type="button" class="store-copy-btn" title="Copy store URL" data-copy="${btAttr(store.link)}" onclick="btCopyStoreLink(event,this)">${BTP_COPY_ICON}</button></span>`:''}</td>`;

      tr.addEventListener('click', e => { if(e.target.tagName==='A'||e.target.closest('.store-copy-btn')||e.target.classList.contains('store-drag-handle')) return; btOpenStoreModal(store.id); });

      tr.addEventListener('dragstart', e => {
        e.dataTransfer.setData('btStoreId', store.id);
        e.dataTransfer.setData('btFromCat', group.id ?? 'null');
        e.dataTransfer.effectAllowed = 'move';
        setTimeout(() => tr.classList.add('store-dragging'), 0);
      });
      tr.addEventListener('dragend', () => tr.classList.remove('store-dragging'));
      tr.addEventListener('dragover', e => {
        e.preventDefault(); e.dataTransfer.dropEffect = 'move';
        if (!e.dataTransfer.types.includes('btstoreid')) return;
        document.querySelectorAll('#btStoresBody tr.store-drag-over-top,#btStoresBody tr.store-drag-over-bottom').forEach(r=>r.classList.remove('store-drag-over-top','store-drag-over-bottom'));
        const box = tr.getBoundingClientRect();
        tr.classList.add(e.clientY < box.top + box.height/2 ? 'store-drag-over-top' : 'store-drag-over-bottom');
      });
      tr.addEventListener('dragleave', () => tr.classList.remove('store-drag-over-top','store-drag-over-bottom'));
      tr.addEventListener('drop', e => {
        e.preventDefault(); e.stopPropagation();
        tr.classList.remove('store-drag-over-top','store-drag-over-bottom');
        const fromId  = parseInt(e.dataTransfer.getData('btStoreId'));
        const toCatId = group.id;
        if (fromId === store.id) return;
        const box      = tr.getBoundingClientRect();
        const insertBefore = e.clientY < box.top + box.height/2;
        const fromIdx  = btStores.findIndex(s => s.id == fromId);
        const toIdx    = btStores.findIndex(s => s.id == store.id);
        if (fromIdx < 0 || toIdx < 0) return;
        const [moved] = btStores.splice(fromIdx, 1);
        moved.categoryId = toCatId;
        const newToIdx = btStores.findIndex(s => s.id == store.id);
        btStores.splice(insertBefore ? newToIdx : newToIdx+1, 0, moved);
        btRecomputeSortOrders();
        btRenderStores();
        btFetch('/stores/reorder','POST',{items: btStores.map((s,i) => ({id:s.id, sort_order:s.sortOrder, category_id:s.categoryId||null}))}).catch(()=>{});
      });

      tbody.appendChild(tr);

      if (cards) {
        const card = document.createElement('div');
        card.className = 'store-card';
        card.addEventListener('click', () => btOpenStoreModal(store.id));
        card.innerHTML = `
          <div class="store-card-name">${store.name}</div>
          <div class="store-card-row">
            <span class="store-status-badge ${sCls}">${store.status}</span>
            <span class="store-status-badge" style="${fStyle}">${store.fulfillment||'—'}</span>
          </div>
          <div class="store-card-row">
            <span class="store-card-label">Open</span><span class="store-card-value">${fmtDate(store.openDate)}</span>
            <span class="store-card-label">Close</span><span class="store-card-value">${fmtDate(store.closeDate,'N/A')}</span>
          </div>
          ${store.notes ? `<div class="store-card-notes">${store.notes}</div>` : ''}
          ${store.link ? `<span class="store-link-cell" style="align-self:flex-start"><a href="${store.link}" class="store-link" target="_blank" onclick="event.stopPropagation()">VIEW</a><button type="button" class="store-copy-btn" title="Copy store URL" data-copy="${btAttr(store.link)}" onclick="btCopyStoreLink(event,this)">${BTP_COPY_ICON}</button></span>` : ''}`;
        cards.appendChild(card);
      }
    });
  });
}

function btRecomputeSortOrders() {
  const byCategory = {};
  btStores.forEach(s => {
    const key = s.categoryId ?? 'null';
    if (!byCategory[key]) byCategory[key] = [];
    byCategory[key].push(s);
  });
  Object.values(byCategory).forEach(group => {
    group.forEach((s,i) => s.sortOrder = i+1);
  });
}

function btSaveCollapsedCats() {
  try { localStorage.setItem('btCollapsedCats', JSON.stringify([...btCollapsedCats])); } catch(e) {}
}

function btToggleAllCategories() {
  const allKeys = btStoreCategories.map(c => c.id).concat([null]).map(id => id === null ? 'null' : id);
  const anyExpanded = allKeys.some(k => !btCollapsedCats.has(k));
  if (anyExpanded) {
    allKeys.forEach(k => btCollapsedCats.add(k));
  } else {
    btCollapsedCats.clear();
  }
  btSaveCollapsedCats();
  btRenderStores();
}

async function btAddCategory() {
  const name = prompt('Category name:');
  if (!name || !name.trim()) return;
  try {
    const newCat = await btFetch('/store-categories','POST',{name:name.trim()});
    btStoreCategories.push(newCat);
    btRenderStores();
  } catch(e) { alert('Error adding category.'); }
}

function btStartRenameCat(btn) {
  const td      = btn.closest('td');
  const display = td.querySelector('.stores-cat-name-display');
  const input   = td.querySelector('.stores-cat-input');
  if (!display || !input) return;
  display.style.display = 'none';
  input.style.display   = '';
  input.focus();
  input.select();
}

async function btSaveCatName(input, catId) {
  const display = input.previousElementSibling;
  const newName = input.value.trim();
  input.style.display = 'none';
  if (display) display.style.display = '';
  if (!newName) return;
  const cat = btStoreCategories.find(c => c.id == catId);
  if (!cat || cat.name === newName) return;
  cat.name = newName;
  btRenderStores();
  try { await btFetch('/store-categories/'+catId,'PUT',{name:newName}); }
  catch(e) { alert('Error renaming category.'); }
}

async function btDeleteCategory(catId) {
  const cat    = btStoreCategories.find(c => c.id == catId);
  const count  = btStores.filter(s => s.categoryId == catId).length;
  const msg    = count > 0
    ? `Delete category "${cat?.name}"? The ${count} store(s) in it will become uncategorized.`
    : `Delete category "${cat?.name}"?`;
  if (!confirm(msg)) return;
  try {
    await btFetch('/store-categories/'+catId,'DELETE');
    btStoreCategories = btStoreCategories.filter(c => c.id != catId);
    btStores.forEach(s => { if (s.categoryId == catId) s.categoryId = null; });
    btRenderStores();
  } catch(e) { alert('Error deleting category.'); }
}

/* ── LINE ITEMS ── */
function btAddLineItem(qty, loc, garment) {
  const container = document.getElementById('btLineItems');
  const row = document.createElement('div');
  row.className = 'bt-line-item';
  row.style.cssText = 'display:flex;flex-direction:column;gap:5px;padding:8px;background:#f8f8fb;border-radius:6px;border:1.5px solid #e8eaf0;';

  const itemIdx = container.children.length;
  const gTypes = ['Shirts','Hoodies','Hats','Other'];
  const garmentPills = gTypes.map(g =>
    `<div class="bt-select-option${garment===g?' selected':''}" data-val="${g}" onclick="btToggleLineGarment(this)" style="padding:4px 6px;font-size:11px;">${g}</div>`
  ).join('');

  row.innerHTML = `
    <div style="display:flex;gap:6px;align-items:center;">
      <input type="number" placeholder="Qty" min="1" value="${qty||''}" style="width:80px;flex-shrink:0;border:1.5px solid #e8eaf0;border-radius:5px;padding:6px 8px;font-family:'Barlow',sans-serif;font-size:13px;color:#0f1240;background:#fff;outline:none;box-sizing:border-box;">
      <input type="number" placeholder="Loc #" min="1" value="${loc||''}" style="width:70px;flex-shrink:0;border:1.5px solid #e8eaf0;border-radius:5px;padding:6px 8px;font-family:'Barlow',sans-serif;font-size:13px;color:#0f1240;background:#fff;outline:none;box-sizing:border-box;">
      <div class="bt-garment-grid" style="display:flex;gap:4px;flex:1;flex-wrap:wrap;">${garmentPills}</div>
      <input type="hidden" class="bt-line-garment" value="${garment||''}">
      <button type="button" onclick="this.closest('.bt-line-item').remove()" style="background:#ffebee;color:#b71c1c;border:none;border-radius:4px;width:26px;height:26px;cursor:pointer;font-size:14px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">&#215;</button>
    </div>`;
  container.appendChild(row);
}

function btToggleLineGarment(el) {
  const row = el.closest('.bt-line-item');
  const grid = el.closest('.bt-garment-grid');
  const hidden = row.querySelector('.bt-line-garment');
  const cur = hidden ? hidden.value : '';
  const val = el.dataset.val;
  grid.querySelectorAll('.bt-select-option').forEach(o => o.classList.remove('selected'));
  if (cur === val) {
    if (hidden) hidden.value = '';
  } else {
    el.classList.add('selected');
    if (hidden) hidden.value = val;
  }
}

function btGetLineItems() {
  return Array.from(document.querySelectorAll('#btLineItems .bt-line-item')).map(row => {
    const inputs = row.querySelectorAll('input[type="number"]');
    const garmentInput = row.querySelector('.bt-line-garment');
    return {
      qty:      inputs[0] ? inputs[0].value.trim() : '',
      location: inputs[1] ? inputs[1].value.trim() : '',
      garment:  garmentInput ? garmentInput.value.trim() : ''
    };
  }).filter(r => r.qty || r.location);
}

function btSetLineItems(lineItems) {
  document.getElementById('btLineItems').innerHTML = '';
  if (lineItems && lineItems.length > 0) {
    lineItems.forEach(li => btAddLineItem(li.qty, li.location, li.garment||''));
  } else {
    btAddLineItem();
  }
}

/* ── JOB MODAL ── */
function btOpenModal(jobId, dateStr) {
  btActiveJob = jobId || null;
  document.getElementById('btBtnDelete').style.display = jobId ? 'block' : 'none';
  document.getElementById('btModalTitle').innerHTML = jobId ? 'EDIT <span>JOB</span>' : 'NEW <span>JOB</span>';
  document.querySelectorAll('.bt-dept-grid .bt-select-option').forEach(o => o.classList.remove('selected'));
  document.querySelectorAll('#btpJobModalOverlay .bt-status-grid .bt-select-option').forEach(o => o.classList.remove('selected'));
  if (jobId) {
    const job = btJobs.find(j => j.id == jobId);
    document.getElementById('btFOrderNum').value = job.orderNum;
    document.getElementById('btFDueDate').value  = job.dueDate;
    document.getElementById('btFCustomer').value = job.customer;
    document.getElementById('btFArtLink').value  = job.artLink;
    btArtPreview();
    document.getElementById('btFNotes').value    = job.notes;
    document.getElementById('btFCaution').checked = !!job.caution;
    const gt = job.garmentType||'';
    const presets = ['Shirts','Hoodies','Hats'];
    if (presets.includes(gt)) {
      btSelectGarment(gt);
    } else if (gt) {
      btSelectGarment('Other');
      document.getElementById('btFGarmentOther').value = gt;
    } else {
      document.querySelectorAll('.bt-garment-grid .bt-select-option').forEach(o => o.classList.remove('selected'));
      document.getElementById('btFGarmentType').value = '';
      document.getElementById('btFGarmentOther').style.display = 'none';
    }
    const lineItemsWithGarment = (job.lineItems && job.lineItems.length ? job.lineItems : [{qty:job.qty||'', location:job.location||'', garment:gt}])
      .map(li => ({...li, garment: li.garment || gt}));
    btSetLineItems(lineItemsWithGarment);
    if (lineItemsWithGarment.length <= 1) btSelectGarment(gt);
    btSelectDept(job.dept);
    btSelectStatus(job.status);
  } else {
    document.getElementById('btFOrderNum').value = '';
    document.getElementById('btFDueDate').value  = dateStr || btTodayOffset(0);
    document.getElementById('btFCustomer').value = '';
    document.getElementById('btFArtLink').value  = '';
    btArtPreview();
    document.getElementById('btFNotes').value    = '';
    document.getElementById('btFCaution').checked = false;
    document.querySelectorAll('.bt-garment-grid .bt-select-option').forEach(o => o.classList.remove('selected'));
    document.getElementById('btFGarmentType').value = '';
    document.getElementById('btFGarmentOther').style.display = 'none';
    document.getElementById('btFGarmentOther').value = '';
    btSelectDept('');
    btSetLineItems([]);
    btSelectStatus('None');
  }
  document.getElementById('btpJobModalOverlay').classList.add('open');
  document.querySelector('#btpJobModalOverlay .bt-modal-wrap').scrollTop = 0;
  const addedByEl = document.getElementById('btModalAddedBy');
  if (addedByEl) {
    if (jobId) {
      const job = btJobs.find(j => j.id == jobId);
      addedByEl.textContent = job && job.createdBy ? 'Added by ' + job.createdBy : '';
    } else {
      addedByEl.textContent = btUserName ? 'Added by ' + btUserName : '';
    }
  }
  setTimeout(function() {
    if (!jobId) document.getElementById('btFOrderNum').focus();
    else document.getElementById('btFCustomer').focus();
  }, 50);
}

function btSelectGarment(val) {
  document.querySelectorAll('.bt-garment-grid .bt-select-option').forEach(o => o.classList.toggle('selected', o.dataset.val===val));
  document.getElementById('btFGarmentType').value = val;
  const otherField = document.getElementById('btFGarmentOther');
  otherField.style.display = val === 'Other' ? 'block' : 'none';
  if (val !== 'Other') otherField.value = '';
}

function btGetGarmentType() {
  const val = document.getElementById('btFGarmentType').value;
  if (val === 'Other') return document.getElementById('btFGarmentOther').value.trim() || 'Other';
  return val;
}

function btOpenModalForDate(dateStr, e) {
  e.stopPropagation();
  btOpenModal(null, dateStr);
}

function btCloseModal() {
  document.getElementById('btpJobModalOverlay').classList.remove('open');
  btActiveJob = null;
}

function btSelectDept(val) {
  document.querySelectorAll('.bt-dept-grid .bt-select-option').forEach(o => o.classList.toggle('selected', o.dataset.val===val));
  document.getElementById('btFDept').value = val;
}

function btSelectStatus(val) {
  document.querySelectorAll('#btpJobModalOverlay .bt-status-grid .bt-select-option').forEach(o => o.classList.toggle('selected', o.dataset.val===val));
  document.getElementById('btFStatus').value = val;
}

async function btSaveJob() {
  const lineItems = btGetLineItems();
  const totalQty = lineItems.reduce((sum, li) => sum + (parseInt(li.qty)||0), 0);
  /* A blank Loc # means "no location on this job" and must survive the round trip.
     It used to fall back to '1', so clearing the field wrote a 1 to the DB and the
     card grew a Loc chip back on the next open. Blank stays blank. */
  const locationSummary = lineItems.length === 1
    ? lineItems[0].location
    : lineItems.map(li => (li.qty ? li.qty + ' @ ' : '') + li.location).filter(s => s.trim()).join(' | ');
  const payload = {
    order_num: document.getElementById('btFOrderNum').value.trim(),
    due_date:  document.getElementById('btFDueDate').value,
    customer:  document.getElementById('btFCustomer').value.trim(),
    qty:       totalQty,
    location:  locationSummary,
    dept:      document.getElementById('btFDept').value,
    status:    document.getElementById('btFStatus').value,
    art_link:  btNormalizeArtPath(document.getElementById('btFArtLink').value),
    notes:     document.getElementById('btFNotes').value.trim(),
    caution:   document.getElementById('btFCaution').checked ? 1 : 0,
    garment_type: (lineItems.length > 0 && lineItems[0].garment) ? lineItems[0].garment : btGetGarmentType(),
  };
  if (!btActiveJob && btUserName) payload.user_name = btUserName;
  if (!payload.customer || !payload.dept || !payload.status) {
    alert('Please fill in Customer, Department, and Status.'); return;
  }
  btSaving(true);
  let btSaveOk = false;
  try {
    if (btActiveJob) { await btFetch('/jobs/'+btActiveJob, 'PUT', payload); }
    else { await btFetch('/jobs', 'POST', payload); }
    btSaveOk = true;
  } catch(e) {
    console.error('Save job error:', e);
    alert('Error saving job. Please try again.');
  }
  btSaving(false);
  if (btSaveOk) {
    btSearchCacheTime = 0;
    btCloseModal();
    try { await btLoadJobs(); btRenderBoard(); } catch(e) { console.error('Reload error after save:', e); }
  }
}

async function btDeleteJob() {
  if (!btActiveJob || !confirm('Delete this job? This cannot be undone.')) return;
  btSaving(true);
  try {
    await btFetch('/jobs/'+btActiveJob, 'DELETE');
    btSearchCacheTime = 0;
    btCloseModal();
    await btLoadJobs();
    btRenderBoard();
  } catch(e) { alert('Error deleting job.'); }
  btSaving(false);
}

/* ── CONTEXT MENU ── */
function btShowContextMenu(e, jobId) {
  btContextJobId = jobId;
  const menu = document.getElementById('btContextMenu');
  menu.style.left = Math.min(e.clientX, window.innerWidth-240) + 'px';
  menu.style.top  = Math.min(e.clientY, window.innerHeight-260) + 'px';
  menu.classList.add('open');
}

async function btQuickStatus(status) {
  if (!btContextJobId) return;
  const id = btContextJobId; btCloseContextMenu(); btSaving(true);
  try {
    await btFetch('/jobs/'+id+'/status', 'POST', {status, user_name: btUserName});
    await btLoadJobs();
    btRenderBoard();
  } catch(e) {}
  btSaving(false);
}

/* ── WOOCOMMERCE: complete the order behind a Transfers card ──
   Looks the order up first so the confirm shows who is about to get emailed. */
async function btWooComplete(jobId, btn) {
  const job = btJobs.find(j => j.id == jobId);
  if (!job) return;
  if (btn) { btn.disabled = true; btn.textContent = 'CHECKING\u2026'; }

  let info = null;
  try {
    info = await btFetch('/jobs/' + jobId + '/woo-order');
  } catch(e) {
    alert('Could not reach WooCommerce. Reload the portal and try again.');
    btWooResetBtn(btn); return;
  }
  if (!info || !info.found) {
    alert((info && info.message) || 'No WooCommerce order matches this card.');
    btWooResetBtn(btn); return;
  }

  const msg = 'COMPLETE THIS ORDER IN WOOCOMMERCE?\n\n'
    + 'Order:     #' + info.number + '\n'
    + 'Customer:  ' + info.customer + '\n'
    + 'Email:     ' + (info.email || '(none on order)') + '\n'
    + 'Total:     ' + info.total + '\n'
    + 'Items:     ' + info.item_count + '\n'
    + 'Status:    ' + info.status_label + '\n\n'
    + (info.status === 'completed'
        ? 'This order is ALREADY completed. Nothing will be emailed.'
        : 'This emails ' + (info.customer || 'the customer') + ' their order-complete notice. It cannot be undone from the portal.');

  if (!confirm(msg)) { btWooResetBtn(btn); return; }

  if (btn) btn.textContent = 'SENDING\u2026';
  btSaving(true);
  let res = null;
  try {
    res = await btFetch('/jobs/' + jobId + '/woo-complete', 'POST', {user_name: btUserName});
  } catch(e) {
    alert('The complete request failed. Check the order in WooCommerce before retrying.');
    btSaving(false); btWooResetBtn(btn); return;
  }
  btSaving(false);

  if (!res || !res.ok) {
    alert((res && res.message) || 'WooCommerce would not complete that order.');
    btWooResetBtn(btn); return;
  }

  alert(res.message);
  await btLoadJobs();
  btRenderBoard();
}

function btWooResetBtn(btn) {
  if (!btn) return;
  btn.disabled = false;
  btn.textContent = '\u2713 COMPLETE ORDER';
}

function btEditFromContext() {
  const id = btContextJobId; btCloseContextMenu(); btOpenModal(id);
}

function btCloseContextMenu() {
  document.getElementById('btContextMenu').classList.remove('open');
  btContextJobId = null;
}

// Close filter dropdowns and calendar when clicking outside
document.addEventListener('click', function(e) {
  if (!e.target.closest('#bt-schedule-app .header-center') && !e.target.closest('#bt-schedule-app .header-actions')) {
    document.getElementById('btFilterBar').classList.remove('open');
    document.getElementById('btStoreFilterBar').classList.remove('open');
    document.getElementById('btFilterToggleBtn').classList.remove('open');
    document.getElementById('btCalendarOverlay').classList.remove('open');
    document.getElementById('btCalendarBtn').classList.remove('open');
  } else if (e.target.closest('#bt-schedule-app .header-center') && !e.target.closest('.filter-bar') && !e.target.closest('#btCalendarOverlay') && !e.target.closest('#btFilterToggleBtn') && !e.target.closest('#btCalendarBtn')) {
    document.getElementById('btFilterBar').classList.remove('open');
    document.getElementById('btStoreFilterBar').classList.remove('open');
    document.getElementById('btFilterToggleBtn').classList.remove('open');
    document.getElementById('btCalendarOverlay').classList.remove('open');
    document.getElementById('btCalendarBtn').classList.remove('open');
  }
  if (!e.target.closest('#btContextMenu')) btCloseContextMenu();
});

/* ── STORE MODAL ── */
function btOpenCapacityPopover(dateStr, e) {
  e.stopPropagation();
  const existing = document.getElementById('btCapacityPopover');
  if (existing) existing.remove();

  const dayData = btClosedDays[dateStr] || null;
  const curCapacity = dayData ? (dayData.capacity !== undefined ? dayData.capacity : 100) : 100;
  const curReason = dayData ? (dayData.reason || '') : '';

  const pop = document.createElement('div');
  pop.id = 'btCapacityPopover';
  pop.style.cssText = 'position:fixed;background:#fff;border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.3);border:1.5px solid #e8eaf0;z-index:999999;padding:14px 16px;min-width:280px;font-family:Barlow,sans-serif;';

  const d = new Date(dateStr + 'T12:00:00');
  const dayLabel = d.toLocaleDateString('en-US',{weekday:'long',month:'short',day:'numeric'});

  pop.innerHTML = `
    <div style="font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#0f1240;margin-bottom:10px;">${dayLabel} — Capacity</div>
    <div style="display:flex;gap:5px;margin-bottom:10px;">
      ${[100,75,50,25,0].map(cap => `
        <button onclick="btSetCapacity('${dateStr}', ${cap})" style="flex:1;padding:8px 4px;border-radius:6px;border:2px solid ${curCapacity===cap?'#0f1240':'#e8eaf0'};background:${curCapacity===cap?'#0f1240':'#fff'};color:${curCapacity===cap?'#fff':'#5a6380'};font-family:'Barlow Condensed',sans-serif;font-size:13px;font-weight:700;cursor:pointer;letter-spacing:.03em;">
          ${cap===100?'OPEN':cap===0?'CLOSED':cap+'%'}
        </button>`).join('')}
    </div>
    <div id="btCapReasonWrap" style="display:${curCapacity<100?'block':'none'};">
      <input type="text" id="btCapReason" placeholder="Reason (e.g. Holiday, Maintenance...)" value="${curReason.replace(/"/g,'&quot;')}" style="width:100%;border:1.5px solid #e8eaf0;border-radius:5px;padding:7px 10px;font-family:'Barlow',sans-serif;font-size:13px;color:#0f1240;outline:none;box-sizing:border-box;margin-bottom:8px;">
      <button onclick="btSaveCapacity('${dateStr}')" style="width:100%;background:#0f1240;color:#fff;border:none;border-radius:6px;padding:8px;font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;">SAVE</button>
    </div>`;

  document.body.appendChild(pop);
  const rect = e.currentTarget.getBoundingClientRect();
  pop.style.left = Math.min(rect.left, window.innerWidth - 320) + 'px';
  pop.style.top  = (rect.bottom + 6) + 'px';

  setTimeout(() => {
    document.addEventListener('click', function closer(ev) {
      if (!ev.target.closest('#btCapacityPopover')) {
        pop.remove();
        document.removeEventListener('click', closer);
      }
    });
  }, 50);
}

let btPendingCapacity = null;
function btSetCapacity(dateStr, cap) {
  btPendingCapacity = cap;
  const wrap = document.getElementById('btCapReasonWrap');
  if (cap === 100) {
    btSaveCapacityDirect(dateStr, 100, '');
    return;
  }
  if (wrap) wrap.style.display = 'block';
  const pop = document.getElementById('btCapacityPopover');
  if (pop) {
    pop.querySelectorAll('button').forEach(b => {
      const txt = b.textContent.trim();
      const bCap = txt==='OPEN'?100:txt==='CLOSED'?0:parseInt(txt);
      if (!isNaN(bCap) && b.closest('div') === pop.children[1]) {
        b.style.borderColor = bCap===cap ? '#0f1240' : '#e8eaf0';
        b.style.background  = bCap===cap ? '#0f1240' : '#fff';
        b.style.color       = bCap===cap ? '#fff' : '#5a6380';
      }
    });
  }
  const reasonInput = document.getElementById('btCapReason');
  if (reasonInput) reasonInput.focus();
}

function btSaveCapacity(dateStr) {
  const reason = document.getElementById('btCapReason')?.value.trim() || '';
  const cap = btPendingCapacity !== null ? btPendingCapacity : 100;
  btSaveCapacityDirect(dateStr, cap, reason);
}

async function btSaveCapacityDirect(dateStr, capacity, reason) {
  const pop = document.getElementById('btCapacityPopover');
  if (pop) pop.remove();
  btPendingCapacity = null;
  btSaving(true);
  try {
    await btFetch('/closed-days', 'POST', {date: dateStr, capacity, reason, user_name: btUserName});
    if (capacity >= 100) {
      delete btClosedDays[dateStr];
    } else {
      btClosedDays[dateStr] = {capacity, reason};
    }
    btRenderBoard();
  } catch(e) {
    console.error('Save capacity error:', e);
    alert('Error saving day capacity.');
  }
  btSaving(false);
}

function btDayNoteKey(e, dateStr) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    e.target.blur();
  }
  if (e.key === 'Escape') {
    const input = document.getElementById('btNoteInput-' + dateStr);
    input.value = btDayNotes[dateStr] || '';
    input.blur();
  }
}

function btShowDayNote(dateStr, e) {
  if (e.target.tagName === 'TEXTAREA') return;
  const display = document.getElementById('btNoteDisplay-' + dateStr);
  const input   = document.getElementById('btNoteInput-' + dateStr);
  if (!display || !input) return;
  display.style.display = 'none';
  input.style.display = 'block';
  input.focus();
  input.setSelectionRange(input.value.length, input.value.length);
}

async function btLoadDayNotes() {
  try {
    const days = btGetWeekDays();
    const start = days[0].toISOString().split('T')[0];
    const end   = days[days.length-1].toISOString().split('T')[0];
    btDayNotes = await btFetch('/day-notes?start=' + start + '&end=' + end) || {};
  } catch(e) {
    console.error('Load day notes error:', e);
    btDayNotes = {};
  }
}

async function btLoadClosedDays() {
  try {
    const days = btGetWeekDays();
    const start = days[0].toISOString().split('T')[0];
    const end   = days[days.length-1].toISOString().split('T')[0];
    btClosedDays = await btFetch('/closed-days?start=' + start + '&end=' + end) || {};
  } catch(e) {
    console.error('Load closed days error:', e);
    btClosedDays = {};
  }
}

async function btSaveDayNote(dateStr) {
  const display = document.getElementById('btNoteDisplay-' + dateStr);
  const input   = document.getElementById('btNoteInput-' + dateStr);
  if (!display || !input) return;
  const note = input.value.trim();
  const prev = btDayNotes[dateStr] || '';
  input.style.display = 'none';
  display.style.display = 'block';
  if (note === prev) return;
  btDayNotes[dateStr] = note;
  display.textContent = note;
  btSaving(true);
  try {
    await btFetch('/day-notes', 'POST', {date: dateStr, note, user_name: btUserName});
  } catch(e) {
    console.error('Save day note error:', e);
    btDayNotes[dateStr] = prev;
    display.textContent = prev;
    alert('Error saving note.');
  }
  btSaving(false);
}

function btToggleDept(val) {
  const cur = document.getElementById('btFDept').value;
  btSelectDept(cur === val ? '' : val);
}

function btToggleGarment(val) {
  const cur = document.getElementById('btFGarmentType').value;
  if (cur === val) {
    document.querySelectorAll('.bt-garment-grid .bt-select-option').forEach(o => o.classList.remove('selected'));
    document.getElementById('btFGarmentType').value = '';
    document.getElementById('btFGarmentOther').style.display = 'none';
    document.getElementById('btFGarmentOther').value = '';
  } else {
    btSelectGarment(val);
  }
}

function btToggleStatus(val) {
  const cur = document.getElementById('btFStatus').value;
  btSelectStatus(cur === val ? '' : val);
}

function btToggleNoCloseDate(cb) {
  const closeInput = document.getElementById('btSfClose');
  if (cb.checked) {
    closeInput.value = '';
    closeInput.disabled = true;
    closeInput.style.opacity = '.4';
  } else {
    closeInput.disabled = false;
    closeInput.style.opacity = '1';
  }
}

function btOpenStoreModal(storeId) {
  btActiveStore = storeId || null;
  document.getElementById('btBtnStoreDelete').style.display = storeId ? 'block' : 'none';
  document.getElementById('btStoreModalTitle').innerHTML = storeId ? 'EDIT <span>STORE</span>' : 'NEW <span>STORE</span>';

  const catSelect = document.getElementById('btSfCategory');
  catSelect.innerHTML = '<option value="">— Uncategorized —</option>' +
    btStoreCategories.map(c => `<option value="${c.id}">${c.name}</option>`).join('');

  const noCloseCb = document.getElementById('btSfNoCloseDate');
  const closeInput = document.getElementById('btSfClose');

  if (storeId) {
    const s = btStores.find(x => x.id == storeId);
    document.getElementById('btSfName').value   = s.name;
    document.getElementById('btSfOpen').value   = s.openDate && s.openDate !== '0000-00-00' ? s.openDate : '';
    const hasClose = s.closeDate && s.closeDate !== '0000-00-00';
    closeInput.value = hasClose ? s.closeDate : '';
    noCloseCb.checked = !hasClose;
    btToggleNoCloseDate(noCloseCb);
    btSelectFulfillment(s.fulfillment || '');
    document.getElementById('btSfStatus').value = s.status;
    document.getElementById('btSfLink').value   = s.link;
    document.getElementById('btSfContactName').value  = s.contactName || '';
    document.getElementById('btSfContactEmail').value = s.contactEmail || '';
    document.getElementById('btSfNotes').value  = s.notes;
    catSelect.value = s.categoryId || '';
    btSetDeliveryDates(s.deliveryDates);
  } else {
    document.getElementById('btSfName').value   = '';
    document.getElementById('btSfOpen').value   = '';
    closeInput.value = '';
    noCloseCb.checked = false;
    btToggleNoCloseDate(noCloseCb);
    btSelectFulfillment('');
    document.getElementById('btSfStatus').value = 'Upcoming';
    document.getElementById('btSfLink').value   = '';
    document.getElementById('btSfContactName').value  = '';
    document.getElementById('btSfContactEmail').value = '';
    document.getElementById('btSfNotes').value  = '';
    catSelect.value = '';
    btSetDeliveryDates('[]');
  }
  document.getElementById('btStoreModalOverlay').classList.add('open');
  document.querySelector('#btStoreModalOverlay .bt-modal-wrap').scrollTop = 0;
}

function btCloseStoreModal() {
  document.getElementById('btStoreModalOverlay').classList.remove('open');
  btActiveStore = null;
}

function btSelectFulfillment(val) {
  document.querySelectorAll('#btFulfillmentGrid .bt-select-option').forEach(o => o.classList.toggle('selected', o.dataset.val===val));
  document.getElementById('btSfFulfillment').value = val;
  const deliverySection = document.getElementById('btSfDeliverySection');
  const needsDelivery = val === 'Individual- Bulk Deliver' || val === 'Group- Bulk Deliver';
  if (deliverySection) {
    deliverySection.style.opacity = needsDelivery ? '1' : '.35';
    deliverySection.style.pointerEvents = needsDelivery ? 'auto' : 'none';
  }
}

function btAddDeliveryDate(dateVal) {
  const container = document.getElementById('btSfDeliveryDates');
  const row = document.createElement('div');
  row.className = 'bt-delivery-date-row';
  row.style.cssText = 'display:flex;gap:6px;align-items:center;';
  row.innerHTML = `
    <input type="date" value="${dateVal||''}" style="flex:1;border:1.5px solid #e8eaf0;border-radius:5px;padding:7px 10px;font-family:'Barlow',sans-serif;font-size:13px;color:#0f1240;background:#fff;outline:none;box-sizing:border-box;">
    <button type="button" onclick="this.closest('.bt-delivery-date-row').remove()" style="background:#ffebee;color:#b71c1c;border:none;border-radius:4px;width:26px;height:26px;cursor:pointer;font-size:14px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">&#215;</button>`;
  container.appendChild(row);
}

function btSetDeliveryDates(json) {
  const container = document.getElementById('btSfDeliveryDates');
  container.innerHTML = '';
  let dates = [];
  try { dates = JSON.parse(json || '[]'); } catch(e) { dates = []; }
  if (Array.isArray(dates) && dates.length) {
    dates.forEach(d => btAddDeliveryDate(d));
  }
}

function btGetDeliveryDates() {
  return JSON.stringify(
    Array.from(document.querySelectorAll('#btSfDeliveryDates input[type="date"]'))
      .map(i => i.value)
      .filter(Boolean)
  );
}

async function btSaveStore() {
  const payload = {
    name:        document.getElementById('btSfName').value.trim(),
    open_date:   document.getElementById('btSfOpen').value,
    close_date:  document.getElementById('btSfNoCloseDate').checked ? '' : document.getElementById('btSfClose').value,
    fulfillment: document.getElementById('btSfFulfillment').value,
    status:      document.getElementById('btSfStatus').value,
    link:        document.getElementById('btSfLink').value.trim(),
    contact_name:  document.getElementById('btSfContactName').value.trim(),
    contact_email: document.getElementById('btSfContactEmail').value.trim(),
    notes:       document.getElementById('btSfNotes').value.trim(),
    category_id: document.getElementById('btSfCategory').value || null,
    delivery_dates: btGetDeliveryDates(),
  };
  if (!payload.name) { alert('Please enter a store name.'); return; }
  btSaving(true);
  try {
    if (btActiveStore) { await btFetch('/stores/'+btActiveStore, 'PUT', payload); }
    else { await btFetch('/stores', 'POST', payload); }
    btCloseStoreModal();
    await btLoadAndRenderStores();
  } catch(e) { alert('Error saving store.'); }
  btSaving(false);
}

async function btDeleteStore() {
  if (!btActiveStore || !confirm('Delete this store? This cannot be undone.')) return;
  btSaving(true);
  try {
    await btFetch('/stores/'+btActiveStore, 'DELETE');
    btCloseStoreModal();
    await btLoadAndRenderStores();
  } catch(e) { alert('Error deleting store.'); }
  btSaving(false);
}

/* ── FILTERS ── */
function btSetDeptFilter(dept) {
  btDeptFilter = dept;
  document.querySelectorAll('.filter-pill').forEach(p => p.classList.toggle('active', p.dataset.dept===dept));
  btRenderBoard();
}

function btSetStatusFilter(status) {
  btStatusFilter = status;
  document.querySelectorAll('.status-filter-pill').forEach(p => p.classList.toggle('active', p.dataset.status===status));
  btRenderBoard();
}

/* ── CALENDAR ── */
let btCalViewYear = null, btCalViewMonth = null;
let btCalJobs = [];
const btDeptColors = {'Digi':'#2196F3','Embroidery':'#FFD600','Online Stores':'#7B1FA2','Custom':'#2E7D32','Transfers':'#0D47A1','Out of House':'#E65100'};

function btToggleCalendar() {
  const overlay = document.getElementById('btCalendarOverlay');
  const btn = document.getElementById('btCalendarBtn');
  const isOpen = overlay.classList.contains('open');
  document.getElementById('btFilterBar').classList.remove('open');
  document.getElementById('btStoreFilterBar').classList.remove('open');
  document.getElementById('btFilterToggleBtn').classList.remove('open');
  if (isOpen) {
    overlay.classList.remove('open');
    btn.classList.remove('open');
  } else {
    const days = btGetWeekDays();
    btCalViewYear  = days[0].getFullYear();
    btCalViewMonth = days[0].getMonth();
    btLoadCalJobs().then(() => {
      btRenderCalendar();
      overlay.classList.add('open');
      btn.classList.add('open');
    });
  }
}

async function btLoadCalJobs() {
  const first = new Date(btCalViewYear, btCalViewMonth, 1);
  const last  = new Date(btCalViewYear, btCalViewMonth + 1, 0);
  const pad = n => String(n).padStart(2,'0');
  const startStr = `${first.getFullYear()}-${pad(first.getMonth()+1)}-01`;
  const endStr   = `${last.getFullYear()}-${pad(last.getMonth()+1)}-${pad(last.getDate())}`;
  try {
    const raw = await btFetch(`/jobs?week_start=${startStr}&week_end=${endStr}`);
    btCalJobs = raw.map(btNormalizeJob);
  } catch(e) { btCalJobs = []; }
}

function btCalShift(dir) {
  btCalViewMonth += dir;
  if (btCalViewMonth < 0)  { btCalViewMonth = 11; btCalViewYear--; }
  if (btCalViewMonth > 11) { btCalViewMonth = 0;  btCalViewYear++; }
  btLoadCalJobs().then(btRenderCalendar);
}

function btRenderCalendar() {
  const grid  = document.getElementById('btCalGrid');
  const title = document.getElementById('btCalTitle');
  const monthName = new Date(btCalViewYear, btCalViewMonth, 1).toLocaleDateString('en-US',{month:'long',year:'numeric'});
  title.textContent = monthName;

  const jobsByDate = {};
  btCalJobs.forEach(j => {
    if (!jobsByDate[j.dueDate]) jobsByDate[j.dueDate] = [];
    jobsByDate[j.dueDate].push(j);
  });

  const today = new Date(); today.setHours(0,0,0,0);
  const weekDays = btGetWeekDays();
  const weekStrs = weekDays.map(d => d.toISOString().split('T')[0]);

  const firstDow = new Date(btCalViewYear, btCalViewMonth, 1).getDay();
  const gridStart = new Date(btCalViewYear, btCalViewMonth, 1);
  gridStart.setDate(gridStart.getDate() - ((firstDow === 0) ? 6 : firstDow - 1));

  let html = ['Mo','Tu','We','Th','Fr','Sa','Su'].map(d => `<div class="cal-dow">${d}</div>`).join('');

  const cur = new Date(gridStart);
  for (let i = 0; i < 42; i++) {
    const dateStr = cur.toISOString().split('T')[0];
    const inMonth = cur.getMonth() === btCalViewMonth;
    const isToday = cur.getTime() === today.getTime();
    const inWeek  = weekStrs.includes(dateStr);
    const dayJobs = jobsByDate[dateStr] || [];

    const deptSet = [...new Set(dayJobs.map(j => j.dept))];
    const dots = deptSet.slice(0,6).map(d => `<span class="cal-dot" style="background:${btDeptColors[d]||'#9ca3b8'}"></span>`).join('');

    const closedData = btClosedDays[dateStr];
    const cap = closedData ? (closedData.capacity !== undefined ? closedData.capacity : 0) : 100;
    const isClosedDay = closedData && cap === 0;
    const isRestrictedDay = closedData && cap > 0 && cap < 100;

    html += `<div class="cal-day${inMonth?'':' other-month'}${isToday?' is-today':''}${inWeek?' is-selected-week':''}${isClosedDay?' is-closed':''}${isRestrictedDay?' is-restricted':''}" onclick="btJumpToWeekOf('${dateStr}')" title="${dayJobs.length} job${dayJobs.length!==1?'s':''}${closedData?' — '+(closedData.reason||(cap+'% capacity')):''}">
      <span class="cal-day-num">${cur.getDate()}</span>
      <span class="cal-dots">${dots}</span>
    </div>`;
    cur.setDate(cur.getDate() + 1);
  }
  grid.innerHTML = html;
}

function btJumpToWeekOf(dateStr) {
  const target = new Date(dateStr + 'T12:00:00');
  const targetDow = target.getDay();
  const targetMonday = new Date(target);
  targetMonday.setDate(target.getDate() + ((targetDow === 0) ? -6 : 1 - targetDow));
  targetMonday.setHours(0,0,0,0);

  const today = new Date(); today.setHours(0,0,0,0);
  const dow = today.getDay();
  const thisMonday = new Date(today);
  thisMonday.setDate(today.getDate() + ((dow === 0) ? -6 : 1 - dow));

  btWeekOffset = Math.round((targetMonday - thisMonday) / (7*24*60*60*1000));
  document.getElementById('btCalendarOverlay').classList.remove('open');
  document.getElementById('btCalendarBtn').classList.remove('open');
  btRefreshWeek();
}

function btSetStoreFilter(status) {
  btStoreFilter = status;
  document.querySelectorAll('.store-filter-pill').forEach(p => p.classList.toggle('active', p.dataset.storeStatus===status));
  btRenderStores();
}

function btToggleStoreFilters() {
  const bar = document.getElementById('btStoreFilterBar');
  const btn = document.getElementById('btFilterToggleBtn');
  bar.classList.toggle('open');
  btn.classList.toggle('open', bar.classList.contains('open'));
}

function btToggleFilters() {
  const onStores = document.getElementById('bt-tab-stores').classList.contains('active');
  document.getElementById('btCalendarOverlay').classList.remove('open');
  document.getElementById('btCalendarBtn').classList.remove('open');
  if (onStores) {
    document.getElementById('btFilterBar').classList.remove('open');
    btToggleStoreFilters();
  } else {
    document.getElementById('btStoreFilterBar').classList.remove('open');
    const bar = document.getElementById('btFilterBar');
    const btn = document.getElementById('btFilterToggleBtn');
    bar.classList.toggle('open');
    btn.classList.toggle('open', bar.classList.contains('open'));
  }
}

function btGoToToday() {
  if (btWeekOffset === 0) return;
  btWeekOffset = 0;
  btRefreshWeek();
}

/* ── SEARCH ── */
let btSearchCache = [];
let btSearchCacheTime = 0;
const BT_SEARCH_CACHE_MS = 60000; // re-fetch at most once per minute

async function btEnsureSearchCache() {
  if (Date.now() - btSearchCacheTime < BT_SEARCH_CACHE_MS && btSearchCache.length) return;
  try {
    const raw = await btFetch('/jobs');
    btSearchCache = raw.map(btNormalizeJob);
    btSearchCacheTime = Date.now();
  } catch(e) { console.error('Search cache error:', e); }
}

function btSearchInputHandler() {
  const wrap  = document.getElementById('btSearchWrap');
  const input = document.getElementById('btSearchInput');
  const q = input.value.trim();
  wrap.classList.toggle('has-text', q.length > 0);
  if (q.length < 2) { wrap.classList.remove('open'); return; }
  btSearchRun(q);
}

async function btSearchRun(q) {
  await btEnsureSearchCache();
  const wrap    = document.getElementById('btSearchWrap');
  const results = document.getElementById('btSearchResults');
  const needle  = q.toLowerCase();

  const matches = btSearchCache.filter(j =>
    (j.orderNum && j.orderNum.toLowerCase().includes(needle)) ||
    (j.customer && j.customer.toLowerCase().includes(needle))
  ).sort((a,b) => (b.dueDate||'').localeCompare(a.dueDate||'')).slice(0, 12);

  if (!matches.length) {
    results.innerHTML = '<div class="bt-search-empty">No jobs found</div>';
    wrap.classList.add('open');
    return;
  }

  results.innerHTML = matches.map(j => `
    <div class="bt-search-result" onclick="btSearchJump('${j.id}','${j.dueDate}')">
      <span class="bsr-order">${btSearchHL(j.orderNum ? '#'+j.orderNum : '—', needle)}</span>
      <span class="bsr-customer">${btSearchHL(j.customer, needle)}</span>
      <span class="bsr-week">${btSearchWeekLabel(j.dueDate)}</span>
    </div>`).join('');
  wrap.classList.add('open');
}

function btSearchHL(text, needle) {
  if (!text) return '';
  const idx = text.toLowerCase().indexOf(needle);
  if (idx < 0) return text;
  return text.slice(0,idx) + '<mark>' + text.slice(idx, idx+needle.length) + '</mark>' + text.slice(idx+needle.length);
}

function btSearchWeekLabel(dueDate) {
  if (!dueDate) return '';
  const d = new Date(dueDate + 'T12:00:00');
  if (isNaN(d)) return '';
  return d.toLocaleDateString('en-US',{month:'short',day:'numeric'});
}

async function btSearchJump(jobId, dueDate) {
  btSearchClear(false);
  if (dueDate) {
    const target = new Date(dueDate + 'T12:00:00');
    const targetDow = target.getDay();
    const targetMonday = new Date(target);
    targetMonday.setDate(target.getDate() + ((targetDow === 0) ? -6 : 1 - targetDow));
    targetMonday.setHours(0,0,0,0);
    const today = new Date(); today.setHours(0,0,0,0);
    const dow = today.getDay();
    const thisMonday = new Date(today);
    thisMonday.setDate(today.getDate() + ((dow === 0) ? -6 : 1 - dow));
    const newOffset = Math.round((targetMonday - thisMonday) / (7*24*60*60*1000));
    if (newOffset !== btWeekOffset) {
      btWeekOffset = newOffset;
      await btRefreshWeek();
    }
  }
  // Make sure we're on the schedule tab
  btSwitchTab('schedule');
  setTimeout(() => {
    const card = document.querySelector(`.job-card[data-id="${jobId}"]`);
    if (card) {
      card.scrollIntoView({behavior:'smooth', block:'center'});
      card.classList.add('bt-flash');
      setTimeout(() => card.classList.remove('bt-flash'), 2000);
    }
  }, 250);
}

function btSearchClear(refocus = true) {
  const wrap  = document.getElementById('btSearchWrap');
  const input = document.getElementById('btSearchInput');
  input.value = '';
  wrap.classList.remove('open','has-text');
  if (refocus) input.focus();
}

function btSearchToggle() {
  const wrap  = document.getElementById('btSearchWrap');
  const input = document.getElementById('btSearchInput');
  const expanded = wrap.classList.toggle('expanded');
  if (expanded) {
    setTimeout(() => input.focus(), 60);
  } else {
    input.value = '';
    wrap.classList.remove('open','has-text');
  }
}

document.addEventListener('click', function(e) {
  if (!e.target.closest('#btSearchWrap')) {
    const wrap = document.getElementById('btSearchWrap');
    if (wrap) {
      wrap.classList.remove('open');
      const input = document.getElementById('btSearchInput');
      if (input && !input.value.trim()) wrap.classList.remove('expanded','has-text');
    }
  }
});

function btShiftWeek(dir) {
  btWeekOffset += dir;
  btRefreshWeek();
}

async function btRefreshWeek() {
  await Promise.all([btLoadJobs(), btLoadDayNotes(), btLoadClosedDays()]);
  btRenderBoard();
  btCheckOverdue();
}

async function btToggleCaution(jobId) {
  const job = btJobs.find(j => j.id == jobId);
  if (!job) return;
  const newVal = job.caution ? 0 : 1;
  job.caution = !!newVal;
  btRenderBoard();
  try {
    await btFetch('/jobs/'+jobId, 'PUT', {_partial: true, caution: newVal});
  } catch(e) {
    job.caution = !newVal;
    btRenderBoard();
  }
}

/* ── DRAG TO REORDER ── */
let btDragSaving = false;
function btInitDrag(container) {
  let draggedCard = null;

  container.querySelectorAll('.job-card').forEach(card => {
    const handle = card.querySelector('.drag-handle');
    if (!handle) return;

    handle.addEventListener('mousedown', () => { card.draggable = true; });
    handle.addEventListener('mouseup',   () => { card.draggable = false; });

    card.addEventListener('dragstart', e => {
      draggedCard = card;
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', String(card.dataset.id||'')); } catch(err) {}
      setTimeout(() => card.classList.add('dragging'), 0);
    });

    card.addEventListener('dragend', async () => {
      card.draggable = false;
      container.classList.remove('drag-over');
      const allIds = [...document.querySelectorAll('#btBoard .job-card')].map(c => parseInt(c.dataset.id));
      // Apply the new order locally first, so any render before the save lands keeps it
      const orderMap = {};
      allIds.forEach((id, i) => orderMap[id] = i + 1);
      btJobs.forEach(j => { if (orderMap[j.id]) j.sortOrder = orderMap[j.id]; });
      btDragSaving = true;
      btSaving(true);
      try {
        await btFetch('/jobs/sort', 'POST', {order: allIds});
      } catch(e) {
        console.error('Sort save error:', e);
        alert('Could not save the new order. It will revert on the next refresh.');
      }
      btSaving(false);
      btDragSaving = false;
      card.classList.remove('dragging');
      draggedCard = null;
    });
  });

  container.addEventListener('dragover', e => {
    e.preventDefault();
    if (!draggedCard) return;
    container.classList.add('drag-over');
    const after = getDragAfterEl(container, e.clientY);
    if (after == null) container.appendChild(draggedCard);
    else container.insertBefore(draggedCard, after);
  });

  container.addEventListener('dragleave', () => container.classList.remove('drag-over'));
  container.addEventListener('drop', e => { e.preventDefault(); container.classList.remove('drag-over'); });

  function getDragAfterEl(cont, y) {
    const els = [...cont.querySelectorAll('.job-card')].filter(el => el !== draggedCard);
    return els.reduce((closest, el) => {
      const box = el.getBoundingClientRect();
      const offset = y - box.top - box.height / 2;
      if (offset < 0 && offset > closest.offset) return {offset, element: el};
      return closest;
    }, {offset: Number.NEGATIVE_INFINITY}).element;
  }
}

/* ── OVERDUE CHECK ── */
async function btCheckOverdue() {
  const bar = document.getElementById('btOverdueBar');
  if (btWeekOffset !== 0) { bar.classList.remove('visible'); return; }
  try {
    const today = new Date(); today.setHours(0,0,0,0);
    const dow = today.getDay(), mondayOffset = (dow===0)?-6:1-dow;
    const thisMonday = new Date(today); thisMonday.setDate(today.getDate()+mondayOffset);
    const mondayStr = thisMonday.toISOString().split('T')[0];

    const raw = await btFetch('/jobs');
    const all = raw.map(btNormalizeJob);
    const overdue = all.filter(j =>
      j.dueDate && j.dueDate < mondayStr &&
      j.status !== 'Complete/Notify Customer'
    );
    if (overdue.length > 0) {
      document.getElementById('btOverdueText').textContent =
        overdue.length + ' job' + (overdue.length>1?'s':'') + ' from previous weeks need' + (overdue.length===1?'s':'') + ' attention';
      bar.classList.add('visible');
      bar.dataset.oldestDate = overdue.sort((a,b) => a.dueDate.localeCompare(b.dueDate))[0].dueDate;
    } else {
      bar.classList.remove('visible');
    }
  } catch(e) { bar.classList.remove('visible'); }
}

function btGoToOverdue() {
  const bar = document.getElementById('btOverdueBar');
  const oldest = bar.dataset.oldestDate;
  if (!oldest) return;
  const target = new Date(oldest + 'T12:00:00');
  const targetDow = target.getDay();
  const targetMonday = new Date(target);
  targetMonday.setDate(target.getDate() + ((targetDow===0)?-6:1-targetDow));
  targetMonday.setHours(0,0,0,0);
  const today = new Date(); today.setHours(0,0,0,0);
  const dow = today.getDay();
  const thisMonday = new Date(today);
  thisMonday.setDate(today.getDate() + ((dow===0)?-6:1-dow));
  btWeekOffset = Math.round((targetMonday - thisMonday) / (7*24*60*60*1000));
  btRefreshWeek();
}

function btDownloadArtFiles() {
  window.location.href = 'https://www.boomerts.com/wp-content/uploads/2026/03/btart-open.zip';
}

/* ── VENDORS (Other > Vendors) ──
   The old shared spreadsheet. Passwords are encrypted server side and never
   ride along in the list payload — a reveal is its own POST, and every reveal
   is written to an audit row. */

let btvData = [], btvCats = [], btvCanEdit = false, btvLoaded = false, btvEditing = null;

async function btvLoad(force) {
  if (btvLoaded && !force) return;
  try {
    const res = await btFetch('/vendors', 'GET');
    btvData    = res.vendors || [];
    btvCats    = res.categories || [];
    btvCanEdit = !!res.can_edit;
    btvLoaded  = true;

    const sel = document.getElementById('btvCat');
    if (sel && sel.options.length <= 1) {
      btvCats.forEach(c => {
        const o = document.createElement('option');
        o.value = c; o.textContent = c;
        sel.appendChild(o);
      });
    }
    const add = document.getElementById('btvAddBtn');
    if (add) add.style.display = btvCanEdit ? '' : 'none';
    btvRender();
  } catch (e) {
    document.getElementById('btvList').innerHTML =
      '<p class="btv-empty">Could not load the vendor list. Refresh to try again.</p>';
  }
}

function btvEsc(v) {
  return String(v == null ? '' : v).replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function btvMsg(text, kind) {
  const el = document.getElementById('btvMsg');
  el.innerHTML = text ? '<div class="btv-msg ' + (kind||'ok') + '">' + btvEsc(text) + '</div>' : '';
  if (text) setTimeout(() => { el.innerHTML = ''; }, 6000);
}

function btvRender() {
  const q   = (document.getElementById('btvSearch').value || '').toLowerCase().trim();
  const cat = document.getElementById('btvCat').value;

  const list = btvData.filter(v => {
    if (cat && v.category !== cat) return false;
    if (!q) return true;
    return [v.name, v.account_no, v.phone, v.fax, v.login, v.notes, v.address, v.website]
      .join(' ').toLowerCase().includes(q);
  });

  document.getElementById('btvCount').textContent =
    list.length + (list.length === 1 ? ' vendor' : ' vendors');

  const form = btvEditing !== null ? btvFormHtml(btvEditing) : '';

  if (!list.length) {
    document.getElementById('btvList').innerHTML = form +
      '<p class="btv-empty">Nothing matches that.</p>';
    return;
  }

  const table =
    '<table class="btv-table">' +
      '<thead><tr>' +
        '<th style="width:22px;"></th>' +
        '<th>Vendor</th><th>Phone</th><th>Account&nbsp;#</th>' +
        '<th>Login</th><th>Password</th><th>Website</th>' +
        (btvCanEdit ? '<th style="text-align:right;">Edit</th>' : '') +
      '</tr></thead><tbody>' +
      list.map(btvRowHtml).join('') +
      '</tbody></table>';

  document.getElementById('btvList').innerHTML = form + table;
}

function btvRowHtml(v) {
  const cols  = btvCanEdit ? 8 : 7;
  const extra = (v.address || v.notes || v.fax);

  const tel  = v.phone
    ? '<a href="tel:' + btvEsc(v.phone.replace(/[^0-9+*]/g,'')) + '">' + btvEsc(v.phone) + '</a>' : '—';
  const site = v.website
    ? '<a href="' + btvEsc(v.website) + '" target="_blank" rel="noopener">' +
      btvEsc(v.website.replace(/^https?:\/\//,'').replace(/\/$/,'').slice(0,28)) + '</a>' : '—';

  const secret = v.has_secret
    ? '<span class="btv-secret" id="btvSec' + v.id + '">' +
        '<span class="btv-dots">••••••</span>' +
        '<button type="button" class="btv-mini" onclick="btvReveal(' + v.id + ')">SHOW</button>' +
      '</span>'
    : '<span style="color:#c9cde0;">—</span>';

  const acts = btvCanEdit
    ? '<td><div class="btv-acts">' +
        '<button type="button" class="btv-mini" onclick="btvEdit(' + v.id + ')">EDIT</button>' +
        '<button type="button" class="btv-mini" onclick="btvDelete(' + v.id + ',\'' +
          btvEsc(v.name).replace(/'/g,"\\'") + '\')">DEL</button>' +
      '</div></td>'
    : '';

  const main =
    '<tr id="btvRow' + v.id + '">' +
      '<td>' + (extra
        ? '<button type="button" class="btv-caret" id="btvCaret' + v.id + '" ' +
          'onclick="btvToggle(' + v.id + ')" title="More">&#9656;</button>'
        : '') + '</td>' +
      '<td><span class="btv-name">' + btvEsc(v.name) + '</span>' +
        (v.category ? '<span class="btv-cat">' + btvEsc(v.category) + '</span>' : '') + '</td>' +
      '<td class="btv-mono">' + tel + '</td>' +
      '<td class="btv-mono">' + (btvEsc(v.account_no) || '—') + '</td>' +
      '<td>' + (btvEsc(v.login) || '—') + '</td>' +
      '<td>' + secret + '</td>' +
      '<td>' + site + '</td>' +
      acts +
    '</tr>';

  const detail = extra
    ? '<tr class="btv-detail" id="btvDet' + v.id + '" style="display:none;">' +
        '<td colspan="' + cols + '"><div class="btv-detail-in">' +
          (v.address ? '<div><h4>Address</h4><p>' + btvEsc(v.address) + '</p></div>' : '') +
          (v.fax ? '<div><h4>Fax</h4><p>' + btvEsc(v.fax) + '</p></div>' : '') +
          (v.notes ? '<div><h4>Notes</h4><p>' + btvEsc(v.notes) + '</p></div>' : '') +
        '</div></td>' +
      '</tr>'
    : '';

  return main + detail;
}

function btvToggle(id) {
  const det = document.getElementById('btvDet' + id);
  const row = document.getElementById('btvRow' + id);
  const car = document.getElementById('btvCaret' + id);
  if (!det) return;
  const open = det.style.display === 'none';
  det.style.display = open ? '' : 'none';
  row.classList.toggle('btv-open', open);
  car.innerHTML = open ? '&#9662;' : '&#9656;';
}

async function btvReveal(id) {
  const box = document.getElementById('btvSec' + id);
  try {
    const res = await btFetch('/vendors/' + id + '/secret', 'POST', {});
    box.innerHTML =
      '<code style="font-size:13px;color:#0f1240;">' + btvEsc(res.secret) + '</code>' +
      '<button type="button" class="btv-mini" onclick="btvCopy(' + id + ',\'' +
        btvEsc(res.secret).replace(/'/g,"\\'") + '\')">COPY</button>';
    // Don't leave it sitting on screen for the next person at that machine.
    setTimeout(() => {
      const b = document.getElementById('btvSec' + id);
      if (b) b.innerHTML = '<span class="btv-dots">••••••</span>' +
        '<button type="button" class="btv-mini" onclick="btvReveal(' + id + ')">SHOW</button>';
    }, 30000);
  } catch (e) {
    btvMsg('Could not read that password.', 'err');
  }
}

function btvCopy(id, text) {
  const done = () => btvMsg('Password copied.', 'ok');
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done).catch(() => {});
  } else {
    const ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } catch (e) {}
    document.body.removeChild(ta);
  }
}

function btvNew()  { btvEditing = {id:0, category:'Apparel'}; btvRender(); }
function btvEdit(id) {
  const v = btvData.find(x => x.id === id);
  if (v) { btvEditing = Object.assign({}, v); btvRender(); }
}
function btvCancel() { btvEditing = null; btvRender(); }

function btvFormHtml(v) {
  const catOpts = btvCats.map(c =>
    '<option value="' + btvEsc(c) + '"' + (c === v.category ? ' selected' : '') + '>' + btvEsc(c) + '</option>').join('');

  return '' +
    '<div class="btv-form">' +
      '<div class="btv-grid">' +
        '<div><label>Vendor name</label><input id="btvfName" value="' + btvEsc(v.name) + '"></div>' +
        '<div><label>Category</label><select id="btvfCat">' + catOpts + '</select></div>' +
        '<div><label>Phone</label><input id="btvfPhone" value="' + btvEsc(v.phone) + '"></div>' +
        '<div><label>Fax</label><input id="btvfFax" value="' + btvEsc(v.fax) + '"></div>' +
        '<div><label>Account #</label><input id="btvfAcct" value="' + btvEsc(v.account_no) + '"></div>' +
        '<div><label>Login</label><input id="btvfLogin" value="' + btvEsc(v.login) + '"></div>' +
        '<div><label>Password' + (v.has_secret ? ' (blank = leave as is)' : '') + '</label>' +
          '<input id="btvfSecret" type="text" placeholder="' + (v.has_secret ? 'unchanged' : '') + '"></div>' +
        '<div><label>Website</label><input id="btvfSite" value="' + btvEsc(v.website) + '"></div>' +
      '</div>' +
      '<div class="btv-grid" style="margin-top:10px;">' +
        '<div><label>Address</label><textarea id="btvfAddr">' + btvEsc(v.address) + '</textarea></div>' +
        '<div><label>Notes / rep</label><textarea id="btvfNotes">' + btvEsc(v.notes) + '</textarea></div>' +
      '</div>' +
      '<div class="btv-ops">' +
        '<button type="button" class="btv-mini" style="background:#1a1f5e;color:#fff;border-color:#1a1f5e;" ' +
          'onclick="btvSave(' + (v.id || 0) + ')">SAVE</button>' +
        '<button type="button" class="btv-mini" onclick="btvCancel()">CANCEL</button>' +
      '</div>' +
    '</div>';
}

async function btvSave(id) {
  const body = {
    name:       document.getElementById('btvfName').value.trim(),
    category:   document.getElementById('btvfCat').value,
    phone:      document.getElementById('btvfPhone').value.trim(),
    fax:        document.getElementById('btvfFax').value.trim(),
    account_no: document.getElementById('btvfAcct').value.trim(),
    login:      document.getElementById('btvfLogin').value.trim(),
    website:    document.getElementById('btvfSite').value.trim(),
    address:    document.getElementById('btvfAddr').value.trim(),
    notes:      document.getElementById('btvfNotes').value.trim()
  };
  const pw = document.getElementById('btvfSecret').value;
  // Only send the password when something was typed, so an edit of the phone
  // number can't silently wipe a password nobody retyped.
  if (pw !== '') body.secret = pw;

  if (!body.name) { btvMsg('A vendor name is needed.', 'err'); return; }

  try {
    await btFetch(id ? '/vendors/' + id : '/vendors', 'POST', body);
    btvEditing = null;
    await btvLoad(true);
    btvMsg(body.name + ' saved.', 'ok');
  } catch (e) {
    btvMsg('Could not save that vendor.', 'err');
  }
}

async function btvDelete(id, name) {
  if (!confirm('Delete ' + name + ' from the vendor list?')) return;
  try {
    await btFetch('/vendors/' + id, 'DELETE', {});
    await btvLoad(true);
    btvMsg(name + ' deleted.', 'ok');
  } catch (e) {
    btvMsg('Could not delete that vendor.', 'err');
  }
}

/* ── ACCOUNT PANEL ──
   Opened from the name in the header. Everyone sees their own username and
   email read-only plus a self-serve password reset; anyone with
   bt_manage_portal_users also gets the full list to edit.
   All five routes are capability-checked server side — the panel hiding a
   control is convenience, not the security boundary. */

let btpAcctData = null;
let btpAcctNames = [];

function btpAcctOpen() {
  document.getElementById('btpAcctBg').style.display = 'block';
  document.getElementById('btpAcctPanel').style.display = 'flex';
  btpAcctMsg('');
  btpAcctLoad();
}

function btpAcctClose() {
  document.getElementById('btpAcctBg').style.display = 'none';
  document.getElementById('btpAcctPanel').style.display = 'none';
}

function btpAcctMsg(text, kind) {
  const el = document.getElementById('btpAcctMsg');
  el.innerHTML = text ? '<div class="btp-acct-msg ' + (kind || 'ok') + '">' + btpAcctEsc(text) + '</div>' : '';
}

function btpAcctEsc(v) {
  return String(v == null ? '' : v)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

async function btpAcctLoad() {
  try {
    const me = await btFetch('/account', 'GET');
    btpAcctData = me;
    document.getElementById('btpAcctLogin').textContent = me.login;
    document.getElementById('btpAcctEmail').textContent = me.email;

    if (!me.can_manage) {
      document.getElementById('btpAcctAdmin').style.display = 'none';
      return;
    }
    document.getElementById('btpAcctAdmin').style.display = 'block';
    document.getElementById('btpAcctList').innerHTML =
      '<p class="btp-acct-note">Loading&hellip;</p>';

    const res = await btFetch('/account/users', 'GET');
    btpAcctRenderList(res.users, res.legacy_names || []);
  } catch (e) {
    btpAcctMsg('Could not load your account. Refresh and try again.', 'err');
  }
}

const BTP_IC = {
  save:  '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
  mail:  '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="22 6 12 13 2 6"/></svg>',
  shield:'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
  x:     '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
  plus:  '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>'
};

function btpAcctLegacyOpts(selected, names) {
  const opts = names.slice();
  if (selected && opts.indexOf(selected) === -1) opts.push(selected);
  return ['<option value="">— none —</option>'].concat(
    opts.map(n => '<option value="' + btpAcctEsc(n) + '"' +
      (n === selected ? ' selected' : '') + '>' + btpAcctEsc(n) + '</option>')
  ).join('');
}

function btpAcctRenderList(users, legacyNames) {
  btpAcctNames = legacyNames || [];

  const head =
    '<div class="btp-acct-new">' +
      '<input type="text" id="btpNewLogin" placeholder="username" autocomplete="off" spellcheck="false">' +
      '<input type="text" id="btpNewName" placeholder="name shown in portal" autocomplete="off">' +
      '<input type="email" id="btpNewEmail" placeholder="email for the invite" autocomplete="off">' +
      '<select id="btpNewLegacy">' + btpAcctLegacyOpts('', btpAcctNames) + '</select>' +
      '<div class="btp-acct-ops">' +
        '<select id="btpNewRole" style="width:auto;font-size:11px;padding:5px 4px;">' +
          '<option value="bt_portal_user">User</option>' +
          '<option value="bt_portal_admin">Admin</option>' +
        '</select>' +
        '<button type="button" class="btp-acct-ic save" title="Create and send invite" ' +
          'onclick="btpAcctCreate(this)">' + BTP_IC.plus + '</button>' +
      '</div>' +
    '</div>' +
    '<div class="btp-acct-head-row">' +
      '<div>User</div><div>Name</div><div>Email</div><div>Old name</div>' +
      '<div style="text-align:right;">Actions</div>' +
    '</div>';

  const rows = users.map(btpAcctRowHtml).join('');

  document.getElementById('btpAcctList').innerHTML = head + rows +
    '<div class="btp-acct-foot">' +
      '<div class="btp-acct-key">' +
        '<span><i class="btp-acct-dot"></i>Portal user</span>' +
        '<span><i class="btp-acct-dot is-admin"></i>Portal admin</span>' +
        '<span><i class="btp-acct-dot is-wp"></i>WordPress admin</span>' +
      '</div>' +
      '<div>' + users.length + ' with access</div>' +
    '</div>';
}

function btpAcctRowHtml(u) {
  const dot = u.access === 'wpadmin' ? 'is-wp' : (u.access === 'portaladmin' ? 'is-admin' : '');
  const locked = (u.access === 'wpadmin') || u.is_self;
  const roleTitle = u.access === 'portaladmin' ? 'Remove admin' : 'Make admin';
  const last = u.last ? 'Last login ' + u.last : 'Never signed in';

  return '' +
    '<div class="btp-acct-line" data-id="' + u.id + '">' +
      '<div class="btp-acct-who" title="' + btpAcctEsc(u.login + ' — ' + last) + '">' +
        '<i class="btp-acct-dot ' + dot + '"></i>' +
        '<span class="btp-acct-login">' + btpAcctEsc(u.login) + '</span>' +
      '</div>' +
      '<input type="text" data-f="name" value="' + btpAcctEsc(u.name) + '" oninput="btpAcctDirty(this)">' +
      '<input type="email" data-f="email" value="' + btpAcctEsc(u.email) + '" oninput="btpAcctDirty(this)">' +
      '<select data-f="legacy" onchange="btpAcctDirty(this)">' +
        btpAcctLegacyOpts(u.legacy, btpAcctNames) + '</select>' +
      '<div class="btp-acct-ops">' +
        '<button type="button" class="btp-acct-ic save" title="Save" onclick="btpAcctSave(' + u.id + ',this)">' + BTP_IC.save + '</button>' +
        '<button type="button" class="btp-acct-ic" title="Email a password link" onclick="btpAcctResetUser(' + u.id + ',this)">' + BTP_IC.mail + '</button>' +
        '<button type="button" class="btp-acct-ic" title="' + roleTitle + '" ' + (locked ? 'disabled' : '') +
          ' onclick="btpAcctRole(' + u.id + ',\'' + (u.access === 'portaladmin' ? 'bt_portal_user' : 'bt_portal_admin') + '\',this)">' + BTP_IC.shield + '</button>' +
        '<button type="button" class="btp-acct-ic danger" title="Remove portal access" ' + (locked ? 'disabled' : '') +
          ' onclick="btpAcctRemove(' + u.id + ',\'' + btpAcctEsc(u.login) + '\',this)">' + BTP_IC.x + '</button>' +
      '</div>' +
    '</div>';
}

/* Highlight a row the moment it differs from what's saved, so nothing gets
   typed and then abandoned unsaved. */
function btpAcctDirty(el) {
  const row = el.closest('.btp-acct-line');
  if (row) row.classList.add('is-dirty');
}

function btpAcctRowValues(id) {
  const row = document.querySelector('#btpAcctList .btp-acct-line[data-id="' + id + '"]');
  if (!row) return null;
  return {
    name:   row.querySelector('[data-f="name"]').value.trim(),
    email:  row.querySelector('[data-f="email"]').value.trim(),
    legacy: row.querySelector('[data-f="legacy"]').value
  };
}

async function btpAcctSave(id, btn) {
  const vals = btpAcctRowValues(id);
  if (!vals) return;
  btn.disabled = true;
  try {
    const saved = await btFetch('/account/users/' + id, 'POST', vals);
    const row = document.querySelector('#btpAcctList .btp-acct-line[data-id="' + id + '"]');
    if (row) row.classList.remove('is-dirty');
    btpAcctMsg(saved.login + ' saved — shown in the portal as "' + saved.shown + '".', 'ok');
    if (saved.is_self) setTimeout(() => location.reload(), 900);
  } catch (e) {
    btpAcctMsg('Could not save that. Check the email is valid and not already in use.', 'err');
  }
  btn.disabled = false;
}

async function btpAcctCreate(btn) {
  const body = {
    login:  document.getElementById('btpNewLogin').value.trim(),
    name:   document.getElementById('btpNewName').value.trim(),
    email:  document.getElementById('btpNewEmail').value.trim(),
    legacy: document.getElementById('btpNewLegacy').value,
    role:   document.getElementById('btpNewRole').value
  };
  if (!body.login || !body.email) {
    btpAcctMsg('A username and an email are both needed.', 'err');
    return;
  }
  btn.disabled = true;
  try {
    const made = await btFetch('/account/users', 'POST', body);
    btpAcctMsg(made.login + ' created. Password:  ' + made.temp +
               '  — also emailed to ' + made.email + '. ' +
               'The portal will ask them to pick their own once they sign in.', 'ok');
    btpAcctLoad();
  } catch (e) {
    btpAcctMsg('Could not create that user. The username or email is probably already taken.', 'err');
  }
  btn.disabled = false;
}

async function btpAcctRole(id, role, btn) {
  btn.disabled = true;
  try {
    const res = await btFetch('/account/users/' + id + '/role', 'POST', {role: role});
    btpAcctMsg(res.login + ' is now a ' +
      (res.access === 'portaladmin' ? 'portal admin — they can add and remove logins.' : 'regular portal user.'), 'ok');
    btpAcctLoad();
  } catch (e) {
    btpAcctMsg('Could not change that.', 'err');
    btn.disabled = false;
  }
}

async function btpAcctRemove(id, login, btn) {
  if (!confirm('Remove portal access for ' + login + '?\n\nTheir account and their name on past jobs stay put.')) return;
  btn.disabled = true;
  try {
    await btFetch('/account/users/' + id, 'DELETE', {});
    const row = document.querySelector('#btpAcctList .btp-acct-line[data-id="' + id + '"]');
    if (row) row.classList.add('is-gone');
    btpAcctMsg(login + ' can no longer open the portal.', 'ok');
    btpAcctLoad();
  } catch (e) {
    btpAcctMsg('Could not remove that.', 'err');
    btn.disabled = false;
  }
}

async function btpAcctResetUser(id, btn) {
  btn.disabled = true;
  try {
    const res = await btFetch('/account/users/' + id + '/reset', 'POST', {});
    btpAcctMsg('New password for ' + res.login + ' is  ' + res.temp +
               '  — also emailed to ' + res.email + '. Read it to them; ' +
               'the portal will ask them to change it.', 'ok');
  } catch (e) {
    btpAcctMsg('Could not send that email. Try again shortly.', 'err');
  }
  btn.disabled = false;
}

async function btpAcctResetSelf() {
  const btn = document.getElementById('btpAcctResetBtn');
  btn.disabled = true;
  try {
    const res = await btFetch('/account/reset', 'POST', {});
    btpAcctMsg('Sent to ' + res.email + '. Open it and pick a new password — good for 24 hours.', 'ok');
  } catch (e) {
    btpAcctMsg('Could not send that email. Try again shortly.', 'err');
  }
  btn.disabled = false;
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && document.getElementById('btpAcctPanel') &&
      document.getElementById('btpAcctPanel').style.display === 'flex') btpAcctClose();
});

/* ── USER ──
   btUserName is set from the signed-in WordPress account at render time; there
   is no picker and nothing cached in localStorage. btSetUser is kept as a no-op
   so any stale inline handler left in a cached page can't throw. */
function btSetUser() { /* identity comes from the session now */ }

/* ── BACKUPS ── */
function btOpenBackupPanel() {
  document.getElementById('btBackupPanel').style.display = 'flex';
  document.getElementById('btBackupBg').style.display = 'block';
  btLoadBackupList();
}

function btCloseBackupPanel() {
  document.getElementById('btBackupPanel').style.display = 'none';
  document.getElementById('btBackupBg').style.display = 'none';
}

async function btLoadBackupList() {
  const list = document.getElementById('btBackupList');
  list.innerHTML = '<div style="padding:20px;text-align:center;color:#9ca3b8;font-size:13px;">Loading...</div>';
  try {
    const backups = await btFetch('/backups');
    if (!backups.length) {
      list.innerHTML = '<div style="padding:20px;text-align:center;color:#9ca3b8;font-size:13px;font-style:italic;">No backups yet</div>';
      return;
    }
    list.innerHTML = backups.map(b => {
      const d = new Date(b.created_at.replace(' ','T'));
      const dateStr = d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) + ' ' + d.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
      const typeBadge = b.type === 'auto'
        ? '<span style="background:#e3f2fd;color:#1565c0;font-size:9px;font-weight:700;padding:2px 6px;border-radius:3px;letter-spacing:.06em;">AUTO</span>'
        : b.type === 'pre_restore'
        ? '<span style="background:#fff3e0;color:#e65100;font-size:9px;font-weight:700;padding:2px 6px;border-radius:3px;letter-spacing:.06em;">PRE-RESTORE</span>'
        : '<span style="background:#e8f5e9;color:#2e7d32;font-size:9px;font-weight:700;padding:2px 6px;border-radius:3px;letter-spacing:.06em;">MANUAL</span>';
      return `
        <div style="padding:10px 16px;border-bottom:1px solid #f0f1f5;display:flex;align-items:center;gap:10px;">
          <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:600;color:#0f1240;display:flex;align-items:center;gap:6px;">${typeBadge} <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${b.label||'Backup'}</span></div>
            <div style="font-size:11px;color:#9ca3b8;margin-top:2px;">${dateStr}</div>
          </div>
          <button onclick="btRestoreFromBackup(${b.id}, '${(b.label||'Backup').replace(/'/g,"\\'")}')" style="background:#0f1240;color:#fff;border:none;border-radius:4px;padding:5px 10px;font-family:'Barlow Condensed',sans-serif;font-size:11px;font-weight:700;letter-spacing:.05em;cursor:pointer;flex-shrink:0;">RESTORE</button>
        </div>`;
    }).join('');
  } catch(e) {
    list.innerHTML = '<div style="padding:20px;text-align:center;color:#b71c1c;font-size:13px;">Error loading backups</div>';
  }
}

function btDownloadCSV() {
  const jobs = [...btJobs].sort((a,b) => (a.dueDate||'').localeCompare(b.dueDate||'') || (a.sortOrder||0)-(b.sortOrder||0));
  const headers = ['Order #','Customer','Qty','Garment Type','Location','Dept','Status','Due Date','Caution','Art Link','Notes'];
  const rows = [headers];
  jobs.forEach(j => {
    rows.push([
      j.orderNum||'', j.customer||'', j.qty||'', j.garmentType||'', j.location||'',
      j.dept||'', j.status||'', j.dueDate||'', j.caution?'YES':'', j.artLink||'', j.notes||''
    ]);
  });
  const csv = rows.map(r => r.map(c => {
    c = String(c).replace(/"/g,'""');
    return /[",\n\r]/.test(c) ? '"'+c+'"' : c;
  }).join(',')).join('\r\n');
  const blob = new Blob([csv], {type:'text/csv'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'schedule-week-' + btGetWeekDays()[0].toISOString().split('T')[0] + '.csv';
  a.click();
  URL.revokeObjectURL(a.href);
}

async function btCreateManualBackup() {
  btSaving(true);
  try {
    await btFetch('/backups', 'POST', {type:'manual'});
    btLoadBackupList();
  } catch(e) { alert('Error creating backup.'); }
  btSaving(false);
}

async function btRestoreFromBackup(id, label) {
  if (!confirm('Restore from "' + label + '"?\n\nThis will REPLACE all current jobs and stores with the backup data.\n\nA backup of the current state will be saved first, so you can undo this.')) return;
  btSaving(true);
  try {
    await btFetch('/backups/' + id + '/restore', 'POST', {});
    btCloseBackupPanel();
    await btRefreshWeek();
    await btLoadAndRenderStores();
    alert('Restored successfully!');
  } catch(e) { alert('Error restoring backup.'); }
  btSaving(false);
}

/* ── QUOTE TOOL ── */
/* The quote UI is owned by the BT Quote plugin and rendered server-side into
   #btQuoteTool via the [bt_quick_quote] shortcode. The portal no longer carries
   its own copy — the old inline version drifted out of sync with BT Quote's
   pricing contract (garment IDs, param names, response keys) and 400'd. */

/* ── TABS ── */
/* Tabs that live inside the OTHER dropdown rather than on the bar itself.
   Add a tab here and it needs nothing else in this function. */
const BT_MORE_TABS = { contacts: 'Contacts', vendors: 'Vendors', exchanges: 'Exchanges', omgscan: 'OMG Scanner', chipscan: 'Chipply Scanner' };  // barcoder: 'Chipply Barcoder' — hidden

function btToggleMore(e) {
  // Clicks on the menu items bubble up to this handler; ignore them so the
  // menu doesn't reopen the instant a selection closes it.
  if (e && e.target.closest && e.target.closest('.tab-menu')) return;
  document.getElementById('btMoreTab').classList.toggle('open');
}

function btCloseMore() {
  const m = document.getElementById('btMoreTab');
  if (m) m.classList.remove('open');
}

document.addEventListener('click', function(e) {
  if (!e.target.closest || !e.target.closest('#btMoreTab')) btCloseMore();
});

function btSwitchTab(tab, push) {
  document.querySelectorAll('#bt-schedule-app .header-tabs > .tab').forEach(t => t.classList.toggle('active', t.dataset.tab===tab));
  document.querySelectorAll('#bt-schedule-app .tab-menu-item').forEach(t => t.classList.toggle('active', t.dataset.tab===tab));
  document.querySelectorAll('#bt-schedule-app .tab-content').forEach(c => c.classList.remove('active'));
  document.getElementById('bt-tab-'+tab).classList.add('active');

  const moreTab = document.getElementById('btMoreTab');
  const inMore  = Object.prototype.hasOwnProperty.call(BT_MORE_TABS, tab);
  moreTab.classList.toggle('active', inMore);
  moreTab.classList.remove('open');
  document.getElementById('btMoreLabel').textContent = inMore ? BT_MORE_TABS[tab] : 'Other';

  const isStores = tab === 'stores';
  const isSchedule = tab === 'schedule';
  document.getElementById('btAddStoreBtn').style.display = isStores ? 'flex' : 'none';
  document.querySelector('#bt-schedule-app .week-nav').style.display = isSchedule ? 'flex' : 'none';
  document.getElementById('btCalendarBtn').style.display = isSchedule ? 'flex' : 'none';
  document.getElementById('btFilterToggleBtn').style.display = (isSchedule || isStores) ? 'flex' : 'none';
  const searchWrap = document.getElementById('btSearchWrap');
  if (searchWrap) searchWrap.style.display = isSchedule ? 'flex' : 'none';
  document.getElementById('btFilterBar').classList.remove('open');
  document.getElementById('btStoreFilterBar').classList.remove('open');
  document.getElementById('btFilterToggleBtn').classList.remove('open');
  document.getElementById('btCalendarOverlay').classList.remove('open');
  document.getElementById('btCalendarBtn').classList.remove('open');

  if (isStores) btLoadAndRenderStores();
  if (tab === 'contacts') btLoadContacts();
  if (tab === 'vendors')  btvLoad();

  // The exchange poll is tied to the tab, not the page.
  if (tab === 'exchanges') { btLoadExchanges(); btStartExPoll(); }
  else btStopExPoll();

  // pushState, so Back walks through the tabs the way people expect. Skipped
  // when the switch came from the URL itself (initial load, Back button) —
  // otherwise every restore would push a duplicate entry.
  if (push !== false) {
    try {
      const next = btTabUrl(tab);
      if (next && next !== window.location.href) window.history.pushState({ btTab: tab }, '', next);
    } catch (e) {}
  }
}

window.addEventListener('popstate', function() {
  btSwitchTab(btTabFromUrl(), false);
});

/* Coming back to a backgrounded window, catch up immediately rather than
   waiting out the rest of the interval on stale data. */
document.addEventListener('visibilitychange', function() {
  if (!document.hidden && btExPoll) btPollExchanges();
});

/* ── ESC to close ── */
function btEsc(e) {
  if (e.key === 'Escape') { btCloseModal(); btCloseStoreModal(); btCloseContextMenu(); btCloseBackupPanel(); btCloseMore(); }
}
document.addEventListener('keydown', btEsc);

/* ── CONTACTS ── */
async function btLoadContacts() {
  const tbody = document.getElementById('btContactsBody');
  try {
    const contacts = await btFetch('/contacts');
    document.getElementById('btContactCount').textContent = contacts.length + ' contact' + (contacts.length !== 1 ? 's' : '');
    if (!contacts.length) {
      tbody.innerHTML = '<tr><td colspan="8" style="padding:40px;text-align:center;color:#9ca3b8;font-style:italic;">No contacts yet — form submissions will appear here</td></tr>';
      return;
    }
    tbody.innerHTML = contacts.map(c => {
      const d = new Date((c.created_at||'').replace(' ','T'));
      const dateStr = isNaN(d) ? '' : d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
      const msg = (c.message||'').length > 80 ? (c.message||'').slice(0,80)+'…' : (c.message||'');
      return `<tr style="border-bottom:1px solid #f0f1f5;">
        <td style="padding:10px 14px;font-weight:600;color:#0f1240;white-space:nowrap;">${btEscHtml(c.first_name)} ${btEscHtml(c.last_name)}</td>
        <td style="padding:10px 14px;">${btEscHtml(c.school_org)}</td>
        <td style="padding:10px 14px;white-space:nowrap;">${btEscHtml(c.city_state)}</td>
        <td style="padding:10px 14px;"><a href="mailto:${btEscHtml(c.email)}" style="color:#1a1f5e;">${btEscHtml(c.email)}</a></td>
        <td style="padding:10px 14px;white-space:nowrap;">${btEscHtml(c.phone)}</td>
        <td style="padding:10px 14px;font-size:15px;color:#5a6380;" title="${btEscHtml(c.message)}">${btEscHtml(msg)}</td>
        <td style="padding:10px 14px;white-space:nowrap;font-size:15px;color:#9ca3b8;">${dateStr}</td>
        <td style="padding:10px 14px;"><button onclick="btDeleteContact(${c.id})" style="background:#ffebee;color:#b71c1c;border:none;border-radius:4px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:'Barlow Condensed',sans-serif;letter-spacing:.05em;">DELETE</button></td>
      </tr>`;
    }).join('');
  } catch(e) {
    tbody.innerHTML = '<tr><td colspan="8" style="padding:40px;text-align:center;color:#b71c1c;">Error loading contacts</td></tr>';
  }
}

async function btDeleteContact(id) {
  if (!confirm('Delete this contact? This cannot be undone.')) return;
  try {
    await btFetch('/contacts/'+id, 'DELETE');
    btLoadContacts();
  } catch(e) { alert('Error deleting contact.'); }
}

/* ── EXCHANGES ──
   Rows are WooCommerce orders that contain the Exchange Shipping product.
   Woo owns the order; the portal owns only where the exchange physically is. */
let btExData = { statuses: {}, exchanges: [] };
let btExFilter = 'all';
let btExPoll = null;

/* The list is built from WooCommerce orders on every request — there is no
   import step, so "checking for new orders" just means calling this again.
   It runs only while the Exchanges tab is open: switching away clears the
   timer, so the rest of the portal never pays for it. */
const BT_EX_POLL_MS = 60000;

function btStartExPoll() {
  if (btExPoll) return;
  btExPoll = setInterval(btPollExchanges, BT_EX_POLL_MS);
}

function btStopExPoll() {
  clearInterval(btExPoll);
  btExPoll = null;
}

async function btPollExchanges() {
  // Nothing to see, or someone is mid-edit — a re-render would throw away
  // half-typed tracking numbers and notes.
  if (document.hidden) return;
  if (!document.getElementById('bt-tab-exchanges').classList.contains('active')) return;
  const a = document.activeElement;
  if (a && a.closest && a.closest('#bt-tab-exchanges') && (a.tagName === 'INPUT' || a.tagName === 'SELECT')) return;

  try {
    const fresh = await btFetch('/exchanges');
    const known = new Set((btExData.exchanges || []).map(x => x.order_id));
    const added = (fresh.exchanges || []).filter(x => !known.has(x.order_id) && !x.hidden);

    // Only repaint when something actually moved, so the table doesn't flicker
    // under someone once a minute for no reason.
    if (JSON.stringify(fresh) === JSON.stringify(btExData)) return;

    btExData = fresh;
    btRenderExchanges();
    if (added.length) {
      btToast(added.length === 1
        ? 'New exchange in: <strong>#' + btEscHtml(added[0].number) + '</strong>.'
        : '<strong>' + added.length + '</strong> new exchanges came in.', 'good');
    }
  } catch (e) { /* transient — the next tick tries again */ }
}

function btEscHtml(s) {
  return String(s == null ? '' : s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

async function btLoadExchanges() {
  const tbody = document.getElementById('btExBody');
  tbody.innerHTML = '<tr><td colspan="14" style="padding:40px;text-align:center;color:#9ca3b8;">Loading...</td></tr>';
  try {
    btExData = await btFetch('/exchanges');
    btRenderExchanges();
  } catch(e) {
    const expired = String(e.message||'').indexOf('403') !== -1;
    tbody.innerHTML = '<tr><td colspan="14" style="padding:40px;text-align:center;color:#b71c1c;">' +
      (expired ? 'Session expired — reload the page.' : 'Error loading exchanges.') + '</td></tr>';
  }
}

function btSetExFilter(f) { btExFilter = f; btRenderExchanges(); }

/* Which pile a row belongs in. Hidden wins over everything; a cancelled or
   refunded order is not work in progress whatever its stored status says, so
   it never sits in Awaiting waiting on a box that isn't coming. An unpaid
   order isn't a live exchange yet either — it rejoins the queue on its own
   the moment Woo says it's paid. */
function btExBucket(x) {
  if (x.hidden) return 'hidden';
  if (x.cancelled) return 'cancelled';
  if (x.unpaid) return 'unpaid';
  return x.status;
}

function btRenderExchanges() {
  const statuses = btExData.statuses || {};
  const all = btExData.exchanges || [];
  const labels = Object.assign({ unpaid: 'Unpaid', cancelled: 'Cancelled', hidden: 'Hidden' }, statuses);

  const live = all.filter(x => !x.hidden);
  const counts = { all: live.length };
  Object.keys(statuses).forEach(k => counts[k] = all.filter(x => btExBucket(x) === k).length);
  counts.unpaid    = all.filter(x => btExBucket(x) === 'unpaid').length;
  counts.cancelled = all.filter(x => btExBucket(x) === 'cancelled').length;
  counts.hidden    = all.filter(x => x.hidden).length;

  const order = ['all'].concat(Object.keys(statuses), ['unpaid', 'cancelled', 'hidden']);
  document.getElementById('btExFilters').innerHTML = order.map(k => {
    const label = k === 'all' ? 'All' : labels[k];
    return '<button class="ex-filter' + (btExFilter === k ? ' active' : '') + '" onclick="btSetExFilter(\'' + k + '\')">' +
           btEscHtml(label) + ' (' + (counts[k] || 0) + ')</button>';
  }).join('');

  const rows = btExFilter === 'all' ? live : all.filter(x => btExBucket(x) === btExFilter);
  const tbody = document.getElementById('btExBody');

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="14" style="padding:40px;text-align:center;color:#9ca3b8;font-style:italic;">' +
      (all.length ? 'Nothing in this status.' : 'No exchange orders yet.') + '</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(x => {
    const bucket = btExBucket(x);
    const dim = (bucket === 'awaiting' || bucket === 'received' || bucket === 'shipped') ? '' : 'opacity:.55;';

    const d = new Date((x.date || '').replace(' ', 'T'));
    const dateStr = isNaN(d) ? '' : d.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});

    // "Exchanging X for Y" reads as two columns so the eye can run down what
    // came in and what goes out. The pairs are rendered in lockstep, one block
    // per item, so a multi-item request stays aligned across both cells.
    /* One row per exchange, but a request can hold up to three items. Rather
       than splitting into extra table rows — which would break the rowspan of
       Status, Tracking and Notes — each of the five item columns stacks its
       values in the same order, so line 2 of Product lines up with line 2 of
       Size, Color, Qty and New Size. */
    const req = x.request || {};
    const dash = '<span class="ex-none">&mdash;</span>';
    let cProduct, cSize, cColor, cQty, cNew;

    if (req.parsed && (req.items || []).length) {
      const stack = (fn, cls) => req.items.map(it =>
        '<div class="ex-pair ' + (cls || '') + '">' + (fn(it) || dash) + '</div>').join('');
      cProduct = stack(it => btEscHtml(it.name), 'ex-ordered');
      cSize    = stack(it => btEscHtml(it.size));
      cColor   = stack(it => btEscHtml(it.color));
      cQty     = stack(it => (it.qty > 1 ? '<span class="ex-qty">' + it.qty + '</span>' : String(it.qty || 1)));
      cNew     = stack(it => btEscHtml(it.want), 'ex-wants');
    } else {
      // Nothing structured to lay out — say what there is in the Product cell
      // and dash the rest, rather than faking columns out of prose.
      cSize = cColor = cQty = cNew = dash;
      if (x.customer_note) {
        cProduct = '<div class="ex-raw">' + btEscHtml(x.customer_note) + '</div>';
      } else if ((x.extra || []).length) {
        // Whatever the form attached that doesn't map to a column. The order
        // number and School/Team are filtered out server-side — they have
        // their own columns and were being printed here a second time.
        cProduct = x.extra.map(m => '<div class="ex-orig">' + btEscHtml(m.key) +
          ': <strong>' + btEscHtml(m.value) + '</strong></div>').join('');
      } else {
        cProduct = '<span class="ex-none">No exchange details &mdash; ' +
                   '<a href="' + btEscHtml(x.edit_url) + '" target="_blank" rel="noopener" style="color:#1a1f5e;">open in Woo</a></span>';
      }
    }

    // Line items that aren't the shipping product, if the order ever has any.
    const items = (x.items || []).map(it =>
      '<div style="margin-bottom:6px;"><span style="font-weight:600;">' + btEscHtml(it.name) +
      '</span> &times;' + it.qty + (it.meta || []).map(m =>
        '<div style="font-size:14px;color:#5a6380;">' + btEscHtml(m.key) + ': ' + btEscHtml(m.value) + '</div>'
      ).join('') + '</div>'
    ).join('');

    const store = x.store
      ? '<span class="ex-store">' + btEscHtml(x.store) + '</span>'
      : '<span class="ex-none">&mdash;</span>';

    /* How it travels, in each direction. Mail in and ship back is the default
       and stays grey; drop off and pickup are the ones that change what staff
       physically do, so those carry the colour. */
    const m = x.methods || {};
    const cIn  = (m.send === 'dropoff')
      ? '<div class="ex-way drop">Drop off</div>'
      : '<div class="ex-way">Mailed</div>';
    /* Tracking belongs to the outbound leg, so it lives under it — and only
       on rows that actually get shipped. A pickup has nothing in the post. */
    const cOut = (m.return === 'pickup')
      ? '<div class="ex-way pickup">Pickup</div>'
      : '<div class="ex-way">Ship</div>' +
        '<input class="ex-input ex-track" value="' + btEscHtml(x.tracking) + '" placeholder="Tracking #" ' +
        'onchange="btSaveExchange(' + x.order_id + ', {tracking:this.value})">';

    /* The customer's own order number, and which platform it belongs to.
       9 digits from 1 is OrderMyGear, 7 digits from 8 is Chipply; the badge is
       left off rather than guessed when it is neither. */
    const srcCls = { 'OMG': 'omg', 'Chipply': 'chip' };
    const origCell = x.original_order
      ? '<div class="ex-orignum">#' + btEscHtml(x.original_order) + '</div>' +
        (x.source ? '<div class="ex-src ' + (srcCls[x.source] || '') + '">' + btEscHtml(x.source) + '</div>' : '')
      : dash;

    const updated = x.updated_at
      ? '<div style="font-size:13px;color:#9ca3b8;margin-top:4px;">' + btEscHtml(x.updated_by || '—') + ' &middot; ' +
        btEscHtml((x.updated_at || '').slice(5, 10)) + '</div>'
      : '';

    // Cancelled or unpaid gets a pill reading Woo's own wording, not a
    // dropdown: there is nothing to move through the shop yet, and nobody
    // should be able to march it forward to Shipped.
    const statusCell = (x.cancelled || x.unpaid)
      ? '<span class="ex-pill ' + (x.cancelled ? 'cancelled' : 'unpaid') + '">' + btEscHtml(x.woo_status_lbl) + '</span>'
      : '<select class="ex-select" onchange="btSaveExchange(' + x.order_id + ', {status:this.value})">' +
          Object.keys(statuses).filter(k => {
            /* A shipped exchange and a collected one are different endings, and
               only one of them applies to a given row. Offering both invites
               someone to mark a pickup as Shipped. The row's own status always
               stays in the list, so nothing that is already set can vanish. */
            if (k === 'shipped'      && m.return === 'pickup' && x.status !== k) return false;
            if (k === 'ready_pickup' && m.return !== 'pickup' && x.status !== k) return false;
            return true;
          }).map(k =>
            '<option value="' + k + '"' + (x.status === k ? ' selected' : '') + '>' + btEscHtml(statuses[k]) + '</option>'
          ).join('') + '</select>';

    const action = x.hidden
      ? '<button class="ex-action" onclick="btSaveExchange(' + x.order_id + ', {hidden:0})">Restore</button>'
      : '<button class="ex-action" onclick="btSaveExchange(' + x.order_id + ', {hidden:1})" title="Hide from the list — nothing is deleted">Hide</button>';

    return '<tr style="' + dim + '">' +
      '<td><a href="' + btEscHtml(x.edit_url) + '" target="_blank" rel="noopener" style="color:#1a1f5e;font-weight:700;">#' + btEscHtml(x.number) + '</a>' +
        '<div style="font-size:14px;color:#9ca3b8;">' + dateStr + '</div>' +
        '<div style="font-size:14px;color:#5a6380;">' + btEscHtml(x.woo_status_lbl) + '</div></td>' +
      '<td>' + origCell + '</td>' +
      '<td><div style="font-weight:600;">' + btEscHtml(x.customer) + '</div>' +
        '<a href="mailto:' + btEscHtml(x.email) + '" style="color:#1a1f5e;font-size:14px;">' + btEscHtml(x.email) + '</a>' +
        '<div style="font-size:14px;color:#5a6380;">' + btEscHtml(x.phone) + '</div>' +
        '<div style="font-size:13px;color:#9ca3b8;margin-top:4px;">' + btEscHtml(x.address) + '</div></td>' +
      '<td class="ex-g ex-g1">' + store + '</td>' +
      '<td class="ex-g">' + items + cProduct + '</td>' +
      '<td class="ex-c ex-g">' + cSize + '</td>' +
      '<td class="ex-c ex-g">' + cColor + '</td>' +
      '<td class="ex-c ex-g">' + cQty + '</td>' +
      '<td class="ex-c ex-g ex-g2">' + cNew + '</td>' +
      '<td class="ex-c">' + cIn + '</td>' +
      '<td class="ex-c ex-out">' + cOut + '</td>' +
      '<td>' + statusCell + updated + '</td>' +
      '<td><input class="ex-input" value="' + btEscHtml(x.notes) + '" placeholder="Notes" ' +
        'onchange="btSaveExchange(' + x.order_id + ', {notes:this.value})"></td>' +
      '<td>' + action + '</td>' +
    '</tr>';
  }).join('');
}

async function btSaveExchange(orderId, patch) {
  const row = (btExData.exchanges || []).find(x => x.order_id === orderId);
  if (!row) return;

  /* Marking Shipped mails the customer immediately, so an empty tracking box
     is worth one question — they would otherwise get a parcel notice with no
     number in it. Declining re-renders, which puts the dropdown back where it
     was rather than leaving it showing a status that was never saved. */
  if (patch.status === 'shipped' && !String(row.tracking || '').trim()) {
    const go = confirm('No Tracking Number Entered.\n\nAre you sure you want to mark this order as shipped and notify the customer?');
    if (!go) { btRenderExchanges(); return; }
  }

  /* Ready for Pickup emails immediately too, and it tells the customer to
     drive to Oswego. Worth the same one question as Shipped. */
  if (patch.status === 'ready_pickup' && row.status !== 'ready_pickup') {
    const go = confirm('Mark this exchange READY FOR PICKUP?\n\n' +
      'This emails ' + (row.customer || 'the customer') + ' that it is finished and waiting at the shop.\n' +
      'Make sure it is actually bagged and on the pickup shelf.');
    if (!go) { btRenderExchanges(); return; }
  }

  btSaving(true);
  try {
    const saved = await btFetch('/exchanges/' + orderId, 'POST', Object.assign({
      status: row.status, tracking: row.tracking, notes: row.notes, hidden: row.hidden, user_name: btUserName
    }, patch));
    Object.assign(row, saved);
    btRenderExchanges();
    // Staff should never be surprised that the customer got mail.
    if (saved.emailed === 'received') {
      btToast('Emailed the customer that we received their items.', 'good');
    } else if (saved.emailed === 'shipped' || saved.emailed === 'shipped-tracking') {
      btToast(row.tracking
        ? 'Emailed the customer that it shipped, with tracking <strong>' + btEscHtml(row.tracking) + '</strong>.'
        : 'Emailed the customer that it shipped. Add a tracking number and they\'ll get it automatically.', 'good');
    } else if (saved.emailed === 'ready_pickup') {
      btToast('Emailed the customer that it\'s <strong>ready to pick up</strong> at the shop.', 'good');
    }
  } catch(e) {
    alert('Could not save the exchange. ' + (String(e.message||'').indexOf('403') !== -1 ? 'Reload the page and try again.' : ''));
  } finally {
    btSaving(false);
  }
}

/* ── INIT ── */
(async function() {
  // The server resolves /employees/exchanges; btTabFromUrl covers the ?tab=
  // fallback and the window between saving the page and the rewrite rules
  // being flushed.
  const startTab = (BT_ROUTE.initial && BT_ROUTE.initial !== 'schedule') ? BT_ROUTE.initial : btTabFromUrl();
  if (startTab && startTab !== 'schedule') btSwitchTab(startTab, false);

  // The old localStorage name picker is retired — clear the leftover key so a
  // stale browser can't reintroduce a name that isn't the signed-in account.
  try { localStorage.removeItem('btUserName'); } catch(e) {}
  try {
    const savedCollapsed = localStorage.getItem('btCollapsedCats');
    if (savedCollapsed) btCollapsedCats = new Set(JSON.parse(savedCollapsed));
  } catch(e) {}
  // One-time migration: old localStorage day notes → shared DB
  try {
    const oldNotes = localStorage.getItem('btDayNotes');
    if (oldNotes) {
      const parsed = JSON.parse(oldNotes);
      const entries = Object.entries(parsed).filter(([k,v]) => v && v.trim());
      for (const [date, note] of entries) {
        await btFetch('/day-notes', 'POST', {date, note, user_name: btUserName || 'migration'}).catch(()=>{});
      }
      localStorage.removeItem('btDayNotes');
    }
  } catch(e) {}
  try {
    await Promise.all([btLoadJobs(), btLoadDayNotes(), btLoadClosedDays()]);
    btRenderBoard();
    btCheckOverdue();
  } catch(e) {
    document.getElementById('btBoard').innerHTML = '<div style="padding:40px;text-align:center;color:#b71c1c;width:100%;">Error loading schedule. Refresh to try again.</div>';
    console.error('BT Schedule init error:', e);
  }

  // Auto-refresh every 15 seconds so all stations stay in sync
  setInterval(async () => {
    // Skip refresh while user is editing (modal open or dragging)
    if (document.getElementById('btpJobModalOverlay').classList.contains('open')) return;
    if (document.getElementById('btStoreModalOverlay').classList.contains('open')) return;
    if (document.querySelector('.job-card.dragging')) return;
    if (btDragSaving) return;
    if (document.activeElement && document.activeElement.classList.contains('day-note-input')) return;
    try {
      await Promise.all([btLoadJobs(), btLoadDayNotes(), btLoadClosedDays()]);
      btRenderBoard();
      if (document.getElementById('bt-tab-stores').classList.contains('active')) {
        await btLoadAndRenderStores();
      }
    } catch(e) {}
  }, 15000);
})();

/* ── PRINTAVO DEEP LINKS ── */
(function () {
  const ENDPOINT = '<?php echo esc_js( home_url("/wp-json/boomerts/v1/printavo-link/") ); ?>';
  const linkCache = {};

  async function resolveOrder(orderNum) {
    if (linkCache[orderNum]) return linkCache[orderNum];
    try {
      const r = await fetch(ENDPOINT + encodeURIComponent(orderNum));
      const data = await r.json();
      if (data && data.url) {
        linkCache[orderNum] = data.url;
        return data.url;
      }
    } catch (e) {}
    return null;
  }

  function linkify(el) {
    if (el.dataset.printavoDone) return;
    el.dataset.printavoDone = '1';
    const orderNum = (el.dataset.orderNumber || '').trim();
    if (!orderNum || !/^\d+$/.test(orderNum)) return;
    el.style.cursor = 'pointer';
    el.style.textDecoration = 'underline';
    el.style.textDecorationStyle = 'dotted';
    el.style.textUnderlineOffset = '2px';
    el.addEventListener('click', async function (ev) {
      ev.stopPropagation();
      ev.preventDefault();
      const url = await resolveOrder(orderNum);
      if (url) window.open(url, '_blank');
      else alert('Order #' + orderNum + ' not found in Printavo.');
    });
  }

  function scan() {
    document.querySelectorAll('[data-order-number]').forEach(linkify);
  }

  scan();
  const observer = new MutationObserver(scan);
  observer.observe(document.body, { childList: true, subtree: true });
})();
</script>

<?php
    return ob_get_clean();
});
