<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\LoginActivity;

class LoginActivityController
{
    public function index(Request $request): Response
    {
        return json_response(
            LoginActivity::where('user_id', $request->user['id'])->latest('logged_in_at')->paginate(20)
        );
    }

    public function adminIndex(Request $request, string $userId): Response
    {
        return json_response(
            LoginActivity::where('user_id', (int) $userId)->latest('logged_in_at')->paginate(20)
        );
    }
}
