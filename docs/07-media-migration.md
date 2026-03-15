# 07 — Media Migration

The `SyncMediaStep` handles the physical transfer of site assets from the source server's filesystem to the target server's filesystem.

---

## Activation

Media migration is **opt-in** and only runs when both conditions are met:

1. The `copy_media` option is set to `true` in the migration request.
2. A valid `source_root_path` is provided (an absolute path to the source CMS's public directory).

---

## How It Works

```
source_root_path/
├── assets/          ← scanned recursively
│   ├── images/
│   └── uploads/
│       └── 2024/
│           └── 01/
│               └── banner.jpg
└── storage/         ← scanned recursively
    └── thumbs/
```

The step iterates over two top-level directories — `assets/` and `storage/` — and copies every file to the equivalent path under `public_path()` on the target server.

---

## Implementation Details

### Memory-Safe Streaming

The step uses **Symfony Finder** to stream files one-by-one, rather than loading all file objects into an array at once:

```php
$finder = new \Symfony\Component\Finder\Finder();
$finder->files()->in($src);

foreach ($finder as $file) {
    $relativePath = $file->getRelativePathname();
    // ...
}
```

This ensures the step can handle asset libraries of **any size** — including terabytes of media — without exhausting PHP memory.

### Path Integrity

The full relative path is preserved. A file at:

```
/source/public/assets/uploads/2024/01/banner.jpg
```

Will be copied to:

```
/target/public/assets/uploads/2024/01/banner.jpg
```

### Non-Destructive

Files are only copied if they do **not** already exist at the target path. Existing files are never overwritten. This makes the media step safe to re-run.

---

## Configuration

| Option | Type | Required | Description |
|:-------|:-----|:---------|:------------|
| `copy_media` | `boolean` | No | Set to `true` to enable media sync |
| `source_root_path` | `string` | If copy_media=true | Absolute path to the source `public/` directory |

**Example:**

```json
{
  "copy_media": true,
  "source_root_path": "/var/www/source-hashtagcms/public"
}
```

---

## Limitations & Considerations

| Scenario | Current Behaviour |
|:---------|:-----------------|
| Source on remote server | ❌ Not supported. Source path must be locally accessible. |
| Target directory missing | ✅ Auto-created with `0755` permissions. |
| File already exists at target | Skipped (non-destructive). |
| Symbolic links | Not followed (Symfony Finder default). |
| Very large files (>2GB) | ✅ No memory pressure (streamed). |

---

## Planned Enhancements

- **S3 / Object Storage Support:** Transfer assets directly between two S3-compatible buckets.
- **SCP / SFTP Transfer:** For remote source server media when local mount is not available.
- **Content URL Rewriting:** After file transfer, scan `static_module_contents.body` for `src="/assets/..."` references and update them if the target uses a different base URL.
- **Overwrite Mode:** Option to overwrite existing files at target when explicitly enabled.

---

## Result Output

After the step completes, the job log includes:

```json
{
  "Media & Assets Synchronization": {
    "assets": "Copied 142 files/folders",
    "storage": "Copied 38 files/folders"
  }
}
```
