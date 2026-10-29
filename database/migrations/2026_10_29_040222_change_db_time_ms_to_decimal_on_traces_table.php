<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `db_time_ms` becomes the sum of the (fractional) query durations, so it needs the same `decimal(12,3)` as
     * `trace_queries.duration_ms`. Changing the column rebuilds the `traces` table (a table copy on MySQL).
     */
    public function up(): void
    {
        Schema::table('traces', function (Blueprint $table): void {
            $table->decimal('db_time_ms', 12, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('traces', function (Blueprint $table): void {
            $table->unsignedInteger('db_time_ms')->default(0)->change();
        });
    }
};
