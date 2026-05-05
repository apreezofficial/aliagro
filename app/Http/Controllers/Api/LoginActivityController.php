<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoginActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginActivityController extends Controller
{
    /**
     * Get login history for authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $activities = LoginActivity::where('user_id', $request->user()->id)
            ->latest('logged_in_at')
            ->paginate(20);

        return response()->json($activities);
    }

    /**
     * Admin: get login history for any user.
     */
    public function adminIndex(int $userId): JsonResponse
    {
        $activities = LoginActivity::where('user_id', $userId)
            ->latest('logged_in_at')
            ->paginate(20);

        return response()->json($activities);
    }
}
