<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Security\ParticipantSession;
use LapFund\Service\QrCode;
use LapFund\Validation;

/**
 * Public pages and the participant area (architecture 8.1): start page with
 * «Link verloren», magic link, participant overview, invitation link.
 */
final class PublicController extends Controller
{
    /** Laps for the projection on the participant page (TS-02). */
    private const PROJECTION_LAPS = [10, 20, 30];
    private const MESSAGE_MAX = 500;
    /** States in which the participant may tick «cash received» (TS-07). */
    private const CASH_STATES = ['collecting', 'run_done', 'billing'];

    public function home(Request $request, array $params): Response
    {
        $this->guardPending();
        return $this->render('public/home.html.twig', [
            'email' => '',
            'error' => null,
            'has_access' => $this->app->participantSession()->current() !== null,
        ]);
    }

    /** «Link verloren» (ML-02, ML-04). Always the same answer, so addresses cannot be probed. */
    public function requestLinks(Request $request, array $params): Response
    {
        $this->guardPending();
        $email = mb_strtolower(trim($request->input('email')));
        if ($error = Validation::email($email)) {
            return $this->render('public/home.html.twig', ['email' => $email, 'error' => $error, 'has_access' => false], 422);
        }
        $limiter = $this->app->rateLimiter();
        $allowed = $request->input('website') === ''                       // honeypot
            && $limiter->hit('links_ip', $request->ip(), 10, 3600)
            && $limiter->hit('links_email', $email, 3, 3600);
        if ($allowed) {
            $this->app->access()->queueLostLinks($email);
        }

        return $this->redirect('/link/sent');
    }

    public function linksSent(Request $request, array $params): Response
    {
        return $this->render('public/link_sent.html.twig');
    }

    /** Magic link (ML-01): exchanged for a session, then on to /me without the token in the address bar. */
    public function magic(Request $request, array $params): Response
    {
        $this->guardPending();
        if (!$this->app->rateLimiter()->hit('magic_ip', $request->ip(), 60, 600)) {
            throw new HttpException(429);
        }
        $p = $this->app->access()->findByMagicToken((string) $params['token']);
        if ($p === null || $p['event_archived_at'] !== null) {
            return $this->render('public/link_invalid.html.twig', [], 404);
        }
        if ($p['event_status'] === 'preparation') {
            return $this->render('public/not_open.html.twig', ['event_name' => $p['event_name']]);
        }
        $this->app->participantSession()->grant($p);

        return $this->redirect('/me');
    }

    /** Participant overview (TS-01 to TS-04). */
    public function me(Request $request, array $params): Response
    {
        $this->guardPending();
        $session = $this->app->participantSession();
        $p = $session->current();
        if ($p === null) {
            $this->flash('info', 'public.me.no_access');

            return $this->redirect('/');
        }
        $event = $this->app->events()->find((int) $p['event_id']);
        if ($event === null) {
            throw new HttpException(404);
        }
        $collecting = $event['status'] === 'collecting';
        $totals = $this->app->pledges()->totals((int) $p['id']);
        $projections = [];
        foreach (self::PROJECTION_LAPS as $laps) {
            $projections[] = ['laps' => $laps, 'cents' => $totals['flat_cents'] + $laps * $totals['per_lap_cents']];
        }

        $share = null;
        if ($collecting) {
            $url = $this->absolute('/i/' . $this->app->access()->inviteToken((int) $p['id']));
            $text = $this->t('public.me.share_text', ['event' => $event['name'], 'url' => $url]);
            $share = [
                'url' => $url,
                'qr' => QrCode::dataUri($url, 7),
                'whatsapp' => 'https://wa.me/?text=' . rawurlencode($text),
                'mail' => 'mailto:?subject=' . rawurlencode($this->t('public.me.share_subject', ['event' => $event['name']]))
                    . '&body=' . rawurlencode($text),
            ];
        }

        $siblings = [];
        foreach ($session->all() as $id => $other) {
            $siblings[] = ['id' => $id, 'first_name' => $other['first_name'], 'event_name' => $other['event_name'], 'current' => $id === (int) $p['id']];
        }

        return $this->render('public/me.html.twig', [
            'p' => $p,
            'event' => $event,
            'brand' => $this->app->branding()->forEvent($event),
            'theme_event' => (int) $event['id'],
            'totals' => $totals,
            'projections' => $projections,
            'pledges' => $this->app->pledges()->forParticipation((int) $p['id']),
            'share' => $share,
            'can_edit_message' => $collecting,
            'message_max' => self::MESSAGE_MAX,
            'siblings' => count($siblings) > 1 ? $siblings : [],
            'can_add_pledge' => $collecting,
            'can_mark_cash' => in_array($event['status'], self::CASH_STATES, true),
            'message_error' => null,
        ]);
    }

    /** Personal message for sponsors (TS-04), only while sponsors are searched. */
    public function saveMessage(Request $request, array $params): Response
    {
        $this->guardPending();
        $p = $this->app->participantSession()->current();
        if ($p === null) {
            return $this->redirect('/');
        }
        if ($p['event_status'] !== 'collecting') {
            $this->flash('error', 'public.me.message_closed');

            return $this->redirect('/me');
        }
        $message = trim(str_replace("\r\n", "\n", $request->raw('message')));
        $message = preg_replace('/[^\P{C}\n]/u', '', $message) ?? '';
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            $this->flash('error', 'public.me.message_too_long', ['max' => self::MESSAGE_MAX]);

            return $this->redirect('/me#message');
        }
        $this->app->db()->update('participations', ['message' => $message !== '' ? $message : null], ['id' => (int) $p['id']]);
        $this->flash('success', 'public.me.message_saved');

