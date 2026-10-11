<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Domain\EventStatus;
use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Security\Tokens;
use LapFund\Service\CsvParticipantParser;
use LapFund\Validation;

/**
 * Participants of an event (TN-01 to TN-06): list, add (new or from the pool),
 * edit, remove, CSV import with preview, and taking over from an earlier event.
 */
final class ParticipantController extends EventScopedController
{
    public function index(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.view');
        $teamFilter = $request->input('team');
        $teamId = ctype_digit($teamFilter) ? (int) $teamFilter : null;
        $query = mb_substr($request->input('q'), 0, 100);
        $rows = $this->app->participants()->forEvent((int) $this->event['id'], $teamId, $query, $teamFilter === 'none');

        return $this->renderEvent('admin/events/participants/index.html.twig', 'participants', [
            'rows' => $rows,
            'teams' => $this->app->teams()->forEvent((int) $this->event['id']),
            'team_filter' => $teamFilter,
            'query' => $query,
            'total' => $this->app->participants()->countForEvent((int) $this->event['id']),
            'can_change' => $this->canChangeList(),
            'invites' => $this->app->mailQueue()->lastInvitePerParticipation((int) $this->event['id']),
            'uninvited' => $this->app->access()->uninvitedCount((int) $this->event['id']),
            'mail_configured' => $this->app->mailSettings()->isConfigured(),
        ]);
    }

    public function add(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.participants');
        $this->guardListOpen();

        return $this->addPage(['first_name' => '', 'last_name' => '', 'email' => '', 'team_id' => ''], [], $request->input('q'));
    }

    public function storeNew(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.participants');
        $this->guardListOpen();
        $input = [
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'email' => strtolower($request->input('email')),
            'team_id' => $request->input('team_id'),
        ];
        $errors = $this->personErrors($input);
        $teamId = $this->teamIdOrNull($input['team_id']);
        if ($errors !== []) {
            return $this->addPage($input, $errors, '', 422);
        }

        $person = $this->app->participants()->findOrCreate($input['first_name'], $input['last_name'], $input['email']);
        $participationId = $this->app->participants()->addToEvent((int) $this->event['id'], $person['id'], $teamId);
        if ($participationId === null) {
            $this->flash('info', 'participants.already_in_event', ['name' => $input['first_name'] . ' ' . $input['last_name']]);

            return $this->redirect($this->eventPath('/participants/add'));
        }
        $this->audit('participant_added', 'participant', $person['id'], null, [
            'name' => $input['first_name'] . ' ' . $input['last_name'], 'from_pool' => !$person['created'],
        ]);
        $this->flash('success', $person['created'] ? 'participants.added_new' : 'participants.added_existing', [
            'name' => $input['first_name'] . ' ' . $input['last_name'],
        ]);
        $this->autoInvite([$participationId]);

        return $this->redirect($this->eventPath('/participants/add'));
    }

    public function storeFromPool(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.participants');
        $this->guardListOpen();
        $person = $this->app->participants()->find((int) $request->input('participant_id'));
        if ($person === null) {
            throw new HttpException(404);
        }
        $teamId = $this->teamIdOrNull($request->input('team_id'));
        $participationId = $this->app->participants()->addToEvent((int) $this->event['id'], (int) $person['id'], $teamId);
        if ($participationId !== null) {
            $this->audit('participant_added', 'participant', (int) $person['id'], null, [
                'name' => $person['first_name'] . ' ' . $person['last_name'], 'from_pool' => true,
            ]);
            $this->flash('success', 'participants.added_existing', ['name' => $person['first_name'] . ' ' . $person['last_name']]);
            $this->autoInvite([$participationId]);
        }

        return $this->redirect($this->eventPath('/participants/add?q=' . rawurlencode($request->input('q'))));
    }

    public function edit(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.view');
        $p = $this->participation($params);

        return $this->editPage($p, ['first_name' => $p['first_name'], 'last_name' => $p['last_name'], 'email' => $p['email'], 'team_id' => (string) ($p['team_id'] ?? '')], []);
    }

    public function update(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.participants');
        $p = $this->participation($params);

        if ($request->input('action') === 'remove') {
            if ((int) $p['pledge_count'] > 0 || !$this->canChangeList()) {
                $this->flash('error', (int) $p['pledge_count'] > 0 ? 'participants.remove_has_pledges' : 'participants.list_closed');

                return $this->redirect($this->eventPath('/participants/' . $p['id']));
            }
            $this->app->participants()->removeFromEvent((int) $p['id']);
            $this->audit('participant_removed', 'participant', (int) $p['participant_id'], ['name' => $p['first_name'] . ' ' . $p['last_name']]);
            $this->flash('success', 'participants.removed', ['name' => $p['first_name'] . ' ' . $p['last_name']]);

            return $this->redirect($this->eventPath('/participants'));
        }

        $input = [
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'email' => strtolower($request->input('email')),
            'team_id' => $request->input('team_id'),
        ];
        $errors = $this->personErrors($input);
        $teamId = $this->teamIdOrNull($input['team_id']);
        $other = $this->app->participants()->findMatch($input['first_name'], $input['last_name'], $input['email']);
        if ($other !== null && (int) $other['id'] !== (int) $p['participant_id']) {
            $errors['first_name'] = 'participants.duplicate_person';
        }
        if ($errors !== []) {
            return $this->editPage($p, $input, $errors, 422);
        }

        $this->app->participants()->update((int) $p['participant_id'], $input['first_name'], $input['last_name'], $input['email']);
        $this->app->participants()->setTeam((int) $p['id'], $teamId);
        $this->audit('participant_updated', 'participant', (int) $p['participant_id'],
            ['name' => $p['first_name'] . ' ' . $p['last_name'], 'email' => $p['email'], 'team_id' => $p['team_id']],
            ['name' => $input['first_name'] . ' ' . $input['last_name'], 'email' => $input['email'], 'team_id' => $teamId],
        );
        $this->flash('success', 'participants.saved');

        return $this->redirect($this->eventPath('/participants'));
    }

