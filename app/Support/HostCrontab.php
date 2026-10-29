<?php

namespace App\Support;

use Closure;
use Cron\CronExpression;
use DateTimeImmutable;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The user crontab that runs schedule:run, for Spec Section 2.2.
 *
 * Facts name the schedule expression and the shortest gap in minutes.
 * The command text, a path, and an assignment are not included.
 */
final class HostCrontab
{
    /**
     * @param  (Closure(): (?string))|null  $reader  Null runs `crontab -l`. A returned string is that file. Null means it could not be read.
     */
    public function __construct(private readonly ?Closure $reader = null) {}

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function facts(): array
    {
        $text = $this->read();

        if ($text === null) {
            return [['host_crontab', 'unavailable']];
        }

        if (trim($text) === '') {
            return [['host_crontab', 'empty']];
        }

        return $this->factsFromText($text);
    }

    private function read(): ?string
    {
        if ($this->reader !== null) {
            return ($this->reader)();
        }

        return $this->readProcess();
    }

    private function readProcess(): ?string
    {
        $binary = (new ExecutableFinder)->find('crontab');

        if ($binary === null) {
            return null;
        }

        $process = new Process([$binary, '-l']);
        $process->setTimeout(5);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if ($process->isSuccessful()) {
            return $process->getOutput();
        }

        $error = strtolower($process->getErrorOutput());

        if (str_contains($error, 'no crontab')) {
            return '';
        }

        return null;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function factsFromText(string $text): array
    {
        $expressions = [];

        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
            $expression = $this->scheduleExpression(trim($line));

            if ($expression === null) {
                continue;
            }

            $expressions[] = $expression;
        }

        $facts = [
            ['host_crontab', 'read'],
            ['host_crontab_schedule', $expressions === [] ? 'no' : 'yes'],
        ];

        foreach ($expressions as $expression) {
            $facts[] = ['host_crontab_expression', $expression];
        }

        if ($expressions !== []) {
            $facts[] = ['host_crontab_interval_minutes', $this->interval($expressions)];
        }

        return $facts;
    }

    /**
     * Null skips the line. `unparsed` is a schedule:run line whose cadence is not a user crontab expression.
     */
    private function scheduleExpression(string $line): ?string
    {
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*=/', $line) === 1) {
            return null;
        }

        if (preg_match('/\bschedule:run\b/', $line) !== 1) {
            return null;
        }

        if (preg_match('/^@([A-Za-z]+)\b/', $line, $alias) === 1) {
            $name = '@'.strtolower($alias[1]);
            $known = ['@hourly', '@daily', '@midnight', '@weekly', '@monthly', '@yearly', '@annually', '@reboot'];

            return in_array($name, $known, true) ? $name : 'unparsed';
        }

        $tokens = preg_split('/\s+/', $line) ?: [];
        $fields = [];

        foreach ($tokens as $token) {
            if (! $this->isCronField($token)) {
                break;
            }

            $fields[] = $token;
        }

        if (count($fields) !== 5) {
            return 'unparsed';
        }

        $expression = implode(' ', $fields);

        return CronExpression::isValidExpression($expression) ? $expression : 'unparsed';
    }

    private function isCronField(string $token): bool
    {
        if (strlen($token) > 64 || preg_match('/^[0-9A-Za-z*,\/\-]+$/', $token) !== 1) {
            return false;
        }

        if (preg_match('/[0-9*,\/\-]/', $token) === 1) {
            return true;
        }

        return in_array(strtoupper($token), [
            'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC',
            'SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT',
        ], true);
    }

    /**
     * @param  list<string>  $expressions
     */
    private function interval(array $expressions): string
    {
        $shortest = null;

        foreach ($expressions as $expression) {
            $minutes = $this->intervalMinutes($expression);

            if ($minutes === null) {
                return 'unparsed';
            }

            $shortest = $shortest === null ? $minutes : min($shortest, $minutes);
        }

        return $shortest === null ? 'unparsed' : (string) $shortest;
    }

    private function intervalMinutes(string $expression): ?int
    {
        if ($expression === 'unparsed' || ! CronExpression::isValidExpression($expression)) {
            return null;
        }

        try {
            $cron = new CronExpression($expression);
            $previous = DateTimeImmutable::createFromInterface(
                $cron->getNextRunDate(new DateTimeImmutable('2026-06-01 00:00:00 UTC'), 0, true),
            );
            $shortest = null;

            for ($step = 0; $step < 8; $step++) {
                $next = DateTimeImmutable::createFromInterface($cron->getNextRunDate($previous, 0, false));
                $seconds = $next->getTimestamp() - $previous->getTimestamp();

                if ($seconds < 60 || $seconds % 60 !== 0) {
                    return null;
                }

                $gap = (int) ($seconds / 60);
                $shortest = $shortest === null ? $gap : min($shortest, $gap);

                if ($shortest === 1) {
                    return 1;
                }

                $previous = $next;
            }
        } catch (Throwable) {
            return null;
        }

        return $shortest;
    }
}
