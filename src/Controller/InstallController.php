<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Config;
use LapFund\Database\Database;
use LapFund\Database\Migrator;
use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Repository\AdminUserRepository;
use LapFund\Repository\AuditLog;
use LapFund\Repository\SettingsRepository;
use LapFund\Security\Auth;
use LapFund\Security\Crypto;
use LapFund\Mail\MailException;
use LapFund\Validation;

/**
 * Web installer (AR-10, NF-02): checks the server, creates the tables,
 * writes config/config.php and the first club admin, then starts the TOTP setup.
 * Locked as soon as config.php exists.
 *
 * Customer instances (licence section in config.php, written by the operator) are set
 * up on /install?key=<setup_key> instead: the operator enters the club and its first
 * admin, who gets an invitation link and sets the password himself. The operator never
 * has a login in the instance.
 */
final class InstallController extends Controller
{
    private const FIELDS = ['db_host', 'db_port', 'db_name', 'db_user', 'club_name', 'admin_name', 'admin_username', 'admin_email'];

    private const CUSTOMER_FIELDS = ['club_name', 'admin_name', 'admin_email', 'app_url'];

    public function show(Request $request, array $params): Response
    {
        if ($this->app->config->isInstalled()) {
            return $this->customerPage($request, null, []);
        }

        return $this->form([
            'db_host' => 'localhost',
            'db_port' => '3306',
        ], []);
    }

    public function install(Request $request, array $params): Response
    {
        if ($this->app->config->isInstalled()) {
            return $this->customerInstall($request);
        }
        $input = [];
        foreach (self::FIELDS as $field) {
            $input[$field] = $request->input($field);
        }
        $input['admin_username'] = strtolower($input['admin_username']);
        $password = $request->raw('password');

        $errors = [];
        if (!$this->requirementsMet()) {
            $errors['_form'] = 'install.requirements_failed';
        }
        foreach (['db_host', 'db_name', 'db_user', 'club_name', 'admin_name'] as $field) {
            if ($e = Validation::required($input[$field])) {
                $errors[$field] = $e;
            }
        }
        if (!preg_match('/^\d{1,5}$/', $input['db_port'])) {
            $errors['db_port'] = 'validation.port';
        }
        if ($e = Validation::username($input['admin_username'])) {
            $errors['admin_username'] = $e;
        }
        if ($e = Validation::email($input['admin_email'])) {
            $errors['admin_email'] = $e;
        }
        if ($e = Auth::passwordProblem($password, $request->raw('password_confirm'))) {
            $errors['password'] = $e;
        }
        if ($errors !== []) {
            return $this->form($input, $errors, 422);
        }

        $dbConfig = [
            'host' => $input['db_host'],
            'port' => (int) $input['db_port'],
            'name' => $input['db_name'],
            'user' => $input['db_user'],
            'pass' => $request->raw('db_pass'),
        ];
        try {
            $db = Database::connect($dbConfig);
        } catch (\PDOException $e) {
            $this->app->logError($e);

            return $this->form($input, ['_form' => 'install.db_failed'], 422, $this->dbHint($e));
        }

        try {
            if ((int) $db->value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admin_users'") > 0
                && (int) $db->value('SELECT COUNT(*) FROM admin_users') > 0) {
                return $this->form($input, ['_form' => 'install.db_not_empty'], 422);
            }
            (new Migrator($db, $this->app->root . '/migrations'))->migrate();
        } catch (\PDOException $e) {
            $this->app->logError($e);

            return $this->form($input, ['_form' => 'install.migration_failed'], 500, $e->getMessage());
        }

        $key = Crypto::generateKey();
        $configValues = [
            'db' => $dbConfig,
            'app' => [
                'key' => $key,
                'locale' => 'de',
                'timezone' => 'Europe/Zurich',
                'debug' => false,
                'installed_at' => gmdate('c'),
                'cron_secret' => bin2hex(random_bytes(16)),
            ],
        ];
        if (!$this->writeConfig(Config::render($configValues))) {
            return $this->form($input, ['_form' => 'install.config_not_writable'], 500);
        }

        $settings = new SettingsRepository($db, new Crypto($key));
        $settings->setMany([
            'club_name' => $input['club_name'],
            'contact_email' => $input['admin_email'],
            'primary_color' => '#0B5FA8',
        ]);
        $users = new AdminUserRepository($db);
        $id = $users->create([
            'username' => $input['admin_username'],
            'display_name' => $input['admin_name'],
            'email' => $input['admin_email'],
            'password_hash' => Auth::hashPassword($password),
            'role' => AdminUserRepository::ROLE_CLUB,
        ]);
        (new AuditLog($db))->log($id, 'install', 'system', null, null, ['version' => \LapFund\App::VERSION]);

        // Continue with the TOTP setup of the new admin.
        $this->app->reloadConfig();
        $this->app->useDatabase($db);
        $this->app->session->regenerate();
        $this->app->session->set('login.pending_id', $id);
        $this->app->session->set('login.pending_at', time());

        return $this->redirect('/admin/login/setup');
    }

