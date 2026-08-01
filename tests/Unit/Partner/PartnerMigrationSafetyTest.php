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

    public function test_v11_webhook_duration_migration_only_alters_partner_table(): void
    {
        $source = file_get_contents(database_path('migrations/2026_08_01_000000_add_duration_to_partner_webhook_deliveries.php'));

        $this->assertStringContainsString("Schema::table('partner_webhook_deliveries'", $source);
        $this->assertStringContainsString("unsignedInteger('duration_ms')", $source);
        $this->assertSame(2, substr_count($source, 'Schema::table('));
        $this->assertStringNotContainsString('DB::table(', $source);
    }

    public function test_payment_idempotency_migration_has_partner_scoped_unique_constraint(): void
    {
        $source = file_get_contents(database_path('migrations/2026_08_01_010000_create_partner_checkout_quotes_and_payment_idempotencies.php'));

        $this->assertStringContainsString("Schema::create('partner_payment_idempotencies'", $source);
        $this->assertStringContainsString("unique(['partner_id', 'idempotency_key']", $source);
        $this->assertStringNotContainsString("Schema::table('shop_payment_transactions'", $source);
        $this->assertStringNotContainsString("Schema::table('shop_orders'", $source);
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
                '2026_08_01_000000_add_duration_to_partner_webhook_deliveries.php',
                '2026_08_01_010000_create_partner_checkout_quotes_and_payment_idempotencies.php',
            ],
        );
    }
}
