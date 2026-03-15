# HashtagCMS Migration Tool: End-to-End Task Plan

## 1. Project Overview
The **Migration Tool** is a specialized package designed to migrate a complete HashtagCMS site (or multiple sites) from a source database to a target database. Unlike the internal `SiteClonerService`, this tool handles cross-database connections, ID re-mapping, and conflict resolution.

---

## 2. Architectural Principles
*   **ETL Pattern**: Extract from Source, Transform (re-map IDs), Load to Target.
*   **Identity Mapping**: A persistent mapping engine to track `$oldId -> $newId` transitions across connections.
*   **Layered Execution**: strictly follows the 4-layer dependency order (Context -> Structural -> Glue -> Content).
*   **Dry Run Support**: Ability to simulate migration and report potential conflicts before writing.

---

## 3. Phase 1: Foundation & Connection Management
### Tasks:
*   [ ] **Package Initialization**: Setup `composer.json`, Service Provider, and Config scaffolding.
*   [ ] **Dynamic Connection Setup**: Implement a mechanism to define the `source` database connection at runtime (DB Host, User, Pass).
*   [ ] **Connection Validator**: Create a tool to verify that both source and target have the HashtagCMS schema installed.
*   [ ] **CLI Framework**: Build the Artisan command `cms:migrate-site {site_id}` with flags for `--dry-run`, `--force`, and `--skip-media`.

---

## 4. Phase 2: The Mapping Engine
### Tasks:
*   [ ] **Mapping Store**: Implement an in-memory or temporary database store to hold ID maps for each table.
*   [ ] **Global Resolver**: Logic to map global entities (`langs`, `platforms`, `countries`) by their unique codes (ISO/Alias) instead of IDs.
*   [ ] **ID Translator**: A utility function that takes an `old_id` and a `table_name` and returns the `new_id` from the map.

---

## 5. Phase 3: Structural Migration (Layers 1 & 2)
### Tasks:
*   [ ] **Site Migration**: 
    *   Extract site record.
    *   Check for domain conflicts in target.
    *   Insert and store `new_site_id`.
*   [ ] **Theme & Asset Discovery**: 
    *   Migrate `themes` records.
    *   Identify theme directory paths for Phase 6.
*   [ ] **Hook & Module Definition**:
    *   Copy `hooks` (mapping by alias).
    *   Copy `modules` (logic, views, query statements).
    *   *Critical*: Update `site_id` in the module record to the new mapped ID.

---

## 6. Phase 4: The Glue Layer (Layer 3)
### Tasks:
*   [ ] **Dynamic Pivot Migration**: Build an automated loop to migrate all `*_site` pivot tables.
    *   `lang_site`, `platform_site`, `currency_site`, `country_site`.
*   [ ] **Category Hierarchy**: 
    *   Migrate `categories` recursively to maintain `parent_id` relationships.
    *   Update `site_id` and `theme_id` using the map.
*   [ ] **The "Master Glue" (module_site)**:
    *   Migrate the `module_site` table. 
    *   Re-map 5 foreign keys per row: `site_id`, `platform_id`, `category_id`, `hook_id`, `module_id`.

---

## 7. Phase 5: Content & I18n Migration (Layer 4)
### Tasks:
*   [ ] **Standardized Lang Migration**: A generic migration engine for all `*_langs` tables.
*   [ ] **Static Content & Media References**: 
    *   Migrate `static_module_contents`.
    *   *Advanced*: Content parser to find `src="/media/..."` URLs and flag them for the Media Sync step.
*   [ ] **Pages & Blocks**:
    *   Migrate `pages` and `page_langs`.
    *   Maintain slug/URL integrity.

---

## 8. Phase 6: Media & Assets
### Tasks:
*   [ ] **Manifest Generation**: Create a list of all files found in `site.favicon`, `theme.directory`, and `static_content`.
*   [ ] **Transfer Engine**: 
    *   Support for local `cp` (if on same server).
    *   Support for `SCP/SFTP` or `S3` sync for remote moves.
