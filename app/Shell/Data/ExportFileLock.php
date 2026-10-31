<?php

namespace App\Shell\Data;

/** A stable per-export lock shared by creation, download, and cleanup. */
final class ExportFileLock
{
    /** @return resource|false */
    public static function acquire(string $absolutePath, int $mode)
    {
        // Never follow a pre-existing link in a private storage directory.
        if (is_link($absolutePath)) {
            return false;
        }

        $lock = @fopen($absolutePath, 'c+b');
        if ($lock === false) {
            return false;
        }
        @chmod($absolutePath, 0600);
        if (! flock($lock, $mode)) {
            fclose($lock);
            return false;
        }

        return $lock;
    }
}
