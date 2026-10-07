<?php

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Badge;
use App\Models\User;
use App\Services\BadgeService;

class BadgeController
{
    public function index(Request $request): Response
    {
        return json_response(['badges' => Badge::query()->orderBy('id')->get()]);
    }

    public function myBadges(Request $request): Response
    {
        $user = User::load($request->user, ['badges']);

        return json_response(['badges' => $user['badges']]);
    }

    public function farmerBadges(Request $request, string $userId): Response
    {
        $user = User::load(User::findOrFail($userId), ['badges']);

        return json_response(['badges' => $user['badges']]);
    }

    // ── Admin ────────────────────────────────────────────────────────────

    public function store(Request $request): Response
    {
        $v = $request->validate([
            'name'        => 'required|string|max:100',
            'slug'        => 'required|string|unique:badges,slug',
            'description' => 'required|string',
            'icon'        => 'nullable|string',
            'type'        => 'required|in:farmer,consumer,both',
        ]);

        return json_response(['message' => 'Badge created.', 'badge' => Badge::create($v)], 201);
    }

    public function award(Request $request): Response
    {
        $v = $request->validate([
            'user_id'  => 'required|exists:users,id',
            'badge_id' => 'required|exists:badges,id',
        ]);

        $user  = User::findOrFail($v['user_id']);
        $badge = Badge::findOrFail($v['badge_id']);

        if (!(new BadgeService())->awardBadge($user['id'], $badge['slug'])) {
            throw new HttpException(422, 'User already has this badge.');
        }

        return json_response(['message' => "Badge '{$badge['name']}' awarded to {$user['name']}."]);
    }

    public function revoke(Request $request): Response
    {
        $v = $request->validate([
            'user_id'  => 'required|exists:users,id',
            'badge_id' => 'required|exists:badges,id',
        ]);

        \App\Core\DB::delete('user_badges', 'user_id = ? AND badge_id = ?', [$v['user_id'], $v['badge_id']]);

        return json_response(['message' => 'Badge revoked.']);
    }
}
