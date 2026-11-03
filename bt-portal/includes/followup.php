<?php
/**
 * BT Portal - Follow Up (Other > Follow Up).
 *
 * Most of this shop's work comes back every year: the same school, the same
 * event, the same shirts, roughly the same week. Nobody was tracking that, so
 * a reorder only happened when the customer remembered first. This page lists
 * who to reach out to about a month before their event comes round again, and
 * sends the email from orders@boomerts.com without leaving the portal.
 *
 * - Past orders come from Printavo (GraphQL v2), on WP cron. No AI anywhere.
 * - The window, the email and the store-line cutoff are all settings.
 * - Nothing sends itself. Send opens an editable preview and a person clicks.
 *
 * -- Who shows up ----------------------------------------------------------
 *
 * Orders are grouped into (contact email, event). The group's LAST order is
 * the one that matters: take its month and day, roll it forward to the next
 * time that date comes round, and if that lands inside the window (0 to 45
 * days out by default) the event is due. Checking the last order rather than
 * any order is what stops an event that already ran again in June from
 * reappearing every November.
 *
 * A card is a person, not an event, because one person running three teams
 * should get one email listing all three rather than three emails. So the
 * card is keyed by contact email and carries every event of theirs that is
 * due, and the card sorts on its soonest event.
 *
 * A card drops out on its own once that customer orders again: any order for
 * the same email or the same company, due later than the order that opened
 * the window, counts as the reorder. Matching the company as well is what
 * catches the reorder that came in under the athletic director instead of the
 * coach. The card is kept, marked "Reordered #NNNNN" and hidden from the
 * working filters rather than deleted, so the history stays readable.
 *
 * -- Event names -----------------------------------------------------------
 *
 * Printavo has no event field, so the name is a guess: the imprint details
 * first (what is actually printed on the garment), then line item
 * descriptions, then the invoice nickname. Guesses are stored per order and
 * staff can rename one; the rename lives in its own column and the sync never
 * writes that column, so it survives every later sync. Reading text off the
 * artwork itself is out of scope here.
 *
 * -- Sending ---------------------------------------------------------------
 *
 * orders@boomerts.com is a Microsoft 365 shared mailbox, so mail goes through
 * the Graph sendMail call on an app registration (application permission
 * Mail.Send, narrowed to that one mailbox with an ApplicationAccessPolicy),
 * with saveToSentItems so the shop's own Sent Items shows what went out.
 * See followup-mail.php and the setup notes in CLAUDE.md.
 */
if (!defined('ABSPATH')) exit;

define('BTP_FU_DB_VERSION', '1');
define('BTP_FU_BACKFILL_FROM', '2020-01-01');
define('BTP_FU_PAGE_SIZE', 25);          // Printavo caps `first` at 25.
define('BTP_FU_PAGES_PER_TICK', 8);      // Keeps one cron run well short of PHP's limit.

/* =========================================================================
   TABLES
   ========================================================================= */

function btp_fu_table($name) { global $wpdb; return $wpdb->prefix . 'bt_fu_' . $name; }

