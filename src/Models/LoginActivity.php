<?php

namespace App\Models;

use App\Core\Model;

class LoginActivity extends Model
{
    public static string $table = 'login_activities';
    public static array $casts = ['logged_in_at' => 'datetime'];
    public static array $fillable = [
        'user_id', 'ip_address', 'user_agent', 'device', 'browser', 'platform', 'location', 'status', 'logged_in_at',
    ];
}
