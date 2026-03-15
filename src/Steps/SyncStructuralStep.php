<?php

namespace HashtagCms\MigrationTool\Steps;

use Illuminate\Support\Facades\DB;

/**
 * Step 2: Structural Synchronization
 *
 * Creates the site record and then syncs all tables that pivot
 * global entities (Step 1) TO the site. Must run before any
 * site-scoped content (Steps 3 & 4).
 *
 * Tables covered (12):
 *   sites, lang_site, site_langs, platform_site, currency_site,
 *   country_site, hook_site, site_zone, site_user, site_props,
 *   themes, modules
 */
class SyncStructuralStep extends AbstractMigrationStep
{
    public function getName(): string
    {
        return "Structural Synchronization (Sites, Themes, Modules)";
    }

    public function execute(int $siteId, array $config): array
    {
        $results = [];

        // ── 1. Sync Site record ───────────────────────────────────────────────
        $sourceSite = $this->sourceConnection->table('sites')->where('id', $siteId)->first();
        if (!$sourceSite) {
            throw new \Exception("Source site not found");
        }

        $sourceSite = (array)$sourceSite;
        $oldSiteId  = $sourceSite['id'];

        $mappedSite               = $this->transform('sites', $sourceSite);
        $sourcePlatformId          = $this->getPlatformId($sourceSite);
        $mappedSite['platform_id'] = $this->service->getFromMap('platforms', $sourcePlatformId) ?? $sourcePlatformId;
        // theme_id must NOT be set during insert/overwrite — it's resolved in
        // the 2nd pass AFTER themes are synced. If left in, the overwrite path
        // would write the old source theme_id to the target immediately.
        unset($mappedSite['theme_id']);

        $domain       = $sourceSite['domain'];
        $existingSite = DB::table('sites')->where('domain', $domain)->first();

        if ($existingSite) {
            $strategy = $config['conflict_strategy'] ?? 'terminate';

            if ($strategy === 'overwrite') {
                DB::table('sites')->where('id', $existingSite->id)->update($mappedSite);
                $newSiteId         = $existingSite->id;
                $results['site']   = "Updated existing site: $domain";
            } elseif ($strategy === 'rename') {
                $mappedSite['domain'] = $domain . '-migrated-' . time();
                $mappedSite['name']   = $sourceSite['name'] . ' (Migrated)';
                $newSiteId            = DB::table('sites')->insertGetId($mappedSite);
                $results['site']      = "Renamed and created new site: {$mappedSite['domain']}";
            } else {
                throw new \Exception("Site with domain '$domain' already exists. Use 'overwrite' or 'rename' strategy.");
            }
        } else {
            $this->reportProgress(32, "Migrating site configuration: " . $sourceSite['domain']);
            $newSiteId       = DB::table('sites')->insertGetId($mappedSite);
            $results['site'] = "Created new site: $domain";
        }

        $this->service->addToMap('sites', $oldSiteId, $newSiteId);

        // ── 2. lang_site — PIVOT: which languages are active for this site ────
        if ($this->sourceTableExists('lang_site')) {
            $results['lang_site'] = $this->syncSitePivot('lang_site', $oldSiteId, $newSiteId, [
                'lang_id' => 'langs',
            ]);
        }

        // ── 3. site_langs — TRANSLATION: site name/description per language ───
        if ($this->sourceTableExists('site_langs')) {
            $results['site_langs'] = $this->syncSiteLangs($oldSiteId, $newSiteId);
        }

        //    These associate Step-1 global entities to this site.
        $platformSiteTable = $this->getSourceTable('platform_site');
        if ($this->sourceTableExists($platformSiteTable)) {
            $results['platform_site'] = $this->syncSitePivot($platformSiteTable, $oldSiteId, $newSiteId, [
                'platform_id' => 'platforms',
            ]);
        }
        
        if ($this->sourceTableExists('currency_site')) {
            $results['currency_site'] = $this->syncSitePivot('currency_site', $oldSiteId, $newSiteId, [
                'currency_id' => 'currencies',
            ]);
        }
        
        if ($this->sourceTableExists('country_site')) {
            $results['country_site']  = $this->syncSitePivot('country_site',  $oldSiteId, $newSiteId, [
                'country_id'  => 'countries',
            ]);
        }

        if ($this->sourceTableExists('hook_site')) {
            $results['hook_site']     = $this->syncSitePivot('hook_site',     $oldSiteId, $newSiteId, [
                'hook_id'     => 'hooks',
            ]);
        }

        if ($this->sourceTableExists('site_zone')) {
            $results['site_zone']     = $this->syncSitePivot('site_zone',     $oldSiteId, $newSiteId, [
                'zone_id'     => 'zones',
            ]);
        }

        if ($this->sourceTableExists('site_user')) {
            $results['site_user']     = $this->syncSitePivot('site_user',     $oldSiteId, $newSiteId, [
                'user_id'     => 'users',
            ]);
        }

        // ── 5a. Themes ────────────────────────────────────────────────────────
        if ($this->sourceTableExists('themes')) {
            $this->reportProgress(40, "Syncing themes for site...");
            $this->syncSiteEntity('themes', $oldSiteId, $newSiteId, 'alias');
            $results['themes'] = "Synced themes";

            // ── 5b. Post-structural pass: back-fill site.theme_id ─────────────────
            //    Now that themes exist in the target we can resolve the FK.
            if (!empty($sourceSite['theme_id'])) {
                $newThemeId = $this->service->getFromMap('themes', $sourceSite['theme_id']);
                if ($newThemeId) {
                    DB::table('sites')->where('id', $newSiteId)->update(['theme_id' => $newThemeId]);
                    $results['site_theme_update'] = "Back-filled theme_id to $newThemeId";
                }
            }
        }

        // ── 6. Modules (site-scoped) ──────────────────────────────────────────
        if ($this->sourceTableExists('modules')) {
            $this->reportProgress(45, "Syncing modules for site...");
            $this->syncSiteEntity('modules', $oldSiteId, $newSiteId, 'alias');
            $results['modules'] = "Synced modules";
        }

        // ── 7. site_props — Key-value configuration for this site ─────────────
        if ($this->sourceTableExists('site_props')) {
            $this->reportProgress(48, "Syncing site properties...");
            $results['site_props'] = $this->syncSiteProps($oldSiteId, $newSiteId);
        }

        return $results;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helper methods
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Sync a *_site (or site_*) pivot table.
     *
     * Remaps site_id to the new site ID, and remaps every other FK column
     * via the ID map. Uses updateOrInsert keyed on all FK columns so it is
     * safe to re-run (idempotent).
     *
     * @param string $table      e.g. 'lang_site', 'platform_site'
     * @param int    $oldSiteId
     * @param int    $newSiteId
     * @param array  $fkMap      [ 'column' => 'mapTable' ]
     */
    protected function syncSitePivot(string $table, int $oldSiteId, int $newSiteId, array $fkMap): string
    {
        $sourceTable = $this->getSourceTable($table);
        $sourceData = $this->sourceConnection->table($sourceTable)->where('site_id', $oldSiteId)->get();
        $count      = 0;

        foreach ($sourceData as $row) {
            $row               = (array)$row;
            // Strip id/timestamps before passing to updateOrInsert.
            // Without this, the old source id would overwrite the target's
            // auto-increment id, causing duplicate key or data corruption.
            $cleanRow          = $this->transform($table, $row);
            $cleanRow['site_id'] = $newSiteId;
            $uniqueKeys          = ['site_id' => $newSiteId];

            foreach ($fkMap as $column => $mapTable) {
                if (isset($row[$column])) {
                    $cleanRow[$column]   = $this->service->getFromMap($mapTable, $row[$column]) ?? $row[$column];
                    $uniqueKeys[$column] = $cleanRow[$column];
                }
            }

            DB::table($table)->updateOrInsert($uniqueKeys, $cleanRow);
            $count++;
        }

        return "Synced $count rows";
    }

    /**
     * Sync site_langs — the translation table for the sites entity.
     *
     * Stores translated metadata about the site itself (name, description, etc.)
     * per language.   Structure: site_id + lang_id + translated fields.
     *
     * Distinct from lang_site which is purely a pivot (no translated content).
     */
    protected function syncSiteLangs(int $oldSiteId, int $newSiteId): string
    {
        $sourceData = $this->sourceConnection
            ->table('site_langs')
            ->where('site_id', $oldSiteId)
            ->get();

        $count = 0;
        foreach ($sourceData as $row) {
            $row           = (array)$row;
            $newLangId     = $this->service->getFromMap('langs', $row['lang_id']) ?? $row['lang_id'];

            $data            = $this->transform('site_langs', $row);
            $data['site_id'] = $newSiteId;
            $data['lang_id'] = $newLangId;

            DB::table('site_langs')->updateOrInsert(
                ['site_id' => $newSiteId, 'lang_id' => $newLangId],
                $data
            );
            $count++;
        }

        return "Synced $count translation rows";
    }

    /**
     * Sync site_props — key-value configuration properties for a site.
     *
     * Unique constraint: (site_id, platform_id, name, group_name)
     * platform_id is remapped from the platforms map (Step 1).
     */
    protected function syncSiteProps(int $oldSiteId, int $newSiteId): string
    {
        $sourceData = $this->sourceConnection
            ->table('site_props')
            ->where('site_id', $oldSiteId)
            ->get();

        $count = 0;
        foreach ($sourceData as $row) {
            $row = (array) $row;

            $oldPlatformId = $this->getPlatformId($row);
            $newPlatformId = $this->service->getFromMap('platforms', $oldPlatformId) ?? $oldPlatformId;

            $data                = $this->transform('site_props', $row);
            $data['site_id']     = $newSiteId;
            $data['platform_id'] = $newPlatformId;

            // Unique constraint key
            DB::table('site_props')->updateOrInsert(
                [
                    'site_id'     => $newSiteId,
                    'platform_id' => $newPlatformId,
                    'name'        => $row['name'],
                    'group_name'  => $row['group_name'],
                ],
                $data
            );
            $count++;
        }

        return "Synced $count site prop rows";
    }

    /**
     * Sync a site-scoped entity table.
     *
     * Deduplicates by $compareKey within the site. If a row with the same
     * alias/name already exists for the new site, maps to it. Otherwise inserts.
     *
     * Used for: themes, modules
     */
    protected function syncSiteEntity(string $table, int $oldSiteId, int $newSiteId, string $compareKey): void
    {
        $this->sourceConnection->table($table)
            ->where('site_id', $oldSiteId)
            ->orderBy('id')
            ->chunk(100, function ($rows) use ($table, $newSiteId, $compareKey) {
                foreach ($rows as $row) {
                    $row   = (array)$row;
                    $oldId = $row['id'];

                    $local = DB::table($table)
                        ->where('site_id', $newSiteId)
                        ->where($compareKey, $row[$compareKey])
                        ->first();

                    if (!$local) {
                        $data            = $this->transform($table, $row);
                        $data['site_id'] = $newSiteId;
                        $newId           = DB::table($table)->insertGetId($data);
                        $this->service->addToMap($table, $oldId, $newId);
                    } else {
                        $this->service->addToMap($table, $oldId, $local->id);
                    }
                }
            });
    }
}