*   [ ] **Path Updating**: If the target environment has a different storage base URL, implement a string-replacement sweep across content.

---

*   [ ] **Record Count Validator**: Post-migration report comparing total rows per table in Source vs. Target.
*   [ ] **Broken Link Checker**: Verification that all migrated categories/pages have valid `module_site` assignments.
*   [ ] **Cache Purge**: Automatically trigger `htcms:cache-clear` on the target site.

---

## 9. Phase 7: Validation & Integrity
### Tasks:
*   [ ] **Record Count Validator**: Post-migration report comparing total rows per table in Source vs. Target.
*   [ ] **Broken Link Checker**: Verification that all migrated categories/pages have valid `module_site` assignments.
*   [ ] **Cache Purge**: Automatically trigger `htcms:cache-clear` on the target site.

---

## 10. Phase 8: Migration UI (The Wizard)
### Tasks:
*   [ ] **UI Integration**: Setup admin routes and Vue.js controller for the Migration tool.
*   [ ] **Step 1: The Secure Connection**:
    *   Form for Source DB credentials.
    *   Connection testing with real-time feedback (success/fail).
*   [ ] **Step 2: Analysis & Discovery (Pre-flight)**:
    *   **Visual Summary**: Interactive dashboard showing what was found on the source (e.g., "5 Sites", "120 Modules", "400 Categories").
    *   **Conflict Indicator**: Highlight items that already exist on the target (e.g., "Domain 'example.com' already exists") with direct action toggles (Skip/Overwrite/Rename).
*   [ ] **Step 3: Dependency Mapping View**:
    *   Detailed list view allowing users to selectively de-select specific categories or modules if they don't want them migrated.
*   [ ] **Step 4: Execution Dashboard**:
    *   **Real-time Progress Bar**: Phased progress (Layer 1, Layer 2, etc.).
    *   **Live Log Stream**: Console-like output window showing actual SQL/File operations as they happen.
*   [ ] **Step 5: Completion Report**:
    *   Final success/fail matrix.
    *   Direct links to "View Site" or "Edit Settings" on the target.

---

## 11. Phase 9: QA & Reliability Fixes
### Tasks:
*   [x] **CSRF Protection**: Inject `X-CSRF-TOKEN` into Axios global defaults.
*   [x] **Connection Persistence**: Implement Session-based storage for Source DB config to survive PHP statelessness.
*   [x] **Dynamic Drivers & Prefixes**: Ensure 'driver' and 'prefix' from UI are actually used in the PDO connection.
*   [x] **Relational Pass**: Implement the 2nd pass for `sites.theme_id` update after themes are migrated.
*   [x] **Glue Expansion**: Add `category_site` and `module_site` full synchronization logic.
*   [x] **Advanced Conflict Resolution**: Implemented 'Rename' and 'Overwrite' strategies for domain conflicts.
*   [x] **Media Transfer**: Initial implementation of recursive file sync specialized for HashtagCms.

---

## 12. Lead QA Architectural Wins
*   [x] **Asynchronous ETL Pipeline**: Migration now runs in background jobs using Laravel Queues.
*   [x] **Persistence Layer**: Tracks migration progress in `cms_migration_logs`.
*   [x] **Idempotent Sync Engine**: Used `updateOrInsert` for all relational data to allow safe re-runs.
*   [x] **N+1 Performance Fix**: Batch translation imports using `whereIn` chunks.
*   [x] **Memory-Safe Media Sync**: Using Symfony Finder stream for massive asset libraries.

---

## 13. Potential Challenges & Conflict Strategies
| Conflict | Default Strategy | Option |
| :--- | :--- | :--- |
| Domain Exists | Terminate | `--rename-site` |
| Module Alias Exists | Link to existing target module | `--force-overwrite` |
| Category Slug Exists | Append suffix (e.g. -copy) | `--skip` |
| ID Overflow | Auto-increment from target max | N/A |

---
**Status**: Initial Design Phase  
**Author**: Antigravity AI  
**Project**: hashtagcms/migration-tool