        return $this->redirect('/me#message');
    }

    /** Pledge entered by the participant, e.g. from the paper list (TS-05). */
    public function newPledge(Request $request, array $params): Response
    {
        $this->guardPending();
        [$p, $event] = $this->participantAndEvent();
        if ($event['status'] !== 'collecting') {
            $this->flash('error', 'pledge.closed.status');

            return $this->redirect('/me');
        }

        return $this->pledgeForm($p, $event, ['type' => 'flat', 'amount' => '', 'method' => \LapFund\Service\PledgeService::methods($event)[0], 'name' => '', 'email' => '', 'message' => '', 'consent' => false], []);
    }

    public function storePledge(Request $request, array $params): Response
    {
        $this->guardPending();
        [$p, $event] = $this->participantAndEvent();
        if ($event['status'] !== 'collecting') {
            $this->flash('error', 'pledge.closed.status');

            return $this->redirect('/me');
        }
        if (!$this->app->rateLimiter()->hit('pledge_participant', (string) $p['id'], 60, 3600)) {
            throw new HttpException(429);
        }
        $input = [
            'type' => $request->input('type'),
            'amount' => $request->input('amount'),
            'method' => $request->input('method'),
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'message' => '',
        ];
        $checked = $this->app->pledgeService()->validate($input, $event, true, $request->input('consent') === '1');
        if ($checked['errors'] !== []) {
            return $this->pledgeForm($p, $event, $input + ['consent' => $request->input('consent') === '1'], $checked['errors'], 422);
        }
        $this->app->pledgeService()->create($event, $p, $checked['data'], 'participant');
        $this->flash('success', 'pledge.participant.saved', ['name' => $checked['data']['sponsor_name']]);

        return $this->redirect($request->input('again') === '1' ? '/me/pledges/new' : '/me#sponsors');
    }

    /** «Bar erhalten» for cash pledges only (TS-07). */
    public function cash(Request $request, array $params): Response
    {
        $this->guardPending();
        [$p, $event] = $this->participantAndEvent();
        if (!in_array($event['status'], self::CASH_STATES, true)
            || !$this->app->pledgeService()->setCashReceived((int) $p['id'], (int) $params['pledge'], $request->input('received') === '1')) {
            $this->flash('error', 'pledge.cash.not_possible');
        }

        return $this->redirect('/me#sponsors');
    }

    /** Sponsor list for printing (TS-08): QR code to the invitation link, no link as text (NF-08). */
    public function listPdf(Request $request, array $params): Response
    {
        $this->guardPending();
        [$p, $event] = $this->participantAndEvent();
        $brand = $this->app->branding()->forEvent($event);
        $logo = $brand['logo'] !== '' ? $this->app->images()->path($brand['logo']) : null;
        $html = $this->app->view()->renderMail('pdf/sponsor_list.html.twig', [
            'p' => $p,
            'event' => $event,
            'brand' => $brand,
            'logo_path' => $logo,
            'qr' => $event['status'] === 'collecting' ? QrCode::dataUri($this->absolute('/i/' . $this->app->access()->inviteToken((int) $p['id'])), 6) : null,
            'pledges' => array_reverse($this->app->pledges()->forParticipation((int) $p['id'])),
            'empty_rows' => max(8, 18 - count($this->app->pledges()->forParticipation((int) $p['id']))),
            'formatter' => $this->app->formatter,
        ]);
        $name = 'Sponsorenliste-' . preg_replace('/[^A-Za-z0-9]+/', '-', $p['first_name'] . '-' . $p['last_name']) . '.pdf';

        return new Response($this->app->pdf()->render($html, $this->t('pdf.title', ['name' => $p['first_name'] . ' ' . $p['last_name']])), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function participantAndEvent(): array
    {
        $p = $this->app->participantSession()->current();
        $event = $p !== null ? $this->app->events()->find((int) $p['event_id']) : null;
        if ($p === null || $event === null) {
            throw new \LapFund\Controller\RedirectException($this->app->request->url('/'));
        }

        return [$p, $event];
    }

    private function pledgeForm(array $p, array $event, array $input, array $errors, int $status = 200): Response
    {
        return $this->render('public/pledge_new.html.twig', [
            'p' => $p,
            'event' => $event,
            'brand' => $this->app->branding()->forEvent($event),
            'theme_event' => (int) $event['id'],
            'methods' => \LapFund\Service\PledgeService::methods($event),
            'quick' => SponsorController::QUICK,
            'input' => $input,
            'errors' => $errors,
        ], $status);
    }

    public function switchParticipation(Request $request, array $params): Response
    {
        $this->app->participantSession()->switchTo((int) $request->input('participation'));

        return $this->redirect('/me');
    }

    /** Forget the access on this device (shared phones). */
    public function logout(Request $request, array $params): Response
    {
        $this->app->participantSession()->forgetAll();
        $this->flash('success', 'public.me.logged_out');

        return $this->redirect('/');
    }

    /** Public pages wait until pending database updates have run. */
    protected function guardPending(): void
    {
        if ($this->app->migrator()->pending() !== []) {
            throw new HttpException(503);
        }
    }

    /** Absolute link: the configured address of the website, else the current host. */
    private function absolute(string $path): string
    {
        $base = $this->app->mailSettings()->values()['app_url'];

        return $base !== '' ? $base . '/' . ltrim($path, '/') : $this->app->request->absoluteUrl($path);
    }
}
