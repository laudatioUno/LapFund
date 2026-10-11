# Changelog

All notable changes to LapFund. Versions follow [semantic versioning](https://semver.org/).
The section of a version is used as the release notes on GitHub.

## [1.0.0] – 2026-10

First complete version: a sponsored run can be handled from preparation to the final report.

### Added
- Event tab **Auswertung**: owed, paid and open amounts, share flat / per lap, pledges, laps, average laps and total kilometres; sums per team; rankings by laps and by collected amount (admins only); comparison with other events
- CSV export of participants, pledges and payments for Excel (UTF-8 with BOM, semicolon)
- Event tab **Protokoll** and filters (event, area) with paging in the change log of the installation; readable details such as «Runden: 23 → 25»; history on the participant and pledge pages
- Data retention: sponsor data of a closed event is anonymized automatically after a set time (default 12 months) or at once by the club admin; also removed from the mail log and the change log; amounts and laps stay for the statistics
- Participants: list of inactive participants (default: no event for 2 years) that the club admin can delete; past events keep anonymous figures
- Database export (SQL) under **System › Datensicherung**, confirmed with the authenticator code
- Settings for the retention period and the inactivity limit
- Documentation: guide for event admins (`docs/anleitung.md`), backup and restore, data protection, `SECURITY.md`, this changelog

### Changed
- The daily data retention runs with the existing cron job (`bin/worker.php` or the cron URL); no new cron job needed
- Closing an event records the date; an anonymized event cannot be reopened
- Release notes are taken from this changelog

### Database
- Migration `005_reports.sql`: event of each change log entry, closing and anonymization date of events, deletion date of participants

## [0.5.0] – 2026-10

### Added
- Lap list as PDF, quick entry of laps, «did not start»
- Amounts owed, payment requests with preview and test mail, correction mails, reminders
- Tick payments one by one or all of a participant, undo; correct, cancel and add pledges
- Participant list with sums and payment state

## [0.4.0] – 2026-10

### Added
- Sponsor page with pledge per lap or flat amount, Twint or cash; payment page with Twint QR code
- Confirmation and payment details mails; participants enter pledges and tick cash
- Sponsor list as PDF

## [0.3.0] – 2026-10

### Added
- SMTP settings, mail queue with sending limit, mail log
- Invitation mails with personal links, «link lost», participant page

## [0.2.0] – 2026-10

### Added
- Events with status, teams, participant pool, CSV import, taking over participants from earlier events

## [0.1.0] – 2026-10

### Added
- Installer, admin login with TOTP, admin accounts with roles, club branding, change log
