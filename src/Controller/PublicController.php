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

    /**
     * Invitation link for sponsors (/i/{token}). Entering a pledge follows in stage 4;
     * until then the page shows who is asking and that the form follows.
     */
    public function invitation(Request $request, array $params): Response
    {
        $this->guardPending();
        if (!$this->app->rateLimiter()->hit('invite_ip', $request->ip(), 120, 600)) {
            throw new HttpException(429);
        }
        $p = $this->app->access()->findByInviteToken((string) $params['token']);
        if ($p === null) {
            return $this->render('public/link_invalid.html.twig', ['for_sponsor' => true], 404);
        }
        $event = $this->app->events()->find((int) $p['event_id']);
        if ($event === null) {
            throw new HttpException(404);
        }

        return $this->render('public/invite.html.twig', [
            'p' => $p,
            'event' => $event,
            'brand' => $this->app->branding()->forEvent($event),
            'theme_event' => (int) $event['id'],
            'open' => ParticipantSession::usable($p) && $event['status'] === 'collecting',
        ]);
    }

    /** Public pages wait until pending database updates have run. */
    private function guardPending(): void
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
