<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AdminPage;
use App\Models\Role;

class AdminPageAccessSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Получаем роли
        $adminRole = Role::where('name', 'admin')->first();
        $userRole = Role::where('name', 'user')->first();

        if (!$adminRole || !$userRole) {
            $this->command->warn('Роли admin или user не найдены. Запустите RoleSeeder сначала.');
            return;
        }

        // Получаем все страницы админки
        $pages = AdminPage::all();

        foreach ($pages as $page) {
            // Администратор имеет доступ ко всем страницам
            if (!$page->roles()->where('role_id', $adminRole->id)->exists()) {
                $page->roles()->attach($adminRole->id);
            }

            // Пользователь не имеет доступа ни к одной странице (по умолчанию)
            // Это можно изменить вручную через админку
        }

        $this->command->info('Начальные разрешения доступа к страницам установлены.');
        $this->command->info("Администратор имеет доступ к {$pages->count()} страницам.");
        $this->command->info('Пользователь не имеет доступа к страницам админки.');
    }
}
