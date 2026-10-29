<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traces', function (Blueprint $table): void {
            $table->unsignedInteger('lazy_loads')->nullable();
            $table->unsignedInteger('hydrated_models')->nullable();
        });

        Schema::table('command_runs', function (Blueprint $table): void {
            $table->unsignedInteger('lazy_loads')->nullable();
            $table->unsignedInteger('hydrated_models')->nullable();
        });

        Schema::table('queue_job_runs', function (Blueprint $table): void {
            $table->unsignedInteger('lazy_loads')->nullable();
            $table->unsignedInteger('hydrated_models')->nullable();
        });

        Schema::table('scheduled_task_runs', function (Blueprint $table): void {
            $table->unsignedInteger('lazy_loads')->nullable();
            $table->unsignedInteger('hydrated_models')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('traces', function (Blueprint $table): void {
            $table->dropColumn(['lazy_loads', 'hydrated_models']);
        });

        Schema::table('command_runs', function (Blueprint $table): void {
            $table->dropColumn(['lazy_loads', 'hydrated_models']);
        });

        Schema::table('queue_job_runs', function (Blueprint $table): void {
            $table->dropColumn(['lazy_loads', 'hydrated_models']);
        });

        Schema::table('scheduled_task_runs', function (Blueprint $table): void {
            $table->dropColumn(['lazy_loads', 'hydrated_models']);
        });
    }
};
