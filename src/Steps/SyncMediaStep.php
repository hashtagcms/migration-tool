<?php

namespace HashtagCms\MigrationTool\Steps;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SyncMediaStep extends AbstractMigrationStep
{
    public function getName(): string
    {
        return "Media & Assets Synchronization";
    }

    public function execute(int $siteId, array $config): array
    {
        if (!($config['copy_media'] ?? false)) {
            return ['status' => 'Skipped by user configuration'];
        }

        $sourceRoot = $config['source_root_path'] ?? null;
        $targetRoot = public_path(); // Usually public_path() for assets

        if (!$sourceRoot || !File::isDirectory($sourceRoot)) {
            return ['status' => 'Skipped: Source root path not found or invalid.', 'path' => $sourceRoot];
        }

        $results = [];
        $folders = ['assets', 'storage']; // Core HashtagCms asset folders

        foreach ($folders as $folder) {
            $src = $sourceRoot . DIRECTORY_SEPARATOR . $folder;
            $dest = $targetRoot . DIRECTORY_SEPARATOR . $folder;

            if (File::isDirectory($src)) {
                $count = $this->copyDirectory($src, $dest);
                $results[$folder] = "Copied $count files/folders";
            }
        }

        return $results;
    }

    /**
     * Recursive copy with basic conflict handling
     */
    protected function copyDirectory($src, $dest): int
    {
        if (!File::isDirectory($dest)) {
            File::makeDirectory($dest, 0755, true);
        }

        $count = 0;
        $finder = new \Symfony\Component\Finder\Finder();
        // Stream files instead of loading all at once
        $finder->files()->in($src);

        foreach ($finder as $file) {
            $relativePath = $file->getRelativePathname();
            $targetPath = $dest . DIRECTORY_SEPARATOR . $relativePath;
            
            $targetDir = dirname($targetPath);
            if (!File::isDirectory($targetDir)) {
                File::makeDirectory($targetDir, 0755, true);
            }

            // Copy if file doesn't exist OR source is newer than target.
            $shouldCopy = !File::exists($targetPath)
                || filemtime($file->getRealPath()) > filemtime($targetPath);

            if ($shouldCopy) {
                File::copy($file->getRealPath(), $targetPath);
                $count++;
            }
        }

        return $count;
    }
}
