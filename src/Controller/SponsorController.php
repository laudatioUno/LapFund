<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Security\ParticipantSession;
use LapFund\Service\PledgeService;

/**
 * Sponsor pages (architecture 8.1): invitation page with the pledge form (/i/{token})
 * and payment page (/p/{token}). Sponsors have no login; both links are random tokens.
 */
final class SponsorController extends Controller
{
    /** A form sent faster than this is treated as a bot (SP-06). */
    private const MIN_FILL_SECONDS = 3;
    public const QUICK = ['flat' => [20, 50, 100], 'per_lap' => [1, 2, 5]];

    public function invitation(Request $request, array $params): Response
    {
        $loaded = $this->load((string) $params['token']);
        if ($loaded === null) {
            return $this->render('public/link_invalid.html.twig', ['for_sponsor' => true], 404);
        }
        [$p, $event] = $loaded;
        $this->app->session->set('pledge_form.' . $p['id'], time());

        return $this->page($p, $event, [
            'type' => 'flat',
            'amount' => '',
            'method' => PledgeService::methods($event)[0],
            'name' => '',
            'email' => '',
            'message' => '',
        ], []);
    }

    /** Saves a pledge (SP-02 to SP-07) and continues on the payment page. */
    public function pledge(Request $request, array $params): Response
    {
        $token = (string) $params['token'];
        $loaded = $this->load($token);
        if ($loaded === null) {
            throw new HttpException(404);
        }
        [$p, $event] = $loaded;
        if (PledgeService::closedReason($event) !== null) {
            return $this->redirect('/i/' . $token);
        }
        $limiter = $this->app->rateLimiter();
        if (!$limiter->hit('pledge_ip', $request->ip(), 20, 3600) || !$limiter->hit('pledge_link', (string) $p['id'], 40, 3600)) {
            throw new HttpException(429);
        }
        if ($request->input('website') !== '') {
            return $this->redirect('/i/' . $token); // honeypot: nothing is saved
        }

        $input = [
            'type' => $request->input('type'),
            'amount' => $request->input('amount'),
            'method' => $request->input('method'),
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'message' => $request->raw('message'),
        ];
        $checked = $this->app->pledgeService()->validate($input, $event, true, $request->input('consent') === '1');
        $errors = $checked['errors'];
        $started = (int) $this->app->session->get('pledge_form.' . $p['id'], 0);
        if ($errors === [] && ($started === 0 || time() - $started < self::MIN_FILL_SECONDS)) {
            $errors['_form'] = 'pledge.error.too_fast';
        }
        if ($errors !== []) {
            $this->app->session->set('pledge_form.' . $p['id'], $started ?: time());

            return $this->page($p, $event, $input + ['consent' => $request->input('consent') === '1'], $errors, 422);
        }

        $result = $this->app->pledgeService()->create($event, $p, $checked['data'], 'online');
        $pledge = $this->app->pledgeService()->find($result['id']);
        if ($pledge !== null) {
            $this->app->access()->queueNewSponsor($event, $pledge, $this->app->pledges()->totals((int) $p['id']));
        }
        $this->app->session->remove('pledge_form.' . $p['id']);
        $this->flash('success', 'pledge.saved', ['name' => $p['first_name']]);

        return $this->redirect('/p/' . $result['token']);
    }

    /** Payment page of one pledge (SP-08, SP-09). */
    public function payment(Request $request, array $params): Response
    {
        $this->guardPending();
        if (!$this->app->rateLimiter()->hit('payment_ip', $request->ip(), 120, 600)) {
            throw new HttpException(429);
        }
        $pledge = $this->app->pledgeService()->findByPaymentToken((string) $params['token']);
        if ($pledge === null) {
            return $this->render('public/link_invalid.html.twig', ['for_sponsor' => true], 404);
        }
        $event = $this->app->events()->find((int) $pledge['event_id']);
        if ($event === null) {
            throw new HttpException(404);
        }

        $due = $this->app->billing()->pledge((int) $event['id'], (int) $pledge['id']);

        return $this->render('public/payment.html.twig', [
            'pledge' => $pledge,
            'due' => $due,
            'laps' => $due['laps'] ?? null,
            'did_not_start' => (bool) ($due['did_not_start'] ?? false),
            'event' => $event,
            'brand' => $this->app->branding()->forEvent($event),
            'theme_event' => (int) $event['id'],
            'token' => (string) $params['token'],
            'twint' => $pledge['payment_method'] === 'twint',
            'twint_message' => PledgeService::twintMessage($pledge),
            'mail_configured' => $this->app->mailSettings()->isConfigured(),
        ]);
    }

    /** «Zahlungsangaben per Mail senden» (SP-09). */
    public function paymentMail(Request $request, array $params): Response
    {
        $this->guardPending();
        $token = (string) $params['token'];
        $pledge = $this->app->pledgeService()->findByPaymentToken($token);
        if ($pledge === null) {
            throw new HttpException(404);
        }
        $event = $this->app->events()->find((int) $pledge['event_id']);
        if ($event === null) {
            throw new HttpException(404);
        }
        $due = $this->app->billing()->pledge((int) $event['id'], (int) $pledge['id']);
        $allowed = $this->app->rateLimiter()->hit('payment_mail', (string) $pledge['id'], 3, 3600)
            && in_array($pledge['status'], ['open', 'cash_received'], true) && $this->app->mailSettings()->isConfigured();
        if ($allowed && $due !== null && $pledge['type'] === 'per_lap' && (int) $due['unknown'] === 0 && (int) $due['owed_cents'] > 0) {
            $this->app->billingService()->queueDueInfo($event, $due);
            $this->flash('success', 'pledge.payment.mail_sent', ['email' => $pledge['sponsor_email']]);
        } elseif ($allowed && $this->app->pledgeService()->queuePaymentInfo($pledge, $token, $event)) {
            $this->flash('success', 'pledge.payment.mail_sent', ['email' => $pledge['sponsor_email']]);
        } else {
            $this->flash('error', 'pledge.payment.mail_not_sent');
        }

        return $this->redirect('/p/' . $token);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>}|null */
    private function load(string $token): ?array
    {
        $this->guardPending();
        if (!$this->app->rateLimiter()->hit('invite_ip', $this->app->request->ip(), 120, 600)) {
            throw new HttpException(429);
        }
        $p = $this->app->access()->findByInviteToken($token);
        $event = $p !== null ? $this->app->events()->find((int) $p['event_id']) : null;
        if ($p === null || $event === null || $event['archived_at'] !== null) {
            return null;
        }

        return [$p, $event];
    }

    private function page(array $p, array $event, array $input, array $errors, int $status = 200): Response
    {
        $closed = PledgeService::closedReason($event);

        return $this->render('public/invite.html.twig', [
            'p' => $p,
            'event' => $event,
            'brand' => $this->app->branding()->forEvent($event),
            'theme_event' => (int) $event['id'],
            'closed' => $closed,
            'open' => $closed === null && ParticipantSession::usable($p),
            'methods' => PledgeService::methods($event),
            'quick' => self::QUICK,
            'input' => $input,
            'errors' => $errors,
            'token' => (string) $p['invite_token'],
        ], $status);
    }

    private function guardPending(): void
    {
        if ($this->app->migrator()->pending() !== []) {
            throw new HttpException(503);
        }
    }
}
