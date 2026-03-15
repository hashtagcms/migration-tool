<?php

namespace HashtagCms\MigrationTool\Tests\Feature;

use HashtagCms\MigrationTool\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

class PreflightRequirementsEndpointTest extends TestCase
{
    public function test_preflight_endpoint_maps_mariadb_driver_to_pdo_mysql_label(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->actingAs($this->makeUser('Staff'));

        $response = $this
            ->withSession([
                'migration_source_db' => [
                    'driver' => 'mariadb',
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'nonexistent_db_for_test',
                    'username' => 'root',
                    'password' => null,
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix' => '',
                ],
            ])
            ->postJson(route('migration.check-requirements'), [
                'site_id' => 1,
                'copy_media' => false,
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $checks = $response->json('checks', []);

        $pdoCheck = collect($checks)->first(function (array $check) {
            return ($check['label'] ?? '') === 'PDO Driver: pdo_mysql';
        });

        $this->assertNotNull($pdoCheck);
        $this->assertArrayHasKey('status', $pdoCheck);
    }
}
