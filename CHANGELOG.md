# Changelog

All notable changes to `hashtagcms/migration-tool` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added

- PHPUnit + Testbench scaffold for package-level regression checks.
- Regression test coverage for:
	- Query builder clone usage in `SyncContentStep`.
	- MariaDB PDO extension mapping in `MigrationController` pre-flight checks.
- Integration-style HTTP test for `/cms-migration/check-requirements` to verify MariaDB driver reports `PDO Driver: pdo_mysql`.
- Regression test coverage for legacy `tenants` table and `tenant_id` to `platform_id` mapping logic in migration steps.
- Configurable auto-start of `queue:work --once` when migration is dispatched (`migration-tool.auto_queue_work_once`).
- Controller-level access restriction so only users with `user_type = Staff` can use the migration tool.
- Automatic provisioning of missing target tables from source schema before dispatching migration.

### Fixed

- Replaced invalid Query Builder method-style clone calls with native `(clone $query)` usage in page sync flow.
- Corrected PDO extension detection to map `mariadb` driver to `pdo_mysql`.
- Fixed queue crash at 90% by replacing `Artisan::has()` with safe command discovery via `Artisan::all()`.
- Updated async job error handling to catch `Throwable` so migration logs are marked `failed` for non-Exception errors.
- Added clear user-facing error when missing target tables cannot be created due to insufficient CREATE TABLE permission.

---

## [1.1.0] — 2026-02-25

### Added

- **Asynchronous ETL Pipeline** — Migration dispatched as a Laravel `ShouldQueue` job (`ProcessMigration`).
- **`cms_migration_logs` table** — Tracks job status, progress percentage, and per-step results.
- **Real-time Progress UI** — New `step === 'migrating'` wizard screen with animated spinner and progress bar.
- **`/check-progress/{job_id}` endpoint** — Returns live status and percentage from the log table.
- **`syncCategorySite()` method** — Idempotent sync of the `category_site` pivot with `category_id`, `site_id`, and `theme_id` mapping.
- **Rename Conflict Strategy** — Appends `-migrated-{timestamp}` to domain, non-destructive copy.
- **`SyncMediaStep`** — Recursive asset sync using Symfony Finder streaming. Covers `assets/` and `storage/`.
- **`source_root_path` UI input** — Conditionally visible when media migration is enabled.
- **`conflict_strategy` UI dropdown** — Terminate (default), Overwrite, Rename.
- **Post-Structural Relational Pass** — `sites.theme_id` auto-updated after themes are migrated.

### Security

- **CSRF Protection** — `axios.defaults.headers.common['X-CSRF-TOKEN']` set globally before Vue mounts.
- **Session-based Connection Persistence** — Source DB config stored in PHP session, re-hydrated via `ensureSourceConnection()`.

### Performance

- **N+1 Eliminated** — Translations fetched using `whereIn` batching (chunk size 200).
- **Memory-Safe Media Sync** — `File::allFiles()` replaced with Symfony Finder iterator.

### Reliability

- **`updateOrInsert` for all pivot tables** — `syncPivotTable()`, `syncCategorySite()`, `syncSitePivot()` are now idempotent.
- **`updateOrInsert` in `SyncContentStep`** — Translation upserts keyed by `(entity_id, lang_id)`.

### Fixed

- Source DB config not persisted between wizard steps (PHP statelessness).
- Table prefix ignored; now correctly applied to dynamic connection.
- Database driver hardcoded to `mysql`; now uses UI-provided driver.
- `DIRECTORY_ROOT` typo — replaced with `DIRECTORY_SEPARATOR`.
- Flat media copy bug — fixed to use `getRelativePathname()` for nested folder structure.

---

## [1.0.0] — 2026-02-24

### Added (Initial Release)

- `MigrationToolServiceProvider` with auto-discovery, config publish, routes and views.
- `MigrationController` — REST API for wizard (test-connection, analyze, site-details, run-migration).
- `SiteMigrationService` — In-memory ID Mapping Engine with `addToMap`, `getFromMap`, `getFullMap`. Full DB transaction wrapping.
- `MigrationStepInterface` and `AbstractMigrationStep` base class.
- `SyncContextStep` — Layer 1: langs, platforms, countries, currencies, zones.
- `SyncStructuralStep` — Layer 2: sites (domain conflict), themes, hooks, modules.
- `SyncGlueStep` — Layer 3: categories (hierarchy), module_props, module_site pivot.
- `SyncContentStep` — Layer 4: static_module_contents, category_langs, module_prop_langs.
- Vue 3 Wizard UI (Connect → Discover → Review → Success).
- 6 named routes under `cms-migration` prefix group.
- `config/migration-tool.php` with `prefix` and `middleware` keys.
