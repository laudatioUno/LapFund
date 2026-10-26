# Anleitung für Event-Admins

Diese Anleitung führt Schritt für Schritt durch einen Sponsorenlauf mit LapFund: von der Vorbereitung über die Sponsorensuche und den Lauftag bis zur Abrechnung und Auswertung. Die Installation und den Betrieb beschreibt das [README](../README.md).

## Überblick

### Rollen

| Rolle | Darf |
| --- | --- |
| **Vereins-Admin** | Events erstellen und Event-Admins zuweisen, Admin-Konten verwalten, Vereinseinstellungen, Mailversand, System und Datensicherung, Personen endgültig löschen, Sponsorendaten anonymisieren. Sieht alle Events, bearbeitet Runden und Zahlungen aber nur, wenn er dem Event selbst zugewiesen ist. |
| **Event-Admin** | Die zugewiesenen Events führen: Einstellungen, Teams, Teilnehmende, Einladungen, Runden, Zahlungen, Auswertung und Export. |

### Der Ablauf eines Events

Ein Event durchläuft fünf Status. Du schaltest ihn auf der Event-Übersicht mit **Weiter zu «…»** einen Schritt weiter (oder zurück).

| Status | Was passiert |
| --- | --- |
| **Vorbereitung** | Event einrichten, Teams und Teilnehmende erfassen. Noch keine Mails. |
| **Sponsorensuche** | Die Kinder erhalten ihren persönlichen Link und suchen Sponsoren. Sponsoren tragen sich online ein. |
| **Lauf durchgeführt** | Keine neuen Zusagen mehr. Du erfasst die Runden. |
| **Abrechnung** | Zahlungsaufforderungen und Erinnerungen verschicken, Zahlungen abhaken. |
| **Abgeschlossen** | Nichts mehr änderbar. Auswertung und Export bleiben. Nach der Aufbewahrungsfrist werden die Sponsorendaten gelöscht. |

## 1. Vorbereitung

### Event anlegen (Vereins-Admin)

**Events › Event erstellen**: Name, Datum, Ort und die Event-Admins wählen. Nur zugewiesene Event-Admins können Runden erfassen und Zahlungen abhaken.

### Einstellungen des Events

Im Event unter **Einstellungen**:

- **Rundenlänge** in Metern: für die Kilometer in der Auswertung.
- **Eintragen bis**: danach können Sponsoren keine Zusagen mehr machen (freiwillig).
- **Erinnerung nach Tagen**: ab wann eine Zahlungserinnerung fällig ist (Standard 14 Tage).
- **Texte**: Willkommenstext und Zweck («Wir sammeln für neue Trikots») erscheinen auf der Seite für die Sponsoren. Betreff und Einleitung der Einladungsmail kannst du anpassen.
- **Twint**: QR-Code des Vereinskontos hochladen. Ohne QR-Code ist nur Barzahlung möglich.
- **Logo und Farbe**: leer lassen, dann gelten die des Vereins.

Die Checkliste auf der Event-Übersicht zeigt, was noch fehlt.

### Teams

Unter **Teams** die Teams anlegen (z. B. Junioren E, Junioren D) und mit den Pfeilen sortieren. Die Reihenfolge gilt für die Zählliste und die Auswertung.

### Teilnehmende

Unter **Teilnehmende › Teilnehmende hinzufügen** gibt es drei Wege:

- **Einzeln** mit Vorname, Nachname und E-Mail der Eltern. Geschwister dürfen dieselbe Adresse haben.
- **CSV importieren**: Datei aus Excel mit den Spalten Vorname, Nachname, E-Mail und optional Team. Du siehst zuerst eine Vorschau.
- **Aus früherem Event übernehmen**: alle oder ausgewählte Kinder, wahlweise mit ihren Teams.

## 2. Sponsorensuche

### Einladungen verschicken

1. Status auf **Sponsorensuche** stellen.
2. Unter **Mails** auf **Einladungen verschicken** klicken. Jedes Kind erhält eine Mail mit seinem persönlichen Link.
3. Der Fortschritt ist auf derselben Seite sichtbar. Fehlgeschlagene Mails lassen sich erneut senden.

Kinder, die du später hinzufügst, werden automatisch eingeladen. Geht ein Link verloren, können die Eltern auf der Startseite mit «Link verloren» alle Links ihrer Adresse anfordern. Du kannst bei einem Kind auch einen **neuen Link** erstellen; der alte funktioniert dann nicht mehr.

