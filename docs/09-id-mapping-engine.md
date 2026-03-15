# 09 — ID Mapping Engine

The **ID Mapping Engine** is the most critical component of the Migration Tool. It solves the fundamental problem of cross-database migrations: **IDs on the source database are different from IDs on the target database**.

---

## The Problem

When migrating a `Category` record:

```
Source DB                         Target DB
─────────────────                 ─────────────────
categories.id = 42    ────X────▶  INSERT → id = 187  (auto-increment)
```

Now every record that references `category_id = 42` in the source must be updated to reference `category_id = 187` in the target. This includes:

- `category_langs.category_id`
- `category_site.category_id`
- `module_site.category_id`
- Child categories: `categories.parent_id`

Without the mapping engine, all these foreign keys would point to invalid or wrong records.

---

## Implementation

The `SiteMigrationService` holds an in-memory map array:

```php
protected array $idMap = [];
// Shape: ['table_name' => ['old_id' => 'new_id']]
```

### Key Methods

#### `addToMap(string $table, $oldId, $newId): void`

Called immediately after every `DB::table()->insert()` to register the ID transition:

```php
$newId = DB::table('categories')->insertGetId($record);
$this->service->addToMap('categories', $oldId, $newId);
```

#### `getFromMap(string $table, $oldId): mixed`

Resolves a source ID to its target equivalent. Returns `null` if not found:

```php
$targetCategoryId = $this->service->getFromMap('categories', $row['category_id']);
```

#### `getFullMap(string $table): array`

Returns the full `[old_id => new_id]` map for a given table. Used by `SyncContentStep` for bulk `whereIn` queries:

```php
$map = $this->service->getFullMap('categories');
$oldIds = array_keys($map);  // All source IDs to fetch translations for
```

---

## Map Population Order

The map is built sequentially, layer by layer:

```
Layer 1 Seeds:
  idMap['langs']      = [1 => 3, 2 => 7, ...]
  idMap['platforms']  = [1 => 1, 2 => 4, ...]  ← often same if shared infra
  idMap['countries']  = [44 => 12, ...]

Layer 2 Seeds:
  idMap['sites']      = [5 => 42]
  idMap['themes']     = [3 => 8]
  idMap['hooks']      = [10 => 24, 11 => 25, ...]
  idMap['modules']    = [7 => 19, 8 => 20, ...]

Layer 3 Seeds:
  idMap['categories'] = [100 => 500, 101 => 501, ...]
  idMap['module_props'] = [22 => 60, ...]

Layer 4 Reads (no new seeds):
  Uses idMap['categories'], idMap['langs'], idMap['module_props']
```

---

## Special Cases

### Context Entities (Match by Code)

For `langs`, `platforms`, etc., the map is seeded by matching on a natural key, not by inserting:

```php
// If lang with iso_code 'en' exists in target:
$existingLang = DB::table('langs')->where('iso_code', $sourceLang['iso_code'])->first();
if ($existingLang) {
    // Don't insert — just map the IDs
    $this->service->addToMap('langs', $sourceLang['id'], $existingLang->id);
} else {
    $newId = DB::table('langs')->insertGetId($sourceLang);
    $this->service->addToMap('langs', $sourceLang['id'], $newId);
}
```

### Category `parent_id`

Categories require a two-pass strategy for hierarchies:

1. **First Pass:** Insert all top-level categories (`parent_id = null`) and build the map.
2. **Second Pass:** Update `parent_id` fields using the map for child categories.

```php
// Pass 2: fix parent_id
foreach ($children as $row) {
    $newParentId = $this->service->getFromMap('categories', $row['parent_id']);
    DB::table('categories')->where('id', $newId)->update(['parent_id' => $newParentId]);
}
```

### Theme ID Fix (Post-Structural Pass)

The `sites` table holds `theme_id`, but themes are migrated *after* the site is inserted. After Layer 2 finishes:

```php
// Update site with the now-mapped theme_id
$newThemeId = $this->service->getFromMap('themes', $sourceSite['theme_id']);
DB::table('sites')->where('id', $newSiteId)->update(['theme_id' => $newThemeId]);
```

---

## Map Lifecycle

The map lives in PHP memory for the duration of the `ProcessMigration` job. It is not persisted to the database. This means:

- The map is reset for every new migration job.
- Re-running the migration for the same site creates a fresh map.
- If two migration jobs run simultaneously for different sites, each has its own independent service instance and map (no race conditions).