    public function done(Request $request, array $params): Response
    {
        return $this->redirect('/admin/login');
    }

    /** New invitation for the club admin of a customer instance, if the first one expired. */
    public function resend(Request $request, array $params): Response
    {
        $this->checkSetupKey($request);
        $admin = $this->state() === 'invited' ? $this->pendingAdmin() : null;
        if ($admin === null) {
            return $this->customerPage($request, null, []);
        }
        $error = $this->sendInvite($admin);
        if ($error === null) {
            $this->app->audit()->log(null, 'license_invite_resent', 'admin_user', (int) $admin['id']);
        }

        return $this->customerPage($request, $error, [], $error === null ? 'install.license.resent' : null);
    }

    private function customerInstall(Request $request): Response
    {
        $this->checkSetupKey($request);
        if ($this->state() !== 'setup' || $this->app->license()->problems() !== []) {
            return $this->customerPage($request, null, []);
        }
        $input = [];
        foreach (self::CUSTOMER_FIELDS as $field) {
            $input[$field] = $request->input($field);
        }
        $input['admin_email'] = mb_strtolower($input['admin_email']);
        $input['app_url'] = rtrim($input['app_url'], '/');
        $input['credit'] = $request->input('credit') === '1';
        $input['mail'] = $request->input('mail') === '1';

        $errors = [];
        foreach (['club_name' => 100, 'admin_name' => 100] as $field => $max) {
            if ($e = Validation::required($input[$field], $max)) {
                $errors[$field] = $e;
            }
        }
        if ($e = Validation::email($input['admin_email'])) {
            $errors['admin_email'] = $e;
        }
        if (Validation::optionalUrl($input['app_url']) !== null || !str_starts_with($input['app_url'], 'http') || str_contains($input['app_url'], '?')) {
            $errors['app_url'] = 'validation.url';
        }
        if ($errors !== []) {
            return $this->customerPage($request, null, $errors, null, $input, 422);
        }

        try {
            $this->app->migrator()->migrate();
        } catch (\PDOException $e) {
            $this->app->logError($e);

            return $this->customerPage($request, null, ['_form' => 'install.migration_failed'], null, $input, 500);
        }

        $this->app->settings()->setMany([
            'club_name' => $input['club_name'],
            'contact_email' => $input['admin_email'],
            'primary_color' => '#0B5FA8',
            'app_url' => $input['app_url'],
            'mail_from_name' => $input['club_name'],
            'license_credit' => $input['credit'] ? ($input['mail'] ? 'run_mail' : 'run') : null,
            'license_mail' => $input['mail'] ? '1' : null,
        ]);
        $id = $this->app->adminUsers()->create([
            'username' => self::usernameFrom($input['admin_email']),
            'display_name' => $input['admin_name'],
            'email' => $input['admin_email'],
            'password_hash' => null,
            'role' => AdminUserRepository::ROLE_CLUB,
        ]);
        $this->app->audit()->log(null, 'install_customer', 'system', null, null, [
            'version' => \LapFund\App::VERSION,
            'license_credit' => $input['credit'],
            'license_mail' => $input['mail'],
        ]);

        $error = $this->sendInvite($this->app->adminUsers()->find($id) ?? []);

        return $this->customerPage($request, $error, []);
    }

    /** Sends the invitation link; returns an error text or null. The link is never shown here. */
    private function sendInvite(array $admin): ?string
    {
        $token = $this->app->invites()->create((int) $admin['id'], null);
        try {
            $this->app->licensing()->sendWelcome(
                (string) $admin['email'],
                (string) $admin['display_name'],
                (string) $admin['username'],
                $this->app->mailSettings()->url('/admin/invite/' . $token),
            );

            return null;
        } catch (MailException $e) {
            $this->app->logError($e);

            return $this->t($e->key) . ($e->detail !== '' ? ' (' . $e->detail . ')' : '');
        }
    }

    /**
     * Where the setup of a customer instance stands: 'setup' (no admin yet),
     * 'invited' (admin has not accepted the invitation yet) or 'done'.
     */
    private function state(): string
    {
        $db = $this->app->db();
        if ((int) $db->value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admin_users'") === 0
            || (int) $db->value('SELECT COUNT(*) FROM admin_users') === 0) {
            return 'setup';
        }
        $active = (int) $db->value('SELECT COUNT(*) FROM admin_users WHERE password_hash IS NOT NULL');

        return $active === 0 && $this->pendingAdmin() !== null ? 'invited' : 'done';
    }

