<?php

namespace HashtagCms\MigrationTool\Tests\Regression;

use HashtagCms\MigrationTool\Tests\TestCase;

class AutoTableProvisioningRegressionTest extends TestCase
{
    public function test_controller_contains_auto_table_provisioning_flow_before_dispatch(): void
    {
        $filePath = __DIR__ . '/../../src/Http/Controllers/MigrationController.php';
        $contents = file_get_contents($filePath);

        $this->assertIsString($contents);
        $this->assertStringContainsString('autoProvisionMissingTargetTables', $contents);
        $this->assertStringContainsString('$tableProvision = $this->autoProvisionMissingTargetTables($sourceConnection);', $contents);
        $this->assertStringContainsString('\'auto_created_tables\' => $tableProvision[\'created\']', $contents);
        $this->assertStringContainsString('\'missing_tables\' => $tableProvision[\'missing\']', $contents);
    }

    public function test_controller_contains_permission_denied_message_for_missing_table_creation(): void
    {
        $filePath = __DIR__ . '/../../src/Http/Controllers/MigrationController.php';
        $contents = file_get_contents($filePath);

        $this->assertIsString($contents);
        $this->assertStringContainsString('Please create these tables first in target database and then run this migration tool again', $contents);
        $this->assertStringContainsString('insufficient CREATE TABLE permission', $contents);
    }
}
