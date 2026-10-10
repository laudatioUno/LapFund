<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Repository\AdminUserRepository;
use LapFund\Service\AdminInvites;
use LapFund\Validation;

/**
 * Admin accounts (AR-01 to AR-08). Club admins only.
 * New admins get an invitation link (72 h) to set their password and TOTP.
 * In stage 1 the link is shown for copying; sending it by mail follows in stage 3.
 */
final class UserController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $this->admin('users.manage');
        $users = array_map(
            static fn (array $u) => $u + ['status' => AdminUserRepository::status($u)],
            $this->app->adminUsers()->all(),
        );

        return $this->render('admin/users/index.html.twig', ['users' => $users]);
    }

    public function create(Request $request, array $params): Response
    {
        $this->admin('users.manage');

        return $this->render('admin/users/form.html.twig', [
            'mode' => 'create',
            'input' => ['username' => '', 'display_name' => '', 'email' => '', 'role' => AdminUserRepository::ROLE_EVENT],
            'errors' => [],
        ]);
    }

    public function store(Request $request, array $params): Response
    {
        $admin = $this->admin('users.manage');
        $input = $this->input($request);
        $input['username'] = strtolower($request->input('username'));
        $errors = $this->validate($input);
        if (!isset($errors['username']) && $this->app->adminUsers()->findByUsername($input['username']) !== null) {
            $errors['username'] = 'admin.users.username_taken';
        }
        if ($errors !== []) {
            return $this->render('admin/users/form.html.twig', ['mode' => 'create', 'input' => $input, 'errors' => $errors], 422);
        }

        $id = $this->app->adminUsers()->create([
            'username' => $input['username'],
            'display_name' => $input['display_name'],
            'email' => $input['email'],
            'role' => $input['role'],
            'password_hash' => null,
        ]);
        $this->app->audit()->log((int) $admin['id'], 'admin_created', 'admin_user', $id, null, $this->publicFields($input));
        $token = $this->app->invites()->create($id, (int) $admin['id']);

        return $this->showInviteLink($id, $token);
    }

    public function edit(Request $request, array $params): Response
    {
        $admin = $this->admin('users.manage');
        $user = $this->findUser($params);

        return $this->render('admin/users/form.html.twig', [
            'mode' => 'edit',
            'user' => $user + ['status' => AdminUserRepository::status($user)],
            'is_self' => (int) $user['id'] === (int) $admin['id'],
            'open_invite' => $this->app->invites()->openFor((int) $user['id']),
            'input' => $user,
            'errors' => [],
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        $admin = $this->admin('users.manage');
        $user = $this->findUser($params);
        $input = $this->input($request) + ['username' => $user['username']];
        $errors = $this->validate($input);

        $isSelf = (int) $user['id'] === (int) $admin['id'];
        if ($isSelf && $input['role'] !== $user['role']) {
            $errors['role'] = 'admin.users.cannot_change_own_role';
        }
        if ($this->wouldRemoveLastClubAdmin($user, $input['role'])) {
            $errors['role'] = 'admin.users.last_club_admin';
        }
        if ($errors !== []) {
            return $this->render('admin/users/form.html.twig', [
                'mode' => 'edit',
                'user' => $user + ['status' => AdminUserRepository::status($user)],
                'is_self' => $isSelf,
                'open_invite' => $this->app->invites()->openFor((int) $user['id']),
                'input' => $input,
                'errors' => $errors,
            ], 422);
        }

        $changes = ['display_name' => $input['display_name'], 'email' => $input['email'], 'role' => $input['role']];
        $this->app->adminUsers()->update((int) $user['id'], $changes);
        $this->app->audit()->log((int) $admin['id'], 'admin_updated', 'admin_user', (int) $user['id'], $this->publicFields($user), $changes);
        $this->flash('success', 'admin.users.saved');

        return $this->redirect('/admin/users');
    }

    /** New setup link: lets the person set a new password and TOTP (also used as access reset). */
    public function reinvite(Request $request, array $params): Response
    {
        $admin = $this->admin('users.manage');
        $user = $this->findUser($params);
        $this->guardNotSelf($admin, $user);

        $token = $this->app->invites()->create((int) $user['id'], (int) $admin['id']);
        $this->app->audit()->log((int) $admin['id'], 'admin_invite_created', 'admin_user', (int) $user['id']);

        return $this->showInviteLink((int) $user['id'], $token);
    }

    public function resetTotp(Request $request, array $params): Response
    {
        $admin = $this->admin('users.manage');
        $user = $this->findUser($params);
        $this->guardNotSelf($admin, $user);

        $this->app->adminUsers()->update((int) $user['id'], ['totp_secret_enc' => null, 'totp_last_step' => null]);
        $this->app->db()->run('DELETE FROM admin_recovery_codes WHERE admin_user_id = ?', [(int) $user['id']]);
        $this->app->audit()->log((int) $admin['id'], 'admin_totp_reset', 'admin_user', (int) $user['id']);
        $this->flash('success', 'admin.users.totp_reset_done', ['name' => $user['display_name']]);

        return $this->redirect('/admin/users/' . $user['id']);
    }

    public function unlock(Request $request, array $params): Response
    {
        $admin = $this->admin('users.manage');
        $user = $this->findUser($params);
        $this->app->adminUsers()->update((int) $user['id'], ['locked_until' => null, 'failed_attempts' => 0, 'first_failed_at' => null]);
        $this->app->audit()->log((int) $admin['id'], 'admin_unlocked', 'admin_user', (int) $user['id']);
        $this->flash('success', 'admin.users.unlocked', ['name' => $user['display_name']]);

        return $this->redirect('/admin/users/' . $user['id']);
    }

    public function toggleActive(Request $request, array $params): Response
    {
        $admin = $this->admin('users.manage');
        $user = $this->findUser($params);
        $this->guardNotSelf($admin, $user);

        $activate = (int) $user['active'] !== 1;
        if (!$activate && $this->wouldRemoveLastClubAdmin($user, 'event_admin')) {
            $this->flash('error', 'admin.users.last_club_admin');

            return $this->redirect('/admin/users/' . $user['id']);
        }
        $this->app->adminUsers()->update((int) $user['id'], ['active' => $activate ? 1 : 0]);
        $this->app->audit()->log((int) $admin['id'], $activate ? 'admin_activated' : 'admin_deactivated', 'admin_user', (int) $user['id']);
        $this->flash('success', $activate ? 'admin.users.activated' : 'admin.users.deactivated', ['name' => $user['display_name']]);

        return $this->redirect('/admin/users/' . $user['id']);
    }

    private function showInviteLink(int $userId, string $token): Response
    {
        $user = $this->app->adminUsers()->find($userId);

        return $this->render('admin/users/invite_link.html.twig', [
            'user' => $user,
            'link' => $this->app->request->absoluteUrl('/admin/invite/' . $token),
            'hours' => (int) (AdminInvites::VALID_SECONDS / 3600),
        ]);
    }

    /** @return array<string, string> */
    private function input(Request $request): array
    {
        $role = $request->input('role');

        return [
            'display_name' => $request->input('display_name'),
            'email' => $request->input('email'),
            'role' => in_array($role, [AdminUserRepository::ROLE_CLUB, AdminUserRepository::ROLE_EVENT], true) ? $role : '',
        ];
    }

    /** @param array<string, string> $input @return array<string, string> */
    private function validate(array $input): array
    {
        $errors = [];
        if ($e = Validation::username($input['username'])) {
            $errors['username'] = $e;
        }
        if ($e = Validation::required($input['display_name'])) {
            $errors['display_name'] = $e;
        }
        if ($e = Validation::email($input['email'])) {
            $errors['email'] = $e;
        }
        if ($input['role'] === '') {
            $errors['role'] = 'validation.required';
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    private function findUser(array $params): array
    {
        $user = $this->app->adminUsers()->find((int) $params['id']);
        if ($user === null) {
            throw new HttpException(404);
        }

        return $user;
    }

    private function guardNotSelf(array $admin, array $user): void
    {
        if ((int) $admin['id'] === (int) $user['id']) {
            throw new HttpException(403);
        }
    }

    private function wouldRemoveLastClubAdmin(array $user, string $newRole): bool
    {
        return $user['role'] === AdminUserRepository::ROLE_CLUB
            && $newRole !== AdminUserRepository::ROLE_CLUB
            && (int) $user['active'] === 1
            && $user['password_hash'] !== null
            && $this->app->adminUsers()->countActiveClubAdmins() <= 1;
    }

    /** @return array<string, mixed> */
    private function publicFields(array $user): array
    {
        return array_intersect_key($user, array_flip(['username', 'display_name', 'email', 'role']));
    }
}
