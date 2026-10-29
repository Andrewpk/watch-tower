<?php

use App\Jobs\DeleteProjectData;

/*
|--------------------------------------------------------------------------
| Docker image
|--------------------------------------------------------------------------
|
| CI does not build the image, so these tests read the Dockerfile and the
| files it embeds as heredocs, and check the parts that other code depends on:
| what supervisord runs, how it stops, and that the stop window fits the
| longest queued job.
|
*/

const BASE_IMAGE_CMD = 'frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile';

function dockerfile(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/Dockerfile');
}

/**
 * The body of the `COPY <<'EOF' <target>` heredoc that writes the given file.
 */
function dockerfileHeredoc(string $target): string
{
    preg_match('/COPY --chmod=\d+ <<\'EOF\' '.preg_quote($target, '/')."\n(.*?)\nEOF\n/s", dockerfile(), $matches);

    if (! isset($matches[1])) {
        throw new RuntimeException("No heredoc in the Dockerfile writes {$target}.");
    }

    return $matches[1];
}

/**
 * Parse the supervisord config into [section => [key => value]].
 *
 * @return array<string, array<string, string>>
 */
function supervisorConfig(): array
{
    $sections = [];
    $current = null;

    foreach (explode("\n", dockerfileHeredoc('/etc/supervisor/watch-tower.conf')) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, ';')) {
            continue;
        }

        if (preg_match('/^\[(.+)\]$/', $line, $matches)) {
            $current = $matches[1];
            $sections[$current] = [];

            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $sections[$current][trim($key)] = trim($value);
    }

    return $sections;
}

it('starts supervisord in the foreground as the container command', function () {
    $config = supervisorConfig();

    expect(dockerfile())->toContain('CMD ["supervisord", "-n", "-c", "/etc/supervisor/watch-tower.conf"]')
        ->and($config['supervisord']['nodaemon'])->toBe('true')
        ->and($config['supervisord']['pidfile'])->toStartWith('/tmp/')
        ->and($config['supervisord']['childlogdir'])->toStartWith('/tmp')
        ->and($config['unix_http_server']['file'])->toStartWith('/tmp/')
        ->and($config['unix_http_server']['chmod'])->toBe('0700');
});

it('sets fixed socket credentials that supervisorctl shares, so supervisord does not log a CRIT', function () {
    $config = supervisorConfig();

    expect($config['unix_http_server'])->toHaveKeys(['username', 'password'])
        ->and($config['supervisorctl']['username'])->toBe($config['unix_http_server']['username'])
        ->and($config['supervisorctl']['password'])->toBe($config['unix_http_server']['password']);
});

it('installs supervisor without recommended packages', function () {
    expect(dockerfile())->toContain('apt-get install -y --no-install-recommends supervisor');
});

it('runs the web server with the base image command', function () {
    expect(supervisorConfig()['program:web']['command'])->toBe(BASE_IMAGE_CMD)
        ->and(supervisorConfig()['program:web']['autostart'])->toBe('true');
});

it('runs the queue worker unless RUN_QUEUE_WORKER is false', function () {
    $worker = supervisorConfig()['program:queue-worker'];

    expect($worker['command'])->toContain('queue:work')
        ->toContain('--tries=3')
        ->toContain('--max-time=')
        ->and($worker['autostart'])->toBe('%(ENV_RUN_QUEUE_WORKER)s');
});

it('makes the queue worker wait for migrations instead of crashing on an empty database', function () {
    $command = supervisorConfig()['program:queue-worker']['command'];

    expect($command)->toStartWith('sh -c ')
        ->toContain('until php /var/www/html/artisan migrate:status --pending=1')
        ->toContain('do sleep 10; done; exec php /var/www/html/artisan queue:work');
});

it('retries a failing worker, scheduler and listener many times before giving up', function () {
    $config = supervisorConfig();

    foreach (['program:queue-worker', 'program:scheduler', 'eventlistener:exit-on-fatal'] as $section) {
        expect((int) $config[$section]['startretries'])->toBeGreaterThanOrEqual(100);
    }
});

