<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Mail\MailException;
use LapFund\Mail\MailSettings;
use LapFund\Mail\SmtpTransport;
use LapFund\Validation;

/**
 * Mail settings, test mail and mail log for the whole installation (EM-09 to EM-12, NF-15),
 * the cron URL (NF-03) and the send step triggered by open progress pages.
 */
final class MailController extends Controller
{
    public function edit(Request $request, array $params): Response
    {
        $this->admin('mail.manage');
        $values = $this->app->mailSettings()->values();
        if ($values['app_url'] === '') {
            $values['app_url'] = rtrim($this->app->request->absoluteUrl('/'), '/');
        }
        if ($values['from_name'] === '') {
            $values['from_name'] = $this->app->branding()->club()['club_name'];
        }

        return $this->page($values + ['password' => ''], []);
    }

    public function update(Request $request, array $params): Response
    {
        $admin = $this->admin('mail.manage');
        $input = [
            'app_url' => rtrim(trim($request->input('app_url')), '/'),
            'from_email' => mb_strtolower(trim($request->input('from_email'))),
            'from_name' => trim($request->input('from_name')),
            'host' => trim($request->input('host')),
            'port' => trim($request->input('port')),
            'encryption' => $request->input('encryption'),
            'username' => trim($request->input('username')),
            'password' => $request->raw('password'),
            'per_minute' => trim($request->input('per_minute')),
            'per_hour' => trim($request->input('per_hour')),
            'has_password' => $this->app->mailSettings()->values()['has_password'],
        ];
        $errors = [];
        if ($input['app_url'] === '' || Validation::optionalUrl($input['app_url']) !== null || str_contains($input['app_url'], '?')) {
            $errors['app_url'] = 'validation.url';
        }
        if ($e = Validation::email($input['from_email'])) {
            $errors['from_email'] = $e;
        }
        if ($e = Validation::required($input['from_name'], 120)) {
            $errors['from_name'] = $e;
        }
        if ($input['host'] === '' || preg_match('/^[A-Za-z0-9.\-]{1,190}$/', $input['host']) !== 1) {
            $errors['host'] = 'mail.settings.host_invalid';
        }
        if ($input['port'] === '' || Validation::optionalInt($input['port'], 1, 65535) !== null) {
            $errors['port'] = 'validation.port';
        }
        if (!in_array($input['encryption'], MailSettings::ENCRYPTIONS, true)) {
            $errors['encryption'] = 'validation.required';
        }
        if (mb_strlen($input['username']) > 190) {
            $errors['username'] = 'validation.too_long';
        }
        if (strlen($input['password']) > 500) {
            $errors['password'] = 'validation.too_long';
        }
        foreach (['per_minute' => 1000, 'per_hour' => 100000] as $field => $max) {
            if ($input[$field] === '' || Validation::optionalInt($input[$field], 1, $max) !== null) {
                $errors[$field] = 'validation.number';
            }
        }
        if ($errors !== []) {
            $input['password'] = '';

            return $this->page($input, $errors, 422);
        }

        $before = $this->app->mailSettings()->values();
        $values = [
            'app_url' => $input['app_url'],
            'from_email' => $input['from_email'],
            'from_name' => $input['from_name'],
            'host' => $input['host'],
            'port' => (int) $input['port'],
            'encryption' => $input['encryption'],
            'username' => $input['username'],
            'per_minute' => (int) $input['per_minute'],
            'per_hour' => (int) $input['per_hour'],
        ];
        // Password: empty field keeps the stored one (EM-11); the checkbox removes it.
        $password = $request->input('remove_password') === '1' ? '' : ($input['password'] !== '' ? $input['password'] : null);
        $this->app->mailSettings()->save($values, $password);

        $after = $values + ['password_changed' => $password !== null];
        unset($before['has_password']);
        $this->app->audit()->log((int) $admin['id'], 'mail_settings_updated', 'settings', null, $before, $after);
        $this->flash('success', 'mail.settings.saved');

        return $this->redirect('/admin/mail');
    }

