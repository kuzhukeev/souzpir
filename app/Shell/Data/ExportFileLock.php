<?php

namespace App\Shell\Data;

use Illuminate\Support\Str;

/** A stable per-export lock shared by creation, download, and cleanup. */
final class ExportFileLock
{
    /** @return resource|false */
    public static function acquireForExport(string $organizationDirectory, string $id, int $mode)
    {
        if (! Str::isUlid($id) || is_link($organizationDirectory) || ! is_dir($organizationDirectory)) {
            return false;
        }

        $directory = $organizationDirectory.DIRECTORY_SEPARATOR.'.locks';
        if (is_link($directory)) {
            return false;
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0700) && ! is_dir($directory)) {
            return false;
        }
        if (is_link($directory) || ! is_dir($directory)) {
            return false;
        }

        // The fixed buckets are never unlinked: removing an open lock would split
        // holders across inodes. Different exports can conservatively share one.
        $bucket = hexdec(substr(hash('sha256', strtoupper($id)), 0, 2)) % 64;

        return self::acquire($directory.DIRECTORY_SEPARATOR.sprintf('%02d.lock', $bucket), $mode);
    }

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
