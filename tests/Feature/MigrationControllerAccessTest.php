<?php

namespace HashtagCms\MigrationTool\Tests\Feature;

use HashtagCms\MigrationTool\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

class MigrationControllerAccessTest extends TestCase
{
    public function test_staff_user_can_open_migration_wizard(): void
    {
        $response = $this
            ->actingAs($this->makeUser('Staff'))
            ->get(route('migration.index'));

        $response->assertOk();
    }

    public function test_visitor_user_is_blocked_with_message_on_json_requests(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $response = $this
            ->actingAs($this->makeUser('Visitors'))
            ->postJson(route('migration.check-requirements'), [
                'site_id' => 1,
            ]);

        $response->assertForbidden();
        $response->assertJson([
            'success' => false,
            'message' => 'Visitors are not allowed to access the migration tool.',
        ]);
    }
}
