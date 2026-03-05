<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AdminSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'icon',
        'is_active',
        'order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    /**
     * Роли, которые имеют доступ к этому разделу
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'admin_section_role', 'admin_section_id', 'role_id');
    }

    /**
     * Проверяет, имеет ли роль доступ к разделу
     */
    public function hasRoleAccess(Role $role): bool
    {
        return $this->roles()->where('role_id', $role->id)->exists();
    }

    /**
     * Проверяет, имеет ли пользователь доступ к разделу
     */
    public function hasUserAccess(User $user): bool
    {
        // Администратор имеет доступ ко всем разделам
        if ($user->hasRole('admin')) {
            return true;
        }

        // Проверяем роли пользователя
        return $user->roles()->whereHas('adminSections', function ($query) {
            $query->where('admin_section_id', $this->id);
        })->exists();
    }

    /**
     * Получает активные разделы, отсортированные по порядку
     */
    public static function getActiveSections()
    {
        return static::where('is_active', true)
            ->orderBy('order')
            ->orderBy('title')
            ->get();
    }

    /**
     * Получает разделы с доступом для конкретной роли
     */
    public static function getSectionsForRole(Role $role)
    {
        if ($role->name === 'admin') {
            return static::where('is_active', true)->get();
        }

        return static::where('is_active', true)
            ->whereHas('roles', function ($query) use ($role) {
                $query->where('role_id', $role->id);
            })
            ->get();
    }
}
