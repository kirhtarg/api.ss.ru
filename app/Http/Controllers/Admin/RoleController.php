<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class RoleController extends Controller
{
    /**
     * Получить все роли
     */
    public function index()
    {
        try {
            $roles = Role::orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $roles
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка получения ролей: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения ролей: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить роль по ID
     */
    public function show($id)
    {
        try {
            $role = Role::find($id);

            if (!$role) {
                return response()->json([
                    'success' => false,
                    'message' => 'Роль не найдена'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $role
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка получения роли: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения роли: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать новую роль
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:roles,name',
                'display_name' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'permissions' => 'nullable|array',
                'is_active' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Проверяем, что имя роли не является системным
            $systemRoles = ['admin', 'user'];
            if (in_array($request->name, $systemRoles)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя создать роль с системным именем'
                ], 422);
            }

            $role = Role::create([
                'name' => $request->name,
                'display_name' => $request->display_name,
                'description' => $request->description,
                'permissions' => $request->permissions ?: [],
                'is_active' => $request->is_active ?? true
            ]);

            Log::info('Создана новая роль: ' . $role->name);

            return response()->json([
                'success' => true,
                'message' => 'Роль успешно создана',
                'data' => $role
            ], 201);
        } catch (\Exception $e) {
            Log::error('Ошибка создания роли: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания роли: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить роль
     */
    public function update(Request $request, $id)
    {
        try {
            $role = Role::find($id);

            if (!$role) {
                return response()->json([
                    'success' => false,
                    'message' => 'Роль не найдена'
                ], 404);
            }

            // Проверяем, что роль не является системной
            $systemRoles = ['admin', 'user'];
            if (in_array($role->name, $systemRoles)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя редактировать системные роли'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:roles,name,' . $id,
                'display_name' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'permissions' => 'nullable|array',
                'is_active' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $role->update([
                'name' => $request->name,
                'display_name' => $request->display_name,
                'description' => $request->description,
                'permissions' => $request->permissions ?: [],
                'is_active' => $request->is_active ?? $role->is_active
            ]);

            Log::info('Обновлена роль: ' . $role->name);

            return response()->json([
                'success' => true,
                'message' => 'Роль успешно обновлена',
                'data' => $role
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка обновления роли: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления роли: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить роль
     */
    public function destroy($id)
    {
        try {
            $role = Role::find($id);

            if (!$role) {
                return response()->json([
                    'success' => false,
                    'message' => 'Роль не найдена'
                ], 404);
            }

            // Проверяем, что роль не является системной
            $systemRoles = ['admin', 'user'];
            if (in_array($role->name, $systemRoles)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить системные роли'
                ], 422);
            }

            // Проверяем, есть ли пользователи с этой ролью
            if ($role->users()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить роль, которая назначена пользователям'
                ], 422);
            }

            $roleName = $role->name;
            $role->delete();

            Log::info('Удалена роль: ' . $roleName);

            return response()->json([
                'success' => true,
                'message' => 'Роль успешно удалена'
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка удаления роли: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления роли: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Изменить статус роли
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $role = Role::find($id);

            if (!$role) {
                return response()->json([
                    'success' => false,
                    'message' => 'Роль не найдена'
                ], 404);
            }

            // Проверяем, что роль не является системной
            $systemRoles = ['admin', 'user'];
            if (in_array($role->name, $systemRoles)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя изменить статус системных ролей'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'is_active' => 'required|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $role->update([
                'is_active' => $request->is_active
            ]);

            Log::info('Изменен статус роли: ' . $role->name . ' на ' . ($request->is_active ? 'активна' : 'неактивна'));

            return response()->json([
                'success' => true,
                'message' => 'Статус роли успешно обновлен',
                'data' => [
                    'id' => $role->id,
                    'is_active' => $role->is_active
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка обновления статуса роли: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления статуса роли: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить статистику по ролям
     */
    public function statistics()
    {
        try {
            $stats = [
                'total_roles' => Role::count(),
                'active_roles' => Role::where('is_active', true)->count(),
                'system_roles' => Role::whereIn('name', ['admin', 'user'])->count(),
                'custom_roles' => Role::whereNotIn('name', ['admin', 'user'])->count(),
                'roles_with_users' => Role::whereHas('users')->count()
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка получения статистики ролей: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения статистики ролей: ' . $e->getMessage()
            ], 500);
        }
    }
}
