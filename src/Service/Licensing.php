<?php

declare(strict_types=1);

namespace LapFund\Service;

use LapFund\App;
use LapFund\Database\Database;
use LapFund\I18n\Formatter;
use LapFund\I18n\Translator;
use LapFund\Mail\MailComposer;
use LapFund\Mail\MailException;
use LapFund\Mail\MailSettings;
use LapFund\Mail\MailTransport;
use LapFund\Repository\AuditLog;
use LapFund\Repository\EventRepository;
use LapFund\Repository\SettingsRepository;
use LapFund\View\View;

/**
 * Runs on hosted customer instances: the club books a run, pays it by Twint and the
 * operator activates it with the signed link in his mail (or on the command line).
 * Until then the event can be prepared, but the sponsor search cannot start.
 *
 * Settings: license_credit ('run' or 'run_mail': first run paid with the order on the
 * website), license_mail ('1': option «Mailversand» paid, valid for all runs).
 */
final class Licensing
{
    /**
     * @param \Closure(): MailTransport $systemTransport the operator's SMTP
     * @param (\Closure(\Throwable): void)|null $log
     */
    public function __construct(
        private readonly Database $db,
        private readonly License $license,
        private readonly SettingsRepository $settings,
        private readonly EventRepository $events,
        private readonly MailSettings $mail,
        private readonly MailComposer $composer,
        private readonly View $view,
        private readonly Pdf $pdf,
        private readonly AuditLog $audit,
        private readonly Translator $translator,
        private readonly Formatter $formatter,
        private readonly \Closure $systemTransport,
        private readonly ?\Closure $log = null,
    ) {
    }

    public function enabled(): bool
    {
        return $this->license->enabled();
    }

    public function license(): License
    {
        return $this->license;
    }

    public function mailIncluded(): bool
    {
        return $this->settings->get('license_mail') === '1';
    }

    /** 'run' or 'run_mail' while the first run, paid on the website, is not yet created. */
    public function credit(): ?string
    {
        $credit = $this->settings->get('license_credit');

        return in_array($credit, ['run', 'run_mail'], true) ? $credit : null;
    }

    /** The option «Mailversand» can be added to this booking. */
    public function offersMail(): bool
    {
        if ($this->mailIncluded() || $this->credit() !== null || trim((string) ($this->license->mailConfig()['host'] ?? '')) === '') {
            return false;
        }

        return (int) $this->db->value("SELECT COUNT(*) FROM license_bookings WHERE status = 'open' AND amount_mail_cents > 0") === 0;
    }

    /** Value for the new event: pending until paid. */
    public function initialState(): string
    {
        return $this->enabled() ? 'pending' : 'active';
    }

    /**
     * Books the run for a newly created event (in the same transaction). With a credit
     * the run is paid already and active at once; otherwise it waits for the payment.
     *
     * @param array<string, mixed> $user the club admin
     * @return array<string, mixed> the booking
     */
    public function book(int $eventId, array $user, bool $withMail): array
    {
        $event = $this->events->find($eventId) ?? throw new \RuntimeException('Event not found');
        $credit = $this->credit();
        $now = Database::now();
        $row = [
            'event_id' => $eventId,
            'event_name' => (string) $event['name'],
            'event_date' => $event['event_date'],
            'club_name' => $this->settings->get('club_name'),
            'amount_run_cents' => $this->license->priceRun(),
            'amount_mail_cents' => 0,
            'booked_by' => (int) $user['id'],
            'booked_by_name' => (string) $user['display_name'],
            'booked_by_email' => (string) $user['email'],
            'booked_at' => $now,
        ];
        if ($credit !== null) {
            $row += ['status' => 'paid', 'paid_via' => 'website', 'paid_at' => $now, 'activated_via' => 'credit'];
            $row['amount_mail_cents'] = $credit === 'run_mail' ? $this->license->priceMail() : 0;
        } elseif ($withMail && $this->offersMail()) {
            $row['amount_mail_cents'] = $this->license->priceMail();
        }

        $id = $this->db->insert('license_bookings', $row);
        $this->db->update('license_bookings', ['reference' => $this->license->reference($id)], ['id' => $id]);
        if ($credit !== null) {
            $this->settings->set('license_credit', null);
        }
        $this->events->update($eventId, ['license_state' => $credit !== null ? 'active' : 'pending']);
        $booking = $this->find($id);
        $this->audit->log((int) $user['id'], 'license_booked', 'event', $eventId, null, [
            'event_id' => $eventId,
            'reference' => $booking['reference'],
            'license_amount' => self::total($booking),
            'license_paid' => $credit !== null,
        ]);

        return $booking;
    }

