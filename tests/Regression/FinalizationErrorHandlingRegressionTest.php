<?php

namespace HashtagCms\MigrationTool\Tests\Regression;

use HashtagCms\MigrationTool\Tests\TestCase;

class FinalizationErrorHandlingRegressionTest extends TestCase
{
    public function test_site_migration_service_uses_safe_command_lookup_and_throwable_catch(): void
    {
        $filePath = __DIR__ . '/../../src/Services/SiteMigrationService.php';
        $contents = file_get_contents($filePath);

        $this->assertIsString($contents);
        $this->assertStringContainsString('Artisan::all();', $contents);
        $this->assertStringContainsString('array_key_exists(\'htcms:cache-clear\'', $contents);
        $this->assertStringContainsString('catch (\\Throwable $e)', $contents);
    }

    public function test_process_migration_job_catches_throwable_and_updates_log(): void
    {
        $filePath = __DIR__ . '/../../src/Jobs/ProcessMigration.php';
        $contents = file_get_contents($filePath);

        $this->assertIsString($contents);
        $this->assertStringContainsString('catch (\\Throwable $e)', $contents);
        $this->assertStringContainsString("'status'  => 'failed'", $contents);
        $this->assertStringContainsString('\'message\' => $e->getMessage()', $contents);
    }
}
