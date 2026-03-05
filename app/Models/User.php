<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'avatar_url',
        'birthday',
        'phone',
        'additional_info',
        'google_id',
        'vk_id',
        'yandex_id',
        'last_login_at',
        'email_verified_at',
        'phone_verified_at',
        'is_active',
        'tech_acc',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'password' => 'hashed',
        'last_login_at' => 'datetime',
        'birthday' => 'date',
        'additional_info' => 'array',
        'is_active' => 'boolean',
        'tech_acc' => 'boolean',
    ];

    /**
     * Роли пользователя (только активные)
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
            ->withPivot('is_active', 'assigned_at')
            ->wherePivot('is_active', '!=', 0); // Исключаем только явно неактивные (0 или false)
    }

    /**
     * Все роли пользователя (включая неактивные) - для отладки
     */
    public function allRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')
            ->withPivot('is_active', 'assigned_at');
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
            if (! $role->hasAllPermissions($permissions)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Получить только активных пользователей (с подтвержденным email и не заблокированных)
     */
    public function scopeActive($query)
    {
        return $query->whereNotNull('email_verified_at')->where('is_active', true);
    }

    /**
     * Обновить время последнего входа
     */
    public function updateLastLogin(): void
    {
        $this->update(['last_login_at' => now()]);
    }

    /**
     * Проверить наличие локального аватара в папке фронтенда
     */
    public function getHasLocalAvatarAttribute(): bool
    {
        try {
            if (! $this->id) {
                return false;
            }

            $filename = 'user_'.$this->id.'.jpg';
            $path = frontend_public_path('images/users/'.$filename);

            return file_exists($path);
        } catch (\Exception $e) {
            return false;
        }
    }
}
