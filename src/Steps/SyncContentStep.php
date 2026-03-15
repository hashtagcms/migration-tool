<?php

namespace HashtagCms\MigrationTool\Steps;

use Illuminate\Support\Facades\DB;

/**
 * Step 4: Content Layer Synchronization
 *
 * Syncs all _langs translation tables + content pivots.
 * Runs last because it depends on every ID map built in Steps 1–3.
 */
class SyncContentStep extends AbstractMigrationStep
{
    public function getName(): string
    {
        return "Content Layer Synchronization (Translations, Pages, Galleries)";
    }

    public function execute(int $siteId, array $config): array
    {
        $newSiteId = $this->service->getFromMap('sites', $siteId);
        $results   = [];

        // ── 1. Static Module Contents (entity + its _langs inline) ───────────
        if ($this->sourceTableExists('static_module_contents')) {
            $this->reportProgress(72, "Syncing Static Module Contents & Translations...");
            $this->syncTranslatableEntity(
                'static_module_contents',
                'static_module_content_langs',
                'static_module_content_id',
                $siteId,
                $newSiteId
            );
            $results['static_module_contents']      = "Synced static module contents";
            $results['static_module_content_langs'] = "Synced static module content translations";
        }

        // ── 2. All _langs translation tables ─────────────────────────────────
        $this->reportProgress(75, "Syncing structural translations...");
        $this->syncTranslations('hook_langs',         'hook_id',        'hooks');
        $results['hook_langs']        = "Synced hook translations";

        $this->syncTranslations('category_langs',    'category_id',    'categories');
        $results['category_langs']    = "Synced category translations";

        $this->syncTranslations('module_prop_langs', 'module_prop_id', 'module_props');
        $results['module_prop_langs'] = "Synced module prop translations";

        $this->syncTranslations('theme_langs',       'theme_id',       'themes');
        $results['theme_langs']       = "Synced theme translations";

        $this->syncTranslations('module_langs',      'module_id',      'modules');
        $results['module_langs']      = "Synced module translations";

        $this->syncTranslations('menu_manager_langs', 'menu_manager_id', 'menu_managers');
        $results['menu_manager_langs'] = "Synced menu manager translations";

        // ── 3. Pages (2-pass for parent_id hierarchy) + page_langs ───────────
        if ($this->sourceTableExists('pages')) {
            $this->reportProgress(80, "Syncing hierarchical pages and content...");
            $this->syncPages($siteId, $newSiteId);
            $results['pages']      = "Synced pages (hierarchical, 2-pass)";
            $results['page_langs'] = "Synced page translations";
        }

        // ── 4. Gallery pivot tables ───────────────────────────────────────────
        if ($this->sourceTableExists('gallery_page')) {
            $results['gallery_page'] = $this->syncPivotByMap('gallery_page', [
                'gallery_id' => 'galleries',
                'page_id'    => 'pages',
            ]);
        }

        if ($this->sourceTableExists('gallery_tag')) {
            $results['gallery_tag'] = $this->syncPivotByMap('gallery_tag', [
                'gallery_id' => 'galleries',
                'tag_id'     => 'tags',
            ]);
        }

        return $results;
    }

