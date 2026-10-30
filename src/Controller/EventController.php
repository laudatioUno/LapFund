<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Domain\EventStatus;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Service\Branding;
use LapFund\Validation;

/**
 * Events (EV-01 to EV-06), event admins (AR-02 to AR-04) and the event look (PE-02 to PE-06).
 */
final class EventController extends EventScopedController
{
    public function index(Request $request, array $params): Response
    {
        $user = $this->admin('events.list');
        $showArchived = $user['role'] === 'club_admin' && $request->input('archived') === '1';

        return $this->render('admin/events/index.html.twig', [
            'events' => $this->app->events()->listFor($user, $showArchived),
            'show_archived' => $showArchived,
            'can_create' => $user['role'] === 'club_admin',
        ]);
    }

    public function create(Request $request, array $params): Response
    {
        $this->admin('events.create');

        return $this->createForm(['name' => '', 'event_date' => '', 'location' => '', 'description' => '', 'admins' => []], []);
    }

    public function store(Request $request, array $params): Response
    {
        $user = $this->admin('events.create');
        $input = [
            'name' => $request->input('name'),
            'event_date' => $request->input('event_date'),
            'location' => $request->input('location'),
            'description' => $request->input('description'),
            'admins' => $this->selectedAdmins($request),
        ];
        $errors = [];
        if ($e = Validation::required($input['name'], 120)) {
            $errors['name'] = $e;
        }
        if ($e = Validation::optionalDate($input['event_date'])) {
            $errors['event_date'] = $e;
        }
        if (mb_strlen($input['location']) > 160) {
            $errors['location'] = 'validation.too_long';
        }
        if ($errors !== []) {
            return $this->createForm($input, $errors, 422);
        }

        $id = $this->app->events()->create([
            'name' => $input['name'],
            'event_date' => $input['event_date'] !== '' ? $input['event_date'] : null,
            'location' => $input['location'],
            'description' => $input['description'] !== '' ? $input['description'] : null,
            'texts' => ['welcome' => '', 'purpose' => '', 'mail_footer' => '', 'invite_subject' => '', 'invite_intro' => ''],
            'created_by' => (int) $user['id'],
        ]);
        $this->app->events()->setAdmins($id, $input['admins']);
        $this->app->audit()->log((int) $user['id'], 'event_created', 'event', $id, null, [
            'name' => $input['name'], 'event_id' => $id, 'admins' => $input['admins'],
        ]);
        $this->flash('success', 'events.created', ['name' => $input['name']]);

        return $this->redirect('/admin/events/' . $id);
    }

    public function show(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.view');
        $id = (int) $this->event['id'];
        $teams = $this->app->teams()->forEvent($id);
        $count = $this->app->participants()->countForEvent($id);
        $status = (string) $this->event['status'];

        $checklist = [
            ['done' => $this->event['event_date'] !== null && $this->event['location'] !== '', 'label' => 'events.check_date', 'link' => $this->eventPath('/settings')],
            ['done' => $teams !== [], 'label' => 'events.check_teams', 'link' => $this->eventPath('/teams')],
            ['done' => $count > 0, 'label' => 'events.check_participants', 'link' => $this->eventPath('/participants')],
            ['done' => (string) ($this->event['twint_qr_file'] ?? '') !== '', 'label' => 'events.check_twint', 'link' => $this->eventPath('/settings#twint')],
            ['done' => $this->event['texts']['welcome'] !== '', 'label' => 'events.check_texts', 'link' => $this->eventPath('/settings#texts')],
        ];

        return $this->renderEvent('admin/events/show.html.twig', 'overview', [
            'statuses' => EventStatus::ORDER,
            'status_index' => EventStatus::index($status),
            'next_status' => EventStatus::next($status),
            'previous_status' => $this->event['anonymized_at'] !== null ? null : EventStatus::previous($status),
            'can_archive' => EventStatus::allowsArchive($status),
            'teams' => $teams,
            'participant_count' => $count,
            'admins' => $this->app->events()->admins($id),
            'checklist' => $checklist,
            'uninvited' => $this->app->access()->uninvitedCount($id),
            'anonymize_at' => $this->app->retention()->anonymizeAt($this->event),
            'retention_months' => $this->app->retention()->months(),
        ]);
    }

