<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scope trace uniqueness to a project and add the stub marker.
     *
     * A correlation id only identifies a trace within the project that ingested it, so two projects may legitimately
     * carry the same id. `is_stub` flags rows created from child records (queries, exceptions, logs, ...) that arrived
     * before their request record; the request record later fills the row in and clears the flag.
     */
    public function up(): void
    {
        Schema::table('traces', function (Blueprint $table): void {
            $table->dropUnique(['correlation_id']);
            $table->unique(['project_id', 'correlation_id']);
            $table->boolean('is_stub')->default(false);
        });
    }

    /**
     * Restore the global unique index.
     *
     * Stub rows have no request record behind them, so they are removed first. Rolling back is refused, before any
     * schema change, when two projects share a correlation id, because the global unique index could not be rebuilt.
     */
    public function down(): void
    {
        $sharesCorrelationIdAcrossProjects = DB::table('traces')
            ->where('is_stub', false)
            ->groupBy('correlation_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($sharesCorrelationIdAcrossProjects) {
            throw new RuntimeException('Cannot roll back: traces from different projects share a correlation_id, so a global unique index cannot be restored.');
        }

        DB::table('traces')->where('is_stub', true)->delete();

        Schema::table('traces', function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'correlation_id']);
            $table->dropColumn('is_stub');
            $table->unique('correlation_id');
        });
    }
};
