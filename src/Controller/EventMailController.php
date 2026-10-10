<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\Request;
use LapFund\Http\Response;

/**
 * Mails of an event: send waves with progress, log, sending the invitations (EM-01, TN-03, NF-16).
 */
final class EventMailController extends EventScopedController
{
    public function index(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.view');
        $eventId = (int) $this->event['id'];
        $queue = $this->app->mailQueue();
        $counts = $queue->counts($eventId);

        return $this->renderEvent('admin/events/mails.html.twig', 'mails', [
            'counts' => $counts,
            'batches' => $queue->batches($eventId, 10),
            'rows' => $queue->log($eventId, 200),
            'uninvited' => $this->app->access()->uninvitedCount($eventId),
            'configured' => $this->app->mailSettings()->isConfigured(),
            'in_progress' => $counts['queued'] + $counts['sending'] > 0,
        ]);
    }

    /** Invitation with magic link to everyone not invited yet (EM-01). Only while sponsors are searched (EV-03). */
    public function inviteAll(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.mail');
        if ($this->event['status'] !== 'collecting') {
            $this->flash('error', 'mails.invite.only_collecting');

            return $this->redirect($this->eventPath('/mails'));
        }
        if (!$this->app->mailSettings()->isConfigured()) {
            $this->flash('error', 'mails.not_configured');

            return $this->redirect($this->eventPath('/mails'));
        }
        $result = $this->app->access()->inviteAll($this->event, (int) $this->user['id']);
        if ($result === null || $result['count'] === 0) {
            $this->flash('info', 'mails.invite.nobody');
        } else {
            $this->audit('invitations_sent', 'event', (int) $this->event['id'], null, ['count' => $result['count'], 'batch_id' => $result['batch_id']]);
            $this->flash('success', 'mails.invite.queued', ['count' => $result['count']]);
        }

        return $this->redirect($this->eventPath('/mails'));
    }

    public function retry(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.mail');
        $n = $this->app->mailQueue()->retryFailed((int) $this->event['id']);
        $this->audit('mails_retried', 'event', (int) $this->event['id'], null, ['count' => $n]);
        $this->flash('success', 'mail.log.retried', ['count' => $n]);

        return $this->redirect($this->eventPath('/mails'));
    }
}
