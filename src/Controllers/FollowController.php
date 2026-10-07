<?php

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\ModelNotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Time;
use App\Models\User;

class FollowController
{
    private function farmer(string $id): array
    {
        return User::where('id', (int) $id)->where('role', 'farmer')->first() ?? throw new ModelNotFoundException();
    }

    /** Follow / unfollow a farmer. */
    public function toggle(Request $request, string $farmerId): Response
    {
        $farmer = $this->farmer($farmerId);
        $user   = $request->user;

        if ($user['id'] === $farmer['id']) {
            throw new HttpException(422, 'You cannot follow yourself.');
        }

        $deleted = DB::delete('farmer_follows', 'follower_id = ? AND farmer_id = ?', [$user['id'], $farmer['id']]);
        if ($deleted) {
            return json_response(['message' => 'Unfollowed.', 'following' => false]);
        }

        $ts = now();
        DB::statement(
            'INSERT IGNORE INTO farmer_follows (follower_id, farmer_id, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$user['id'], $farmer['id'], $ts, $ts]
        );

        return json_response(['message' => 'Following.', 'following' => true]);
    }

    /** Farmers the user follows. */
    public function following(Request $request): Response
    {
        $page = User::query()
            ->select(['users.*', 'farmer_follows.follower_id AS pivot_follower_id', 'farmer_follows.farmer_id AS pivot_farmer_id',
                'farmer_follows.created_at AS pivot_created_at', 'farmer_follows.updated_at AS pivot_updated_at'])
            ->join('farmer_follows', 'farmer_follows.farmer_id', 'users.id')
            ->where('farmer_follows.follower_id', $request->user['id'])
            ->with('farmer_profile:user_id,farm_name,state,rating,is_verified')
            ->orderBy('farmer_follows.id')
            ->paginate(20);

        return json_response($this->withPivot($page));
    }

    /** Followers of a farmer. */
    public function followers(Request $request, string $farmerId): Response
    {
        $farmer = $this->farmer($farmerId);

        $count = DB::value('SELECT COUNT(*) FROM farmer_follows WHERE farmer_id = ?', [$farmer['id']]);
        $page  = User::query()
            ->select(['users.id', 'users.name', 'users.avatar', 'farmer_follows.follower_id AS pivot_follower_id',
                'farmer_follows.farmer_id AS pivot_farmer_id', 'farmer_follows.created_at AS pivot_created_at',
                'farmer_follows.updated_at AS pivot_updated_at'])
            ->join('farmer_follows', 'farmer_follows.follower_id', 'users.id')
            ->where('farmer_follows.farmer_id', $farmer['id'])
            ->orderBy('farmer_follows.id')
            ->paginate(20);

        return json_response(['followers_count' => (int) $count, 'followers' => $this->withPivot($page)]);
    }

    /** Fold pivot_* columns into Eloquent-style `pivot` objects. */
    private function withPivot(array $page): array
    {
        $page['data'] = array_map(function (array $row) {
            $row['pivot'] = [
                'follower_id' => $row['pivot_follower_id'],
                'farmer_id'   => $row['pivot_farmer_id'],
                'created_at'  => Time::iso($row['pivot_created_at']),
                'updated_at'  => Time::iso($row['pivot_updated_at']),
            ];
            unset($row['pivot_follower_id'], $row['pivot_farmer_id'], $row['pivot_created_at'], $row['pivot_updated_at']);
            return $row;
        }, $page['data']);

        return $page;
    }
}
