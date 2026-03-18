# 02 — Installation & Configuration

## Prerequisites

Before installing, ensure the following are available on the **target** server:

- PHP `^8.2`
- Laravel `10.x` or higher
- `hashtagcms/hashtagcms` core package installed and configured
- A configured queue driver (`database`, `redis`, or `sqs`)
- Network access from the target server to the **source** database host

---

## Step 1: Install via Composer

```bash
composer require hashtagcms/migration-tool
```

Laravel's package auto-discovery will automatically register the `MigrationToolServiceProvider`.

---

## Step 2: Run Package Migrations

The package ships with a migration for the progress-tracking table:

```bash
php artisan migrate
```

This creates the `cms_migration_logs` table.

---

## Step 3: Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag="migration-tool-config"
```

This will create `config/migration-tool.php`:

```php
<?php

return [
    'prefix'     => 'cms-migration',   // URL prefix for all wizard routes
    'middleware' => ['web', 'auth'],    // Middleware group applied to all routes
    'auto_queue_work_once' => true,     // Auto-runs one queue worker on dispatch
];
```

> **Tip:** Even if you override middleware, the controller also blocks non-Staff users (`user_type !== 'Staff'`).

---

## Step 4: Configure a Queue Driver

The migration runs as a background job. Configure your queue in `.env`:

```dotenv
# Recommended for simplicity
QUEUE_CONNECTION=database

# Or Redis for high throughput
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

If using the `database` driver, create the jobs table:

```bash
php artisan queue:table
php artisan migrate
```

---

## Step 5: Start the Queue Worker

```bash
# For development
php artisan queue:work

# For production (using Supervisor)
php artisan queue:work --tries=1 --timeout=600
```

> **Important:** Set `--timeout` to at least `600` seconds (10 minutes) for large sites.

If `auto_queue_work_once` is enabled (default), dispatching a migration from the wizard auto-starts a one-shot worker process (`queue:work --once`) for convenience.

---

## Step 6: Access the Wizard

Navigate to:

```
https://your-target-app.com/cms-migration
```

Or use the named route:

```php
route('migration.index')
```

---

## Environment Requirements

The **source** database credentials are entered via the wizard UI at runtime — no `.env` changes are needed on the target server for the source database.

The **target** database uses the standard Laravel `DB_*` environment variables.

---

## Recommended Supervisor Configuration

```ini
[program:hashtagcms-migration-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work --queue=default --tries=1 --timeout=600
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/hashtagcms-migration.log
```
