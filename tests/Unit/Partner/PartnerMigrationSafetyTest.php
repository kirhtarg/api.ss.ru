<?php

namespace Tests\Unit\Partner;

use Tests\TestCase;

class PartnerMigrationSafetyTest extends TestCase
{
    public function test_partner_migrations_do_not_alter_existing_store_or_payment_tables(): void
    {
        $migrations = $this->partnerMigrationSources();
        $protectedTables = ['shop_orders', 'shop_goods', 'users', 'shop_payment_methods', 'shop_payment_transactions'];

        foreach ($protectedTables as $table) {
            foreach ($migrations as $source) {
                $this->assertStringNotContainsString("Schema::table('{$table}'", $source);
                $this->assertStringNotContainsString("DB::table('{$table}')->update", $source);
            }
        }
    }

    public function test_menu_migration_is_idempotent_and_never_updates_existing_items(): void
    {
        $source = file_get_contents(database_path('migrations/2026_07_31_020000_add_partner_api_admin_menu_item.php'));

        $this->assertStringContainsString("->where('href', 'partner-api')", $source);
        $this->assertStringContainsString("->where('page_id', \$settingsPageId)", $source);
        $this->assertStringContainsString('if ($itemExists)', $source);
        $this->assertStringNotContainsString('updateOrInsert', $source);
        $this->assertStringNotContainsString("DB::table('admin_menu_items')->update", $source);
    }

    /**
     * @return list<string>
     */
    private function partnerMigrationSources(): array
    {
        return array_map(
            fn (string $file): string => file_get_contents(database_path('migrations/'.$file)),
            [
                '2026_07_31_000000_create_partner_api_tables.php',
                '2026_07_31_010000_create_partner_payouts_table.php',
                '2026_07_31_020000_add_partner_api_admin_menu_item.php',
            ],
        );
    }
}
