<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\User;
use App\Watch\Databases;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerGates();
        $this->registerRouteBindings();
        $this->configureRateLimiting();
        $this->clearSplitDatabasesOnFresh();
    }

    /**
     * Make `migrate:fresh` wipe the telemetry and log databases too.
     *
     * Those tables live in databases of their own that `migrate:fresh` does not drop, and `migrate` keeps telemetry
     * that is already there when only the app database was wiped (so an app volume lost on its own never costs the
     * telemetry). `migrate:fresh` is a deliberate reset, so it empties them itself, after its production
     * confirmation. Deleting rather than truncating fires the full-text index triggers and respects MySQL foreign keys.
     */
    protected function clearSplitDatabasesOnFresh(): void
    {
        Event::listen(function (DatabaseRefreshed $event): void {
            $default = (string) config('database.default');

            if ($event->database !== null && $event->database !== $default) {
                return;
            }

            $groups = [
                Databases::TELEMETRY => Databases::TELEMETRY_TABLES,
                Databases::LOGS => Databases::LOG_TABLES,
            ];

            foreach ($groups as $connection => $tables) {
                if (Databases::sameDatabase($default, $connection)) {
                    continue;
                }

                $schema = DB::connection($connection)->getSchemaBuilder();

                foreach (array_reverse($tables) as $table) {
                    if ($schema->hasTable($table)) {
                        DB::connection($connection)->table($table)->delete();
                    }
                }
            }
        });
    }

    /**
     * Register the named limiters used by the ingest API routes.
     *
     * `ingest` is keyed on the project set by the auth middleware, so it must
     * run after it. `agent-auth` is keyed on the client IP and runs before
     * auth, so guessed environment tokens are throttled too.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('ingest', function (Request $request): Limit {
            $project = $request->attributes->get('watch_project');

            return $this->ingestLimit(
                (int) config('watch.ingest.rate_limit_per_minute'),
                $project instanceof Project ? 'project:'.$project->id : 'ip:'.$request->ip(),
            );
        });

        RateLimiter::for('agent-auth', fn (Request $request): Limit => $this->ingestLimit(
            (int) config('watch.ingest.agent_auth_per_minute'),
            'ip:'.$request->ip(),
        ));
    }

    /**
     * Build a per-minute limit that answers 429 in the shape of the endpoint
     * being throttled. A limit of zero or less disables throttling.
     */
    protected function ingestLimit(int $maxAttempts, string $key): Limit
    {
        if ($maxAttempts <= 0) {
            return Limit::none();
        }

        return Limit::perMinute($maxAttempts)
            ->by($key)
            ->response(function (Request $request, array $headers): JsonResponse {
                $body = $request->routeIs('api.nightwatch.*')
                    ? ['message' => 'Too many requests', 'stop' => false]
                    : ['error' => 'Too many requests.'];

                return response()->json($body, 429, $headers);
            });
    }

    protected function registerRouteBindings(): void
    {
        Route::model('admin', User::class);
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function registerGates(): void
    {
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        Gate::define('manage-admins', fn (User $user) => $user->isSuperAdmin());

        Gate::define('manage-projects', fn (User $user) => $user->isSuperAdmin());

        Gate::define('view-project', fn (User $user, Project $project) => $user->isSuperAdmin()
            || $project->admins()->whereKey($user->getKey())->exists());
    }
}
