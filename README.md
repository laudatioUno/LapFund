# LapFund

**LapFund** is a self-hosted web tool for sponsored runs ("Sponsorenlauf"): participants share a personal link, sponsors pledge online – a fixed amount or an amount per lap – and the club records laps and keeps track of payments. Made for clubs that want to replace paper lists without paying platform fees.

> Status: **stage 4 of 6**. Installer, admin login with two-factor authentication, admin accounts, club branding, events, teams, participants, mail sending, invitations with personal links, the participant page and sponsor pledges work. Laps, billing and reports follow in the next stages.

## Features

Stage 1 – foundation

- Web installer: checks the server, creates the tables, writes the configuration and the first club admin
- Admin login with username, password **and TOTP** (RFC 6238, any authenticator app), one-time recovery codes, lockout after repeated failures
- Two roles: **club admin** (whole installation) and **event admin** (assigned events, from stage 2)
- Invite new admins with a single-use link (valid 72 hours); reset access or two-factor of an admin
- Club branding: name, logo (PNG/JPG), club color with automatic text contrast, links to privacy policy and imprint
- Audit log of admin actions
- German user interface; all texts in `lang/de/*.php`, ready for further languages

Stage 2 – events and participants
- Events with date, location, lap length, entry deadline and an optional goal; created by the club admin, who assigns the event admins
- Event status in five steps (preparation, sponsor search, run done, billing, closed), one step forward or back; archive for finished events
- Per-event look and texts: logo, color, welcome text, purpose, mail sender name and footer, Twint QR code; empty values fall back to the club's
- Teams per event with custom order
- One participant pool for all events: add people one by one, from the pool, by CSV import with a preview (separator and Windows/Excel encoding detected), or by taking them over from an earlier event, with or without their teams
- Participant list with team filter and search

Stage 3 – mail and participant access
- Mail settings in the admin console: no-reply sender, SMTP server (SSL/TLS or STARTTLS), password stored encrypted, sending limit per minute and hour, test mail with understandable error messages
- Mail queue with send waves, progress display and mail log; failed mails are retried and can be sent again
- Invitation mail with a personal link (magic link) for every participant; participants added during the sponsor search are invited automatically; subject and intro editable per event
- Participant page: sums, personal sponsor link with WhatsApp, e-mail and QR code, a message for sponsors; siblings on the same phone can switch between their pages
- «Link lost» on the start page: one mail with the links of all participations of an address (siblings included), with the same answer for known and unknown addresses
- The event admin can issue a new link for a participant; the old one stops working at once

Stage 4 – sponsors
- Sponsor page behind the personal sponsor link: pledge a fixed amount or an amount per lap, pay by Twint or cash, with consent and spam protection
- Payment page: Twint QR code of the event, amount and the message to enter (participant / sponsor), or the cash note; payment details by mail on request
- Confirmation mail to the sponsor with the payment details
- Participants enter pledges themselves (e.g. from the paper list) and tick cash payments as received
- Sponsor list as PDF for printing, with a QR code to the sponsor link
- Entry deadline per event; after the run no new pledges

## Requirements

