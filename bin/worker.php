<?php

declare(strict_types=1);

// Sends due mails from the queue (architecture 9.1). Run it every minute by cron:
//   * * * * * php /path/to/lapfund/bin/worker.php
// It respects the sending limit from the mail settings (EM-12). Once a day it also
// runs the data retention (anonymizes sponsor data of old events, NF-11).

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$app = LapFund\App::boot(dirname(__DIR__));
if (!$app->config->isInstalled()) {
    fwrite(STDERR, "LapFund is not installed yet.\n");
    exit(1);
}
if ($app->migrator()->pending() !== []) {
    fwrite(STDERR, "Database updates are pending. Run them in the admin console (System) first.\n");
    exit(1);
}

$housekeeping = $app->retention()->runDaily();
if ($housekeeping['events'] > 0) {
    printf("data retention: %d events, %d pledges anonymized\n", $housekeeping['events'], $housekeeping['pledges']);
}
$result = $app->mailWorker()->run(500, 50);
if ($result['locked']) {
    echo "Another worker is running.\n";
    exit(0);
}
$counts = $app->mailQueue()->counts();
printf(
    "sent %d, failed %d, cancelled %d | queue: %d waiting, %d failed in total | limit left: %d%s\n",
    $result['sent'],
    $result['failed'],
    $result['cancelled'],
    $counts['queued'] + $counts['sending'],
    $counts['failed'],
    $result['allowance'],
    $app->mailSettings()->isConfigured() ? '' : ' | mail settings incomplete',
);
