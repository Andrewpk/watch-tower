<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fill `db_time_ms` for traces stored before it was summed from their queries. Only traces still at 0 that have
     * query rows are touched, so a value sent by a legacy client is kept. Queries stored before this change have
     * whole-millisecond durations, so the backfilled sums are approximate.
     */
    public function up(): void
    {
        DB::table('traces')
            ->select('id')
            ->where('db_time_ms', 0)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('trace_queries')->whereColumn('trace_queries.trace_id', 'traces.id'))
            ->chunkById(1000, function ($traces): void {
                DB::table('traces')
                    ->whereIn('id', $traces->pluck('id')->all())
                    ->update([
                        'db_time_ms' => DB::raw('(select coalesce(sum(trace_queries.duration_ms), 0) from trace_queries where trace_queries.trace_id = traces.id)'),
                    ]);
            }, 'id');
    }

    /**
     * The sums cannot be told apart from values written by the application, so there is nothing to undo.
     */
    public function down(): void {}
};
