<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traces', function (Blueprint $table): void {
            $table->string('route_name', 255)->nullable();
            $table->string('route_methods', 100)->nullable();
            $table->string('route_domain', 255)->nullable();
            $table->string('route_action', 255)->nullable();
            $table->unsignedInteger('request_size_bytes')->nullable();
            $table->unsignedInteger('response_size_bytes')->nullable();
            $table->decimal('bootstrap_ms', 12, 3)->nullable();
            $table->decimal('before_middleware_ms', 12, 3)->nullable();
            $table->decimal('action_ms', 12, 3)->nullable();
            $table->decimal('render_ms', 12, 3)->nullable();
            $table->decimal('after_middleware_ms', 12, 3)->nullable();
            $table->decimal('sending_ms', 12, 3)->nullable();
            $table->decimal('terminating_ms', 12, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('traces', function (Blueprint $table): void {
            $table->dropColumn([
                'route_name',
                'route_methods',
                'route_domain',
                'route_action',
                'request_size_bytes',
                'response_size_bytes',
                'bootstrap_ms',
                'before_middleware_ms',
                'action_ms',
                'render_ms',
                'after_middleware_ms',
                'sending_ms',
                'terminating_ms',
            ]);
        });
    }
};
