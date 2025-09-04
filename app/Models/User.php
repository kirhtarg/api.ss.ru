<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'avatar_url',
        'google_id',
        'vk_id',
        'yandex_id',
        'is_active',
        'last_login_at',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    protected $appends = ['avatar_url'];

    /**
     * Роли пользователя
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
            ->withPivot('is_active', 'assigned_at')
            ->wherePivot('is_active', true);
    }

    /**
     * Проверить, имеет ли пользователь определенную роль
     */
    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    /**
     * Проверить, имеет ли пользователь любую из ролей
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->roles()->whereIn('name', $roles)->exists();
    }

    /**
     * Проверить, имеет ли пользователь все роли
     */
    public function hasAllRoles(array $roles): bool
    {
        return $this->roles()->whereIn('name', $roles)->count() === count($roles);
    }

    /**
     * Проверить, является ли пользователь администратором
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Проверить, является ли пользователь обычным пользователем
     */
    public function isRegularUser(): bool
    {
        return $this->hasRole('user');
    }

    /**
     * Проверить, имеет ли пользователь определенное разрешение
     */
    public function hasPermission(string $permission): bool
    {
        foreach ($this->roles as $role) {
            if ($role->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Проверить, имеет ли пользователь любое из разрешений
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($this->roles as $role) {
            if ($role->hasAnyPermission($permissions)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Проверить, имеет ли пользователь все разрешения
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($this->roles as $role) {
            if (!$role->hasAllPermissions($permissions)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Получить URL аватара
     */
    public function getAvatarUrlAttribute(): ?string
    {
        // Если есть URL аватара (от OAuth провайдеров), используем его
        if ($this->attributes['avatar_url']) {
            return $this->attributes['avatar_url'];
        }
        
        // Если есть локальный файл аватара, используем его
        if ($this->avatar) {
            return asset('storage/' . $this->avatar);
        }
        
        return null;
    }

    /**
     * Получить только активных пользователей
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Обновить время последнего входа
     */
    public function updateLastLogin(): void
    {
        $this->update(['last_login_at' => now()]);
    }
}