function btp_fu_install() {
    if (get_option('btp_fu_db_version') === BTP_FU_DB_VERSION) return;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $GLOBALS['wpdb']->get_charset_collate();

    /* One row per Printavo order. event_manual is never written by the sync. */
    dbDelta("CREATE TABLE IF NOT EXISTS " . btp_fu_table('orders') . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        visual_id VARCHAR(32) NOT NULL DEFAULT '',
        printavo_id VARCHAR(64) NOT NULL DEFAULT '',
        nickname VARCHAR(255) NOT NULL DEFAULT '',
        due_at DATE NULL,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        qty INT NOT NULL DEFAULT 0,
        contact_name VARCHAR(190) NOT NULL DEFAULT '',
        contact_email VARCHAR(190) NOT NULL DEFAULT '',
        contact_phone VARCHAR(60) NOT NULL DEFAULT '',
        company VARCHAR(190) NOT NULL DEFAULT '',
        event_guess VARCHAR(190) NOT NULL DEFAULT '',
        event_manual VARCHAR(190) NOT NULL DEFAULT '',
        source_text TEXT NULL,
        synced_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY visual_id (visual_id),
        KEY contact_email (contact_email),
        KEY company (company),
        KEY due_at (due_at)
    ) $charset;");

    /* One row per person per cycle. The cycle year is the year the upcoming
       event falls in, so last year's card is never reused for this year. */
    dbDelta("CREATE TABLE IF NOT EXISTS " . btp_fu_table('cards') . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        contact_email VARCHAR(190) NOT NULL DEFAULT '',
        cycle_year SMALLINT NOT NULL DEFAULT 0,
        status VARCHAR(24) NOT NULL DEFAULT 'to_contact',
        emailed_at DATETIME NULL,
        emailed_by VARCHAR(120) NOT NULL DEFAULT '',
        snooze_until DATE NULL,
        notes TEXT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY person_cycle (contact_email, cycle_year)
    ) $charset;");

    dbDelta("CREATE TABLE IF NOT EXISTS " . btp_fu_table('log') . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        card_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        at DATETIME NULL,
        who VARCHAR(120) NOT NULL DEFAULT '',
        kind VARCHAR(24) NOT NULL DEFAULT '',
        detail TEXT NULL,
        PRIMARY KEY (id),
        KEY card_id (card_id)
    ) $charset;");

    update_option('btp_fu_db_version', BTP_FU_DB_VERSION);
}
add_action('init', 'btp_fu_install', 2);

/* =========================================================================
   SETTINGS
   ========================================================================= */

function btp_fu_window_days() { return max(1, (int) get_option('btp_fu_window_days', 45)); }
function btp_fu_store_weeks() { return max(0, (int) get_option('btp_fu_store_weeks', 6)); }
function btp_fu_sender()      { return trim((string) get_option('btp_fu_sender', 'orders@boomerts.com')); }

function btp_fu_store_line() {
    return (string) get_option('btp_fu_store_line',
        " We can also set up an online store if it's easier to have everyone order on their own.");
}

function btp_fu_actor() {
    return function_exists('btp_actor_name') ? btp_actor_name() : wp_get_current_user()->display_name;
}

function btp_fu_today() {
    /* The shop's day, not UTC. Same reason dtf-jobs.php carries its own zone:
       this site's WordPress timezone is still the Lightsail default. */
    $tz = defined('BTP_DTF_TZ') ? BTP_DTF_TZ : 'America/Chicago';
    try { $now = new DateTimeImmutable('now', new DateTimeZone($tz)); }
    catch (Exception $e) { $now = new DateTimeImmutable('now'); }
    return $now->format('Y-m-d');
}

/* =========================================================================
   EVENT NAME: a guess from what was actually printed
   ========================================================================= */

function btp_fu_norm($s) {
    return strtolower(preg_replace('/\s+/', ' ', trim((string) $s)));
}

/** Words that describe the garment or the job, never the event. */
function btp_fu_stopwords() {
    return apply_filters('btp_fu_stopwords', array(
        'front', 'back', 'left chest', 'right chest', 'full back', 'full front', 'sleeve',
        'screen print', 'screenprint', 'embroidery', 'embroidered', 'dtf', 'digital',
        'transfer', 'transfers', 'vinyl', 'heat press', 'print', 'printing', 'logo',
        'art', 'artwork', 'design', 'tee', 'tees', 't-shirt', 'tshirt', 'shirt', 'shirts',
        'hoodie', 'hoodies', 'crewneck', 'sweatshirt', 'long sleeve', 'short sleeve',
        'polo', 'hat', 'hats', 'cap', 'caps', 'tote', 'bag', 'bags', 'color', 'colors',
        'white', 'black', 'navy', 'red', 'royal', 'gray', 'grey', 'reorder', 're-order',
        'order', 'invoice', 'quote', 'qty', 'ink', 'thread', 'n/a', 'none', 'tbd',
    ));
}

/**
 * Best guess at the event, most specific source first.
 *
 * Imprint details lead because that text is what goes on the garment, so it
 * names the team or the trip far more often than anything else on the
 * invoice. Line item descriptions next, and the nickname last, because a
 * nickname is whoever typed the invoice being brief.
 */
