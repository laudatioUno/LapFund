<?php

declare(strict_types=1);

namespace LapFund;

use LapFund\Database\Database;
use LapFund\Database\Migrator;
use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Http\Router;
use LapFund\Http\Session;
use LapFund\I18n\Formatter;
use LapFund\I18n\Translator;
use LapFund\Repository\AdminUserRepository;
use LapFund\Repository\AuditLog;
use LapFund\Repository\EventRepository;
use LapFund\Repository\ParticipantRepository;
use LapFund\Repository\TeamRepository;
use LapFund\Repository\SettingsRepository;
use LapFund\Security\Auth;
use LapFund\Security\Crypto;
use LapFund\Security\Csrf;
use LapFund\Security\RateLimiter;
use LapFund\Security\Totp;
use LapFund\Service\AdminInvites;
use LapFund\Service\Branding;
use LapFund\Service\ImageUpload;
use LapFund\Service\ParticipantService;
use LapFund\View\View;

/**
 * Application kernel: builds services on demand and dispatches the request.
 */
final class App
{
    public const VERSION = '0.2.0';

    public Config $config;
    public readonly Translator $translator;
    public readonly Formatter $formatter;
    public Request $request;
    public Session $session;

    private ?Database $db = null;
    private ?View $view = null;

    /** @var array<string, object> */
    private array $services = [];

    private function __construct(public readonly string $root)
    {
        $this->config = Config::load($root);
        date_default_timezone_set((string) $this->config->get('app.timezone', 'Europe/Zurich'));
        $this->translator = new Translator($root . '/lang', (string) $this->config->get('app.locale', 'de'));
        $this->formatter = new Formatter((string) $this->config->get('app.timezone', 'Europe/Zurich'));
    }

    public static function boot(string $root): self
    {
        return new self($root);
    }

    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        $this->session = new Session($request->isSecure(), $request->basePath === '' ? '/' : $request->basePath . '/');

        try {
            $response = $this->dispatch($request);
        } catch (Controller\RedirectException $e) {
            $response = Response::redirect($e->location);
        } catch (HttpException $e) {
            $response = $this->errorResponse($e->status);
        } catch (\Throwable $e) {
            $this->logError($e);
            $response = $this->errorResponse(500, $this->debug() ? $e::class . ': ' . $e->getMessage() : null);
        }

        return SecurityHeaders::apply($response, $request);
    }

    private function dispatch(Request $request): Response
    {
        $installing = str_starts_with($request->path, '/install');
        if (!$this->config->isInstalled() && !$installing && !str_starts_with($request->path, '/theme.css')) {
            return Response::redirect($request->url('/install'));
        }
        if ($this->config->isInstalled() && $installing && $request->path !== '/install/done') {
            throw new HttpException(404);
        }

        $router = new Router();
        Routes::register($router, $this);
        [$handler, $params] = $router->match($request->method, $request->path);

        if ($request->isPost()) {
            $this->csrf()->check($request);
        }

        return $handler($request, $params);
    }

    public function debug(): bool
    {
        return (bool) $this->config->get('app.debug', false);
    }

    public function db(): Database
    {
        return $this->db ??= Database::connect($this->config->db());
    }

    /** Used by the installer, which connects before config.php exists. */
    public function useDatabase(Database $db): void
    {
        $this->db = $db;
    }

    public function reloadConfig(): void
    {
        $this->config = Config::load($this->root);
        $this->services = [];
    }

    public function view(): View
    {
        return $this->view ??= new View($this);
    }

    public function crypto(): Crypto
    {
        return $this->service(Crypto::class, fn () => new Crypto((string) $this->config->get('app.key')));
    }

    public function settings(): SettingsRepository
    {
        return $this->service(SettingsRepository::class, fn () => new SettingsRepository($this->db(), $this->crypto()));
    }

    public function branding(): Branding
    {
        return $this->service(Branding::class, fn () => new Branding($this->config->isInstalled() ? $this->settings() : null));
    }

    public function adminUsers(): AdminUserRepository
    {
        return $this->service(AdminUserRepository::class, fn () => new AdminUserRepository($this->db()));
    }

    public function audit(): AuditLog
    {
        return $this->service(AuditLog::class, fn () => new AuditLog($this->db()));
    }

    public function events(): EventRepository
    {
        return $this->service(EventRepository::class, fn () => new EventRepository($this->db()));
    }

    public function teams(): TeamRepository
    {
        return $this->service(TeamRepository::class, fn () => new TeamRepository($this->db()));
    }

    public function participants(): ParticipantRepository
    {
        return $this->service(ParticipantRepository::class, fn () => new ParticipantRepository($this->db()));
    }

    public function participantService(): ParticipantService
    {
        return $this->service(ParticipantService::class, fn () => new ParticipantService($this->db(), $this->participants(), $this->teams()));
    }

    public function invites(): AdminInvites
    {
        return $this->service(AdminInvites::class, fn () => new AdminInvites($this->db()));
    }

    public function totp(): Totp
    {
        return $this->service(Totp::class, fn () => new Totp());
    }

    public function csrf(): Csrf
    {
        return $this->service(Csrf::class, fn () => new Csrf($this->session));
    }

    public function images(): ImageUpload
    {
        return $this->service(ImageUpload::class, fn () => new ImageUpload($this->root . '/storage/uploads'));
    }

    public function migrator(): Migrator
    {
        return $this->service(Migrator::class, fn () => new Migrator($this->db(), $this->root . '/migrations'));
    }

    public function auth(): Auth
    {
        return $this->service(Auth::class, fn () => new Auth(
            $this->db(),
            $this->session,
            $this->adminUsers(),
            $this->crypto(),
            $this->totp(),
            new RateLimiter($this->db()),
        ));
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param callable(): T $factory
     * @return T
     */
    private function service(string $id, callable $factory): object
    {
        return $this->services[$id] ??= $factory();
    }

    public function errorResponse(int $status, ?string $detail = null): Response
    {
        $key = in_array($status, [403, 404, 405, 419, 429, 503], true) ? (string) $status : '500';
        try {
            return $this->view()->render('error.html.twig', [
                'status' => $status,
                'title' => $this->translator->t('errors.' . $key . '.title'),
                'message' => $this->translator->t('errors.' . $key . '.text'),
                'detail' => $detail,
            ], $status);
        } catch (\Throwable $e) {
            $this->logError($e);

            return Response::text($this->translator->t('errors.500.title'), $status);
        }
    }

    public function logError(\Throwable $e): void
    {
        $dir = $this->root . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n",
            gmdate('c'),
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        );
        @file_put_contents($dir . '/app-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
