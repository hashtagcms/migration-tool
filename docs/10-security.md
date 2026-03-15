# 10 — Security

The Migration Tool connects to external databases and writes significant amounts of data. This document covers the security considerations built into the package and the recommendations for hardening a production deployment.

---

## CSRF Protection

All API endpoints are registered under the `web` middleware group, which includes Laravel's `VerifyCsrfToken` middleware.

The wizard injects the CSRF token into every Axios request at page load:

```javascript
axios.defaults.headers.common['X-CSRF-TOKEN'] = '{{ csrf_token() }}';
```

This is set **before** the Vue application mounts, ensuring all POST requests (test-connection, analyze, run-migration) pass the CSRF check and `419 Unknown Status` errors are prevented.

---

## Session-Based Connection Persistence

Source database credentials are stored in the **PHP session** (server-side) on the target server — never in the browser's local storage, cookies, or URL parameters.

```php
// Stored after successful test-connection
session(['migration_source_db' => $config]);

// Re-hydrated on every subsequent request
$config = session('migration_source_db');
Config::set("database.connections.temp_source_connection", $config);
```

**Why this is safe:**

- Session data is stored on the server (file system or Redis).
- The session cookie is `HttpOnly` and CSRF-protected.
- Credentials are never exposed in API responses.

---

## Dynamic Connection Isolation

The source database connection is always registered as `temp_source_connection` — a name that does **not** conflict with any of the standard Laravel connections (`mysql`, `pgsql`, etc.).

This ensures:
- All reads from the source DB use `DB::connection('temp_source_connection')->table(...)`.
- All writes to the target DB use the default `DB::table(...)`.
- There is **no risk** of accidentally writing source data into the target using the wrong connection method.

---

## Input Validation

All API endpoints validate their inputs with Laravel's validation:

| Endpoint | Validated Fields |
|:---------|:-----------------|
| `test-connection` | host, database, username, port (required); password, prefix, driver (nullable) |
| `site-details` | site_id (required, numeric) |
| `run-migration` | site_id (required, numeric); conflict_strategy (required, in:terminate,overwrite,rename); copy_media (nullable, boolean); source_root_path (nullable, string) |

The `conflict_strategy` validation (`in:terminate,overwrite,rename`) prevents arbitrary string values from being used in the MySQL query.

---

## Background Job Security

Credentials are passed to the `ProcessMigration` job as a constructor argument. Laravel serializes these via `SerializesModels`. For additional security in production:

- **Encrypt the Queue Payload.** If using Redis or SQS, ensure the connection is encrypted (TLS).
- **Restrict Queue Worker Access.** The queue worker should not be accessible from the public internet.
- **Use Read-Only Credentials.** The source database user should ideally be configured with `SELECT` privileges only. The tool only reads from the source.

---

## Filesystem Security (Media Sync)

The `source_root_path` is not sanitized beyond PHP's native file system access controls. To prevent abuse:

- Only trusted, authenticated users should have access to the wizard (see below).
- The path must be locally accessible from the web server process — remote paths cannot be abused.
- The step only **reads** from the source path and **writes** to `public_path()` on the target.

---

## Access Control Recommendations

By default, the wizard routes use only the `web` middleware. For production, restrict access:

**Option 1: Add `auth` middleware in config:**

```php
// config/migration-tool.php
return [
    'prefix'     => 'cms-migration',
    'middleware' => ['web', 'auth'],
];
```

**Option 2: Add an admin role check:**

```php
'middleware' => ['web', 'auth', 'can:manage-admin'],
```

**Option 3: Use IP allowlisting at the web server level (Nginx):**

```nginx
location /cms-migration {
    allow 192.168.1.100;
    deny all;
    # ... proxy settings
}
```

---

## Known Risks & Mitigations

| Risk | Mitigation |
|:-----|:-----------|
| Source DB credentials exposed | Stored in server-side session only |
| CSRF attacks | `X-CSRF-TOKEN` on all POST requests |
| SQL Injection via table names | Table names are hardcoded in step classes — not user input |
| Overwrite strategy deletes data | UI warns with `DANGEROUS` label; requires explicit selection |
| Long-running job timeout | Queue worker with `--timeout=600`; progress tracked independently |
| Media path traversal | Path must be locally accessible; no traversal is possible across network |
