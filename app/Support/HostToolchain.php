<?php

namespace App\Support;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Git and Composer for the Section 2.2 hosting check.
 *
 * An allowlisted binary that exits 0 is available. The fact is the first
 * x.y.z in that output, or unread. The command text and the path are not
 * stored. A missing binary does not fail the check.
 */
final class HostToolchain
{
    /**
     * @var array<string, list<string>>
     */
    private const CANDIDATES = [
        'git' => [
            '/usr/bin/git',
            '/usr/local/bin/git',
            '/bin/git',
        ],
        'composer' => [
            '/usr/local/bin/composer',
            '/usr/bin/composer',
            '/usr/local/bin/composer.phar',
            '/usr/bin/composer.phar',
        ],
    ];

    /**
     * @param  (Closure(): list<array{tool: string, output: string, ok: bool}>)|null  $probes
     */
    public function __construct(
        private readonly ?Closure $probes = null,
    ) {}

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function facts(): array
    {
        $versions = [];

        foreach ($this->readProbes() as $probe) {
            $tool = $probe['tool'];

            if (($tool !== 'git' && $tool !== 'composer') || array_key_exists($tool, $versions) || $probe['ok'] !== true) {
                continue;
            }

            $versions[$tool] = $this->versionFrom($probe['output']);
        }

        $facts = [];

        foreach (['git', 'composer'] as $tool) {
            if (! array_key_exists($tool, $versions)) {
                $facts[] = [$tool, 'unavailable'];

                continue;
            }

            $facts[] = [$tool, 'available'];
            $facts[] = [$tool.'_version', $versions[$tool]];
        }

        return $facts;
    }

    /**
     * @return list<array{tool: string, output: string, ok: bool}>
     */
    private function readProbes(): array
    {
        if ($this->probes !== null) {
            return ($this->probes)();
        }

        return $this->probeFilesystem();
    }

    /**
     * @return list<array{tool: string, output: string, ok: bool}>
     */
    private function probeFilesystem(): array
    {
        $rows = [];

        foreach (self::CANDIDATES as $tool => $paths) {
            $rows[] = $this->firstRun($tool, $paths);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $paths
     * @return array{tool: string, output: string, ok: bool}
     */
    private function firstRun(string $tool, array $paths): array
    {
        foreach ($paths as $path) {
            if (! is_file($path) || ! is_executable($path)) {
                continue;
            }

            $result = $this->run($tool, $path);

            if ($result['ok']) {
                return $result;
            }
        }

        return ['tool' => $tool, 'output' => '', 'ok' => false];
    }

    /**
     * @return array{tool: string, output: string, ok: bool}
     */
    private function run(string $tool, string $path): array
    {
        $command = $tool === 'composer'
            ? [$path, '--version', '--no-ansi', '--no-interaction', '--no-plugins']
            : [$path, '--version'];
        $process = new Process($command, sys_get_temp_dir(), $this->environment($tool), null, $tool === 'composer' ? 5 : 2);

        try {
            $process->run();
        } catch (Throwable) {
            return ['tool' => $tool, 'output' => '', 'ok' => false];
        }

        return [
            'tool' => $tool,
            'output' => $process->getOutput()."\n".$process->getErrorOutput(),
            'ok' => $process->isSuccessful(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function environment(string $tool): array
    {
        $env = getenv();
        $env['GIT_TERMINAL_PROMPT'] = '0';

        if ($tool === 'composer') {
            $env['COMPOSER_DISABLE_NETWORK'] = '1';
            $env['COMPOSER_NO_INTERACTION'] = '1';
        }

        return $env;
    }

    private function versionFrom(string $text): string
    {
        if (preg_match('/\b(\d+\.\d+\.\d+)\b/', $text, $match) !== 1) {
            return 'unread';
        }

        return $match[1];
    }
}