function btp_fu_guess_event($imprints, $line_items, $nickname) {
    foreach (array((array) $imprints, (array) $line_items, array($nickname)) as $source) {
        foreach ($source as $raw) {
            $clean = btp_fu_clean_event_text($raw);
            if ($clean !== '') return $clean;
        }
    }
    return '';
}

function btp_fu_clean_event_text($raw) {
    $s = trim(preg_replace('/\s+/', ' ', (string) $raw));
    if ($s === '') return '';

    /* Imprint details read like "Front - NCHS Basketball" or "1 color / NCHS
       Basketball". Split on the separators and keep the longest piece, which
       is the one carrying a name rather than a placement or a count. */
    $parts = preg_split('/\s*[\/|,]\s*|\s+-\s+|\s*:\s*/u', $s);
    $best  = '';
    $stops = btp_fu_stopwords();

    foreach ((array) $parts as $p) {
        $p = trim($p, " \t\n\r-,.");
        if ($p === '' || is_numeric($p)) continue;
        if (in_array(btp_fu_norm($p), $stops, true)) continue;
        /* "1 color", "2 locations" and friends are job spec, not an event. */
        if (preg_match('/^\d+\s*(color|colour|loc|location|pc|pcs|piece)s?$/i', $p)) continue;
        if (preg_match('/^\d+$/', preg_replace('/\D/', '', $p)) && !preg_match('/[a-z]/i', $p)) continue;
        if (mb_strlen($p) > mb_strlen($best)) $best = $p;
    }

    if ($best === '') return '';
    if (mb_strlen($best) < 3 || mb_strlen($best) > 80) return '';
    return btp_fu_title_case($best);
}

function btp_fu_title_case($s) {
    /* Anything already mixed case is left alone: "NCHS Basketball" is right as
       typed, and title-casing it would give "Nchs Basketball". */
    if ($s !== mb_strtoupper($s, 'UTF-8') && $s !== mb_strtolower($s, 'UTF-8')) return $s;
    $out = array();
    foreach (preg_split('/\s+/', $s) as $w) {
        /* A short all-caps word is an acronym (NCHS, PE, JV); keep it. */
        $out[] = (mb_strlen($w) <= 4 && $w === mb_strtoupper($w, 'UTF-8'))
            ? $w
            : mb_convert_case($w, MB_CASE_TITLE, 'UTF-8');
    }
    return implode(' ', $out);
}

/** What the page and the email call this order's event. */
function btp_fu_event_label($row) {
    $manual = isset($row['event_manual']) ? trim((string) $row['event_manual']) : '';
    if ($manual !== '') return $manual;
    $guess = isset($row['event_guess']) ? trim((string) $row['event_guess']) : '';
    if ($guess !== '') return $guess;
    return 'Last order';
}

function btp_fu_event_key($row) { return btp_fu_norm(btp_fu_event_label($row)); }

/**
 * Every order that a rename should move with it.
 *
 * A person renames the EVENT, not the invoice. Renaming one order on its own
 * would split its group, and the orders left behind would regroup under the
 * old name and show up as a second event on the same card, due the same week.
 * So a rename takes every order of that customer's that currently reads the
 * same. Returns visual_ids, including the one asked for.
 */
function btp_fu_rename_targets($rows, $visual_id) {
    $target = null;
    foreach ($rows as $r) {
        if ((string) $r['visual_id'] === (string) $visual_id) { $target = $r; break; }
    }
    if (!$target) return array();

    $email = btp_fu_norm($target['contact_email']);
    $key   = btp_fu_event_key($target);
    $out   = array();
    foreach ($rows as $r) {
        if (btp_fu_norm($r['contact_email']) !== $email) continue;
        if (btp_fu_event_key($r) !== $key) continue;
        $out[] = (string) $r['visual_id'];
    }
    return $out;
}

/* =========================================================================
   THE WINDOW
   ========================================================================= */

