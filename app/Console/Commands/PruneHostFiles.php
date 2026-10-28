<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

class PruneHostFiles extends Command
{
    protected $signature = 'host:prune-files {--dry-run : Count what would be deleted without deleting it}';

    protected $description = 'Delete stale file sessions, compiled views, file cache entries and logs under storage so the host stays far from its inode cap.';

    /**
     * Directory under storage, then the age in days after which its files are stale.
     * Only these four trees are touched. storage/app (uploads and dumps) never is.
     *
     * @var array<string, int>
     */
    private const RETENTION_DAYS = [
        'framework/sessions' => 2,
        'framework/views' => 7,
        'framework/cache/data' => 7,
        'logs' => 7,
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $removedTotal = 0;

        foreach (self::RETENTION_DAYS as $relative => $days) {
            $dir = storage_path($relative);
            if (! is_dir($dir) || is_link($dir)) {
                continue;
            }

            $cutoff = now()->subDays($days)->getTimestamp();
            $removed = 0;

            $files = Finder::create()->files()->in($dir)->ignoreDotFiles(true)->ignoreVCS(true)->filter(
                static fn (\SplFileInfo $file): bool => ! $file->isLink() && $file->getMTime() < $cutoff,
            );
            foreach ($files as $file) {
                if ($dryRun || @unlink($file->getPathname())) {
                    $removed++;
                }
            }

            if (! $dryRun) {
                $this->removeEmptyDirectories($dir);
            }

            $removedTotal += $removed;
            $this->line(sprintf('%s: %s %d older than %d days', $relative, $dryRun ? 'would remove' : 'removed', $removed, $days));
        }

        $this->info(sprintf('%s %d files.', $dryRun ? 'Would remove' : 'Removed', $removedTotal));

        return self::SUCCESS;
    }

    private function removeEmptyDirectories(string $root): void
    {
        $dirs = Finder::create()->directories()->in($root)->sort(
            static fn (\SplFileInfo $a, \SplFileInfo $b): int => strlen($b->getPathname()) <=> strlen($a->getPathname()),
        );
        foreach ($dirs as $dir) {
            if (! $dir->isLink() && count(scandir($dir->getPathname()) ?: []) === 2) {
                @rmdir($dir->getPathname());
            }
        }
    }
}
