<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

class HealthController
{
    public function index(Request $request): Response
    {
        // Fresh deploy: send browsers straight to the installer.
        if ($request->path === '/' && str_contains((string) $request->header('accept', ''), 'text/html') && !\App\Services\Installer::isInstalled()) {
            return Response::redirect(rtrim($request->url(), '/') . '/setup');
        }

        return json_response(['name' => config('app.name'), 'status' => 'ok']);
    }
}