/**
 * The next time this month and day comes round, counted from $today.
 *
 * Feb 29 rolls to Mar 1 in a common year rather than being dropped: the event
 * is real and still wants an email. Returns Y-m-d, or '' if the date is junk.
 */
function btp_fu_next_anniversary($due_date, $today) {
    /* An empty string is 'now' to date_create_immutable, not a failure, so a
       row with no due date would otherwise come back as due today. */
    if (!btp_fu_is_ymd($due_date) || !btp_fu_is_ymd($today)) return '';
    $due = date_create_immutable((string) $due_date);
    $now = date_create_immutable((string) $today);
    if (!$due || !$now) return '';

    $m = (int) $due->format('n');
    $d = (int) $due->format('j');

    for ($i = 0; $i <= 1; $i++) {
        $y     = (int) $now->format('Y') + $i;
        $m_use = $m;
        $d_use = $d;
        if ($m === 2 && $d === 29 && !checkdate(2, 29, $y)) { $m_use = 3; $d_use = 1; }
        $cand = sprintf('%04d-%02d-%02d', $y, $m_use, $d_use);
        if ($cand >= $now->format('Y-m-d')) return $cand;
    }
    return '';
}

function btp_fu_is_ymd($s) {
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $s);
}

function btp_fu_days_between($from, $to) {
    if (!btp_fu_is_ymd($from) || !btp_fu_is_ymd($to)) return null;
    $a = date_create_immutable((string) $from);
    $b = date_create_immutable((string) $to);
    if (!$a || !$b) return null;
    return (int) $a->diff($b)->format('%r%a');
}

/** Days out if the event is inside the window, otherwise null. */
function btp_fu_days_out_if_due($last_due, $today, $window_days) {
    $next = btp_fu_next_anniversary($last_due, $today);
    if ($next === '') return null;
    $out = btp_fu_days_between($today, $next);
    if ($out === null || $out < 0 || $out > $window_days) return null;
    return $out;
}

/** Which filter bucket a number of days out belongs to. */
function btp_fu_bucket($days_out) {
    if ($days_out <= 7)  return 'due_now';
    if ($days_out <= 14) return 'two_weeks';
    return 'later';
}

/* =========================================================================
   BUILDING THE LIST
   ========================================================================= */

/**
 * Every card for today: a person, their due events, and their tracking state.
 *
 * Grouping happens in PHP rather than SQL because the rule is "the group's
 * LAST order decides", and expressing that plus the anniversary roll in
 * portable SQL costs more than it saves on a table this size.
 */
function btp_fu_build_cards($today = '', $window_days = 0) {
    global $wpdb;
    $today       = $today ?: btp_fu_today();
    $window_days = $window_days ?: btp_fu_window_days();
    $orders      = btp_fu_table('orders');

    $rows = $wpdb->get_results(
        "SELECT * FROM $orders WHERE contact_email <> '' AND due_at IS NOT NULL ORDER BY due_at ASC",
        ARRAY_A
    );
    if (!$rows) return array();

    return btp_fu_cards_from_rows($rows, $today, $window_days);
}

/**
 * Pure: rows in, cards out. Split off the query so the rules can be tested
 * without a database.
 */
