<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BadgeController extends Controller
{
    /**
     * Get all available badges.
     */
    public function index(): JsonResponse
    {
        return response()->json(['badges' => Badge::all()]);
    }

    /**
     * Get badges for the authenticated user.
     */
    public function myBadges(Request $request): JsonResponse
    {
        $badges = $request->user()
            ->badges()
            ->withPivot('awarded_at')
            ->get();

        return response()->json(['badges' => $badges]);
    }

    /**
     * Get badges for a public farmer profile.
     */
    public function farmerBadges(int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);

        $badges = $user->badges()->withPivot('awarded_at')->get();

        return response()->json(['badges' => $badges]);
    }

    // ── Admin ─────────────────────────────────────────────────────────────

    /**
     * Create a badge (admin).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'slug'        => 'required|string|unique:badges,slug',
            'description' => 'required|string',
            'icon'        => 'nullable|string',
            'type'        => 'required|in:farmer,consumer,both',
        ]);

        $badge = Badge::create($validated);

        return response()->json(['message' => 'Badge created.', 'badge' => $badge], 201);
    }

    /**
     * Manually award a badge to a user (admin).
     */
    public function award(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'  => 'required|exists:users,id',
            'badge_id' => 'required|exists:badges,id',
        ]);

        $user  = User::findOrFail($request->user_id);
        $badge = Badge::findOrFail($request->badge_id);

        if ($user->badges()->where('badge_id', $badge->id)->exists()) {
            return response()->json(['message' => 'User already has this badge.'], 422);
        }

        $user->badges()->attach($badge->id, ['awarded_at' => now()]);

        return response()->json(['message' => "Badge '{$badge->name}' awarded to {$user->name}."]);
    }

    /**
     * Revoke a badge from a user (admin).
     */
    public function revoke(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'  => 'required|exists:users,id',
            'badge_id' => 'required|exists:badges,id',
        ]);

        User::findOrFail($request->user_id)
            ->badges()
            ->detach($request->badge_id);

        return response()->json(['message' => 'Badge revoked.']);
    }
}
