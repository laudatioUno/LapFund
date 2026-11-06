# Changelog

All notable changes to LapFund. Versions follow [semantic versioning](https://semver.org/).
The section of a version is used as the release notes on GitHub.

## [1.8.0] – 2026-11

### Added
- Link preview in WhatsApp, iMessage & co. for the sponsor link (Open Graph): title «Unterstütze Lea beim Sponsorenlauf 2027» (first name only), description with club, date and place, and an image made from the logo – 1200 × 630 JPEG, the logo centred on white with a band in the club (or event) colour. No last name, no pledges or amounts.
- `apple-touch-icon` from the logo (180 × 180), used by iMessage and when a page is added to the home screen.

## [1.7.0] – 2026-11

### Changed
- «Stay in touch» on the sponsor form is now a card with title, text and a switch in the club colour instead of a plain checkbox; tapping anywhere on the card toggles it. Still never preselected and not needed for the pledge.
- *Vereinseinstellungen › Kontakt halten*: the title is configurable too (default «Newsletter vom <club>», up to 80 characters). The wording stored with each consent is title and text together; the CSV export shows it as «title – text».

## [1.6.0] – 2026-11

### Added
- «Stay in touch» (newsletter) checkbox on the sponsor form, below the consent: switch it on and set its text under *Vereinseinstellungen › Kontakt halten* (off by default, default text with the club name). Never preselected and not needed for the pledge; only on the sponsor's own form, not when a participant or admin enters a pledge.
- New page *Kontakte* for the club admin: name, e-mail, date and exact wording of the consent, source (event, for which participant), search and event filter, CSV export of the active contacts for a newsletter tool, unsubscribe and delete. One contact per e-mail address; a new consent updates it. Contacts are kept when an event is anonymized. Export, unsubscribe and delete are logged.

### Changed
- Sponsor and participant pledge forms: required fields and the consent are marked with *, with the note «Angaben mit * sind nötig zum Abschicken.»

### Database
- Migration `010_contacts.sql`: table `contacts`.

## [1.5.0] – 2026-11

### Added
- Twint payment link per event: in the event settings choose *Zahlungslink*, *QR-Code* or *Kein Twint*. With the payment link from the TWINT Business Portal, the pledge page shows TWINT's own pay button (embed snippet, unchanged) with amount and payment reference filled in (`trxInfo`, `amount`) – for flat pledges at once, for per-lap pledges once the laps are recorded after the run. On the phone it opens the Twint app, on a computer Twint shows a QR code. The button image is loaded from go.twint.ch; only this page allows TWINT's inline style and onclick, by hash.
- Payment reference per pledge, e.g. `SL26-000123-6`: prefix of the event (setting, default «SL» + year), the number of the pledge and a check digit. Set once when the pledge is saved, unique (database key), no names. Shown on the participant page, in the CSV exports and used as the message to enter with the QR code.
- Import of Twint payments (tab *Abrechnung*): CSV with «Datum», «Betrag Transaktion (CHF)», «Transaktionskosten (CHF)», «Zahlungszweck»; amount + costs is compared with the amount owed. Preview with «passt» (selected), «anderer Betrag», «schon bezahlt» and «nicht zugeordnet»; the selected rows are marked as paid with date and amount from the list. Search by payment reference.

### Changed
- Mails in payment link mode contain no Twint code, only the link to the pledge page.
- With the QR code, sponsors enter the payment reference as message instead of the names.

### Database
- Migration `009_twint_paylink.sql`: Twint mode, payment link and reference prefix per event; reference per pledge (existing pledges get theirs). Events with a QR code keep the QR mode.

## [1.4.3] – 2026-10

### Changed
- Sponsor list PDF for the participants is now in landscape format: wider columns for name and e-mail address to write by hand. Still fits on one page with up to 7 pledges plus empty rows.

## [1.4.2] – 2026-10

### Fixed
- Event and club settings: the change log only lists what really changed, with readable values (e.g. «Ort: Diessbach → Diessbach b. Büren», «Twint-QR-Code: ersetzt»). Saving without changes writes no entry; the save button stays inactive until something in the form is changed.

### Changed
- Laps can only be recorded in status *Lauf durchgeführt*; in *Abrechnung* they are locked. For a correction, set the status back to *Lauf durchgeführt* (correction mails to sponsors and participants work as before).

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