    /**
     * Sync a site-scoped entity that has its own _langs child table.
     */
    protected function syncTranslatableEntity(
        string $table,
        string $langTable,
        string $idColumn,
        int $oldSiteId,
        int $newSiteId
    ): void {
        $sourceData = $this->sourceConnection->table($table)
            ->where('site_id', $oldSiteId)
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($table, $langTable, $idColumn, $newSiteId) {
                foreach ($rows as $row) {
                    $row   = (array)$row;
                    $oldId = $row['id'];

                    $data            = $this->transform($table, $row);
                    $data['site_id'] = $newSiteId;

                    $local = DB::table($table)
                        ->where('site_id', $newSiteId)
                        ->where('alias',   $row['alias'])
                        ->first();

                    if ($local) {
                        $newId = $local->id;
                        $this->service->addToMap($table, $oldId, $newId);
                        continue;
                    }

                    $newId = DB::table($table)->insertGetId($data);
                    $this->service->addToMap($table, $oldId, $newId);

                    $sourceLangTable = $this->getSourceTable($langTable);
                    $sourceLangs = $this->sourceConnection->table($sourceLangTable)->where($idColumn, $oldId)->get();
                    foreach ($sourceLangs as $lRow) {
                        $lRow             = (array)$lRow;
                        $lData            = $this->transform($langTable, $lRow);
                        $lData[$idColumn] = $newId;
                        $lData['lang_id'] = $this->service->getFromMap('langs', $lRow['lang_id']) ?? $lRow['lang_id'];

                        DB::table($langTable)->updateOrInsert(
                            [$idColumn => $newId, 'lang_id' => $lData['lang_id']],
                            $lData
                        );
                    }
                }
            });
    }

    /**
     * Sync translations for global/shared entities.
     */
    protected function syncTranslations(string $table, string $idColumn, string $mapTable): void
    {
        $map = $this->service->getFullMap($mapTable);
        if (empty($map)) return;

        $oldIds = array_keys($map);
        $sourceTable = $this->getSourceTable($table);
        if (!$this->sourceTableExists($table)) return;

        foreach (array_chunk($oldIds, 200) as $chunk) {
            $sourceLangs = $this->sourceConnection->table($sourceTable)
                ->whereIn($idColumn, $chunk)
                ->orderBy($idColumn) // pivot
                ->get();

            foreach ($sourceLangs as $lRow) {
                $lRow      = (array)$lRow;
                $newId     = $map[$lRow[$idColumn]];
                $newLangId = $this->service->getFromMap('langs', $lRow['lang_id']) ?? $lRow['lang_id'];

                $lData             = $this->transform($table, $lRow);
                $lData[$idColumn]  = $newId;
                $lData['lang_id']  = $newLangId;

                DB::table($table)->updateOrInsert(
                    [$idColumn => $newId, 'lang_id' => $newLangId],
                    $lData
                );
            }
        }
    }

    /**
     * Sync pages (3-pass: flat -> hierarchy -> translations).
     */
    protected function syncPages(int $oldSiteId, int $newSiteId): void
    {
        $query = $this->sourceConnection->table('pages')->where('site_id', $oldSiteId);

        // Pass 1: Insert flat
        $total = (clone $query)->count();
        $processed = 0;
        (clone $query)
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($newSiteId, &$total, &$processed) {
                foreach ($rows as $row) {
                    $row   = (array)$row;
                    $oldId = $row['id'];

                    $data              = $this->transform('pages', $row);
                    $data['site_id']   = $newSiteId;
                    $data['parent_id'] = 0; 

                    if (!empty($row['category_id'])) {
                        $data['category_id'] = $this->service->getFromMap('categories', $row['category_id']) ?? $row['category_id'];
                    }
                    if (!empty($row['theme_id'])) {
                        $data['theme_id'] = $this->service->getFromMap('themes', $row['theme_id']) ?? $row['theme_id'];
                    }

                    $newId = DB::table('pages')->insertGetId($data);
                    $this->service->addToMap('pages', $oldId, $newId);
                }
                $processed += count($rows);
                if ($total > 0) {
                    $this->reportProgress(80 + (int)(($processed / $total) * 5), "Syncing pages (Pass 1/3: inserting flat)...");
                }
            });

        // Pass 2: Update parent_id
        $this->reportProgress(85, "Syncing pages (Pass 2/3: resolving hierarchy)...");
        (clone $query)
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $row = (array)$row;
                    if (!empty($row['parent_id']) && $row['parent_id'] > 0) {
                        $newParentId = $this->service->getFromMap('pages', $row['parent_id']);
                        if ($newParentId) {
                            $newId = $this->service->getFromMap('pages', $row['id']);
                            DB::table('pages')->where('id', $newId)->update(['parent_id' => $newParentId]);
                        }
                    }
                }
            });

        // Pass 3 — translations
        $this->reportProgress(88, "Syncing pages (Pass 3/3: translations)...");
        $this->syncTranslations('page_langs', 'page_id', 'pages');
    }
}
