<?php

namespace HashtagCms\MigrationTool\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

use HashtagCms\MigrationTool\Services\TemplateMigrationService;

class MigrationController extends Controller
{
    public function __construct()
    {
        $this->middleware(function (Request $request, \Closure $next) {
            if (($request->user()->user_type ?? null) !== 'Staff') {
                $message = 'Visitors are not allowed to access the migration tool.';

                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $message,
                    ], 403);
                }

                abort(403, $message);
            }

            return $next($request);
        });
    }

    protected function autoStartQueueWorkerOnce(): void
    {
        if (!config('migration-tool.auto_queue_work_once', false)) {
            return;
        }

        $queueDefault = (string) config('queue.default', 'sync');
        if (in_array($queueDefault, ['sync', 'null'], true)) {
            return;
        }

        if (!class_exists(Process::class)) {
            Log::warning('MigrationTool: Symfony Process is unavailable; cannot auto-start queue worker once.');
            return;
        }

        try {
            $queueName = (string) config("queue.connections.{$queueDefault}.queue", 'default');

            $command = [
                PHP_BINARY,
                'artisan',
                'queue:work',
                '--once',
                "--queue={$queueName}",
            ];

            $process = new Process($command, base_path());
            $process->disableOutput();
            $process->start();
        } catch (\Throwable $e) {
            Log::warning('MigrationTool: failed to auto-start queue worker once: ' . $e->getMessage());
        }
    }

    protected function registerSourceConnection(array $config)
    {
        Config::set("database.connections.temp_source_connection", $config);
    }

    protected function ensureSourceConnection()
    {
        $config = session('migration_source_db');
        if (!$config) {
            abort(400, 'Source database connection not configured in session.');
        }
        $this->registerSourceConnection($config);
    }

    public function index()
    {
        return view('migration-tool::index');
    }

    public function testConnection(Request $request)
    {
        $request->validate([
            'host' => 'required',
            'database' => 'required',
            'username' => 'required',
            'password' => 'nullable',
            'port' => 'required|numeric',
            'prefix' => 'nullable|string',
            'driver' => 'nullable|string',
        ]);

        $config = [
            'driver' => $request->driver ?? 'mysql',
            'host' => $request->host,
            'port' => $request->port,
            'database' => $request->database,
            'username' => $request->username,
            'password' => $request->password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $request->prefix ?? '',
        ];

        // Store in session to survive PHP statelessness
        session(['migration_source_db' => $config]);
        $this->registerSourceConnection($config);

        try {
            $connection = DB::connection('temp_source_connection');
            $connection->getPdo();

            // Detect legacy mode for UI display
            $tableNames = $this->getTableNames($connection);
            $isLegacy   = in_array('tenants', $tableNames) && !in_array('platforms', $tableNames);

            return response()->json([
                'success' => true,
                'message' => 'Connection successful!',
                'target'  => route('migration.analyze'),
                'legacy'  => $isLegacy
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Fetch all table names from a connection in a driver-aware way.
     * Supports MySQL/MariaDB, PostgreSQL, and SQLite.
     *
     * @return string[]
     */
    protected function getTableNames($connection): array
    {
        $driver = $connection->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $rows     = $connection->select('SHOW TABLES');
            $firstRow = (array) ($rows[0] ?? []);
            $colName  = array_key_first($firstRow);
            return array_column(array_map(fn($r) => (array) $r, $rows), $colName);
        }

        if ($driver === 'pgsql') {
            $rows = $connection->select(
                "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"
            );
            return array_column(array_map(fn($r) => (array) $r, $rows), 'tablename');
        }

        if ($driver === 'sqlite') {
            $rows = $connection->select(
                "SELECT name FROM sqlite_master WHERE type = 'table'"
            );
            return array_column(array_map(fn($r) => (array) $r, $rows), 'name');
        }

        return []; // Unknown driver — list returned empty
    }

    /**
     * Resolve PDO extension name from configured DB driver.
     */
    protected function pdoExtensionForDriver(string $dbDriver): string
    {
        return $dbDriver === 'mariadb' ? 'pdo_mysql' : 'pdo_' . $dbDriver;
    }

    /**
     * Safely count rows in a table. Returns 0 if table does not exist.
     */
    protected function safeCount($connection, string $table, array $tableNames, ?int $siteId = null): int
    {
        if (!in_array($table, $tableNames)) {
            return 0;
        }
        $query = $connection->table($table);
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }
        return $query->count();
    }

    public function analyze(Request $request)
    {
        $this->ensureSourceConnection();
        $connection = DB::connection('temp_source_connection');
        $sourceRoot = $request->input('source_root_path');

        try {
            $tableNames    = $this->getTableNames($connection);
            $platformTable = (in_array('tenants', $tableNames) && !in_array('platforms', $tableNames))
                             ? 'tenants'
                             : 'platforms';
            $summary = [
                'sites'            => $this->safeCount($connection, 'sites', $tableNames),
                'users'            => $this->safeCount($connection, 'users', $tableNames),
                'roles'            => $this->safeCount($connection, 'roles', $tableNames),
                'modules'          => $this->safeCount($connection, 'modules', $tableNames),
                'categories'       => $this->safeCount($connection, 'categories', $tableNames),
                'pages'            => $this->safeCount($connection, 'pages', $tableNames),
                'themes'           => $this->safeCount($connection, 'themes', $tableNames),
                'static_contents'  => $this->safeCount($connection, 'static_module_contents', $tableNames),
                'galleries'        => $this->safeCount($connection, 'galleries', $tableNames),
                'site_props'       => $this->safeCount($connection, 'site_props', $tableNames),
                'platforms'        => $this->safeCount($connection, $platformTable, $tableNames),
            ];

            $packageCheck = $this->compareComposerPackages($sourceRoot);

            return response()->json([
                'success' => true,
                'summary' => $summary,
                'package_warnings' => $packageCheck,
                'sites_list' => $connection->table('sites')->get(['id', 'name', 'domain'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Analysis failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Compare legacy composer.json with current and suggest missing packages.
     */
    protected function compareComposerPackages(?string $sourcePath): array
    {
        if (!$sourcePath || !File::exists($sourcePath . '/composer.json')) {
            return [];
        }

        try {
            $legacyComposer = json_decode(File::get($sourcePath . '/composer.json'), true);
            $currentComposer = json_decode(File::get(base_path('composer.json')), true);

            $legacyReq = $legacyComposer['require'] ?? [];
            $currentReq = $currentComposer['require'] ?? [];

            $missing = [];
            foreach ($legacyReq as $pkg => $ver) {
                // Ignore PHP and standard Laravel/HashtagCMS packages
                if ($pkg === 'php' || str_starts_with($pkg, 'laravel/') || str_contains($pkg, 'hashtagcms')) {
                    continue;
                }
                
                if (!isset($currentReq[$pkg])) {
                    $missing[] = $pkg;
                }
            }

            return $missing;
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getSiteDetails(Request $request)
    {
        $request->validate(['site_id' => 'required|numeric']);
        $this->ensureSourceConnection();
        $siteId = $request->site_id;
        $connection = DB::connection('temp_source_connection');

        try {
            $tableNames = $this->getTableNames($connection);

            // Fetch detailed counts for the specific site
            $details = [
                'categories'     => $this->safeCount($connection, 'categories',            $tableNames, $siteId),
                'modules'        => $this->safeCount($connection, 'modules',               $tableNames, $siteId),
                'pages'          => $this->safeCount($connection, 'pages',                 $tableNames, $siteId),
                'themes'         => $this->safeCount($connection, 'themes',                $tableNames, $siteId),
                'module_props'   => $this->safeCount($connection, 'module_props',           $tableNames, $siteId),
                'site_props'     => $this->safeCount($connection, 'site_props',             $tableNames, $siteId),
                'galleries'      => $this->safeCount($connection, 'galleries',              $tableNames, $siteId),
                'microsites'     => $this->safeCount($connection, 'microsites',             $tableNames, $siteId),
                'festivals'      => $this->safeCount($connection, 'festivals',              $tableNames, $siteId),
                'comments'       => $this->safeCount($connection, 'comments',               $tableNames, $siteId),
                'subscribers'    => $this->safeCount($connection, 'subscribers',            $tableNames, $siteId),
            ];

            return response()->json([
                'success' => true,
                'details' => $details
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch site details: ' . $e->getMessage()
            ]);
        }
    }

    public function runMigration(Request $request)
    {
        $request->validate([
            'site_id' => 'required|numeric',
            'conflict_strategy' => 'required|string|in:terminate,overwrite,rename',
            'copy_media' => 'nullable|boolean',
            'source_root_path' => 'nullable|string',
        ]);

        $this->ensureSourceConnection();
        $dbConfig = session('migration_source_db');
        $jobId    = (string) \Illuminate\Support\Str::uuid();

        // Create log entry
        DB::table('cms_migration_logs')->insert([
            'job_id' => $jobId,
            'site_id' => $request->site_id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Dispatch background job
        \HashtagCms\MigrationTool\Jobs\ProcessMigration::dispatch(
            $request->site_id,
            [
                'conflict_strategy' => $request->conflict_strategy,
                'copy_media' => $request->copy_media ?? false,
                'source_root_path' => $request->source_root_path
            ],
            $dbConfig,
            $jobId
        );

        $this->autoStartQueueWorkerOnce();

        return response()->json([
            'success' => true,
            'job_id' => $jobId,
            'message' => 'Migration started in background'
        ]);
    }

    /**
     * Pre-flight requirements check before starting a migration.
     *
     * Returns a structured list of checks: each item has:
     *   - label   : human-readable name
     *   - status  : 'pass' | 'warning' | 'fail'
     *   - message : supporting detail
     *   - critical: bool — if true and status is 'fail', migration must be blocked
     */
    public function checkRequirements(Request $request)
    {
        $request->validate([
            'site_id'           => 'required|numeric',
            'copy_media'        => 'nullable|boolean',
            'source_root_path'  => 'nullable|string',
        ]);

        $this->ensureSourceConnection();
        $connection   = DB::connection('temp_source_connection');
        $siteId       = (int) $request->site_id;
        $copyMedia    = (bool) ($request->copy_media ?? false);
        $sourceRoot   = $request->source_root_path;

        $checks = [];

        // ── 1. PHP Version ────────────────────────────────────────────────────
        $phpVersion    = PHP_VERSION;
        $phpOk         = version_compare($phpVersion, '8.2.0', '>=');
        $checks[]      = [
            'label'    => 'PHP Version (>= 8.2)',
            'status'   => $phpOk ? 'pass' : 'fail',
            'message'  => "Running PHP $phpVersion",
            'critical' => true,
        ];

        // ── 2. Required PHP Extensions ────────────────────────────────────────
        $requiredExts = ['pdo', 'mbstring', 'json', 'openssl'];
        foreach ($requiredExts as $ext) {
            $loaded   = extension_loaded($ext);
            $checks[] = [
                'label'    => "PHP Extension: $ext",
                'status'   => $loaded ? 'pass' : 'fail',
                'message'  => $loaded ? "Loaded" : "Extension '$ext' is missing. Install it via php.ini.",
                'critical' => true,
            ];
        }

        // ── 3. PDO Driver Extension ───────────────────────────────────────────
        $dbDriver   = session('migration_source_db')['driver'] ?? 'mysql';
        $pdoDriver  = $this->pdoExtensionForDriver($dbDriver);
        $pdoLoaded  = extension_loaded($pdoDriver);
        $checks[]   = [
            'label'    => "PDO Driver: $pdoDriver",
            'status'   => $pdoLoaded ? 'pass' : 'fail',
            'message'  => $pdoLoaded ? "Loaded" : "Install the '$pdoDriver' extension for your PHP build.",
            'critical' => true,
        ];

        // ── 4. Source DB — Connection Alive ───────────────────────────────────
        try {
            $connection->getPdo();
            $checks[] = [
                'label'    => 'Source DB Connection',
                'status'   => 'pass',
                'message'  => 'Successfully connected to source database.',
                'critical' => true,
            ];
        } catch (\Exception $e) {
            $checks[] = [
                'label'    => 'Source DB Connection',
                'status'   => 'fail',
                'message'  => 'Cannot connect: ' . $e->getMessage(),
                'critical' => true,
            ];
        }

        // ── 5. Source DB — Site Exists ────────────────────────────────────────
        try {
            $siteExists = $connection->table('sites')->where('id', $siteId)->exists();
            $checks[]   = [
                'label'    => "Source Site (ID: $siteId) Exists",
                'status'   => $siteExists ? 'pass' : 'fail',
                'message'  => $siteExists ? "Site #$siteId found in source database." : "No site with ID $siteId found in the source database.",
                'critical' => true,
            ];
        } catch (\Exception $e) {
            $checks[] = [
                'label'    => "Source Site (ID: $siteId) Exists",
                'status'   => 'fail',
                'message'  => 'Could not query sites table: ' . $e->getMessage(),
                'critical' => true,
            ];
        }

        // ── 6. Source DB — Core Tables Present ───────────────────────────────
        $requiredSourceTables = ['sites', 'users', 'pages', 'categories', 'themes', 'modules'];
        try {
            $presentTables = $this->getTableNames($connection);
            $missingTables = array_diff($requiredSourceTables, $presentTables);
            $checks[]      = [
                'label'    => 'Source DB Core Tables',
                'status'   => empty($missingTables) ? 'pass' : 'fail',
                'message'  => empty($missingTables)
                              ? 'All required source tables are present.'
                              : 'Missing tables: ' . implode(', ', $missingTables),
                'critical' => true,
            ];
        } catch (\Exception $e) {
            $checks[] = [
                'label'    => 'Source DB Core Tables',
                'status'   => 'warning',
                'message'  => 'Could not verify source tables: ' . $e->getMessage(),
                'critical' => false,
            ];
        }

        // ── 7. Target DB — Migration Table Exists ────────────────────────────
        $logsTableExists = \Illuminate\Support\Facades\Schema::hasTable('cms_migration_logs');
        $checks[]        = [
            'label'    => 'Target DB: Migration Logs Table',
            'status'   => $logsTableExists ? 'pass' : 'fail',
            'message'  => $logsTableExists
                          ? 'cms_migration_logs table exists.'
                          : 'Run `php artisan migrate` first. The cms_migration_logs table is missing.',
            'critical' => true,
        ];

        // ── 8. Queue Configuration ────────────────────────────────────────────
        $queueDriver  = config('queue.default', 'sync');
        $queueIsAsync = !in_array($queueDriver, ['sync', 'null']);
        $checks[]     = [
            'label'    => 'Queue Driver',
            'status'   => $queueIsAsync ? 'pass' : 'warning',
            'message'  => $queueIsAsync
                          ? "Queue driver '$queueDriver' is configured. Background jobs will work."
                          : "Queue driver is '$queueDriver'. Migration will run synchronously and may time out for large sites. Configure a real queue driver (redis, database) for production use.",
            'critical' => false,
        ];

        // ── 9. Media Source Path (if copy_media is enabled) ──────────────────
        if ($copyMedia) {
            if (!$sourceRoot) {
                $checks[] = [
                    'label'    => 'Media Source Path',
                    'status'   => 'fail',
                    'message'  => '"Migrate Media" is enabled but no Source Installation Path was provided.',
                    'critical' => true,
                ];
            } elseif (!\Illuminate\Support\Facades\File::isDirectory($sourceRoot)) {
                $checks[] = [
                    'label'    => 'Media Source Path',
                    'status'   => 'fail',
                    'message'  => "The path '$sourceRoot' does not exist or is not a directory.",
                    'critical' => true,
                ];
            } else {
                $checks[] = [
                    'label'    => 'Media Source Path',
                    'status'   => 'pass',
                    'message'  => "Source path '$sourceRoot' is accessible.",
                    'critical' => true,
                ];
            }
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $hasCriticalFailure = collect($checks)
            ->filter(fn($c) => $c['critical'] && $c['status'] === 'fail')
            ->isNotEmpty();

        return response()->json([
            'success'              => true,
            'checks'               => $checks,
            'can_proceed'          => !$hasCriticalFailure,
            'critical_failure'     => $hasCriticalFailure,
        ]);
    }

    public function checkProgress($jobId)
    {
        $log = DB::table('cms_migration_logs')->where('job_id', $jobId)->first();
        if (!$log) {
            return response()->json(['success' => false, 'message' => 'Job not found']);
        }

        return response()->json([
            'success' => true,
            'status' => $log->status,
            'progress' => $log->progress,
            'message' => $log->message,
            'results' => json_decode($log->results, true)
        ]);
    }

    /**
     * Handle template/view file migration via the web UI.
     */
    public function migrateTemplates(Request $request, TemplateMigrationService $service)
    {
        $request->validate([
            'site_id'     => 'required|numeric',
            'source_root' => 'required|string',
        ]);

        $this->ensureSourceConnection();
        $sourceRoot = $request->input('source_root');
        $siteId     = (int) $request->input('site_id');

        try {
            $result = $service->migrate($sourceRoot, $siteId);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
