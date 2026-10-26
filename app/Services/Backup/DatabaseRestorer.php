<?php

namespace App\Services\Backup;

use PDO;
use PDOException;

/**
 * Replays one deploy-style gzip into the configured database.
 *
 * The deploy script already writes that gzip. This class does not shell out,
 * so the database password never appears on a command line.
 */
final class DatabaseRestorer
{
    /**
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $connection
     */
    public function restore(string $path, array $connection, bool $force): RestoreReport
    {
        $database = $connection['database'];
        if (! $this->connectionIsSafe($connection)) {
            return RestoreReport::failed('settings');
        }

        $resolved = $this->resolvePath($path);
        if ($resolved instanceof RestoreReport) {
            return $resolved;
        }

        $sql = $this->contents($resolved);
        if ($sql === null) {
            return RestoreReport::failed('archive', $database);
        }

        if (! $this->headerIsDump($sql)) {
            return RestoreReport::failed('unrecognized', $database);
        }

        $statements = $this->statements($sql);
        if ($statements === null) {
            return RestoreReport::failed('unrecognized', $database);
        }

        $names = $this->databaseNames($statements);
        if ($names === null || $names !== [$database]) {
            return RestoreReport::failed('database', $database);
        }

        if (! $force) {
            return new RestoreReport(false, 'refused', $database, 0);
        }

        try {
            $pdo = $this->connect($connection);
        } catch (PDOException) {
            return RestoreReport::failed('connection', $database);
        }

        $applied = 0;
        foreach ($statements as $statement) {
            $failed = $this->execute($pdo, $statement, $database);
            if ($failed !== null) {
                return $failed;
            }
            $applied++;
        }

        return new RestoreReport(true, 'ok', $database, $applied);
    }

    /**
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $connection
     */
    private function connectionIsSafe(array $connection): bool
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $connection['host']) !== 1) {
            return false;
        }

        if ($connection['port'] < 1 || $connection['port'] > 65535) {
            return false;
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $connection['database']) !== 1) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_]+$/', $connection['username']) === 1;
    }

    private function resolvePath(string $path): string|RestoreReport
    {
        if (! str_ends_with($path, '.sql.gz')) {
            return RestoreReport::failed('path');
        }

        $real = realpath($path);
        if ($real === false || ! is_file($real)) {
            return RestoreReport::failed('path');
        }

        $normalized = str_replace('\\', '/', $real);
        if (str_contains($normalized, 'public_html') || str_contains($normalized, 'www/reserveworx.com')) {
            return RestoreReport::failed('path');
        }

        $mode = fileperms($real);
        if ($mode === false || ($mode & 0777) !== 0600) {
            return RestoreReport::failed('mode');
        }

        return $real;
    }

    private function contents(string $path): ?string
    {
        $compressed = file_get_contents($path);
        if (! is_string($compressed) || $compressed === '' || ! str_starts_with($compressed, "\x1f\x8b")) {
            return null;
        }

        set_error_handler(static fn (): bool => true);
        try {
            $sql = gzdecode($compressed);
        } finally {
            restore_error_handler();
        }
        if (! is_string($sql) || $sql === '') {
            return null;
        }

        return $sql;
    }

    private function headerIsDump(string $sql): bool
    {
        if ($sql === '') {
            return false;
        }

        $lines = preg_split("/\r\n|\n|\r/", $sql);
        if ($lines === false) {
            return false;
        }

        $head = implode("\n", array_slice($lines, 0, 40));

        return preg_match('/MySQL dump|MariaDB dump|CREATE TABLE|Dump completed/', $head) === 1;
    }

    /**
     * Split a mysqldump script on semicolons outside quotes and comments.
     * Conditional comments stay inside the statement. A routine delimiter,
     * or a trailing fragment, is refused.
     *
     * @return list<string>|null
     */
    private function statements(string $sql): ?array
    {
        if (preg_match('/^\s*DELIMITER\b/mi', $sql) === 1) {
            return null;
        }

        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                }

                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }

                continue;
            }

            if ($inSingle) {
                $buffer .= $char;
                if ($char === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;

                    continue;
                }
                if ($char === "'" && $next === "'") {
                    $buffer .= $next;
                    $i++;

                    continue;
                }
                if ($char === "'") {
                    $inSingle = false;
                }

                continue;
            }

            if ($inDouble) {
                $buffer .= $char;
                if ($char === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;

                    continue;
                }
                if ($char === '"' && $next === '"') {
                    $buffer .= $next;
                    $i++;

                    continue;
                }
                if ($char === '"') {
                    $inDouble = false;
                }

                continue;
            }

            if ($inBacktick) {
                $buffer .= $char;
                if ($char === '`' && $next === '`') {
                    $buffer .= $next;
                    $i++;

                    continue;
                }
                if ($char === '`') {
                    $inBacktick = false;
                }

                continue;
            }

            if ($char === '-' && $next === '-' && $this->startsLineComment($sql, $i + 2, $length)) {
                $inLineComment = true;
                $i++;

                continue;
            }

            if ($char === '#') {
                $inLineComment = true;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $third = $i + 2 < $length ? $sql[$i + 2] : '';
                if ($third === '!') {
                    $end = strpos($sql, '*/', $i + 3);
                    if ($end === false) {
                        return null;
                    }
                    $buffer .= substr($sql, $i, $end + 2 - $i);
                    $i = $end + 1;

                    continue;
                }

                $inBlockComment = true;
                $i++;

                continue;
            }

            if ($char === "'") {
                $inSingle = true;
                $buffer .= $char;

                continue;
            }

            if ($char === '"') {
                $inDouble = true;
                $buffer .= $char;

                continue;
            }

            if ($char === '`') {
                $inBacktick = true;
                $buffer .= $char;

                continue;
            }

            $buffer .= $char;
            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            return null;
        }

        return $statements;
    }

    private function startsLineComment(string $sql, int $index, int $length): bool
    {
        if ($index >= $length) {
            return true;
        }

        return in_array($sql[$index], [' ', "\t", "\n", "\r"], true);
    }

    /**
     * @param  list<string>  $statements
     * @return list<string>|null
     */
    private function databaseNames(array $statements): ?array
    {
        $names = [];
        foreach ($statements as $statement) {
            $found = $this->nameIn($statement);
            if ($found === null) {
                continue;
            }
            if ($found === '') {
                return null;
            }
            if (! in_array($found, $names, true)) {
                $names[] = $found;
            }
        }

        return $names === [] ? null : $names;
    }

    private function nameIn(string $statement): ?string
    {
        if (preg_match('/^CREATE\s+DATABASE\b/i', $statement) === 1) {
            if (preg_match('/`([A-Za-z0-9_]+)`/', $statement, $match) !== 1) {
                return '';
            }

            return $match[1];
        }

        if (preg_match('/^USE\s+`([A-Za-z0-9_]+)`\s*;?$/i', $statement, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $connection
     */
    private function connect(array $connection): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $connection['host'],
                $connection['port'],
                $connection['database'],
            ),
            $connection['username'],
            $connection['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ],
        );
    }

    private function execute(PDO $pdo, string $statement, string $database): ?RestoreReport
    {
        $named = $this->nameIn($statement);
        if ($named !== null && $named !== $database) {
            return RestoreReport::failed('database', $database);
        }

        try {
            $pdo->exec($statement);
        } catch (PDOException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ($named === $database && $driverCode === 1044 && str_starts_with($statement, 'CREATE')) {
                return null;
            }

            return RestoreReport::failed('sql', $database);
        }

        return null;
    }
}
