<?php

namespace Database\Seeders;

use App\Models\CacheEvent;
use App\Models\CommandRun;
use App\Models\ErrorGroup;
use App\Models\ErrorOccurrence;
use App\Models\LogEntry;
use App\Models\MailSend;
use App\Models\NotificationSend;
use App\Models\Organization;
use App\Models\OutgoingRequest;
use App\Models\Project;
use App\Models\QueueJobRun;
use App\Models\ScheduledTaskRun;
use App\Models\Trace;
use App\Models\TraceQuery;
use App\Models\User;
use App\Watch\Fingerprinter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'acme'],
            ['name' => 'Acme Inc.', 'plan' => 'pro', 'retention_days' => 30]
        );

        $teamMembers = [
            ['name' => 'Jordan Pierce', 'email' => 'jordan@acme.test'],
            ['name' => 'Riley Chen', 'email' => 'riley@acme.test'],
            ['name' => 'Sam Okafor', 'email' => 'sam@acme.test'],
            ['name' => 'Avery Patel', 'email' => 'avery@acme.test'],
        ];

        $teamUsers = collect($teamMembers)->map(fn (array $member) => User::query()->firstOrCreate(
            ['email' => $member['email']],
            [
                'name' => $member['name'],
                'password' => Hash::make('password'),
                'organization_id' => $organization->id,
                'role' => 'member',
            ]
        ))->all();

        $project = $this->ensureDemoProject($organization);

        $this->command?->info("Seeding traces for project: {$project->name}");

        $now = CarbonImmutable::now();
        $start = $now->subDay();

        $endUsers = $this->buildEndUserPool();

        $routes = [
            ['GET', '/api/users', 200, 12],
            ['GET', '/api/users', 200, 18],
            ['GET', '/api/users/{id}', 200, 9],
            ['POST', '/api/users', 201, 45],
            ['POST', '/api/orders', 201, 132],
            ['GET', '/api/orders', 200, 27],
            ['GET', '/api/orders/{id}', 200, 16],
            ['PUT', '/api/orders/{id}', 200, 64],
            ['DELETE', '/api/orders/{id}', 204, 30],
            ['GET', '/dashboard', 200, 88],
            ['GET', '/settings', 200, 44],
            ['POST', '/api/payments', 200, 412],
            ['POST', '/api/payments', 500, 1240],
            ['GET', '/api/reports/heavy', 200, 1850],
            ['GET', '/api/search', 200, 230],
            ['GET', '/api/search', 404, 21],
            ['GET', '/api/products', 200, 22],
            ['POST', '/api/checkout', 422, 18],
            ['POST', '/api/checkout', 200, 320],
            ['GET', '/api/notifications', 200, 14],
        ];

        $totalTraces = 480;
        $traces = [];

        for ($i = 0; $i < $totalTraces; $i++) {
            $route = $routes[array_rand($routes)];
            [$method, $uri, $baseStatus, $baseDuration] = $route;

            $jitter = random_int(-30, 200) / 100;
            $duration = max(2, (int) round($baseDuration * (1 + $jitter)));

            $status = $baseStatus;
            if (random_int(1, 100) <= 4) {
                $status = 500;
            } elseif (random_int(1, 100) <= 8 && $baseStatus < 400) {
                $status = [400, 401, 403, 404, 422][array_rand([400, 401, 403, 404, 422])];
            }

            $offsetSeconds = random_int(0, 86_400);
            $occurredAt = $start->addSeconds($offsetSeconds);

            $hasUser = random_int(1, 100) <= 80;
            $endUser = $hasUser ? $endUsers[array_rand($endUsers)] : null;

            $traces[] = [
                'id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'correlation_id' => (string) Str::uuid(),
                'method' => $method,
                'uri' => $uri,
                'status_code' => $status,
                'user_identifier' => $endUser['id'] ?? null,
                'user_email' => $endUser['email'] ?? null,
                'user_name' => $endUser['name'] ?? null,
                'duration_ms' => $duration,
                'db_queries_count' => random_int(0, 18),
                'db_time_ms' => random_int(0, max(1, (int) ($duration / 3))),
                'memory_used_kb' => random_int(2_048, 32_768),
                'memory_peak_kb' => random_int(4_096, 65_536),
                'environment' => 'production',
                'release_version' => 'v1.4.2',
                'hostname' => 'web-'.random_int(1, 3),
                'ip_address' => '203.0.113.'.random_int(1, 254),
                'user_agent' => 'Mozilla/5.0 (compatible; Demo/1.0)',
                'headers' => json_encode(['accept' => 'application/json']),
                'request_data' => json_encode([]),
                'response_data' => json_encode(['status' => 'ok']),
                'has_errors' => $status >= 500,
                'has_slow_queries' => $duration > 800,
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ];
        }

        foreach (array_chunk($traces, 200) as $chunk) {
            Trace::query()->insert($chunk);
        }

        $this->seedExceptions($project, $traces, $start, $teamUsers, $endUsers);
        $this->seedQueries($project, $traces);
        $this->seedJobs($project, $start, $endUsers);
        $this->seedCommands($project, $start);
        $this->seedScheduledTasks($project, $start, $now);
        $this->seedMail($project, $start);
        $this->seedNotifications($project, $start);
        $this->seedCache($project, $start);
        $this->seedOutgoingRequests($project, $start);
        $this->seedLogs($project, $start, $teamUsers);
    }

    /**
     * Find or create the demo project.
     *
     * Only the hash of a project's API key is stored, so the key of a newly
     * created project is printed to the console: this is the only time it can
     * be read.
     */
    public function ensureDemoProject(Organization $organization): Project
    {
        $project = Project::query()->where('slug', 'acme-app')->first();

        if ($project !== null) {
            $this->command?->info("Project {$project->name} already exists; its API key is stored hashed and cannot be shown. Rotate it in Settings > Projects to get a new one.");

            return $project;
        }

        $project = new Project([
            'organization_id' => $organization->id,
            'slug' => 'acme-app',
            'name' => 'Acme App',
            'description' => 'Production demo project',
            'sampling_rate' => 1.0,
            'retention_days' => 30,
        ]);
        $apiKey = $project->issueApiKey();
        $project->save();

        $this->command?->info("Demo API key for {$project->name} (only its hash is stored, copy it now): {$apiKey}");

        return $project;
    }

    /**
     * @param  list<array<string, mixed>>  $traces
     * @param  list<User>  $teamUsers
     * @param  list<array{id:string,email:string,name:string}>  $endUsers
     */
    private function seedExceptions(Project $project, array $traces, CarbonImmutable $start, array $teamUsers, array $endUsers): void
    {
        $templates = [
            [
                'class' => 'Illuminate\\Database\\QueryException',
                'message' => 'SQLSTATE[42S22]: Column not found: orders.status',
                'file' => 'app/Repositories/OrderRepository.php',
                'line' => 84,
                'is_handled' => false,
                'stacktrace' => [
                    ['file' => 'app/Repositories/OrderRepository.php', 'line' => 84, 'function' => 'findByStatus', 'class' => 'App\\Repositories\\OrderRepository'],
                    ['file' => 'app/Http/Controllers/OrderController.php', 'line' => 47, 'function' => 'index', 'class' => 'App\\Http\\Controllers\\OrderController'],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Routing/Controller.php', 'line' => 54, 'function' => 'callAction', 'class' => 'Illuminate\\Routing\\Controller'],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php', 'line' => 43, 'function' => 'dispatch', 'class' => 'Illuminate\\Routing\\ControllerDispatcher'],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Routing/Route.php', 'line' => 260, 'function' => 'runController', 'class' => 'Illuminate\\Routing\\Route'],
                ],
            ],
            [
                'class' => 'GuzzleHttp\\Exception\\ConnectException',
                'message' => 'cURL error 28: Connection timed out after 30000 ms',
                'file' => 'app/Services/PaymentGateway.php',
                'line' => 122,
                'is_handled' => true,
                'stacktrace' => [
                    ['file' => 'app/Services/PaymentGateway.php', 'line' => 122, 'function' => 'charge', 'class' => 'App\\Services\\PaymentGateway'],
                    ['file' => 'app/Http/Controllers/PaymentController.php', 'line' => 73, 'function' => 'store', 'class' => 'App\\Http\\Controllers\\PaymentController'],
                    ['file' => 'vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php', 'line' => 211, 'function' => 'createRejection', 'class' => 'GuzzleHttp\\Handler\\CurlFactory'],
                ],
            ],
            [
                'class' => 'TypeError',
                'message' => 'Argument #1 ($id) must be of type int, string given',
                'file' => 'app/Http/Controllers/UserController.php',
                'line' => 56,
                'is_handled' => false,
                'stacktrace' => [
                    ['file' => 'app/Http/Controllers/UserController.php', 'line' => 56, 'function' => 'show', 'class' => 'App\\Http\\Controllers\\UserController'],
                    ['file' => 'app/Services/UserResolver.php', 'line' => 21, 'function' => 'resolve', 'class' => 'App\\Services\\UserResolver'],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Routing/Route.php', 'line' => 260, 'function' => 'runController', 'class' => 'Illuminate\\Routing\\Route'],
                ],
            ],
            [
                'class' => 'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException',
                'message' => 'The route api/legacy could not be found.',
                'file' => 'vendor/symfony/http-kernel/HttpKernel.php',
                'line' => 138,
                'is_handled' => true,
                'stacktrace' => [
                    ['file' => 'vendor/symfony/http-kernel/HttpKernel.php', 'line' => 138, 'function' => 'handleRaw', 'class' => 'Symfony\\Component\\HttpKernel\\HttpKernel'],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php', 'line' => 169, 'function' => 'handle', 'class' => 'Illuminate\\Foundation\\Http\\Kernel'],
                ],
            ],
            [
                'class' => 'RuntimeException',
                'message' => 'Failed to dispatch InvoiceMailer: queue connection unavailable',
                'file' => 'app/Jobs/SendInvoice.php',
                'line' => 41,
                'is_handled' => false,
                'stacktrace' => [
                    ['file' => 'app/Jobs/SendInvoice.php', 'line' => 41, 'function' => 'handle', 'class' => 'App\\Jobs\\SendInvoice'],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php', 'line' => 122, 'function' => 'call', 'class' => 'Illuminate\\Queue\\CallQueuedHandler'],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Queue/Jobs/Job.php', 'line' => 99, 'function' => 'fire', 'class' => 'Illuminate\\Queue\\Jobs\\Job'],
                ],
            ],
            [
                'class' => 'Illuminate\\Auth\\AuthenticationException',
                'message' => 'Unauthenticated.',
                'file' => 'app/Http/Middleware/Authenticate.php',
                'line' => 27,
                'is_handled' => true,
                'stacktrace' => [
                    ['file' => 'app/Http/Middleware/Authenticate.php', 'line' => 27, 'function' => 'redirectTo', 'class' => 'App\\Http\\Middleware\\Authenticate'],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Auth/Middleware/Authenticate.php', 'line' => 81, 'function' => 'unauthenticated', 'class' => 'Illuminate\\Auth\\Middleware\\Authenticate'],
                ],
            ],
            [
                'class' => 'Illuminate\\Validation\\ValidationException',
                'message' => 'The given data was invalid.',
                'file' => 'app/Http/Requests/CheckoutRequest.php',
                'line' => 38,
                'is_handled' => true,
                'stacktrace' => [
                    ['file' => 'app/Http/Requests/CheckoutRequest.php', 'line' => 38, 'function' => 'rules', 'class' => 'App\\Http\\Requests\\CheckoutRequest'],
                    ['file' => 'vendor/laravel/framework/src/Illuminate/Foundation/Http/FormRequest.php', 'line' => 145, 'function' => 'failedValidation', 'class' => 'Illuminate\\Foundation\\Http\\FormRequest'],
                ],
            ],
            [
                'class' => 'Redis\\RedisException',
                'message' => 'Connection refused: tcp://redis:6379',
                'file' => 'app/Services/CacheWarmer.php',
                'line' => 64,
                'is_handled' => false,
                'stacktrace' => [
                    ['file' => 'app/Services/CacheWarmer.php', 'line' => 64, 'function' => 'warm', 'class' => 'App\\Services\\CacheWarmer'],
                    ['file' => 'app/Console/Commands/WarmCache.php', 'line' => 22, 'function' => 'handle', 'class' => 'App\\Console\\Commands\\WarmCache'],
                ],
            ],
        ];

        $priorities = ['none', 'none', 'none', 'low', 'low', 'medium', 'medium', 'high'];
        $descriptions = [
            null,
            null,
            "Started appearing after the **v1.4.2** deploy.\n\nLikely related to the new orders table migration that added the `status` column.",
            "Customer reports indicate this happens at checkout when payment provider is slow.\n\n- Affecting ~3% of payments\n- Retry queue picks up most failures",
            "Reproduced locally by passing a numeric string to the `users.show` route.\n\nNeed to add explicit casting at the controller boundary.",
        ];

        $errorTraces = array_values(array_filter($traces, fn (array $t): bool => $t['has_errors']));
        $errorRows = [];
        $groups = [];
        $fingerprinter = new Fingerprinter;
        $environments = ['production', 'production', 'production', 'staging'];

        $errorCount = max(120, count($errorTraces) * 2);

        for ($i = 0; $i < $errorCount; $i++) {
            $tpl = $templates[array_rand($templates)];
            $trace = $errorTraces !== [] && random_int(1, 100) <= 70
                ? $errorTraces[array_rand($errorTraces)]
                : null;

            $occurredAt = $trace
                ? CarbonImmutable::parse($trace['occurred_at'])
                : $start->addSeconds(random_int(0, 86_400));

            $errorUser = null;
            if ($trace !== null && ! empty($trace['user_identifier'])) {
                $errorUser = [
                    'id' => $trace['user_identifier'],
                    'email' => $trace['user_email'],
                    'name' => $trace['user_name'],
                ];
            } elseif ($endUsers !== [] && random_int(1, 100) <= 75) {
                $errorUser = $endUsers[array_rand($endUsers)];
            }

            $fingerprint = $fingerprinter->forException([
                'class' => $tpl['class'],
                'file' => $tpl['file'],
                'line' => $tpl['line'],
            ]);

            if (! isset($groups[$fingerprint])) {
                $groups[$fingerprint] = [
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'fingerprint' => $fingerprint,
                    'display_number' => count($groups) + 1,
                    'exception_class' => $tpl['class'],
                    'first_message' => $tpl['message'],
                    'first_file' => $tpl['file'],
                    'first_line' => $tpl['line'],
                    'total_count' => 0,
                    'first_occurrence_at' => $occurredAt,
                    'last_occurrence_at' => $occurredAt,
                    'status' => 'unresolved',
                    'priority' => $priorities[array_rand($priorities)],
                    'description' => $descriptions[array_rand($descriptions)],
                    'is_handled' => $tpl['is_handled'],
                    'linear_issue_url' => null,
                    'subscriber_ids' => json_encode([]),
                    'framework_version' => '12.35.1',
                    'language_version' => '8.4.0',
                    'assigned_to_user_id' => random_int(1, 100) <= 60 ? $teamUsers[array_rand($teamUsers)]->id : null,
                    'tags' => json_encode([]),
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ];
            }

            $groups[$fingerprint]['total_count']++;
            if ($occurredAt->lt($groups[$fingerprint]['first_occurrence_at'])) {
                $groups[$fingerprint]['first_occurrence_at'] = $occurredAt;
            }
            if ($occurredAt->gt($groups[$fingerprint]['last_occurrence_at'])) {
                $groups[$fingerprint]['last_occurrence_at'] = $occurredAt;
                $groups[$fingerprint]['updated_at'] = $occurredAt;
            }

            $errorRows[] = [
                'id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'trace_id' => $trace['id'] ?? null,
                'error_group_id' => $groups[$fingerprint]['id'],
                'exception_class' => $tpl['class'],
                'message' => $tpl['message'],
                'stacktrace' => json_encode($tpl['stacktrace']),
                'fingerprint' => $fingerprint,
                'user_identifier' => $errorUser['id'] ?? null,
                'user_email' => $errorUser['email'] ?? null,
                'user_name' => $errorUser['name'] ?? null,
                'file' => $tpl['file'],
                'line' => $tpl['line'],
                'is_handled' => $tpl['is_handled'],
                'environment' => $environments[array_rand($environments)],
                'release_version' => 'v1.4.2',
                'language_version' => '8.4.0',
                'framework_version' => '12.35.1',
                'hostname' => 'web-1',
                'context' => json_encode([
                    'request' => ['method' => 'GET', 'url' => '/api/example'],
                ]),
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ];
        }

        ErrorGroup::query()->insert(array_values($groups));
        foreach (array_chunk($errorRows, 200) as $chunk) {
            ErrorOccurrence::query()->insert($chunk);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $traces
     */
    private function seedQueries(Project $project, array $traces): void
    {
        /** @var list<array{sql:string,type:string,connection:string,weight:int,base_ms:float,jitter_ms:float}> $templates */
        $templates = [
            ['sql' => 'select * from `users` where `id` = ? limit 1', 'type' => 'select', 'connection' => 'mysql', 'weight' => 220, 'base_ms' => 0.6, 'jitter_ms' => 0.6],
            ['sql' => 'select * from `users` where `email` = ? limit 1', 'type' => 'select', 'connection' => 'mysql', 'weight' => 80, 'base_ms' => 0.8, 'jitter_ms' => 0.6],
            ['sql' => 'select `id`, `name`, `email` from `users` where `organization_id` = ? order by `name` asc', 'type' => 'select', 'connection' => 'mysql', 'weight' => 30, 'base_ms' => 1.4, 'jitter_ms' => 0.9],
            ['sql' => 'select count(*) as aggregate from `users` where `organization_id` = ? and `deleted_at` is null', 'type' => 'select', 'connection' => 'mysql', 'weight' => 25, 'base_ms' => 1.1, 'jitter_ms' => 0.7],
            ['sql' => 'update `users` set `last_seen_at` = ?, `updated_at` = ? where `id` = ?', 'type' => 'update', 'connection' => 'mysql', 'weight' => 60, 'base_ms' => 1.5, 'jitter_ms' => 1.2],
            ['sql' => 'select * from `organizations` where `slug` = ? limit 1', 'type' => 'select', 'connection' => 'mysql', 'weight' => 95, 'base_ms' => 0.7, 'jitter_ms' => 0.5],
            ['sql' => 'select * from `projects` where `organization_id` = ? order by `created_at` desc', 'type' => 'select', 'connection' => 'mysql', 'weight' => 55, 'base_ms' => 1.3, 'jitter_ms' => 0.8],
            ['sql' => 'select `projects`.*, (select count(*) from `error_groups` where `projects`.`id` = `error_groups`.`project_id` and `error_groups`.`status` = ?) as `open_issues` from `projects` where `slug` = ? limit 1', 'type' => 'select', 'connection' => 'mysql', 'weight' => 70, 'base_ms' => 2.4, 'jitter_ms' => 2.6],
            ['sql' => 'select * from `error_groups` where `project_id` = ? and `status` = ? order by `last_occurrence_at` desc limit 25 offset ?', 'type' => 'select', 'connection' => 'mysql', 'weight' => 50, 'base_ms' => 2.1, 'jitter_ms' => 1.8],
            ['sql' => 'select count(*) as aggregate from `error_groups` where `project_id` = ? and `status` = ?', 'type' => 'select', 'connection' => 'mysql', 'weight' => 35, 'base_ms' => 1.2, 'jitter_ms' => 0.8],
            ['sql' => 'select * from `error_occurrences` where `error_group_id` = ? order by `occurred_at` desc limit 1', 'type' => 'select', 'connection' => 'mysql', 'weight' => 65, 'base_ms' => 1.8, 'jitter_ms' => 1.5],
            ['sql' => 'select `environment`, count(*) as `total` from `error_occurrences` where `error_group_id` = ? group by `environment`', 'type' => 'select', 'connection' => 'mysql', 'weight' => 25, 'base_ms' => 2.6, 'jitter_ms' => 2.4],
            ['sql' => 'insert into `error_occurrences` (`error_group_id`, `project_id`, `message`, `file`, `line`, `stacktrace`, `context`, `environment`, `occurred_at`, `created_at`, `updated_at`) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 'type' => 'insert', 'connection' => 'mysql', 'weight' => 30, 'base_ms' => 3.2, 'jitter_ms' => 2.5],
            ['sql' => 'update `error_groups` set `total_count` = `total_count` + 1, `last_occurrence_at` = ?, `updated_at` = ? where `id` = ?', 'type' => 'update', 'connection' => 'mysql', 'weight' => 35, 'base_ms' => 2.1, 'jitter_ms' => 1.6],
            ['sql' => 'select * from `traces` where `project_id` = ? and `occurred_at` between ? and ? order by `occurred_at` desc limit ?', 'type' => 'select', 'connection' => 'mysql', 'weight' => 40, 'base_ms' => 4.6, 'jitter_ms' => 6.5],
            ['sql' => 'select `bucket`, count(*) as `total` from `traces` where `project_id` = ? and `occurred_at` between ? and ? group by `bucket` order by `bucket` asc', 'type' => 'select', 'connection' => 'mysql', 'weight' => 18, 'base_ms' => 7.3, 'jitter_ms' => 9.8],
            ['sql' => 'select avg(`duration_ms`) as `avg`, max(`duration_ms`) as `max` from `traces` where `project_id` = ? and `occurred_at` between ? and ?', 'type' => 'select', 'connection' => 'mysql', 'weight' => 15, 'base_ms' => 6.2, 'jitter_ms' => 8.1],
            ['sql' => 'select * from `queue_job_runs` where `project_id` = ? and `status` = ? order by `dispatched_at` desc limit ?', 'type' => 'select', 'connection' => 'mysql', 'weight' => 30, 'base_ms' => 1.7, 'jitter_ms' => 1.3],
            ['sql' => 'update `queue_job_runs` set `status` = ?, `completed_at` = ?, `duration_ms` = ?, `updated_at` = ? where `id` = ?', 'type' => 'update', 'connection' => 'mysql', 'weight' => 22, 'base_ms' => 1.4, 'jitter_ms' => 0.9],
            ['sql' => 'insert into `queue_job_runs` (`id`, `project_id`, `job_class`, `queue`, `connection`, `payload`, `status`, `dispatched_at`, `created_at`, `updated_at`) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 'type' => 'insert', 'connection' => 'mysql', 'weight' => 28, 'base_ms' => 2.1, 'jitter_ms' => 1.5],
            ['sql' => 'select * from `command_runs` where `project_id` = ? and `command` = ? order by `occurred_at` desc limit ?', 'type' => 'select', 'connection' => 'mysql', 'weight' => 14, 'base_ms' => 1.6, 'jitter_ms' => 1.1],
            ['sql' => 'select * from `scheduled_task_runs` where `project_id` = ? and `task_hash` = ? order by `occurred_at` desc', 'type' => 'select', 'connection' => 'mysql', 'weight' => 12, 'base_ms' => 1.5, 'jitter_ms' => 1.0],
            ['sql' => 'select * from `sessions` where `id` = ? limit 1', 'type' => 'select', 'connection' => 'mysql', 'weight' => 110, 'base_ms' => 0.5, 'jitter_ms' => 0.3],
            ['sql' => 'update `sessions` set `payload` = ?, `last_activity` = ? where `id` = ?', 'type' => 'update', 'connection' => 'mysql', 'weight' => 95, 'base_ms' => 0.9, 'jitter_ms' => 0.4],
            ['sql' => 'insert into `sessions` (`id`, `user_id`, `payload`, `last_activity`) values (?, ?, ?, ?)', 'type' => 'insert', 'connection' => 'mysql', 'weight' => 18, 'base_ms' => 1.1, 'jitter_ms' => 0.5],
            ['sql' => 'delete from `sessions` where `last_activity` < ?', 'type' => 'delete', 'connection' => 'mysql', 'weight' => 4, 'base_ms' => 5.2, 'jitter_ms' => 3.6],
            ['sql' => 'select * from `cache` where `key` = ? and `expiration` > ? limit 1', 'type' => 'select', 'connection' => 'mysql', 'weight' => 75, 'base_ms' => 0.4, 'jitter_ms' => 0.3],
            ['sql' => 'insert into `cache` (`key`, `value`, `expiration`) values (?, ?, ?) on duplicate key update `value` = values(`value`), `expiration` = values(`expiration`)', 'type' => 'insert', 'connection' => 'mysql', 'weight' => 42, 'base_ms' => 0.9, 'jitter_ms' => 0.5],
            ['sql' => 'select count(*) as aggregate from `notifications` where `user_id` = ? and `read_at` is null', 'type' => 'select', 'connection' => 'mysql', 'weight' => 48, 'base_ms' => 0.9, 'jitter_ms' => 0.6],
            ['sql' => 'select * from `notifications` where `user_id` = ? order by `created_at` desc limit ?', 'type' => 'select', 'connection' => 'mysql', 'weight' => 32, 'base_ms' => 1.4, 'jitter_ms' => 1.1],
            ['sql' => 'update `notifications` set `read_at` = ?, `updated_at` = ? where `id` = ?', 'type' => 'update', 'connection' => 'mysql', 'weight' => 16, 'base_ms' => 1.0, 'jitter_ms' => 0.7],
            ['sql' => 'select * from `orders` where `user_id` = ? order by `created_at` desc', 'type' => 'select', 'connection' => 'mysql', 'weight' => 38, 'base_ms' => 2.5, 'jitter_ms' => 2.1],
            ['sql' => 'select `orders`.*, `users`.`email` from `orders` inner join `users` on `orders`.`user_id` = `users`.`id` where `orders`.`id` = ? limit 1', 'type' => 'select', 'connection' => 'mysql', 'weight' => 26, 'base_ms' => 2.8, 'jitter_ms' => 1.9],
            ['sql' => 'update `orders` set `status` = ?, `updated_at` = ? where `id` = ?', 'type' => 'update', 'connection' => 'mysql', 'weight' => 20, 'base_ms' => 1.6, 'jitter_ms' => 0.9],
            ['sql' => 'insert into `orders` (`user_id`, `total`, `currency`, `status`, `created_at`, `updated_at`) values (?, ?, ?, ?, ?, ?)', 'type' => 'insert', 'connection' => 'mysql', 'weight' => 18, 'base_ms' => 2.2, 'jitter_ms' => 1.5],
            ['sql' => 'select * from `order_items` where `order_id` = ?', 'type' => 'select', 'connection' => 'mysql', 'weight' => 36, 'base_ms' => 1.7, 'jitter_ms' => 1.2],
            ['sql' => 'select `products`.* from `products` where `products`.`id` in (?, ?, ?, ?)', 'type' => 'select', 'connection' => 'mysql', 'weight' => 28, 'base_ms' => 1.9, 'jitter_ms' => 1.3],
            ['sql' => 'select * from `products` where `slug` = ? limit 1', 'type' => 'select', 'connection' => 'mysql', 'weight' => 70, 'base_ms' => 0.9, 'jitter_ms' => 0.5],
            ['sql' => 'select * from `inventory` where `product_id` = ? and `warehouse_id` = ? for update', 'type' => 'select', 'connection' => 'mysql', 'weight' => 10, 'base_ms' => 6.8, 'jitter_ms' => 9.2],
            ['sql' => 'update `inventory` set `quantity` = `quantity` - ?, `updated_at` = ? where `product_id` = ? and `warehouse_id` = ?', 'type' => 'update', 'connection' => 'mysql', 'weight' => 8, 'base_ms' => 4.1, 'jitter_ms' => 5.6],
            ['sql' => 'select * from `audit_logs` where `subject_type` = ? and `subject_id` = ? order by `created_at` desc limit ?', 'type' => 'select', 'connection' => 'mysql', 'weight' => 14, 'base_ms' => 8.7, 'jitter_ms' => 12.1],
            ['sql' => 'insert into `audit_logs` (`user_id`, `subject_type`, `subject_id`, `event`, `properties`, `created_at`) values (?, ?, ?, ?, ?, ?)', 'type' => 'insert', 'connection' => 'mysql', 'weight' => 20, 'base_ms' => 2.4, 'jitter_ms' => 1.7],
            ['sql' => 'select * from `webhooks` where `project_id` = ? and `is_active` = ?', 'type' => 'select', 'connection' => 'mysql', 'weight' => 16, 'base_ms' => 0.8, 'jitter_ms' => 0.4],
            ['sql' => 'insert into `webhook_deliveries` (`webhook_id`, `payload`, `status`, `attempt`, `created_at`) values (?, ?, ?, ?, ?)', 'type' => 'insert', 'connection' => 'mysql', 'weight' => 14, 'base_ms' => 1.4, 'jitter_ms' => 0.9],
            ['sql' => 'select * from `feature_flags` where `key` = ? limit 1', 'type' => 'select', 'connection' => 'redis', 'weight' => 200, 'base_ms' => 0.2, 'jitter_ms' => 0.1],
            ['sql' => 'select * from `rate_limits` where `bucket` = ? and `period_start` >= ?', 'type' => 'select', 'connection' => 'redis', 'weight' => 140, 'base_ms' => 0.3, 'jitter_ms' => 0.2],
            ['sql' => 'incr `rate_limits:?:?`', 'type' => 'update', 'connection' => 'redis', 'weight' => 140, 'base_ms' => 0.2, 'jitter_ms' => 0.1],
            ['sql' => 'select `id`, `email` from `analytics`.`events` where `event` = ? and `created_at` between ? and ? order by `created_at` desc limit ?', 'type' => 'select', 'connection' => 'analytics', 'weight' => 9, 'base_ms' => 18.4, 'jitter_ms' => 24.6],
            ['sql' => 'select sum(`amount`) as `total` from `analytics`.`revenue_daily` where `tenant_id` = ? and `day` between ? and ?', 'type' => 'select', 'connection' => 'analytics', 'weight' => 7, 'base_ms' => 14.6, 'jitter_ms' => 18.2],
        ];

        $weightedIndex = $this->buildWeightedIndex($templates);

        $tracesByHour = [];
        foreach ($traces as $trace) {
            $hour = substr((string) $trace['occurred_at'], 0, 13);
            $tracesByHour[$hour][] = $trace;
        }

        $rows = [];
        foreach ($templates as $template) {
            $totalCalls = max(1, (int) ($template['weight'] * random_int(7, 12)));

            for ($i = 0; $i < $totalCalls; $i++) {
                $trace = $traces[array_rand($traces)];

                $duration = $template['base_ms'] + (random_int(0, (int) ($template['jitter_ms'] * 100)) / 100);
                if (random_int(1, 50) === 1) {
                    $duration *= random_int(3, 12);
                }

                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'trace_id' => $trace['id'],
                    'query_type' => $template['type'],
                    'sql' => $template['sql'],
                    'bindings' => json_encode([]),
                    'connection_name' => $template['connection'],
                    'duration_ms' => round($duration, 3),
                    'row_count' => $template['type'] === 'select' ? random_int(0, 50) : null,
                    'is_n_plus_one' => $totalCalls > 1200 && random_int(1, 4) === 1,
                    'is_slow' => $duration > 25,
                    'occurred_at' => $trace['occurred_at'],
                    'created_at' => $trace['occurred_at'],
                    'updated_at' => $trace['occurred_at'],
                ];

                if (count($rows) >= 500) {
                    TraceQuery::query()->insert($rows);
                    $rows = [];
                }
            }
        }

        // Filler queries to populate the long tail (low-weight templates picked at random).
        $filler = max(0, 800 - count($rows));
        for ($i = 0; $i < $filler; $i++) {
            $template = $templates[$weightedIndex[array_rand($weightedIndex)]];
            $trace = $traces[array_rand($traces)];
            $duration = $template['base_ms'] + (random_int(0, (int) ($template['jitter_ms'] * 100)) / 100);

            $rows[] = [
                'id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'trace_id' => $trace['id'],
                'query_type' => $template['type'],
                'sql' => $template['sql'],
                'bindings' => json_encode([]),
                'connection_name' => $template['connection'],
                'duration_ms' => round($duration, 3),
                'row_count' => $template['type'] === 'select' ? random_int(0, 50) : null,
                'is_n_plus_one' => false,
                'is_slow' => $duration > 25,
                'occurred_at' => $trace['occurred_at'],
                'created_at' => $trace['occurred_at'],
                'updated_at' => $trace['occurred_at'],
            ];

            if (count($rows) >= 500) {
                TraceQuery::query()->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            TraceQuery::query()->insert($rows);
        }
    }

    /**
     * @param  list<array{weight:int}>  $templates
     * @return list<int>
     */
    private function buildWeightedIndex(array $templates): array
    {
        $index = [];
        foreach ($templates as $i => $template) {
            for ($j = 0; $j < $template['weight']; $j++) {
                $index[] = $i;
            }
        }

        return $index;
    }

    /**
     * @param  list<array{id:string,email:string,name:string}>  $endUsers
     */
    private function seedJobs(Project $project, CarbonImmutable $start, array $endUsers): void
    {
        $jobClasses = [
            'App\\Events\\Backup\\BackupCreated',
            'App\\Events\\Backup\\BackupSuccess',
            'App\\Events\\Server\\ResourceMonitorStatsReceived',
            'App\\Events\\Site\\CertificateStatusUpdated',
            'App\\Events\\Site\\SiteActive',
            'App\\Events\\Site\\SiteCreationProgress',
            'App\\Events\\Site\\SiteDeleted',
            'App\\Jobs\\Certificate\\DeployScript',
            'App\\Jobs\\SendInvoiceEmail',
            'App\\Jobs\\ProcessPayment',
            'App\\Jobs\\GenerateReport',
            'App\\Jobs\\SyncCustomer',
            'App\\Jobs\\PruneAuditLog',
            'App\\Jobs\\IndexSearch',
            'App\\Jobs\\WarmCache',
            'App\\Jobs\\ExportCsv',
        ];

        $connections = ['redis', 'redis', 'redis', 'database', 'sqs'];
        $queues = ['default', 'default', 'default', 'high', 'low', 'emails'];

        // Weighted status distribution: mostly completed, some released/queued, rare failures.
        $statuses = [
            'completed', 'completed', 'completed', 'completed', 'completed',
            'completed', 'completed', 'completed', 'completed', 'completed',
            'released', 'released',
            'queued',
            'failed',
        ];

        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $dispatched = $start->addSeconds(random_int(0, 86_400));
            $status = $statuses[array_rand($statuses)];
            $duration = random_int(20, 6_200);

            $startedAt = $status === 'queued' ? null : $dispatched->addSeconds(random_int(0, 5));
            $completedAt = $status === 'completed' && $startedAt !== null ? $startedAt->addMilliseconds($duration) : null;
            $failedAt = $status === 'failed' && $startedAt !== null ? $startedAt->addMilliseconds($duration) : null;

            $jobUser = $endUsers !== [] && random_int(1, 100) <= 65
                ? $endUsers[array_rand($endUsers)]
                : null;

            $rows[] = [
                'id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'trace_id' => null,
                'job_class' => $jobClasses[array_rand($jobClasses)],
                'queue' => $queues[array_rand($queues)],
                'connection' => $connections[array_rand($connections)],
                'dispatched_at' => $dispatched,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'failed_at' => $failedAt,
                'duration_ms' => $status === 'queued' ? null : $duration,
                'attempts' => $status === 'failed' ? random_int(2, 3) : ($status === 'queued' ? 0 : 1),
                'status' => $status,
                'payload' => json_encode([]),
                'user_identifier' => $jobUser['id'] ?? null,
                'user_email' => $jobUser['email'] ?? null,
                'user_name' => $jobUser['name'] ?? null,
                'exception' => $status === 'failed' ? json_encode(['class' => 'RuntimeException', 'message' => 'demo failure']) : null,
                'environment' => 'production',
                'created_at' => $dispatched,
                'updated_at' => $completedAt ?? $failedAt ?? $dispatched,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            QueueJobRun::query()->insert($chunk);
        }
    }

    private function seedCommands(Project $project, CarbonImmutable $start): void
    {
        $commands = [
            ['command' => 'backup:daily', 'base_duration' => 4_200, 'fail_rate' => 5],
            ['command' => 'backup:hourly', 'base_duration' => 1_800, 'fail_rate' => 3],
            ['command' => 'backup:sweep', 'base_duration' => 320, 'fail_rate' => 1],
            ['command' => 'servers:cleanup-ssh-key-files', 'base_duration' => 145, 'fail_rate' => 2],
            ['command' => 'uploads:clear', 'base_duration' => 85, 'fail_rate' => 0],
            ['command' => 'alerts:cleanup-migration-failure-archives', 'base_duration' => 230, 'fail_rate' => 4],
            ['command' => 'queue:prune-batches', 'base_duration' => 60, 'fail_rate' => 1],
            ['command' => 'cache:prune-stale-tags', 'base_duration' => 28, 'fail_rate' => 0],
        ];

        $environments = ['production', 'production', 'production', 'staging'];

        $rows = [];
        for ($i = 0; $i < 420; $i++) {
            $tpl = $commands[array_rand($commands)];

            $jitter = random_int(-30, 220) / 100;
            $duration = max(5, (int) round($tpl['base_duration'] * (1 + $jitter)));

            $failed = random_int(1, 100) <= $tpl['fail_rate'];
            $exitCode = $failed ? [1, 1, 1, 2, 127][array_rand([1, 1, 1, 2, 127])] : 0;
            $status = $failed ? 'failed' : 'completed';

            $offsetSeconds = random_int(0, 86_400);
            $occurredAt = $start->addSeconds($offsetSeconds);

            $rows[] = [
                'id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'command' => $tpl['command'],
                'arguments' => json_encode([]),
                'options' => json_encode(['--quiet' => false]),
                'status' => $status,
                'exit_code' => $exitCode,
                'duration_ms' => $duration,
                'output' => $failed
                    ? "Error: process exited with code {$exitCode}\n  at ".$tpl['command']
                    : "Completed {$tpl['command']} in {$duration}ms",
                'environment' => $environments[array_rand($environments)],
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            CommandRun::query()->insert($chunk);
        }
    }

    private function seedScheduledTasks(Project $project, CarbonImmutable $start, CarbonImmutable $now): void
    {
        $tasks = [
            [
                'task' => 'php artisan backup:daily',
                'schedule' => 'Every hour',
                'schedule_summary' => 'AT 02:00 AM',
                'next_run_at' => $now->addMinutes(46),
                'base_duration' => 920,
                'fail_rate' => 5,
                'skip_rate' => 2,
            ],
            [
                'task' => 'php artisan backup:hourly',
                'schedule' => 'Every hour',
                'schedule_summary' => 'AT MINUTE 00',
                'next_run_at' => $now->addMinutes(46),
                'base_duration' => 540,
                'fail_rate' => 3,
                'skip_rate' => 5,
            ],
            [
                'task' => 'php artisan backup:sweep',
                'schedule' => 'Every 15 minutes',
                'schedule_summary' => 'EVERY 15 MINUTES',
                'next_run_at' => $now->addMinutes(1),
                'base_duration' => 18,
                'fail_rate' => 1,
                'skip_rate' => 0,
            ],
            [
                'task' => 'php artisan servers:cleanup-ssh-key-files',
                'schedule' => 'Every hour',
                'schedule_summary' => 'AT MINUTE 00',
                'next_run_at' => $now->addMinutes(46),
                'base_duration' => 1_680,
                'fail_rate' => 2,
                'skip_rate' => 0,
            ],
            [
                'task' => 'php artisan uploads:clear',
                'schedule' => 'At 25 minutes past the hour',
                'schedule_summary' => 'AT MINUTE 25',
                'next_run_at' => $now->addMinutes(11),
                'base_duration' => 466,
                'fail_rate' => 0,
                'skip_rate' => 0,
            ],
            [
                'task' => 'Closure at: routes/console.php:38',
                'schedule' => 'Every day at 02:00',
                'schedule_summary' => 'AT 02:00 AM',
                'next_run_at' => $now->addDay()->setTime(2, 0),
                'base_duration' => 14,
                'fail_rate' => 0,
                'skip_rate' => 0,
            ],
        ];

        $environments = ['production', 'production', 'production', 'staging'];

        $rows = [];
        foreach ($tasks as $tpl) {
            $runs = random_int(40, 90);
            $hash = sha1($tpl['task']);

            for ($i = 0; $i < $runs; $i++) {
                $jitter = random_int(-25, 180) / 100;
                $duration = max(2, (int) round($tpl['base_duration'] * (1 + $jitter)));

                $roll = random_int(1, 100);
                if ($roll <= $tpl['fail_rate']) {
                    $status = 'failed';
                    $exitCode = [1, 1, 2, 127][array_rand([1, 1, 2, 127])];
                } elseif ($roll <= $tpl['fail_rate'] + $tpl['skip_rate']) {
                    $status = 'skipped';
                    $exitCode = null;
                    $duration = null;
                } else {
                    $status = 'processed';
                    $exitCode = 0;
                }

                $offsetSeconds = random_int(0, 86_400);
                $occurredAt = $start->addSeconds($offsetSeconds);

                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'task' => $tpl['task'],
                    'task_hash' => $hash,
                    'schedule' => $tpl['schedule'],
                    'schedule_summary' => $tpl['schedule_summary'],
                    'next_run_at' => $tpl['next_run_at'],
                    'status' => $status,
                    'exit_code' => $exitCode,
                    'duration_ms' => $duration,
                    'threshold_ms' => null,
                    'output' => $status === 'failed'
                        ? "Error: process exited with code {$exitCode}"
                        : ($status === 'skipped' ? 'Skipped by withoutOverlapping()' : "Completed in {$duration}ms"),
                    'environment' => $environments[array_rand($environments)],
                    'occurred_at' => $occurredAt,
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            ScheduledTaskRun::query()->insert($chunk);
        }
    }

    private function seedMail(Project $project, CarbonImmutable $start): void
    {
        $mailables = [
            [
                'class' => 'App\\Mail\\OrderShipped',
                'subject' => 'Your order has shipped',
                'base_duration' => 280,
                'mailer' => 'smtp',
                'source_type' => 'job',
                'source_label' => 'App\\Jobs\\SendShipmentNotice',
            ],
            [
                'class' => 'App\\Mail\\WelcomeEmail',
                'subject' => 'Welcome to Acme',
                'base_duration' => 140,
                'mailer' => 'smtp',
                'source_type' => 'controller',
                'source_label' => 'App\\Http\\Controllers\\Auth\\RegisterController@store',
            ],
            [
                'class' => 'App\\Mail\\PasswordResetEmail',
                'subject' => 'Reset your password',
                'base_duration' => 90,
                'mailer' => 'smtp',
                'source_type' => 'controller',
                'source_label' => 'App\\Http\\Controllers\\Auth\\ForgotPasswordController@sendResetLinkEmail',
            ],
            [
                'class' => 'App\\Mail\\InvoiceReady',
                'subject' => 'Your invoice is ready',
                'base_duration' => 420,
                'mailer' => 'postmark',
                'source_type' => 'job',
                'source_label' => 'App\\Jobs\\GenerateInvoice',
            ],
            [
                'class' => 'App\\Mail\\WeeklyDigest',
                'subject' => 'Your weekly digest',
                'base_duration' => 1_240,
                'mailer' => 'ses',
                'source_type' => 'schedule',
                'source_label' => 'php artisan digest:weekly',
            ],
            [
                'class' => 'App\\Mail\\PaymentReceived',
                'subject' => 'Payment received',
                'base_duration' => 180,
                'mailer' => 'postmark',
                'source_type' => 'job',
                'source_label' => 'App\\Jobs\\ProcessPayment',
            ],
            [
                'class' => 'App\\Mail\\AccountSuspended',
                'subject' => 'Your account has been suspended',
                'base_duration' => 220,
                'mailer' => 'smtp',
                'source_type' => 'command',
                'source_label' => 'php artisan accounts:suspend',
            ],
        ];

        $environments = ['production', 'production', 'production', 'staging'];
        $rows = [];

        foreach ($mailables as $tpl) {
            $sends = random_int(40, 110);

            for ($i = 0; $i < $sends; $i++) {
                $jitter = random_int(-25, 220) / 100;
                $duration = max(8, (int) round($tpl['base_duration'] * (1 + $jitter)));

                $recipientsCount = random_int(1, 4);
                $attachments = random_int(0, 100) <= 15 ? random_int(1, 3) : 0;

                $offsetSeconds = random_int(0, 86_400);
                $occurredAt = $start->addSeconds($offsetSeconds);

                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'trace_id' => null,
                    'mailable_class' => $tpl['class'],
                    'mailer' => $tpl['mailer'],
                    'subject' => $tpl['subject'],
                    'from_address' => 'no-reply@acme.test',
                    'from_name' => 'Acme',
                    'recipients_to' => null,
                    'recipients_cc' => null,
                    'recipients_bcc' => null,
                    'recipients_count' => $recipientsCount,
                    'attachments_count' => $attachments,
                    'queue' => $tpl['source_type'] === 'job' ? 'mail' : null,
                    'status' => 'sent',
                    'duration_ms' => $duration,
                    'source_type' => $tpl['source_type'],
                    'source_id' => null,
                    'source_label' => $tpl['source_label'],
                    'environment' => $environments[array_rand($environments)],
                    'occurred_at' => $occurredAt,
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            MailSend::query()->insert($chunk);
        }
    }

    private function seedNotifications(Project $project, CarbonImmutable $start): void
    {
        $notifications = [
            [
                'class' => 'App\\Notifications\\OrderShippedNotification',
                'channels' => ['mail', 'database'],
                'base_duration' => 240,
                'source_type' => 'job',
                'source_label' => 'App\\Jobs\\ShipOrder',
            ],
            [
                'class' => 'App\\Notifications\\PaymentFailedNotification',
                'channels' => ['mail', 'slack', 'database'],
                'base_duration' => 320,
                'source_type' => 'job',
                'source_label' => 'App\\Jobs\\ProcessPayment',
            ],
            [
                'class' => 'App\\Notifications\\NewCommentNotification',
                'channels' => ['database', 'broadcast'],
                'base_duration' => 60,
                'source_type' => 'controller',
                'source_label' => 'App\\Http\\Controllers\\CommentsController@store',
            ],
            [
                'class' => 'App\\Notifications\\TwoFactorCodeNotification',
                'channels' => ['mail', 'nexmo'],
                'base_duration' => 140,
                'source_type' => 'controller',
                'source_label' => 'App\\Http\\Controllers\\Auth\\TwoFactorController@send',
            ],
            [
                'class' => 'App\\Notifications\\SystemAlertNotification',
                'channels' => ['slack'],
                'base_duration' => 410,
                'source_type' => 'schedule',
                'source_label' => 'php artisan monitor:alerts',
            ],
            [
                'class' => 'App\\Notifications\\InvoiceOverdueNotification',
                'channels' => ['mail', 'database'],
                'base_duration' => 280,
                'source_type' => 'schedule',
                'source_label' => 'php artisan invoices:remind',
            ],
        ];

        $environments = ['production', 'production', 'production', 'staging'];
        $rows = [];

        foreach ($notifications as $tpl) {
            $base = random_int(40, 110);

            for ($i = 0; $i < $base; $i++) {
                foreach ($tpl['channels'] as $channel) {
                    $jitter = random_int(-30, 220) / 100;
                    $duration = max(5, (int) round($tpl['base_duration'] * (1 + $jitter)));

                    $offsetSeconds = random_int(0, 86_400);
                    $occurredAt = $start->addSeconds($offsetSeconds);

                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'project_id' => $project->id,
                        'trace_id' => null,
                        'notification_class' => $tpl['class'],
                        'channel' => $channel,
                        'notifiable_type' => 'App\\Models\\User',
                        'notifiable_id' => (string) random_int(1, 1000),
                        'queue' => $tpl['source_type'] === 'job' ? 'notifications' : null,
                        'status' => 'sent',
                        'duration_ms' => $duration,
                        'source_type' => $tpl['source_type'],
                        'source_id' => null,
                        'source_label' => $tpl['source_label'],
                        'environment' => $environments[array_rand($environments)],
                        'occurred_at' => $occurredAt,
                        'created_at' => $occurredAt,
                        'updated_at' => $occurredAt,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            NotificationSend::query()->insert($chunk);
        }
    }

    private function seedCache(Project $project, CarbonImmutable $start): void
    {
        $keys = [
            ['key' => 'site.settings', 'reads' => 1100, 'writes' => 28, 'deletes' => 4, 'hit_rate' => 96, 'fail_rate' => 0],
            ['key' => 'projects.list', 'reads' => 720, 'writes' => 60, 'deletes' => 12, 'hit_rate' => 88, 'fail_rate' => 1],
            ['key' => 'user.permissions.{id}', 'reads' => 980, 'writes' => 150, 'deletes' => 30, 'hit_rate' => 84, 'fail_rate' => 1],
            ['key' => 'team.members.{slug}', 'reads' => 360, 'writes' => 30, 'deletes' => 6, 'hit_rate' => 91, 'fail_rate' => 0],
            ['key' => 'projects.dashboard.metrics', 'reads' => 540, 'writes' => 120, 'deletes' => 8, 'hit_rate' => 77, 'fail_rate' => 0],
            ['key' => 'flags.feature.web', 'reads' => 410, 'writes' => 14, 'deletes' => 1, 'hit_rate' => 99, 'fail_rate' => 0],
            ['key' => 'billing.invoice.{id}', 'reads' => 220, 'writes' => 90, 'deletes' => 38, 'hit_rate' => 65, 'fail_rate' => 5],
            ['key' => 'queue.config', 'reads' => 130, 'writes' => 10, 'deletes' => 2, 'hit_rate' => 95, 'fail_rate' => 0],
            ['key' => 'rate-limit:{ip}:{route}', 'reads' => 1450, 'writes' => 320, 'deletes' => 80, 'hit_rate' => 71, 'fail_rate' => 2],
            ['key' => 'session:{id}', 'reads' => 1200, 'writes' => 800, 'deletes' => 60, 'hit_rate' => 82, 'fail_rate' => 1],
            ['key' => 'menu.navigation', 'reads' => 360, 'writes' => 12, 'deletes' => 1, 'hit_rate' => 98, 'fail_rate' => 0],
            ['key' => 'reports.weekly.{year}.{week}', 'reads' => 90, 'writes' => 18, 'deletes' => 6, 'hit_rate' => 60, 'fail_rate' => 3],
        ];

        $environments = ['production', 'production', 'production', 'staging'];
        $stores = ['redis', 'redis', 'redis', 'database'];

        $rows = [];

        foreach ($keys as $tpl) {
            $key = (string) $tpl['key'];
            $hits = (int) round($tpl['reads'] * ($tpl['hit_rate'] / 100));
            $misses = max(0, $tpl['reads'] - $hits);

            $events = [
                ['op' => 'hit', 'count' => $hits, 'success_rate' => 100],
                ['op' => 'miss', 'count' => $misses, 'success_rate' => 100],
                ['op' => 'write', 'count' => $tpl['writes'], 'success_rate' => 100 - max(0, intdiv($tpl['fail_rate'], 2))],
                ['op' => 'delete', 'count' => $tpl['deletes'], 'success_rate' => 100 - $tpl['fail_rate']],
            ];

            foreach ($events as $event) {
                for ($i = 0; $i < $event['count']; $i++) {
                    $succeeded = random_int(1, 100) <= $event['success_rate'];

                    $duration = match ($event['op']) {
                        'hit' => random_int(0, 3),
                        'miss' => random_int(0, 4),
                        'write' => random_int(1, 8),
                        'delete' => random_int(1, 6),
                        default => 1,
                    };

                    $offsetSeconds = random_int(0, 86_400);
                    $occurredAt = $start->addSeconds($offsetSeconds);

                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'project_id' => $project->id,
                        'trace_id' => null,
                        'key' => $key,
                        'store' => $stores[array_rand($stores)],
                        'operation' => $event['op'],
                        'succeeded' => $succeeded,
                        'duration_ms' => $duration,
                        'environment' => $environments[array_rand($environments)],
                        'occurred_at' => $occurredAt,
                        'created_at' => $occurredAt,
                        'updated_at' => $occurredAt,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            CacheEvent::query()->insert($chunk);
        }
    }

    private function seedOutgoingRequests(Project $project, CarbonImmutable $start): void
    {
        $hosts = [
            ['host' => 'api.stripe.com', 'count' => 320, 'avg_ms' => 180, 'methods' => ['POST', 'GET'], 'paths' => ['/v1/charges', '/v1/customers', '/v1/invoices', '/v1/payment_intents'], 'success_rate' => 99, 'rate_400' => 1, 'rate_500' => 0],
            ['host' => 'api.cloudflare.com', 'count' => 280, 'avg_ms' => 95, 'methods' => ['GET', 'POST', 'PUT', 'DELETE'], 'paths' => ['/client/v4/zones', '/client/v4/zones/{id}/dns_records', '/client/v4/accounts/{id}/storage', '/client/v4/user'], 'success_rate' => 98, 'rate_400' => 1, 'rate_500' => 1],
            ['host' => 'api.github.com', 'count' => 220, 'avg_ms' => 240, 'methods' => ['GET', 'POST'], 'paths' => ['/repos/{owner}/{repo}', '/users/{user}/repos', '/repos/{owner}/{repo}/pulls', '/search/code'], 'success_rate' => 96, 'rate_400' => 3, 'rate_500' => 1],
            ['host' => 'api.openai.com', 'count' => 190, 'avg_ms' => 1240, 'methods' => ['POST'], 'paths' => ['/v1/chat/completions', '/v1/embeddings', '/v1/images/generations'], 'success_rate' => 92, 'rate_400' => 4, 'rate_500' => 3],
            ['host' => 'api.digitalocean.com', 'count' => 175, 'avg_ms' => 220, 'methods' => ['GET', 'POST', 'DELETE'], 'paths' => ['/v2/droplets', '/v2/databases', '/v2/load_balancers', '/v2/account'], 'success_rate' => 97, 'rate_400' => 2, 'rate_500' => 1],
            ['host' => 'api.linode.com', 'count' => 150, 'avg_ms' => 260, 'methods' => ['GET', 'POST'], 'paths' => ['/v4/linode/instances', '/v4/databases', '/v4/account'], 'success_rate' => 96, 'rate_400' => 3, 'rate_500' => 1],
            ['host' => 'api.vultr.com', 'count' => 135, 'avg_ms' => 280, 'methods' => ['GET', 'POST'], 'paths' => ['/v2/instances', '/v2/account', '/v2/billing/list'], 'success_rate' => 95, 'rate_400' => 4, 'rate_500' => 1],
            ['host' => 'hooks.slack.com', 'count' => 410, 'avg_ms' => 145, 'methods' => ['POST'], 'paths' => ['/services/{token}'], 'success_rate' => 99, 'rate_400' => 1, 'rate_500' => 0],
            ['host' => 'api.mailgun.net', 'count' => 380, 'avg_ms' => 210, 'methods' => ['POST'], 'paths' => ['/v3/{domain}/messages', '/v3/{domain}/events'], 'success_rate' => 98, 'rate_400' => 1, 'rate_500' => 1],
            ['host' => 'api.sendgrid.com', 'count' => 340, 'avg_ms' => 180, 'methods' => ['POST', 'GET'], 'paths' => ['/v3/mail/send', '/v3/templates', '/v3/stats'], 'success_rate' => 98, 'rate_400' => 1, 'rate_500' => 1],
            ['host' => 'api.postmarkapp.com', 'count' => 220, 'avg_ms' => 165, 'methods' => ['POST'], 'paths' => ['/email', '/email/batch'], 'success_rate' => 99, 'rate_400' => 1, 'rate_500' => 0],
            ['host' => 'api.twilio.com', 'count' => 195, 'avg_ms' => 320, 'methods' => ['POST', 'GET'], 'paths' => ['/2010-04-01/Accounts/{sid}/Messages.json', '/2010-04-01/Accounts/{sid}/Calls.json'], 'success_rate' => 97, 'rate_400' => 2, 'rate_500' => 1],
            ['host' => 'api.intercom.io', 'count' => 160, 'avg_ms' => 290, 'methods' => ['GET', 'POST', 'PUT'], 'paths' => ['/contacts', '/conversations', '/articles'], 'success_rate' => 96, 'rate_400' => 3, 'rate_500' => 1],
            ['host' => 'api.algolia.net', 'count' => 510, 'avg_ms' => 75, 'methods' => ['GET', 'POST'], 'paths' => ['/1/indexes/{index}/query', '/1/indexes/{index}/objects'], 'success_rate' => 99, 'rate_400' => 1, 'rate_500' => 0],
            ['host' => 'api.pusher.com', 'count' => 290, 'avg_ms' => 105, 'methods' => ['POST'], 'paths' => ['/apps/{id}/events', '/apps/{id}/channels'], 'success_rate' => 99, 'rate_400' => 1, 'rate_500' => 0],
            ['host' => 'api.ably.io', 'count' => 240, 'avg_ms' => 115, 'methods' => ['POST', 'GET'], 'paths' => ['/channels/{name}/messages', '/stats'], 'success_rate' => 98, 'rate_400' => 1, 'rate_500' => 1],
            ['host' => 'api.heroku.com', 'count' => 95, 'avg_ms' => 350, 'methods' => ['GET', 'POST'], 'paths' => ['/apps', '/apps/{id}/dynos', '/apps/{id}/releases'], 'success_rate' => 95, 'rate_400' => 3, 'rate_500' => 2],
            ['host' => 'api.bunny.net', 'count' => 120, 'avg_ms' => 140, 'methods' => ['GET', 'POST'], 'paths' => ['/pullzone', '/storagezone', '/purge'], 'success_rate' => 98, 'rate_400' => 2, 'rate_500' => 0],
            ['host' => 'api.fastly.com', 'count' => 90, 'avg_ms' => 130, 'methods' => ['GET', 'POST', 'PURGE'], 'paths' => ['/service/{id}', '/service/{id}/version/{ver}', '/purge/{host}'], 'success_rate' => 98, 'rate_400' => 1, 'rate_500' => 1],
            ['host' => 'api.datadoghq.com', 'count' => 460, 'avg_ms' => 88, 'methods' => ['POST'], 'paths' => ['/api/v1/series', '/api/v1/events', '/api/v1/check_run'], 'success_rate' => 99, 'rate_400' => 1, 'rate_500' => 0],
            ['host' => 'sentry.io', 'count' => 510, 'avg_ms' => 95, 'methods' => ['POST'], 'paths' => ['/api/{id}/store/', '/api/{id}/envelope/'], 'success_rate' => 99, 'rate_400' => 1, 'rate_500' => 0],
            ['host' => 'api.segment.io', 'count' => 380, 'avg_ms' => 110, 'methods' => ['POST'], 'paths' => ['/v1/track', '/v1/identify', '/v1/batch'], 'success_rate' => 99, 'rate_400' => 1, 'rate_500' => 0],
            ['host' => 'api.mixpanel.com', 'count' => 290, 'avg_ms' => 120, 'methods' => ['POST'], 'paths' => ['/track', '/engage', '/import'], 'success_rate' => 98, 'rate_400' => 1, 'rate_500' => 1],
            ['host' => 'maps.googleapis.com', 'count' => 175, 'avg_ms' => 220, 'methods' => ['GET'], 'paths' => ['/maps/api/geocode/json', '/maps/api/directions/json', '/maps/api/place/details/json'], 'success_rate' => 98, 'rate_400' => 2, 'rate_500' => 0],
            ['host' => 'graph.facebook.com', 'count' => 130, 'avg_ms' => 260, 'methods' => ['GET', 'POST'], 'paths' => ['/v18.0/me', '/v18.0/{id}/feed', '/v18.0/{id}/messages'], 'success_rate' => 95, 'rate_400' => 4, 'rate_500' => 1],
            ['host' => 'api.twitter.com', 'count' => 110, 'avg_ms' => 280, 'methods' => ['GET', 'POST'], 'paths' => ['/2/tweets', '/2/users/{id}', '/2/users/by/username/{name}'], 'success_rate' => 93, 'rate_400' => 5, 'rate_500' => 2],
            ['host' => 'api.notion.com', 'count' => 145, 'avg_ms' => 310, 'methods' => ['GET', 'POST', 'PATCH'], 'paths' => ['/v1/pages', '/v1/databases/{id}/query', '/v1/blocks/{id}/children'], 'success_rate' => 96, 'rate_400' => 3, 'rate_500' => 1],
            ['host' => 'api.airtable.com', 'count' => 95, 'avg_ms' => 240, 'methods' => ['GET', 'POST', 'PATCH'], 'paths' => ['/v0/{base}/{table}', '/v0/meta/bases/{base}/tables'], 'success_rate' => 97, 'rate_400' => 2, 'rate_500' => 1],
            ['host' => 'api.dropboxapi.com', 'count' => 80, 'avg_ms' => 380, 'methods' => ['POST'], 'paths' => ['/2/files/upload', '/2/files/list_folder', '/2/sharing/create_shared_link'], 'success_rate' => 96, 'rate_400' => 3, 'rate_500' => 1],
            ['host' => 'www.googleapis.com', 'count' => 165, 'avg_ms' => 290, 'methods' => ['GET', 'POST'], 'paths' => ['/drive/v3/files', '/calendar/v3/calendars/{id}/events', '/oauth2/v3/userinfo'], 'success_rate' => 97, 'rate_400' => 2, 'rate_500' => 1],
            ['host' => 'login.microsoftonline.com', 'count' => 105, 'avg_ms' => 320, 'methods' => ['POST'], 'paths' => ['/common/oauth2/v2.0/token', '/common/oauth2/v2.0/authorize'], 'success_rate' => 97, 'rate_400' => 2, 'rate_500' => 1],
            ['host' => 'api.amazonaws.com', 'count' => 230, 'avg_ms' => 175, 'methods' => ['GET', 'POST', 'PUT'], 'paths' => ['/2010-08-01/functions/{name}/invocations', '/sns/publish', '/sqs/sendmessage'], 'success_rate' => 98, 'rate_400' => 1, 'rate_500' => 1],
            ['host' => 'api.shopify.com', 'count' => 180, 'avg_ms' => 310, 'methods' => ['GET', 'POST'], 'paths' => ['/admin/api/2024-01/orders.json', '/admin/api/2024-01/products.json', '/admin/api/2024-01/customers.json'], 'success_rate' => 96, 'rate_400' => 3, 'rate_500' => 1],
        ];

        $sources = [
            ['type' => 'Job', 'labels' => ['ProcessPayment', 'SendInvoice', 'SyncContacts', 'UploadAsset', 'RenderReport']],
            ['type' => 'Request', 'labels' => ['POST /api/orders', 'POST /api/checkout', 'GET /api/users', 'POST /webhooks/stripe']],
            ['type' => 'Command', 'labels' => ['sync:contacts', 'reports:weekly', 'cache:warm', 'newsletter:send']],
            ['type' => 'Scheduled', 'labels' => ['daily-digest', 'hourly-sync', 'reconcile-balances']],
        ];

        $environments = ['production', 'production', 'production', 'staging'];
        $rows = [];

        foreach ($hosts as $tpl) {
            $host = (string) $tpl['host'];
            $count = (int) $tpl['count'];
            $avg = (int) $tpl['avg_ms'];
            $methods = (array) $tpl['methods'];
            $paths = (array) $tpl['paths'];
            $successRate = (int) $tpl['success_rate'];
            $rate400 = (int) $tpl['rate_400'];
            $rate500 = (int) $tpl['rate_500'];

            for ($i = 0; $i < $count; $i++) {
                $method = $methods[array_rand($methods)];
                $path = $paths[array_rand($paths)];
                $url = 'https://'.$host.$path;

                $r = random_int(1, 100);
                if ($r <= $successRate) {
                    $candidates = [200, 200, 200, 200, 201, 204];
                    if (in_array($method, ['GET'], true) && random_int(1, 100) <= 8) {
                        $candidates = [301, 302, 304];
                    }
                    $status = $candidates[array_rand($candidates)];
                    $duration = (int) max(2, round($avg * (1 + random_int(-30, 80) / 100)));
                } elseif ($r <= $successRate + $rate400) {
                    $status = [400, 401, 403, 404, 409, 422, 429][array_rand([400, 401, 403, 404, 409, 422, 429])];
                    $duration = (int) max(2, round($avg * (1 + random_int(-40, 30) / 100)));
                } elseif ($r <= $successRate + $rate400 + $rate500) {
                    $status = [500, 502, 503, 504][array_rand([500, 502, 503, 504])];
                    $duration = (int) max(2, round($avg * (1 + random_int(50, 350) / 100)));
                } else {
                    $status = null;
                    $duration = random_int(1500, 5000);
                }

                if (random_int(1, 100) <= 2) {
                    $duration = (int) round($duration * random_int(3, 8));
                }

                $offsetSeconds = random_int(0, 86_400);
                $occurredAt = $start->addSeconds($offsetSeconds);

                $source = $sources[array_rand($sources)];
                $sourceLabel = $source['labels'][array_rand($source['labels'])];

                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'trace_id' => null,
                    'method' => $method,
                    'host' => $host,
                    'url' => $url,
                    'status_code' => $status,
                    'duration_ms' => $duration,
                    'request_size_bytes' => random_int(120, 4_000),
                    'response_size_bytes' => $status === null ? null : random_int(200, 18_000),
                    'source_type' => $source['type'],
                    'source_id' => 'src_'.Str::random(10),
                    'source_label' => $sourceLabel,
                    'environment' => $environments[array_rand($environments)],
                    'occurred_at' => $occurredAt,
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            OutgoingRequest::query()->insert($chunk);
        }
    }

    /**
     * @param  list<User>  $teamUsers
     */
    private function seedLogs(Project $project, CarbonImmutable $start, array $teamUsers): void
    {
        $environments = ['production', 'production', 'production', 'staging'];

        $templates = [
            ['level' => 'info', 'source_type' => 'command', 'source' => 'backup:sweep', 'message' => 'Backup still running on server', 'context_factory' => 'backup'],
            ['level' => 'info', 'source_type' => 'command', 'source' => 'queue:work', 'message' => 'Processed: App\\Jobs\\ProcessPayment', 'context_factory' => 'queue'],
            ['level' => 'info', 'source_type' => 'command', 'source' => 'cache:warm', 'message' => 'Warming cache for tenant', 'context_factory' => 'tenant'],
            ['level' => 'info', 'source_type' => 'command', 'source' => 'horizon:supervise', 'message' => 'Supervisor started', 'context_factory' => 'supervisor'],
            ['level' => 'info', 'source_type' => 'job', 'source' => 'App\\Jobs\\ServerProvision', 'message' => "array (\n  'message' => 'Updated proxy docker compose',\n  'server' => '46',\n)", 'context_factory' => 'server'],
            ['level' => 'info', 'source_type' => 'job', 'source' => 'App\\Jobs\\SyncContacts', 'message' => 'Synced 250 contacts from Mailgun', 'context_factory' => 'sync'],
            ['level' => 'warning', 'source_type' => 'job', 'source' => 'App\\Jobs\\SiteBackup', 'message' => 'Cannot perform backup on site with status: sleeping', 'context_factory' => 'site'],
            ['level' => 'warning', 'source_type' => 'job', 'source' => 'App\\Jobs\\DispatchWebhook', 'message' => 'Webhook delivery delayed: rate limit hit', 'context_factory' => 'webhook'],
            ['level' => 'warning', 'source_type' => 'system', 'source' => 'database.slow_query', 'message' => 'Slow query exceeded 1500ms', 'context_factory' => 'query'],
            ['level' => 'warning', 'source_type' => 'request', 'source' => 'POST /api/checkout', 'message' => 'Idempotency key reused for checkout request', 'context_factory' => 'checkout'],
            ['level' => 'error', 'source_type' => 'job', 'source' => 'App\\Jobs\\RenderReport', 'message' => 'Failed to render report: missing template', 'context_factory' => 'report'],
            ['level' => 'error', 'source_type' => 'request', 'source' => 'POST /api/payments', 'message' => 'Payment gateway returned 502', 'context_factory' => 'payment'],
            ['level' => 'error', 'source_type' => 'system', 'source' => 'redis.connection', 'message' => 'Lost connection to redis primary, failing over', 'context_factory' => 'redis'],
            ['level' => 'notice', 'source_type' => 'scheduled', 'source' => 'daily-digest', 'message' => 'Daily digest sent to 1,204 recipients', 'context_factory' => 'digest'],
            ['level' => 'notice', 'source_type' => 'system', 'source' => 'feature.flag', 'message' => 'Feature flag toggled', 'context_factory' => 'flag'],
            ['level' => 'debug', 'source_type' => 'request', 'source' => 'GET /api/users', 'message' => 'Cache hit: user.permissions', 'context_factory' => 'permissions'],
            ['level' => 'debug', 'source_type' => 'command', 'source' => 'telescope:prune', 'message' => 'Pruned 4,210 stale entries', 'context_factory' => 'prune'],
            ['level' => 'critical', 'source_type' => 'system', 'source' => 'disk.monitor', 'message' => 'Disk usage above 95%', 'context_factory' => 'disk'],
        ];

        $rows = [];
        $now = $start->addDay();
        $window = $now->diffInSeconds($start);

        foreach ($templates as $tpl) {
            $count = match ($tpl['level']) {
                'critical' => random_int(2, 6),
                'error' => random_int(20, 60),
                'warning' => random_int(40, 90),
                'notice' => random_int(15, 40),
                'debug' => random_int(60, 120),
                default => random_int(120, 260),
            };

            for ($i = 0; $i < $count; $i++) {
                $offsetSeconds = random_int(0, max(1, (int) abs($window)));
                $occurredAt = $start->addSeconds($offsetSeconds);

                $user = $teamUsers !== [] && random_int(1, 100) <= 70
                    ? $teamUsers[array_rand($teamUsers)]
                    : null;

                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'trace_id' => null,
                    'level' => $tpl['level'],
                    'message' => $tpl['message'],
                    'source_type' => $tpl['source_type'],
                    'source_label' => $tpl['source'],
                    'user_name' => $user?->name,
                    'context' => json_encode($this->buildLogContext($tpl['context_factory'])),
                    'environment' => $environments[array_rand($environments)],
                    'occurred_at' => $occurredAt,
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            LogEntry::query()->insert($chunk);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLogContext(string $kind): array
    {
        return match ($kind) {
            'backup' => [
                'backup_id' => random_int(100_000, 999_999),
                'site_id' => random_int(10_000, 99_999),
                'domain' => Arr::random(['mseducationacademy.in', 'shopfly.io', 'acme.test', 'tenant-3a.flywp.test']),
            ],
            'queue' => [
                'job_id' => 'jb_'.Str::random(10),
                'queue' => Arr::random(['default', 'high', 'webhooks', 'media']),
                'attempts' => random_int(1, 3),
            ],
            'tenant' => [
                'tenant_id' => 'ten_'.Str::random(8),
                'keys_warmed' => random_int(20, 250),
            ],
            'supervisor' => [
                'supervisor' => 'horizon-supervisor-1',
                'processes' => random_int(2, 10),
            ],
            'server' => [
                'server_id' => random_int(10, 90),
                'role' => Arr::random(['proxy', 'app', 'db', 'cache']),
            ],
            'sync' => [
                'provider' => Arr::random(['mailgun', 'sendgrid', 'postmark']),
                'count' => random_int(50, 800),
                'duration_ms' => random_int(800, 6000),
            ],
            'site' => [
                'site_id' => random_int(10_000, 99_999),
                'status' => 'sleeping',
                'domain' => Arr::random(['old-blog.test', 'archive.acme.test', 'staging.tenant-7.flywp.test']),
            ],
            'webhook' => [
                'webhook_id' => 'wh_'.Str::random(10),
                'attempt' => random_int(2, 6),
                'next_retry_in_seconds' => random_int(15, 600),
            ],
            'query' => [
                'duration_ms' => random_int(1500, 9000),
                'connection' => Arr::random(['mysql', 'mysql-readonly']),
                'sql_fingerprint' => 'select * from orders where customer_id = ?',
            ],
            'checkout' => [
                'idempotency_key' => 'idk_'.Str::random(12),
                'customer_id' => 'cus_'.Str::random(8),
            ],
            'report' => [
                'report' => Arr::random(['weekly-revenue', 'monthly-cohorts', 'daily-active-users']),
                'reason' => 'TemplateNotFoundException',
            ],
            'payment' => [
                'provider' => 'stripe',
                'status' => 502,
                'charge_id' => 'ch_'.Str::random(14),
            ],
            'redis' => [
                'host' => Arr::random(['10.0.4.12', '10.0.4.13']),
                'failover_to' => Arr::random(['10.0.4.20', '10.0.4.21']),
            ],
            'digest' => [
                'recipients' => random_int(800, 2_500),
                'campaign' => 'daily-digest-'.now()->format('Ymd'),
            ],
            'flag' => [
                'flag' => Arr::random(['checkout_v2', 'new_dashboard', 'edge_cache']),
                'enabled' => (bool) random_int(0, 1),
            ],
            'permissions' => [
                'user_id' => random_int(1, 5000),
                'cache_key' => 'user.permissions.'.random_int(1, 5000),
            ],
            'prune' => [
                'pruned' => random_int(1000, 8000),
                'before' => now()->subDays(7)->toDateString(),
            ],
            'disk' => [
                'mount' => '/',
                'used_pct' => random_int(95, 99),
                'host' => Arr::random(['app-prod-1', 'app-prod-2', 'app-prod-3']),
            ],
            default => [],
        };
    }

    /**
     * @return list<array{id:string,email:string,name:string}>
     */
    private function buildEndUserPool(): array
    {
        $names = [
            'Felix Rose-Collins',
            'Danny Verbeek',
            'Harold Datus',
            'Denis Gomes Franco',
            'Kushal Karki',
            'Suttichai Naksoda',
            'Vedat Uikani',
            'Marta Lindqvist',
            'Carlos Mendoza',
            'Aiko Tanaka',
            'Priya Raman',
            'Sebastian Klein',
            'Olusola Adebayo',
            'Lena Petrov',
            'Wei Zhang',
            'Hugo Bernard',
            'Amelia Foster',
            'Kai Stevenson',
            'Noemi Schulte',
            'Jamal Robinson',
            'Ines Cardoso',
            'Tobias Hartmann',
            'Sara Khoury',
            'Min-jun Park',
            'Beatriz Costa',
        ];

        $domains = ['ranktracker.com', 'dotnow.nl', 'moderngeeks.com', 'denisgomes.com.br', 'codethrive.io', 'flywp.test', 'acme.test', 'shopfly.io'];

        $users = [];
        foreach ($names as $i => $name) {
            $first = strtolower(explode(' ', $name)[0]);
            $first = preg_replace('/[^a-z]/', '', $first);
            $users[] = [
                'id' => 'usr_'.Str::random(10),
                'email' => $first.'@'.$domains[$i % count($domains)],
                'name' => $name,
            ];
        }

        return $users;
    }
}