    // --- CSV import -----------------------------------------------------------

    public function importForm(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.participants');
        $this->guardListOpen();

        return $this->renderEvent('admin/events/participants/import.html.twig', 'participants', ['error' => null]);
    }

    public function importPreview(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.participants');
        $this->guardListOpen();
        $file = $request->file('csv');
        $error = null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $error = 'participants.import.no_file';
        } elseif ($file['size'] > CsvParticipantParser::MAX_BYTES) {
            $error = 'participants.import.too_large';
        }
        $parsed = $error === null ? (new CsvParticipantParser())->parse((string) file_get_contents($file['tmp_name'])) : ['rows' => [], 'error' => $error];
        if ($parsed['error'] !== null) {
            return $this->renderEvent('admin/events/participants/import.html.twig', 'participants', ['error' => $parsed['error']], 422);
        }

        $result = $this->app->participantService()->classifyImport((int) $this->event['id'], $parsed['rows']);
        $token = Tokens::random(16);
        $this->app->session->set('import.' . $this->event['id'], ['token' => $token, 'rows' => $result['rows'], 'at' => time()]);

        return $this->renderEvent('admin/events/participants/import_preview.html.twig', 'participants', $result + ['token' => $token]);
    }

    public function importApply(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.participants');
        $this->guardListOpen();
        $key = 'import.' . $this->event['id'];
        $pending = $this->app->session->get($key);
        $token = $request->input('token');
        if (!is_array($pending) || !hash_equals((string) $pending['token'], $token) || time() - (int) $pending['at'] > 3600) {
            $this->flash('error', 'participants.import.expired');

            return $this->redirect($this->eventPath('/participants/import'));
        }
        $this->app->session->remove($key);

        // Classify again: the pool may have changed since the preview.
        $rows = $this->app->participantService()->classifyImport((int) $this->event['id'], $pending['rows'])['rows'];
        $added = $this->app->participantService()->applyImport((int) $this->event['id'], $rows);
        $this->audit('participants_imported', 'event', (int) $this->event['id'], null, ['added' => count($added)]);
        $this->flash('success', 'participants.import.done', ['count' => count($added)]);
        $this->autoInvite($added);

        return $this->redirect($this->eventPath('/participants'));
    }

    // --- Take over from another event ------------------------------------------

    public function copyForm(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.participants');
        $this->guardListOpen();
        $others = $this->app->events()->others((int) $this->event['id']);
        $sourceId = (int) $request->input('from');
        $source = null;
        $rows = [];
        foreach ($others as $o) {
            if ((int) $o['id'] === $sourceId) {
                $source = $o;
                $rows = $this->app->participants()->fromEvent($sourceId, (int) $this->event['id']);
            }
        }

        return $this->renderEvent('admin/events/participants/copy.html.twig', 'participants', [
            'others' => $others,
            'source' => $source,
            'rows' => $rows,
        ]);
    }

    public function copyApply(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.participants');
        $this->guardListOpen();
        $sourceId = (int) $request->input('from');
        if ($sourceId === (int) $this->event['id'] || $this->app->events()->find($sourceId) === null) {
            throw new HttpException(404);
        }
        $raw = $request->post['participants'] ?? [];
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $v) {
            if (is_string($v) && ctype_digit($v)) {
                $ids[] = (int) $v;
            }
        }
        if ($ids === []) {
            $this->flash('error', 'participants.copy.none_selected');

            return $this->redirect($this->eventPath('/participants/copy?from=' . $sourceId));
        }
        $added = $this->app->participantService()->copyFromEvent($sourceId, (int) $this->event['id'], $ids, $request->input('keep_teams') === '1');
        $this->audit('participants_copied', 'event', (int) $this->event['id'], null, ['from_event' => $sourceId, 'added' => count($added)]);
        $this->flash('success', 'participants.copy.done', ['count' => count($added)]);
        $this->autoInvite($added);

        return $this->redirect($this->eventPath('/participants'));
    }

    // --- Access: invitation and magic link ----------------------------------------

    /** Sends the invitation again, with the same magic link (TN-03). */
    public function resendInvite(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.mail');
        $p = $this->participation($params);
        if ($this->event['status'] !== 'collecting') {
            $this->flash('error', 'mails.invite.only_collecting');
        } elseif (!$this->app->mailSettings()->isConfigured()) {
            $this->flash('error', 'mails.not_configured');
        } elseif ($this->app->access()->queueInvite($this->event, (int) $p['id'], null, (int) $this->user['id'])) {
            $this->audit('invitation_sent', 'participant', (int) $p['participant_id'], null, ['name' => $p['first_name'] . ' ' . $p['last_name']]);
            $this->flash('success', 'participants.access.invite_queued', ['email' => $p['email']]);
        }

        return $this->redirect($this->eventPath('/participants/' . $p['id']));
    }

    /** New magic link; the old one stops working at once, the new one goes out by mail (ML-03). */
    public function newLink(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.mail');
        $p = $this->participation($params);
        if (!in_array($this->event['status'], ['collecting', 'run_done', 'billing'], true)) {
            $this->flash('error', 'participants.access.link_not_now');
        } elseif (!$this->app->mailSettings()->isConfigured()) {
            $this->flash('error', 'mails.not_configured');
        } elseif ($this->app->access()->regenerateAndSend($this->event, (int) $p['id'], (int) $this->user['id'])) {
            $this->audit('magic_link_renewed', 'participant', (int) $p['participant_id'], null, ['name' => $p['first_name'] . ' ' . $p['last_name']]);
            $this->flash('success', 'participants.access.link_renewed', ['email' => $p['email']]);
        }

        return $this->redirect($this->eventPath('/participants/' . $p['id']));
    }

    /**
     * Participants added while sponsors are searched get their invitation right away (TN-03).
     *
     * @param list<int> $participationIds
     */
    private function autoInvite(array $participationIds): void
    {
        if ($participationIds === [] || $this->event['status'] !== 'collecting') {
            return;
        }
        if (!$this->app->mailSettings()->isConfigured()) {
            $this->flash('warning', 'participants.access.not_invited_no_mail');

            return;
        }
        $result = $this->app->access()->inviteMany($this->event, $participationIds, (int) $this->user['id']);
        if ($result !== null && $result['count'] > 0) {
            $this->audit('invitations_sent', 'event', (int) $this->event['id'], null, ['count' => $result['count'], 'batch_id' => $result['batch_id']]);
            $this->flash('info', 'participants.access.invited_auto', ['count' => $result['count']]);
        }
    }

    // --- Helpers ----------------------------------------------------------------

    private function addPage(array $input, array $errors, string $query, int $status = 200): Response
    {
        $query = mb_substr(trim($query), 0, 100);

        return $this->renderEvent('admin/events/participants/add.html.twig', 'participants', [
            'input' => $input,
            'errors' => $errors,
            'teams' => $this->app->teams()->forEvent((int) $this->event['id']),
            'query' => $query,
            'pool' => $query !== '' ? $this->app->participants()->searchPool($query, (int) $this->event['id'], 30) : [],
        ], $status);
    }

    private function editPage(array $p, array $input, array $errors, int $status = 200): Response
    {
        return $this->renderEvent('admin/events/participants/edit.html.twig', 'participants', [
            'p' => $p,
            'input' => $input,
            'errors' => $errors,
            'teams' => $this->app->teams()->forEvent((int) $this->event['id']),
            'can_remove' => (int) $p['pledge_count'] === 0 && $this->canChangeList(),
            'invite' => $this->app->mailQueue()->lastInvitePerParticipation((int) $this->event['id'])[(int) $p['id']] ?? null,
            'mail_configured' => $this->app->mailSettings()->isConfigured(),
            'pledges' => $this->app->pledges()->forParticipation((int) $p['id']),
        ], $status);
    }

    /** @return array<string, string> */
    private function personErrors(array $input): array
    {
        $errors = [];
        foreach (['first_name', 'last_name'] as $f) {
            if ($e = Validation::required($input[$f], 80)) {
                $errors[$f] = $e;
            }
        }
        if ($e = Validation::email($input['email'])) {
            $errors['email'] = $e;
        }

        return $errors;
    }

    private function teamIdOrNull(string $value): ?int
    {
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $team = $this->app->teams()->find((int) $this->event['id'], (int) $value);

        return $team === null ? null : (int) $team['id'];
    }

    /** @return array<string, mixed> */
    private function participation(array $params): array
    {
        $p = $this->app->participants()->participation((int) $this->event['id'], (int) $params['pid']);
        if ($p === null) {
            throw new HttpException(404);
        }

        return $p;
    }

    private function canChangeList(): bool
    {
        return $this->perm['participants'] && EventStatus::allowsParticipantChanges((string) $this->event['status']);
    }

    private function guardListOpen(): void
    {
        if (!EventStatus::allowsParticipantChanges((string) $this->event['status'])) {
            $this->flash('error', 'participants.list_closed');
            throw new RedirectException($this->app->request->url($this->eventPath('/participants')));
        }
    }
}
