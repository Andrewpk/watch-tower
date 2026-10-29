<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('end_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();
            $table->string('identifier');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['project_id', 'identifier']);
            $table->index(['project_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('end_users');
    }
};
