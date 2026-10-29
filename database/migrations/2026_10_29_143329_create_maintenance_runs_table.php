<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_runs', function (Blueprint $table): void {
            $table->string('task')->primary();
            $table->timestamp('finished_at');
            $table->unsignedBigInteger('rows_deleted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_runs');
    }
};
