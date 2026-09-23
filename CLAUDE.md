# BT Portal

Boomer T's employee portal. Schedule board, online stores, quote tab, redirect tool,
contacts, exchange tracking, vendors, OMG and Chipply scanners, BT Accounts orders, Bruce Art, day notes, backups, and the
`[bt_schedule]` shortcode.

- Site: boomerts.com, page `/employees/`
- Current version: **0.55.1**. Constant `BTP_VERSION`, function prefix `btp_`.
- Repo: `strummer95/bt-portal`

## Environment (read this before anything else)

**Boomer T's Ink & Thread is a separate company from Duck and Rabbit Co.** It is Dillon's
dad's shop. It runs on **AWS Lightsail Bitnami WordPress + Elementor**, NOT IONOS. Never
conflate it with PresStora or any Duck and Rabbit project, and never make BT depend on
PresStora at runtime.

**Dillon works only through the WordPress dashboard.** No SSH, no SFTP, no server file
access. Everything ships as a plugin update. That constraint drives the whole release
process below.

Brand: navy `#27267e`, pink/magenta `#e535ab`, Oswald.

## Release process

Every version bump touches four places, and **they must all match** or WordPress loops
forever trying to install an update it already has:

1. `Version:` in the `bt-portal/bt-portal.php` plugin header
2. `BTP_VERSION` in the same file
3. `version` in `manifest.json`
4. The version inside the zip you build

Steps:

1. Edit files under `bt-portal/`.
2. Bump the header `Version:` and `BTP_VERSION` together.
3. `node --check` any touched JS and `php -l` any touched PHP. The container does now carry
   a PHP binary (8.4), so lint rather than brace-auditing by hand.
4. Build both zips at the repo root: `bt-portal-X.Y.Z.zip` and plain `bt-portal.zip`.
   Zip the `bt-portal/` folder, not its contents, and exclude `.DS_Store`.
5. Update `manifest.json`: `version`, `download_url` pointed at the **versioned** raw URL
   (`https://raw.githubusercontent.com/strummer95/bt-portal/main/bt-portal-X.Y.Z.zip`), and
   prepend a changelog entry.
6. Commit and push to `main`.
7. Dillon goes to **BT Portal → Check for updates** (the panel at the bottom of the
   BT Portal page), then **Plugins → Update Now**.

Why versioned zips and not GitHub Releases: `uploads.github.com` is blocked from the
container, so release assets cannot be attached. The updater reads `manifest.json` through
`api.github.com` with `Accept: application/vnd.github.raw`, which reflects a push instantly
with no CDN delay.

Write the changelog in plain language for shop staff, not developers. Look at the existing
entries in `manifest.json` and match that voice: what changed, what it means for the person
using it, and what actually caused it when a bug is being fixed.

## Structure

`bt-portal/bt-portal.php` is the loader. Includes, in load order:

`users.php` (portal logins, login gate, identity) · `vendors-seed.php` · `vendors.php` ·
`db.php` (tables, migrations, nightly CSV and DB backup crons) · `rest.php` (all
`boomerts/v1` endpoints) · `shortcode.php` (the frontend app, 4800 lines) · `head.php` ·
`redirect.php` (`/stores/` redirects, `[bt_redirect_tab]`) · `woo.php` ·
`dtf-jobs.php` (DTF Studio orders → Transfers cards) · `exchanges.php` ·
`exchange-mail.php` · `exchanges-diag.php` · `omg-scanner.php` · `printavo.php` ·
`chipply-barcoder.php` (hidden) · `chipply-scanner.php` · `bruce-art.php` · `routing.php` (`/employees/<tab>`
deep links) · `bt-admin.php` · `admin.php` · `updater.php`

The plugin is a port of the old BT-Sched WPCode snippets (1 = DB, 2 = API, 3 = Frontend,
4 = Adminbar), which is why the file comments reference snippet numbers.

`includes/bt-admin.php` is **byte-identical across bt-portal, bt-catalog, bt-quote,
bt-accounts and bt-dtf**. Whichever plugin loads first defines `bt_admin_updates_panel()`; the rest
skip it via `function_exists`. Do not fork it. If it changes, re-copy it into all five in
the same release round. Anything plugin-specific goes above the panel, in that plugin's own
code.

**Where the update check lives is a fixed rule across the BT plugins:** the shared panel
is the last thing on the plugin's own top-level admin page. Never a separate Updates
submenu. BT Transfers was the one exception until its 0.7.5 and it is not coming back.

## Other > Accounts (0.51.0)

