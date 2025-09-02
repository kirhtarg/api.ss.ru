<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AdminPage;
use App\Models\Role;

class ManagerAccessSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Получаем роль manager
        $managerRole = Role::where('name', 'manager')->first();

        if (!$managerRole) {
            $this->command->warn('Роль manager не найдена. Создаем её...');
            $managerRole = Role::create([
                'name' => 'manager',
                'display_name' => 'Менеджер',
                'description' => 'Ограниченный доступ к системе',
                'is_active' => true,
            ]);
        }

        // Получаем страницы админки, к которым должен иметь доступ менеджер
        $allowedPages = [
            'dashboard',  // Панель управления
            'shop',       // Магазин
            'settings',   // Настройки (только просмотр)
        ];

        foreach ($allowedPages as $pageSlug) {
            $page = AdminPage::where('slug', $pageSlug)->first();
            
            if ($page) {
                // Проверяем, есть ли уже доступ
                if (!$page->roles()->where('role_id', $managerRole->id)->exists()) {
                    $page->roles()->attach($managerRole->id);
                    $this->command->info("Доступ к странице '{$page->title}' предоставлен роли manager");
                } else {
                    $this->command->info("Доступ к странице '{$page->title}' уже существует для роли manager");
                }
            } else {
                $this->command->warn("Страница с slug '{$pageSlug}' не найдена");
            }
        }

        $this->command->info('Настройка доступа для роли manager завершена.');
    }
}
