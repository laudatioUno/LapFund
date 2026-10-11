<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\App;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Repository\AuditLog;

final class AdminController extends Controller
{
    public function dashboard(Request $request, array $params): Response
    {
        $user = $this->admin('dashboard.view');
        $brand = $this->app->branding()->club();
        $isClub = $user['role'] === 'club_admin';

        $checklist = [];
        if ($isClub) {
            $checklist = [
                ['done' => $brand['logo'] !== '', 'label' => $this->t('admin.dashboard.check_logo'), 'link' => '/admin/settings'],
                ['done' => $brand['contact_email'] !== '', 'label' => $this->t('admin.dashboard.check_contact'), 'link' => '/admin/settings'],
                ['done' => $brand['privacy_url'] !== '', 'label' => $this->t('admin.dashboard.check_privacy'), 'link' => '/admin/settings'],
                ['done' => count($this->app->adminUsers()->all()) > 1, 'label' => $this->t('admin.dashboard.check_admins'), 'link' => '/admin/users'],
            ];
        }

        return $this->render('admin/dashboard.html.twig', [
            'events' => array_slice($this->app->events()->listFor($user), 0, 6),
            'can_create' => $isClub,
            'checklist' => $checklist,
            'pending_migrations' => $isClub ? $this->app->migrator()->pending() : [],
        ]);
    }

    public function system(Request $request, array $params): Response
    {
        $this->admin('system.manage');

        return $this->render('admin/system.html.twig', [
            'app_version' => App::VERSION,
            'php_version' => PHP_VERSION,
            'db_version' => (string) $this->app->db()->value('SELECT VERSION()'),
            'pending' => $this->app->migrator()->pending(),
        ]);
    }

    public function migrate(Request $request, array $params): Response
    {
        $user = $this->admin('system.manage');
        $done = $this->app->migrator()->migrate();
        $this->app->audit()->log((int) $user['id'], 'migrate', 'system', null, null, ['versions' => $done]);
        $this->flash('success', 'admin.system.migrated', ['count' => count($done)]);

        return $this->redirect('/admin/system');
    }

    public function audit(Request $request, array $params): Response
    {
        $user = $this->admin('audit.view');
        $area = $request->input('area');
        $area = isset(AuditLog::AREAS[$area]) ? $area : '';
        $event = $request->input('event');
        $eventId = ctype_digit($event) ? (int) $event : null;

        return $this->render('admin/audit.html.twig', [
            'log' => $this->app->audit()->search(['event_id' => $eventId, 'area' => $area], max(1, (int) $request->input('page'))),
            'area' => $area,
            'areas' => array_keys(AuditLog::AREAS),
            'event_id' => $eventId,
            'events' => $this->app->events()->listFor($user, true),
        ]);
    }

    /**
     * Database export for backups (NF-17). Contains all personal data, so the
     * club admin confirms with the code of the authenticator app.
     */
    public function backup(Request $request, array $params): Response
    {
        $user = $this->admin('system.manage');
        if (!$this->app->auth()->attemptSecondFactor($user, trim($request->input('code')))) {
            $this->flash('error', 'admin.system.backup_code_wrong');

            return $this->redirect('/admin/system');
        }
        $out = fopen('php://temp', 'w+b');
        $this->app->databaseExport()->write($out, App::VERSION);
        rewind($out);
        $body = (string) stream_get_contents($out);
        fclose($out);
        $this->app->audit()->log((int) $user['id'], 'database_exported', 'system', null, null, ['bytes' => strlen($body)]);

        return new Response($body, 200, [
            'Content-Type' => 'application/sql; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $this->app->databaseExport()->filename() . '"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
