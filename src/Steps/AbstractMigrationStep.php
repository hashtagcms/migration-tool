<?php

namespace HashtagCms\MigrationTool\Steps;

use HashtagCms\MigrationTool\Services\SiteMigrationService;
use Illuminate\Support\Facades\DB;


abstract class AbstractMigrationStep implements MigrationStepInterface
{
    protected $sourceConnection;
    protected bool $isLegacyTenants = false;
    protected array $sourceTableNames = [];
    protected ?\Closure $onProgress = null;

    public function __construct(SiteMigrationService $service, ?\Closure $onProgress = null)
    {
        $this->service = $service;
        $this->onProgress = $onProgress;
        $this->sourceConnection = DB::connection('temp_source_connection');
        
        // Cache table names to avoid multiple DB calls and handle missing tables safely
        $this->sourceTableNames = $this->getSourceTableNames();

        // Detect if the source database uses the legacy 'tenants' terminology
        $this->detectLegacyMode();
    }

    /**
     * Check if a table exists in the source database.
     */
    protected function sourceTableExists(string $table): bool
    {
        return in_array($this->getSourceTable($table), $this->sourceTableNames);
    }

    /**
     * Auto-detect if source DB is using legacy 'tenants' table.
     */
    protected function detectLegacyMode(): void
    {
        try {
            if (in_array('tenants', $this->sourceTableNames) && !in_array('platforms', $this->sourceTableNames)) {
                $this->isLegacyTenants = true;
            }
        } catch (\Exception $e) {
            // Fallback: unable to detect legacy mode — default to modern schema
        }
    }

    /**
     * Fetch all table names from the source connection in a driver-aware way.
     *
     * Supports MySQL, PostgreSQL, and SQLite. Falls back to an empty array
     * for unsupported drivers (legacy detection is skipped, not crashed).
     *
     * @return string[]
     */
    protected function getSourceTableNames(): array
    {
        $driver = $this->sourceConnection->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $rows = $this->sourceConnection->select('SHOW TABLES');
            $firstRow = (array) ($rows[0] ?? []);
            $colName  = array_key_first($firstRow);
            return array_column(array_map(fn($r) => (array) $r, $rows), $colName);
        }

        if ($driver === 'pgsql') {
            $rows = $this->sourceConnection->select(
                "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"
            );
            return array_column(array_map(fn($r) => (array) $r, $rows), 'tablename');
        }

        if ($driver === 'sqlite') {
            $rows = $this->sourceConnection->select(
                "SELECT name FROM sqlite_master WHERE type = 'table'"
            );
            return array_column(array_map(fn($r) => (array) $r, $rows), 'name');
        }

