<?php

namespace HashtagCms\MigrationTool\Services;

use HashtagCms\MigrationTool\Steps\SyncContextStep;
use HashtagCms\MigrationTool\Steps\SyncStructuralStep;
use HashtagCms\MigrationTool\Steps\SyncGlueStep;
use HashtagCms\MigrationTool\Steps\SyncContentStep;
use HashtagCms\MigrationTool\Steps\SyncValidationStep;
use HashtagCms\MigrationTool\Steps\SyncMediaStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class SiteMigrationService
{
    protected array $results = [];
    protected array $idMap = []; // [table_name => [old_id => new_id]]

    public function __construct()
    {
    }

    public function migrate(int $siteId, array $config, ?\Closure $onProgress = null): array
    {
        // Reset state to ensure this instance is safe to call multiple times.
        $this->results = [];
        $this->idMap   = [];

        // ── Phase 1: DB Migration (Atomic) ────────────────────────────────────
        $dbSteps = [
            ['class' => SyncContextStep::class,    'baseProgress' => 10, 'weight' => 20],
            ['class' => SyncStructuralStep::class, 'baseProgress' => 30, 'weight' => 20],
            ['class' => SyncGlueStep::class,       'baseProgress' => 50, 'weight' => 20],
            ['class' => SyncContentStep::class,    'baseProgress' => 70, 'weight' => 20],
        ];

        try {
            DB::beginTransaction();

            foreach ($dbSteps as $item) {
                // Instantiate step with progress callback for granular updates
                $step = new $item['class']($this, $onProgress);
                
                if ($onProgress) {
                    $onProgress($item['baseProgress'], "Starting " . $step->getName() . "...");
                }

                $this->results[$step->getName()] = $step->execute($siteId, $config);
                
                if ($onProgress) {
                    $onProgress($item['baseProgress'] + $item['weight'], "Completed: " . $step->getName());
                }
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Migration failed at DB step: " . $e->getMessage(), [
                'site_id' => $siteId,
                'trace'   => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'phase'   => 'db',
                'trace'   => $e->getTraceAsString(),
            ];
        }

        // ── Phase 2: Post-Commit Logic (Validation & Media) ───────────────────

        // 1. Validation (Does not roll back, but important for reporting)
        try {
            $validation = new SyncValidationStep($this);
            $this->results[$validation->getName()] = $validation->execute($siteId, $config);
            if ($onProgress) {
                $onProgress(90, 'running: Validation');
            }
        } catch (\Exception $e) {
            $this->results['Validation Error'] = $e->getMessage();
        }

        // 2. Media Sync (Filesystem)
        if (!empty($config['copy_media'])) {
            $mediaStep = new SyncMediaStep($this);
            try {
                $this->results[$mediaStep->getName()] = $mediaStep->execute($siteId, $config);
                if ($onProgress) {
                    $onProgress(95, 'running: Media Sync');
                }
            } catch (\Exception $e) {
                Log::warning("Migration media sync failed (DB is intact): " . $e->getMessage());
                $this->results[$mediaStep->getName()] = ['warning' => $e->getMessage()];
            }
        }

        // 3. Cache Clearing (Final Polish)
        $this->clearCmsCache();

        return [
            'success' => true,
            'message' => 'Migration completed successfully',
            'results' => $this->results,
        ];
    }

    /**
     * Clear HashtagCMS cache if the command is available.
     */
    protected function clearCmsCache(): void
    {
        try {
            $availableCommands = Artisan::all();

            if (array_key_exists('htcms:cache-clear', $availableCommands)) {
                Artisan::call('htcms:cache-clear');
                $this->results['Finalization'] = "CMS Cache cleared successfully.";
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to clear CMS cache: " . $e->getMessage());
        }
    }

    public function addToMap(string $table, $oldId, $newId): void
    {
        $this->idMap[$table][$oldId] = $newId;
    }

    public function getFromMap(string $table, $oldId)
    {
        return $this->idMap[$table][$oldId] ?? null;
    }

    public function getFullMap(string $table): array
    {
        return $this->idMap[$table] ?? [];
    }
}
