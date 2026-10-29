<?php

namespace App\Services\Platform;

use Closure;
use Throwable;

/**
 * Spec Section 12 dependency scanning: Dependabot and composer audit in CI.
 * File text and paths are not returned. The read does not write a row.
 */
final class DependencyScanningCheck
{
    private const MAX_BYTES = 65536;

    /**
     * @param  (Closure(): (string|false))|null  $dependabotReader
     * @param  (Closure(): (string|false))|null  $ciReader
     */
    public function __construct(
        private readonly ?Closure $dependabotReader = null,
        private readonly ?Closure $ciReader = null,
    ) {}

    /**
     * @return array{dependabot: bool, composer_audit: bool}
     */
    public function status(): array
    {
        return [
            'dependabot' => $this->dependabotConfigured(),
            'composer_audit' => $this->composerAuditInCi(),
        ];
    }

    private function dependabotConfigured(): bool
    {
        $text = $this->read($this->dependabotReader, base_path('.github/dependabot.yml'));

        if ($text === null) {
            return false;
        }

        return preg_match('/package-ecosystem\s*:\s*["\']?composer["\']?/i', $text) === 1;
    }

    private function composerAuditInCi(): bool
    {
        $text = $this->read($this->ciReader, base_path('.github/workflows/ci.yml'));

        if ($text === null) {
            return false;
        }

        return str_contains($text, 'composer audit');
    }

    /**
     * @param  (Closure(): (string|false))|null  $reader
     */
    private function read(?Closure $reader, string $path): ?string
    {
        try {
            if ($reader !== null) {
                $text = $reader();
            } else {
                if (! is_file($path) || ! is_readable($path)) {
                    return null;
                }

                $text = file_get_contents($path, false, null, 0, self::MAX_BYTES);
            }
        } catch (Throwable) {
            return null;
        }

        if (! is_string($text) || $text === '') {
            return null;
        }

        return $text;
    }
}
