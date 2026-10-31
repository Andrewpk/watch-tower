<?php

use Symfony\Component\Process\Process;

/**
 * A test run must never reach a telemetry or logs database that the machine happens to be configured with.
 */
it('blanks every split database setting for the test run', function () {
    $xml = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml');
    $blanked = [];

    foreach (['env', 'server'] as $kind) {
        foreach ($xml->php->{$kind} as $variable) {
            if (preg_match('/^DB_(TELEMETRY|LOGS)_/', (string) $variable['name'])) {
                $blanked[$kind][(string) $variable['name']] = [(string) $variable['value'], (string) $variable['force']];
            }
        }
    }

    $expected = [];

    foreach (['TELEMETRY', 'LOGS'] as $group) {
        foreach (['DATABASE', 'URL', 'HOST', 'PORT', 'USERNAME', 'PASSWORD'] as $setting) {
            $expected['env']["DB_{$group}_{$setting}"] = ['', 'true'];
            $expected['server']["DB_{$group}_{$setting}"] = ['', 'true'];
        }
    }

    expect($blanked)->toEqual($expected);
});

it('keeps the split connections in memory even when the process environment configures them', function () {
    $environment = [];

    foreach (['TELEMETRY', 'LOGS'] as $group) {
        $environment["DB_{$group}_DATABASE"] = '/nonexistent/'.strtolower($group).'.sqlite';
        $environment["DB_{$group}_URL"] = 'mysql://user:secret@db.invalid:3306/'.strtolower($group);
        $environment["DB_{$group}_HOST"] = 'db.invalid';
    }

    $process = new Process(
        [PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/pest', 'tests/Unit/DatabasesTest.php', '--filter', 'keeps in-memory databases separate'],
        dirname(__DIR__, 2),
        [...$environment, 'DB_CONNECTION' => 'sqlite', 'NO_COLOR' => '1', 'PAO_DISABLE' => '1'],
    );
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});
