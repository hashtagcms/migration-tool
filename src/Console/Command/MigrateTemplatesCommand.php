<?php

namespace HashtagCms\MigrationTool\Console\Command;

use HashtagCms\MigrationTool\Services\TemplateMigrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateTemplatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cms:migrate-templates
                            {site-id : The source site ID}
                            {--source-root= : The absolute path to the source Laravel root}
                            {--host= : Source DB host}
                            {--database= : Source DB name}
                            {--username= : Source DB user}
                            {--password= : Source DB password}
                            {--port=3306 : Source DB port}
                            {--prefix= : Source DB table prefix}
                            {--driver=mysql : Source DB driver}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate view/template files for a specific site from a source installation';

    /**
     * Execute the console command.
     */
    public function handle(TemplateMigrationService $templateService): int
    {
        $siteId = $this->argument('site-id');
        $sourceRoot = $this->option('source-root');

        if (!$sourceRoot) {
            $this->error('The --source-root option is required to locate template files.');
            return 1;
        }

        // Setup the temporary source connection
        $dbConfig = [
            'driver'   => $this->option('driver') ?? 'mysql',
            'host'     => $this->option('host'),
            'port'     => $this->option('port'),
            'database' => $this->option('database'),
            'username' => $this->option('username'),
            'password' => $this->option('password'),
            'prefix'   => $this->option('prefix') ?? '',
        ];

        if (empty($dbConfig['host']) || empty($dbConfig['database'])) {
            $this->error('Database connection details are required to discover theme directories.');
            return 1;
        }

        try {
            config(['database.connections.temp_source_connection' => array_merge($dbConfig, [
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ])]);

            $this->info("Connecting to source database to fetch theme details...");
            DB::connection('temp_source_connection')->getPdo();

            $this->info("Starting template migration for Site ID: $siteId...");
            $result = $templateService->migrate($sourceRoot, $siteId);

            if ($result['success']) {
                $this->info($result['message']);
                
                foreach ($result['details'] as $theme) {
                    $this->comment("Theme: {$theme['theme']} ({$theme['directory']})");
                    foreach ($theme['paths'] as $path => $stats) {
                        $status = $stats['status'] === 'success' ? '<info>OK</info>' : '<error>ERR</error>';
                        $this->line("  - $path: $status (Files: {$stats['copied']})");
                    }
                }
                return 0;
            }

            $this->error($result['message']);
            return 1;

        } catch (\Exception $e) {
            $this->error("Failed to migrate templates: " . $e->getMessage());
            return 1;
        }
    }
}