The pane only hosts BT Accounts' `[bta_staff_orders]`; all of its logic, REST and styling
live in the bt-accounts repo (`includes/staff-orders.php`). The menu item and pane render
only when that shortcode exists and the user has `bta_handle_orders`, which is granted per
person under BT Accounts > Shop staff, not by portal role. `btSwitchTab()` falls back to
Schedule when a tab's pane is missing, so `/employees/accounts` is safe for everyone.

`window.btpNewJob(prefill, onCreated)` (0.52.0) opens the normal New Job window with
`orderNum`, `customer`, `dueDate`, `notes`, `lineItems`, `dept` filled in, and after a
successful POST calls `onCreated` with the saved row. `btCloseModal()` clears the
hand-off, so cancel calls nothing. BT Accounts' Create job card button is the caller.

Sub-addresses (0.53.0): a second rewrite rule maps `/employees/<tab>/<item>/` to the page
with `btp_tab` + `btp_item` (`?tab=x&item=y` without pretty permalinks). JS helpers
`btpCurrentItem()` and `btpSetItem(tab, item)`; `btTabFromUrl()` reads only the first
segment. `btSwitchTab()` calls the Accounts loader after its pushState, so the tab always
reads the settled address. Only Accounts uses items so far (order number, lowercased).

## Auth model

Portal logins replaced a shared WordPress page password and a "Who are you?" name dropdown.

- Roles: `bt_portal_user` (everyday staff: schedule, stores, exchanges, scanners, quote,
  redirect) and `bt_portal_admin` (manages portal logins only, not a WordPress admin).
- Old dropdown names survive as `btp_legacy_name` user meta so `created_by`,
  `woo_completed_by` and day-note authors stay continuous. The header shows legacy name if
  set, else display name.
- Admin screen: **BT Portal → Portal Users**. In-portal account panel opens from the header
  name.
- New accounts get a generated readable password like `KTM-8412`, shown on screen and
  emailed. No links to expire. Characters ambiguous when read aloud (O/0, I/1) are avoided.
- Five failed attempts triggers a 15 minute cooldown; **Unlock** on Portal Users clears it.
- The portal page is excluded from page caching, since it renders the signed-in name.

## Open security issue, not yet fixed

`includes/rest.php` still registers the legacy `boomerts/v1` routes with
`permission_callback => __return_true`, which means **unauthenticated read and write**:
jobs (GET/POST/PUT/DELETE, status, reorder, sort), stores, store-categories, contacts,
day-notes, closed-days.

Backups were locked to a `bt_portal_backups` capability in 0.27.0 because a restore
silently replaces the entire board. The rest were left open deliberately, not by oversight,
because a customer-facing page may be hitting them.

**Before locking these down**, check whether the public exchange form or any other
customer-facing page calls them. Ask Dillon rather than assuming. The exchange routes and
the Woo order-completion route already require the portal's `wp_rest` nonce, so they are
the model to follow.

## Things that will bite you

- **Modal id collisions with BT Quote.** The Quote tab renders BT Quote's
  `[bt_quick_quote]` shortcode inside the portal, so both plugins' markup is on the same
  page. A shared `btModalOverlay` id once made clicking a job card open BT Quote's hidden
  overlay instead. Portal ids and classes are `btp-` prefixed now. Keep it that way, and
  never reuse a bare `bt-` id.
- **The portal CSS reset** (`#bt-schedule-app *`) once outranked BT Quote's stylesheet and
  flattened the quote tool. It is wrapped in `:where()` so it contributes zero specificity
  and skips the `.bt-tool` subtree. Don't raise it.
- **Deep links need a permalink flush.** After updating, open the portal once to register
  the page. If a deep link 404s, Settings → Permalinks → Save once. Routing is built from
  whichever page holds the shortcode, not a hardcoded "employees".
- **Vendor passwords** are encrypted at rest with a key written to the uploads folder,
  falling back to the database if that folder is not writable. A silent failure there once
  regenerated the key on every page load, which would have made every stored password
  decrypt to nothing. Don't undo the fallback.
- **The exchanges query fix.** Comparing a text `meta_value` against an unquoted number
  forced a full scan of the order-item table on every load, ignoring the index, and got
  slower with every order ever placed until it hit the 30 second limit. Quote the value.
  Results are cached five minutes.
- **Don't delete `exchanges-diag.php`.** It exists because the exchanges failure took nine
  releases to pin down.

- **Synology Drive art paths (0.50.0, 0.50.1).** One Mac user works off site through
  Synology Drive, so his art paths arrive as
  `/Users/<n>/Library/CloudStorage/SynologyDrive-<connection>/...`. He only needs to
  *save* links; production PCs open them. `BT_ART_SYNC_HOST` plus
  `BT_ART_SYNC_FOLDER_TO_SHARE` convert them to UNC. The real server path is
  `\\BoomerTs\BTServer\1 - ARTWORK\...`: the synced `1 - ARTWORK` folder sits **inside**
  the BTServer share, it is not a share itself. Any other synced folder needs its own
  map line; do not go back to guessing the top folder is the share.

