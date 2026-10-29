<?php

namespace App\Support;

use Closure;
use Throwable;

/**
 * Whether laravel/shared/backups holds a private deploy dump.
 *
 * Spec Section 12 and Section 14 item 15 require a verified private backup.
 * A pass needs one non-empty *.sql.gz that is mode 600 and starts as gzip.
 * The directory path and the SQL are never returned. Paths under public_html
 * or www/reserveworx.com are refused.
 */
final class DeployBackupPresence
{
    private const MAX_LIST = 32;

    /**
     * @param  (Closure(): (?string))|null  $directory
     */
    public function __construct(private readonly ?Closure $directory = null) {}

    /**
     * @return 'present'|'missing'
     */
    public function status(): string
    {
        $directory = $this->resolveDirectory();

        if ($directory === null) {
            return 'missing';
        }

        try {
            $entries = scandir($directory);
        } catch (Throwable) {
            return 'missing';
        }

        if (! is_array($entries)) {
            return 'missing';
        }

        $seen = 0;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_string($entry)) {
                continue;
            }

            if (++$seen > self::MAX_LIST) {
                break;
            }

            if (! str_ends_with($entry, '.sql.gz')) {
                continue;
            }

            if (str_contains($entry, '/') || str_contains($entry, '\\') || str_contains($entry, "\0")) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if ($this->isPrivateGzip($path)) {
                return 'present';
            }
        }

        return 'missing';
    }

    private function resolveDirectory(): ?string
    {
        if ($this->directory !== null) {
            try {
                $path = ($this->directory)();
            } catch (Throwable) {
                return null;
            }

            if (! is_string($path) || $path === '') {
                return null;
            }

            return $this->acceptDirectory($path);
        }

        foreach ($this->candidateDirectories() as $candidate) {
            $accepted = $this->acceptDirectory($candidate);
            if ($accepted !== null) {
                return $accepted;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function candidateDirectories(): array
    {
        $candidates = [];

        $fromEnv = $this->directoryBesideSymlink(base_path('.env'), 'shared/.env');
        if ($fromEnv !== null) {
            $candidates[] = $fromEnv.'/backups';
        }

        $fromStorage = $this->directoryBesideSymlink(base_path('storage'), 'shared/storage');
        if ($fromStorage !== null) {
            $candidates[] = $fromStorage.'/backups';
        }

        $base = base_path();
        $candidates[] = dirname($base).'/shared/backups';
        $candidates[] = dirname(dirname($base)).'/shared/backups';

        return $candidates;
    }

    private function directoryBesideSymlink(string $link, string $suffix): ?string
    {
        if (! is_link($link)) {
            return null;
        }

        try {
            $target = readlink($link);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($target) || $target === '') {
            return null;
        }

        $resolved = str_starts_with($target, DIRECTORY_SEPARATOR)
            ? $target
            : dirname($link).DIRECTORY_SEPARATOR.$target;

        try {
            $real = realpath($resolved);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($real)) {
            return null;
        }

        $normalized = str_replace('\\', '/', $real);
        $needle = '/'.str_replace('\\', '/', $suffix);

        if (! str_ends_with($normalized, $needle)) {
            return null;
        }

        return dirname($real);
    }

    private function acceptDirectory(string $path): ?string
    {
        try {
            $real = realpath($path);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($real) || ! is_dir($real)) {
            return null;
        }

        $normalized = str_replace('\\', '/', $real);

        if (str_contains($normalized, 'public_html') || str_contains($normalized, 'www/reserveworx.com')) {
            return null;
        }

        return $real;
    }

    private function isPrivateGzip(string $path): bool
    {
        try {
            if (! is_file($path) || is_link($path)) {
                return false;
            }

            $normalized = str_replace('\\', '/', $path);
            if (str_contains($normalized, 'public_html') || str_contains($normalized, 'www/reserveworx.com')) {
                return false;
            }

            $mode = fileperms($path);
            if ($mode === false || ($mode & 0777) !== 0600) {
                return false;
            }

            $size = filesize($path);
            if ($size === false || $size < 2) {
                return false;
            }

            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return false;
            }

            try {
                $header = fread($handle, 2);
            } finally {
                fclose($handle);
            }
        } catch (Throwable) {
            return false;
        }

        return $header === "\x1f\x8b";
    }
}
