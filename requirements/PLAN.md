# RIN2 – "Web app with Notifications" in Drupal 11: Project Plan

Source spec: `requirements/Web app with Notifications.pdf` (written for Laravel; rebuilt here in Drupal 11).

How to use this file: work through the steps in order. Each step ends with **Done when** checks and one or more git commits. Tick the boxes as we go, and edit anything you want changed. Decisions marked **(decide)** are open; write your choice next to them.

---

## 0. Ground rules

| Item | Choice |
|---|---|
| Stack | Drupal 11.4, PHP 8.4, MariaDB 11.8, DDEV (spec allows "MySQL or Postgres"; MariaDB is MySQL-compatible) |
| Custom code | One custom module: `web/modules/custom/rin2_notify` |
| Coding standard | Drupal coding standard (phpcs `Drupal,DrupalPractice`) in place of PSR-12; explained in a design note |
| Site config | Exported to `config/sync` and committed |
| Commits | Small, focused ("atomic") commits with meaningful messages, as the spec requires |
| Delivery | Tarball/zip of the project **including `.git`**, plus README with install + verify steps |

### Requirement → Drupal mapping (reference)

| Spec requirement | Drupal implementation | Step |
|---|---|---|
| Basic users table + seed records | Core User entity; records created by hand / via the UI (seed command skipped, see Step 5) | 3 |
| Migration on "production" users table: notification switch NOT NULL, phone nullable | Base fields added via `hook_entity_base_field_info()` + `hook_update_N()` that installs them and backfills existing rows | 3 |
| Notifications: type, short text, expiration, destination (user / all) | Custom content entity `rin2_notification` | 4 |
| Know when a user reads a notification | Custom table `rin2_notification_read` (uid, notification id, read time) | 4 |
| Page listing users + unread count, with filters | Admin page `/admin/people/notifications` (custom controller, client-side filters) | 7 |
| Impersonate a user | Contrib `drupal/masquerade` | 2, 7 |
| Home page with bell + unread counter; click → list; click item → mark read | Custom block + controller routes (AJAX, CSRF-protected) | 8 |
| User notification settings form (on/off, email, phone) | Custom form, phone validated with `giggsey/libphonenumber-for-php` | 9 |
| Post new notifications | Notification add form (admin permission) | 6 |
| List of posted notifications with detail + filters | Admin View with exposed filters + entity view page | 6 |
| Clean, consistent UI | Olivero (front) + Claro (admin) + small CSS for the bell | 10 |

---

## Step 1: Repository and dev tooling ✅

