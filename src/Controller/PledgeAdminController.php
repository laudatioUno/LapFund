<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Service\PledgeService;

/**
 * Pledges of one participant in the admin console (AD-02, AD-09, ZA-05):
 * add, correct, cancel, mark as paid (single or all), undo.
 * Only assigned event admins; club admins see them read-only (RU-01, A7).
 */
final class PledgeAdminController extends EventScopedController
{
    /** States in which pledges and payments can be changed; «closed» is read-only. */
    public const CHANGE_STATES = ['collecting', 'run_done', 'billing'];

    public function create(Request $request, array $params): Response
    {
        $p = $this->load($params);

        return $this->form($p, null, ['type' => 'flat', 'amount' => '', 'method' => PledgeService::methods($this->event)[0], 'name' => '', 'email' => '', 'message' => '', 'confirm' => true], []);
    }

    public function store(Request $request, array $params): Response
    {
        $p = $this->load($params);
        $input = $this->input($request) + ['message' => '', 'confirm' => $request->input('confirm') === '1'];
        $checked = $this->app->pledgeService()->validate($input, $this->event, false, false);
        if ($checked['errors'] !== []) {
            return $this->form($p, null, $input, $checked['errors'], 422);
        }
        $access = $this->app->access()->participation((int) $p['id']);
        $data = $checked['data'];
        $result = $this->app->pledgeService()->create($this->event, $access, $data, 'admin', $input['confirm']);
        $this->audit('pledge_created', 'pledge', $result['id'], null, ['sponsor' => $data['sponsor_name'], 'amount_cents' => $data['amount_cents'], 'type' => $data['type']]);
        $this->flash('success', 'pledges.admin.created', ['name' => $data['sponsor_name']]);

        return $this->redirect($this->participantPath($p));
    }

    public function edit(Request $request, array $params): Response
    {
        $p = $this->load($params);
        $pl = $this->pledge($params);
        $amount = number_format((int) $pl['amount_cents'] / 100, 2, '.', '');

        return $this->form($p, $pl, [
            'type' => $pl['type'], 'amount' => $amount, 'method' => $pl['payment_method'],
            'name' => (string) $pl['sponsor_name'], 'email' => (string) $pl['sponsor_email'], 'message' => (string) $pl['sponsor_message'],
        ], []);
    }

    public function update(Request $request, array $params): Response
    {
        $p = $this->load($params);
        $pl = $this->pledge($params);
        if (!in_array($pl['status'], ['open', 'cash_received'], true)) {
            $this->flash('error', 'pledges.admin.not_editable');

            return $this->redirect($this->participantPath($p));
        }
        $input = $this->input($request) + ['message' => $request->raw('message')];
        $event = $this->event;
        if ($pl['payment_method'] === 'twint') {
            $event['twint_qr_file'] = $event['twint_qr_file'] ?: 'kept'; // keep Twint selectable for existing Twint pledges
        }
        $checked = $this->app->pledgeService()->validate($input, $event, false, false);
        if ($checked['errors'] !== []) {
            return $this->form($p, $pl, $input, $checked['errors'], 422);
        }
        $differs = $this->app->billingService()->update($this->event, $pl, $checked['data'], (int) $this->user['id'], $request->input('send_correction') === '1');
        $this->flash('success', 'pledges.admin.updated');
        if ($differs) {
            $this->flash($request->input('send_correction') === '1' ? 'info' : 'warning',
                $request->input('send_correction') === '1' ? 'pledges.admin.correction_sent' : 'pledges.admin.correction_hint');
        }

        return $this->redirect($this->participantPath($p));
    }

    public function cancel(Request $request, array $params): Response
    {
        $p = $this->load($params);
        $pl = $this->pledge($params);
        $reason = trim($request->input('reason'));
        if ($reason === '' || $pl['status'] === 'cancelled') {
            $this->flash('error', 'pledges.admin.cancel_reason');

            return $this->redirect($this->participantPath($p) . '/pledges/' . $pl['id']);
        }
        $this->app->billingService()->cancel($pl, $reason, (int) $this->user['id']);
        $this->flash('success', 'pledges.admin.cancelled', ['name' => $pl['sponsor_name']]);

        return $this->redirect($this->participantPath($p));
    }