    public function status(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.status');
        $from = (string) $this->event['status'];
        $to = $request->input('status');
        if (!EventStatus::isValid($to) || !EventStatus::canMove($from, $to)) {
            $this->flash('error', 'events.status_invalid');

            return $this->redirect($this->eventPath());
        }
        if ($from === 'closed' && $this->event['anonymized_at'] !== null) {
            $this->flash('error', 'events.reopen_anonymized');

            return $this->redirect($this->eventPath());
        }
        $this->app->events()->update((int) $this->event['id'], [
            'status' => $to,
            'closed_at' => $to === 'closed' ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $this->audit('event_status_changed', 'event', (int) $this->event['id'], ['status' => $from], ['status' => $to]);
        $this->flash('success', 'events.status_changed', ['status' => $this->t('events.status.' . $to)]);
        if ($to === 'collecting' && $this->app->access()->uninvitedCount((int) $this->event['id']) > 0) {
            $this->flash('info', 'mails.invite.after_status');
        }

        return $this->redirect($this->eventPath());
    }

    public function archive(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.archive');
        $archive = $request->input('archive') === '1';
        if ($archive && !EventStatus::allowsArchive((string) $this->event['status'])) {
            $this->flash('error', 'events.archive_not_allowed');

            return $this->redirect($this->eventPath());
        }
        $this->app->events()->update((int) $this->event['id'], ['archived_at' => $archive ? gmdate('Y-m-d H:i:s') : null]);
        $this->audit($archive ? 'event_archived' : 'event_unarchived', 'event', (int) $this->event['id']);
        $this->flash('success', $archive ? 'events.archived' : 'events.unarchived');

        return $this->redirect($archive ? '/admin/events' : $this->eventPath());
    }

    /** Deletes the sponsor data of a closed event now instead of after the retention period (NF-11). */
    public function anonymize(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.anonymize');
        if ($this->event['status'] !== 'closed' || $this->event['anonymized_at'] !== null) {
            $this->flash('error', 'events.anonymize_not_allowed');

            return $this->redirect($this->eventPath());
        }
        $n = $this->app->retention()->anonymizeEvent($this->event);
        $this->audit('event_anonymized', 'event', (int) $this->event['id'], null, ['pledges' => $n, 'automatic' => false]);
        $this->flash('success', 'events.anonymized', ['count' => $n]);

        return $this->redirect($this->eventPath());
    }

    public function settings(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.edit');

        return $this->settingsForm($this->inputFromEvent($this->event), []);
    }

    public function updateSettings(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.edit');
        $event = $this->event;
        $input = [
            'name' => $request->input('name'),
            'event_date' => $request->input('event_date'),
            'location' => $request->input('location'),
            'description' => $request->input('description'),
            'lap_length_m' => $request->input('lap_length_m'),
            'entry_deadline' => $request->input('entry_deadline'),
            'goal_chf' => $request->input('goal_chf'),
            'reminder_days' => $request->input('reminder_days'),
            'sender_name' => $request->input('sender_name'),
            'own_color' => $request->input('own_color') === '1',
            'notify_new_sponsor' => $request->input('notify_new_sponsor') === '1',
            'thank_on_payment' => $request->input('thank_on_payment') === '1',
            'primary_color' => $request->input('primary_color'),
            'welcome' => $request->input('welcome'),
            'invite_subject' => $request->input('invite_subject'),
            'invite_intro' => $request->input('invite_intro'),
            'purpose' => $request->input('purpose'),
            'mail_footer' => $request->input('mail_footer'),
            'admins' => $this->perm['assign_admins'] ? $this->selectedAdmins($request) : $this->app->events()->adminIds((int) $event['id']),
        ];

        $errors = [];
        if ($e = Validation::required($input['name'], 120)) {
            $errors['name'] = $e;
        }
        if ($e = Validation::optionalDate($input['event_date'])) {
            $errors['event_date'] = $e;
        }
        if ($e = Validation::optionalDateTime($input['entry_deadline'])) {
            $errors['entry_deadline'] = $e;
        }
        if ($e = Validation::optionalInt($input['lap_length_m'], 1, 100000)) {
            $errors['lap_length_m'] = $e;
        }
        if ($input['reminder_days'] === '') {
            $input['reminder_days'] = '14';
        }
        if ($e = Validation::optionalInt($input['reminder_days'], 1, 90)) {
            $errors['reminder_days'] = 'validation.number';
        }
        if ($e = Validation::optionalInt($input['goal_chf'], 1, 10000000)) {
            $errors['goal_chf'] = $e;
        }
        foreach (['location' => 160, 'sender_name' => 120, 'welcome' => 1000, 'purpose' => 200, 'mail_footer' => 1000, 'invite_subject' => 150, 'invite_intro' => 1000] as $field => $max) {
            if (mb_strlen($input[$field]) > $max) {
                $errors[$field] = 'validation.too_long';
            }
        }
        $color = null;
        if ($input['own_color']) {
            $color = Branding::validColor($input['primary_color']);
            if ($color === null) {
                $errors['primary_color'] = 'validation.color';
            }
        }

        $uploads = [];
        foreach (['logo' => 600, 'twint_qr' => 1000] as $field => $edge) {
            $file = $request->file($field);
            if ($errors === [] && $file !== null) {
                try {
                    $uploads[$field] = $this->app->images()->store($file, $edge);
                } catch (\InvalidArgumentException $e) {
                    $errors[$field] = $e->getMessage();
                }
            }
        }
        if ($errors !== []) {
            foreach ($uploads as $name) {
                $this->app->images()->delete($name);
            }

            return $this->settingsForm($input, $errors, 422);
        }

        $data = [
            'name' => $input['name'],
            'event_date' => $input['event_date'] !== '' ? $input['event_date'] : null,
            'location' => $input['location'],
            'description' => $input['description'] !== '' ? $input['description'] : null,
            'lap_length_m' => $input['lap_length_m'] !== '' ? (int) $input['lap_length_m'] : null,
            'entry_deadline' => $this->localToUtc($input['entry_deadline']),
            'goal_cents' => $input['goal_chf'] !== '' ? (int) $input['goal_chf'] * 100 : null,
            'reminder_days' => (int) $input['reminder_days'],
            'notify_new_sponsor' => $input['notify_new_sponsor'] ? 1 : 0,
            'thank_on_payment' => $input['thank_on_payment'] ? 1 : 0,
            'sender_name' => $input['sender_name'] !== '' ? $input['sender_name'] : null,
            'primary_color' => $color,
            'texts' => ['welcome' => $input['welcome'], 'purpose' => $input['purpose'], 'mail_footer' => $input['mail_footer'], 'invite_subject' => $input['invite_subject'], 'invite_intro' => $input['invite_intro']],
        ];
        foreach (['logo' => 'logo_file', 'twint_qr' => 'twint_qr_file'] as $field => $column) {
            $remove = $request->input('remove_' . $field) === '1';
            if (isset($uploads[$field]) || $remove) {
                if ((string) ($event[$column] ?? '') !== '') {
                    $this->app->images()->delete((string) $event[$column]);
                }
                $data[$column] = $uploads[$field] ?? null;
            }
        }

        $this->app->events()->update((int) $event['id'], $data);
        if ($this->perm['assign_admins']) {
            $before = $this->app->events()->adminIds((int) $event['id']);
            sort($before);
            $after = $input['admins'];
            sort($after);
            if ($before !== $after) {
                $this->app->events()->setAdmins((int) $event['id'], $input['admins']);
                $this->audit('event_admins_updated', 'event', (int) $event['id'], ['admins' => $before], ['admins' => $after]);
            }
        }
        $this->audit('event_updated', 'event', (int) $event['id'], $this->auditFields($event), $this->auditFields($data + ['texts' => $data['texts']]));
        $this->flash('success', 'events.saved');

        return $this->redirect($this->eventPath('/settings'));
    }

    /** @return array<string, mixed> */
    private function inputFromEvent(array $event): array
    {
        return [
            'name' => $event['name'],
            'event_date' => (string) ($event['event_date'] ?? ''),
            'location' => (string) $event['location'],
            'description' => (string) ($event['description'] ?? ''),
            'lap_length_m' => $event['lap_length_m'] === null ? '' : (string) $event['lap_length_m'],
            'entry_deadline' => $this->utcToLocal($event['entry_deadline']),
            'goal_chf' => $event['goal_cents'] === null ? '' : (string) intdiv((int) $event['goal_cents'], 100),
            'reminder_days' => (string) ($event['reminder_days'] ?? 14),
            'sender_name' => (string) ($event['sender_name'] ?? ''),
            'own_color' => $event['primary_color'] !== null,
            'notify_new_sponsor' => (int) ($event['notify_new_sponsor'] ?? 1) === 1,
            'thank_on_payment' => (int) ($event['thank_on_payment'] ?? 1) === 1,
            'primary_color' => (string) ($event['primary_color'] ?? $this->app->branding()->club()['primary_color']),
            'welcome' => $event['texts']['welcome'],
            'invite_subject' => $event['texts']['invite_subject'],
            'invite_intro' => $event['texts']['invite_intro'],
            'purpose' => $event['texts']['purpose'],
            'mail_footer' => $event['texts']['mail_footer'],
            'admins' => $this->app->events()->adminIds((int) $event['id']),
        ];
    }

    private function settingsForm(array $input, array $errors, int $status = 200): Response
    {
        return $this->renderEvent('admin/events/settings.html.twig', 'settings', [
            'input' => $input,
            'errors' => $errors,
            'all_admins' => $this->assignableAdmins(),
            'look' => $this->app->branding()->forEvent($this->event),
            'club' => $this->app->branding()->club(),
        ], $status);
    }

    private function createForm(array $input, array $errors, int $status = 200): Response
    {
        return $this->render('admin/events/create.html.twig', [
            'input' => $input,
            'errors' => $errors,
            'all_admins' => $this->assignableAdmins(),
        ], $status);
    }

    /** @return list<array<string, mixed>> */
    private function assignableAdmins(): array
    {
        return array_values(array_filter(
            $this->app->adminUsers()->all(),
            static fn (array $u) => (int) $u['active'] === 1,
        ));
    }

    /** @return list<int> only ids of existing, active admins */
    private function selectedAdmins(Request $request): array
    {
        $raw = $request->post['admins'] ?? [];
        $valid = array_map(static fn (array $u) => (int) $u['id'], $this->assignableAdmins());
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            if (is_string($value) && ctype_digit($value) && in_array((int) $value, $valid, true)) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    private function localToUtc(string $local): ?string
    {
        if ($local === '') {
            return null;
        }
        $dt = new \DateTimeImmutable($local, new \DateTimeZone(date_default_timezone_get()));

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function utcToLocal(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }

        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d\TH:i');
    }

    /** @return array<string, mixed> */
    private function auditFields(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'event_date', 'location', 'lap_length_m', 'entry_deadline', 'goal_cents',
            'sender_name', 'primary_color', 'logo_file', 'twint_qr_file', 'texts', 'reminder_days', 'notify_new_sponsor', 'thank_on_payment',
        ]));
    }
}
