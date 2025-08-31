<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    /**
     * Пользователи с этой ролью
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id')
            ->withPivot('is_active', 'assigned_at')
            ->wherePivot('is_active', true);
    }

    /**
     * Scope для активных ролей
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
