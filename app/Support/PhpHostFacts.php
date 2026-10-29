<?php

namespace App\Support;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * PHP binaries and extensions for the Section 2.2 hosting check.
 *
 * The running binary is named with PHP_VERSION. Another allowlisted binary
 * that exists is named from `php -v`. The command text of that probe is not
 * stored. A missing extension is reported and does not fail the check.
 */
final class PhpHostFacts
{
    /**
     * The extensions the October 23, 2026 CLI probe recorded.
     *
     * @var list<string>
     */
    public const EXTENSIONS = [
        'bcmath',
        'ctype',
        'curl',
        'dom',
        'fileinfo',
        'gd',
        'intl',
        'mbstring',
        'openssl',
        'pdo_mysql',
        'tokenizer',
        'xml',
        'zip',
    ];

    /**
     * @var list<string>
     */
    private const CANDIDATES = [
        '/usr/bin/php',
        '/usr/bin/php8.2',
        '/usr/bin/php8.3',
        '/usr/bin/php8.4',
        '/usr/bin/php8.5',
        '/usr/local/bin/php',
        '/usr/local/php74',
        '/usr/local/php74/bin/php',
        '/usr/local/php80',
        '/usr/local/php80/bin/php',
        '/usr/local/php81',
        '/usr/local/php81/bin/php',
        '/usr/local/php82',
        '/usr/local/php82/bin/php',
        '/usr/local/php83',
        '/usr/local/php83/bin/php',
        '/usr/local/php84',
        '/usr/local/php84/bin/php',
        '/usr/local/php85',
        '/usr/local/php85/bin/php',
        '/usr/local/php86',
        '/usr/local/php86/bin/php',
    ];

    /**
     * @param  (Closure(): list<array{path: string, output: string}>)|null  $probes
     * @param  (Closure(): list<string>)|null  $extensions
     */
    public function __construct(
        private readonly ?Closure $probes = null,
        private readonly ?Closure $extensions = null,
    ) {}

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function facts(): array
    {
        $facts = [];

        foreach ($this->binaries() as $path => $version) {
            $facts[] = ['php_binary', $path.' version='.$version];
        }

        $loaded = array_map(strtolower(...), $this->extensionNames());
        $missing = false;

        foreach (self::EXTENSIONS as $name) {
            $present = in_array($name, $loaded, true);
            $facts[] = ['php_extension', $name.'='.($present ? 'yes' : 'no')];
            $missing = $missing || ! $present;
        }

        $facts[] = ['php_extensions', $missing ? 'missing' : 'ok'];

        return $facts;
    }

    /**
     * @return array<string, string>
     */
    private function binaries(): array
    {
        $rows = [];

        foreach ($this->probes !== null ? ($this->probes)() : $this->probeFilesystem() as $probe) {
            $path = $this->cleanPath($probe['path']);

            if ($path === null || array_key_exists($path, $rows)) {
                continue;
            }

            $rows[$path] = $this->versionFrom($probe['output']);
        }

        ksort($rows);

        return $rows;
    }

    /**
     * @return list<array{path: string, output: string}>
     */
    private function probeFilesystem(): array
    {
        $rows = [];
        $seen = [];
        $current = $this->cleanPath(PHP_BINARY);

        if ($current === null) {
            $rows[] = ['path' => 'current', 'output' => PHP_VERSION];
        } else {
            $seen[$this->identity($current)] = true;
            $rows[] = ['path' => $current, 'output' => PHP_VERSION];
        }

        foreach (self::CANDIDATES as $path) {
            if (! is_file($path) || ! is_executable($path)) {
                continue;
            }

            $identity = $this->identity($path);

            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $rows[] = ['path' => $path, 'output' => $this->readVersion($path)];
        }

        return $rows;
    }

    private function identity(string $path): string
    {
        $real = realpath($path);

        return is_string($real) ? $real : $path;
    }

    private function readVersion(string $path): string
    {
        $process = new Process([$path, '-v']);
        $process->setTimeout(2);

        try {
            $process->run();
        } catch (Throwable) {
            return '';
        }

        return $process->getOutput()."\n".$process->getErrorOutput();
    }

    /**
     * @return list<string>
     */
    private function extensionNames(): array
    {
        if ($this->extensions !== null) {
            return ($this->extensions)();
        }

        return get_loaded_extensions();
    }

    private function cleanPath(string $path): ?string
    {
        if ($path === 'current') {
            return 'current';
        }

        if (preg_match('#^/[A-Za-z0-9_./+-]+$#', $path) !== 1 || str_contains($path, '..')) {
            return null;
        }

        if ($path === PHP_BINARY || in_array($path, self::CANDIDATES, true)) {
            return $path;
        }

        return null;
    }

    private function versionFrom(string $text): string
    {
        if (preg_match('/\b(\d+\.\d+\.\d+)\b/', $text, $match) !== 1) {
            return 'unread';
        }

        return $match[1];
    }
}
