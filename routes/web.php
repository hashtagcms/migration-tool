<?php

use Illuminate\Support\Facades\Route;
use HashtagCms\MigrationTool\Http\Controllers\MigrationController;

Route::group(['prefix' => config('migration-tool.prefix', 'cms-migration'), 'middleware' => config('migration-tool.middleware', ['web'])], function () {
    Route::get('/', [MigrationController::class, 'index'])->name('migration.index');
    Route::post('/test-connection', [MigrationController::class, 'testConnection'])->name('migration.test-connection');
    Route::post('/analyze', [MigrationController::class, 'analyze'])->name('migration.analyze');
    Route::post('/site-details', [MigrationController::class, 'getSiteDetails'])->name('migration.site-details');
    Route::post('/run-migration', [MigrationController::class, 'runMigration'])->name('migration.run-migration');
    Route::post('/migrate-templates', [MigrationController::class, 'migrateTemplates'])->name('migration.migrate-templates');
    Route::post('/check-requirements', [MigrationController::class, 'checkRequirements'])->name('migration.check-requirements');
    Route::get('/check-progress/{job_id}', [MigrationController::class, 'checkProgress'])->name('migration.check-progress');
});
