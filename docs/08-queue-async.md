# 08 — Queue & Async Jobs

The Migration Tool is designed for **production reliability**. Running a large-scale database migration synchronously inside an HTTP request is unsafe — it will fail due to web server timeouts (typically 30–60 seconds). The tool solves this with a native Laravel Queue Job.

---

## Architecture

```
HTTP Request (< 1s)                   Background Queue Worker
        │                                       │
        │  POST /run-migration                  │
        ▼                                       │
MigrationController                            │
  1. Validates request                          │
  2. Creates cms_migration_logs row            │
  3. dispatch(ProcessMigration::class) ─────▶  │
  4. Returns { job_id }                         │  ProcessMigration::handle()
        │                                       │    re-hydrates DB connection
        │                                       │    runs migration pipeline steps
        │                                       │    updates log on each step
Wizard polls /check-progress/{id}  ◀──────────  │    final: status=completed
  every 2 seconds                               │
```

---

## The `ProcessMigration` Job

**File:** `src/Jobs/ProcessMigration.php`

**Implements:** `ShouldQueue`

**Traits Used:** `Dispatchable`, `InteractsWithQueue`, `Queueable`, `SerializesModels`

### Constructor Arguments

| Parameter | Type | Description |
|:----------|:-----|:------------|
| `$siteId` | `int` | Source site ID to migrate |
| `$config` | `array` | Migration options (conflict strategy, media, etc.) |
| `$dbConfig` | `array` | Full source DB connection config array |
| `$logId` | `string` | Unique job identifier (e.g., `mig_67c5a1b2f43e8`) |

> **Why pass `$dbConfig` in the constructor?**  
> PHP queue workers run in a separate process with no access to the original HTTP session. By serializing the DB config into the job payload, we ensure the worker can always re-hydrate the dynamic `temp_source_connection` regardless of session state.

### Execution Flow

```php
public function handle(): void
{
    config(["database.connections.temp_source_connection" => $this->dbConfig]);

    // Mark as running
    DB::table('cms_migration_logs')->where('job_id', $this->logId)->update(['status' => 'running', 'progress' => 10]);

    // Run each step and update progress
    foreach ($steps as $item) {
        DB::table('cms_migration_logs')->update(['status' => 'running: ' . $item['name'], 'progress' => $item['progress']]);
        $res = $item['step']->execute($this->siteId, $this->config);
        $allResults[$item['step']->getName()] = $res;
    }

    // Mark as completed
    DB::table('cms_migration_logs')->update(['status' => 'completed', 'progress' => 100, 'results' => json_encode($allResults)]);
}
```

---

## The `cms_migration_logs` Table

Created by the package migration: `2026_02_25_000000_create_cms_migration_logs_table.php`

| Column | Type | Description |
|:-------|:-----|:------------|
| `id` | `bigint` | Auto-increment PK |
| `job_id` | `varchar` | Unique job identifier (indexed) |
| `site_id` | `int` | Source site being migrated |
| `status` | `varchar` | `pending`, `running: Step Name`, `completed`, `failed` |
| `progress` | `int` | 0–100 percentage |
| `message` | `text\|null` | Error message if failed |
| `results` | `json\|null` | Final per-step result data |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

---

## Progress States

| Status Value | Meaning |
|:-------------|:--------|
| `pending` | Job queued, not yet picked up by worker |
| `running: Context Sync` | Layer 1 executing (25%) |
| `running: Structural Sync` | Layer 2 executing (50%) |
| `running: Glue Sync` | Layer 3 executing (75%) |
| `running: Content Sync` | Layer 4 executing (90%) |
| `running: Media Sync` | Assets copying (95%) |
| `completed` | All steps passed (100%) |
| `failed` | An exception was thrown (rollback executed) |

---

## Queue Configuration

### Recommended `.env` Settings

```dotenv
QUEUE_CONNECTION=database     # or redis, sqs
```

### Worker Start Command

```bash
# Development
php artisan queue:work

# Production (long-running migrations)
php artisan queue:work --tries=1 --timeout=600 --memory=512
```

### Auto-run One Job Worker (Package Option)

If you want the package to automatically run the queued migration job without manually running:

```bash
php artisan queue:work --once
```

set this in `config/migration-tool.php`:

```php
'auto_queue_work_once' => true,
```

When enabled, the package starts a background `queue:work --once` process right after dispatching the migration job.

**Options Explained:**

| Option | Value | Reason |
|:-------|:------|:-------|
| `--tries` | `1` | No automatic retries — migration is transactional. A retry would re-run a partially-committed migration. |
| `--timeout` | `600` | Allow up to 10 minutes for large datasets + media sync. |
| `--memory` | `512` | Sufficient for most sites; increase for very large datasets. |

---

## Error Handling

If any pipeline step or finalization logic throws an error/exception:

1. The Laravel database transaction is rolled back (no partial data in target DB).
2. The `cms_migration_logs` row is updated: `status = 'failed'`, `message = <error message>`.
3. The exception is logged to Laravel's log file via `Log::error()`.
4. The wizard UI detects `status === 'failed'` during polling and shows an alert with the error message.

---

## Polling Implementation (Frontend)

```javascript
const startPolling = (jobId) => {
    pollInterval = setInterval(async () => {
        const response = await axios.get(`/cms-migration/check-progress/${jobId}`);
        
        progress.value = response.data.progress;
        migrationMessage.value = response.data.status.toUpperCase();

        if (response.data.status === 'completed') {
            clearInterval(pollInterval);
            migrationResults.value = response.data.results;
            step.value = 'success';
        } else if (response.data.status === 'failed') {
            clearInterval(pollInterval);
            alert('Migration failed: ' + response.data.message);
            step.value = 'review';
        }
    }, 2000); // Poll every 2 seconds
};
```
