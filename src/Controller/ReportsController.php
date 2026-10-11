<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Repository\AuditLog;
use LapFund\Service\ExportService;

/**
 * Event dashboard and reports (AD-03, AD-04), CSV export (AD-05) and the change
 * log of the event (AD-07). Visible to the admins who can see the event.
 */
final class ReportsController extends EventScopedController
{
    private const RANKING_TOP = 10;

    public function index(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.view');
        $reports = $this->app->reports();
        $summary = $reports->summary($this->event);
        $all = $request->input('all') === '1';
        $byLaps = $reports->ranking($this->event, 'laps');
        $amountBy = $summary['owed'] > 0 ? 'amount' : 'sponsors';
        $byAmount = $reports->ranking($this->event, $amountBy);
        $visible = array_map(static fn (array $e) => (int) $e['id'], $this->app->events()->listFor($this->user, true));

        return $this->renderEvent('admin/events/reports.html.twig', 'reports', [
            's' => $summary,
            'after_run' => in_array($this->event['status'], ['run_done', 'billing', 'closed'], true) || $summary['with_laps'] > 0,
            'teams' => $reports->teams($this->event),
            'rank_laps' => $all ? $byLaps : array_slice($byLaps, 0, self::RANKING_TOP),
            'rank_amount' => $all ? $byAmount : array_slice($byAmount, 0, self::RANKING_TOP),
            'rank_amount_by' => $amountBy,
            'rank_more' => !$all && (count($byLaps) > self::RANKING_TOP || count($byAmount) > self::RANKING_TOP),
            'compare' => $reports->compare($visible),
            'exports' => ExportService::KINDS,
        ]);
    }

    /** CSV download (AD-05): participants, pledges or payments. */
    public function export(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.view');
        $kind = (string) $params['kind'];
        if (!in_array($kind, ExportService::KINDS, true)) {
            throw new HttpException(404);
        }
        $file = $this->app->exports()->build($this->event, $kind);
        $this->audit('export_downloaded', 'event', (int) $this->event['id'], null, ['kind' => $kind]);

        return new Response($file['body'], 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Change log of this event (AD-07). */
    public function log(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.view');
        $area = $request->input('area');
        $area = isset(AuditLog::AREAS[$area]) ? $area : '';
        $result = $this->app->audit()->search(['event_id' => (int) $this->event['id'], 'area' => $area], max(1, (int) $request->input('page')));

        return $this->renderEvent('admin/events/audit.html.twig', 'audit', [
            'log' => $result,
            'area' => $area,
            'areas' => array_keys(AuditLog::AREAS),
        ]);
    }
}
