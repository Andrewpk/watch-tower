<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Number of leading key characters kept as the non-secret display prefix.
     */
    private const PREFIX_LENGTH = 10;

    /**
     * Store a SHA-256 hash of each project's API key instead of the key itself.
     *
     * Existing keys are hashed in place before the plaintext column is dropped, so deployed agents keep working with
     * the keys they already have. `api_secret_hash` was never read and is dropped with it.
     *
     * `api_key_hash` and `api_key_prefix` stay nullable: SQLite cannot add a NOT NULL column without a default, and
     * changing the column afterwards would rebuild `projects`, which every telemetry table references.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->char('api_key_hash', 64)->nullable()->unique();
            $table->string('api_key_prefix', 16)->nullable();
            $table->char('previous_api_key_hash', 64)->nullable()->index();
            $table->timestamp('previous_api_key_expires_at')->nullable();
            $table->unsignedInteger('api_key_version')->default(1);
            $table->timestamp('api_key_last_used_at')->nullable();
        });

        DB::table('projects')->select(['id', 'api_key'])->orderBy('id')->chunkById(100, function ($projects): void {
            foreach ($projects as $project) {
                DB::table('projects')->where('id', $project->id)->update([
                    'api_key_hash' => hash('sha256', $project->api_key),
                    'api_key_prefix' => substr($project->api_key, 0, self::PREFIX_LENGTH),
                ]);
            }
        });

        // The unique index goes first: SQLite refuses to drop an indexed column.
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique(['api_key']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['api_key', 'api_secret_hash']);
        });
    }

    /**
     * Restore the plaintext columns.
     *
     * Hashes cannot be turned back into keys, so every project gets a new random key and secret hash. Agents must be
     * reconfigured with the new keys, which are not recorded anywhere readable. The columns come back nullable for
     * the same reason `up()` adds the new ones nullable.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('api_key', 64)->nullable();
            $table->string('api_secret_hash')->nullable();
        });

        DB::table('projects')->select('id')->orderBy('id')->chunkById(100, function ($projects): void {
            foreach ($projects as $project) {
                DB::table('projects')->where('id', $project->id)->update([
                    'api_key' => 'pk_'.Str::random(40),
                    'api_secret_hash' => hash('sha256', Str::random(48)),
                ]);
            }
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->unique('api_key');
            $table->dropUnique(['api_key_hash']);
            $table->dropIndex(['previous_api_key_hash']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn([
                'api_key_hash',
                'api_key_prefix',
                'previous_api_key_hash',
                'previous_api_key_expires_at',
                'api_key_version',
                'api_key_last_used_at',
            ]);
        });
    }
};