function btp_fu_cards_from_rows($rows, $today, $window_days) {
    $by_person  = array();   // email => rows
    $by_company = array();   // company key => rows

    foreach ($rows as $r) {
        $email = btp_fu_norm($r['contact_email']);
        if ($email === '') continue;
        $by_person[$email][] = $r;
        $ck = btp_fu_norm($r['company']);
        if ($ck !== '') $by_company[$ck][] = $r;
    }

    $cards = array();

    foreach ($by_person as $email => $person_rows) {
        /* (person, event) groups; the group's last order is what counts. */
        $groups = array();
        foreach ($person_rows as $r) {
            $groups[btp_fu_event_key($r)][] = $r;
        }

        $due_events = array();
        foreach ($groups as $key => $group) {
            usort($group, function ($a, $b) { return strcmp((string) $a['due_at'], (string) $b['due_at']); });
            $last = $group[count($group) - 1];

            $days_out = btp_fu_days_out_if_due($last['due_at'], $today, $window_days);
            if ($days_out === null) continue;

            $due_events[] = array(
                'event'     => btp_fu_event_label($last),
                'event_key' => $key,
                'last_due'  => $last['due_at'],
                'next_due'  => btp_fu_next_anniversary($last['due_at'], $today),
                'days_out'  => $days_out,
                'orders'    => array_map('btp_fu_order_out', array_reverse($group)),
            );
        }
        if (!$due_events) continue;

        usort($due_events, function ($a, $b) { return $a['days_out'] - $b['days_out']; });

        /* Reordered? Anything for this person or their company due later than
           the newest order that opened a window. */
        $latest_trigger = '';
        foreach ($due_events as $e) {
            if ($e['last_due'] > $latest_trigger) $latest_trigger = $e['last_due'];
        }

        $candidates = $person_rows;
        $company    = '';
        foreach ($person_rows as $r) {
            if (trim((string) $r['company']) !== '') { $company = $r['company']; break; }
        }
        $ck = btp_fu_norm($company);
        if ($ck !== '' && isset($by_company[$ck])) {
            $candidates = array_merge($candidates, $by_company[$ck]);
        }

        $reorder = null;
        foreach ($candidates as $r) {
            if ((string) $r['due_at'] <= $latest_trigger) continue;
            if ($reorder === null || (string) $r['due_at'] > (string) $reorder['due_at']) $reorder = $r;
        }

        $first = $due_events[0];
        $cards[] = array(
            'contact_email' => $person_rows[0]['contact_email'],
            'contact_name'  => btp_fu_pick_name($person_rows),
            'contact_phone' => btp_fu_pick_field($person_rows, 'contact_phone'),
            'company'       => $company,
            'cycle_year'    => (int) substr($first['next_due'], 0, 4),
            'next_due'      => $first['next_due'],
            'days_out'      => $first['days_out'],
            'bucket'        => btp_fu_bucket($first['days_out']),
            'events'        => $due_events,
            'reorder'       => $reorder ? array(
                'visual_id' => $reorder['visual_id'],
                'due_at'    => $reorder['due_at'],
            ) : null,
        );
    }

    usort($cards, function ($a, $b) {
        $c = strcmp($a['next_due'], $b['next_due']);
        return $c !== 0 ? $c : strcmp($a['contact_email'], $b['contact_email']);
    });

    return $cards;
}

/** The most recent non-empty value, since older invoices carry stale details. */
function btp_fu_pick_field($rows, $field) {
    $val = '';
    foreach ($rows as $r) {
        if (trim((string) $r[$field]) !== '') $val = trim((string) $r[$field]);
    }
    return $val;
}

function btp_fu_pick_name($rows) {
    return btp_fu_pick_field($rows, 'contact_name');
}

function btp_fu_order_out($r) {
    return array(
        'visual_id'    => (string) $r['visual_id'],
        'printavo_id'  => (string) $r['printavo_id'],
        'event'        => btp_fu_event_label($r),
        'event_manual' => (string) $r['event_manual'],
        'event_guess'  => (string) $r['event_guess'],
        'due_at'       => (string) $r['due_at'],
        'qty'          => (int) $r['qty'],
        'total'        => (float) $r['total'],
    );
}

/** First name for the greeting, or '' when there is no name on file. */
function btp_fu_first_name($full) {
    $full = trim(preg_replace('/\s+/', ' ', (string) $full));
    if ($full === '') return '';
    /* "Smith, Jane" happens in imported contacts. */
    if (strpos($full, ',') !== false) {
        $bits = array_map('trim', explode(',', $full));
        if (count($bits) >= 2 && $bits[1] !== '') $full = $bits[1];
    }
    $parts = explode(' ', $full);
    $first = $parts[0];
    /* An email address in the name field is not a name. */
    if (strpos($first, '@') !== false) return '';
    return $first;
}

/* =========================================================================
   TRACKING STATE
   ========================================================================= */

