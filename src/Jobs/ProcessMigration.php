<?php

namespace HashtagCms\MigrationTool\Jobs;

use HashtagCms\MigrationTool\Services\SiteMigrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessMigration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $siteId;
    protected array $config;
    protected array $dbConfig;
    protected string $logId;

    public function __construct(int $siteId, array $config, array $dbConfig, string $logId)
    {
        $this->siteId   = $siteId;
        $this->config   = $config;
        $this->dbConfig = $dbConfig;
        $this->logId    = $logId;
    }

    public function handle(): void
    {
        // Re-hydrate the source DB connection for this background process.
        config(["database.connections.temp_source_connection" => $this->dbConfig]);

        $this->updateLog('Establishing connection to source...', 5);

        try {
            $service = new SiteMigrationService();

            $this->updateLog('Initializing Migration Pipeline...', 10);

            $result = $service->migrate($this->siteId, $this->config, function (int $progress, string $status) {
                $this->updateLog($status, $progress);
            });

            if (!$result['success']) {
                throw new \Exception($result['message']);
            }

            DB::table('cms_migration_logs')->where('job_id', $this->logId)->update([
                'status'   => 'completed',
                'progress' => 100,
                'results'  => json_encode($result['results']),
            ]);

        } catch (\Throwable $e) {
            Log::error("Async Migration Failed: " . $e->getMessage(), ['job_id' => $this->logId]);
            DB::table('cms_migration_logs')->where('job_id', $this->logId)->update([
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update migration log status and progress in one call.
     */
    protected function updateLog(string $status, int $progress): void
    {
        DB::table('cms_migration_logs')->where('job_id', $this->logId)->update([
            'status'   => $status,
            'progress' => $progress,
        ]);
    }
}
