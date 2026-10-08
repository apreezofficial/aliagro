<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\Installer;

/**
 * GET/POST /setup — one-time web installer. Disabled for good once
 * storage/installed.lock exists (or an existing working install is detected).
 */
class SetupController
{
    public function show(Request $request): Response
    {
        if (Installer::isInstalled()) {
            return $this->locked();
        }

        $origin = preg_replace('#/setup$#', '', $request->url());

        return $this->form([
            'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_database' => 'aliagro_db', 'db_username' => 'root',
            'create_database' => '1', 'app_url' => $origin, 'frontend_url' => '',
        ], []);
    }

    public function install(Request $request): Response
    {
        if (Installer::isInstalled()) {
            return $this->locked();
        }

        $old = $request->all();
        $errors = [];

        try {
            $data = Validator::validate($old, [
                'db_host'        => 'required|string|max:255',
                'db_port'        => 'required|integer|between:1,65535',
                'db_database'    => 'required|string|max:64',
                'db_username'    => 'required|string|max:255',
                'db_password'    => 'nullable|string',
                'app_url'        => 'required|string|max:255',
                'frontend_url'   => 'nullable|string|max:255',
                'admin_name'     => 'required|string|max:255',
                'admin_email'    => 'required|email',
                'admin_password' => 'required|confirmed|strong_password',
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors;
            $data = [];
        }

        if (!$errors && !preg_match('/^[A-Za-z0-9_$]+$/', $data['db_database'])) {
            $errors['db_database'][] = 'Use letters, numbers and underscores only.';
        }
        if (!$errors && !preg_match('#^https?://#i', $data['app_url'])) {
            $errors['app_url'][] = 'Must start with http:// or https://';
        }
        if (Installer::requiresKey() && !hash_equals((string) env('SETUP_KEY'), (string) ($old['setup_key'] ?? ''))) {
            $errors['setup_key'][] = 'Wrong setup key.';
        }

        if ($errors) {
            return $this->form($old, $errors, null, 422);
        }

        $data['db_password']     = $data['db_password'] ?? '';
        $data['app_url']         = rtrim($data['app_url'], '/');
        $data['frontend_url']    = rtrim($data['frontend_url'] ?? '', '/') ?: $data['app_url'];
        $data['create_database'] = !empty($old['create_database']);
        $data['demo_data']       = !empty($old['demo_data']);

        try {
            $log = (new Installer())->install($data);
        } catch (\RuntimeException $e) {
            return $this->form($old, [], $e->getMessage(), 500);
        }

        return $this->done($log, $data);
    }

    // ── views ────────────────────────────────────────────────────────────

    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    private function layout(string $title, string $body, int $status = 200): Response
    {
        $css = 'body{margin:0;background:#f3f4f6;font:15px/1.5 system-ui,Segoe UI,Arial,sans-serif;color:#1f2937}'
            . '.wrap{max-width:640px;margin:32px auto;padding:0 16px}h1{margin:0 0 4px;color:#15803d;font-size:24px}'
            . '.sub{margin:0 0 20px;color:#6b7280}.card{background:#fff;border-radius:10px;padding:24px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}'
            . 'h2{font-size:16px;margin:0 0 14px}label{display:block;font-weight:600;font-size:13px;margin:12px 0 4px}'
            . 'input[type=text],input[type=password],input[type=email],input[type=number]{width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:15px}'
            . '.row{display:flex;gap:12px}.row>div{flex:1}.chk{font-weight:400;display:flex;gap:8px;align-items:flex-start;margin:10px 0}.chk input{margin-top:4px}'
            . '.hint{color:#6b7280;font-size:12px;margin-top:3px}.err{color:#b91c1c;font-size:13px;margin-top:3px}'
            . '.alert{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:6px;padding:10px 12px;margin-bottom:16px}'
            . '.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:6px;padding:10px 12px}'
            . 'button{background:#16a34a;color:#fff;border:0;border-radius:6px;padding:11px 20px;font-size:15px;font-weight:600;cursor:pointer;width:100%}'
            . 'button:hover{background:#15803d}code{background:#f3f4f6;padding:1px 5px;border-radius:4px}ul{padding-left:20px;margin:8px 0}';

        return Response::html('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . self::e($title) . '</title><style>' . $css . '</style></head><body><div class="wrap">'
            . '<h1>AliAgro setup</h1><p class="sub">' . self::e($title) . '</p>' . $body . '</div></body></html>', $status);
    }

    private function field(string $name, string $label, array $old, array $errors, string $type = 'text', string $hint = '', bool $keep = true, string $extra = ''): string
    {
        $value = $keep && $type !== 'password' ? self::e($old[$name] ?? '') : '';
        $err   = isset($errors[$name]) ? '<div class="err">' . self::e(implode(' ', $errors[$name])) . '</div>' : '';
        return '<label for="' . $name . '">' . self::e($label) . '</label><input id="' . $name . '" name="' . $name . '" type="' . $type . '" value="' . $value . '" ' . $extra . '>'
            . ($hint ? '<div class="hint">' . $hint . '</div>' : '') . $err;
    }

    private function form(array $old, array $errors, ?string $failure = null, int $status = 200): Response
    {
        $f = fn(string $n, string $l, string $t = 'text', string $h = '', string $x = '') => $this->field($n, $l, $old, $errors, $t, $h, true, $x);
        $chk = fn(string $n, string $text, bool $on) => '<label class="chk"><input type="checkbox" name="' . $n . '" value="1"' . ($on ? ' checked' : '') . '><span>' . $text . '</span></label>';

        $body = ($failure ? '<div class="alert"><strong>Setup failed.</strong> ' . self::e($failure) . '</div>' : '')
            . ($errors ? '<div class="alert">Please fix the highlighted fields.</div>' : '')
            . '<form method="post" action="">'
            . '<div class="card"><h2>1. MySQL connection</h2>'
            . '<div class="row"><div>' . $f('db_host', 'Host') . '</div><div style="max-width:120px">' . $f('db_port', 'Port', 'number') . '</div></div>'
            . $f('db_database', 'Database name')
            . $f('db_username', 'Username')
            . $this->field('db_password', 'Password', $old, $errors, 'password', 'Leave empty if the user has no password.')
            . $chk('create_database', 'Create the database if it does not exist <span class="hint">(untick on shared hosting where you created it in cPanel)</span>', !empty($old['create_database']))
            . '</div>'
            . '<div class="card"><h2>2. Site</h2>'
            . $f('app_url', 'API URL', 'text', 'Where this API is reachable, e.g. https://api.example.com')
            . $f('frontend_url', 'Frontend URL (optional)', 'text', 'Used in emails and referral links. Defaults to the API URL.')
            . '</div>'
            . '<div class="card"><h2>3. Administrator</h2>'
            . $f('admin_name', 'Full name')
            . $f('admin_email', 'Email', 'email')
            . $this->field('admin_password', 'Password', $old, $errors, 'password', 'At least 8 characters with upper and lower case letters and a number.')
            . $this->field('admin_password_confirmation', 'Confirm password', $old, $errors, 'password')
            . $chk('demo_data', 'Also create demo farmer &amp; consumer accounts <span class="hint">(their passwords are public &ndash; do not use on a live site)</span>', !empty($old['demo_data']))
            . '</div>'
            . (Installer::requiresKey() ? '<div class="card"><h2>Setup key</h2>' . $f('setup_key', 'SETUP_KEY from your server environment', 'password') . '</div>' : '')
            . '<button type="submit">Install</button></form>'
            . '<p class="hint" style="margin-top:14px">This will create the tables, seed categories, badges and a sample coupon, create your admin account, save <code>.env</code> and then lock this page.</p>';

        return $this->layout('First-time installation', $body, $status);
    }

    private function done(array $log, array $data): Response
    {
        $items = implode('', array_map(fn($l) => '<li>' . self::e($l) . '</li>', $log));
        $body = '<div class="card"><div class="ok"><strong>Installation complete.</strong> This page is now locked.</div>'
            . '<ul>' . $items . '</ul>'
            . '<h2 style="margin-top:18px">Next steps</h2><ul>'
            . '<li>Sign in via <code>POST ' . self::e($data['app_url']) . '/api/auth/login</code> with <code>' . self::e($data['admin_email']) . '</code>.</li>'
            . '<li>Open <code>.env</code> to add mail (<code>MAIL_*</code>), Paystack/Flutterwave and Google keys.</li>'
            . '<li>API health check: <a href="' . self::e($data['app_url']) . '/up">/up</a></li></ul></div>';

        return $this->layout('All set', $body);
    }

    private function locked(): Response
    {
        return $this->layout('Already installed', '<div class="card">Setup has already been completed, so this page is disabled. '
            . 'To run it again, delete <code>storage/installed.lock</code> (and take a backup first).</div>', 403);
    }
}
