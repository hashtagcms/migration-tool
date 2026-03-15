<?php

namespace HashtagCms\MigrationTool\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

class TemplateMigrationService
{
    /**
     * Map of legacy namespaces/strings to replace with modern ones.
     */
    protected array $v1ToV2Replacements = [
        'MarghoobSuleman\HashtagCms' => 'HashtagCms',
    ];

    /**
     * Migrate template/view files for a specific site.
     *
     * @param string $sourceRoot The absolute path to the source Laravel installation root.
     * @param int $siteId The source site ID.
     * @return array Summary of the migration.
     */
    public function migrate(string $sourceRoot, int $siteId): array
    {
        $sourceConnection = DB::connection('temp_source_connection');
        
        // Fetch themes for this site to get their 'directory' names
        $themes = $sourceConnection->table('themes')
            ->where('site_id', $siteId)
            ->get(['alias', 'directory']);

        if ($themes->isEmpty()) {
            return [
                'success' => true,
                'message' => 'No themes found for this site. No files were copied.',
                'details' => []
            ];
        }

        $results = [];
        $totalFiles = 0;

        foreach ($themes as $theme) {
            $dir = $theme->directory;
            if (empty($dir)) continue;

            $themeResults = [
                'theme' => $theme->alias,
                'directory' => $dir,
                'paths' => []
            ];

            // 1. Copy resources/views/themes/{directory}
            $metaPath = "resources/views/themes/{$dir}";
            $metaStats = $this->copyDirectory($sourceRoot, $metaPath);
            $themeResults['paths'][$metaPath] = $metaStats;
            $totalFiles += $metaStats['copied'];

            // 2. Copy resources/views/{directory} (Modules/Templates)
            $viewPath = "resources/views/{$dir}";
            $viewStats = $this->copyDirectory($sourceRoot, $viewPath);
            $themeResults['paths'][$viewPath] = $viewStats;
            $totalFiles += $viewStats['copied'];

            $results[] = $themeResults;
        }

        return [
            'success' => true,
            'message' => "Successfully copied $totalFiles template files for " . $themes->count() . " themes.",
            'details' => $results
        ];
    }

    /**
     * Copy a directory from source to target.
     */
    protected function copyDirectory(string $sourceRoot, string $relativePath): array
    {
        $fullSourcePath = rtrim($sourceRoot, '/') . DIRECTORY_SEPARATOR . $relativePath;
        $fullTargetPath = base_path($relativePath);

        if (!File::exists($fullSourcePath)) {
            return ['status' => 'skipped', 'message' => 'Source directory not found', 'copied' => 0];
        }

        if (!File::isDirectory($fullSourcePath)) {
            return ['status' => 'error', 'message' => 'Source path is not a directory', 'copied' => 0];
        }

        // Ensure target directory exists
        if (!File::exists($fullTargetPath)) {
            File::makeDirectory($fullTargetPath, 0755, true);
        }

        $finder = new Finder();
        $finder->files()->in($fullSourcePath);

        $copiedCount = 0;
        foreach ($finder as $file) {
            $targetFile = $fullTargetPath . DIRECTORY_SEPARATOR . $file->getRelativePathname();
            
            // Ensure subdirectories in target exist
            $targetSubDir = dirname($targetFile);
            if (!File::exists($targetSubDir)) {
                File::makeDirectory($targetSubDir, 0755, true);
            }

            File::copy($file->getRealPath(), $targetFile);

            // POST-COPY: Perform smart replacement for PHP/Blade files.
            // Note: getExtension() returns 'php' for both regular .php and .blade.php files.
            if ($file->getExtension() === 'php') {
                $this->applySmartReplacements($targetFile);
            }
            $copiedCount++;
        }

        return [
            'status' => 'success',
            'copied' => $copiedCount
        ];
    }

    /**
     * Apply replacements to a file to handle namespace changes.
     */
    protected function applySmartReplacements(string $filePath): void
    {
        $content = File::get($filePath);
        $originalContent = $content;

        foreach ($this->v1ToV2Replacements as $old => $new) {
            $content = str_replace($old, $new, $content);
        }

        if ($content !== $originalContent) {
            File::put($filePath, $content);
        }
    }
}
