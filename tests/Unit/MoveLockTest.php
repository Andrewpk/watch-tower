<?php

use App\Watch\MoveLock;
use Illuminate\Database\Connection;

/**
 * A MySQL connection that answers `get_lock` with 1 and `is_used_lock(...) = connection_id()` with `$held`. No server
 * is involved.
 */
function fakeServerConnection(mixed $held, array &$statements): Connection
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('getName')->andReturn('mysql');
    $connection->shouldReceive('scalar')->andReturnUsing(function (string $sql) use ($held, &$statements) {
        $statements[] = $sql;

        return str_contains($sql, 'get_lock') ? 1 : (str_contains($sql, 'is_used_lock') ? $held : null);
    });

    return $connection;
}

it('accepts a MySQL lock that this session still holds', function () {
    $statements = [];
    $connection = fakeServerConnection(1, $statements);

    $lock = MoveLock::acquire($connection, 'mysql', $connection, 'mysql', 0);
    $lock->assertHeld();
    $lock->release();

    expect($statements)->toContain('select is_used_lock(?) = connection_id()')
        ->and(end($statements))->toBe('select release_lock(?)');
});

it('notices a MySQL lock lost to a silent reconnect', function (mixed $held) {
    $statements = [];
    $connection = fakeServerConnection($held, $statements);

    $lock = MoveLock::acquire($connection, 'mysql', $connection, 'mysql', 0);

    expect(fn () => $lock->assertHeld())->toThrow(RuntimeException::class, 'lost its database lock');
})->with([
    'another session holds it' => 0,
    'nobody holds it' => null,
    'false' => false,
]);
