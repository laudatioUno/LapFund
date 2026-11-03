<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Domain\PaymentReference;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Security\Tokens;
use LapFund\Service\TwintPaymentImport;

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

        $searchRef = PaymentReference::normalize($request->input('ref'));
        $found = null;
        if ($searchRef !== '') {
            $row = $this->app->twintImport()->classify($eventId, [['line' => 0, 'date' => gmdate('Y-m-d'), 'total_cents' => 0, 'reference' => $searchRef, 'error' => null]])['rows'][0];
            $found = $row['pledge'] !== null ? $row['pledge'] : null;
        }

        return $this->renderEvent('admin/events/payments.html.twig', 'payments', [
            'search_ref' => $searchRef,
            'found' => $found,
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
            'can_import' => $this->perm['payments'] && in_array($this->event['status'], PledgeAdminController::CHANGE_STATES, true),
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

    // --- Import of Twint payments ------------------------------------------------------

    /** Reads the uploaded list and shows what would be marked as paid. Nothing is changed yet. */
    public function importPreview(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.payments');
        if (!$this->importAllowed()) {
            return $this->redirect($this->eventPath('/payments'));
        }
        $file = $request->file('csv');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $parsed = ['rows' => [], 'error' => 'payments.import.no_file'];
        } elseif ($file['size'] > TwintPaymentImport::MAX_BYTES) {
            $parsed = ['rows' => [], 'error' => 'payments.import.too_large'];
        } else {
            $parsed = TwintPaymentImport::parse((string) file_get_contents($file['tmp_name']));
        }
        if ($parsed['error'] !== null) {
            $this->flash('error', $parsed['error']);

            return $this->redirect($this->eventPath('/payments#import'));
        }
        $result = $this->app->twintImport()->classify((int) $this->event['id'], $parsed['rows']);
        $token = Tokens::random(16);
        $this->app->session->set('payment_import.' . $this->event['id'], ['token' => $token, 'rows' => $parsed['rows'], 'at' => time()]);

        return $this->renderEvent('admin/events/payments_import.html.twig', 'payments', $result + [
            'token' => $token,
            'selectable' => TwintPaymentImport::SELECTABLE,
        ]);
    }

    /** Marks the selected rows of the preview as paid (with date and amount from the list). */
    public function importApply(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.payments');
        if (!$this->importAllowed()) {
            return $this->redirect($this->eventPath('/payments'));
        }
        $key = 'payment_import.' . $this->event['id'];
        $pending = $this->app->session->get($key);
        if (!is_array($pending) || !hash_equals((string) $pending['token'], $request->input('token')) || time() - (int) $pending['at'] > 3600) {
            $this->flash('error', 'payments.import.expired');

            return $this->redirect($this->eventPath('/payments#import'));
        }
        $selected = array_map('intval', array_filter(is_array($request->post['lines'] ?? null) ? $request->post['lines'] : [], 'is_string'));
        if ($selected === []) {
            $this->flash('error', 'payments.import.nothing_selected');

            return $this->redirect($this->eventPath('/payments#import'));
        }
        $this->app->session->remove($key);

        // Classify again: payments may have been ticked in the meantime.
        $rows = $this->app->twintImport()->classify((int) $this->event['id'], $pending['rows'])['rows'];
        $event = $this->event;
        $adminId = (int) $this->user['id'];
        $billing = $this->app->billingService();
        $done = $this->app->db()->transaction(function () use ($rows, $selected, $event, $adminId, $billing): array {
            $count = 0;
            $cents = 0;
            foreach ($rows as $row) {
                if (!in_array((int) $row['line'], $selected, true) || !in_array($row['status'], TwintPaymentImport::SELECTABLE, true)) {
                    continue;
                }
                $pledge = $this->app->billing()->pledge((int) $event['id'], (int) $row['pledge']['id']);
                if ($pledge === null || !in_array($pledge['status'], ['open', 'cash_received'], true)) {
                    continue;
                }
                $billing->markPaid($pledge, (string) $row['date'], 'twint', (int) $row['total_cents'], $adminId, null, $event);
                $count++;
                $cents += (int) $row['total_cents'];
            }

            return ['count' => $count, 'cents' => $cents];
        });
        $this->audit('payments_imported', 'event', (int) $this->event['id'], null, ['imported' => $done['count'], 'amount_cents' => $done['cents']]);
        $this->flash('success', 'payments.import.done', ['count' => $done['count'], 'amount' => $this->app->formatter->money($done['cents'])]);

        return $this->redirect($this->eventPath('/payments'));
    }

    private function importAllowed(): bool
    {
        if (!in_array($this->event['status'], PledgeAdminController::CHANGE_STATES, true)) {
            $this->flash('error', 'pledges.admin.not_now');

            return false;
        }

        return true;
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
