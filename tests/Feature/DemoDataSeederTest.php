<?php

use App\Models\Organization;
use App\Models\Project;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

/**
 * A seeder wired to a console that records what it prints.
 *
 * @param  list<string>  $printed
 */
function demoSeederPrinting(array &$printed): DemoDataSeeder
{
    $command = Mockery::mock(Command::class);
    $command->shouldReceive('info')->andReturnUsing(function (string $message) use (&$printed): void {
        $printed[] = $message;
    });

    return (new DemoDataSeeder)->setCommand($command);
}

it('prints the key of a new demo project, because only its hash is stored', function () {
    $printed = [];
    $organization = Organization::factory()->create(['slug' => 'acme']);

    $project = demoSeederPrinting($printed)->ensureDemoProject($organization);

    expect($printed)->toHaveCount(1)
        ->and($printed[0])->toContain('Demo API key for Acme App');

    preg_match('/(pk_\w+)$/', $printed[0], $matches);

    expect($matches)->toHaveCount(2)
        ->and(Project::findByApiKey($matches[1])?->id)->toBe($project->id)
        ->and($project->fresh()->api_key_prefix)->toBe(substr($matches[1], 0, 10))
        ->and(json_encode($project->fresh()->getAttributes()))->not->toContain($matches[1]);
});

it('leaves the key of an existing demo project alone', function () {
    $printed = [];
    $organization = Organization::factory()->create(['slug' => 'acme']);
    $existing = Project::factory()->withApiKey('pk_test_existing')->create([
        'organization_id' => $organization->id,
        'slug' => 'acme-app',
    ]);

    $project = demoSeederPrinting($printed)->ensureDemoProject($organization);

    expect($project->id)->toBe($existing->id)
        ->and($printed[0])->toContain('already exists')
        ->and(Project::findByApiKey('pk_test_existing')?->id)->toBe($existing->id);
});
