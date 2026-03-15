# HashtagCMS Migration Tool

[![PHP Version](https://img.shields.io/badge/PHP-%5E8.2-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-10.x%2B-orange)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Package](https://img.shields.io/badge/package-hashtagcms%2Fmigration--tool-purple)](https://github.com/hashtagcms/migration-tool)

A production-grade, asynchronous database, media, and template migration tool for [HashtagCMS](https://hashtagcms.org). Move a complete CMS site — including all data layers, translations, media files, and relational graph — from a **source** database to a **target** installation with a beautiful guided wizard UI.

---

## ✨ Feature Highlights

| Feature | Status |
|:---|:---|
| 7-Step ETL Pipeline (Context → Structural → Glue → Content → Media → Validation) | ✅ |
| **Legacy terminology support** (Auto-detect `tenants` vs `platforms`) | ✅ |
| **Smart Template Migration** (with automated namespace refactoring) | ✅ |
| **Pre-migration Package Audit** (Detect missing composer dependencies) | ✅ |
| Artisan CLI Interface (`cms:migrate-site` & `cms:migrate-templates`) | ✅ |
| ID Mapping Engine (old_id → new_id across connections) | ✅ |
| Asynchronous Background Job Processing & Progress Monitoring | ✅ |
| **Scale-ready Performance** (Chunked data processing for large sites) | ✅ |
| Conflict Resolution: Terminate / Overwrite / Rename | ✅ |
| Media & Asset File Synchronization | ✅ |
| Automated Post-Migration Integrity Validation | ✅ |

---

## 📋 Requirements

- PHP `^8.2`
- Laravel `10.x` or `11.x`
- HashtagCMS core package `hashtagcms/hashtagcms`
- A queue driver configured (e.g., `database`, `redis`, `sqs`)

---

## 🚀 Installation

1. **Require the package via Composer:**
```bash
composer require hashtagcms/migration-tool
```

2. **Publish configuration and run migrations:**
```bash
php artisan vendor:publish --tag="migration-tool-config"
php artisan migrate
```

3. **Run your queue worker:**
```bash
php artisan queue:work
```

---

## 💻 Usage

### 📊 Migration Wizard (UI)
Open the Migration Wizard in your browser for a guided experience:
`https://your-app.com/cms-migration`

*   **Discovery Phase**: Automatically identifies site statistics and **warns about missing composer packages** found in the source project.
*   **Template Sync**: Independently migrate your theme and module blade files with real-time namespace refactoring (`MarghoobSuleman\HashtagCms` → `HashtagCms`).

### ⌨️ Artisan Commands (CLI)

#### 1. Full Data Migration
```bash
php artisan cms:migrate-site {site_id} \
    --database="source_db" \
    --username="root" \
    --password="password" \
    --media --source-root="/var/www/old-site"
```

#### 2. Standalone Template Migration
```bash
php artisan cms:migrate-templates {site_id} "/absolute/path/to/source"
```

---

## 🧠 Smart Migration Features

### 🔄 Legacy Compatibility
The tool automatically detects if the source database uses the older `tenants` and `tenant_id` terminology. It transparently maps these to the modern `platforms` and `platform_id` structure in HashtagCMS V2+.

### 📝 Code Refactoring
During template migration, the tool processes all `.php` and `.blade.php` files. It automatically bridges the gap between legacy and modern namespaces:
- `MarghoobSuleman\HashtagCms` $\rightarrow$ `HashtagCms`

### 📦 Dependency Audit
Before starting, the tool reads the `composer.json` of your legacy installation and compares it to your new one. It will provide a ready-to-use `composer require` command for any missing third-party packages.

---

## 📖 Documentation

| # | Document | Description |
|:--|:---------|:------------|
| 01 | [Architecture Overview](docs/01-architecture.md) | ETL design, layers, and class structure |
| 02 | [Installation & Configuration](docs/02-installation.md) | Full setup guide |
| 03 | [The ETL Pipeline](docs/03-etl-pipeline.md) | Details on all 7 sync steps |
| 04 | [Migration Wizard UI](docs/04-wizard-ui.md) | How the frontend wizard works |
| 05 | [API Reference](docs/05-api-reference.md) | All backend endpoints |
| 06 | [Conflict Resolution](docs/06-conflict-resolution.md) | Domain & data conflict strategies |
| 07 | [Media Migration](docs/07-media-migration.md) | File sync and path handling |
| 08 | [Queue & Async Jobs](docs/08-queue-async.md) | Background job and progress tracking |
| 09 | [ID Mapping Engine](docs/09-id-mapping-engine.md) | How foreign keys are re-mapped |
| 10 | [Security](docs/10-security.md) | CSRF, session, and connection safety |
| 11 | [Template Migration](docs/11-template-migration.md) | Standalone file/namespace syncing |
| 12 | [Legacy & Dependency Support](docs/12-legacy-support.md) | Terminology and package audit |
| 13 | [Regression Smoke Checks](docs/13-regression-smoke-checks.md) | Validate recent migration bug fixes |

---

## 🤝 Contributing
See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

---

## 📄 License
MIT © [Marghoob Suleman](mailto:marghoobsuleman@gmail.com)