### Was die Kinder sehen

Auf ihrer Seite finden die Kinder (oder ihre Eltern):

- den Sponsor-Link zum Teilen per WhatsApp, Mail oder QR-Code,
- ihre Zusagen und Summen,
- die Möglichkeit, Zusagen von der Papierliste selbst einzutragen und Bargeld als erhalten abzuhaken,
- die Sponsorenliste als PDF zum Ausdrucken.

### Was die Sponsoren sehen

Über den Link des Kindes tragen sich Sponsoren ein: pauschal oder pro Runde, Zahlung mit Twint oder bar. Sie erhalten eine Bestätigung mit den Zahlungsangaben. Pauschale Twint-Beträge können sie sofort bezahlen.

Gleichzeitig erhält das Kind (bzw. die Mailadresse der Eltern) eine Mail «Neuer Sponsor» mit Name, Betrag und Nachricht des Sponsors und dem Link zur persönlichen Seite. Diese Mail lässt sich in den Einstellungen des Events abschalten.

## 3. Lauftag

Unter **Runden** gibt es zwei Vorlagen zum Drucken, jeweils für den ganzen Event oder pro Team:

- **Zählliste**: pro Team eine Seite mit Nummer, Name, Feld für die Strichliste, Total und Visum. Praktisch, wenn eine Person ein ganzes Team zählt.
- **Zählstreifen**: ein Streifen pro Kind, sechs pro A4-Seite zum Auseinanderschneiden. Jeder Zähler bekommt seinen Streifen, macht pro Runde einen Strich und trägt am Schluss das Total ein.

Die Nummern auf beiden Vorlagen entsprechen der Reihenfolge bei der Runden-Erfassung.

## 4. Nach dem Lauf

### Runden erfassen

1. Status auf **Lauf durchgeführt** stellen. Ab jetzt sind keine neuen Zusagen mehr möglich.
2. Unter **Runden** die Totale von der Zählliste übertragen. Mit Tab oder Enter springst du zur nächsten Person; die Reihenfolge entspricht der Zählliste.
3. Wer nicht gelaufen ist, bekommt **Nicht gestartet** (nicht 0 Runden): Beträge pro Runde fallen dann weg, pauschale Beträge bleiben geschuldet.

Die geschuldeten Beträge rechnet LapFund selbst aus: pauschal oder Runden × Betrag pro Runde.

### Zahlungsaufforderungen

1. Status auf **Abrechnung** stellen.
2. **Vorher:** Pauschale Twint-Zahlungen, die schon eingegangen sind, beim Kind als bezahlt abhaken. Sonst erhalten auch diese Sponsoren eine Aufforderung.
3. Unter **Abrechnung** mit **Vorschau** prüfen und eine **Testmail** an dich selbst schicken.
4. **Zahlungsaufforderungen senden**. Jede Zusage erhält eine eigene Mail mit Betrag und Twint-QR-Code oder dem Hinweis auf Barzahlung.

Nicht angeschrieben werden: bezahlte, stornierte und als «bar erhalten» markierte Zusagen sowie Zusagen pro Runde ohne erfasste Runden.

### Zahlungen abhaken

Beim Kind unter **Teilnehmende › Name**:

- **Einzeln**: Datum, Zahlungsart und erhaltener Betrag (kann vom geschuldeten Betrag abweichen).
- **Vollständig bezahlt**: hakt alle offenen Zusagen eines Kindes auf einmal ab, z. B. wenn es das gesammelte Bargeld abgibt. Lässt sich rückgängig machen.

Die Teilnehmerliste zeigt pro Kind geschuldet, bezahlt und offen; mit dem Filter «noch offen» siehst du, wo noch etwas fehlt.

### Erinnerungen

Unter **Abrechnung › Erinnerungen** erscheinen offene Zahlungen, deren Aufforderung länger als die eingestellte Frist zurückliegt. **Erinnerungen senden** schickt sie alle auf einmal.

### Korrekturen

- **Runden falsch?** Unter **Runden** korrigieren. Hatten Sponsoren schon eine Aufforderung, kannst du mit einem Häkchen eine Korrekturmail mit dem neuen Betrag senden.
- **Zusage falsch?** Beim Kind die Zusage öffnen und korrigieren, auch hier optional mit Korrekturmail.
- **Zusage ungültig?** Mit Begründung stornieren.
- **Zusage fehlt?** Beim Kind **Zusage erfassen**, mit oder ohne Bestätigungsmail.

