<?php

namespace HashtagCms\MigrationTool\Tests\Regression;

use HashtagCms\MigrationTool\Tests\TestCase;

class LegacyTenantsPlatformMappingRegressionTest extends TestCase
{
    public function test_abstract_step_contains_legacy_tenants_table_mapping_logic(): void
    {
        $filePath = __DIR__ . '/../../src/Steps/AbstractMigrationStep.php';
        $contents = file_get_contents($filePath);

        $this->assertIsString($contents);
        $this->assertStringContainsString('if ($this->isLegacyTenants && $table === \'platforms\')', $contents);
        $this->assertStringContainsString('return \'tenants\';', $contents);
    }

    public function test_abstract_step_contains_tenant_id_to_platform_id_column_mapping_logic(): void
    {
        $filePath = __DIR__ . '/../../src/Steps/AbstractMigrationStep.php';
        $contents = file_get_contents($filePath);

        $this->assertIsString($contents);
        $this->assertStringContainsString('array_key_exists(\'tenant_id\', $data)', $contents);
        $this->assertStringContainsString('$data[\'platform_id\'] = $data[\'tenant_id\'];', $contents);
        $this->assertStringContainsString('unset($data[\'tenant_id\']);', $contents);
        $this->assertStringContainsString('return (int) ($row[\'platform_id\'] ?? $row[\'tenant_id\'] ?? 0);', $contents);
    }
}
