# Changelog

All notable changes to LapFund. Versions follow [semantic versioning](https://semver.org/).
The section of a version is used as the release notes on GitHub.

## [1.4.1] – 2026-10

### Fixed
- Event overview: the status buttons are now in a logical order – «← Zurück zu …» on the left, «Weiter zu … →» on the right, like the status steps above ([#21](https://github.com/laudatioUno/LapFund/issues/21)).

## [1.4.0] – 2026-10

### Added
- Result mail to the participant after the run (EM-08): laps, kilometres, number of sponsors, amount collected and the part from the laps, with the button to the personal page (sponsor details are there). Sent when the laps are saved (checkbox, ticked by default) to everyone with laps or «did not start» who has not had one yet; participants without laps (e.g. running later) get it once their laps are entered. If the laps change after the mail, a correction goes out. «Did not start» gets its own wording.

### Database
- Migration `008_result_mail.sql`: what was sent per participation (date, laps, did not start).

## [1.3.0] – 2026-10

### Added
- Thank-you mail to the sponsor when the payment is ticked (EM-07): amount received, date, payment method and the button to the pledge. Also for «Vollständig bezahlt» (one mail per pledge). Sent 10 minutes after ticking; if the payment is taken back in the meantime, the mail is dropped. Switchable per event in the settings (on by default).

### Database
- Migration `007_payment_thanks.sql`: setting `thank_on_payment` per event; mails can refer to their pledge.

## [1.2.0] – 2026-10

### Added
- Mail to the participant when a sponsor pledges online («Neuer Sponsor für Lea»): sponsor name, amount, message, the new totals and the button to the participant page (EM-04). Not for pledges the participant or an admin enters. Only during the sponsor search; switchable per event in the settings (on by default).

### Changed
- Data retention: when a closed event is anonymized, the content of these mails (sponsor names) is deleted as well; queued ones are cancelled once the run is done.

### Database
- Migration `006_sponsor_notice.sql`: setting `notify_new_sponsor` per event.

## [1.1.0] – 2026-10

### Added
- Counting strips (tab *Runden*): one strip per participant with number, name, team, an empty field for the tally marks, total, «did not start» and counter; six strips per A4 page to cut apart and hand to the counters. For the whole event or per team ([#8](https://github.com/laudatioUno/LapFund/issues/8)). The lap list stays as before.

## [1.0.2] – 2026-10

### Fixed
- Admin console: name, role and «Abmelden» at the bottom of the side bar were pushed below long pages (e.g. Vereinseinstellungen, Mailversand, Änderungsprotokoll) and only visible after scrolling to the end. The side bar now stays in view, with the account box always at its bottom ([#15](https://github.com/laudatioUno/LapFund/issues/15)).

## [1.0.1] – 2026-10

### Fixed
- WhatsApp button on the participant page: on phones it now opens WhatsApp's «send to» picker with the prepared text instead of only the app's start screen (iPhone, [#13](https://github.com/laudatioUno/LapFund/issues/13)). Computers keep using WhatsApp Web.

### Added
- «Teilen …» button on the participant page: opens the phone's share menu (WhatsApp, Signal, Threema, SMS …); shown only where the browser supports it

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
