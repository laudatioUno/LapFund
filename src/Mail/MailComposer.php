<?php

declare(strict_types=1);

namespace LapFund\Mail;

use LapFund\I18n\Translator;
use LapFund\View\View;

/**
 * Renders mails from the templates in templates/mail/<language>/ (HTML and text)
 * and prepares them for the queue. The structure is fixed in the templates; per event
 * the subject and intro of the invitation and the footer are editable (A6, PE-06).
 */
final class MailComposer
{
    public function __construct(
        private readonly View $view,
        private readonly Translator $translator,
        private readonly MailSettings $settings,
    ) {
    }

    /**
     * @param array<string, string> $brand Branding::club() or Branding::forEvent()
     * @param array<string, mixed> $vars
     * @return array{type: string, to_email: string, to_name: string, from_name: string, subject: string, body_html: string, body_text: string, inline_images: list<array{cid: string, file: string}>}
     */
    public function build(string $type, string $toEmail, string $toName, string $subject, array $brand, array $vars): array
    {
        $images = [];
        if (($brand['logo'] ?? '') !== '') {
            $images[] = ['cid' => 'logo', 'file' => $brand['logo']];
        }
        $data = $vars + [
            'brand' => $brand,
            'subject' => $subject,
            'has_logo' => $images !== [],
            'home_url' => $this->settings->url('/'),
        ];
        $dir = 'mail/' . $this->translator->locale . '/';
        if (!$this->view->exists($dir . $type . '.html.twig')) {
            $dir = 'mail/de/';
        }

        return [
            'type' => $type,
            'to_email' => $toEmail,
            'to_name' => $toName,
            'from_name' => $this->fromName($brand),
            'subject' => $subject,
            'body_html' => $this->view->renderMail($dir . $type . '.html.twig', $data),
            'body_text' => self::normalizeText($this->view->renderMail($dir . $type . '.txt.twig', $data)),
            'inline_images' => $images,
        ];
    }

    /** Event sender name, else the sender name of the mail settings, else the club name (EM-09, EM-13). */
    public function fromName(array $brand): string
    {
        if (($brand['event_name'] ?? '') !== '' && ($brand['sender_name'] ?? '') !== '' && $brand['sender_name'] !== $brand['club_name']) {
            return $brand['sender_name'];
        }
        $name = $this->settings->values()['from_name'];

        return $name !== '' ? $name : (string) ($brand['club_name'] ?? '');
    }

    /** Replaces {vorname}, {name}, {event}, {verein} in editable texts. */
    public static function placeholders(string $text, array $values): string
    {
        return strtr($text, [
            '{vorname}' => (string) ($values['first_name'] ?? ''),
            '{name}' => (string) ($values['last_name'] ?? ''),
            '{event}' => (string) ($values['event'] ?? ''),
            '{verein}' => (string) ($values['club'] ?? ''),
        ]);
    }

    private static function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text) . "\n";
    }
}
