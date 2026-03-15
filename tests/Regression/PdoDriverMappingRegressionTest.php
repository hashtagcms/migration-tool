<?php

namespace HashtagCms\MigrationTool\Tests\Regression;

use HashtagCms\MigrationTool\Http\Controllers\MigrationController;
use HashtagCms\MigrationTool\Tests\TestCase;
use ReflectionMethod;

class PdoDriverMappingRegressionTest extends TestCase
{
    public function test_mariadb_driver_maps_to_pdo_mysql(): void
    {
        $controller = new MigrationController();
        $method = new ReflectionMethod(MigrationController::class, 'pdoExtensionForDriver');
        $method->setAccessible(true);

        $this->assertSame('pdo_mysql', $method->invoke($controller, 'mariadb'));
        $this->assertSame('pdo_mysql', $method->invoke($controller, 'mysql'));
        $this->assertSame('pdo_pgsql', $method->invoke($controller, 'pgsql'));
        $this->assertSame('pdo_sqlite', $method->invoke($controller, 'sqlite'));
    }
}
