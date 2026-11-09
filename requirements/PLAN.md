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
| Basic users table + seed records | Core User entity + Drush seed command | 3, 5 |
| Migration on "production" users table: notification switch NOT NULL, phone nullable | Base fields added via `hook_entity_base_field_info()` + `hook_update_N()` that installs them and backfills existing rows | 3 |
| Notifications: type, short text, expiration, destination (user / all) | Custom content entity `rin2_notification` | 4 |
| Know when a user reads a notification | Custom table `rin2_notification_read` (uid, notification id, read time) | 4 |
| Page listing users + unread count, with filters | Admin page (View or custom list) with exposed filters | 7 |
| Impersonate a user | Contrib `drupal/masquerade` | 2, 7 |
| Home page with bell + unread counter; click → list; click item → mark read | Custom block + controller routes (AJAX, CSRF-protected) | 8 |
| User notification settings form (on/off, email, phone) | Custom form, phone validated with `giggsey/libphonenumber-for-php` | 9 |
| Post new notifications | Notification add form (admin permission) | 6 |
| List of posted notifications with detail + filters | Admin View with exposed filters + entity view page | 6 |
| Clean, consistent UI | Olivero (front) + Claro (admin) + small CSS for the bell | 10 |

---

## Step 1: Repository and dev tooling

- [ ] `git init`, first commit of the current scaffold (`.gitignore`, `CLAUDE.md`, `composer.*`, `.ddev/config.yaml`, `settings.php`, `config/sync/.gitkeep`)
- [ ] `ddev composer require --dev drupal/core-dev`: PHPUnit, PHPCS + Drupal standard, PHPStan
- [ ] Add `phpcs.xml.dist` (standard `Drupal,DrupalPractice`, path `web/modules/custom`)
- [ ] Add `phpunit.xml` setup for running tests inside DDEV (DB URL, base URL)
- [ ] Export current site config: `ddev drush cex -y`

**Done when:** `ddev exec vendor/bin/phpcs` and `ddev exec vendor/bin/phpunit -c web/core --list-tests` both run.
**Commits:** `Initial Drupal 11 scaffold`, `Add dev tooling (core-dev, phpcs config)`, `Export baseline site configuration`

## Step 2: Contrib dependencies

- [ ] `ddev composer require drupal/masquerade` (2.2.x, Drupal 11 compatible), enable
- [ ] `ddev composer require giggsey/libphonenumber-for-php` (9.x)
- [ ] `ddev composer require --dev drupal/devel` (optional; for debugging)
- [ ] Grant masquerade permission to the admin role only

**Done when:** an admin can masquerade as another user and switch back.
**Commits:** `Add masquerade module`, `Add libphonenumber library`

## Step 3: Custom module and the users-table "migration"

- [ ] Scaffold `rin2_notify` (`.info.yml`, `.module`, `.permissions.yml`, `.routing.yml`, `.services.yml`)
- [ ] Add two **base fields on User** in `hook_entity_base_field_info()`:
  - `notifications_enabled`: boolean, **required (NOT NULL)**, default `TRUE`
  - `phone`: string/telephone, **nullable**
- [ ] `hook_update_N()` that installs the field definitions on an existing site and backfills `notifications_enabled = 1` for existing users. This mirrors "the table is already migrated, seeded and in production".
- [ ] Design note `web/modules/custom/rin2_notify/docs/users-migration.md`: why base fields + update hook, compared with Field UI config fields

**Done when:** on a site with existing users, `ddev drush updb -y` adds the columns, and `ddev drush sqlq "DESCRIBE users_field_data"` shows `notifications_enabled NOT NULL` and `phone NULL`.
**Commits:** `Scaffold rin2_notify module`, `Add notifications_enabled and phone fields to users with update hook`

## Step 4: Notification entity and read tracking

- [ ] Content entity `rin2_notification`, fields:
  - `type`: list: `marketing`, `invoices`, `system` (required)
  - `message`: short text, max 255 (required)
  - `expires`: datetime (required)
  - `target_uid`: entity reference to user, **empty = all users**
  - `created`, `uid` (author)
- [ ] Table `rin2_notification_read` via `hook_schema()`: `uid`, `notification_id`, `read_at`; primary key (uid, notification_id)
- [ ] Service `rin2_notify.manager` (`NotificationManager`) containing all the rules:
  - `getVisibleFor(AccountInterface $user)`: not expired, target is the user or all, ordered newest first
  - `countUnreadFor($user)`
  - `markRead($notification, $user)` (idempotent)
  - `isRead($notification, $user)`