it('runs the scheduler unless RUN_SCHEDULER is false', function () {
    $scheduler = supervisorConfig()['program:scheduler'];

    expect($scheduler['command'])->toContain('schedule:work --whisper')
        ->and($scheduler['autostart'])->toBe('%(ENV_RUN_SCHEDULER)s');
});

it('turns both optional programs on in the image environment', function () {
    expect(dockerfile())->toContain('RUN_QUEUE_WORKER=true')
        ->toContain('RUN_SCHEDULER=true');
});

it('logs every program to the container output and signals whole process groups', function () {
    foreach (['web', 'queue-worker', 'scheduler'] as $name) {
        $program = supervisorConfig()["program:{$name}"];

        expect($program)->toMatchArray([
            'stdout_logfile' => '/dev/stdout',
            'stdout_logfile_maxbytes' => '0',
            'stderr_logfile' => '/dev/stderr',
            'stderr_logfile_maxbytes' => '0',
            'stopasgroup' => 'true',
            'killasgroup' => 'true',
            'autorestart' => 'true',
        ]);
    }
});

it('puts all three programs in one group so that they stop together', function () {
    expect(supervisorConfig()['group:watch-tower']['programs'])->toBe('web,queue-worker,scheduler');
});

it('gives a running job time to finish before the queue worker is killed', function () {
    $jobTimeout = (new ReflectionClass(DeleteProjectData::class))->getDefaultProperties()['timeout'];

    expect((int) supervisorConfig()['program:queue-worker']['stopwaitsecs'])->toBeGreaterThan($jobTimeout);
});

it('stops the container when the web server gives up starting', function () {
    expect(supervisorConfig()['eventlistener:exit-on-fatal']['events'])->toBe('PROCESS_STATE_FATAL')
        ->and(supervisorConfig()['eventlistener:exit-on-fatal']['command'])->toBe('/usr/local/bin/watch-tower-exit-on-fatal');
});

it('ships an event listener that is valid sh', function () {
    $process = proc_open(['sh', '-n'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], dockerfileHeredoc('/usr/local/bin/watch-tower-exit-on-fatal')."\n");
    fclose($pipes[0]);
    $errors = stream_get_contents($pipes[2]);

    expect(proc_close($process))->toBe(0, $errors);
});

/**
 * Run the listener script against one PROCESS_STATE_FATAL event.
 *
 * The wrapper shell is the listener's parent, like supervisord is in the
 * container, and reports a TERM it receives.
 *
 * @return array{output: string, errors: string}
 */
function runExitOnFatalListener(string $payload): array
{
    $script = tempnam(sys_get_temp_dir(), 'listener');
    file_put_contents($script, dockerfileHeredoc('/usr/local/bin/watch-tower-exit-on-fatal')."\n");

    $header = 'ver:3.0 server:supervisor serial:1 pool:exit-on-fatal poolserial:1 eventname:PROCESS_STATE_FATAL len:'.strlen($payload);

    $process = proc_open(
        ['sh', '-c', 'trap "echo TERMED >&2" TERM; sh "$0"; :', $script],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    fwrite($pipes[0], "{$header}\n{$payload}");
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    proc_close($process);
    unlink($script);

    return ['output' => $output, 'errors' => $errors];
}

it('answers the supervisord event protocol and signals its parent when the web server gives up', function () {
    $payload = 'processname:web groupname:watch-tower from_state:BACKOFF';

    $result = runExitOnFatalListener($payload);

    expect($result['output'])->toBe("READY\nRESULT 2\nOKREADY\n")
        ->and($result['errors'])->toContain("supervisord gave up on the web server ({$payload}). Stopping the container.")
        ->toContain('TERMED');
});

it('logs but does not stop the container when the queue worker or scheduler gives up', function (string $program) {
    $payload = "processname:{$program} groupname:watch-tower from_state:BACKOFF";

    $result = runExitOnFatalListener($payload);

    expect($result['output'])->toBe("READY\nRESULT 2\nOKREADY\n")
        ->and($result['errors'])->toContain("supervisord gave up on a program ({$payload}). The container keeps running.")
        ->not->toContain('TERMED');
})->with(['queue-worker', 'scheduler']);
