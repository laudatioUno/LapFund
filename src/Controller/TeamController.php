<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Validation;

/**
 * Teams per event (TN-05). The assignment of participants is per event,
 * because juniors change teams from year to year.
 */
final class TeamController extends EventScopedController
{
    public function index(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.view');

        return $this->page('', []);
    }

    public function store(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.participants');
        $name = $request->input('name');
        if ($error = $this->nameError($name, null)) {
            return $this->page($name, ['name' => $error], 422);
        }
        $id = $this->app->teams()->create((int) $this->event['id'], $name);
        $this->audit('team_created', 'team', $id, null, ['name' => $name]);
        $this->flash('success', 'events.teams.created', ['name' => $name]);

        return $this->redirect($this->eventPath('/teams'));
    }

    public function update(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.participants');
        $team = $this->team($params);
        $action = $request->input('action');

        if ($action === 'up' || $action === 'down') {
            $this->app->teams()->move((int) $this->event['id'], (int) $team['id'], $action === 'up' ? -1 : 1);

            return $this->redirect($this->eventPath('/teams'));
        }

        if ($action === 'delete') {
            $this->app->teams()->delete((int) $team['id']);
            $this->audit('team_deleted', 'team', (int) $team['id'], ['name' => $team['name']]);
            $this->flash('success', 'events.teams.deleted', ['name' => $team['name']]);

            return $this->redirect($this->eventPath('/teams'));
        }

        $name = $request->input('name');
        if ($error = $this->nameError($name, (int) $team['id'])) {
            $this->flash('error', $error);

            return $this->redirect($this->eventPath('/teams'));
        }
        $this->app->teams()->rename((int) $team['id'], $name);
        $this->audit('team_renamed', 'team', (int) $team['id'], ['name' => $team['name']], ['name' => $name]);
        $this->flash('success', 'events.teams.renamed', ['name' => $name]);

        return $this->redirect($this->eventPath('/teams'));
    }

    private function page(string $newName, array $errors, int $status = 200): Response
    {
        return $this->renderEvent('admin/events/teams.html.twig', 'teams', [
            'teams' => $this->app->teams()->forEvent((int) $this->event['id']),
            'new_name' => $newName,
            'errors' => $errors,
        ], $status);
    }

    private function nameError(string $name, ?int $ownId): ?string
    {
        if ($e = Validation::required($name, 80)) {
            return $e;
        }
        $existing = $this->app->teams()->findByName((int) $this->event['id'], $name);

        return ($existing !== null && (int) $existing['id'] !== $ownId) ? 'events.teams.name_taken' : null;
    }

    /** @return array<string, mixed> */
    private function team(array $params): array
    {
        $team = $this->app->teams()->find((int) $this->event['id'], (int) $params['teamId']);
        if ($team === null) {
            throw new HttpException(404);
        }

        return $team;
    }
}
