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

    // ---------------------------------------------------------------------------------
    // Only for hosted customer instances, where each run is booked and paid (see README,
    // «Hosted customer instances»). Leave it out for a club's own installation.
    // Then open https://<instance>/install?key=<setup_key> to enter the club and its admin.
    //
    // 'license' => [
    //     'enabled' => true,
    //     'setup_key' => '',        // at least 16 random characters, opens /install
    //     'secret' => '',           // at least 32 random characters, signs the activation links
    //     'prefix' => 'MUST',       // payment reference LF-MUST-0001 (2 to 8 letters or digits)
    //     'price_run' => 9900,      // in Rappen
    //     'price_mail' => 1000,     // option «Mailversand», paid once
    //     'twint_link' => '',       // your Twint payment link (https://go.twint.ch/1/e/tw?tw=acq.…)
    //     'operator_email' => '',   // gets every booking with the activation link
    //     'issuer' => "Name\nStreet 1\n3000 Town", // sender on the receipts
    //     'receipt_note' => '',     // optional line on the receipts
    //     'support_email' => '',    // optional, contact in the mails to the club
    //     'terms_url' => '',        // optional, link to the terms on the booking form
    // ],
    // // Your SMTP account: invitation at setup, bookings, receipts, and all mails of clubs
    // // that booked «Mailversand». The club never sees these values.
    // 'mail' => [
    //     'host' => '', 'port' => 465, 'encryption' => 'ssl', 'user' => '', 'pass' => '',
    //     'from_email' => 'noreply@lapfund.ch', 'from_name' => 'LapFund',
    //     'club_from_email' => '', // optional sender for the club's mails, e.g. musterdorf@lapfund.ch
    // ],
];
