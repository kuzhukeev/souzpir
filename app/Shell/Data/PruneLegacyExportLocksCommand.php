<?php

namespace App\Shell\Data;

use DirectoryIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OutOfBoundsException;

/** Offline-only migration from the previous per-export lock scheme. */
final class PruneLegacyExportLocksCommand extends Command
{
    protected $signature = 'data:prune-legacy-export-locks {organization} {--confirm-offline} {--limit=100}';

    protected $description = 'Remove a bounded batch of legacy export locks after all application processes have stopped.';

    public function handle(): int
    {
        if (! $this->option('confirm-offline')) {
            $this->error('Stop all old and new web, worker and scheduler processes, then pass --confirm-offline.');

            return self::FAILURE;
        }

        $org = $this->argument('organization');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if (! is_string($org) || ! Str::isUlid($org) || $limit === false || config('filesystems.disks.local.driver') !== 'local') {
            $this->error('Invalid organization, limit, or export disk.');

            return self::FAILURE;
        }

        $root = Storage::disk('local')->path('data-exports');
        if (is_link($root) || ! is_dir($root) || ($realRoot = realpath($root)) === false) {
            $this->error('Private export root is unavailable or unsafe.');

            return self::FAILURE;
        }
        $public = realpath(public_path());
        if ($public !== false) {
            $normalizedRoot = strtolower(str_replace('\\', '/', $realRoot));
            $normalizedPublic = rtrim(strtolower(str_replace('\\', '/', $public)), '/');
            if ($normalizedRoot === $normalizedPublic || str_starts_with($normalizedRoot, $normalizedPublic.'/')) {
                $this->error('Export root must not be public.');

                return self::FAILURE;
            }
        }

        $directory = $realRoot.DIRECTORY_SEPARATOR.$org;
        $realDirectory = realpath($directory);
        $normalize = static fn (string $path): string => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
        if (is_link($directory) || ! is_dir($directory) || $realDirectory === false
            || $normalize($realDirectory) !== $normalize($directory)) {
            $this->error('Organization export directory is unavailable or unsafe.');

            return self::FAILURE;
        }

        // Cursor and lock share one permanent file so two operators cannot race.
        // Old and new application processes must remain stopped throughout.
        $cursor = ExportFileLock::acquire($directory.DIRECTORY_SEPARATOR.'.legacy-offline-cursor', LOCK_EX | LOCK_NB);
        if ($cursor === false) {
            $this->error('Another offline cleanup is running or the cursor is unsafe.');

            return self::FAILURE;
        }
        try {
            rewind($cursor);
            $saved = stream_get_contents($cursor);
            $position = is_string($saved) && ctype_digit(trim($saved)) ? (int) trim($saved) : 0;
            $files = new DirectoryIterator($directory);
            try {
                $files->seek($position);
            } catch (OutOfBoundsException) {
                $files->rewind();
            }
            $removed = 0;
            $scanned = 0;
            $scanLimit = max(512, $limit * 128);
            while ($files->valid() && $removed < $limit && $scanned < $scanLimit) {
                $file = $files->current();
                $scanned++;
                $name = $file->getFilename();
                if (! $file->isLink() && $file->isFile()
                    && preg_match('/^([0-9A-HJKMNP-TV-Z]{26})\.lock$/D', $name, $matches)
                    && Str::isUlid($matches[1])) {
                    $path = $file->getPathname();
                    clearstatcache(true, $path);
                    if (! is_link($path) && is_file($path) && filesize($path) === 0) {
                        if (! @unlink($path)) {
                            $this->error('Unable to remove a legacy lock file; retry after correcting permissions.');

                            return self::FAILURE;
                        }
                        $removed++;
                    }
                }
                $files->next();
            }
            $next = $files->valid() ? max(0, $files->key() - $removed) : 0;
            rewind($cursor);
            $value = (string) $next;
            if (! ftruncate($cursor, 0) || fwrite($cursor, $value) !== strlen($value) || ! fflush($cursor)) {
                $this->error('Unable to save offline cleanup cursor.');

                return self::FAILURE;
            }
            $this->info("Legacy lock files removed: {$removed}. Next cursor: {$next}.");

            return self::SUCCESS;
        } finally {
            fclose($cursor);
        }
    }
}
