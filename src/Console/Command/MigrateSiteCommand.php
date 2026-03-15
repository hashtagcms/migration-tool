<?php

namespace HashtagCms\MigrationTool\Console\Command;

use HashtagCms\MigrationTool\Services\SiteMigrationService;
use HashtagCms\MigrationTool\Jobs\ProcessMigration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateSiteCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cms:migrate-site 
                            {site_id : The ID of the site to migrate from the source database}
                            {--driver=mysql : Database driver}
                            {--host= : Database host}
                            {--port=3306 : Database port}
                            {--database= : Database name}
                            {--username= : Database username}
                            {--password= : Database password}
                            {--strategy=terminate : Conflict strategy: overwrite, rename, or terminate}
                            {--media : Whether to copy media files}
                            {--source-root= : Filesystem path to the source site root (for media assets)}
                            {--async : Whether to run the migration as a background job}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate a site and its content from a source HashtagCMS database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $siteId = $this->argument('site_id');
        
        $dbConfig = [
            'driver'    => $this->option('driver'),
            'host'      => $this->option('host') ?? config('database.connections.mysql.host'),
            'port'      => $this->option('port'),
            'database'  => $this->option('database'),
            'username'  => $this->option('username'),
            'password'  => $this->option('password'),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
        ];

        // Check required options
        if (!$dbConfig['database'] || !$dbConfig['username']) {
            $this->error("Database name and username are required.");
            return 1;
        }

        $config = [
            'conflict_strategy' => $this->option('strategy'),
            'copy_media'        => $this->option('media'),
            'source_root_path'  => $this->option('source-root'),
        ];

        // Configure connection
        config(["database.connections.temp_source_connection" => $dbConfig]);

        try {
            // Test connection
            DB::connection('temp_source_connection')->getPdo();
        } catch (\Exception $e) {
            $this->error("Could not connect to the source database: " . $e->getMessage());
            return 1;
        }

        if ($this->option('async')) {
            $logId = (string) Str::uuid();
            
            DB::table('cms_migration_logs')->insert([
                'job_id'     => $logId,
                'site_id'    => $siteId,
                'status'     => 'pending',
                'progress'   => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            ProcessMigration::dispatch($siteId, $config, $dbConfig, $logId);
            
            $this->info("Migration job dispatched!");
            $this->line("Log ID: <info>$logId</info>");
            $this->line("You can monitor the progress in the 'cms_migration_logs' table.");
        } else {
            $this->info("Starting migration for site ID: $siteId...");
            
            $service = new SiteMigrationService();
            $result = $service->migrate($siteId, $config);

            if ($result['success']) {
                $this->info("Migration completed successfully!");
                $this->outputResults($result['results']);
            } else {
                $this->error("Migration failed: " . $result['message']);
                if (isset($result['trace'])) {
                    $this->line($result['trace']);
                }
                return 1;
            }
        }

        return 0;
    }

    /**
     * Output results in a table format.
     */
    protected function outputResults(array $results): void
    {
        foreach ($results as $stepName => $stepResult) {
            $this->line("\n<options=bold>$stepName</>");
            
            if (is_array($stepResult)) {
                $rows = [];
                foreach ($stepResult as $table => $info) {
                    $rows[] = [$table, $info];
                }
                $this->table(['Table/Entity', 'Details'], $rows);
            } else {
                $this->line($stepResult);
            }
        }
    }
}
