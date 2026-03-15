<?php

namespace HashtagCms\MigrationTool\Tests;

use HashtagCms\MigrationTool\MigrationToolServiceProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('migration-tool.middleware', ['web']);
    }

    protected function getPackageProviders($app): array
    {
        return [
            MigrationToolServiceProvider::class,
        ];
    }

    protected function makeUser(string $userType = 'Staff'): Authenticatable
    {
        $user = new class extends AuthenticatableUser {
            protected $guarded = [];
            public $timestamps = false;
        };

        $user->forceFill([
            'id' => 1,
            'user_type' => $userType,
        ]);

        return $user;
    }
}
