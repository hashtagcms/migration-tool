# 06 — Conflict Resolution

When migrating data from a source to a target database, collisions are inevitable — especially for entities that use human-readable unique keys like domains, aliases, or slugs.

---

## Domain Conflicts (Sites)

The most critical conflict is a **site domain collision** — when the source site's domain already exists in the target database.

### Strategies

Configure via the `conflict_strategy` option in the wizard UI or in the `run-migration` API call.

---

#### `terminate` (Default — Safest)

```
Source domain: "example.com"
Target has:   "example.com" ← already exists
Result:       Exception thrown, transaction rolled back, no data written.
```

Use this when you want to be sure your data is never accidentally overwritten. The migration will fail immediately with a descriptive error message.

---

#### `rename` (Non-Destructive Copy)

```
Source domain: "example.com"
Target has:   "example.com"
Result:       New site created with domain "example.com-migrated-1740459000"
              Site name appended with " (Migrated)"
```

This is the safest additive strategy. Both the original and migrated site coexist. You can review the migrated site, then manually update the domain when ready.

**Resulting record:**

```php
[
    'domain' => 'example.com-migrated-1740459000',
    'name'   => 'My Site (Migrated)',
    // ...all other site fields
]
```

---

#### `overwrite` (DANGEROUS)

```
Source domain: "example.com"
Target has:   "example.com" ← exists (id=5)
Result:       Target record (id=5) is updated with all source values.
```

> ⚠️ **Use with extreme caution.** This will replace the existing site's configuration with source data.

---

## Context Entity Conflicts (Langs, Platforms, etc.)

Context entities (Layer 1) are **never duplicated**. They are matched by their natural key:

| Table | Match Key | Action if Found |
|:------|:----------|:----------------|
| `langs` | `iso_code` | Existing ID is used in the map. |
| `platforms` | `alias` | Existing ID is used in the map. |
| `countries` | `iso_code` | Existing ID is used in the map. |
| `currencies` | `code` | Existing ID is used in the map. |
| `zones` | `alias` | Existing ID is used in the map. |

If the entity does not exist in the target, a new one is created and its ID is mapped.

---

## Structural Entity Conflicts (Themes, Hooks, Modules)

Structural entities are matched by `alias`:

- If an entity with the same `alias` and `site_id` (the new mapped one) already exists on the target, the existing target ID is mapped and no duplicate is created.
- If it does not exist, a new record is inserted.

---

## Pivot Table Conflicts

All pivot tables (`category_site`, `module_site`, `lang_site`, etc.) use `updateOrInsert` with the composite unique key. This means:

- **Re-running a migration is safe.** No duplicate pivot rows will be created.
- If a pivot row already exists, it is **updated** with the latest values from the source.

---

## Translation Conflicts (Content Layer)

Translation rows (`category_langs`, `static_module_contents`) are upserted using `updateOrInsert` keyed by `(entity_id, lang_id)`. This ensures:

- Running a migration twice does not duplicate translation rows.
- Updated source translations will overwrite stale target translations on re-runs.

---

## Conflict Summary Table

| Entity Type | Conflict Key | Behaviour |
|:------------|:-------------|:----------|
| `sites` | `domain` | Configurable: Terminate / Overwrite / Rename |
| `themes` | `alias` + `new_site_id` | Match existing, avoid dup |
| `hooks` | `alias` + `new_site_id` | Match existing, avoid dup |
| `modules` | `alias` + `new_site_id` | Match existing, avoid dup |
| `langs` | `iso_code` | Always match; never duplicate |
| `platforms` | `alias` | Always match; never duplicate |
| `categories` | N/A | New records per migration |
| Pivot Tables | Composite PK columns | `updateOrInsert` |
| Translation Tables | `(entity_id, lang_id)` | `updateOrInsert` |
