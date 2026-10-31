<?php

namespace App\Watch;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The lock that keeps two table moves from interleaving: a MySQL `GET_LOCK`, or an `flock` on a file beside the SQLite
 * database.
 */
final class MoveLock
{
    private const FILE_NAME = '.watch-tower-move.lock';

    private const SERVER_LOCK_NAME = 'watch_tower_table_move';

    /**
     * @param  Closure(): void  $release  Gives the lock up.
     * @param  (Closure(): void)|null  $assertHeld  Throws when the lock was lost. Null for a lock that cannot be lost.
     */
    public function __construct(
        private readonly Closure $release,
        private readonly ?Closure $assertHeld = null,
    ) {}

    /**
     * Wait for the lock, for at most `$timeoutSeconds`.
     */
    public static function acquire(Connection $source, string $from, Connection $target, string $to, int $timeoutSeconds): self
    {
        foreach ([$source, $target] as $connection) {
            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                return self::acquireServerLock($connection, $timeoutSeconds);
            }
        }

        $path = $target->getDriverName() === 'sqlite' ? Databases::configuredSqlitePath($to) : null;
        $path ??= $source->getDriverName() === 'sqlite' ? Databases::configuredSqlitePath($from) : null;

        if ($path === null) {
            return new self(fn () => null);
        }

        return self::acquireFileLock(dirname($path).DIRECTORY_SEPARATOR.self::FILE_NAME, $timeoutSeconds);
    }

    /**
     * Throw unless this process still holds the lock.
     *
     * A MySQL lock belongs to a session. When the connection drops, Laravel reconnects without a word and the new
     * session holds nothing, so another migrator may already be running.
     */
    public function assertHeld(): void
    {
        if ($this->assertHeld !== null) {
            ($this->assertHeld)();
        }
    }

    public function release(): void
    {
        ($this->release)();
    }

    /**
     * A named lock on the server. It belongs to the session, so it goes away by itself if the process dies.
     */
    private static function acquireServerLock(Connection $connection, int $timeoutSeconds): self
    {
        try {
            $acquired = (int) $connection->scalar('select get_lock(?, 0)', [self::SERVER_LOCK_NAME]);
        } catch (Throwable $exception) {
            throw new RuntimeException("Cannot connect to the [{$connection->getName()}] database, so nothing was moved: ".$exception->getMessage(), previous: $exception);
        }

        if ($acquired !== 1) {
            Log::info('Another process is moving tables between databases; waiting for it to finish.');

            $acquired = $timeoutSeconds > 0
                ? (int) $connection->scalar('select get_lock(?, ?)', [self::SERVER_LOCK_NAME, $timeoutSeconds])
                : 0;
        }

        if ($acquired !== 1) {
            throw new RuntimeException(self::timeoutMessage());
        }

        return new self(
            function () use ($connection): void {
                try {
                    $connection->scalar('select release_lock(?)', [self::SERVER_LOCK_NAME]);
                } catch (Throwable) {
                    // The session ending releases it anyway.
                }
            },
            function () use ($connection): void {
                if ((int) $connection->scalar('select is_used_lock(?) = connection_id()', [self::SERVER_LOCK_NAME]) !== 1) {
                    throw new RuntimeException('The migration lost its database lock (the connection to the database was probably dropped), so another migration may have started. Nothing further was dropped. Wait for any other migration to finish and run this one again.');
                }
            },
        );
    }

    /**
     * An exclusive `flock` on a file of its own. The database file is never opened for this: closing a second handle
     * to it would cancel SQLite's own locks. The lock file is never deleted, because unlinking it races with a waiter.
     */
    private static function acquireFileLock(string $lockFile, int $timeoutSeconds): self
    {
        $handle = @fopen($lockFile, 'c');

        if ($handle === false) {
            throw new RuntimeException("Cannot open the lock file {$lockFile}, so nothing was moved. Check that the directory is writable.");
        }

        $deadline = time() + $timeoutSeconds;
        $logged = false;

        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (time() >= $deadline) {
                fclose($handle);

                throw new RuntimeException(self::timeoutMessage());
            }

            if (! $logged) {
                Log::info('Another process is moving tables between databases; waiting for it to finish.', ['lock' => $lockFile]);
                $logged = true;
            }

            sleep(1);
        }

        return new self(function () use ($handle): void {
            flock($handle, LOCK_UN);
            fclose($handle);
        });
    }

    private static function timeoutMessage(): string
    {
        return 'Another process is moving tables between databases (another migration or a container starting up) and did not finish in time. Nothing was changed. Wait for it to finish and run the migration again.';
    }
}
