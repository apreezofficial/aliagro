<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

class HealthController
{
    public function index(Request $request): Response
    {
        return json_response(['name' => config('app.name'), 'status' => 'ok']);
    }
}
