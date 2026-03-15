<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_migration_logs', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->string('job_id')->index();
            $blueprint->integer('site_id');
            $blueprint->string('status')->default('pending'); // pending, running, completed, failed
            $blueprint->integer('progress')->default(0);
            $blueprint->text('message')->nullable();
            $blueprint->json('results')->nullable();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_migration_logs');
    }
};
