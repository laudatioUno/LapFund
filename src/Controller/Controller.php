<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\App;
use LapFund\Http\HttpException;
use LapFund\Http\Response;
use LapFund\Security\Permissions;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    /** @param array<string, mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return $this->app->view()->render($template, $data, $status);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect($this->app->request->url($path));
    }

    protected function flash(string $type, string $key, array $params = []): void
    {
        $this->app->session->flash($type, $this->app->translator->t($key, $params));
    }

    protected function t(string $key, array $params = []): string
    {
        return $this->app->translator->t($key, $params);
    }

    /**
     * Returns the logged-in admin or throws a redirect to the login page.
     *
     * @return array<string, mixed>
     */
    protected function admin(?string $action = null): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new RedirectException($this->app->request->url('/admin/login'));
        }
        if ($action !== null && !Permissions::can($user, $action)) {
            throw new HttpException(403);
        }

        return $user;
    }
}
