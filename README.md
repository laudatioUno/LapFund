# LapFund

**LapFund** is a self-hosted web tool for sponsored runs ("Sponsorenlauf"): participants share a personal link, sponsors pledge online – a fixed amount or an amount per lap – and the club records laps, requests the payments and keeps track of them. Made for clubs that want to replace paper lists without paying platform fees.

> Version **1.0.0**. A sponsored run can be handled from preparation to the final report: events, participants, invitations, sponsor pledges, laps, payments, reports, export and data retention.
> A step-by-step guide for event admins (German) is in [docs/anleitung.md](docs/anleitung.md).

## Features

**Events and participants**
- Events with date, location, lap length and entry deadline; the club admin creates them and assigns the event admins
- Event status in five steps (preparation, sponsor search, run done, billing, closed), one step forward or back; archive for finished events
- Per-event look and texts: logo, color, welcome text, purpose, mail sender name and footer, Twint QR code; empty values fall back to the club's
- Teams per event with custom order
- One participant pool for all events: add people one by one, from the pool, by CSV import with a preview, or by taking them over from an earlier event

**Mail and participant access**
- SMTP settings with test mail and understandable error messages; mail queue with sending limit per minute and hour, progress display and mail log
- Invitation mail with a personal link (magic link) for every participant; «link lost» on the start page
- Participant page: sums, personal sponsor link with WhatsApp, e-mail and QR code, a message for sponsors; siblings on the same phone can switch between their pages

**Sponsors**
- Sponsor page behind the personal sponsor link: a fixed amount or an amount per lap, Twint or cash, with consent and spam protection
- Payment page with the Twint QR code of the event, amount and message, or the cash note; confirmation mail with the payment details
- Mail to the participant for every new online pledge, with the new totals (switchable per event)
- Participants enter pledges themselves (e.g. from the paper list) and tick cash as received; sponsor list as PDF

**Run and billing**
- Lap list as PDF for counting on the day; quick entry of the laps, «did not start» separate from 0 laps
- Amounts owed are calculated (fixed amount, or laps × amount per lap), never stored twice
- Payment requests (one mail per pledge) with preview, test mail and filters; correction mails; reminders after a configurable number of days
- Tick payments (date, method, amount received), or all pledges of a participant at once when the cash is handed in; undo possible
- Thank-you mail to the sponsor when the payment is ticked (switchable per event)
- Correct, cancel and add pledges as event admin

**Reports and closing**
- Event dashboard: owed, paid, open, share flat / per lap, number of pledges, average laps, total kilometres
- Sums per team, rankings by laps and by collected amount (admins only, never public), comparison with earlier events
- CSV export of participants, pledges and payments for Excel (UTF-8 with BOM, semicolon)
- Change log: who changed what and when, per event and for the whole installation, with filters; history on every participant and pledge
- Data retention: sponsor data is anonymized a set time after an event is closed (default 12 months), or at once on request; inactive participants can be deleted
- Database export (SQL) from the admin console for backups

## Requirements