- [x] `git init`, first commit of the current scaffold (`.gitignore`, `CLAUDE.md`, `composer.*`, `.ddev/config.yaml`, `settings.php`, `config/sync/.gitkeep`)
- [x] `ddev composer require --dev drupal/core-dev`: PHPUnit, PHPCS + Drupal standard, PHPStan
- [x] Add `phpcs.xml.dist` (standard `Drupal,DrupalPractice`, path `web/modules/custom`)
- [x] PHPUnit env for DDEV: `SIMPLETEST_DB`, `SIMPLETEST_BASE_URL`, `BROWSERTEST_OUTPUT_DIRECTORY` in `.ddev/config.yaml` `web_environment` (no `phpunit.xml` copy; core's `web/core/phpunit.xml.dist` is used via `-c web/core`)
- [x] Site name set to "RIN2 Notifications" (was Drush's placeholder "Drush Site-Install")
- [x] Export current site config: `ddev drush cex -y` (137 config items → `config/sync/`)

**Done when:** ✅ `ddev exec vendor/bin/phpcs` exits 0, ✅ test env vars present in the container, ✅ `ddev drush config:status` → "No differences between DB and sync directory".
**Commits:** `first commit`, `fixed db port local`, `Add dev tooling (drupal/core-dev), phpunit, phpcs, phpstan`, `Add phpcs config and PHPUnit environment for DDEV`, `[TASK] add all yml-files`
**Notes:** `core-dev` needed `-W` (downgrades `sebastian/diff` 7.0.1 → 6.0.2, allowed by core). `web/themes/custom` dropped from phpcs targets (no custom theme planned; phpcs errors on missing paths).

## Step 2: Contrib dependencies ✅

- [x] `ddev composer require drupal/masquerade` (2.2.0 installed), enabled
- [x] `ddev composer require giggsey/libphonenumber-for-php` (9.0.43 installed)
- [x] `ddev composer require --dev drupal/devel` (5.5.0 installed in `require-dev`; downloaded but **not enabled**, so enable it only when needed for debugging)
- [x] Masquerade permission for the admin role only: `administrator` has `is_admin: true`, so it gets all permissions automatically. No other role (`anonymous`, `authenticated`, `content_editor`) has `masquerade as any user` / `masquerade as super user`

**Done when:** ✅ masquerade enabled (`core.extension.yml`), `/unmasquerade` route exists, ✅ only `administrator` holds masquerade permissions, ✅ libphonenumber loads and validates `+393331234567`, ✅ `ddev drush config:status` → no differences.
**Commits:** `[TASK] add packages masquerade libphonenumber and devel`, `[TASK] add module and setting for masquerade`
**Manual check (still worth doing once):** log in as admin (`ddev drush uli`) → `/admin/people` → masquerade as another user → "Switch back" (`/unmasquerade`). Create test users by hand, e.g. `ddev drush user:create demo --password=demo` (Step 5 seeding skipped).

## Step 3: Custom module and the users-table "migration" ✅

- [x] Scaffold `rin2_notify` (`.info.yml`, `.module`), enabled; `.install`, `.permissions.yml`, `.routing.yml`, `.services.yml` are added in the steps that need them
- [x] Add two **base fields on User** in `hook_entity_base_field_info()` (definitions in `rin2_notify_user_base_field_definitions()`):
  - `notifications_enabled`: boolean, **NOT NULL**, default `TRUE`, initial value `TRUE`
  - `phone`: string (max 32, E.164), **nullable**
- [x] `rin2_notify_update_11001()`: installs both fields on the existing table; `setInitialValue(TRUE)` backfills existing users with `1`. This mirrors "the table is already migrated, seeded and in production".
- [x] `rin2_notify_update_11002()` + `src/Rin2UserStorageSchema.php` + `hook_entity_type_alter()`: core ignores `setStorageRequired()` for shared tables (drupal.org/node/2841291), so 11001 left the column nullable; this adds NOT NULL the way core does for the user `name` column
- [x] Design note `web/modules/custom/rin2_notify/docs/users-migration.md`

**Done when:** ✅ `SHOW CREATE TABLE users_field_data` → `notifications_enabled tinyint(4) NOT NULL`, `phone varchar(32) DEFAULT NULL`; ✅ existing users backfilled with `1`; ✅ raw `SET notifications_enabled = NULL` rejected (error 1048); ✅ `updatedb:status` clean, entity definitions up to date; ✅ phpcs clean.
**Commits:** `Scaffold rin2_notify module`, `[TASK] 2 new fields in user table`, + NOT NULL fix (11002) and design note (to commit)
**Open:** ~~fresh-install path (NOT NULL without running updates) not yet verified~~ → covered by `NotificationManagerTest::testNotificationsEnabledIsNotNullOnInstall()` (Step 4).
**Local data:** demo users `alice`, `bob`, `tom` created by hand (uid 2–4); kept as demo data (Step 5 seeding skipped).

## Step 4: Notification entity and read tracking ✅

- [x] Content entity `rin2_notification`, fields:
  - `type`: list: `marketing`, `invoices`, `system` (required)
  - `message`: short text, max 255 (required)
  - `expires`: timestamp (required); stored as a Unix timestamp so "not expired" is an integer comparison
  - `target_uid`: entity reference to user, **empty = all users**
  - `created`, `uid` (author)
- [x] Table `rin2_notification_read` via `hook_schema()`: `uid`, `notification_id`, `read_at`; primary key (uid, notification_id); update 11004 creates it on the existing site; rows removed when a user or notification is deleted
- [x] Service `rin2_notify.manager` (`NotificationManager`, autowirable via `NotificationManagerInterface`) containing all the rules:
  - `getVisibleFor(AccountInterface $user)`: not expired, target is the user or all, ordered newest first
  - `countUnreadFor($user)`
  - `markRead($notification, $user)` (idempotent; does nothing for users with `notifications_enabled = 0`, so they never get a row in `rin2_notification_read`)
  - `isRead($notification, $user)`
  - `isVisibleTo($notification, $user)` (added: used by `markRead()`, and by the Step 8 route for access)
- [x] Cache tags/contexts so counters update after posting or reading: `getCacheableMetadataFor($user)` → context `user`; tags `rin2_notification_list` (core, any post/edit/delete), `user:UID` (notifications switched on/off), `rin2_notification_read:UID` (invalidated by `markRead()` on first read); max-age = seconds until the next visible notification expires. Global tag `rin2_notification_read` for admin read counts, also invalidated by the delete cleanups
- [x] Permissions: `administer rin2 notifications`, `view own rin2 notifications` (`rin2_notify.permissions.yml`)

**Done when:** ✅ kernel test `tests/src/Kernel/NotificationManagerTest.php` (9 tests, 112 assertions) proves visibility rules (expired hidden, targeted vs all, "for all" incl. later users), read/unread counts, idempotent `markRead()`, notifications-off rules (D5), delete cleanup, cache metadata + tag invalidation, and fresh-install NOT NULL on `notifications_enabled`; ✅ phpcs clean; ✅ `updatedb:status` clean.
**Commits:** `Add notification entity`, `Add read-status table and NotificationManager service`, `Add kernel tests for notification visibility rules`
**Notes:** `drupal:options` added to `rin2_notify.info.yml` dependencies (the `type` field is `list_string`). Run tests: `ddev exec vendor/bin/phpunit -c web/core web/modules/custom/rin2_notify`.

## Step 5: Seed data ⏭️ skipped

**Skipped (2026-11-15):** test data is created by hand or through the frontend features (Steps 6–9) instead of a seed command.

- [-] ~~Drush command `ddev drush rin2:seed`: creates ~10 users (some with phone, one with notifications off) and ~15 notifications (all types, some expired, some targeted, some for all), and marks a few as read~~
- [-] ~~Optional `--reset` flag to delete seeded data~~

~~**Done when:** a fresh install plus `rin2:seed` gives a demo-ready site.~~
~~**Commits:** `Add rin2:seed Drush command for demo data`~~

## Step 6: Post notifications + posted-notifications list ✅ (browser check of the JS filters pending)

- [x] Add form for `rin2_notification` (admin only) at `/admin/content/notifications/add` (menu: Content → Post notification); destination radios "All users" / "Specific users" + a user table with checkboxes (name, email) shown via `#states`, at least one user required server-side when "specific"; after saving → detail page
  - **Change (2026-11-15): several recipients per notification.** `target_uid` has unlimited cardinality, stored in `rin2_notification__target_uid` (one row per recipient; no rows = all users); update 11005 moved existing recipients
  - **Decision (2026-11-15): notifications are write-once.** No edit and no delete: `NotificationAccessControlHandler` forbids `update`/`delete` for everyone (incl. uid 1, JSON:API); no edit/delete routes exist
- [x] Validation: expiry must be in the future (only on create, since there is no editing): constraint plugin `Rin2ExpiresInFuture` on the `expires` field, so it applies to every save path that validates (form, API); message ≤ 255 chars (already enforced by `max_length`)
- [x] Admin list `/admin/content/notifications` (custom controller `NotificationListController`): columns type, message (links to detail page), destination, posted, expires, status (active/expired), read count (`NotificationManager::getReadCounts()`, one grouped query); newest first; "Notifications" tab under Content + "Post notification" button; cache: list + read tags, max-age until the next notification expires
- [x] Filters (client-side, simple-datatables 10.3.0): message contains, type, destination (All users / each recipient), status (active/expired), Reset; plus column sorting (dates by timestamp via `data-order`) and paging (25 per page). `NotificationFilterForm` (never submitted) + generic `js/admin-table.js` (`data-rin2-table` / `data-rin2-column` / `data-rin2-match`), reusable for the Step 7 users list
- [x] Detail page per notification `/admin/content/notifications/{id}` (`NotificationViewBuilder`, full view mode): type, message, expires, recipient ("All users" when empty), author, posted, status, and a "Read by N users" table (user, read at) from `NotificationManager::getReaders()`

**Filters:** to be built client-side with the JS library **simple-datatables** (user's choice). Table built by a **custom controller** (decided 2026-11-15, not a View). Library: **local copy** in `js/vendor/simple-datatables/` (unmodified UMD build + CSS + LICENSE, LGPL-3.0), declared in `rin2_notify.libraries.yml`.
**Tests:** `tests/src/Functional/NotificationFormTest.php` (post to all / to a user, user required, expiry in the past rejected, list rows/status/read count/order, access: 403 for non-admins, no edit/delete pages, entity access forbids update/delete); kernel tests for `getReadCounts()`, `getReaders()` and the expiry constraint; functional checks for filter markup, `data-order`, attached JS and the detail page. 18 tests green. The JS filtering itself is not covered automatically (no FunctionalJavascript/Chrome in DDEV): check it by hand in the browser.
**Done when:** an admin can post a notification and find it with each filter.
**Commits:** `Add notification add form`, `Add posted notifications admin view with filters`, `Add notification detail page`

## Step 7: Users list with unread count + impersonate ✅ (browser check of the JS filters pending)

- [x] Admin page `/admin/people/notifications` (instead of `/admin/rin2/users`: "Notifications" tab under People, like the Content tab in Step 6; menu link under People), custom controller `UserListController`: name (links to the user), email, phone, notifications on/off, **unread count**, status (active/blocked), operations. All users except anonymous, sorted by name. Permission `administer rin2 notifications`
- [x] Unread count (D1: custom controller, same pattern as Step 6): `NotificationManager::getUnreadCounts($uids)`, same rules as `countUnreadFor()` (incl. D5: 0 when notifications are off) in 3 queries for the whole list instead of one per user; cache: `user_list`, list + read tags, max-age until the next notification expires
- [x] Filters (client-side, `UserFilterForm` + `js/admin-table.js`): name or email contains (searches 2 columns: `data-rin2-column="0,1"`), notifications on/off, "has unread" (new matcher `positive`: yes = count > 0), status active/blocked, Reset; sorting (unread numeric via `data-order`; operations column not sortable via `th[data-rin2-sortable="false"]`) and paging
- [x] Row actions: **Impersonate** (masquerade link with CSRF token, only where masquerade allows it: not yourself, not while masquerading, and not for blocked users) → front page for now (**@todo Step 8:** `/home`); **Edit settings** → core user edit form with destination back to the list (**@todo Step 9:** settings form). `masquerade` added to the module dependencies

**Done when:** ✅ counts match `countUnreadFor()` for the hand-made users (admin 1, alice 1, bob 2, tom 2), ✅ Impersonate switches to the user and lands on the front page (Step 8 changes it to the home page).
**Tests:** kernel `testGetUnreadCounts()` (batch equals single count: targeted, several recipients, read, expired, notifications off, anonymous/unknown uid); functional `tests/src/Functional/UserListTest.php` (row contents, blocked/off users, filter markup, impersonate links + switching, edit link, access). 26 tests green. JS filtering itself: check by hand in the browser.
**Commits:** `[TASK] fix functional tests for multi-recipient notifications`, `[TASK] batch unread counts per user`, `[TASK] users list with unread count, filters and impersonate`

## Step 8: User home page + bell

- [ ] Route `/home` (logged-in users): simple welcome page
- [ ] Block `NotificationBellBlock` placed in the Olivero header: bell icon + unread badge
- [ ] Click the badge to open a dropdown/panel listing visible notifications (unread in bold, type label, expiry)
- [ ] Click a notification: POST to `/rin2/notification/{id}/read` (CSRF token) → marks it read, updates the badge without a page reload (Drupal AJAX or small vanilla JS)
- [ ] Respect `notifications_enabled`: when off, the bell is hidden **(decide: hidden, or shown greyed with "notifications off")**

**Done when:** while impersonating a test user, the badge count equals the users-list count, and clicking items lowers it to 0.
**Commits:** `Add user home page`, `Add notification bell block with unread badge`, `Mark notifications as read via AJAX`

## Step 9: User notification settings form

- [ ] Route `/user/{user}/notification-settings` (own account, or admin)
- [ ] Fields: on-screen notifications on/off, email (unique, valid), phone
- [ ] Phone validation with libphonenumber: must parse, be valid, and be type **MOBILE** (or FIXED_LINE_OR_MOBILE); stored in E.164 format (`+393331234567`). Default region **(decide: IT? DE?)** for numbers typed without `+`
- [ ] Link from the user home page and the users list

**Done when:** a valid mobile number saves, and a landline or garbage number is rejected with a clear message.
**Commits:** `Add user notification settings form`, `Validate phone as mobile number with libphonenumber`

## Step 10: UI polish

- [ ] Bell/badge/dropdown CSS consistent with Olivero; type labels color-coded (marketing / invoices / system)
- [ ] Admin pages use Claro defaults; tidy column labels and empty-state texts
- [ ] Main menu links for admins: Users overview, Notifications, Post notification

**Done when:** a walkthrough of all pages looks consistent on desktop and mobile width.
**Commits:** `Style notification bell and type labels`, `Add admin menu links`

## Step 11: Tests and code quality

- [ ] Kernel tests: NotificationManager rules (from Step 4), phone validator
- [ ] Functional tests: post notification → visible for target only; mark read → count decrements; expired hidden; settings form validation
- [ ] `phpcs` clean, `phpstan` (level as feasible) clean

**Done when:** `ddev exec vendor/bin/phpunit -c web/core web/modules/custom/rin2_notify` passes, and phpcs reports no errors.
**Commits:** `Add functional tests for notification flow`, `Fix coding standard issues`

## Step 12: Documentation and delivery

- [ ] Root `README.md`: requirements (DDEV, Docker), install steps (`ddev start`, `ddev composer install`, `ddev drush si --existing-config -y`), how to create test users and notifications by hand, login (`ddev drush uli`), **verification walkthrough** matching each spec bullet, how to run tests
- [ ] Design notes (`.md` in the relevant folders): Drupal vs Laravel mapping, users "migration", read-status table design, why libphonenumber over MessageBird (free, offline, no API key)
- [ ] Final check from a **fresh clone**: install from config and follow the README end to end
- [ ] Package: `git archive` is NOT enough (it drops `.git`); use `tar czf rin2-drupal.tar.gz --exclude=vendor --exclude=web/core --exclude=web/modules/contrib --exclude=web/sites/default/files my-drupal11-site`

**Done when:** someone with only DDEV can install and verify every feature from the README.
**Commits:** `Add README with install and verification guide`, `Add design notes`

---

## Open decisions

| # | Question | Recommendation | Your choice |
|---|---|---|---|
| D1 | Unread-count list: Views plugin or custom controller? | Views (filters for free) | **Custom controller** (2026-11-15), same pattern and client-side filters as the Step 6 list |
| D2 | Bell when notifications are switched off | Hide bell entirely | |
| D3 | Default phone region for numbers without `+` | IT (spec author looks Italian) or DE | |
| D4 | "For all" notifications: also visible to users created **after** posting? | Yes (simplest, matches "for all") | **Yes.** "For all" means everybody, including users created later (except users with notifications off, see D5) |
| D5 | Does turning notifications off also exclude the user from unread counts in the admin list? | No: count stays, setting only affects on-screen display | **Yes.** `notifications_enabled = 0` → user gets no notifications at runtime: `getVisibleFor()` returns none, `countUnreadFor()` returns 0. Re-enabling shows still-unexpired notifications again (runtime check, nothing stored) |
| D6 | Push to GitHub in addition to the tarball? | Yes, private repo | |

## Progress log

| Date | Step | Notes |
|---|---|---|
| 2026-11-09 | Setup | DDEV + Drupal 11.4 installed, `.gitignore`, config sync → `config/sync`, `CLAUDE.md` |
| 2026-11-09 | 1 | git repo created and pushed to github.com/sinianzhang/drupal_rin2 (public); DB host port fixed to 59002 |
| 2026-11-09 | 1 ✅ | Step 1 complete: dev tooling installed, phpcs + PHPUnit env configured, baseline config exported (137 items), site renamed |
| 2026-11-09 | 2 ✅ | masquerade 2.2.0 enabled (admin-only via is_admin), libphonenumber 9.0.43, devel 5.5.0 (dev, not enabled) |
| 2026-11-09 | 3 ✅ | rin2_notify module; users.notifications_enabled (NOT NULL, via custom storage schema, updates 11001+11002) and users.phone (nullable); design note |
| 2026-11-15 | 4 | `rin2_notification` content entity (update 11003 installs it on the existing site); `expires` as timestamp instead of datetime; permissions file added |
| 2026-11-15 | 4 | `rin2_notification_read` table (hook_schema + update 11004), read rows cleaned up on user/notification delete |
| 2026-11-15 | 4 | D4 + D5 decided; `NotificationManager` service built, scenario-checked via drush (kernel test still to do) |
| 2026-11-15 | 4 ✅ | Step 4 complete: cache metadata + tag invalidation, kernel test (9 tests green, also closes Step 3 fresh-install check), `options` dependency |
| 2026-11-15 | 5 ⏭️ | Step 5 (seed command) skipped by decision; test data created by hand / via UI |
| 2026-11-15 | 6.1 ✅ | Add form with destination radios, write-once access handler (no edit/delete), menu link, functional test (12 tests green) |
| 2026-11-15 | 6.2 + 6.3 ✅ | Expiry-in-future constraint (create only); admin list as custom controller with status + read count, Content tab, action button (16 tests green). Filters (6.4) and detail page (6.5) still open |
| 2026-11-15 | 6.4 + 6.5 ✅ | Client-side filters/sorting/paging with simple-datatables 10.3.0 (local copy), detail page with status + readers table (18 tests green). Step 6 done pending a manual browser check of the filters |
| 2026-11-15 | 6 | Several recipients per notification (`target_uid` unlimited, own table `rin2_notification__target_uid`, update 11005); functional tests fixed for it (20 tests green) |
| 2026-11-15 | 7 ✅ | D1 decided (custom controller). Users list `/admin/people/notifications` with batch unread counts, client-side filters (name/email, notifications, has unread, status), Impersonate (→ front page until Step 8) and Edit settings (→ user edit form until Step 9); 26 tests green. Pending: manual browser check of the filters |
| 2026-11-17 | 6 | "Notifications" added to the Navigation sidebar as a child of Content (menu `content`, parent `navigation.content`), so Content opens a flyout; the `system.admin_content` child link stays for the classic Toolbar |