- [ ] Cache tags/contexts so counters update after posting or reading
- [ ] Permissions: `administer rin2 notifications`, `view own rin2 notifications`

**Done when:** a kernel test proves visibility rules (expired hidden, targeted vs all, read/unread counts).
**Commits:** `Add notification entity`, `Add read-status table and NotificationManager service`, `Add kernel tests for notification visibility rules`

## Step 5: Seed data

- [ ] Drush command `ddev drush rin2:seed`: creates ~10 users (some with phone, one with notifications off) and ~15 notifications (all types, some expired, some targeted, some for all), and marks a few as read
- [ ] Optional `--reset` flag to delete seeded data

**Done when:** a fresh install plus `rin2:seed` gives a demo-ready site.
**Commits:** `Add rin2:seed Drush command for demo data`

## Step 6: Post notifications + posted-notifications list

- [ ] Add/edit/delete forms for `rin2_notification` (admin only); destination field: "All users" or autocomplete a user
- [ ] Validation: expiry must be in the future; message ≤ 255 chars
- [ ] Admin list `/admin/content/notifications` (View): columns type, message, destination, created, expires, status (active/expired), read count
- [ ] Exposed filters: type, destination (all / specific user), status (active/expired), text contains
- [ ] Detail page per notification (who has read it, and when)

**Done when:** an admin can post a notification and find it with each filter.
**Commits:** `Add notification add/edit forms`, `Add posted notifications admin view with filters`, `Add notification detail page`

## Step 7: Users list with unread count + impersonate

- [ ] Admin page `/admin/rin2/users`: name, email, phone, notifications on/off, **unread count**
- [ ] Unread count via a custom Views field plugin that calls `NotificationManager` (or a custom controller table; **(decide)** Views is the recommendation)
- [ ] Exposed filters: name/email contains, notifications on/off, "has unread"
- [ ] Row actions: **Impersonate** (masquerade link → user home page), **Edit settings** (Step 9 form)

**Done when:** the list shows correct counts that match the seed data, and clicking Impersonate lands on that user's home page.
**Commits:** `Add unread count Views field plugin`, `Add users overview page with filters and impersonate action`

## Step 8: User home page + bell

- [ ] Route `/home` (logged-in users): simple welcome page
- [ ] Block `NotificationBellBlock` placed in the Olivero header: bell icon + unread badge
- [ ] Click the badge to open a dropdown/panel listing visible notifications (unread in bold, type label, expiry)
- [ ] Click a notification: POST to `/rin2/notification/{id}/read` (CSRF token) → marks it read, updates the badge without a page reload (Drupal AJAX or small vanilla JS)
- [ ] Respect `notifications_enabled`: when off, the bell is hidden **(decide: hidden, or shown greyed with "notifications off")**

**Done when:** while impersonating a seeded user, the badge count equals the users-list count, and clicking items lowers it to 0.
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

- [ ] Root `README.md`: requirements (DDEV, Docker), install steps (`ddev start`, `ddev composer install`, `ddev drush si --existing-config -y`, `ddev drush rin2:seed`), login (`ddev drush uli`), **verification walkthrough** matching each spec bullet, how to run tests
- [ ] Design notes (`.md` in the relevant folders): Drupal vs Laravel mapping, users "migration", read-status table design, why libphonenumber over MessageBird (free, offline, no API key)
- [ ] Final check from a **fresh clone**: install from config and follow the README end to end
- [ ] Package: `git archive` is NOT enough (it drops `.git`); use `tar czf rin2-drupal.tar.gz --exclude=vendor --exclude=web/core --exclude=web/modules/contrib --exclude=web/sites/default/files my-drupal11-site`

**Done when:** someone with only DDEV can install and verify every feature from the README.
**Commits:** `Add README with install and verification guide`, `Add design notes`

---

## Open decisions

| # | Question | Recommendation | Your choice |
|---|---|---|---|
| D1 | Unread-count list: Views plugin or custom controller? | Views (filters for free) | |
| D2 | Bell when notifications are switched off | Hide bell entirely | |
| D3 | Default phone region for numbers without `+` | IT (spec author looks Italian) or DE | |
| D4 | "For all" notifications: also visible to users created **after** posting? | Yes (simplest, matches "for all") | |
| D5 | Does turning notifications off also exclude the user from unread counts in the admin list? | No: count stays, setting only affects on-screen display | |
| D6 | Push to GitHub in addition to the tarball? | Yes, private repo | |

## Progress log

| Date | Step | Notes |
|---|---|---|
| 2026-11-09 | Setup | DDEV + Drupal 11.4 installed, `.gitignore`, config sync → `config/sync`, `CLAUDE.md` |
