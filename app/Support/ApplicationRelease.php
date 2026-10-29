<?php

namespace App\Support;

/**
 * Semantic version of this build, from Spec Section 16.
 * The value is config('app.version'). A value that does not match is not repeated.
 */
final class ApplicationRelease
{
    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    public function check(): array
    {
        $version = $this->version();

        if ($version === null) {
            return [
                'key' => 'release',
                'label' => 'Release',
                'state' => 'fail',
                'detail' => 'The release version is not semantic versioning.',
                'line' => 'fail',
            ];
        }

        return [
            'key' => 'release',
            'label' => 'Release',
            'state' => 'pass',
            'detail' => 'This build is '.$version.'. The release tag is v'.$version.'.',
            'line' => 'pass',
        ];
    }

    public function version(): ?string
    {
        $value = config('app.version');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
