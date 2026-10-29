<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('command_runs', function (Blueprint $table): void {
            $table->unsignedInteger('memory_peak_kb')->nullable();
        });

        Schema::table('queue_job_runs', function (Blueprint $table): void {
            $table->unsignedInteger('memory_peak_kb')->nullable();
        });

        Schema::table('scheduled_task_runs', function (Blueprint $table): void {
            $table->unsignedInteger('memory_peak_kb')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('command_runs', function (Blueprint $table): void {
            $table->dropColumn('memory_peak_kb');
        });

        Schema::table('queue_job_runs', function (Blueprint $table): void {
            $table->dropColumn('memory_peak_kb');
        });

        Schema::table('scheduled_task_runs', function (Blueprint $table): void {
            $table->dropColumn('memory_peak_kb');
        });
    }
};
