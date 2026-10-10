<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\App;
use LapFund\Http\Request;
use LapFund\Http\Response;

final class AdminController extends Controller
{
    /** Public start page; the participant "link lost" form follows in stage 3. */
    public function home(Request $request, array $params): Response
    {
        return $this->redirect('/admin');
    }

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
        $this->admin('audit.view');

        return $this->render('admin/audit.html.twig', ['entries' => $this->app->audit()->latest(200)]);
    }
}
