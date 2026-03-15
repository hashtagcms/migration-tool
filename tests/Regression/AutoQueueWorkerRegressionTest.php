<?php

namespace HashtagCms\MigrationTool\Tests\Regression;

use HashtagCms\MigrationTool\Tests\TestCase;

class AutoQueueWorkerRegressionTest extends TestCase
{
    public function test_controller_has_auto_queue_worker_once_trigger_after_dispatch(): void
    {
        $filePath = __DIR__ . '/../../src/Http/Controllers/MigrationController.php';
        $contents = file_get_contents($filePath);

        $this->assertIsString($contents);
        $this->assertStringContainsString('protected function autoStartQueueWorkerOnce(): void', $contents);
        $this->assertStringContainsString("config('migration-tool.auto_queue_work_once', false)", $contents);
        $this->assertStringContainsString('$this->autoStartQueueWorkerOnce();', $contents);
        $this->assertStringContainsString("'queue:work'", $contents);
        $this->assertStringContainsString("'--once'", $contents);
    }
}
