<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'icon', 'type'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_badges')
            ->withPivot('awarded_at')
            ->withTimestamps();
    }
}
