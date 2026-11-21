<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Domain\EventStatus;
use LapFund\Http\HttpException;
use LapFund\Http\Response;
use LapFund\Security\Permissions;

/**
 * Base for pages inside an event: loads the event and checks the permission
 * for the requested action (architecture chapter 6).
 */
abstract class EventScopedController extends Controller
{
    /** @var array<string, mixed> */
    protected array $event = [];

    /** @var array<string, mixed> */
    protected array $user = [];

    /** @var array<string, bool> */
    protected array $perm = [];

    protected function loadEvent(array $params, string $action): void
    {
        $this->user = $this->admin('events.list');
        $event = $this->app->events()->find((int) $params['id']);
        if ($event === null) {
            throw new HttpException(404);
        }
        $assigned = $this->app->events()->isAssigned((int) $event['id'], (int) $this->user['id']);
        if (!Permissions::canOnEvent($this->user, 'event.view', $assigned)) {
            throw new HttpException(404);
        }
        if (!Permissions::canOnEvent($this->user, $action, $assigned)) {
            throw new HttpException(403);
        }
        $this->event = $event;
        $this->perm = Permissions::eventFlags($this->user, $assigned) + ['assigned' => $assigned];
        if ($this->isFinal() && $this->app->request->isPost() && !in_array($action, self::ALLOWED_WHEN_FINAL, true)) {
            $this->flash('error', 'license.closed_final');
            throw new RedirectException($this->app->request->url($this->eventPath()));
        }
    }

    /** What still works on a closed event of a customer instance (exports are GET requests). */
    private const ALLOWED_WHEN_FINAL = ['event.archive', 'event.anonymize'];

    /**
     * On customer instances a closed event is final: it cannot be reopened or
     * changed any more, only viewed and exported.
     */
    protected function isFinal(): bool
    {
        return $this->app->license()->enabled() && ($this->event['status'] ?? '') === EventStatus::CLOSED;
    }

    /** @param array<string, mixed> $data */
    protected function renderEvent(string $template, string $tab, array $data = [], int $status = 200): Response
    {
        return $this->render($template, $data + [
            'event' => $this->event,
            'perm' => $this->perm,
            'event_tab' => $tab,
            'event_final' => $this->isFinal(),
            'event_pending' => $this->app->license()->enabled() && ($this->event['license_state'] ?? 'active') === 'pending',
        ], $status);
    }

    protected function eventPath(string $suffix = ''): string
    {
        return '/admin/events/' . $this->event['id'] . $suffix;
    }

    protected function audit(string $action, string $entityType, ?int $entityId, ?array $before = null, ?array $after = null): void
    {
        $this->app->audit()->log((int) $this->user['id'], $action, $entityType, $entityId, $before, ($after ?? []) + ['event_id' => (int) $this->event['id']]);
    }
}
