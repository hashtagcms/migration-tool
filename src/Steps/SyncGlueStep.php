<?php

namespace HashtagCms\MigrationTool\Steps;

use Illuminate\Support\Facades\DB;

/**
 * Step 3: Glue Layer Synchronization
 *
 * Syncs all site-scoped relational/junction entities. These depend on
 * both the global maps (Step 1) and the structural maps (Step 2).
 */
class SyncGlueStep extends AbstractMigrationStep
{
    public function getName(): string
    {
        return "Glue Layer Synchronization (Categories, Galleries, Menus, Content)";
    }

    public function execute(int $siteId, array $config): array
    {
        $newSiteId = $this->service->getFromMap('sites', $siteId);
        if (!$newSiteId) {
            throw new \Exception("Structural sync must be completed before glue sync.");
        }

        $results = [];

        // ── 1. Categories (2-pass for parent_id hierarchy) ───────────────────
        if ($this->sourceTableExists('categories')) {
            $this->reportProgress(52, "Syncing hierarchical categories...");
            $results['categories'] = $this->syncCategories($siteId, $newSiteId);
        }

        // ── 2. category_site pivot ────────────────────────────────────────────
        if ($this->sourceTableExists('category_site')) {
            $this->reportProgress(55, "Linking categories to site...");
            $categorySiteTable = $this->getSourceTable('category_site');
            $results['category_site'] = $this->syncCategorySite($siteId, $newSiteId, $categorySiteTable);
        }

        // ── 3. module_props ───────────────────────────────────────────────────
        if ($this->sourceTableExists('module_props')) {
            $this->reportProgress(58, "Syncing module properties...");
            $modulePropsTable = $this->getSourceTable('module_props');
            $results['module_props'] = $this->syncModuleProps($siteId, $newSiteId, $modulePropsTable);
        }

        // ── 4. module_site (master glue pivot) ────────────────────────────────
        if ($this->sourceTableExists('module_site')) {
            $this->reportProgress(62, "Migrating Layout: Hook/Module Assignments...");
            $results['module_site'] = $this->syncModuleSite($siteId, $newSiteId);
        }

        // ── 5. galleries ──────────────────────────────────────────────────────
        if ($this->sourceTableExists('galleries')) {
            $this->reportProgress(64, "Syncing galleries...");
            $results['galleries'] = $this->syncSimpleSiteEntity('galleries', $siteId, $newSiteId);

            // ── 6. category_gallery pivot ─────────────────────────────────────────
            if ($this->sourceTableExists('category_gallery')) {
                $results['category_gallery'] = $this->syncPivotByMap('category_gallery', [
                    'category_id' => 'categories',
                    'gallery_id'  => 'galleries',
                ]);
            }
        }

        // ── 7. menu_managers ──────────────────────────────────────────────────
        if ($this->sourceTableExists('menu_managers')) {
            $this->reportProgress(66, "Syncing menu managers...");
            $results['menu_managers'] = $this->syncSimpleSiteEntity('menu_managers', $siteId, $newSiteId);
        }

        // ── 8. microsites ─────────────────────────────────────────────────────
        if ($this->sourceTableExists('microsites')) {
            $this->reportProgress(68, "Syncing microsites...");
            $results['microsites'] = $this->syncSimpleSiteEntity('microsites', $siteId, $newSiteId);
        }

        // ── 9. festivals ────────────────────────────────────
        if ($this->sourceTableExists('festivals')) {
            $this->reportProgress(70, "Syncing festivals...");
            $results['festivals'] = $this->syncSimpleSiteEntity('festivals', $siteId, $newSiteId);
        }

        // ── 10. comments ────────────────────
        if ($this->sourceTableExists('comments')) {
            $this->reportProgress(72, "Syncing comments...");
            $results['comments'] = $this->syncComments($siteId, $newSiteId);
        }

        // ── 11. subscribers ───────────────────────────────────────────────────
        if ($this->sourceTableExists('subscribers')) {
            $this->reportProgress(73, "Syncing subscribers...");
            $results['subscribers'] = $this->syncSimpleSiteEntity('subscribers', $siteId, $newSiteId);
        }

        // ── 12. contacts ──────────────────────────────────────────────────────
        if ($this->sourceTableExists('contacts')) {
            $this->reportProgress(74, "Syncing contacts...");
            $results['contacts'] = $this->syncSimpleSiteEntity('contacts', $siteId, $newSiteId);
        }

        return $results;
    }

