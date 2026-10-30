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
        if ($request->input('send_results') === '1') {
            // Result mail to the participants (EM-08): only new ones and changed laps
            $r = $this->app->access()->queueResults($this->event, $this->app->billing()->participants((int) $this->event['id']));
            if ($r['sent'] + $r['corrected'] > 0) {
                $this->audit('result_mails_sent', 'event', (int) $this->event['id'], null, ['count' => $r['sent'], 'corrections' => $r['corrected']]);
                $this->flash('info', 'laps.results_queued', ['count' => $r['sent'], 'corrections' => $r['corrected']]);
            }
            if ($r['waiting'] > 0) {
                $this->flash('info', 'laps.results_waiting', ['count' => $r['waiting']]);
            }
        }
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
        $groups = [];
        foreach ($this->rowsForPdf($request) as $row) {
            $key = $row['team_id'] === null ? 'none' : (string) $row['team_id'];
            $groups[$key] ??= ['name' => $row['team_name'], 'rows' => []];
            $groups[$key]['rows'][] = $row;
        }

        return $this->pdfResponse('pdf/lap_list.html.twig', ['groups' => $groups], 'laps.pdf_title', 'Zaehlliste.pdf', true);
    }

    /**
     * Counting strips: one strip per participant, six per A4 page to cut apart and
     * hand to the counters (issue #8). Whole event in one run, or one team.
     */
    public function strips(Request $request, array $params): Response
    {
        $this->loadEvent($params, 'event.view');

        return $this->pdfResponse('pdf/lap_strips.html.twig', ['rows' => $this->rowsForPdf($request)], 'laps.strips_title', 'Zaehlstreifen.pdf', false);
    }

    /** Participants in the order of the lap entry, optionally of one team (?team=<id> or none). */
    private function rowsForPdf(Request $request): array
    {
        $team = $request->input('team');

        return array_values(array_filter(
            $this->app->billing()->participants((int) $this->event['id']),
            static fn (array $r) => $team === '' || $team === ($r['team_id'] === null ? 'none' : (string) $r['team_id']),
        ));
    }

    /** @param array<string, mixed> $data */
    private function pdfResponse(string $template, array $data, string $titleKey, string $filename, bool $pageNumbers): Response
    {
        $brand = $this->app->branding()->forEvent($this->event);
        $html = $this->app->view()->renderMail($template, $data + [
            'event' => $this->event,
            'brand' => $brand,
            'logo_path' => $brand['logo'] !== '' ? $this->app->images()->path($brand['logo']) : null,
        ]);
        $title = $this->t($titleKey, ['event' => $this->event['name']]);

        return new Response($this->app->pdf()->render($html, $title, $pageNumbers), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
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
