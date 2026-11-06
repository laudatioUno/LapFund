<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Service\Csv;

/**
 * Contacts: sponsors who agreed that the club may stay in touch (newsletter).
 * Club admins only: list, CSV export for the newsletter tool, unsubscribe, delete.
 */
final class ContactsController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $this->admin('contacts.manage');
        $filter = $this->filter($request);

        return $this->render('admin/contacts.html.twig', [
            'filter' => $filter,
            'rows' => $this->app->contacts()->search($filter),
            'counts' => $this->app->contacts()->counts(),
            'events' => $this->app->contacts()->events(),
            'enabled' => $this->app->contactOptIn() !== null,
        ]);
    }

    /** Active contacts (with the current filter) as CSV for the newsletter tool. */
    public function export(Request $request, array $params): Response
    {
        $admin = $this->admin('contacts.manage');
        $filter = ['active' => true] + $this->filter($request);
        $rows = $this->app->contacts()->search($filter, 10000);
        $t = fn (string $key): string => $this->app->translator->t('admin.contacts.col.' . $key);
        $body = Csv::build([$t('name'), $t('email'), $t('consent_at'), $t('source'), $t('consent_text')], array_map(fn (array $r): array => [
            $r['name'], $r['email'], $this->app->formatter->date($r['consent_at']), $r['source_event_name'], str_replace("\n", ' – ', (string) $r['consent_text']),
        ], $rows));
        $this->app->audit()->log((int) $admin['id'], 'contacts_exported', 'contact', null, null, ['count' => count($rows)]);

        return new Response($body, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="kontakte-' . gmdate('Y-m-d') . '.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Marks a contact as unsubscribed (e.g. after a request by mail); not exported any more. */
    public function revoke(Request $request, array $params): Response
    {
        $admin = $this->admin('contacts.manage');
        $contact = $this->contact($params);
        if ($contact['revoked_at'] === null) {
            $this->app->contacts()->revoke((int) $contact['id']);
            $this->app->audit()->log((int) $admin['id'], 'contact_revoked', 'contact', (int) $contact['id']);
        }
        $this->flash('success', 'admin.contacts.revoked', ['name' => $contact['name']]);

        return $this->redirect('/admin/contacts');
    }

    /** Deletes a contact for good. */
    public function delete(Request $request, array $params): Response
    {
        $admin = $this->admin('contacts.manage');
        $contact = $this->contact($params);
        $this->app->contacts()->delete((int) $contact['id']);
        $this->app->audit()->log((int) $admin['id'], 'contact_deleted', 'contact', (int) $contact['id']);
        $this->flash('success', 'admin.contacts.deleted', ['name' => $contact['name']]);

        return $this->redirect('/admin/contacts');
    }

    /** @return array{q: string, event_id: int} */
    private function filter(Request $request): array
    {
        return ['q' => mb_substr(trim($request->input('q')), 0, 100), 'event_id' => max(0, (int) $request->input('event'))];
    }

    /** @return array<string, mixed> */
    private function contact(array $params): array
    {
        $contact = $this->app->contacts()->find((int) $params['id']);
        if ($contact === null) {
            throw new HttpException(404);
        }

        return $contact;
    }
}