    /**
     * Mails after the booking (outside the transaction): the operator gets the
     * activation link, or the club its receipt if the run was paid already.
     */
    public function afterBooking(array $booking): void
    {
        if ($booking['status'] === 'paid') {
            $this->sendReceipt($booking);

            return;
        }
        $vars = [
            'booking' => $booking,
            'total' => self::total($booking),
            'instance' => $this->instanceName(),
            'activate_url' => $this->activationUrl($booking),
            'booked_at' => $this->formatter->datetime((string) $booking['booked_at']),
        ];
        $subject = $this->translator->t('mail.license.booking_subject', ['club' => $booking['club_name'], 'event' => $booking['event_name']]);
        $this->send('license_booking', $this->license->operatorEmail(), '', $subject, $this->operatorBrand(), $vars);
    }

    public function activationUrl(array $booking): string
    {
        $ref = (string) $booking['reference'];

        return $this->mail->url('/license/activate/' . rawurlencode($ref) . '?sig=' . $this->license->signature($ref));
    }

    /**
     * Marks the booking as paid and activates the event. Returns false if the booking
     * was not open (activated already or deleted).
     */
    public function activate(array $booking, string $via): bool
    {
        if ($booking['status'] !== 'open') {
            return false;
        }
        $done = $this->db->transaction(function (Database $db) use ($booking, $via): bool {
            $changed = $db->run(
                "UPDATE license_bookings SET status = 'paid', paid_at = ?, activated_via = ? WHERE id = ? AND status = 'open'",
                [Database::now(), $via, (int) $booking['id']],
            )->rowCount();
            if ($changed !== 1) {
                return false;
            }
            if ($booking['event_id'] !== null) {
                $this->events->update((int) $booking['event_id'], ['license_state' => 'active']);
            }
            if ((int) $booking['amount_mail_cents'] > 0) {
                $this->settings->set('license_mail', '1');
            }

            return true;
        });
        if (!$done) {
            return false;
        }
        $this->audit->log(null, 'license_activated', 'event', $booking['event_id'] !== null ? (int) $booking['event_id'] : null, null, [
            'event_id' => $booking['event_id'] !== null ? (int) $booking['event_id'] : null,
            'reference' => $booking['reference'],
            'license_via' => $via,
        ]);
        $this->sendReceipt($this->find((int) $booking['id']) ?? $booking);

        return true;
    }

