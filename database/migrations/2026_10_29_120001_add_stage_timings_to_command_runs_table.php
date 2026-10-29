<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('command_runs', function (Blueprint $table): void {
            $table->decimal('bootstrap_ms', 12, 3)->nullable();
            $table->decimal('action_ms', 12, 3)->nullable();
            $table->decimal('terminating_ms', 12, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('command_runs', function (Blueprint $table): void {
            $table->dropColumn(['bootstrap_ms', 'action_ms', 'terminating_ms']);
        });
    }
};
