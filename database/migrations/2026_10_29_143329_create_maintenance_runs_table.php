<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_runs', function (Blueprint $table): void {
            $table->string('task')->primary();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->unsignedBigInteger('rows_deleted')->nullable();
        });

        // Baseline for the retention prune: an install that already has projects
        // has no recorded run yet, and the overdue warning must not fire until
        // a full day has passed without one. `rows_deleted` stays null until a
        // real run replaces this row.
        DB::table('maintenance_runs')->insert([
            'task' => 'watch:prune',
            'started_at' => now(),
            'finished_at' => now(),
            'rows_deleted' => null,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_runs');
    }
};