    /** @return array<string, mixed>|null */
    private function pendingAdmin(): ?array
    {
        return $this->app->db()->one("SELECT * FROM admin_users WHERE role = 'club_admin' AND password_hash IS NULL AND active = 1 ORDER BY id LIMIT 1");
    }

    private function checkSetupKey(Request $request): void
    {
        if (!$this->app->license()->enabled() || !$this->app->license()->validSetupKey($request->input('key'))) {
            throw new HttpException(404);
        }
    }

    /** @param array<string, string> $errors */
    private function customerPage(Request $request, ?string $mailError, array $errors, ?string $notice = null, array $input = [], int $status = 200): Response
    {
        $this->checkSetupKey($request);
        try {
            $state = $this->state();
        } catch (\PDOException $e) {
            $this->app->logError($e);

            return $this->render('install/customer.html.twig', ['state' => 'db_failed', 'key' => $request->input('key'), 'detail' => $this->dbHint($e)], 500);
        }
        $admin = $state === 'invited' ? $this->pendingAdmin() : null;

        return $this->render('install/customer.html.twig', [
            'state' => $state,
            'key' => $request->input('key'),
            'problems' => $this->app->license()->problems(),
            'checks' => $this->requirements(),
            'requirements_ok' => $this->requirementsMet(),
            'input' => $input + [
                'club_name' => '',
                'admin_name' => '',
                'admin_email' => '',
                'app_url' => rtrim($request->absoluteUrl('/'), '/'),
                'credit' => true,
                'mail' => false,
            ],
            'errors' => $errors,
            'mail_error' => $mailError,
            'notice' => $notice,
            'admin_email' => $admin['email'] ?? '',
            'license' => $this->app->license(),
        ], $status);
    }

    /** Login name from the e-mail address: the part before @, e.g. sandra.muster */
    public static function usernameFrom(string $email): string
    {
        $local = strtolower(explode('@', $email)[0]);
        $name = trim(preg_replace('/[^a-z0-9._-]+/', '.', $local) ?? '', '._-');
        $name = substr($name, 0, 40);

        return Validation::username($name) === null ? $name : 'admin';
    }

    /** @return list<array{label: string, ok: bool, detail: string}> */
    private function requirements(): array
    {
        $root = $this->app->root;
        $ext = static fn (string $name) => extension_loaded($name);

        return [
            ['label' => 'PHP ≥ 8.3', 'ok' => PHP_VERSION_ID >= 80300, 'detail' => PHP_VERSION],
            ['label' => 'pdo_mysql', 'ok' => $ext('pdo_mysql'), 'detail' => ''],
            ['label' => 'sodium', 'ok' => $ext('sodium'), 'detail' => ''],
            ['label' => 'gd', 'ok' => $ext('gd'), 'detail' => ''],
            ['label' => 'mbstring', 'ok' => $ext('mbstring'), 'detail' => ''],
            ['label' => 'openssl', 'ok' => $ext('openssl'), 'detail' => ''],
            ['label' => 'dom', 'ok' => $ext('dom'), 'detail' => ''],
            ['label' => 'iconv', 'ok' => $ext('iconv'), 'detail' => ''],
            ['label' => 'Argon2id', 'ok' => defined('PASSWORD_ARGON2ID'), 'detail' => ''],
            ['label' => 'config/ ' . $this->t('install.writable'), 'ok' => is_writable($root . '/config'), 'detail' => ''],
            ['label' => 'storage/ ' . $this->t('install.writable'), 'ok' => is_writable($root . '/storage'), 'detail' => ''],
        ];
    }

    private function requirementsMet(): bool
    {
        foreach ($this->requirements() as $check) {
            if (!$check['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string> $input @param array<string, string> $errors */
    private function form(array $input, array $errors, int $status = 200, ?string $detail = null): Response
    {
        return $this->render('install/install.html.twig', [
            'checks' => $this->requirements(),
            'requirements_ok' => $this->requirementsMet(),
            'input' => $input + array_fill_keys(self::FIELDS, ''),
            'errors' => $errors,
            'detail' => $detail,
        ], $status);
    }

    private function writeConfig(string $content): bool
    {
        $path = $this->app->root . '/config/config.php';
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            return false;
        }
        $ok = fwrite($handle, $content) === strlen($content);
        fclose($handle);
        @chmod($path, 0640);

        return $ok;
    }

    private function dbHint(\PDOException $e): string
    {
        $code = (string) ($e->errorInfo[1] ?? $e->getCode());

        return match ($code) {
            '1045' => $this->t('install.db_hint_access'),
            '1049' => $this->t('install.db_hint_unknown_db'),
            '2002', '2005' => $this->t('install.db_hint_host'),
            default => $this->t('install.db_hint_generic', ['code' => $code]),
        };
    }
}
