<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trace_queries', function (Blueprint $table): void {
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('connection_type', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trace_queries', function (Blueprint $table): void {
            $table->dropColumn(['file', 'line', 'connection_type']);
        });
    }
};
