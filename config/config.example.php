<?php

// Example configuration. The web installer writes config/config.php for you.
// Only create it by hand if you cannot use the installer.

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'lapfund',
        'user' => 'lapfund',
        'pass' => 'change-me',
    ],
    'app' => [
        // 32 random bytes, base64. Create with: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
        // Never change it later: encrypted values (TOTP secrets) could no longer be read.
        'key' => '',
        'locale' => 'de',
        'timezone' => 'Europe/Zurich',
        'debug' => false,
        'cron_secret' => '',
    ],
];
