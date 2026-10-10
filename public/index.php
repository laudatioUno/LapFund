<?php

declare(strict_types=1);

// PHP built-in development server: let it serve existing static files itself.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_string($path) && $path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

LapFund\App::boot(dirname(__DIR__))->run();