    /** An unpaid run is deleted: its booking is kept as cancelled. */
    public function cancelForEvent(int $eventId): void
    {
        $this->db->run(
            "UPDATE license_bookings SET status = 'cancelled', cancelled_at = ? WHERE event_id = ? AND status = 'open'",
            [Database::now(), $eventId],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM license_bookings WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByReference(string $reference): ?array
    {
        return $this->db->one('SELECT * FROM license_bookings WHERE reference = ?', [$reference]);
    }

    /** @return array<string, mixed>|null the current booking of an event */
    public function forEvent(int $eventId): ?array
    {
        return $this->db->one(
            "SELECT * FROM license_bookings WHERE event_id = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1",
            [$eventId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function bookings(?string $status = null): array
    {
        return $status === null
            ? $this->db->all('SELECT * FROM license_bookings ORDER BY id')
            : $this->db->all('SELECT * FROM license_bookings WHERE status = ? ORDER BY id', [$status]);
    }

    public static function total(array $booking): int
    {
        return (int) $booking['amount_run_cents'] + (int) $booking['amount_mail_cents'];
    }

    /** Twint link of the operator with reference and amount filled in. */
    public function payUrl(array $booking): string
    {
        return TwintPaylink::url($this->license->twintLink(), (string) $booking['reference'], self::total($booking));
    }

    public function receiptPdf(array $booking): string
    {
        $html = $this->view->renderMail('pdf/receipt.html.twig', [
            'booking' => $booking,
            'total' => self::total($booking),
            'issuer' => $this->license->issuer(),
            'note' => $this->license->receiptNote(),
            'instance' => $this->instanceName(),
            'paid_on' => $this->formatter->date((string) $booking['paid_at']),
        ]);

        return $this->pdf->render($html, $this->translator->t('license.receipt.title') . ' ' . $booking['reference'], false);
    }

    public static function receiptFilename(array $booking): string
    {
        return 'Quittung-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $booking['reference']) . '.pdf';
    }

    /** Address of this instance without https://, e.g. musterdorf.lapfund.ch */
    public function instanceName(): string
    {
        $url = $this->mail->values()['app_url'];

        return (string) (parse_url($url, PHP_URL_HOST) ?: $url);
    }

    /**
     * Mail to the new club admin of a customer instance with the invitation link.
     *
     * @throws MailException
     */
    public function sendWelcome(string $email, string $name, string $username, string $inviteUrl): void
    {
        $brand = $this->clubBrand();
        $subject = $this->translator->t('mail.license.welcome_subject', ['club' => $brand['club_name']]);
        $mail = $this->composer->build('license_welcome', $email, $name, $subject, $brand, [
            'name' => explode(' ', trim($name))[0],
            'username' => $username,
            'invite_url' => $inviteUrl,
            'instance' => $this->instanceName(),
        ]);
        $this->transportSend($mail);
    }

    /** Receipt to the club admin who booked; also sent when the run was paid on the website. */
    private function sendReceipt(array $booking): void
    {
        $vars = [
            'booking' => $booking,
            'total' => self::total($booking),
            'event_url' => $booking['event_id'] !== null ? $this->mail->url('/admin/events/' . $booking['event_id']) : $this->mail->url('/admin/events'),
            'first_name' => explode(' ', trim((string) $booking['booked_by_name']))[0],
        ];
        $subject = $this->translator->t('mail.license.activated_subject', ['event' => $booking['event_name']]);
        $this->send('license_activated', (string) $booking['booked_by_email'], (string) $booking['booked_by_name'], $subject, $this->clubBrand(), $vars, [[
            'name' => self::receiptFilename($booking),
            'content' => $this->receiptPdf($booking),
            'type' => 'application/pdf',
        ]]);
    }

    /** Sends one mail at once through the operator's SMTP. Errors are logged, not thrown. */
    private function send(string $type, string $to, string $toName, string $subject, array $brand, array $vars, array $attachments = []): bool
    {
        try {
            $mail = $this->composer->build($type, $to, $toName, $subject, $brand, $vars);
            $this->transportSend($mail + ['attachments' => $attachments]);

            return true;
        } catch (\Throwable $e) {
            if ($this->log !== null) {
                ($this->log)($e);
            }

            return false;
        }
    }

    /** @throws MailException */
    private function transportSend(array $mail): void
    {
        $mail['from_name'] = $this->mail->forSystem()->values()['from_name'];
        $mail['inline_images'] = []; // licence mails carry no club logo
        $mail['attachments'] ??= [];
        ($this->systemTransport)()->send($mail);
    }

    /** @return array<string, string> */
    private function operatorBrand(): array
    {
        return [
            'club_name' => 'LapFund',
            'event_name' => $this->translator->t('mail.license.operator_header', ['instance' => $this->instanceName()]),
            'primary_color' => Branding::DEFAULT_COLOR,
            'on_primary' => Branding::textColorOn(Branding::DEFAULT_COLOR),
            'logo' => '',
            'contact_email' => '',
            'privacy_url' => '',
            'mail_footer' => $this->translator->t('mail.license.operator_footer', ['version' => App::VERSION]),
        ];
    }

    /** @return array<string, string> the club's colours, the operator's contact */
    private function clubBrand(): array
    {
        $color = Branding::validColor($this->settings->get('primary_color')) ?? Branding::DEFAULT_COLOR;

        return [
            'club_name' => $this->settings->get('club_name', 'LapFund'),
            'primary_color' => $color,
            'on_primary' => Branding::textColorOn($color),
            'logo' => '',
            'contact_email' => $this->license->supportEmail(),
            'privacy_url' => '',
        ];
    }
}