Jede Änderung steht im **Protokoll** des Events und im Verlauf beim Kind.

## 5. Auswertung und Abschluss

### Auswertung

Der Tab **Auswertung** zeigt:

- vor dem Lauf die Zusagen pauschal und pro Runde,
- nach dem Lauf geschuldet, bezahlt und offen, Anteil pauschal / pro Runde, Runden, Ø Runden und Kilometer,
- die Summen pro Team,
- Ranglisten nach Runden und Sammelbetrag. **Nur für Admins**, sie erscheinen nirgends öffentlich,
- den Vergleich mit anderen Events.

### Export

Unter **Auswertung › Export** drei CSV-Dateien für Excel:

| Datei | Inhalt |
| --- | --- |
| Teilnehmende | Pro Kind: Team, Runden, Zusagen, geschuldet, bezahlt, offen, Zahlungsstatus |
| Zusagen | Alle Zusagen mit Sponsor, E-Mail, Art, Betrag, Status, geschuldetem Betrag |
| Zahlungen | Alle abgehakten Zahlungen mit Datum, Zahlungsart, erhaltenem Betrag und Differenz |

Die Dateien enthalten Personendaten: nur so lange aufbewahren wie nötig, nicht weitergeben.

### Abschliessen

Wenn alles bezahlt ist: Status auf **Abgeschlossen** stellen. Danach lässt sich nichts mehr ändern, die Auswertung bleibt.

Der Vereins-Admin kann ein abgeschlossenes Event **archivieren**; es verschwindet dann aus den Listen.

### Was mit den Daten passiert

- **Sponsoren** werden nicht erneut angeschrieben. 12 Monate nach dem Abschluss (einstellbar) löscht LapFund automatisch Name, E-Mail, Nachricht und Zahlungslink. Beträge und Runden bleiben für die Statistik. Der Vereins-Admin kann das auf der Event-Übersicht mit **Jetzt anonymisieren** auch sofort tun. Ein anonymisiertes Event kann nicht mehr geöffnet werden.
- **Teilnehmende** bleiben im Pool, damit du sie im nächsten Jahr übernehmen kannst. Unter **Personen › Inaktiv** sieht der Vereins-Admin, wer länger nicht mehr dabei war, und kann diese Personen endgültig löschen.

## 6. Für den Vereins-Admin

- **Admin-Konten**: Neue Admins erhalten einen Einladungslink (72 Stunden gültig). Dort können Zugang und Zwei-Faktor zurückgesetzt werden.
- **Mailversand**: SMTP-Zugang, Versandlimit und Testmail; das Mailprotokoll zeigt jeden Versand.
- **Vereinseinstellungen**: Name, Logo, Farbe, Kontaktadresse, Datenschutzerklärung und Impressum, Aufbewahrungsfristen.
- **Änderungsprotokoll**: alle Änderungen aller Events, filterbar nach Event und Bereich.
- **System**: Version, Datenbank-Aktualisierungen nach einem Update und **Datensicherung**. Für den Export der Datenbank ist der Code aus der Authenticator-App nötig. Zu einem vollständigen Backup gehören zusätzlich `config/config.php` und der Ordner `storage/uploads/`.

## Häufige Fragen

**Ein Kind findet seinen Link nicht mehr.**
Auf der Startseite «Link verloren» wählen und die E-Mail-Adresse eingeben, oder du erstellst beim Kind einen neuen Link.

**Ein Sponsor hat bar bezahlt, aber das Geld ist noch beim Kind.**
Das Kind hakt die Zusage auf seiner Seite als «bar erhalten» ab. Bezahlt ist sie erst, wenn du sie abhakst, zum Beispiel mit **Vollständig bezahlt** bei der Abgabe des Geldes.

**Ein Sponsor hat einen anderen Betrag überwiesen.**
Beim Abhaken den tatsächlich erhaltenen Betrag eintragen. Die Differenz steht im Export der Zahlungen.

**Die Mails kommen nicht an.**
Unter **Mailversand** eine Testmail schicken und das Mailprotokoll prüfen. Bleiben Mails in der Warteschlange, fehlt meist der Cronjob (siehe README).

**Wie viele Mails gehen auf einmal raus?**
So viele, wie das Versandlimit unter **Mailversand** erlaubt. Der Rest folgt automatisch in den nächsten Minuten.
