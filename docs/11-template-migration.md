# Template & View Migration

The HashtagCMS Migration Tool includes a standalone engine for migrating your custom presentations layers (Themes and Module Blade views).

## 🎛️ Features
- **Standalone Execution**: Sync files without running a full database migration.
- **Smart Namespace Bridge**: Automatically refactors legacy namespaces during transfer.
- **Site-Scoped**: Detects active themes for a specific site and only syncs relevant directories.
- **Recursive Sync**: Handles deep directory nested structures.

## 🛠️ How it Works
When a template migration is triggered (via UI or CLI), the `TemplateMigrationService` performs the following logic:

1.  **Discovery**: Queries the source database to find the `directory` names of all themes assigned to the selected `site_id`.
2.  **Path Resolution**: Look for these directories in the source installation:
    - `{source_root}/resources/views/themes/{theme_dir}`
    - `{source_root}/resources/views/{theme_dir}` (Legacy fallback)
3.  **Namespace Refactoring**: For every `.php` and `.blade.php` file copied, it performs a search/replace for older vendor names:
    - `MarghoobSuleman\HashtagCms` $\rightarrow$ `HashtagCms`
4.  **Target Injection**: Files are placed in the corresponding `resources/views` path in your current Laravel installation.

## ⌨️ CLI Usage
```bash
php artisan cms:migrate-templates {site_id} "/absolute/path/to/source"
```

## 🖥️ UI Usage
1.  Navigate to the **Migration Wizard**.
2.  During the **Final Review** step, enter your **Source Installation Path**.
3.  Click **Sync Now** under the **Template Sync** card.
4.  View real-time results for each theme detected.

> [!IMPORTANT]
> Template migration requires local filesystem access to the source project root. If you are migrating across servers, ensure you have mounted the source drive or copied the source files to a temporary directory on the target server.