    /** Test mail, sent directly and not through the queue, so errors show at once (EM-10). */
    public function test(Request $request, array $params): Response
    {
        $admin = $this->admin('mail.manage');
        $to = mb_strtolower(trim($request->input('test_to')));
        if (Validation::email($to) !== null) {
            $this->flash('error', 'mail.test.invalid_address');

            return $this->redirect('/admin/mail#test');
        }
        if (!$this->app->rateLimiter()->hit('test_mail', (string) $admin['id'], 10, 600)) {
            throw new HttpException(429);
        }
        if (!$this->app->mailSettings()->isConfigured()) {
            $this->flash('error', 'mail.error.not_configured');

            return $this->redirect('/admin/mail#test');
        }
        $brand = $this->app->branding()->club();
        $mail = $this->app->mailComposer()->build('test', $to, '', $this->t('mail.test.subject', ['club' => $brand['club_name']]), $brand, [
            'admin_name' => $admin['display_name'],
        ]);
        $transport = new SmtpTransport($this->app->mailSettings(), 15);
        try {
            $transport->send([
                'to_email' => $to,
                'to_name' => '',
                'from_name' => $mail['from_name'],
                'subject' => $mail['subject'],
                'body_html' => $mail['body_html'],
                'body_text' => $mail['body_text'],
                'inline_images' => array_map(fn (array $i) => ['cid' => $i['cid'], 'path' => $this->app->root . '/storage/uploads/' . $i['file']], $mail['inline_images']),
            ]);
            $id = $this->app->mailQueue()->enqueue($mail + ['created_by' => (int) $admin['id']]);
            $this->app->mailQueue()->claim($id);
            $this->app->mailQueue()->markSent($id);
            $this->app->audit()->log((int) $admin['id'], 'test_mail_sent', 'settings', null, null, ['to' => $to]);
            $this->flash('success', 'mail.test.sent', ['to' => $to]);
        } catch (MailException $e) {
            $this->app->session->set('mail_test_error', ['key' => $e->key, 'detail' => $e->detail]);
            $this->flash('error', $e->key);
        } finally {
            $transport->close();
        }

        return $this->redirect('/admin/mail#test');
    }

    /** All mails of the installation, incl. «Link verloren» and test mails (NF-15). */
    public function log(Request $request, array $params): Response
    {
        $this->admin('mail.manage');
        $status = $request->input('status');
        $status = in_array($status, ['queued', 'sent', 'failed', 'cancelled'], true) ? $status : null;

        return $this->render('admin/mail/log.html.twig', [
            'rows' => $this->app->mailQueue()->log(null, 300, $status),
            'counts' => $this->app->mailQueue()->counts(),
            'status_filter' => $status ?? '',
            'batches' => $this->app->mailQueue()->batches(null, 5),
        ]);
    }

    public function retry(Request $request, array $params): Response
    {
        $admin = $this->admin('mail.manage');
        $n = $this->app->mailQueue()->retryFailed(null);
        $this->app->audit()->log((int) $admin['id'], 'mails_retried', 'mail', null, null, ['count' => $n]);
        $this->flash('success', 'mail.log.retried', ['count' => $n]);

        return $this->redirect('/admin/mail/log');
    }

    /**
     * One send step, triggered every few seconds by an open progress page (fallback
     * without cron). Returns the progress as JSON.
     */
    public function tick(Request $request, array $params): Response
    {
        $user = $this->admin('dashboard.view');
        $result = $this->app->mailWorker()->run(20, 15);
        $eventId = ctype_digit($request->input('event')) ? (int) $request->input('event') : null;
        if ($eventId !== null && $user['role'] !== 'club_admin' && !$this->app->events()->isAssigned($eventId, (int) $user['id'])) {
            $eventId = -1;
        }
        $counts = $this->app->mailQueue()->counts($eventId);

        return new Response((string) json_encode(['counts' => $counts, 'run' => $result]), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    /** Cron URL for external cron services (NF-03): /cron/{key} */
    public function cron(Request $request, array $params): Response
    {
        if (!hash_equals($this->app->mailSettings()->cronKey(), (string) $params['key'])) {
            throw new HttpException(404);
        }
        if ($this->app->migrator()->pending() !== []) {
            return Response::text("pending database updates\n", 503);
        }
        $this->app->retention()->runDaily();
        $r = $this->app->mailWorker()->run(200, 50);

        return Response::text($r['locked'] ? "busy\n" : sprintf("sent %d, failed %d, cancelled %d\n", $r['sent'], $r['failed'], $r['cancelled']));
    }

    /** @param array<string, mixed> $input @param array<string, string> $errors */
    private function page(array $input, array $errors, int $status = 200): Response
    {
        $testError = $this->app->session->get('mail_test_error');
        $this->app->session->remove('mail_test_error');

        return $this->render('admin/mail/settings.html.twig', [
            'input' => $input,
            'errors' => $errors,
            'encryptions' => MailSettings::ENCRYPTIONS,
            'configured' => $this->app->mailSettings()->isConfigured(),
            'counts' => $this->app->mailQueue()->counts(),
            'cron_url' => $this->app->mailSettings()->url('/cron/' . $this->app->mailSettings()->cronKey()),
            'worker_path' => $this->app->root . '/bin/worker.php',
            'test_error' => is_array($testError) ? $testError : null,
            'test_to' => (string) ($this->app->auth()->user()['email'] ?? ''),
        ], $status);
    }
}
