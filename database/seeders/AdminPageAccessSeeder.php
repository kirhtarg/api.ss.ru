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
        $managerRole = Role::where('name', 'manager')->first();
        $userRole = Role::where('name', 'user')->first();

        if (!$adminRole || !$userRole) {
            $this->command->warn('Роли admin или user не найдены. Запустите RoleSeeder сначала.');
            return;
        }

        // Получаем все страницы админки
        $pages = AdminPage::all();

        // Страницы, к которым должен иметь доступ менеджер
        $managerAllowedPages = ['dashboard', 'shop', 'settings'];

        foreach ($pages as $page) {
            // Администратор имеет доступ ко всем страницам
            if (!$page->roles()->where('role_id', $adminRole->id)->exists()) {
                $page->roles()->attach($adminRole->id);
            }

            // Менеджер имеет доступ к определенным страницам
            if ($managerRole && in_array($page->slug, $managerAllowedPages)) {
                if (!$page->roles()->where('role_id', $managerRole->id)->exists()) {
                    $page->roles()->attach($managerRole->id);
                }
            }

            // Пользователь не имеет доступа ни к одной странице (по умолчанию)
            // Это можно изменить вручную через админку
        }

        $this->command->info('Начальные разрешения доступа к страницам установлены.');
        $this->command->info("Администратор имеет доступ к {$pages->count()} страницам.");
        if ($managerRole) {
            $this->command->info("Менеджер имеет доступ к " . count($managerAllowedPages) . " страницам.");
        }
        $this->command->info('Пользователь не имеет доступа к страницам админки.');
    }
}
