<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_task_runs', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable();
            $table->unsignedSmallInteger('repeat_seconds')->nullable();
            $table->boolean('without_overlapping')->nullable();
            $table->boolean('on_one_server')->nullable();
            $table->boolean('run_in_background')->nullable();
            $table->boolean('even_in_maintenance_mode')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_task_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'timezone',
                'repeat_seconds',
                'without_overlapping',
                'on_one_server',
                'run_in_background',
                'even_in_maintenance_mode',
            ]);
        });
    }
};
