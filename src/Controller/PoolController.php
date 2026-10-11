<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Security\Permissions;

/**
 * Participant pool across all events (F16: visible to all admins, name and e-mail).
 * Club admins can delete participants who have not taken part for a while (NF-11).
 */
final class PoolController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $user = $this->admin('pool.view');
        $query = mb_substr($request->input('q'), 0, 100);
        $inactive = $request->input('inactive') === '1';
        $retention = $this->app->retention();

        return $this->render('admin/pool.html.twig', [
            'query' => $query,
            'inactive' => $inactive,
            'rows' => $inactive ? $retention->inactiveParticipants() : $this->app->participants()->searchPool($query, null, 300),
            'total' => $this->app->participants()->poolCount(),
            'inactive_years' => $retention->inactiveYears(),
            'can_delete' => Permissions::can($user, 'participants.delete'),
        ]);
    }

    /** Deletes one participant for good; past events keep anonymous figures. */
    public function delete(Request $request, array $params): Response
    {
        $user = $this->admin('participants.delete');
        $person = $this->app->participants()->find((int) $params['pid']);
        if ($person === null || $person['anonymized_at'] !== null) {
            throw new HttpException(404);
        }
        if (!$this->app->retention()->canDelete((int) $person['id'])) {
            $this->flash('error', 'participants.pool.delete_active');

            return $this->redirect('/admin/participants');
        }
        $this->app->retention()->deleteParticipant((int) $person['id']);
        $this->app->audit()->log((int) $user['id'], 'participant_deleted', 'participant', (int) $person['id']);
        $this->flash('success', 'participants.pool.deleted', ['name' => $person['first_name'] . ' ' . $person['last_name']]);

        return $this->redirect('/admin/participants' . ($request->input('back') === 'inactive' ? '?inactive=1' : ''));
    }

    /** Deletes all participants of the inactive list at once. */
    public function deleteInactive(Request $request, array $params): Response
    {
        $user = $this->admin('participants.delete');
        $retention = $this->app->retention();
        $rows = $retention->inactiveParticipants();
        foreach ($rows as $row) {
            $retention->deleteParticipant((int) $row['id']);
        }
        if ($rows !== []) {
            $this->app->audit()->log((int) $user['id'], 'participants_deleted', 'participant', null, null, ['count' => count($rows), 'years' => $retention->inactiveYears()]);
        }
        $this->flash('success', 'participants.pool.deleted_many', ['count' => count($rows)]);

        return $this->redirect('/admin/participants?inactive=1');
    }
}
