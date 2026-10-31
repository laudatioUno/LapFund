<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\Request;
use LapFund\Http\Response;
use LapFund\Service\Branding;
use LapFund\Service\Retention;
use LapFund\Validation;

/**
 * Club data and look (PE-01, PE-04, PE-05, PE-07). Club admins only.
 * Mail settings: MailController.
 */
final class SettingsController extends Controller
{
    private const TEXT_FIELDS = ['club_name', 'club_website', 'contact_email', 'primary_color', 'imprint_url', 'privacy_url'];

    public function edit(Request $request, array $params): Response
    {
        $this->admin('settings.manage');
        $settings = $this->app->settings();
        $input = [];
        foreach (self::TEXT_FIELDS as $field) {
            $input[$field] = $settings->get($field);
        }
        $input['primary_color'] = Branding::validColor($input['primary_color']) ?? Branding::DEFAULT_COLOR;
        $input['retention_months'] = (string) $this->app->retention()->months();
        $input['inactive_years'] = (string) $this->app->retention()->inactiveYears();

        return $this->form($input, []);
    }

    public function update(Request $request, array $params): Response
    {
        $admin = $this->admin('settings.manage');
        $input = [];
        foreach (self::TEXT_FIELDS as $field) {
            $input[$field] = $request->input($field);
        }

        $errors = [];
        if ($e = Validation::required($input['club_name'], 120)) {
            $errors['club_name'] = $e;
        }
        if ($e = Validation::email($input['contact_email'])) {
            $errors['contact_email'] = $e;
        }
        foreach (['club_website', 'imprint_url', 'privacy_url'] as $field) {
            if ($e = Validation::optionalUrl($input[$field])) {
                $errors[$field] = $e;
            }
        }
        $defaults = ['retention_months' => Retention::DEFAULT_MONTHS, 'inactive_years' => Retention::DEFAULT_INACTIVE_YEARS];
        foreach (['retention_months' => [1, 60], 'inactive_years' => [1, 10]] as $field => [$min, $max]) {
            $input[$field] = trim($request->input($field));
            if ($input[$field] === '') {
                $input[$field] = (string) $defaults[$field];
            }
            if (!ctype_digit($input[$field]) || (int) $input[$field] < $min || (int) $input[$field] > $max) {
                $errors[$field] = 'admin.settings.' . $field . '_invalid';
            } else {
                $input[$field] = (string) (int) $input[$field];
            }
        }
        $color = Branding::validColor($input['primary_color']);
        if ($color === null) {
            $errors['primary_color'] = 'validation.color';
        } else {
            $input['primary_color'] = $color;
        }

        $logo = $request->file('logo');
        $newLogo = null;
        if ($errors === [] && $logo !== null) {
            try {
                $newLogo = $this->app->images()->store($logo, 600);
            } catch (\InvalidArgumentException $e) {
                $errors['logo'] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            return $this->form($input, $errors, 422);
        }

        $settings = $this->app->settings();
        $all = $settings->all();
        $before = [];
        $after = [];
        foreach ($input as $key => $value) {
            if ((string) ($all[$key] ?? '') !== (string) $value) {
                $before[$key] = $all[$key] ?? '';
                $after[$key] = $value;
            }
        }
        if ($after === [] && $newLogo === null) {
            $this->flash('info', 'admin.settings.no_changes');

            return $this->redirect('/admin/settings');
        }
        $settings->setMany($input);
        if ($newLogo !== null) {
            $old = $settings->get('logo_file');
            $settings->set('logo_file', $newLogo);
            if ($old !== '') {
                $this->app->images()->delete($old);
            }
            $after['logo'] = $old !== '' ? 'replaced' : 'added';
        }
        // Only what really changed goes into the change log
        $this->app->audit()->log((int) $admin['id'], 'settings_updated', 'settings', null, $before, $after);

        if (Branding::textColorOn($input['primary_color']) !== '#FFFFFF') {
            $this->flash('info', 'admin.settings.color_light_hint');
        }
        $this->flash('success', 'admin.settings.saved');

        return $this->redirect('/admin/settings');
    }

    public function deleteLogo(Request $request, array $params): Response
    {
        $admin = $this->admin('settings.manage');
        $settings = $this->app->settings();
        $old = $settings->get('logo_file');
        if ($old !== '') {
            $settings->set('logo_file', '');
            $this->app->images()->delete($old);
            $this->app->audit()->log((int) $admin['id'], 'logo_deleted', 'settings', null);
        }
        $this->flash('success', 'admin.settings.logo_deleted');

        return $this->redirect('/admin/settings');
    }

    /** @param array<string, string> $input @param array<string, string> $errors */
    private function form(array $input, array $errors, int $status = 200): Response
    {
        return $this->render('admin/settings.html.twig', [
            'input' => $input,
            'errors' => $errors,
            'logo' => $this->app->settings()->get('logo_file'),
        ], $status);
    }
}