function btp_fu_card_state($email, $cycle_year) {
    global $wpdb;
    $t   = btp_fu_table('cards');
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $t WHERE contact_email = %s AND cycle_year = %d",
        $email, $cycle_year
    ), ARRAY_A);
    if (!$row) {
        return array(
            'id' => 0, 'status' => 'to_contact', 'emailed_at' => null, 'emailed_by' => '',
            'snooze_until' => null, 'notes' => '', 'log' => array(),
        );
    }
    $row['log'] = btp_fu_card_log((int) $row['id']);
    return $row;
}

function btp_fu_card_id($email, $cycle_year, $create = true) {
    global $wpdb;
    $t  = btp_fu_table('cards');
    $id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $t WHERE contact_email = %s AND cycle_year = %d", $email, $cycle_year
    ));
    if ($id || !$create) return $id;

    $now = current_time('mysql');
    $wpdb->insert($t, array(
        'contact_email' => $email,
        'cycle_year'    => $cycle_year,
        'status'        => 'to_contact',
        'created_at'    => $now,
        'updated_at'    => $now,
    ));
    return (int) $wpdb->insert_id;
}

function btp_fu_card_log($card_id) {
    global $wpdb;
    $t = btp_fu_table('log');
    return $wpdb->get_results($wpdb->prepare(
        "SELECT at, who, kind, detail FROM $t WHERE card_id = %d ORDER BY id DESC LIMIT 50", $card_id
    ), ARRAY_A) ?: array();
}

function btp_fu_log($card_id, $kind, $detail) {
    global $wpdb;
    $wpdb->insert(btp_fu_table('log'), array(
        'card_id' => (int) $card_id,
        'at'      => current_time('mysql'),
        'who'     => btp_fu_actor(),
        'kind'    => $kind,
        'detail'  => $detail,
    ));
}

function btp_fu_statuses() {
    return array('to_contact', 'emailed', 'replied', 'not_this_year', 'snoozed');
}

/* =========================================================================
   PRINTAVO SYNC
   ========================================================================= */

function btp_fu_sync_state() {
    $d = array(
        'mode'       => 'backfill',
        'cursor'     => '',          // Printavo endCursor inside the current window
        'before'     => '',          // walking backwards: current window's upper bound
        'done'       => false,
        'last_run'   => '',
        'last_error' => '',
        'seen'       => 0,
        'saved'      => 0,
    );
    $s = get_option('btp_fu_sync_state', array());
    return is_array($s) ? array_merge($d, $s) : $d;
}

function btp_fu_set_sync_state($patch) {
    update_option('btp_fu_sync_state', array_merge(btp_fu_sync_state(), $patch), false);
}

/**
 * One page of invoices.
 *
 * `first` is capped at 25 and the complexity budget is 25000, so every nested
 * connection carries its own `first` as well; without those the whole query is
 * rejected rather than trimmed.
 */
function btp_fu_query($before, $after_cursor, $since = '') {
    $q = 'query BTFollowUp($first: Int!, $after: String, $inAfter: ISO8601DateTime, $inBefore: ISO8601DateTime) {
      invoices(first: $first, after: $after, inProductionAfter: $inAfter, inProductionBefore: $inBefore) {
        pageInfo { hasNextPage endCursor }
        nodes {
          id
          visualId
          nickname
          dueAt
          total
          totalQuantity
          contact { fullName email phone customer { companyName } }
          lineItemGroups(first: 4) {
            nodes {
              imprints(first: 3) { nodes { details } }
              lineItems(first: 4) { nodes { description } }
            }
          }
        }
      }
    }';

    $vars = array('first' => BTP_FU_PAGE_SIZE);
    if ($after_cursor !== '') $vars['after']    = $after_cursor;
    if ($since !== '')        $vars['inAfter']  = $since;
    if ($before !== '')       $vars['inBefore'] = $before;

    return btp_printavo_gql($q, $vars);
}

/** Printavo returns connections as `nodes`; tolerate `edges { node }` too. */
function btp_fu_nodes($conn) {
    if (!is_array($conn)) return array();
    if (isset($conn['nodes']) && is_array($conn['nodes'])) return $conn['nodes'];
    if (isset($conn['edges']) && is_array($conn['edges'])) {
        $out = array();
        foreach ($conn['edges'] as $e) if (isset($e['node'])) $out[] = $e['node'];
        return $out;
    }
    return array();
}

