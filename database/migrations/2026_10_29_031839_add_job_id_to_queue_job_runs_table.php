<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_job_runs', function (Blueprint $table): void {
            $table->string('job_id', 64)->nullable()->after('trace_id');
            $table->unique(['project_id', 'job_id'], 'queue_job_runs_project_job_unique');
        });
    }

    public function down(): void
    {
        Schema::table('queue_job_runs', function (Blueprint $table): void {
            $table->dropUnique('queue_job_runs_project_job_unique');
            $table->dropColumn('job_id');
        });
    }
};
