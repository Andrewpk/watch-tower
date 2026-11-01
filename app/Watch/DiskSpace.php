<?php

namespace App\Watch;

use Closure;

/**
 * Free space on a disk, behind a class so that tests can pretend the disk is nearly full.
 */
class DiskSpace
{
    /**
     * @param  (Closure(string): (int|float|false))|null  $freeBytes  Free bytes in a directory. Defaults to `disk_free_space`.
     */
    public function __construct(private readonly ?Closure $freeBytes = null) {}

    /**
     * Free bytes in the directory's file system, or false when that cannot be determined.
     */
    public function freeIn(string $directory): int|float|false
    {
        return $this->freeBytes !== null ? ($this->freeBytes)($directory) : @disk_free_space($directory);
    }
}