        return []; // Unknown driver — legacy mode detection skipped
    }

    /**
     * Get the correct source table name (handles tenants -> platforms)
     */
    protected function getSourceTable(string $table): string
    {
        if ($this->isLegacyTenants && $table === 'platforms') {
            return 'tenants';
        }
        return $table;
    }

    /**
     * Transform a source row before inserting into the target.
     * Strips IDs/timestamps AND handles tenant_id -> platform_id mapping.
     */
    protected function transform(string $table, array $row): array
    {
        $data = [];
        foreach ($row as $key => $value) {
            $data[$key] = $this->normalizeValue($key, $value);
        }

        // 1. Handle legacy column mapping (tenant_id -> platform_id)
        if (array_key_exists('tenant_id', $data)) {
            $data['platform_id'] = $data['tenant_id'];
            unset($data['tenant_id']);
        }

        if (array_key_exists('platform_id', $data)) {
            $oldPlatformId = $data['platform_id'];
            // Only remap if we have a valid positive integer ID
            if (!is_null($oldPlatformId) && (int)$oldPlatformId > 0) {
                $data['platform_id'] = $this->service->getFromMap('platforms', (int)$oldPlatformId) ?? $oldPlatformId;
            }
        }

        // 2. Strip system columns to let the target DB handle them
        unset($data['id']);
        unset($data['created_at']);
        unset($data['updated_at']);

        return $data;
    }

    /**
     * Get platform_id / tenant_id from a source row safely.
     */
    protected function getPlatformId(array $row): int
    {
        return (int) ($row['platform_id'] ?? $row['tenant_id'] ?? 0);
    }

    /**
     * Fix specific data quirks found in older versions.
     */
    protected function normalizeValue(string $key, mixed $value): mixed
    {
        // Direction enum fix: empty strings in source should be null in target
        if ($key === 'direction' && ($value === '' || $value === null)) {
            return null;
        }
        
        return $value;
    }

    /**
     * Get a safe column to use for orderBy during chunking.
     * Prefers 'id', falls back to first available column.
     */
    protected function getSafeOrderColumn(string $table): string
    {
        $columns = $this->sourceConnection->getSchemaBuilder()->getColumnListing($table);
        if (in_array('id', $columns)) {
            return 'id';
        }
        // Fallback to first column (usually a primary or foreign key)
        return $columns[0] ?? 'id';
    }

    /**
     * Helper to safely chunk a source table even if 'id' is missing.
     */
    protected function safeChunk(string $table, \Closure $callback, int $size = 200, ?\Closure $queryModifier = null): void
    {
        $query = $this->sourceConnection->table($table);
        if ($queryModifier) {
            $queryModifier($query);
        }

        $orderCol = $this->getSafeOrderColumn($table);
        $query->orderBy($orderCol)->chunk($size, $callback);
    }

    /**
     * Generic sync for simple site-scoped entities that only have site_id as FK.
     * Inserts all rows for the old site, remaps site_id, records ID in map.
     *
     * Used for: galleries, menu_managers, microsites, festivals, subscribers, contacts
     */
    protected function syncSimpleSiteEntity(string $table, int $oldSiteId, int $newSiteId): string
    {
        $total = 0;
        // Pass 1: Insert flat
        $this->safeChunk($table, function ($rows) use ($table, $newSiteId, &$total) {
            foreach ($rows as $row) {
                $row   = (array)$row;
                $oldId = $row['id'] ?? null;

                $data            = $this->transform($table, $row);
                $data['site_id'] = $newSiteId;

                // If it's a hierarchical table (like menu_managers), temporarily set parent to 0
                if (isset($row['parent_id'])) {
                    $data['parent_id'] = 0;
                }

                $newId = DB::table($table)->insertGetId($data);
                if ($oldId) {
                    $this->service->addToMap($table, $oldId, $newId);
                }
                $total++;
            }
        }, 200, function ($query) use ($oldSiteId) {
            $query->where('site_id', $oldSiteId);
        });

        // Pass 2: Hierarchy (only for hierarchical tables like menu_managers)
        if ($this->sourceConnection->getSchemaBuilder()->hasColumn($table, 'parent_id')) {
            $this->safeChunk($table, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $row = (array)$row;
                    if (!empty($row['parent_id']) && $row['parent_id'] > 0) {
                        $newParentId = $this->service->getFromMap($table, $row['parent_id']);
                        if ($newParentId) {
                            $newId = $this->service->getFromMap($table, $row['id']);
                            DB::table($table)->where('id', $newId)->update(['parent_id' => $newParentId]);
                        }
                    }
                }
            }, 200, function ($query) use ($oldSiteId) {
                $query->where('site_id', $oldSiteId);
            });
        }

        return "Synced $total $table entities";
    }

    /**
     * Sync a pivot table whose rows are identified entirely by FK columns,
     * all of which must already be in the ID map.
     */
    protected function syncPivotByMap(string $table, array $fkMap): string
    {
        $total = 0;
        $firstColumn    = array_key_first($fkMap);
        $firstMapTable  = $fkMap[$firstColumn];
        $oldIds         = array_keys($this->service->getFullMap($firstMapTable));

        if (empty($oldIds)) return "No $table assignments to sync";

        foreach (array_chunk($oldIds, 200) as $chunk) {
            $this->safeChunk($table, function ($rows) use ($table, $fkMap, &$total) {
                foreach ($rows as $row) {
                    $row        = (array)$row;
                    $data       = $this->transform($table, $row);
                    $uniqueKeys = [];
                    $skip       = false;

                    foreach ($fkMap as $column => $mapTable) {
                        $newId = $this->service->getFromMap($mapTable, $row[$column]) ?? null;
                        if (!$newId) { $skip = true; break; }
                        $data[$column]       = $newId;
                        $uniqueKeys[$column] = $newId;
                    }

                    if ($skip) continue;

                    DB::table($table)->updateOrInsert($uniqueKeys, $data);
                    $total++;
                }
            }, 200, function ($query) use ($firstColumn, $chunk) {
                $query->whereIn($firstColumn, $chunk);
            });
        }
        return "Synced $total $table pivot rows";
    }

    /**
     * Report progress if callback is available.
     */
    protected function reportProgress(int $progress, string $message): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($progress, $message);
        }
    }
}
