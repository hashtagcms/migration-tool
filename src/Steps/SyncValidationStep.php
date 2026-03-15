<?php

namespace HashtagCms\MigrationTool\Steps;

use Illuminate\Support\Facades\DB;

/**
 * Step 5: Post-Migration Validation
 * 
 * Compares record counts between source and target databases
 * to ensure all expected data has been migrated.
 */
class SyncValidationStep extends AbstractMigrationStep
{
    public function getName(): string
    {
        return "Post-Migration Validation";
    }

    public function execute(int $siteId, array $config): array
    {
        $newSiteId = $this->service->getFromMap('sites', $siteId);
        
        $tables = [
            // Global tables (partial check - only what we inserted/mapped)
            'langs' => ['site_id' => null],
            'platforms' => ['site_id' => null],
            'users' => ['site_id' => null],
            
            // Site-specific tables
            'themes' => ['site_id' => true],
            'modules' => ['site_id' => true],
            'categories' => ['site_id' => true],
            'pages' => ['site_id' => true],
            'static_module_contents' => ['site_id' => true],
            'galleries' => ['site_id' => true],
            'menu_managers' => ['site_id' => true],
            'microsites' => ['site_id' => true],
            'festivals' => ['site_id' => true],
            'comments' => ['site_id' => true],
            'subscribers' => ['site_id' => true],
            'contacts' => ['site_id' => true],
            'site_props' => ['site_id' => true],
        ];

        $results = [];
        $failed = 0;

        foreach ($tables as $table => $info) {
            try {
                $sourceTable = $this->getSourceTable($table);
                $sourceCount = 0;

                if ($this->sourceTableExists($table)) {
                    $sourceQuery = $this->sourceConnection->table($sourceTable);
                    if ($info['site_id'] === true) {
                        $sourceQuery->where('site_id', $siteId);
                    }
                    $sourceCount = $sourceQuery->count();
                }

                // Validation logic
                if ($info['site_id'] === true) {
                    // Site-scoped: use record count in target filtered by site_id
                    $targetCount = DB::table($table)->where('site_id', $newSiteId)->count();
                } else {
                    // Global-scoped: count mapping entries we created during this run
                    $targetCount = count($this->service->getFullMap($table));
                }

                $status = ($sourceCount === $targetCount) ? 'OK' : 'MISMATCH';
                if ($status === 'MISMATCH') $failed++;

                $results[$table] = [
                    'source' => $sourceCount,
                    'target' => $targetCount,
                    'status' => $status,
                    'type'   => $info['site_id'] === true ? 'scoped' : 'global_map'
                ];
            } catch (\Exception $e) {
                $results[$table] = ['error' => $e->getMessage()];
            }
        }

        $results['_summary'] = [
            'total_tables_checked' => count($tables),
            'mismatches' => $failed,
            'status' => ($failed === 0) ? 'Success' : 'Warnings Found'
        ];

        return $results;
    }
}