function btp_fu_text_list($group_conn, $inner, $field) {
    $out = array();
    foreach (btp_fu_nodes($group_conn) as $g) {
        foreach (btp_fu_nodes(isset($g[$inner]) ? $g[$inner] : null) as $n) {
            $v = isset($n[$field]) ? trim((string) $n[$field]) : '';
            if ($v !== '') $out[] = $v;
        }
    }
    return $out;
}

/** One invoice node -> the row we store. Null when there is nothing usable. */
function btp_fu_row_from_node($node) {
    $visual = isset($node['visualId']) ? trim((string) $node['visualId']) : '';
    if ($visual === '') return null;

    $due = isset($node['dueAt']) ? substr((string) $node['dueAt'], 0, 10) : '';
    if ($due === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due = null;

    $contact  = isset($node['contact']) && is_array($node['contact']) ? $node['contact'] : array();
    $customer = isset($contact['customer']) && is_array($contact['customer']) ? $contact['customer'] : array();

    $groups   = isset($node['lineItemGroups']) ? $node['lineItemGroups'] : null;
    $imprints = btp_fu_text_list($groups, 'imprints', 'details');
    $items    = btp_fu_text_list($groups, 'lineItems', 'description');
    $nickname = isset($node['nickname']) ? (string) $node['nickname'] : '';

    return array(
        'visual_id'     => $visual,
        'printavo_id'   => isset($node['id']) ? (string) $node['id'] : '',
        'nickname'      => $nickname,
        'due_at'        => $due,
        'total'         => isset($node['total']) ? (float) $node['total'] : 0,
        'qty'           => isset($node['totalQuantity']) ? (int) $node['totalQuantity'] : 0,
        'contact_name'  => isset($contact['fullName']) ? (string) $contact['fullName'] : '',
        'contact_email' => isset($contact['email']) ? strtolower(trim((string) $contact['email'])) : '',
        'contact_phone' => isset($contact['phone']) ? (string) $contact['phone'] : '',
        'company'       => isset($customer['companyName']) ? (string) $customer['companyName'] : '',
        'event_guess'   => btp_fu_guess_event($imprints, $items, $nickname),
        'source_text'   => wp_json_encode(array('imprints' => $imprints, 'items' => $items)),
        'synced_at'     => current_time('mysql'),
    );
}

/**
 * Insert or update by visual_id. event_manual is left out of the column list
 * on purpose: a sync must never undo somebody's rename.
 */
function btp_fu_upsert($row) {
    global $wpdb;
    $t  = btp_fu_table('orders');
    $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE visual_id = %s", $row['visual_id']));
    if ($id) {
        $wpdb->update($t, $row, array('id' => $id));
        return 'updated';
    }
    $wpdb->insert($t, $row);
    return 'inserted';
}

/**
 * One cron tick.
 *
 * Backfill walks backwards a year at a time from today to BTP_FU_BACKFILL_FROM,
 * a few pages per tick, keeping its place in the option between runs. Once it
 * reaches the start it flips to incremental and only asks for invoices in
 * production since the last run. A 429 ends the tick quietly and the next one
 * picks up from the same cursor.
 */
function btp_fu_sync_tick($max_pages = 0) {
    $max_pages = $max_pages ?: BTP_FU_PAGES_PER_TICK;
    $state     = btp_fu_sync_state();
    $today     = btp_fu_today();
    $saved     = 0;
    $seen      = 0;

    if ($state['mode'] === 'backfill' && $state['before'] === '') {
        $state['before'] = $today . 'T23:59:59Z';
    }

    for ($page = 0; $page < $max_pages; $page++) {
        if ($state['mode'] === 'backfill') {
            $window_start = btp_fu_year_before($state['before']);
            $since = max($window_start, BTP_FU_BACKFILL_FROM . 'T00:00:00Z');
            $body  = btp_fu_query($state['before'], $state['cursor'], $since);
        } else {
            $since = $state['last_run'] !== ''
                ? gmdate('Y-m-d\TH:i:s\Z', strtotime($state['last_run']) - DAY_IN_SECONDS)
                : gmdate('Y-m-d\TH:i:s\Z', strtotime('-7 days'));
            $body = btp_fu_query('', $state['cursor'], $since);
        }

        if (is_wp_error($body)) {
            $msg = $body->get_error_message();
            btp_fu_set_sync_state(array('last_error' => $msg, 'last_run' => current_time('mysql')));
            return array('ok' => false, 'error' => $msg, 'seen' => $seen, 'saved' => $saved);
        }
        if (!empty($body['errors'])) {
            $msg = 'Printavo: ' . wp_json_encode($body['errors']);
            btp_fu_set_sync_state(array('last_error' => $msg, 'last_run' => current_time('mysql')));
            return array('ok' => false, 'error' => $msg, 'seen' => $seen, 'saved' => $saved);
        }

        $conn  = isset($body['data']['invoices']) ? $body['data']['invoices'] : null;
        $nodes = btp_fu_nodes($conn);

        foreach ($nodes as $node) {
            $seen++;
            $row = btp_fu_row_from_node($node);
            if ($row) { btp_fu_upsert($row); $saved++; }
        }

        $has_next = !empty($conn['pageInfo']['hasNextPage']);
        $cursor   = isset($conn['pageInfo']['endCursor']) ? (string) $conn['pageInfo']['endCursor'] : '';

        if ($has_next && $cursor !== '') {
            $state['cursor'] = $cursor;
            continue;
        }

        /* End of this window. */
        $state['cursor'] = '';
        if ($state['mode'] !== 'backfill') break;

        $next_before = btp_fu_year_before($state['before']);
        if (substr($next_before, 0, 10) <= BTP_FU_BACKFILL_FROM) {
            $state['mode'] = 'incremental';
            $state['done'] = true;
            break;
        }
        $state['before'] = $next_before;
    }

    btp_fu_set_sync_state(array(
        'mode'       => $state['mode'],
        'cursor'     => $state['cursor'],
        'before'     => $state['before'],
        'done'       => $state['done'],
        'last_run'   => current_time('mysql'),
        'last_error' => '',
        'seen'       => (int) $state['seen'] + $seen,
        'saved'      => (int) $state['saved'] + $saved,
    ));

    return array('ok' => true, 'seen' => $seen, 'saved' => $saved, 'mode' => $state['mode'], 'done' => $state['done']);
}

function btp_fu_year_before($iso) {
    $t = strtotime((string) $iso);
    if (!$t) $t = time();
    return gmdate('Y-m-d\TH:i:s\Z', strtotime('-1 year', $t));
}

/* Backfill wants a tick more often than once a day; once it is done, daily
   is plenty. Both run off the same hook so there is only one to clear. */
add_action('btp_fu_sync_event', 'btp_fu_sync_cron');

function btp_fu_sync_cron() {
    if (!get_option('btp_fu_sync_enabled', 0)) return;
    btp_fu_sync_tick();
}

function btp_fu_schedule() {
    if (!get_option('btp_fu_sync_enabled', 0)) {
        $ts = wp_next_scheduled('btp_fu_sync_event');
        if ($ts) wp_unschedule_event($ts, 'btp_fu_sync_event');
        return;
    }
    if (!wp_next_scheduled('btp_fu_sync_event')) {
        $state = btp_fu_sync_state();
        wp_schedule_event(time() + 60, $state['done'] ? 'daily' : 'hourly', 'btp_fu_sync_event');
    }
}
add_action('init', 'btp_fu_schedule', 20);

/** Backfill finished: drop to daily. */
function btp_fu_reschedule_if_done() {
    $state = btp_fu_sync_state();
    if (!$state['done']) return;
    $ts = wp_next_scheduled('btp_fu_sync_event');
    if ($ts && wp_get_schedule('btp_fu_sync_event') !== 'daily') {
        wp_unschedule_event($ts, 'btp_fu_sync_event');
        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'btp_fu_sync_event');
    }
}
add_action('btp_fu_sync_event', 'btp_fu_reschedule_if_done', 99);
