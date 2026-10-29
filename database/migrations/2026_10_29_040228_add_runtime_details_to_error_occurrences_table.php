<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_occurrences', function (Blueprint $table) {
            $table->string('language_version', 50)->nullable()->after('release_version');
            $table->string('framework_version', 50)->nullable()->after('language_version');
            $table->string('hostname')->nullable()->after('framework_version');
        });
    }

    public function down(): void
    {
        Schema::table('error_occurrences', function (Blueprint $table) {
            $table->dropColumn(['language_version', 'framework_version', 'hostname']);
        });
    }
};
