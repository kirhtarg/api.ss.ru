<?php

namespace Tests\Unit\Partner;

use App\Services\Partner\PartnerPromotionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PartnerPromotionTagRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('settings', function (Blueprint $table): void {
            $table->id(); $table->string('key')->unique(); $table->text('value')->nullable(); $table->timestamps();
        });
        Schema::create('shop_bonus_settings', function (Blueprint $table): void {
            $table->id(); $table->string('name')->unique();
            $table->decimal('regular_price_percentage', 5, 2)->default(5);
            $table->decimal('sale_price_percentage', 5, 2)->default(2.5);
            $table->decimal('max_usage_percentage', 5, 2)->default(50);
            $table->boolean('is_active')->default(true); $table->decimal('min_order_amount', 12, 2)->default(0);
            $table->decimal('min_purchase_amount', 12, 2)->default(0); $table->integer('min_bonus_amount')->default(1);
            $table->integer('max_bonus_amount')->nullable(); $table->integer('bonus_expiry_days')->default(365);
            $table->json('metadata')->nullable(); $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shop_bonus_settings');
        Schema::dropIfExists('settings');
        parent::tearDown();
    }

    public function test_product_tags_can_block_registration_discount_and_bonus_earn(): void
    {
        $result = app(PartnerPromotionService::class)->apply([$this->item([
            'disables_bonuses' => true, 'disables_registered_discount' => true,
        ])], $this->registeredPromotion(7));

        $this->assertSame(1000.0, $result['payable_subtotal']);
        $this->assertSame(0, $result['bonus']['earn_preview']);
        $this->assertSame(1, $result['bonus']['tag_rules']['blocked_lines']);
        $this->assertSame('disabled_by_product_tag', $result['decisions'][0]['reason']);
    }

    public function test_highest_tag_bonus_percent_is_used_as_line_bonus_rate(): void
    {
        $result = app(PartnerPromotionService::class)->apply([$this->item([
            'increased_bonus_percent' => 12,
        ])], $this->registeredPromotion(0));

        $this->assertSame(1000.0, $result['payable_subtotal']);
        $this->assertSame(120, $result['bonus']['earn_preview']);
        $this->assertSame(1, $result['bonus']['tag_rules']['increased_lines']);
    }

    private function item(array $policy): array
    {
        return ['good_id' => 1, 'variation_id' => null, 'quantity' => 1, 'base_price' => 1000.0,
            'price' => 1000.0, 'final_price' => 1000.0, 'total' => 1000.0, 'discounts' => [],
            'tag_policy' => array_replace(['disables_bonuses' => false, 'disables_registered_discount' => false,
                'extra_discount_percent' => 0.0, 'increased_bonus_percent' => 0.0], $policy)];
    }

    private function registeredPromotion(float $percent): array
    {
        return ['customer_reference' => ['registration_status' => 'registered', 'birthday_status' => 'not_today'],
            'registration_discount_percent' => $percent];
    }
}
