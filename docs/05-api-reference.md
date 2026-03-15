# 05 — API Reference

All routes are registered under the prefix defined in `config/migration-tool.php` (default: `cms-migration`) and protected by the configured middleware group (default: `web`).

---

## Route Table

| Method | URI | Name | Controller Method |
|:-------|:----|:-----|:------------------|
| `GET` | `/cms-migration` | `migration.index` | `index` |
| `POST` | `/cms-migration/test-connection` | `migration.test-connection` | `testConnection` |
| `POST` | `/cms-migration/analyze` | `migration.analyze` | `analyze` |
| `POST` | `/cms-migration/site-details` | `migration.site-details` | `getSiteDetails` |
| `POST` | `/cms-migration/run-migration` | `migration.run-migration` | `runMigration` |
| `GET` | `/cms-migration/check-progress/{job_id}` | `migration.check-progress` | `checkProgress` |

---

## `GET /cms-migration`

Returns the Blade wizard view.

**Response:** HTML page.

---

## `POST /cms-migration/test-connection`

Validates source database credentials and stores them in the session.

**Request Body:**

```json
{
  "host": "127.0.0.1",
  "port": 3306,
  "database": "source_db_name",
  "username": "root",
  "password": "secret",
  "prefix": "",
  "driver": "mysql"
}
```

**Validation Rules:**

| Field | Rule |
|:------|:-----|
| `host` | `required` |
| `database` | `required` |
| `username` | `required` |
| `port` | `required|numeric` |
| `password` | `nullable` |
| `prefix` | `nullable|string` |
| `driver` | `nullable|string` |

**Success Response:**

```json
{
  "success": true,
  "message": "Connection successful!",
  "target": "https://app.com/cms-migration/analyze"
}
```

**Error Response:**

```json
{
  "success": false,
  "message": "Connection failed: SQLSTATE[HY000] [2002] Connection refused"
}
```

---

## `POST /cms-migration/analyze`

Re-hydrates the session connection and returns aggregate counts and site list from the source database.

**Request Body:** None required (session is used).

**Success Response:**

```json
{
  "success": true,
  "summary": {
    "sites": 3,
    "modules": 45,
    "categories": 120,
    "pages": 8,
    "themes": 2,
    "static_modules": 200
  },
  "sites_list": [
    { "id": 1, "name": "Main Site", "domain": "example.com" },
    { "id": 2, "name": "Blog", "domain": "blog.example.com" }
  ]
}
```

---

## `POST /cms-migration/site-details`

Returns detailed entity counts for a specific source site.

**Request Body:**

```json
{ "site_id": 1 }
```

**Success Response:**

```json
{
  "success": true,
  "details": {
    "categories": 45,
    "modules": 12,
    "pages": 3,
    "themes": 1,
    "module_props": 28
  }
}
```

---

## `POST /cms-migration/run-migration`

Validates the request, creates a progress log entry, and dispatches `ProcessMigration` to the queue.

**Request Body:**

```json
{
  "site_id": 1,
  "conflict_strategy": "rename",
  "copy_media": true,
  "source_root_path": "/var/www/source-cms/public"
}
```

**Validation Rules:**

| Field | Rule |
|:------|:-----|
| `site_id` | `required|numeric` |
| `conflict_strategy` | `required|string|in:terminate,overwrite,rename` |
| `copy_media` | `nullable|boolean` |
| `source_root_path` | `nullable|string` |

**Success Response:**

```json
{
  "success": true,
  "job_id": "mig_67c5a1b2f43e8",
  "message": "Migration started in background"
}
```

---

## `GET /cms-migration/check-progress/{job_id}`

Returns the current status of a dispatched migration job.

**URL Parameters:** `job_id` — The unique ID returned by `run-migration`.

**Response (running):**

```json
{
  "success": true,
  "status": "running: Glue Sync",
  "progress": 75,
  "message": null,
  "results": null
}
```

**Response (completed):**

```json
{
  "success": true,
  "status": "completed",
  "progress": 100,
  "message": null,
  "results": {
    "Context Synchronization": { "langs": "Matched 3 existing", ... },
    "Structural Synchronization": { "site": "Created new site: example.com", ... },
    "Glue Layer Synchronization": { "categories": "Synced categories for site ID: 42", ... },
    "Content Synchronization": { "static_modules": "Synced translations for 15 modules" },
    "Media & Assets Synchronization": { "assets": "Copied 142 files/folders" }
  }
}
```

**Response (failed):**

```json
{
  "success": true,
  "status": "failed",
  "progress": 50,
  "message": "Site with domain example.com already exists. Use 'overwrite' or 'rename' strategy.",
  "results": null
}
```

**Response (job not found):**

```json
{
  "success": false,
  "message": "Job not found"
}
```
