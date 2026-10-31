<?php

namespace App\Shell\Data;

use DirectoryIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;

/** Removes only verified export files; lock sentinels are intentionally permanent. */
final class PruneExports
{
    public function prune(int $limit = 100, int $orphanGraceMinutes = 60): int
    {
        return $this->run(null, $limit, $orphanGraceMinutes, 5000);
    }

    /** Opportunistic cleanup is limited to the current organization. */
    public function pruneOrganization(string $organizationId, int $limit = 20, int $budgetMs = 100): int
    {
        if (! Str::isUlid($organizationId)) {
            throw new RuntimeException('Invalid organization identifier.');
        }
        $grace = filter_var(config('features.E1-11.defaults.export_orphan_grace_minutes', 60), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10080]]);
        if ($grace === false) {
            throw new RuntimeException('Invalid export cleanup settings.');
        }
        return $this->run($organizationId, $limit, $grace, $budgetMs);
    }

    private function run(?string $onlyOrg, int $limit, int $orphanGraceMinutes, int $budgetMs): int
    {
        if ($limit < 1 || $limit > 500 || $orphanGraceMinutes < 1 || $budgetMs < 1 || $budgetMs > 5000) {
            throw new RuntimeException('Invalid export cleanup settings.');
        }
        if (config('filesystems.disks.local.driver') !== 'local') {
            throw new RuntimeException('A private local export disk is required.');
        }

        $disk = Storage::disk('local');
        $rawRoot = $disk->path('data-exports');
        if (! file_exists($rawRoot)) {
            return 0;
        }
        if (is_link($rawRoot) || ! is_dir($rawRoot)) {
            throw new RuntimeException('Unsafe export root.');
        }
        $root = realpath($rawRoot);
        $public = realpath(public_path());
        if ($root === false) {
            throw new RuntimeException('Unable to resolve export root.');
        }
        if ($public !== false) {
            $normalizedRoot = strtolower(str_replace('\\', '/', $root));
            $normalizedPublic = rtrim(strtolower(str_replace('\\', '/', $public)), '/');
            if ($normalizedRoot === $normalizedPublic || str_starts_with($normalizedRoot, $normalizedPublic.'/')) {
                throw new RuntimeException('Export storage must be private.');
            }
        }

        $removed = 0;
        $examined = 0;
        $cutoff = now()->subMinutes($orphanGraceMinutes)->getTimestamp();
        $deadline = microtime(true) + $budgetMs / 1000;
        $directories = $onlyOrg === null ? new DirectoryIterator($root) : [new SplFileInfo($root.DIRECTORY_SEPARATOR.$onlyOrg)];
        $directoriesExamined = 0;
        foreach ($directories as $directory) {
            if (++$directoriesExamined > $limit || microtime(true) >= $deadline) break;
            if ($directory->isLink() || ! $directory->isDir() || ! Str::isUlid($directory->getFilename())) continue;
            $org = $directory->getFilename();
            $directoryPath = $directory->getPathname();
            $cursor = ExportFileLock::acquire($directoryPath.DIRECTORY_SEPARATOR.'.cleanup-cursor', LOCK_EX | LOCK_NB);
            if ($cursor === false) continue;
            try {
                rewind($cursor);
                $saved = stream_get_contents($cursor);
                $position = is_string($saved) && ctype_digit(trim($saved)) ? (int) trim($saved) : 0;
                $files = new DirectoryIterator($directoryPath);
                try {
                    $files->seek($position);
                } catch (\OutOfBoundsException) {
                    $files->rewind();
                }
                while ($files->valid() && $examined < $limit && microtime(true) < $deadline) {
                    $file = $files->current();
                    ++$examined;
                    if (! $file->isDot() && ! $file->isLink() && $file->isFile()
                        && preg_match('/^([0-9A-HJKMNP-TV-Z]{26})\.(zip|zip\.tmp|jsonl\.tmp)$/D', $file->getFilename(), $matches)
                        && Str::isUlid($matches[1])) {
                        $id = $matches[1];
                        $path = $file->getPathname();
                        $lock = ExportFileLock::acquire($directoryPath.DIRECTORY_SEPARATOR.$id.'.lock', LOCK_EX | LOCK_NB);
                        if ($lock !== false) {
                            try {
                                $removed += DB::transaction(function () use ($id, $org, $file, $matches, $cutoff, $path): int {
                                    // Avoid making a page request wait indefinitely on another transaction.
                                    DB::statement("SET LOCAL lock_timeout = '50ms'");
                                    $row = DB::table('data_exports')->where('id', $id)->lockForUpdate()->first();
                                    $expired = $row && $row->organization_id === $org
                                        && $row->path === 'data-exports/'.$org.'/'.$id.'.zip'
                                        && now()->greaterThanOrEqualTo($row->expires_at);
                                    $oldOrphan = ! $row && $file->getMTime() <= $cutoff;
                                    $oldTemporary = $matches[2] !== 'zip' && $file->getMTime() <= $cutoff
                                        && (! $row || $row->organization_id === $org);
                                    if (! $expired && ! $oldOrphan && ! $oldTemporary) {
                                        return 0;
                                    }
                                    clearstatcache(true, $path);
                                    if (is_link($path) || ! is_file($path) || ! @unlink($path)) {
                                        return 0;
                                    }
                                    if ($expired && $matches[2] === 'zip') {
                                        DB::table('data_exports')->where('id', $id)->where('organization_id', $org)
                                            ->where('path', 'data-exports/'.$org.'/'.$id.'.zip')->where('expires_at', '<=', now())->delete();
                                    }
                                    return 1;
                                });
                            } finally {
                                fclose($lock);
                            }
                        }
                    }
                    $files->next();
                }
                $position = $files->valid() ? $files->key() : 0;
                rewind($cursor);
                ftruncate($cursor, 0);
                fwrite($cursor, (string) $position);
                fflush($cursor);
            } finally {
                fclose($cursor);
            }
            if ($examined >= $limit || microtime(true) >= $deadline) break;
        }
        return $removed;
    }
}
