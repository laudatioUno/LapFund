# LapFund

**LapFund** is a self-hosted web tool for sponsored runs ("Sponsorenlauf"): participants share a personal link, sponsors pledge online – a fixed amount or an amount per lap – and the club records laps and keeps track of payments. Made for clubs that want to replace paper lists without paying platform fees.

> Status: **stage 1 of 6 (foundation)**. Installer, admin login with two-factor authentication, admin accounts and club branding work. Events, participants, sponsors and payments follow in the next stages.

## Features (stage 1)

- Web installer: checks the server, creates the tables, writes the configuration and the first club admin
- Admin login with username, password **and TOTP** (RFC 6238, any authenticator app), one-time recovery codes, lockout after repeated failures
- Two roles: **club admin** (whole installation) and **event admin** (assigned events, from stage 2)
- Invite new admins with a single-use link (valid 72 hours); reset access or two-factor of an admin
- Club branding: name, logo (PNG/JPG), club color with automatic text contrast, links to privacy policy and imprint
- Audit log of admin actions
- German user interface; all texts in `lang/de/*.php`, ready for further languages

## Requirements

- PHP **8.3 or newer** with `pdo_mysql`, `sodium`, `gd`, `mbstring`
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

1. Back up the database and the folders `config/` and `storage/`.
2. Upload the new release over the old files (keep `config/` and `storage/`).
3. In the admin console open **System** and run the pending database updates, or run `php bin/migrate.php`.

## Security

- All secrets (TOTP keys) are encrypted with libsodium; the key is in `config/config.php`, outside the web root. **Never lose or change that key.**
- Passwords: Argon2id. Links (invitations, later magic links): 256-bit random tokens, stored only as SHA-256 hashes.
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
