# 01 — Architecture Overview

## Package Identity

| Property | Value |
|:---|:---|
| **Package Name** | `hashtagcms/migration-tool` |
| **Namespace** | `HashtagCms\MigrationTool` |
| **Type** | Laravel Package |
| **License** | MIT |
| **Author** | Marghoob Suleman |

---

## Design Philosophy

The Migration Tool is built around three core principles:

1. **ETL (Extract → Transform → Load)** — Data is never mutated in-place. It is extracted from the source, IDs are remapped, then loaded into the target.
2. **Layered Dependency Order** — Migration proceeds through exactly four data layers in strict order to respect foreign key dependencies.
3. **Async-First** — The migration process runs in a Laravel background job to prevent HTTP timeouts and to support large datasets.

---

## The Four Data Layers

```
┌─────────────────────────────────────────────────────┐
│ LAYER 1: CONTEXT                                    │
│  langs, platforms, countries, currencies, zones     │
│  (Global, site-independent, matched by code/alias)  │
└────────────────────────┬────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────┐
│ LAYER 2: STRUCTURAL                                 │
│  sites, themes, hooks, modules                      │
│  (Site-specific definitions and configurations)     │
└────────────────────────┬────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────┐
│ LAYER 3: GLUE                                       │
│  categories, module_props, category_site,           │
│  module_site, lang_site, platform_site              │
│  (Relationships binding Layers 1 & 2 together)      │
└────────────────────────┬────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────┐
│ LAYER 4: CONTENT                                    │
│  static_module_contents, category_langs,            │
│  module_prop_langs (Translatable content/i18n)      │
└─────────────────────────────────────────────────────┘
```

---

## Class Map

```
src/
├── MigrationToolServiceProvider.php     # Registers routes, migrations, config
│
├── Http/Controllers/
│   └── MigrationController.php          # REST API for the UI wizard
│
├── Jobs/
│   └── ProcessMigration.php             # Laravel Queued Background Job
│
├── Services/
│   └── SiteMigrationService.php         # Orchestrator + ID Mapping Engine
│
└── Steps/
    ├── MigrationStepInterface.php        # Contract: execute() + getName()
    ├── AbstractMigrationStep.php         # Base: syncTable, transform helpers
    ├── SyncContextStep.php               # Layer 1 – global context tables
    ├── SyncStructuralStep.php            # Layer 2 – site, themes, hooks, modules
    ├── SyncGlueStep.php                  # Layer 3 – categories, pivots
    ├── SyncContentStep.php               # Layer 4 – translations
    └── SyncMediaStep.php                 # Asset file sync (Symfony Finder)

database/migrations/
    └── ..._create_cms_migration_logs_table.php

resources/views/
    └── index.blade.php                   # Vue 3 Composition API Wizard UI

routes/
    └── web.php                           # 6 named HTTP routes

config/
    └── migration-tool.php                # Prefix + middleware config
```

---

## Data Flow Diagram

```
Browser Wizard
     │
     │ POST /cms-migration/run-migration
     ▼
MigrationController
     │  validates + stores job_id in cms_migration_logs
     │
     │ dispatch(ProcessMigration::class)
     ▼
Queue Worker (background)
     │
     │  re-hydrates temp_source_connection from job payload
     │
     ├──▶ SyncContextStep.execute()   [progress: 25%]
     ├──▶ SyncStructuralStep.execute() [progress: 50%]
     ├──▶ SyncGlueStep.execute()      [progress: 75%]
     ├──▶ SyncContentStep.execute()   [progress: 90%]
     └──▶ SyncMediaStep.execute()     [progress: 95%]
              │
              └──▶ cms_migration_logs: status=completed, progress=100
                        ▲
                        │  GET /cms-migration/check-progress/{job_id}
                   Browser polls every 2s
```

---

## ID Mapping Engine

The `SiteMigrationService` holds an in-memory map:

```php
protected array $idMap = []; // [table_name => [old_id => new_id]]
```

Every time a row is inserted into the target database, its new auto-incremented ID is registered:

```php
$this->service->addToMap('categories', $oldId, $newId);
```

And any subsequent step can resolve a foreign key:

```php
$row['category_id'] = $this->service->getFromMap('categories', $row['category_id']);
```

This ensures complete relational integrity across all four layers without any manual ID lookups.
