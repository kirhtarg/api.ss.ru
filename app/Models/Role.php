<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'permissions',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'permissions' => 'array'
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
     * Страницы админки, к которым имеет доступ эта роль
     */
    public function adminPages(): BelongsToMany
    {
        return $this->belongsToMany(AdminPage::class, 'admin_page_role', 'role_id', 'admin_page_id');
    }

    /**
     * Scope для активных ролей
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Проверить, имеет ли роль определенное разрешение
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->permissions) {
            return false;
        }
        
        return in_array($permission, $this->permissions);
    }

    /**
     * Проверить, имеет ли роль любое из разрешений
     */
    public function hasAnyPermission(array $permissions): bool
    {
        if (!$this->permissions) {
            return false;
        }
        
        foreach ($permissions as $permission) {
            if (in_array($permission, $this->permissions)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Проверить, имеет ли роль все разрешения
     */
    public function hasAllPermissions(array $permissions): bool
    {
        if (!$this->permissions) {
            return false;
        }
        
        foreach ($permissions as $permission) {
            if (!in_array($permission, $this->permissions)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Добавить разрешение к роли
     */
    public function addPermission(string $permission): void
    {
        if (!$this->permissions) {
            $this->permissions = [];
        }
        
        if (!in_array($permission, $this->permissions)) {
            $this->permissions[] = $permission;
            $this->save();
        }
    }

    /**
     * Удалить разрешение из роли
     */
    public function removePermission(string $permission): void
    {
        if ($this->permissions) {
            $this->permissions = array_filter($this->permissions, function($p) use ($permission) {
                return $p !== $permission;
            });
            $this->save();
        }
    }

    /**
     * Установить разрешения для роли
     */
    public function setPermissions(array $permissions): void
    {
        $this->permissions = $permissions;
        $this->save();
    }
}
