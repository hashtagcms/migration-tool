<?php

namespace HashtagCms\MigrationTool\Tests\Regression;

use HashtagCms\MigrationTool\Tests\TestCase;

class SyncContentCloneRegressionTest extends TestCase
{
    public function test_sync_content_step_does_not_use_invalid_clone_method_call(): void
    {
        $filePath = __DIR__ . '/../../src/Steps/SyncContentStep.php';
        $contents = file_get_contents($filePath);

        $this->assertIsString($contents);
        $this->assertStringNotContainsString('->clone(', $contents);
        $this->assertStringContainsString('(clone $query)->count();', $contents);
        $this->assertSame(3, substr_count($contents, '(clone $query)'));
    }
}
