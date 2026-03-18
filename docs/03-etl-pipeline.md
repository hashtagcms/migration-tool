# 03 — The ETL Pipeline

The core of the Migration Tool is a six-step ETL pipeline, orchestrated by `SiteMigrationService` and executed inside a Laravel background job (`ProcessMigration`).

---

## Pipeline Overview

| Step | Class | Layer | Coverage | Progress |
|:-----|:------|:------|:---------|:---------|
| 1 | `SyncContextStep` | Context | langs, platforms, countries, users, roles, permissions, tags, cms_modules | 25% |
| 2 | `SyncStructuralStep` | Structural | sites, themes, site_langs, modules, site_props | 50% |
| 3 | `SyncGlueStep` | Glue | categories, galleries, microsites, festivals, comments, subscribers | 75% |
| 4 | `SyncContentStep` | Content | translations for all entities, pages, gallery pivots | 90% |
| 5 | `SyncValidationStep` | Validation | integrity check and record count comparison | 90% |
| 6 | `SyncMediaStep` | Assets | physical files in `assets/` and `storage/` | 95% |

All database-heavy steps (1-4) run inside a scoped `DB::beginTransaction()`. Steps 5 and 6 run post-commit.

---

## Step 1 — SyncContextStep

**Purpose:** Migrate global entities that exist independently of any site.

**Strategy:** Match by a unique identifier code (e.g., ISO language code, alias) rather than ID. If the entity already exists in the target, its existing ID is mapped — no duplicate is created.

**Tables:**

| Table | Match Column |
|:------|:-------------|
| `langs` | `iso_code` |
| `platforms` | `name` |
| `countries` | `iso_code` |
| `cities` | `name` + `country_id` |
| `users` | `email` |
| `roles` / `permissions` | `name` |
| `cms_modules` | `controller_name` |

**Special Handling:** 
- **Cities:** Deduplicated by composite (name + mapped country_id).
- **Migrations:** Merged into the target `migrations` table at `max(batch) + 1`.
- **Global Pivots:** `permission_role` and `role_user` are remapped and synced.

---

## Step 2 — SyncStructuralStep

**Purpose:** Migrate the site and all of its structural definition records.

**Execution Order (important for FK integrity):**

1. **Site** — Handles domain conflict using the configured strategy (Terminate / Overwrite / Rename). Stores `new_site_id` in the map.
2. **Themes** — Migrated and mapped.
3. **Post-Structural Pass** — `sites.theme_id` is updated using the freshly-built theme map. This solves the circular dependency where a site references a theme that didn't exist yet.
4. **Hooks** — Matched by `alias`.
5. **Modules** — Matched by `alias`.

**Conflict Resolution:**

| Strategy | Behaviour |
|:---------|:----------|
| `terminate` | Throws an exception if the domain already exists. Safe default. |
| `overwrite` | Updates the existing site record (clears `theme_id` until post-sync pass). |
| `rename` | Appends `-migrated-{timestamp}` to the domain. |

**Additional Tables:**
- `site_props`: Deduplicated by `(site_id, platform_id, name, group_name)`.
- `site_user`: Remaps global users to this specific site.
- `theme_langs` / `module_langs`: (Moved to Content Sync).

---

## Step 3 — SyncGlueStep

**Purpose:** Migrate all relational data that binds sites to content.

**Execution Order:**

1. **Categories** — Migrated recursively. `parent_id` is resolved using the category map after all top-level categories are processed first, preserving the hierarchy.
2. **category_site** — The pivot linking categories to sites (with theme context). Uses `updateOrInsert` for idempotency.
3. **module_props** — Module-specific property definitions.
4. **module_site** — The "master glue" pivot. Remaps `site_id`, `platform_id`, `category_id`, `hook_id`, and `module_id` (5 FKs per row).

---

## Step 4 — SyncContentStep

**Purpose:** Migrate all translatable content (i18n rows).

**Performance Note:** Uses `whereIn` batching with a chunk size of **200** to prevent N+1 query explosion. For a site with 5,000 categories × 3 langs = 15,000 rows, only **25 queries** are executed instead of 5,000.

**Tables:**

| Table | FK Column |
|:------|:----------|
| `static_module_contents` | `module_id` |
| `category_langs` | `category_id` |
| `module_prop_langs` | `module_prop_id` |

**Idempotency:** Uses `DB::updateOrInsert([$idColumn => $newId, 'lang_id' => $newLangId], $data)` to prevent duplicate translation rows on re-runs.

---

## Step 5 — SyncValidationStep

**Purpose:** Provide a post-migration integrity report.

**Verification Profile:**
- Compares record counts for core entities (themes, modules, categories, pages, etc.) between the source site and the new target site.
- Flags any "MISMATCH" or "OK" status per table.
- Runs post-commit, ensuring validation reflects the final state of the database.

---

## Step 6 — SyncMediaStep

**Purpose:** Copy physical asset files from the source filesystem to the target filesystem.

**Activation:** Only runs if `copy_media: true` is set and a valid `source_root_path` is provided.

**Implementation Details:**

- Uses **Symfony Finder** iterator (streaming) instead of `File::allFiles()` to prevent memory exhaustion on large asset libraries.
- Scans `assets/` and `storage/` directories from the specified source root.
- Preserves full relative path structure.
- **Atomic File Sync:** Checks `File::exists($targetPath)` before copying to avoid overwriting existing local files.
- **Resilient:** Media failures emit a warning but do NOT invalidate the successful database migration.

---

## Extending the Pipeline

To add a custom migration step:

```php
// 1. Create your step class
class SyncCustomStep extends AbstractMigrationStep
{
    public function getName(): string
    {
        return "Custom Data Sync";
    }

    public function execute(int $siteId, array $config): array
    {
        // your migration logic
        return ['custom' => 'Synced N rows'];
    }
}

// 2. Register it in ProcessMigration.php or SiteMigrationService.php
$steps = [
    // ...existing steps...
    ['name' => 'Custom Sync', 'step' => new SyncCustomStep($service), 'progress' => 98],
];
```
