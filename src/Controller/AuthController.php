<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Database\Database;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Security\Auth;

/**
 * Admin login (AR-06 to AR-09): password, then TOTP or recovery code.
 * Also the first TOTP setup and the acceptance of admin invitations (AR-07).
 */
final class AuthController extends Controller
{
    public function loginForm(Request $request, array $params): Response
    {
        if ($this->app->auth()->user() !== null) {
            return $this->redirect('/admin');
        }

        return $this->render('auth/login.html.twig', ['username' => '']);
    }

    public function login(Request $request, array $params): Response
    {
        $username = strtolower($request->input('username'));
        $result = $this->app->auth()->attemptPassword($username, $request->raw('password'), $request->ip());

        if ($result === 'ok') {
            $user = $this->app->auth()->pendingUser();

            return $this->redirect($user !== null && $user['totp_secret_enc'] === null ? '/admin/login/setup' : '/admin/login/totp');
        }

        $message = match ($result) {
            'locked' => 'auth.locked',
            'rate_limited' => 'auth.rate_limited',
            default => 'auth.invalid_credentials',
        };

        return $this->render('auth/login.html.twig', [
            'username' => $username,
            'error' => $this->t($message, ['minutes' => (int) (Auth::LOCK_SECONDS / 60)]),
        ], $result === 'rate_limited' ? 429 : 422);
    }

    public function totpForm(Request $request, array $params): Response
    {
        $user = $this->app->auth()->pendingUser();
        if ($user === null) {
            return $this->redirect('/admin/login');
        }
        if ($user['totp_secret_enc'] === null) {
            return $this->redirect('/admin/login/setup');
        }

        return $this->render('auth/totp.html.twig', ['user' => $user]);
    }

    public function totp(Request $request, array $params): Response
    {
        $auth = $this->app->auth();
        $user = $auth->pendingUser();
        if ($user === null) {
            $this->flash('error', 'auth.pending_expired');

            return $this->redirect('/admin/login');
        }

        if (!$auth->attemptSecondFactor($user, $request->input('code'))) {
            return $this->render('auth/totp.html.twig', [
                'user' => $user,
                'error' => $this->t('auth.invalid_code'),
            ], 422);
        }

        $auth->login($user);
        $this->app->audit()->log((int) $user['id'], 'login', 'admin_user', (int) $user['id']);

        $remaining = (int) $this->app->db()->value(
            'SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_user_id = ? AND used_at IS NULL',
            [(int) $user['id']],
        );
        if ($remaining <= 3) {
            $this->flash('warning', 'auth.recovery_codes_low', ['count' => $remaining]);
        }

        return $this->redirect('/admin');
    }

    public function setupForm(Request $request, array $params): Response
    {
        $user = $this->app->auth()->pendingUser();
        if ($user === null) {
            return $this->redirect('/admin/login');
        }
        if ($user['totp_secret_enc'] !== null) {
            return $this->redirect('/admin/login/totp');
        }

        return $this->renderSetup($user);
    }

    public function setup(Request $request, array $params): Response
    {
        $auth = $this->app->auth();
        $user = $auth->pendingUser();
        if ($user === null || $user['totp_secret_enc'] !== null) {
            return $this->redirect('/admin/login');
        }

        $codes = $auth->confirmSetup($user, $request->input('code'));
        if ($codes === null) {
            return $this->renderSetup($user, $this->t('auth.invalid_code'));
        }

        $auth->login($user);
        $this->app->audit()->log((int) $user['id'], 'totp_setup', 'admin_user', (int) $user['id']);
        $this->app->session->set('login.new_recovery_codes', $codes);

        return $this->redirect('/admin/login/recovery-codes');
    }

    /** Shows the recovery codes once after the setup. */
    public function recoveryCodes(Request $request, array $params): Response
    {
        $this->admin();
        $codes = $this->app->session->get('login.new_recovery_codes');
        if (!is_array($codes) || $codes === []) {
            return $this->redirect('/admin');
        }

        return $this->render('auth/recovery_codes.html.twig', ['codes' => $codes]);
    }

    public function recoveryCodesDone(Request $request, array $params): Response
    {
        $this->admin();
        $this->app->session->remove('login.new_recovery_codes');
        $this->flash('success', 'auth.setup_done');

        return $this->redirect('/admin');
    }

    public function logout(Request $request, array $params): Response
    {
        $user = $this->app->auth()->user();
        if ($user !== null) {
            $this->app->audit()->log((int) $user['id'], 'logout', 'admin_user', (int) $user['id']);
        }
        $this->app->auth()->logout();
        $this->flash('success', 'auth.logged_out');

        return $this->redirect('/admin/login');
    }

    public function inviteForm(Request $request, array $params): Response
    {
        $invite = $this->app->invites()->findValid($params['token']);
        if ($invite === null) {
            return $this->render('auth/invite_invalid.html.twig', [], 404);
        }

        return $this->render('auth/invite.html.twig', ['invite' => $invite, 'token' => $params['token']]);
    }

    public function invite(Request $request, array $params): Response
    {
        $invites = $this->app->invites();
        $invite = $invites->findValid($params['token']);
        if ($invite === null) {
            return $this->render('auth/invite_invalid.html.twig', [], 404);
        }

        $password = $request->raw('password');
        if ($problem = Auth::passwordProblem($password, $request->raw('password_confirm'))) {
            return $this->render('auth/invite.html.twig', [
                'invite' => $invite,
                'token' => $params['token'],
                'error' => $this->t($problem),
            ], 422);
        }

        $id = (int) $invite['id'];
        $this->app->db()->transaction(function (Database $db) use ($id, $password, $invite, $invites): void {
            $this->app->adminUsers()->update($id, [
                'password_hash' => Auth::hashPassword($password),
                'totp_secret_enc' => null,
                'totp_last_step' => null,
            ]);
            $invites->markUsed((int) $invite['invite_id']);
        });
        $this->app->audit()->log($id, 'invite_accepted', 'admin_user', $id);

        $this->app->session->regenerate();
        $this->app->session->set('login.pending_id', $id);
        $this->app->session->set('login.pending_at', time());

        return $this->redirect('/admin/login/setup');
    }

    private function renderSetup(array $user, ?string $error = null): Response
    {
        $auth = $this->app->auth();
        $secret = $auth->setupSecret();
        $issuer = $this->app->branding()->club()['club_name'] . ' LapFund';
        $uri = $this->app->totp()->provisioningUri($secret, (string) $user['username'], $issuer);

        return $this->render('auth/setup_totp.html.twig', [
            'user' => $user,
            'qr' => $this->app->totp()->qrDataUri($uri),
            'secret_grouped' => trim(chunk_split($secret, 4, ' ')),
            'error' => $error,
        ], $error === null ? 200 : 422);
    }
}
