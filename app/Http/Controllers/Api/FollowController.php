<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    /**
     * Follow or unfollow a farmer (toggle).
     */
    public function toggle(Request $request, int $farmerId): JsonResponse
    {
        $farmer = User::where('id', $farmerId)->where('role', 'farmer')->firstOrFail();
        $user   = $request->user();

        if ($user->id === $farmer->id) {
            return response()->json(['message' => 'You cannot follow yourself.'], 422);
        }

        if ($user->following()->where('farmer_id', $farmer->id)->exists()) {
            $user->following()->detach($farmer->id);
            return response()->json(['message' => 'Unfollowed.', 'following' => false]);
        }

        $user->following()->attach($farmer->id);
        return response()->json(['message' => 'Following.', 'following' => true]);
    }

    /**
     * List farmers the user is following.
     */
    public function following(Request $request): JsonResponse
    {
        $farmers = $request->user()
            ->following()
            ->with('farmerProfile:user_id,farm_name,state,rating,is_verified')
            ->paginate(20);

        return response()->json($farmers);
    }

    /**
     * List followers of a farmer.
     */
    public function followers(int $farmerId): JsonResponse
    {
        $farmer = User::where('id', $farmerId)->where('role', 'farmer')->firstOrFail();

        return response()->json([
            'followers_count' => $farmer->followers()->count(),
            'followers'       => $farmer->followers()
                ->select('id', 'name', 'avatar')
                ->paginate(20),
        ]);
    }
}
