<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `db_time_ms` becomes the sum of the (fractional) query durations. A single query is capped at the
     * `decimal(12,3)` range of `trace_queries.duration_ms`; the sum gets `decimal(15,3)` (about 31 years), so a long-lived trace such as a daemon command can never overflow it. Changing the column
     * rebuilds the `traces` table (a table copy on MySQL).
     */
    public function up(): void
    {
        Schema::table('traces', function (Blueprint $table): void {
            $table->decimal('db_time_ms', 15, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('traces', function (Blueprint $table): void {
            $table->unsignedInteger('db_time_ms')->default(0)->change();
        });
    }
};
