<?php

$envFile = dirname(__DIR__).'/.env';

if (! is_file($envFile)) {
    touch($envFile);
}

require dirname(__DIR__).'/vendor/autoload.php';
