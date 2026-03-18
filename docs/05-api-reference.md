# 05 — API Reference

All routes are registered under the prefix in `config/migration-tool.php` (default: `cms-migration`) and use configured middleware (default: `['web', 'auth']`).

In addition, the controller enforces:
- authenticated user with `user_type = Staff`
- non-Staff users receive `403` with message: `Visitors are not allowed to access the migration tool.`

---

## Route Table

| Method | URI | Name | Controller Method |
|:-------|:----|:-----|:------------------|
| `GET` | `/cms-migration` | `migration.index` | `index` |
| `POST` | `/cms-migration/test-connection` | `migration.test-connection` | `testConnection` |
| `POST` | `/cms-migration/analyze` | `migration.analyze` | `analyze` |
| `POST` | `/cms-migration/site-details` | `migration.site-details` | `getSiteDetails` |
| `POST` | `/cms-migration/run-migration` | `migration.run-migration` | `runMigration` |
| `POST` | `/cms-migration/migrate-templates` | `migration.migrate-templates` | `migrateTemplates` |
| `POST` | `/cms-migration/check-requirements` | `migration.check-requirements` | `checkRequirements` |
| `GET` | `/cms-migration/check-progress/{job_id}` | `migration.check-progress` | `checkProgress` |

---

## Access Errors

### `403 Forbidden` (non-Staff)

```json
{
  "success": false,
  "message": "Visitors are not allowed to access the migration tool."
}
```

### `400 Bad Request` (session missing source DB config)

```json
{
  "message": "Source database connection not configured in session."
}
```

---

## `GET /cms-migration`

Returns the Blade wizard UI.

---

## `POST /cms-migration/test-connection`

Validates source connection payload, stores source DB config in session, tests PDO connectivity, and detects legacy schema mode.

**Request fields:**
- `host` (required)
- `port` (required, numeric)
- `database` (required)
- `username` (required)
- `password` (nullable)
- `prefix` (nullable)
- `driver` (nullable, defaults to `mysql`)

**Success response:**

```json
{
  "success": true,
  "message": "Connection successful!",
  "target": "https://app.test/cms-migration/analyze",
  "legacy": true
}
```

---

## `POST /cms-migration/analyze`

Uses source DB session config to return source summary and discovered sites.

**Success response shape:**

```json
{
  "success": true,
  "summary": {
    "sites": 3,
    "users": 50,
    "roles": 5,
    "modules": 20,
    "categories": 120,
    "pages": 40,
    "themes": 2,
    "static_contents": 400,
    "galleries": 15,
    "site_props": 12,
    "platforms": 4
  },
  "package_warnings": ["vendor/package-a"],
  "sites_list": [{ "id": 1, "name": "Main", "domain": "example.com" }]
}
```

---

## `POST /cms-migration/site-details`

Returns detailed counts for one source site.

**Request:**

```json
{ "site_id": 1 }
```

---

## `POST /cms-migration/check-requirements`

Runs pre-flight checks before migration.

**Request:**

```json
{
  "site_id": 1,
  "copy_media": true,
  "source_root_path": "/var/www/old-site"
}
```

**Response:**
- `checks`: array of `{label,status,message,critical}`
- `can_proceed`: bool
- `critical_failure`: bool

---

## `POST /cms-migration/run-migration`

Creates `cms_migration_logs` row and dispatches `ProcessMigration` job.

Before dispatch, the controller checks for package-managed tables missing in target DB:
- if possible, it auto-creates them from source schema
- if CREATE TABLE permission is missing, request is blocked with an explicit message and missing table list

**Request:**

```json
{
  "site_id": 1,
  "conflict_strategy": "rename",
  "copy_media": true,
  "source_root_path": "/var/www/old-site"
}
```

**Success:**

```json
{
  "success": true,
  "job_id": "24192de0-d837-4ade-80d1-4129cb4d5123",
  "message": "Migration started in background",
  "auto_created_tables": ["recommendation"]
}
```

When `migration-tool.auto_queue_work_once = true`, dispatch also auto-starts one queue worker process (`queue:work --once`).

**Failure when table creation is not allowed:**

```json
{
  "success": false,
  "message": "Unable to create missing target tables due to insufficient CREATE TABLE permission. Please create these tables first in target database and then run this migration tool again: recommendation.",
  "missing_tables": ["recommendation"]
}
```

---

## `POST /cms-migration/migrate-templates`

Runs template migration independently from data migration.

**Request:**

```json
{
  "site_id": 1,
  "source_root": "/absolute/path/to/source/laravel"
}
```

---

## `GET /cms-migration/check-progress/{job_id}`

Returns live migration state from `cms_migration_logs`.

**Running example:**

```json
{
  "success": true,
  "status": "running: Validation",
  "progress": 90,
  "message": null,
  "results": null
}
```

**Completed example:**

```json
{
  "success": true,
  "status": "completed",
  "progress": 100,
  "message": null,
  "results": { "...": "..." }
}
```

**Failed example:**

```json
{
  "success": true,
  "status": "failed",
  "progress": 90,
  "message": "Exact exception text",
  "results": null
}
```
