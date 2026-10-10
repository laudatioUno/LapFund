<?php

declare(strict_types=1);

namespace LapFund\View;

use LapFund\App;
use LapFund\Http\Response;
use LapFund\Security\Csrf;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig setup. Autoescaping is on for all templates (XSS protection, NF-10).
 */
final class View
{
    private readonly Environment $twig;

    public function __construct(private readonly App $app)
    {
        $cacheDir = $app->root . '/storage/cache/twig';
        $useCache = !$app->debug() && (is_dir($cacheDir) || @mkdir($cacheDir, 0775, true)) && is_writable($cacheDir);

        $this->twig = new Environment(new FilesystemLoader($app->root . '/templates'), [
            'autoescape' => 'html',
            'cache' => $useCache ? $cacheDir : false,
            'strict_variables' => $app->debug(),
            'auto_reload' => true,
        ]);

        $t = $app->translator;
        $this->twig->addFunction(new TwigFunction('t', static fn (string $key, array $params = []) => $t->t($key, $params)));
        $this->twig->addFilter(new TwigFilter('t', static fn (string $key, array $params = []) => $t->t($key, $params)));
        $this->twig->addFunction(new TwigFunction('url', fn (string $path = '/') => $this->app->request->url($path)));
        $this->twig->addFunction(new TwigFunction('asset', fn (string $path) => $this->asset($path)));
        $this->twig->addFunction(new TwigFunction('csrf_field', fn () => new Markup(
            '<input type="hidden" name="' . Csrf::FIELD . '" value="' . htmlspecialchars($this->app->csrf()->token(), ENT_QUOTES) . '">',
            'UTF-8',
        )));
        $this->twig->addFilter(new TwigFilter('datetime', fn (?string $v) => $this->app->formatter->datetime($v)));
        $this->twig->addFilter(new TwigFilter('date_ch', fn (?string $v) => $this->app->formatter->date($v)));
        $this->twig->addFilter(new TwigFilter('money', fn (int $cents) => $this->app->formatter->money($cents)));
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->renderString($template, $data), $status);
    }

    /** @param array<string, mixed> $data */
    public function renderString(string $template, array $data = []): string
    {
        $user = null;
        if ($this->app->config->isInstalled() && isset($this->app->session)) {
            try {
                $user = $this->app->auth()->user();
            } catch (\Throwable) {
                $user = null;
            }
        }

        $globals = [
            'version' => App::VERSION,
            'brand' => $this->safeBranding(),
            'current_user' => $user,
            'is_club_admin' => $user !== null && $user['role'] === 'club_admin',
            'flashes' => isset($this->app->session) ? $this->app->session->takeFlashes() : [],
            'current_path' => isset($this->app->request) ? $this->app->request->path : '/',
            'lang' => $this->app->translator->locale,
        ];

        return $this->twig->render($template, $data + $globals);
    }

    private function asset(string $path): string
    {
        $file = $this->app->root . '/public/assets/' . ltrim($path, '/');
        $version = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : App::VERSION;

        return $this->app->request->url('/assets/' . ltrim($path, '/')) . '?v=' . $version;
    }

    /** @return array<string, string> */
    private function safeBranding(): array
    {
        try {
            return $this->app->branding()->club();
        } catch (\Throwable) {
            return (new \LapFund\Service\Branding(null))->club();
        }
    }
}
