<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Config;
use LapFund\Database\Database;
use LapFund\Database\Migrator;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Repository\AdminUserRepository;
use LapFund\Repository\AuditLog;
use LapFund\Repository\SettingsRepository;
use LapFund\Security\Auth;
use LapFund\Security\Crypto;
use LapFund\Validation;

/**
 * Web installer (AR-10, NF-02): checks the server, creates the tables,
 * writes config/config.php and the first club admin, then starts the TOTP setup.
 * Locked as soon as config.php exists.
 */
final class InstallController extends Controller
{
    private const FIELDS = ['db_host', 'db_port', 'db_name', 'db_user', 'club_name', 'admin_name', 'admin_username', 'admin_email'];

    public function show(Request $request, array $params): Response
    {
        return $this->form([
            'db_host' => 'localhost',
            'db_port' => '3306',
        ], []);
    }

    public function install(Request $request, array $params): Response
    {
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