    /** Marks one pledge as paid (ZA-05). */
    public function paid(Request $request, array $params): Response
    {
        $p = $this->load($params);
        $pl = $this->pledge($params);
        $date = $this->date($request->input('date'));
        $method = in_array($request->input('method'), ['twint', 'cash'], true) ? $request->input('method') : $pl['payment_method'];
        $amount = $request->input('amount') === '' ? (int) $pl['owed_cents'] : PledgeService::parseAmount($request->input('amount'));
        if (!in_array($pl['status'], ['open', 'cash_received'], true) || (int) $pl['unknown'] === 1 || $date === null || $amount === null) {
            $this->flash('error', (int) $pl['unknown'] === 1 ? 'pledges.admin.laps_missing' : 'pledges.admin.paid_invalid');

            return $this->redirect($this->participantPath($p));
        }
        $this->app->billingService()->markPaid($pl, $date, $method, $amount, (int) $this->user['id'], null, $this->event);
        $this->flash('success', 'pledges.admin.paid', ['name' => $pl['sponsor_name'], 'amount' => $this->app->formatter->money($amount)]);

        return $this->redirect($this->participantPath($p));
    }

    public function unpaid(Request $request, array $params): Response
    {
        $p = $this->load($params);
        $pl = $this->pledge($params);
        if ($pl['status'] === 'paid') {
            $this->app->billingService()->markUnpaid($pl, (int) $this->user['id']);
            $this->flash('success', 'pledges.admin.unpaid', ['name' => $pl['sponsor_name']]);
        }

        return $this->redirect($this->participantPath($p));
    }

    /** «Vollständig bezahlt» for a participant, e.g. when the cash is handed in (AD-09). */
    public function allPaid(Request $request, array $params): Response
    {
        $p = $this->load($params);
        $date = $this->date($request->input('date'));
        $method = in_array($request->input('method'), ['twint', 'cash'], true) ? $request->input('method') : 'cash';
        if ($date === null) {
            $this->flash('error', 'pledges.admin.paid_invalid');

            return $this->redirect($this->participantPath($p));
        }
        $r = $this->app->billingService()->markAllPaid($this->event, (int) $p['id'], $date, $method, (int) $this->user['id']);
        $this->flash($r['count'] > 0 ? 'success' : 'info', $r['count'] > 0 ? 'pledges.admin.all_paid' : 'pledges.admin.nothing_open', [
            'count' => $r['count'], 'amount' => $this->app->formatter->money($r['cents']),
        ]);

        return $this->redirect($this->participantPath($p));
    }

    public function undoAll(Request $request, array $params): Response
    {
        $p = $this->load($params);
        $group = $this->app->billingService()->lastGroup((int) $p['id']);
        $n = $group !== null ? $this->app->billingService()->undoGroup((int) $p['id'], $group, (int) $this->user['id']) : 0;
        $this->flash('success', 'pledges.admin.undone', ['count' => $n]);

        return $this->redirect($this->participantPath($p));
    }

    /** @return array<string, mixed> participation of this event */
    private function load(array $params): array
    {
        $this->loadEvent($params, 'event.payments');
        $p = $this->app->participants()->participation((int) $this->event['id'], (int) $params['pid']);
        if ($p === null) {
            throw new HttpException(404);
        }
        if (!in_array($this->event['status'], self::CHANGE_STATES, true)) {
            $this->flash('error', 'pledges.admin.not_now');
            throw new RedirectException($this->app->request->url($this->participantPath($p)));
        }

        return $p;
    }

    private function pledge(array $params): array
    {
        $pl = $this->app->billing()->pledge((int) $this->event['id'], (int) $params['plid']);
        if ($pl === null || (int) $pl['participation_id'] !== (int) $params['pid']) {
            throw new HttpException(404);
        }

        return $pl;
    }

    private function participantPath(array $p): string
    {
        return $this->eventPath('/participants/' . $p['id']);
    }

    /** @return array<string, string> */
    private function input(Request $request): array
    {
        return [
            'type' => $request->input('type'),
            'amount' => $request->input('amount'),
            'method' => $request->input('method'),
            'name' => $request->input('name'),
            'email' => $request->input('email'),
        ];
    }

    private function date(string $value): ?string
    {
        if ($value === '') {
            return gmdate('Y-m-d');
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return ($d !== false && $d->format('Y-m-d') === $value && $value <= gmdate('Y-m-d', time() + 86400)) ? $value : null;
    }

    private function form(array $p, ?array $pl, array $input, array $errors, int $status = 200): Response
    {
        $event = $this->event;
        $methods = PledgeService::methods($event);
        if ($pl !== null && $pl['payment_method'] === 'twint' && !in_array('twint', $methods, true)) {
            $methods = ['twint', 'cash'];
        }

        return $this->renderEvent('admin/events/participants/pledge_form.html.twig', 'participants', [
            'p' => $p,
            'pl' => $pl,
            'input' => $input,
            'errors' => $errors,
            'methods' => $methods,
            'history' => $pl === null ? [] : array_reverse($this->app->audit()->history([['pledge', (int) $pl['id']]])),
        ], $status);
    }
}
