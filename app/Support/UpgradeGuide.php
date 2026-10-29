<?php

namespace App\Support;

/**
 * The upgrade procedure from Spec Section 17.
 * The sentences are the same ones docs/DEPLOY.md publishes.
 * Reading them does not deploy or write a price, tax, fee, or refund.
 */
final class UpgradeGuide
{
    public function intro(): string
    {
        return 'An upgrade replaces the live release with a newer commit of this same application. It is not a first install. Reading this procedure does not run a deploy and does not write a booking, a price, a payment, a tax, a fee, or a refund.';
    }

    /**
     * @return list<array{title: string, body: string}>
     */
    public function steps(): array
    {
        return [
            [
                'title' => 'Wait for CI',
                'body' => 'Deploy starts after CI passes for that commit, and only while that commit is still the tip of main. A failed CI run deploys nothing.',
            ],
            [
                'title' => 'The server does not run Composer',
                'body' => 'The runner installs dependencies. The server does not run Composer.',
            ],
            [
                'title' => 'Back up before migrations',
                'body' => 'Migrations run only after a verified private dump of this database. The file is mode 600 under laravel/shared/backups. If that dump is missing or does not verify, migrations do not run and the live release stays.',
            ],
            [
                'title' => 'Cache the new release first',
                'body' => 'Migrations, the role sync, and the config, route, and view caches run on the new release before it becomes current. If those steps fail, the live release stays.',
            ],
            [
                'title' => 'Keep one rollback release',
                'body' => 'The previous release stays on disk. Rollback swaps that code back and does not undo migrations.',
            ],
            [
                'title' => 'Check the public health URL',
                'body' => 'The health check requests https://app.reserveworx.com/up. A failed check swaps the code back once and does not roll migrations back.',
            ],
            [
                'title' => 'Point cron at the current release',
                'body' => 'Cron keeps calling laravel/current so the next minute uses the new release. Do not add a second cron.',
            ],
            [
                'title' => 'Smoke and readiness',
                'body' => 'After a healthy upgrade, reserveworx:smoke runs. Open /platform/readiness for the same local checks. Neither one writes a price, a tax, a fee, or a refund.',
            ],
            [
                'title' => 'Start a deploy',
                'body' => 'Open GitHub Actions, choose Deploy, and run the workflow on branch main. A later upgrade uses action deploy with migrations on. The workflow dumps the database first and fails if that dump does not verify. A bad release uses action rollback. That swaps the previous release and does not restore the database. This page does not run a deploy.',
            ],
        ];
    }
}