- PHP **8.3 or newer** with `pdo_mysql`, `sodium`, `gd`, `mbstring`, `openssl`, `dom`, `iconv`
- MySQL 8.0+ or MariaDB 10.6+ (an empty database)
- An SMTP account for sending mails (a no-reply address of the club, the hosting provider's mail server or a mail service)
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
7. Under **Vereinseinstellungen**, add the club's contact address and the link to its privacy policy (shown to sponsors when they pledge).
8. Open **Mailversand**, enter the SMTP account and send a test mail.
9. Set up the cron job (see below).

### Cron job

One cron job every minute sends the queued mails and, once a day, runs the data retention:

```
* * * * * php /path/to/lapfund/bin/worker.php
```

If your hosting has no cron jobs, let an external cron service call the cron URL shown on the **Mailversand** page every minute. Without either, LapFund sends mails and runs the daily data retention after page views (PHP-FPM), and sends mails while the progress page of a send wave is open.

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
| `config/config.php` | Database access and the encryption key | **Yes, required.** Without it the installer starts again; without the key, two-factor logins, participant links and the SMTP password can no longer be read. |
| `storage/uploads/` | Uploaded logos and Twint QR codes | **Yes** |
| `storage/logs/` | Error logs | Optional |
| `storage/cache/` | Template and font cache | No, it is rebuilt automatically |
| everything else | Program files and libraries | No, replaced by the release |

Steps:

1. Make a backup (see below).
2. Move the current installation into a folder **outside the website**, e.g. `lapfund-old` (inside the website, `config.php` with passwords would no longer be protected by the `.htaccess`).
3. Unpack the new release ZIP. It contains a folder `lapfund/`: its **contents** go where the old installation was.
4. Copy back `config/config.php` and the contents of `storage/uploads/` from the old folder (and `storage/logs/` if you want to keep the logs). Do this before opening the site, otherwise the installer appears.
5. Check that `config/` and `storage/` with all subfolders are writable for the web server.
6. Log in as club admin. If the release brings database updates, you are sent to **System**: run them there (or run `php bin/migrate.php`). Until then, event admins see a maintenance notice.
7. Delete the old folder. Keep a copy of `config/config.php` in a safe place.

Unpacking the release directly over the old files also works; files that no longer exist in the new version simply stay behind. Custom changes to the `.htaccess` files are overwritten in both cases.

## Backup and restore

A complete backup has three parts:

| Part | How |
| --- | --- |
| Database | Admin console › **System** › **Datensicherung**: confirm with the code of your authenticator app and download the SQL file. Your hosting's database backup or phpMyAdmin export works as well. |
| `config/config.php` | Copy the file. It holds the encryption key: without it, the participant links, two-factor logins and the SMTP password in a restored database cannot be read. |
| `storage/uploads/` | Copy the folder (logos and Twint QR codes). |

The SQL file contains all personal data. Store it encrypted or in a safe place and delete old copies.

**Restore**

1. Create an empty database (utf8mb4).
2. Import the SQL file, e.g. with phpMyAdmin › Import, or `mysql -u USER -p DATABASE < lapfund-backup-….sql`.
3. Install the same LapFund version (or newer) as described above, put back `config/config.php` (adjust the database access in it if it changed) and `storage/uploads/`.
4. Log in. Newer versions run their database updates on the **System** page.

## Data protection

LapFund is built for the Swiss data protection act (revDSG) and stores as little as possible:

- **Sponsors:** name, e-mail and an optional message; consent with a link to the club's privacy policy. Sponsors are never contacted again for a later event. A set time after an event is **closed** (default **12 months**, changeable under **Vereinseinstellungen**) their name, e-mail, message and payment link are deleted automatically, also from the mail log and the change log. Amounts, laps and payment states stay for the statistics. On a closed event the club admin can do this at once with **Jetzt anonymisieren**.
- **Participants (children):** first name, last name and a parents' e-mail address. They stay in the pool for later events. Under **Personen › Inaktiv** the club admin can delete everybody who has not taken part for a set number of years (default 2); past events keep their laps and amounts anonymously.
- **Mails:** the content of sent mails is deleted after 30 days.
- **IP addresses** are only used hashed for rate limits and deleted after 24 hours.
- Public pages are not indexed by search engines; the children's names are never listed publicly, rankings are visible to admins only.
- CSV exports and the database export contain personal data; every download is recorded in the change log.

## Security

- All secrets (TOTP keys, SMTP password, participant and payment links) are encrypted with libsodium; the key is in `config/config.php`, outside the web root. **Never lose or change that key.**
- Admin login with password (Argon2id) and TOTP, one-time recovery codes, lockout after repeated failures. The database export asks for the TOTP code again.
- Magic links and admin invitations: 256-bit random tokens, looked up by their SHA-256 hash; sponsor links: 128-bit random tokens, separate from the magic link.
- Links in mails are built from the website address in the mail settings, never from the request's host name.
- Strict Content Security Policy: only resources from your own server, no inline scripts or styles. CSV exports neutralise cell values Excel would read as formulas.
- Found a vulnerability? See [SECURITY.md](SECURITY.md).

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
| `src/` | Application code (`Http`, `Controller`, `Service`, `Repository`, `Domain`, `Mail`, `Security`, `I18n`) |
| `templates/` | Twig templates (auto-escaped), mail and PDF templates |
| `lang/de/` | German texts; copy the folder to add a language |
| `migrations/` | Numbered SQL migrations, applied in order |
| `bin/` | `worker.php` (cron), `migrate.php`, release build |
| `docs/` | Guide for event admins |
| `config/`, `storage/` | Created/used at runtime, not public |

Every pull request builds a test package: **Checks › CI › Summary › Artifacts**; the summary also says whether merging will publish a release.

Releases are published automatically by GitHub Actions after a merge to `main`. The version comes from the code (`App::VERSION` in `src/App.php`):

- To publish a new version, raise `App::VERSION` and add a section `## [x.y.z]` to [CHANGELOG.md](CHANGELOG.md) in the pull request, then merge. The workflow runs the tests, builds the ZIP including `vendor/` with the versions pinned in `composer.lock`, creates the tag `v<version>` and publishes the release with the changelog section as release notes.
- If that version has been released already (e.g. a merge that only changes documentation), nothing is published.
- Versions with a suffix such as `1.3.0-beta.1` become pre-releases.
- **Actions › Release › Run workflow** does the same by hand, e.g. to retry after a failed run.

Contributions are welcome: please open an issue first for larger changes. Texts for the user interface go into `lang/de/` (a test checks that every key exists).

## License

LapFund is released under the [MIT License](LICENSE).
Bundled libraries keep their own licenses (MIT, BSD-3-Clause, Apache-2.0, LGPL-2.1 and LGPL-3.0); see `THIRD-PARTY-LICENSES.txt` in the release ZIP.
Fonts: Barlow and Barlow Condensed by The Barlow Project Authors, [SIL Open Font License 1.1](public/assets/fonts/OFL.txt).
