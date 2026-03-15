# Legacy Database Support & Dependency Audit

The migration tool is designed to bridge the gap between HashtagCMS V1 (Legacy) and its modern modular architecture.

## 🔄 Legacy Database Terminology
HashtagCMS V2 introduced a shift in core terminology. Key changes handled by the tool include:

| Legacy Entity (V1) | Modern Entity (V2+) |
| :--- | :--- |
| `tenants` table | `platforms` table |
| `tenant_site` table | `platform_site` table |
| `tenant_id` column | `platform_id` column |

### How the Tool Handles This
The `AbstractMigrationStep` includes a **Terminology Layer**. When you connect to a source database:
1.  **Detection**: The tool checks if the `platforms` table exists. If only `tenants` is found, it switches to **Legacy Mode**.
2.  **Transparent Mapping**: All subsequent SQL queries are automatically translated. For example, when a step requests the `platforms` table, the tool fetches from `tenants` instead.
3.  **Data Normalization**: During data transfer, all `tenant_id` values are renamed to `platform_id` before being inserted into the target database.

---

## 📦 Smart Dependency Audit
Migrating a database is only half the battle. If your source project relied on third-party Laravel packages that are missing in the target installation, the site will fail to load.

### The Audit Process
During the **Source Analysis** phase, the tool reads the `composer.json` from the source root and compares it with your current `composer.json`.

1.  **Exclusions**: Standard packages (Laravel core, HashtagCMS core, PHP) are ignored.
2.  **Custom packages**: Any unique third-party packages found in the source but missing in the target are flagged.
3.  **Actionable Feedback**: The UI provides a ready-made command you can copy:
    ```bash
    composer require vendor/package-a vendor/package-b
    ```

### Why this is critical
HashtagCMS often uses "Hooks" and "Module Properties" that might trigger logic from other packages. This audit ensures your target environment is functionally identical to the source.