    /**
     * Sync categories (hierarchical, 2-pass).
     */
    protected function syncCategories(int $oldSiteId, int $newSiteId): string
    {
        $total = 0;
        // Pass 1 — insert flat, capture ID map
        $this->sourceConnection->table('categories')
            ->where('site_id', $oldSiteId)
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($newSiteId, &$total) {
                foreach ($rows as $row) {
                    $row   = (array)$row;
                    $oldId = $row['id'];
                    $data  = $this->transform('categories', $row);
                    $data['site_id']   = $newSiteId;
                    $data['parent_id'] = 0; // resolved in pass 2

                    $newId = DB::table('categories')->insertGetId($data);
                    $this->service->addToMap('categories', $oldId, $newId);
                    $total++;
                }
            });

        // Pass 2 — resolve parent_id hierarchy
        $this->sourceConnection->table('categories')
            ->where('site_id', $oldSiteId)
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $row = (array)$row;
                    if (!empty($row['parent_id']) && $row['parent_id'] > 0) {
                        $newParentId = $this->service->getFromMap('categories', $row['parent_id']);
                        if ($newParentId) {
                            $newId = $this->service->getFromMap('categories', $row['id']);
                            DB::table('categories')->where('id', $newId)->update(['parent_id' => $newParentId]);
                        }
                    }
                }
            });
        return "Synced $total categories (hierarchical, 2-pass)";
    }

    /**
     * Sync category_site pivot.
     */
    protected function syncCategorySite(int $oldSiteId, int $newSiteId, string $table = 'category_site'): string
    {
        $count = 0;
        $this->sourceConnection->table($table)
            ->where('site_id', $oldSiteId)
            ->orderBy('category_id') // Pivot workaround for missing 'id'
            ->chunk(200, function ($rows) use ($newSiteId, $table, &$count) {
                foreach ($rows as $row) {
                    $row  = (array)$row;
                    $data = $this->transform($table, $row);

                    $data['site_id']     = $newSiteId;
                    $data['category_id'] = $this->service->getFromMap('categories', $row['category_id']) ?? $row['category_id'];
                    $data['theme_id']    = $this->service->getFromMap('themes',     $row['theme_id'])    ?? $row['theme_id'];

                    $oldPlatformId = $this->getPlatformId($row);
                    if ($oldPlatformId > 0) {
                        $data['platform_id'] = $this->service->getFromMap('platforms', $oldPlatformId) ?? $oldPlatformId;
                    }

                    DB::table($table)->updateOrInsert(
                        [
                            'site_id'     => $newSiteId,
                            'category_id' => $data['category_id'],
                            'platform_id' => $data['platform_id'] ?? 1
                        ],
                        $data
                    );
                    $count++;
                }
            });
        return "Synced $count category-site assignments";
    }

    /**
     * Sync module_props.
     */
    protected function syncModuleProps(int $oldSiteId, int $newSiteId, string $table = 'module_props'): string
    {
        $total = 0;
        $this->sourceConnection->table($table)
            ->where('site_id', $oldSiteId)
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($newSiteId, $table, &$total) {
                foreach ($rows as $row) {
                    $row = (array)$row;

                    $data                = $this->transform($table, $row);
                    $data['site_id']     = $newSiteId;
                    $data['module_id']   = $this->service->getFromMap('modules',   $row['module_id'])   ?? $row['module_id'];

                    $oldPlatformId       = $this->getPlatformId($row);
                    $data['platform_id'] = $this->service->getFromMap('platforms', $oldPlatformId) ?? $oldPlatformId;

                    // Idempotent: check by unique constraint before inserting.
                    $local = DB::table($table)
                        ->where('site_id',     $newSiteId)
                        ->where('module_id',   $data['module_id'])
                        ->where('platform_id', $data['platform_id'])
                        ->where('name',        $row['name'])
                        ->where('group',       $row['group'] ?? '')
                        ->first();

                    if ($local) {
                        $this->service->addToMap($table, $row['id'], $local->id);
                    } else {
                        $newId = DB::table($table)->insertGetId($data);
                        $this->service->addToMap($table, $row['id'], $newId);
                    }
                    $total++;
                }
            });
        return "Synced $total module properties";
    }

    /**
     * Sync module_site — master glue pivot.
     */
    protected function syncModuleSite(int $oldSiteId, int $newSiteId): string
    {
        $total = 0;
        $this->sourceConnection->table('module_site')
            ->where('site_id', $oldSiteId)
            ->orderBy('module_id') // Pivot workaround
            ->chunk(200, function ($rows) use ($newSiteId, &$total) {
                foreach ($rows as $row) {
                    $row  = (array)$row;
                    $data = $this->transform('module_site', $row);
                    $data['site_id'] = $newSiteId;
                    $fkMap = [
                        'module_id'    => 'modules',
                        'theme_id'     => 'themes',
                        'hook_id'      => 'hooks',
                        'category_id'  => 'categories',
                        'microsite_id' => 'microsites',
                    ];

                    foreach ($fkMap as $column => $mapTable) {
                        if (isset($row[$column])) {
                            // Only remap if value is positive (id exists)
                            if ($row[$column] > 0) {
                                $data[$column] = $this->service->getFromMap($mapTable, $row[$column]) ?? $row[$column];
                            } else {
                                $data[$column] = $row[$column];
                            }
                        }
                    }

                    // Handle platform_id remapping specifically as it's often missing or differently named (tenant_id)
                    $oldPlatformId = $this->getPlatformId($row);
                    if ($oldPlatformId > 0) {
                        $data['platform_id'] = $this->service->getFromMap('platforms', $oldPlatformId) ?? $oldPlatformId;
                    }

                    // Junction tables should use the full set of identifying columns for updateOrInsert
                    $uniqueKeys = ['site_id' => $newSiteId];
                    foreach (['module_id', 'hook_id', 'category_id', 'microsite_id', 'platform_id'] as $col) {
                        if (isset($data[$col])) {
                            $uniqueKeys[$col] = $data[$col];
                        }
                    }

                    DB::table('module_site')->updateOrInsert($uniqueKeys, $data);
                    $total++;
                }
            });
        return "Synced $total module-site assignments";
    }

    /**
     * Sync comments.
     */
    protected function syncComments(int $oldSiteId, int $newSiteId): string
    {
        $total = 0;
        // Pass 1: Insert content flat
        $this->sourceConnection->table('comments')
            ->where('site_id', $oldSiteId)
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($newSiteId, &$total) {
                foreach ($rows as $row) {
                    $row   = (array)$row;
                    $oldId = $row['id'];

                    $data            = $this->transform('comments', $row);
                    $data['site_id'] = $newSiteId;
                    $data['parent_id'] = 0; // resolved in pass 2

                    if (!empty($row['category_id'])) {
                        $data['category_id'] = $this->service->getFromMap('categories', $row['category_id']) ?? $row['category_id'];
                    }

                    if (!empty($row['user_id'])) {
                        $data['user_id'] = $this->service->getFromMap('users', $row['user_id']) ?? $row['user_id'];
                    }

                    $newId = DB::table('comments')->insertGetId($data);
                    $this->service->addToMap('comments', $oldId, $newId);
                    $total++;
                }
            });

        // Pass 2: Threaded hierarchy resolution
        $this->sourceConnection->table('comments')
            ->where('site_id', $oldSiteId)
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $row = (array)$row;
                    if (!empty($row['parent_id']) && $row['parent_id'] > 0) {
                        $newParentId = $this->service->getFromMap('comments', $row['parent_id']);
                        if ($newParentId) {
                            $newId = $this->service->getFromMap('comments', $row['id']);
                            DB::table('comments')->where('id', $newId)->update(['parent_id' => $newParentId]);
                        }
                    }
                }
            });
        return "Synced $total comments";
    }
}
