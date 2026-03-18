# 13 — Regression Smoke Checks

Use this checklist in your host Laravel application (the app that installs this package) to validate key fixed regressions:

1. Query builder clone behavior in page migration
2. MariaDB PDO extension detection in pre-flight checks
3. Finalization stability around 90% progress
4. Access guard enforcement for non-Staff users

---

## Preconditions

- Package installed and discovered
- Source database credentials available
- Queue worker running for async migration

```bash
php artisan queue:work
```

---

## A. Route & Wizard Availability

```bash
php artisan route:list --path=cms-migration
```

Expected:
- Routes under `/cms-migration` are listed
- Includes `migration.check-requirements`, `migration.run-migration`, and `migration.check-progress`

---

## B. MariaDB PDO Pre-flight Regression

1. Open the wizard in browser:
   - `/cms-migration`
2. Connect using a MariaDB source connection.
3. Go to **Pre-flight Check**.

Expected:
- `PDO Driver: pdo_mysql` shows **pass** when PDO MySQL extension is installed.
- It should not report a false failure for `pdo_mariadb`.

---

## C. Page Sync Clone Regression

1. Start migration for a site that has records in `pages`.
2. Monitor progress until completion.

Expected:
- Migration does not fail with method errors related to cloning.
- No error such as:
  - `Call to undefined method ...::clone()`
- `Content Layer Synchronization` finishes and progress reaches completion.

---

## D. Finalization (90% Crash) Regression

1. Run migration for a valid site and wait until validation phase (~90%).
2. Confirm process proceeds to completion.

Expected:
- No crash related to cache command checks.
- Job transitions to `completed` with `progress = 100`.

---

## E. Access Guard Regression (Staff Only)

1. Access route as authenticated user with `user_type = Visitors`.
2. Try any JSON endpoint (for example, pre-flight check).

Expected:
- HTTP `403`.
- JSON body includes:
  - `success: false`
  - `message: Visitors are not allowed to access the migration tool.`

---

## F. Optional Log Validation

Check migration logs table:

```sql
SELECT job_id, status, progress, message
FROM cms_migration_logs
ORDER BY id DESC
LIMIT 5;
```

Expected:
- Recent job reaches `status = completed` and `progress = 100`.
- `message` should be null or non-fatal.

---

## G. Quick CLI Syntax Sanity (Package Workspace)

```bash
php -l src/Steps/SyncContentStep.php
php -l src/Http/Controllers/MigrationController.php
```

Expected:
- `No syntax errors detected` for both files.
