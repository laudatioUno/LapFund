<?php

declare(strict_types=1);

// Booked runs of a customer instance, for the operator on the server:
//   php bin/license.php list           all bookings
//   php bin/license.php list --open    only bookings waiting for the payment
//   php bin/license.php activate LF-MUST-0003
// Activating does the same as the button in the operator's mail: the run is
// unlocked and the club gets its receipt by mail.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$app = LapFund\App::boot(dirname(__DIR__));
if (!$app->config->isInstalled() || !$app->license()->enabled()) {
    fwrite(STDERR, "This is not a customer instance (no licence section in config/config.php).\n");
    exit(1);
}
if ($app->migrator()->pending() !== []) {
    fwrite(STDERR, "Database updates are pending. Run them in the admin console (System) first.\n");
    exit(1);
}

$licensing = $app->licensing();
$command = $argv[1] ?? '';

if ($command === 'list') {
    $rows = $licensing->bookings(in_array('--open', $argv, true) ? 'open' : null);
    if ($rows === []) {
        echo "No bookings.\n";
        exit(0);
    }
    foreach ($rows as $b) {
        printf(
            "%-14s %-9s %-10s %10s  %s (%s)\n",
            $b['reference'],
            $b['status'],
            substr((string) $b['booked_at'], 0, 10),
            $app->formatter->money(LapFund\Service\Licensing::total($b)),
            $b['event_name'],
            $b['booked_by_name'],
        );
    }
    exit(0);
}

if ($command === 'activate') {
    $reference = strtoupper(trim($argv[2] ?? ''));
    $booking = $reference !== '' ? $licensing->findByReference($reference) : null;
    if ($booking === null) {
        fwrite(STDERR, "Unknown booking: {$reference}\n");
        exit(1);
    }
    if (!$licensing->activate($booking, 'cli')) {
        fwrite(STDERR, "{$reference} is not open (status: {$booking['status']}).\n");
        exit(1);
    }
    echo "{$reference} activated, receipt sent to {$booking['booked_by_email']}.\n";
    exit(0);
}

fwrite(STDERR, "Usage: php bin/license.php list [--open] | activate <reference>\n");
exit(1);