## Not in this repo (don't hunt for it)

These live on the site as snippets, not in any GitHub repo:

- **Customer exchange form, the exchange slip template, and the admin print slip.** One
  WPCode snippet (Universal/PHP, shortcode `[bt_exchange_form]`, functions `bt_exchange_*`).
  This plugin only **reads** the order meta that snippet writes (`_bt_exchange_items`,
  `_bt_original_order`, `_bt_school_team`). Before locking down the legacy
  `boomerts/v1` routes above, check whether that form calls any of them.
- **Spirit wear lead page** (`[bt_spiritwear]`, boomerts.com/spiritwear) and its
  **fundraiser clone** (`[bt_fundraiser]`). Code Snippets PHP. Fields `sw_*` / `fr_*`,
  leads email orders@boomerts.com, Meta conversion fires on `?sw=thanks`.

If a change is needed in one of these, say so and hand Dillon the snippet code to paste.
Don't create a repo copy of it.

## Repo hygiene

There are **38 versioned zips** committed at the repo root and the `.git` directory is
6.6 MB. Every release adds another. Worth pruning old ones at some point, but check with
Dillon first: `manifest.json` only points at the current one, so older zips are dead weight
rather than load-bearing.

## Working notes

- Compact, always. Dillon flags too much whitespace on every project.
- Text sizing errs UP: table body 15px or larger, headers and badges 13px or larger.
- Terse and results-first. Ship the actual deliverable, not narration.

## Other > Bruce Art (0.54.0)

`includes/bruce-art.php`, shortcode `[bt_bruce_art]`, tab id `bruceart`, slug `bruce-art`.
Hosts the Bruce site iframe embed (`boomerts.sites.askbruce.ai`) behind the portal login.
The SDK script is injected by `window.btpBruceArtLoad()`, which `btSwitchTab()` calls on
open, so it never loads on other tabs and the iframe is built while visible (resizeToFit
measures a real width). The SDK reads `embedContainer`, NOT `container`; without it it appends a new div to <body> (0.54.0 bug). Pane has no min-height on purpose: the fill sizing subtracts space below the iframe. Container id is `btp-bruce-embed`, not Bruce's stock
`bruce-embed`, to stay clear of the future catalog/quote Bruce embed. Site and SDK URLs are
`BTP_BRUCE_SITE_URL` / `BTP_BRUCE_SDK_URL` constants with filters. Hiding the embed does not
lock the Bruce site's own public URL.

## DTF Studio orders on the board (0.55.0)

`includes/dtf-jobs.php` writes the Transfers job card when a gang sheet order is paid for.
Before that, a card only existed if somebody read the new order email and typed one.

- Fires on `woocommerce_payment_complete` and the processing and completed status hooks.
  An unpaid order (pending, on hold) is deliberately left off the board.
- Recognises a DTF order from the gang sheet meta BT Transfers writes on the line items,
  including the public `Sheet File` / `Sheet Size` keys older orders carry.
- **Due date: today before 2pm, next day after, then rolled forward.** The roll is not
  cosmetic. `btGetWeekDays()` builds five columns from Monday, so a card dated Saturday or
  Sunday is in the table and on no column at all. Days at 0% capacity are skipped for the
  same reason; a day at reduced capacity is still open and is used.
- Dedup is three-sided and all three are load-bearing: `_btp_dtf_job_id` on the order, a
  lookup by `woo_order_id`, and **a lookup by order number against any Transfers card**.
  The last one is what sees a card somebody typed by hand, which carries neither of the
  other two.
- **Never hook `woocommerce_order_status_completed` here.** 0.55.0 did, and it duplicated
  every hand-typed card on day one: `btp_woo_complete()` calls
  `$order->update_status('completed')`, which fires that hook *synchronously, before*
  `woo.php` stamps `woo_order_id` on the card being completed. So the Complete Order
  button built a second card for the job it had just finished. Completion is the end of a
  job, never a reason to schedule one. Completed, cancelled, refunded, failed and trashed
  orders are all refused outright now.
- `btp_dtf_jobs_since` (0.55.1) records when the feature first ran. An order created before
  it is ignored forever, so a status change on an old order cannot drop it on today's
  board — which is how the pre-0.55.0 backlog landed on the 23rd.
- Reuses `woo_order_id` (from `woo.php`) and `auto_kind` (from `store-schedule.php`), so
  there is no migration. The insert is filtered through `SHOW COLUMNS` so an older table
  still gets its card.
- **Nothing here updates or deletes a card after it is written.** Once it is on the board it
  belongs to production. A cancelled or refunded order leaves its card standing.
