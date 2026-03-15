<?php

namespace HashtagCms\MigrationTool\Steps;

use Illuminate\Support\Facades\DB;

/**
 * Step 1: Context Synchronization
 *
 * Syncs all GLOBAL reference tables that have no site_id and are shared
 * across the entire HashtagCMS installation. Everything here must be
 * migrated first because subsequent steps reference these IDs via FKs.
 *
 * Tables covered (16):
 *   langs, platforms, zones, currencies, countries, country_langs,
 *   cities, hooks, roles, permissions, tags, users,
 *   user_profiles, cms_permissions, permission_role, role_user
 */
class SyncContextStep extends AbstractMigrationStep
{
    public function getName(): string
    {
        return "Context Synchronization (Global)";
    }

    public function execute(int $siteId, array $config): array
    {
        $results = [];

        // ── Phase A: Pure reference tables (deduplicated by business key) ────
        //
        // ORDER IS CRITICAL:
        //   langs       → must be first (every _langs table needs lang_id)
        //   countries   → before cities (cities.country_id depends on it)
        //   users       → before user_profiles, cms_permissions, role_user
        //   roles       → before permission_role, role_user
        //   permissions → before permission_role

        $tables = [
            // ── Language master ───────────────────────────────────────────────
            'langs'       => ['compare' => 'iso_code', 'langs_fk' => null,          'fk_map' => []],

            // ── Structural references ─────────────────────────────────────────
            'platforms'   => ['compare' => 'name',     'langs_fk' => null,          'fk_map' => []],
            'zones'       => ['compare' => 'name',     'langs_fk' => null,          'fk_map' => []],
            'currencies'  => ['compare' => 'iso_code', 'langs_fk' => null,          'fk_map' => []],

            // ── Countries (has country_langs: country_id + lang_id, no site_id)
            'countries'   => ['compare' => 'iso_code', 'langs_fk' => 'country_id',  'fk_map' => []],

            // ── Cities — must be after countries so country_id can be remapped ───────────────
            // IMPORTANT: cities are NOT in the generic loop below because they
            // require deduplication by (name + country_id) together — same city
            // name can exist in multiple countries. Handled in syncCities().

            // ── Global CMS entities ───────────────────────────────────────────
            'hooks'       => ['compare' => 'alias',    'langs_fk' => null,          'fk_map' => []],
            'roles'       => ['compare' => 'name',     'langs_fk' => null,          'fk_map' => []],
            'permissions' => ['compare' => 'name',     'langs_fk' => null,          'fk_map' => []],
            'tags'        => ['compare' => 'name',     'langs_fk' => null,          'fk_map' => []],

            // ── CMS Modules (must be before cms_permissions: cms_module_id FK) ───────────────
            // controller_name is the stable unique identifier across installations.
            'cms_modules' => ['compare' => 'controller_name', 'langs_fk' => null,   'fk_map' => []],

            // ── Users — deduplicate by email ──────────────────────────────────
            // If a user with the same email already exists in the target, map
            // to that existing user (do NOT overwrite their password/data).
            'users'       => ['compare' => 'email',    'langs_fk' => null,          'fk_map' => []],
        ];

        $tableCount = count($tables);
        $currentIndex = 0;

        foreach ($tables as $table => $meta) {
            $currentIndex++;
            $created     = 0;
            $mapped      = 0;
            $total       = 0;
            $sourceTable = $this->getSourceTable($table);

            if (!$this->sourceTableExists($table)) {
                $results[$table] = "Skipped (Table missing in source)";
                continue;
            }

            $this->reportProgress(
                $this->onProgress ? 10 + (int)(($currentIndex / $tableCount) * 15) : 10,
                "Syncing global table: $table ($currentIndex/$tableCount)..."
            );

            // Use chunk() instead of chunkById() because some legacy tables might 
            // not have 'id' as primary key or might have composite keys.
            $this->sourceConnection->table($sourceTable)->orderBy($meta['compare'] ?? 'id')->chunk(500, function ($rows) use (&$created, &$mapped, &$total, $table, $meta) {
                foreach ($rows as $row) {
                    $row          = (array)$row;
                    $oldId        = $row['id'];
                    $compareValue = $row[$meta['compare']];
                    $total++;

                    // Deduplicate by business key — never trust IDs cross-database.
                    $local = DB::table($table)->where($meta['compare'], $compareValue)->first();

                    if ($local) {
                        $newId = $local->id;
                        $mapped++;
                    } else {
                        $data = $this->transform($table, $row);

                        // Remap any FK columns this row owns before inserting.
                        foreach ($meta['fk_map'] as $fkColumn => $mapTable) {
                            if (isset($data[$fkColumn])) {
                                $data[$fkColumn] = $this->service->getFromMap($mapTable, $data[$fkColumn])
                                                   ?? $data[$fkColumn];
                            }
                        }

                        $newId = DB::table($table)->insertGetId($data);
                        $created++;
                    }

                    // Store map using target table name ('platforms') even if source was 'tenants'
                    $this->service->addToMap($table, $oldId, $newId);
                }
            });

            $results[$table] = "Synced $total from $sourceTable (Created: $created, Mapped: $mapped)";

            // Sync _langs translation child if this entity has one.
            if (!empty($meta['langs_fk'])) {
                $langsTable  = $this->resolveLangsTableName($table);
                $langsSynced = $this->syncContextLangs($langsTable, $meta['langs_fk'], $table);
                $results[$langsTable] = "Synced $langsSynced translation rows";
            }
        }

        // Cities are handled separately: deduplication requires BOTH name AND
        // country_id — the generic loop only supports a single compare column.
        $results['cities'] = $this->syncCities();

        // ── Phase B: One-to-one dependent tables ─────────────────────────────

        // user_profiles: one profile per user, unique by user_id
        if ($this->sourceTableExists('user_profiles')) {
            $results['user_profiles'] = $this->syncUserProfiles();
        }

        // cms_permissions: user ↔ cms_module permission grants.
        if ($this->sourceTableExists('cms_permissions')) {
            $results['cms_permissions'] = $this->syncCmsPermissions();
        }

        // ── Phase C: Global pivot tables ─────────────────────────────────────

        // permission_role: which permissions belong to which roles
        if ($this->sourceTableExists('permission_role')) {
            $results['permission_role'] = $this->syncGlobalPivot(
                'permission_role',
                ['permission_id' => 'permissions', 'role_id' => 'roles']
            );
        }

        // role_user: which roles are assigned to which users
        if ($this->sourceTableExists('role_user')) {
            $results['role_user'] = $this->syncGlobalPivot(
                'role_user',
                ['role_id' => 'roles', 'user_id' => 'users']
            );
        }

        // ── Phase D: Migration records ─────────────────────────────────────
        // Merge source migration records into the target so that
        // `php artisan migrate` does not attempt to re-run migrations
        // that have already been applied via the source database.
        $results['migrations'] = $this->syncMigrations();

        return $results;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helper methods
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Sync cities with composite deduplication: (name + mapped country_id).
     *
     * Cannot use the generic loop because cities require TWO columns for
     * unique identity — the same city name exists in many countries.
     * e.g. "Springfield" in the USA and UK are different cities.
     */
    protected function syncCities(): string
    {
        if (!$this->sourceTableExists('cities')) return "Skipped (cities table missing)";

        $created = 0;
        $mapped  = 0;
        $total   = 0;

        $this->safeChunk('cities', function ($rows) use (&$created, &$mapped, &$total) {
            foreach ($rows as $row) {
                $row   = (array)$row;
                $oldId = $row['id'] ?? null;
                $total++;

                // Remap country_id FIRST so the local lookup uses the correct target ID.
                $newCountryId = $this->service->getFromMap('countries', $row['country_id'])
                                ?? $row['country_id'];

                // Deduplicate by (name + country_id) — composite business key.
                $local = DB::table('cities')
                    ->where('name',       $row['name'])
                    ->where('country_id', $newCountryId)
                    ->first();

                if ($local) {
                    $newId = $local->id;
                    $mapped++;
                } else {
                    $data               = $this->transform('cities', $row);
                    $data['country_id'] = $newCountryId;
                    $newId              = DB::table('cities')->insertGetId($data);
                    $created++;
                }

                if ($oldId) {
                    $this->service->addToMap('cities', $oldId, $newId);
                }
            }
        });

        return "Synced $total cities (Created: $created, Mapped: $mapped)";
    }

    /**
     * Resolve the _langs child table name from the parent table name.
     */
    protected function resolveLangsTableName(string $table): string
    {
        $explicitMap = [
            'countries' => 'country_langs',
        ];
        return $explicitMap[$table] ?? (rtrim($table, 's') . '_langs');
    }

    /**
     * Sync a _langs translation table for a global context entity.
     *
     * Structure: <entity>_id (FK to parent) + lang_id + translated fields.
     * NO site_id in these tables — they are global translations.
     * Both FKs are remapped via the ID maps built in the loop above.
     */
    protected function syncContextLangs(string $langsTable, string $fkColumn, string $parentTable): int
    {
        $sourceTable = $this->getSourceTable($langsTable);
        if (!$this->sourceTableExists($langsTable)) return 0;

        $count = 0;
        $this->safeChunk($sourceTable, function ($rows) use ($langsTable, $fkColumn, $parentTable, &$count) {
            foreach ($rows as $row) {
                $row         = (array)$row;
                $newParentId = $this->service->getFromMap($parentTable, $row[$fkColumn]) ?? $row[$fkColumn];
                $newLangId   = $this->service->getFromMap('langs', $row['lang_id'])      ?? $row['lang_id'];

                $data              = $this->transform($langsTable, $row);
                $data[$fkColumn]   = $newParentId;
                $data['lang_id']   = $newLangId;

                DB::table($langsTable)->updateOrInsert(
                    [$fkColumn => $newParentId, 'lang_id' => $newLangId],
                    $data
                );
                $count++;
            }
        });

        return $count;
    }

    /**
     * Sync user_profiles (one-to-one with users).
     * Unique by user_id — one profile row per user.
     */
    protected function syncUserProfiles(): string
    {
        $count = 0;
        $this->safeChunk('user_profiles', function ($rows) use (&$count) {
            foreach ($rows as $row) {
                $row       = (array)$row;
                $newUserId = $this->service->getFromMap('users', $row['user_id']) ?? null;

                if (!$newUserId) {
                    continue; // user was not migrated — skip their profile
                }

                $data            = $this->transform('user_profiles', $row);
                $data['user_id'] = $newUserId;

                DB::table('user_profiles')->updateOrInsert(
                    ['user_id' => $newUserId],
                    $data
                );
                $count++;
            }
        });

        return "Synced $count user profile rows";
    }

    /**
     * Sync cms_permissions (user ↔ cms_module access grants).
     *
     * Both user_id and cms_module_id are remapped via the ID maps
     * built in the generic loop above (users + cms_modules).
     * Rows are skipped if either referenced entity was not migrated.
     */
    protected function syncCmsPermissions(): string
    {
        $count = 0;
        $this->safeChunk('cms_permissions', function ($rows) use (&$count) {
            foreach ($rows as $row) {
                $row            = (array)$row;
                $newUserId      = $this->service->getFromMap('users',       $row['user_id'])      ?? null;
                $newCmsModuleId = $this->service->getFromMap('cms_modules', $row['cms_module_id']) ?? null;

                if (!$newUserId || !$newCmsModuleId) {
                    continue; // either user or cms_module not migrated — skip
                }

                $data                   = $this->transform('cms_permissions', $row);
                $data['user_id']        = $newUserId;
                $data['cms_module_id']  = $newCmsModuleId;

                // Unique by (user_id + cms_module_id)
                DB::table('cms_permissions')->updateOrInsert(
                    ['user_id' => $newUserId, 'cms_module_id' => $newCmsModuleId],
                    $data
                );
                $count++;
            }
        });

        return "Synced $count cms permission rows";
    }

    /**
     * Sync a global pivot table (no site_id) where every FK column
     * is in the ID map. All FK columns in $fkMap are remapped.
     *
     * Used for: permission_role, role_user
     *
     * @param string $table  e.g. 'permission_role'
     * @param array  $fkMap  [ 'column' => 'mapTable' ]
     */
    protected function syncGlobalPivot(string $table, array $fkMap): string
    {
        $count = 0;
        $this->safeChunk($table, function ($rows) use ($table, $fkMap, &$count) {
            foreach ($rows as $row) {
                $row  = (array)$row;
                $data = $this->transform($table, $row);

                $uniqueKeys = [];
                $skip       = false;

                foreach ($fkMap as $column => $mapTable) {
                    $newId = $this->service->getFromMap($mapTable, $row[$column]) ?? null;
                    if (!$newId) {
                        $skip = true; // referenced entity was not migrated
                        break;
                    }
                    $data[$column]       = $newId;
                    $uniqueKeys[$column] = $newId;
                }

                if ($skip) continue;

                DB::table($table)->updateOrInsert($uniqueKeys, $data);
                $count++;
            }
        });

        return "Synced $count $table rows";
    }

    /**
     * Merge migration records from source into target.
     *
     * Purpose:
     *   After a full data migration, if `php artisan migrate` is run on the
     *   target it checks the `migrations` table to know which migrations have
     *   already been executed. If source migration records are missing, Laravel
     *   will try to re-run those migrations — which would fail (tables exist)
     *   or silently corrupt data.
     *
     * Strategy — MERGE only (never overwrite, never delete):
     *   1. Fetch all migration names already present on the target.
     *   2. Insert only records from source that are NOT in the target.
     *   3. Assign all new records to batch = max(target_batch) + 1 so they are
     *      grouped as "imported" and won't be rolled back or re-run accidentally.
     *
     * No ID mapping needed — nothing in HashtagCMS references migration rows
     * by their auto-increment id.
     */
    protected function syncMigrations(): string
    {
        // Build a fast O(1) lookup set of migration names already on the target.
        $existingNames = DB::table('migrations')
            ->pluck('migration')
            ->flip()
            ->toArray();

        // All new records will be grouped into the next batch.
        $nextBatch = (int) DB::table('migrations')->max('batch') + 1;

        $sourceMigrations = $this->sourceConnection->table('migrations')->get();
        $inserted         = 0;

        foreach ($sourceMigrations as $row) {
            // Skip if already recorded on the target — never overwrite.
            if (isset($existingNames[$row->migration])) {
                continue;
            }

            DB::table('migrations')->insert([
                'migration' => $row->migration,
                'batch'     => $nextBatch,
            ]);

            $inserted++;
        }

        return "Merged $inserted new migration records into batch $nextBatch"
               . ($inserted === 0 ? " (target already up to date)" : "");
    }
}
