<?php

declare(strict_types=1);

// Runs pending database migrations from the command line:
//   php bin/migrate.php
// The same is available in the admin console under System.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$config = LapFund\Config::load(dirname(__DIR__));
if (!$config->isInstalled()) {
    fwrite(STDERR, "LapFund is not installed yet. Run the web installer first.\n");
    exit(1);
}

$db = LapFund\Database\Database::connect($config->db());
$migrator = new LapFund\Database\Migrator($db, dirname(__DIR__) . '/migrations');
$done = $migrator->migrate();

echo $done === [] ? "Database is up to date.\n" : 'Applied: ' . implode(', ', $done) . "\n";
