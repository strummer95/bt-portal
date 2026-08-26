# BT Portal

Boomer T's employee portal. Schedule board, online stores, quote tab, redirect tool,
contacts, exchange tracking, vendors, OMG and Chipply scanners, day notes, backups, and the
`[bt_schedule]` shortcode.

- Site: boomerts.com, page `/employees/`
- Current version: **0.47.0**. Constant `BTP_VERSION`, function prefix `btp_`.
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
3. `node --check` any touched JS. There is no PHP binary in the container, so brace-audit
   touched PHP by hand.
4. Build both zips at the repo root: `bt-portal-X.Y.Z.zip` and plain `bt-portal.zip`.
   Zip the `bt-portal/` folder, not its contents, and exclude `.DS_Store`.
5. Update `manifest.json`: `version`, `download_url` pointed at the **versioned** raw URL
   (`https://raw.githubusercontent.com/strummer95/bt-portal/main/bt-portal-X.Y.Z.zip`), and
   prepend a changelog entry.
6. Commit and push to `main`.
7. Dillon goes to **BT Portal → Status & Updates → Check for updates now**, then
   **Plugins → Update Now**.

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
`redirect.php` (`/stores/` redirects, `[bt_redirect_tab]`) · `woo.php` · `exchanges.php` ·
`exchange-mail.php` · `exchanges-diag.php` · `omg-scanner.php` · `printavo.php` ·
`chipply-barcoder.php` (hidden) · `chipply-scanner.php` · `routing.php` (`/employees/<tab>`
deep links) · `bt-admin.php` · `admin.php` · `updater.php`

The plugin is a port of the old BT-Sched WPCode snippets (1 = DB, 2 = API, 3 = Frontend,
4 = Adminbar), which is why the file comments reference snippet numbers.

`includes/bt-admin.php` is **byte-identical across bt-portal, bt-catalog, bt-quote and
bt-accounts**. Whichever plugin loads first defines `bt_admin_updates_panel()`; the rest
skip it via `function_exists`. Do not fork it. If it changes, re-copy it into all four in
the same release round. Anything plugin-specific goes above the panel, in that plugin's own
code.

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

## Repo hygiene

There are **38 versioned zips** committed at the repo root and the `.git` directory is
6.6 MB. Every release adds another. Worth pruning old ones at some point, but check with
Dillon first: `manifest.json` only points at the current one, so older zips are dead weight
rather than load-bearing.

## Working notes

- Compact, always. Dillon flags too much whitespace on every project.
- Text sizing errs UP: table body 15px or larger, headers and badges 13px or larger.
- Terse and results-first. Ship the actual deliverable, not narration.
