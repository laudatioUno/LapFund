<?php

declare(strict_types=1);

namespace LapFund;

use LapFund\Controller\AdminController;
use LapFund\Controller\AssetController;
use LapFund\Controller\AuthController;
use LapFund\Controller\EventController;
use LapFund\Controller\EventMailController;
use LapFund\Controller\InstallController;
use LapFund\Controller\LapsController;
use LapFund\Controller\MailController;
use LapFund\Controller\ParticipantController;
use LapFund\Controller\PaymentsController;
use LapFund\Controller\PledgeAdminController;
use LapFund\Controller\PoolController;
use LapFund\Controller\PublicController;
use LapFund\Controller\ReportsController;
use LapFund\Controller\SettingsController;
use LapFund\Controller\SponsorController;
use LapFund\Controller\TeamController;
use LapFund\Controller\UserController;
use LapFund\Http\Router;

/**
 * All routes of the application (architecture chapter 8).
 */
final class Routes
{
    public static function register(Router $r, App $app): void
    {
        $c = static fn (string $class, string $method): \Closure =>
            static fn ($request, array $params) => (new $class($app))->{$method}($request, $params);

        // Public and participants (stage 3)
        $r->get('/', $c(PublicController::class, 'home'));
        $r->post('/link', $c(PublicController::class, 'requestLinks'));
        $r->get('/link/sent', $c(PublicController::class, 'linksSent'));
        $r->get('/t/{token:[A-Za-z0-9_-]+}', $c(PublicController::class, 'magic'));
        $r->get('/me', $c(PublicController::class, 'me'));
        $r->post('/me/message', $c(PublicController::class, 'saveMessage'));
        $r->post('/me/switch', $c(PublicController::class, 'switchParticipation'));
        $r->post('/me/logout', $c(PublicController::class, 'logout'));
        $r->post('/me/pledges/{pledge:[1-9][0-9]*}/cash', $c(PublicController::class, 'cash'));
        $r->get('/me/pledges/new', $c(PublicController::class, 'newPledge'));
        $r->post('/me/pledges/new', $c(PublicController::class, 'storePledge'));
        $r->get('/me/list.pdf', $c(PublicController::class, 'listPdf'));

        // Sponsors (stage 4)
        $r->get('/i/{token:[A-Za-z0-9_-]+}', $c(SponsorController::class, 'invitation'));
        $r->post('/i/{token:[A-Za-z0-9_-]+}', $c(SponsorController::class, 'pledge'));
        $r->get('/p/{token:[A-Za-z0-9_-]+}', $c(SponsorController::class, 'payment'));
        $r->post('/p/{token:[A-Za-z0-9_-]+}/mail', $c(SponsorController::class, 'paymentMail'));
        $r->get('/cron/{key:[A-Za-z0-9]+}', $c(MailController::class, 'cron'));
        $r->get('/theme.css', $c(AssetController::class, 'theme'));
        $r->get('/media/{file:[a-f0-9]+\.png}', $c(AssetController::class, 'media'));

        // Installer
        $r->get('/install', $c(InstallController::class, 'show'));
        $r->post('/install', $c(InstallController::class, 'install'));
        $r->get('/install/done', $c(InstallController::class, 'done'));

        // Login
        $r->get('/admin/login', $c(AuthController::class, 'loginForm'));
        $r->post('/admin/login', $c(AuthController::class, 'login'));
        $r->get('/admin/login/totp', $c(AuthController::class, 'totpForm'));
        $r->post('/admin/login/totp', $c(AuthController::class, 'totp'));
        $r->get('/admin/login/setup', $c(AuthController::class, 'setupForm'));
        $r->post('/admin/login/setup', $c(AuthController::class, 'setup'));
        $r->get('/admin/login/recovery-codes', $c(AuthController::class, 'recoveryCodes'));
        $r->post('/admin/login/recovery-codes', $c(AuthController::class, 'recoveryCodesDone'));
        $r->post('/admin/logout', $c(AuthController::class, 'logout'));
        $r->get('/admin/invite/{token}', $c(AuthController::class, 'inviteForm'));
        $r->post('/admin/invite/{token}', $c(AuthController::class, 'invite'));

        // Admin console
        $r->get('/admin', $c(AdminController::class, 'dashboard'));
        $r->get('/admin/system', $c(AdminController::class, 'system'));
        $r->post('/admin/system/migrate', $c(AdminController::class, 'migrate'));
        $r->post('/admin/system/backup', $c(AdminController::class, 'backup'));
        $r->get('/admin/audit', $c(AdminController::class, 'audit'));
        $r->get('/admin/mail', $c(MailController::class, 'edit'));
        $r->post('/admin/mail', $c(MailController::class, 'update'));
        $r->post('/admin/mail/test', $c(MailController::class, 'test'));
        $r->get('/admin/mail/log', $c(MailController::class, 'log'));
        $r->post('/admin/mail/retry', $c(MailController::class, 'retry'));
        $r->post('/admin/mail/tick', $c(MailController::class, 'tick'));

        $r->get('/admin/users', $c(UserController::class, 'index'));
        $r->get('/admin/users/new', $c(UserController::class, 'create'));
        $r->post('/admin/users/new', $c(UserController::class, 'store'));
        $r->get('/admin/users/{id}', $c(UserController::class, 'edit'));
        $r->post('/admin/users/{id}', $c(UserController::class, 'update'));
        $r->post('/admin/users/{id}/invite', $c(UserController::class, 'reinvite'));
        $r->post('/admin/users/{id}/reset-totp', $c(UserController::class, 'resetTotp'));
        $r->post('/admin/users/{id}/unlock', $c(UserController::class, 'unlock'));
        $r->post('/admin/users/{id}/active', $c(UserController::class, 'toggleActive'));

        // Events (stage 2)
        $r->get('/admin/events', $c(EventController::class, 'index'));
        $r->get('/admin/events/new', $c(EventController::class, 'create'));
        $r->post('/admin/events/new', $c(EventController::class, 'store'));
        $r->get('/admin/events/{id}', $c(EventController::class, 'show'));
        $r->post('/admin/events/{id}/status', $c(EventController::class, 'status'));
        $r->post('/admin/events/{id}/archive', $c(EventController::class, 'archive'));
        $r->post('/admin/events/{id}/anonymize', $c(EventController::class, 'anonymize'));
        $r->get('/admin/events/{id}/settings', $c(EventController::class, 'settings'));
        $r->post('/admin/events/{id}/settings', $c(EventController::class, 'updateSettings'));

        $r->get('/admin/events/{id}/teams', $c(TeamController::class, 'index'));
        $r->post('/admin/events/{id}/teams', $c(TeamController::class, 'store'));
        $r->post('/admin/events/{id}/teams/{teamId:[1-9][0-9]*}', $c(TeamController::class, 'update'));

        $r->get('/admin/events/{id}/participants', $c(ParticipantController::class, 'index'));
        $r->get('/admin/events/{id}/participants/add', $c(ParticipantController::class, 'add'));
        $r->post('/admin/events/{id}/participants/add', $c(ParticipantController::class, 'storeNew'));
        $r->post('/admin/events/{id}/participants/add-from-pool', $c(ParticipantController::class, 'storeFromPool'));
        $r->get('/admin/events/{id}/participants/import', $c(ParticipantController::class, 'importForm'));
        $r->post('/admin/events/{id}/participants/import', $c(ParticipantController::class, 'importPreview'));
        $r->post('/admin/events/{id}/participants/import/apply', $c(ParticipantController::class, 'importApply'));
        $r->get('/admin/events/{id}/participants/copy', $c(ParticipantController::class, 'copyForm'));
        $r->post('/admin/events/{id}/participants/copy', $c(ParticipantController::class, 'copyApply'));
        $r->get('/admin/events/{id}/participants/{pid:[1-9][0-9]*}', $c(ParticipantController::class, 'edit'));
        $r->post('/admin/events/{id}/participants/{pid:[1-9][0-9]*}', $c(ParticipantController::class, 'update'));

        $r->get('/admin/events/{id}/mails', $c(EventMailController::class, 'index'));
        $r->post('/admin/events/{id}/invitations', $c(EventMailController::class, 'inviteAll'));
        $r->post('/admin/events/{id}/mails/retry', $c(EventMailController::class, 'retry'));
        $r->post('/admin/events/{id}/participants/{pid:[1-9][0-9]*}/invite', $c(ParticipantController::class, 'resendInvite'));
        $r->post('/admin/events/{id}/participants/{pid:[1-9][0-9]*}/link', $c(ParticipantController::class, 'newLink'));

        // Laps and billing (stage 5)
        $r->get('/admin/events/{id}/laps', $c(LapsController::class, 'index'));
        $r->post('/admin/events/{id}/laps', $c(LapsController::class, 'save'));
        $r->get('/admin/events/{id}/laps.pdf', $c(LapsController::class, 'pdf'));
        $r->get('/admin/events/{id}/lap-strips.pdf', $c(LapsController::class, 'strips'));
        $r->get('/admin/events/{id}/payments', $c(PaymentsController::class, 'index'));
        $r->post('/admin/events/{id}/payments/send', $c(PaymentsController::class, 'send'));
        $r->post('/admin/events/{id}/payments/test', $c(PaymentsController::class, 'test'));
        $r->post('/admin/events/{id}/payments/remind', $c(PaymentsController::class, 'remind'));
        $pp = '/admin/events/{id}/participants/{pid:[1-9][0-9]*}';
        $r->get($pp . '/pledges/new', $c(PledgeAdminController::class, 'create'));
        $r->post($pp . '/pledges/new', $c(PledgeAdminController::class, 'store'));
        $r->get($pp . '/pledges/{plid:[1-9][0-9]*}', $c(PledgeAdminController::class, 'edit'));
        $r->post($pp . '/pledges/{plid:[1-9][0-9]*}', $c(PledgeAdminController::class, 'update'));
        $r->post($pp . '/pledges/{plid:[1-9][0-9]*}/cancel', $c(PledgeAdminController::class, 'cancel'));
        $r->post($pp . '/pledges/{plid:[1-9][0-9]*}/paid', $c(PledgeAdminController::class, 'paid'));
        $r->post($pp . '/pledges/{plid:[1-9][0-9]*}/unpaid', $c(PledgeAdminController::class, 'unpaid'));
        $r->post($pp . '/paid', $c(PledgeAdminController::class, 'allPaid'));
        $r->post($pp . '/paid/undo', $c(PledgeAdminController::class, 'undoAll'));

        // Reports, export, change log (stage 6)
        $r->get('/admin/events/{id}/reports', $c(ReportsController::class, 'index'));
        $r->get('/admin/events/{id}/export/{kind:[a-z]+}.csv', $c(ReportsController::class, 'export'));
        $r->get('/admin/events/{id}/log', $c(ReportsController::class, 'log'));

        $r->get('/admin/participants', $c(PoolController::class, 'index'));
        $r->post('/admin/participants/{pid:[1-9][0-9]*}/delete', $c(PoolController::class, 'delete'));
        $r->post('/admin/participants/delete-inactive', $c(PoolController::class, 'deleteInactive'));

        $r->get('/admin/settings', $c(SettingsController::class, 'edit'));
        $r->post('/admin/settings', $c(SettingsController::class, 'update'));
        $r->post('/admin/settings/logo/delete', $c(SettingsController::class, 'deleteLogo'));
    }
}