- PHP **8.3 or newer** with `pdo_mysql`, `sodium`, `gd`, `mbstring`, `openssl`, `dom`, `iconv`
- An SMTP account for sending mails (a no-reply address of the club, the hosting provider's mail server or a mail service)
- MySQL 8.0+ or MariaDB 10.6+ (an empty database)
- HTTPS
- Works on ordinary shared hosting: no root access, no containers, no Composer on the server

## Installation

1. Download `lapfund-<version>.zip` from the [releases](../../releases). It contains all libraries; nothing is loaded from the internet at runtime.
2. Upload and unpack it on your web space.
3. Point the domain's web root to the `public/` folder.
   If your hosting does not allow that, keep the web root on the LapFund folder: the included `.htaccess` blocks all other folders and forwards requests to `public/` (Apache with `mod_rewrite`).
4. Make `config/` and `storage/` writable for the web server.
5. Open the site in the browser. The installer starts automatically. **Run it right after uploading**, because until it has run anyone could open it.
6. After the installation, set up two-factor authentication with an authenticator app and store the recovery codes safely.
7. Open **Mailversand** in the admin console, enter the SMTP account and send a test mail.
8. Set up a cron job that sends the queued mails every minute (recommended):

   ```
   * * * * * php /path/to/lapfund/bin/worker.php
   ```

   If your hosting has no cron jobs, let an external cron service call the cron URL shown on the **Mailversand** page every minute. Without either, LapFund sends mails after page views (PHP-FPM) and while the progress page of a send wave is open.

### nginx

```nginx
root /path/to/lapfund/public;
index index.php;

location / {
    try_files $uri /index.php$is_args$args;
}
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
```

## Updating

Always use the release file `lapfund-<version>.zip` from the [releases](../../releases). GitHub's "Download ZIP" and "Source code" archives do not contain the libraries (`vendor/`) and will not work.

**Keep these, everything else comes from the new release:**

| Path | Content | Keep? |
| --- | --- | --- |
| `config/config.php` | Database access and the encryption key | **Yes, required.** Without it the installer starts again; without the key, two-factor logins and the SMTP password can no longer be read. |
| `storage/uploads/` | Uploaded logos and Twint QR codes | **Yes** |
| `storage/logs/` | Error logs | Optional |
| `storage/cache/` | Template cache | No, it is rebuilt automatically |
| everything else | Program files and libraries | No, replaced by the release |

Steps:

1. Back up the database (export as SQL) and the folders `config/` and `storage/`.
2. Move the current installation into a folder **outside the website**, e.g. `lapfund-old` (inside the website, `config.php` with passwords would no longer be protected by the `.htaccess`).
3. Unpack the new release ZIP. It contains a folder `lapfund/`: its **contents** go where the old installation was.
4. Copy back `config/config.php` and the contents of `storage/uploads/` from the old folder (and `storage/logs/` if you want to keep the logs). Do this before opening the site, otherwise the installer appears.
5. Check that `config/` and `storage/` with all subfolders are writable for the web server.
6. Log in as club admin. If the release brings database updates, you are sent to **System**: run them there (or run `php bin/migrate.php`). Until then, event admins see a maintenance notice and public pages are not available.
7. Delete the old folder. Keep a copy of `config/config.php` in a safe place.

Unpacking the release directly over the old files also works; files that no longer exist in the new version simply stay behind. Custom changes to the `.htaccess` files are overwritten in both cases.

## Security

- All secrets (TOTP keys, SMTP password) are encrypted with libsodium; the key is in `config/config.php`, outside the web root. **Never lose or change that key.**
- Passwords: Argon2id. Magic links and admin invitations: 256-bit random tokens, looked up by their SHA-256 hash; sponsor links: 128-bit random tokens, separate from the magic link.
- Links in mails are built from the website address in the mail settings, never from the request's host name.
- Public pages are not indexed by search engines and send no referrer.
- Strict Content Security Policy: only resources from your own server, no inline scripts or styles.
- Found a vulnerability? Please report it privately to the maintainer instead of opening a public issue.

## Development

```bash
composer install
php -S localhost:8000 -t public public/index.php   # development server
vendor/bin/phpunit                                  # unit tests
bin/build-release.sh                                # builds build/lapfund-<version>.zip
```

Structure:

| Folder | Content |
| --- | --- |
| `public/` | Web root: front controller `index.php`, CSS, JS, fonts |
| `src/` | Application code (`Http`, `Controller`, `Service`, `Repository`, `Security`, `I18n`) |
| `templates/` | Twig templates (auto-escaped) |
| `lang/de/` | German texts; copy the folder to add a language |
| `migrations/` | Numbered SQL migrations |
| `config/`, `storage/` | Created/used at runtime, not public |

Releases are built by GitHub Actions: set the version in `src/App.php` (`App::VERSION`), then open **Actions › Release › Run workflow** on GitHub and enter that version (or push a tag `v<version>`). The workflow runs the tests, builds the ZIP including `vendor/` with the versions pinned in `composer.lock`, creates the tag and publishes the release.

## License

LapFund is released under the [MIT License](LICENSE).
Bundled libraries keep their own licenses (MIT, BSD-3-Clause, Apache-2.0); see `THIRD-PARTY-LICENSES.txt` in the release ZIP.
Fonts: Barlow and Barlow Condensed by The Barlow Project Authors, [SIL Open Font License 1.1](public/assets/fonts/OFL.txt).
