#!/usr/bin/env bash
# Write laravel/shared/.env for app.reserveworx.com. Values arrive in the
# environment. This script does not print them. Optional: RW_MAIL_MAILER and
# RW_POSTMARK_TOKEN (Postmark; the default mailer stays log).
set -euo pipefail

: "${RW_APP_KEY:?RW_APP_KEY is required}"
: "${RW_DB_DATABASE:?RW_DB_DATABASE is required}"
: "${RW_DB_USERNAME:?RW_DB_USERNAME is required}"
: "${RW_DB_PASSWORD:?RW_DB_PASSWORD is required}"

domain="${HOME}/www/app.reserveworx.com"
shared="${domain}/laravel/shared"
umask 077
mkdir -p \
  "$shared" \
  "${shared}/storage/framework/cache/data" \
  "${shared}/storage/framework/sessions" \
  "${shared}/storage/framework/views" \
  "${shared}/storage/logs" \
  "${shared}/storage/app/public"

export RW_ENV_PATH="${shared}/.env"
php << 'PHP'
<?php
$path = getenv('RW_ENV_PATH');
$values = [
    'APP_NAME' => 'ReserveWorx',
    'APP_ENV' => 'production',
    'APP_KEY' => getenv('RW_APP_KEY'),
    'APP_DEBUG' => 'false',
    'APP_URL' => 'https://app.reserveworx.com',
    'LOG_CHANNEL' => 'daily',
    'LOG_DAILY_DAYS' => '7',
    'LOG_LEVEL' => 'error',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_DATABASE' => getenv('RW_DB_DATABASE'),
    'DB_USERNAME' => getenv('RW_DB_USERNAME'),
    'DB_PASSWORD' => getenv('RW_DB_PASSWORD'),
    'SESSION_DRIVER' => 'database',
    'SESSION_LIFETIME' => '120',
    'QUEUE_CONNECTION' => 'database',
    'QUEUE_WORK_MAX_TIME' => '20',
    'CACHE_STORE' => 'database',
    // Deploy refreshes SMTP from the MAIL_PASSWORD secret. This full rewrite
    // stays on the log mailer so a bootstrap without that secret cannot send.
    'MAIL_MAILER' => getenv('RW_MAIL_MAILER') ?: 'log',
    'MAIL_FROM_ADDRESS' => 'no-reply@reserveworx.com',
    'POSTMARK_MESSAGE_STREAM_ID' => 'outbound',
    'PLATFORM_DOMAINS' => 'app.reserveworx.com',
];

// Optional. Written only when the caller supplies it, and never printed.
if (getenv('RW_POSTMARK_TOKEN')) {
    $values['POSTMARK_TOKEN'] = getenv('RW_POSTMARK_TOKEN');
}

$lines = [];
foreach ($values as $key => $value) {
    $value = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
    $lines[] = $key.'="'.$value.'"';
}

file_put_contents($path, implode("\n", $lines)."\n");
chmod($path, 0600);

try {
    $dsn = 'mysql:host=localhost;port=3306;dbname='.getenv('RW_DB_DATABASE').';charset=utf8mb4';
    new PDO($dsn, (string) getenv('RW_DB_USERNAME'), (string) getenv('RW_DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "db_login=ok\n";
} catch (PDOException $exception) {
    $state = $exception->errorInfo[0] ?? 'unknown';
    echo "db_login=failed sqlstate={$state}\n";
    exit(1);
}

echo "shared_env=written\n";
PHP
