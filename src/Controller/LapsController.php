<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\Request;
use LapFund\Http\Response;

/**
 * Lap list as PDF for counting on the day (RU-05) and quick entry of the laps (RU-01 to RU-04).
 */
final class LapsController extends EventScopedController
{
    /** Laps are recorded after the run and can be corrected during billing. */
    public const EDIT_STATES = ['run_done', 'billing'];

    public function index(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.view');

        return $this->page($this->app->billing()->participants((int) $this->event['id']), []);
    }

    public function save(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.laps');
        if (!in_array($this->event['status'], self::EDIT_STATES, true)) {
            $this->flash('error', 'laps.not_now');

            return $this->redirect($this->eventPath('/laps'));
        }
        $laps = $request->post['laps'] ?? [];
        $dns = $request->post['dns'] ?? [];
        $parsed = \LapFund\Service\BillingService::parseLaps(is_array($laps) ? $laps : [], is_array($dns) ? $dns : []);
        if ($parsed['errors'] !== []) {
            $rows = $this->app->billing()->participants((int) $this->event['id']);
            foreach ($rows as &$row) {
                $id = (int) $row['participation_id'];
                $row['input'] = is_array($laps) ? (string) ($laps[$id] ?? '') : '';
                $row['input_dns'] = is_array($dns) && isset($dns[$id]);
            }
            unset($row);

            return $this->page($rows, $parsed['errors'], 422);
        }
        $result = $this->app->billingService()->saveLaps($this->event, $parsed['values'], (int) $this->user['id'], $request->input('send_corrections') === '1');
        $this->flash('success', 'laps.saved', ['count' => $result['changed']]);
        if ($result['affected'] !== []) {
            $this->flash($request->input('send_corrections') === '1' ? 'info' : 'warning',
                $request->input('send_corrections') === '1' ? 'laps.corrections_sent' : 'laps.corrections_hint',
                ['count' => count($result['affected'])]);
        }

        return $this->redirect($this->eventPath('/laps'));
    }

    /** Lap list per team, each team on its own page (RU-05). */
    public function pdf(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.view');
        $teamFilter = $request->input('team');
        $rows = $this->app->billing()->participants((int) $this->event['id']);
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['team_id'] === null ? 'none' : (string) $row['team_id'];
            if ($teamFilter !== '' && $teamFilter !== $key) {
                continue;
            }
            $groups[$key] ??= ['name' => $row['team_name'], 'rows' => []];
            $groups[$key]['rows'][] = $row;
        }
        $brand = $this->app->branding()->forEvent($this->event);
        $html = $this->app->view()->renderMail('pdf/lap_list.html.twig', [
            'event' => $this->event,
            'brand' => $brand,
            'logo_path' => $brand['logo'] !== '' ? $this->app->images()->path($brand['logo']) : null,
            'groups' => $groups,
        ]);

        return new Response($this->app->pdf()->render($html, $this->t('laps.pdf_title', ['event' => $this->event['name']])), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Zaehlliste.pdf"',
        ]);
    }

    private function page(array $rows, array $errors, int $status = 200): Response
    {
        return $this->renderEvent('admin/events/laps.html.twig', 'laps', [
            'rows' => $rows,
            'errors' => $errors,
            'teams' => $this->app->teams()->forEvent((int) $this->event['id']),
            'can_edit' => $this->perm['laps'] && in_array($this->event['status'], self::EDIT_STATES, true),
            'requested' => $this->app->billing()->requestedOpenCount((int) $this->event['id']) > 0,
        ], $status);
    }
}
