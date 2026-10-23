<?php

namespace App\Support;

final class Hostname
{
    public static function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        return rtrim($host, '.');
    }

    public static function isValid(string $host): bool
    {
        return (bool) preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
            $host,
        );
    }
}
