<?php

namespace App\Shell\Data;

use DirectoryIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;

/** Bounded, independent passes over expired records and orphan export files. */
final class PruneExports
{
    public function prune(int $limit = 100, int $orphanGraceMinutes = 60): int
    {
        return $this->run(null, $limit, $orphanGraceMinutes, 5000);
    }

    public function pruneOrganization(string $organizationId, int $limit = 20, int $budgetMs = 100): int
    {
        if (! Str::isUlid($organizationId)) throw new RuntimeException('Invalid organization identifier.');
        $grace = filter_var(config('features.E1-11.defaults.export_orphan_grace_minutes', 60), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10080]]);
        if ($grace === false) throw new RuntimeException('Invalid export cleanup settings.');
        return $this->run($organizationId, $limit, $grace, $budgetMs);
    }

    private function run(?string $onlyOrg, int $limit, int $orphanGraceMinutes, int $budgetMs): int
    {
        if ($limit < 1 || $limit > 500 || $orphanGraceMinutes < 1 || $budgetMs < 1 || $budgetMs > 5000) throw new RuntimeException('Invalid export cleanup settings.');
        if (config('filesystems.disks.local.driver') !== 'local') throw new RuntimeException('A private local export disk is required.');
        $disk = Storage::disk('local');
        $rawRoot = $disk->path('data-exports');
        if (! file_exists($rawRoot)) return 0;
        if (is_link($rawRoot) || ! is_dir($rawRoot)) throw new RuntimeException('Unsafe export root.');
        $root = realpath($rawRoot);
        $public = realpath(public_path());
        if ($root === false) throw new RuntimeException('Unable to resolve export root.');
        if ($public !== false) {
            $normalizedRoot = strtolower(str_replace('\\', '/', $root));
            $normalizedPublic = rtrim(strtolower(str_replace('\\', '/', $public)), '/');
            if ($normalizedRoot === $normalizedPublic || str_starts_with($normalizedRoot, $normalizedPublic.'/')) throw new RuntimeException('Export storage must be private.');
        }

        if ($onlyOrg !== null) {
            $directory = new SplFileInfo($root.DIRECTORY_SEPARATOR.$onlyOrg);
            if ($directory->isLink() || ! $directory->isDir()) return 0;
            return $this->organization($directory->getPathname(), $onlyOrg, $limit, $orphanGraceMinutes, $budgetMs);
        }

        // Scheduled scans also persist their directory position. Even when
        // unrelated entries fill a scan, subsequent calls continue and wrap.
        $cursor = ExportFileLock::acquire($root.DIRECTORY_SEPARATOR.'.cleanup-organizations-v2', LOCK_EX | LOCK_NB);
        if ($cursor === false) return 0;
        try {
            rewind($cursor);
            $saved = stream_get_contents($cursor);
            $position = is_string($saved) && ctype_digit(trim($saved)) ? (int) trim($saved) : 0;
            $directories = new DirectoryIterator($root);
            try {
                $directories->seek($position);
            } catch (\OutOfBoundsException) {
                $directories->rewind();
            }
            $removed = 0;
            $scanned = 0;
            $visited = 0;
            $deadline = microtime(true) + $budgetMs / 1000;
            $scanLimit = max(128, $limit * 16);
            // Inspect at least a bounded scan looking for an organization,
            // even if time expires while scanning unrelated directory entries.
            while ($directories->valid() && $scanned < $scanLimit && ($visited === 0 || microtime(true) < $deadline)) {
                $directory = $directories->current();
                ++$scanned;
                if (! $directory->isLink() && $directory->isDir() && Str::isUlid($directory->getFilename())) {
                    ++$visited;
                    $removed += $this->organization($directory->getPathname(), $directory->getFilename(), $limit, $orphanGraceMinutes, $budgetMs);
                }
                $directories->next();
            }
            $this->saveCursor($cursor, (string) ($directories->valid() ? $directories->key() : 0));
            return $removed;
        } finally {
            fclose($cursor);
        }
    }

    private function organization(string $directory, string $org, int $limit, int $grace, int $budgetMs): int
    {
        // Each pass has an independent quota and fresh soft time budget.
        // Current payloads never share a directory with legacy lock sentinels.
        $removed = $this->expired($directory, $org, $limit, microtime(true) + $budgetMs / 2000);
        $files = $directory.DIRECTORY_SEPARATOR.'files';
        if (! is_link($files) && is_dir($files)) {
            $removed += $this->orphans($directory, $files, '.cleanup-orphan-files-v3', $org, $limit, $grace, $budgetMs / 2);
        }
        // Continue bounded compatibility cleanup for files from the old layout.
        return $removed + $this->orphans($directory, $directory, '.cleanup-orphan-v2', $org, $limit, $grace, $budgetMs / 2);
    }

    private function expired(string $directory, string $org, int $limit, float $deadline): int
    {
        $cursor = ExportFileLock::acquire($directory.DIRECTORY_SEPARATOR.'.cleanup-expired-v2', LOCK_EX | LOCK_NB);
        if ($cursor === false) return 0;
        try {
            rewind($cursor);
            $state = json_decode(stream_get_contents($cursor) ?: 'null', true);
            $query = DB::table('data_exports')->where('organization_id', $org)->where('expires_at', '<=', now());
            if (is_array($state) && isset($state['expires_at'], $state['id']) && is_string($state['expires_at']) && Str::isUlid($state['id'])) {
                $query->where(function ($q) use ($state): void {
                    $q->where('expires_at', '>', $state['expires_at'])->orWhere(function ($q) use ($state): void {
                        $q->where('expires_at', '=', $state['expires_at'])->where('id', '>', $state['id']);
                    });
                });
            }
            $rows = $query->orderBy('expires_at')->orderBy('id')->limit($limit)->get();
            $removed = 0;
            $processed = 0;
            foreach ($rows as $candidate) {
                if ($processed > 0 && microtime(true) >= $deadline) break;
                ++$processed;
                // Advance past locked or invalid records as well; the next cycle
                // wraps so a permanently busy first row cannot starve later rows.
                $state = ['expires_at' => (string) $candidate->expires_at, 'id' => (string) $candidate->id];
                $id = (string) $candidate->id;
                if (! Str::isUlid($id)) continue;
                $lock = ExportFileLock::acquireForExport($directory, $id, LOCK_EX | LOCK_NB);
                if ($lock === false) continue;
                try {
                    $removed += DB::transaction(function () use ($id, $org, $directory): int {
                        DB::statement("SET LOCAL lock_timeout = '50ms'");
                        $row = DB::table('data_exports')->where('id', $id)->lockForUpdate()->first();
                        if (! $row || $row->organization_id !== $org || now()->lessThan($row->expires_at)) return 0;
                        $legacy = 'data-exports/'.$org.'/'.$id.'.zip';
                        $current = 'data-exports/'.$org.'/files/'.$id.'.zip';
                        if (! in_array($row->path, [$legacy, $current], true)) return 0;
                        $dataDir = $row->path === $current ? $directory.DIRECTORY_SEPARATOR.'files' : $directory;
                        // A missing data directory is a missing file, but a link
                        // must never be traversed or used to justify deletion.
                        if (is_link($dataDir)) return 0;
                        $file = $dataDir.DIRECTORY_SEPARATOR.$id.'.zip';
                        clearstatcache(true, $file);
                        if (is_link($file)) return 0;
                        if (file_exists($file) && (! is_file($file) || ! @unlink($file))) return 0;
                        DB::table('data_exports')->where('id', $id)->where('organization_id', $org)->where('path', $row->path)->where('expires_at', '<=', now())->delete();
                        return 1;
                    });
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->getCode() !== '55P03') throw $e;
                } finally {
                    fclose($lock);
                }
            }
            if ($processed === $rows->count() && $rows->count() < $limit) $state = null;
            $this->saveCursor($cursor, json_encode($state, JSON_THROW_ON_ERROR));
            return $removed;
        } finally {
            fclose($cursor);
        }
    }

    private function orphans(string $directory, string $scanDirectory, string $cursorName, string $org, int $limit, int $grace, float $budgetMs): int
    {
        $cursor = ExportFileLock::acquire($directory.DIRECTORY_SEPARATOR.$cursorName, LOCK_EX | LOCK_NB);
        if ($cursor === false) return 0;
        try {
            rewind($cursor);
            $saved = stream_get_contents($cursor);
            $position = is_string($saved) && ctype_digit(trim($saved)) ? (int) trim($saved) : 0;
            if (is_link($scanDirectory) || ! is_dir($scanDirectory)) return 0;
            $files = new DirectoryIterator($scanDirectory);
            try {
                $files->seek($position);
            } catch (\OutOfBoundsException) {
                $files->rewind();
            }
            // Seeking a directory offset can itself be slow. Give this pass
            // a fresh soft budget after seek, independently of SQL.
            $deadline = microtime(true) + $budgetMs / 1000;
            $candidates = 0;
            $scanned = 0;
            $removed = 0;
            $cutoff = now()->subMinutes($grace)->getTimestamp();
            // Legacy .lock files and cursor files do not consume candidate quota.
            // A separate scan cap and deadline bound the cost of unrelated entries.
            $scanLimit = max(512, $limit * 128);
            while ($files->valid() && $candidates < $limit && $scanned < $scanLimit && ($scanned === 0 || microtime(true) < $deadline)) {
                $file = $files->current();
                ++$scanned;
                if (! $file->isDot() && preg_match('/^([0-9A-HJKMNP-TV-Z]{26})\.(zip|zip\.tmp|jsonl\.tmp)$/D', $file->getFilename(), $matches) && Str::isUlid($matches[1])) {
                    ++$candidates;
                    if (! $file->isLink() && $file->isFile() && $file->getMTime() <= $cutoff) {
                        $id = $matches[1];
                        $path = $file->getPathname();
                        $lock = ExportFileLock::acquireForExport($directory, $id, LOCK_EX | LOCK_NB);
                        if ($lock !== false) {
                            try {
                                $removed += DB::transaction(function () use ($id, $org, $matches, $cutoff, $path): int {
                                    DB::statement("SET LOCAL lock_timeout = '50ms'");
                                    $row = DB::table('data_exports')->where('id', $id)->lockForUpdate()->first();
                                    $oldTemporary = $matches[2] !== 'zip' && (! $row || $row->organization_id === $org);
                                    $orphan = ! $row;
                                    clearstatcache(true, $path);
                                    if ((! $oldTemporary && ! $orphan) || is_link($path) || ! is_file($path) || filemtime($path) > $cutoff || ! @unlink($path)) return 0;
                                    return 1;
                                });
                            } catch (\Illuminate\Database\QueryException $e) {
                                if ($e->getCode() !== '55P03') throw $e;
                            } finally {
                                fclose($lock);
                            }
                        }
                    }
                }
                $files->next();
            }
            // Directory entries can shift after unlink. Revisit up to the number
            // removed on the next pass; reaching EOF wraps to the beginning.
            $next = $files->valid() ? max(0, $files->key() - $removed) : 0;
            $this->saveCursor($cursor, (string) $next);
            return $removed;
        } finally {
            fclose($cursor);
        }
    }

    /** @param resource $cursor */
    private function saveCursor($cursor, string $value): void
    {
        rewind($cursor);
        if (! ftruncate($cursor, 0) || fwrite($cursor, $value) !== strlen($value) || ! fflush($cursor)) throw new RuntimeException('Unable to save cleanup cursor.');
    }
}
