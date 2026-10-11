<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\Request;
use LapFund\Http\Response;

/**
 * Billing of an event: open amounts per participant and team (AD-10), payment
 * requests with preview and test mail (ZA-02), reminders (ZA-06).
 */
final class PaymentsController extends EventScopedController
{
    public function index(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.view');
        $eventId = (int) $this->event['id'];
        [$type, $teamId] = $this->filter($request);
        $rows = $this->app->billing()->participants($eventId);
        $open = array_values(array_filter($rows, static fn (array $r) => $r['open_cents'] > 0 || $r['unknown_count'] > 0));
        usort($open, static fn (array $a, array $b) => $b['open_cents'] <=> $a['open_cents']);
        $teams = [];
        foreach ($rows as $r) {
            $key = $r['team_name'] ?? '';
            $teams[$key] ??= ['name' => $r['team_name'], 'owed' => 0, 'paid' => 0, 'open' => 0];
            $teams[$key]['owed'] += $r['owed_cents'];
            $teams[$key]['paid'] += $r['paid_cents'];
            $teams[$key]['open'] += $r['open_cents'];
        }

        return $this->renderEvent('admin/events/payments.html.twig', 'payments', [
            'totals' => $this->app->billing()->totals($eventId),
            'open_rows' => $open,
            'team_totals' => count($teams) > 1 ? array_values($teams) : [],
            'due' => $this->app->billing()->dueForRequest($eventId, $type, $teamId),
            'reminders' => $this->app->billing()->dueForReminder($eventId, max(1, (int) $this->event['reminder_days'])),
            'teams' => $this->app->teams()->forEvent($eventId),
            'filter_type' => $type,
            'filter_team' => $teamId,
            'preview' => $request->input('preview') === '1' ? $this->app->billingService()->preview($this->event, $type, $teamId) : null,
            'configured' => $this->app->mailSettings()->isConfigured(),
            'billing' => $this->event['status'] === 'billing',
        ]);
    }

    /** Sends the payment requests (ZA-02, EM-05). */
    public function send(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.payments');
        [$type, $teamId] = $this->filter($request);
        if (!$this->ready()) {
            return $this->redirect($this->eventPath('/payments'));
        }
        $result = $this->app->billingService()->requestPayments($this->event, (int) $this->user['id'], $type, $teamId);
        if ($result === null) {
            $this->flash('info', 'payments.nothing_due');
        } else {
            $this->audit('payment_requests_sent', 'event', (int) $this->event['id'], null, ['count' => $result['count'], 'type' => $type, 'team_id' => $teamId]);
            $this->flash('success', 'payments.requests_queued', ['count' => $result['count']]);
        }

        return $this->redirect($this->eventPath('/mails'));
    }

    public function test(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.payments');
        [$type, $teamId] = $this->filter($request);
        if ($this->ready() && $this->app->billingService()->testRequest($this->event, (string) $this->user['email'], $type, $teamId)) {
            $this->flash('success', 'payments.test_queued', ['email' => $this->user['email']]);
        } elseif ($this->event['status'] === 'billing') {
            $this->flash('info', 'payments.nothing_due');
        }

        return $this->redirect($this->eventPath('/payments?' . http_build_query(['type' => $type, 'team' => $teamId ?? ''])));
    }

    /** Reminders for open payments (ZA-06). */
    public function remind(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.payments');
        if (!$this->ready()) {
            return $this->redirect($this->eventPath('/payments'));
        }
        $result = $this->app->billingService()->remind($this->event, (int) $this->user['id']);
        if ($result === null) {
            $this->flash('info', 'payments.no_reminders');
        } else {
            $this->audit('payment_reminders_sent', 'event', (int) $this->event['id'], null, ['count' => $result['count']]);
            $this->flash('success', 'payments.reminders_queued', ['count' => $result['count']]);
        }

        return $this->redirect($this->eventPath('/mails'));
    }

    private function ready(): bool
    {
        if ($this->event['status'] !== 'billing') {
            $this->flash('error', 'payments.only_billing');

            return false;
        }
        if (!$this->app->mailSettings()->isConfigured()) {
            $this->flash('error', 'mails.not_configured');

            return false;
        }

        return true;
    }

    /** @return array{0: 'all'|'flat'|'per_lap', 1: int|null} */
    private function filter(Request $request): array
    {
        $type = $request->input('type');
        $type = in_array($type, ['flat', 'per_lap'], true) ? $type : 'all';
        $team = $request->input('team');

        return [$type, ctype_digit($team) ? (int) $team : null];
    }
}
