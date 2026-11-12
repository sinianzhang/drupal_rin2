# Design note: the users-table "migration"

## Requirement

> Do the new database migrations for "users" considering that the table is
> already migrated, seeded and in production.
> - Add the notification switch as not null
> - Add the phone number as nullable

In Laravel this is a migration that runs `Schema::table('users', …)` with `php artisan migrate`.
This note explains the Drupal 11 equivalent and the choices behind it.

## Result

Two columns on `users_field_data` (Drupal's users table):

| Column | Type | Null | Default for new users | Existing users |
|---|---|---|---|---|
| `notifications_enabled` | `tinyint(4)` | **NOT NULL** | `1` (on) | backfilled with `1` |
| `phone` | `varchar(32)` | NULL | `NULL` | stay `NULL` |

## Approach

### 1. Base fields, not Field UI fields

Fields are defined in code in `rin2_notify_entity_base_field_info()` (`rin2_notify.module`).

| | Base field (chosen) | Field UI / config field |
|---|---|---|
| Storage | Column in `users_field_data`, next to `name` and `mail` | Separate table per field (`user__field_phone`) |
| Defined in | PHP, versioned with the module | YAML config in `config/sync` |
| NOT NULL possible | Yes (see section 3) | No, rows only exist when a value is set |
| Matches "add a column to users" | Yes | No |

The definitions live in one function, `rin2_notify_user_base_field_definitions()`, so the hook and the update functions cannot drift apart.

### 2. Update hooks as migrations

`hook_update_N()` in `rin2_notify.install` is Drupal's migration. Each update runs once (`ddev drush updb`), and Drupal records the last one applied per module, like Laravel's `migrations` table.

| Update | What it does |
|---|---|
| `rin2_notify_update_11001` | Installs both field storage definitions on the existing user table. `setInitialValue(TRUE)` fills every existing row with `1` while the column is added, so no separate backfill query is needed. |
| `rin2_notify_update_11002` | Adds the NOT NULL constraint to `notifications_enabled` (see section 3). |

On a **fresh install** no update runs. Installing the module creates the columns directly from the base field definitions and marks 11001 and 11002 as already applied (`ModuleInstaller` sets the installed version to the latest update). Module install creates field storage through the *current* user entity type, which already carries `Rin2UserStorageSchema`, so the column should be NOT NULL straight away. This path is not yet verified; an automated test will cover it (see "Verify").

### 3. NOT NULL needs a custom storage schema

`BaseFieldDefinition::setStorageRequired(TRUE)` is stored, but core ignores it for shared tables such as `users_field_data`. Only entity keys (`uid`, `langcode`, …) get NOT NULL there:

```php
// core/lib/Drupal/Core/Entity/Sql/SqlContentEntityStorageSchema.php
// "For now, we only hardcode 'not null' to a few "entity keys" …
//  @todo Fix this in https://www.drupal.org/node/2841291."
$schema['fields'][$schema_field_name]['not null'] = in_array($field_name, $not_null_keys);
```

So 11001 alone produced a nullable column. The fix follows the pattern core itself uses for the user `name` column in `UserStorageSchema`:

- `src/Rin2UserStorageSchema.php` extends core's `UserStorageSchema` and sets `'not null' => TRUE` for `notifications_enabled`.
- `rin2_notify_entity_type_alter()` makes it the user entity's `storage_schema` handler.
- Update 11002 records the new handler on the installed entity type (`updateEntityType()`), then re-applies the field definition (`updateFieldStorageDefinition()`). Core compares the old and new schema and runs `ALTER TABLE … MODIFY … NOT NULL`. It refuses if any row holds NULL; after 11001 none do.

The handler must be recorded first because core applies field schema updates with the *last-installed* entity type definition, which still names core's handler.

**Why not a raw `ALTER TABLE`:** Drupal keeps its own copy of every entity table's schema (key-value collection `entity.storage_schema.sql`). A manual change would leave that copy saying "nullable", and a later field update could silently revert the constraint. Going through the storage schema handler keeps Drupal's record and the real table in sync.

**Trade-off:** only one module can own the user `storage_schema` handler. If another module replaces it later, `Rin2UserStorageSchema` must extend that class instead.

### 4. Phone as a plain string

- `string` with `max_length` 32, rather than core's `telephone` field type. `telephone` needs the core Telephone module and only adds a `tel:` link formatter.
- Numbers are stored normalized in E.164 format (`+393331234567`).
- Validation as a real **mobile** number (libphonenumber) is done in the settings form (Step 9). It is not a database concern.

## Verify

```bash
ddev drush updatedb:status    # "No database updates required."
ddev drush sqlq "SHOW CREATE TABLE users_field_data" | tr '\\' '\n' | grep -E "notifications|phone"
#   `notifications_enabled` tinyint(4) NOT NULL,
#   `phone` varchar(32) DEFAULT NULL,
ddev drush sqlq "SELECT uid, name, notifications_enabled, phone FROM users_field_data"
#   every existing user: notifications_enabled = 1, phone = NULL
```

The status report (`/admin/reports/status`) shows "Entity/field definitions: Up to date".

Writing NULL directly is rejected by the database:

```sql
UPDATE users_field_data SET notifications_enabled = NULL WHERE uid = 1;
-- ERROR 1048: Column 'notifications_enabled' cannot be null
```

The fresh-install path (constraint created without running updates) is covered by an automated test in a later step.
