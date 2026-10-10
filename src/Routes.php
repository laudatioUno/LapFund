<?php

declare(strict_types=1);

namespace LapFund;

use LapFund\Controller\AdminController;
use LapFund\Controller\AssetController;
use LapFund\Controller\AuthController;
use LapFund\Controller\InstallController;
use LapFund\Controller\SettingsController;
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

        // Public
        $r->get('/', $c(AdminController::class, 'home'));
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
        $r->get('/admin/audit', $c(AdminController::class, 'audit'));

        $r->get('/admin/users', $c(UserController::class, 'index'));
        $r->get('/admin/users/new', $c(UserController::class, 'create'));
        $r->post('/admin/users/new', $c(UserController::class, 'store'));
        $r->get('/admin/users/{id}', $c(UserController::class, 'edit'));
        $r->post('/admin/users/{id}', $c(UserController::class, 'update'));
        $r->post('/admin/users/{id}/invite', $c(UserController::class, 'reinvite'));
        $r->post('/admin/users/{id}/reset-totp', $c(UserController::class, 'resetTotp'));
        $r->post('/admin/users/{id}/unlock', $c(UserController::class, 'unlock'));
        $r->post('/admin/users/{id}/active', $c(UserController::class, 'toggleActive'));

        $r->get('/admin/settings', $c(SettingsController::class, 'edit'));
        $r->post('/admin/settings', $c(SettingsController::class, 'update'));
        $r->post('/admin/settings/logo/delete', $c(SettingsController::class, 'deleteLogo'));
    }
}
