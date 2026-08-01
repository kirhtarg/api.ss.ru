<?php

namespace Tests\Feature\PartnerApi;

use App\Models\Partner;
use App\Models\PartnerApiCredential;
use App\Services\OrderCalculationService;
use App\Services\Partner\PartnerSignatureCanonicalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerCatalogV11HttpTest extends TestCase
{
    private PartnerApiCredential $credential;
    private string $secret = 'catalog-v11-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        Cache::flush();
        $partner = Partner::create([
            'public_id' => (string) Str::uuid(), 'code' => 'catalog-v11', 'name' => 'Catalog v1.1',
            'is_active' => true, 'commission_rate' => 0.1,
        ]);
        $this->credential = $partner->credentials()->create([
            'key_id' => 'pk_catalog_v11', 'secret' => $this->secret, 'scopes' => ['catalog:read'],
        ]);
        $this->mock(OrderCalculationService::class, function ($mock): void {
            $mock->shouldReceive('calculateFinalUnitPrice')->andReturnUsing(fn ($good) => [
                'base_price' => (float) $good->price,
                'sale_price' => (float) ($good->sale_price ?? 0),
                'final_price' => (float) ($good->sale_price ?: $good->price),
                'discounts' => [],
            ]);
        });
    }

    protected function tearDown(): void
    {
        foreach (['partner_api_request_logs', 'shop_stocks', 'shop_good_properties', 'shop_property_values', 'shop_properties', 'shop_good_brands', 'shop_brands', 'shop_variation_attributes_values', 'shop_variation_attribute_values', 'shop_variation_attributes', 'shop_good_images', 'shop_good_variations', 'shop_good_categories', 'shop_categories', 'shop_goods', 'partner_api_credentials', 'partners'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_legacy_category_shape_is_preserved_without_sync_parameters(): void
    {
        DB::table('shop_categories')->insert([
            'name' => 'Bikes', 'slug' => 'bikes', 'is_active' => true, 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $path = '/api/partner/v1/catalog/categories';

        $this->withHeaders($this->signedHeaders($path, '', 'catalog-category-legacy'))->get($path)
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'bikes')
            ->assertJsonMissingPath('data.data');
    }

    public function test_product_cursor_is_stable_and_incremental_feed_contains_inactive_product(): void
    {
        $firstId = $this->insertGood('ACTIVE-1', true, now()->subMinutes(2));
        $secondId = $this->insertGood('INACTIVE-2', false, now()->subMinute());
        $basePath = '/api/partner/v1/catalog/products';
        $query = http_build_query(['updated_since' => now()->subHour()->toIso8601String(), 'per_page' => 1]);
        $first = $this->withHeaders($this->signedHeaders($basePath, $query, 'catalog-cursor-1'))
            ->get($basePath.'?'.$query)->assertOk();
        $this->assertSame($firstId, $first->json('data.data.0.id'));
        $cursor = $first->json('meta.next_cursor');
        $this->assertNotEmpty($cursor);
        $nextQuery = http_build_query(['updated_since' => now()->subHour()->toIso8601String(), 'per_page' => 1, 'cursor' => $cursor]);
        $second = $this->withHeaders($this->signedHeaders($basePath, $nextQuery, 'catalog-cursor-2'))
            ->get($basePath.'?'.$nextQuery)->assertOk();

        $this->assertSame($secondId, $second->json('data.data.0.id'));
        $second->assertJsonPath('data.data.0.is_active', false);
    }

    public function test_active_detail_is_available_and_inactive_detail_is_hidden(): void
    {
        $activeId = $this->insertGood('ACTIVE-DETAIL', true, now());
        $inactiveId = $this->insertGood('INACTIVE-DETAIL', false, now());

        foreach ([[$activeId, 200, 'active-detail'], [$inactiveId, 404, 'inactive-detail']] as [$id, $status, $nonce]) {
            $path = '/api/partner/v1/catalog/products/'.$id;
            $this->withHeaders($this->signedHeaders($path, '', $nonce))->get($path)->assertStatus($status);
        }
    }

    public function test_inactive_product_is_absent_without_updated_since(): void
    {
        $this->insertGood('ACTIVE-LIST', true, now());
        $inactiveId = $this->insertGood('INACTIVE-LIST', false, now());
        $path = '/api/partner/v1/catalog/products';
        $query = 'per_page=100';

        $response = $this->withHeaders($this->signedHeaders($path, $query, 'inactive-normal-list'))
            ->get($path.'?'.$query)->assertOk();

        $this->assertNotContains($inactiveId, collect($response->json('data.data'))->pluck('id')->all());
    }

    public function test_product_response_contains_explicit_contract_without_internal_fields(): void
    {
        $goodId = $this->insertGood('SAFE-DTO', true, now());
        $path = '/api/partner/v1/catalog/products/'.$goodId;

        $response = $this->withHeaders($this->signedHeaders($path, '', 'catalog-safe-dto'))->get($path)->assertOk();
        $response->assertJsonPath('data.sku', 'SAFE-DTO')
            ->assertJsonPath('data.currency', 'RUB')
            ->assertJsonPath('data.available_quantity', 5)
            ->assertJsonMissingPath('data.supplier')
            ->assertJsonMissingPath('data.demping_price')
            ->assertJsonMissingPath('data.metadata');
    }

    private function insertGood(string $sku, bool $active, $updatedAt): int
    {
        return DB::table('shop_goods')->insertGetId([
            'name' => $sku, 'slug' => strtolower($sku), 'sku' => $sku, 'description' => 'Description',
            'short_description' => 'Short', 'price' => 1000, 'sale_price' => null,
            'stock_quantity' => 5, 'is_active' => $active, 'supplier' => 'must-not-leak',
            'created_at' => $updatedAt, 'updated_at' => $updatedAt,
        ]);
    }

    private function signedHeaders(string $path, string $query, string $nonce): array
    {
        $timestamp = (string) now()->timestamp;
        $canonical = app(PartnerSignatureCanonicalizer::class)
            ->request('GET', $path, $query, '', $timestamp, $nonce);

        return [
            'X-Partner-Key' => $this->credential->key_id,
            'X-Partner-Timestamp' => $timestamp,
            'X-Partner-Nonce' => $nonce,
            'X-Partner-Signature' => hash_hmac('sha256', $canonical, $this->secret),
        ];
    }

    private function createTables(): void
    {
        Schema::create('partners', function (Blueprint $table): void { $table->id(); $table->uuid('public_id'); $table->string('code'); $table->string('name'); $table->boolean('is_active'); $table->decimal('commission_rate', 7, 4); $table->string('commission_status')->default('after_completed'); $table->text('webhook_url')->nullable(); $table->text('webhook_secret')->nullable(); $table->json('allowed_ips')->nullable(); $table->json('metadata')->nullable(); $table->timestamps(); });
        Schema::create('partner_api_credentials', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('partner_id'); $table->string('key_id'); $table->text('secret'); $table->json('scopes'); $table->timestamp('last_used_at')->nullable(); $table->timestamp('expires_at')->nullable(); $table->timestamp('revoked_at')->nullable(); $table->timestamps(); });
        Schema::create('partner_api_request_logs', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('partner_id')->nullable(); $table->string('request_id'); $table->string('method'); $table->string('path'); $table->unsignedSmallInteger('response_status')->nullable(); $table->unsignedInteger('duration_ms')->nullable(); $table->string('ip_address')->nullable(); $table->char('request_hash', 64)->nullable(); $table->string('error_code')->nullable(); $table->timestamps(); });
        Schema::create('shop_goods', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('slug'); $table->string('sku'); $table->text('description')->nullable(); $table->text('short_description')->nullable(); $table->decimal('price', 12, 2); $table->decimal('sale_price', 12, 2)->nullable(); $table->integer('stock_quantity'); $table->boolean('is_active'); $table->string('supplier')->nullable(); $table->decimal('width', 8, 2)->nullable(); $table->decimal('height', 8, 2)->nullable(); $table->decimal('depth', 8, 2)->nullable(); $table->decimal('weight', 8, 2)->nullable(); $table->timestamps(); });
        Schema::create('shop_categories', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('parent_id')->nullable(); $table->string('name'); $table->string('slug'); $table->string('image')->nullable(); $table->boolean('is_active'); $table->integer('sort_order'); $table->timestamps(); });
        Schema::create('shop_good_categories', function (Blueprint $table): void { $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('category_id'); });
        Schema::create('shop_good_variations', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('good_id'); $table->string('name')->nullable(); $table->string('sku')->nullable(); $table->decimal('price', 12, 2)->default(0); $table->decimal('sale_price', 12, 2)->nullable(); $table->integer('stock_quantity')->default(0); $table->boolean('is_active')->default(true); $table->timestamps(); });
        Schema::create('shop_good_images', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('variation_id')->nullable(); $table->string('file_path'); $table->string('alt_text')->nullable(); $table->boolean('is_main')->default(false); $table->integer('sort_order')->default(0); $table->timestamps(); });
        Schema::create('shop_variation_attributes', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('type')->nullable(); $table->timestamps(); });
        Schema::create('shop_variation_attribute_values', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('attribute_id'); $table->string('value'); $table->string('color')->nullable(); $table->boolean('is_active')->default(true); $table->integer('sort_order')->default(0); $table->timestamps(); });
        Schema::create('shop_variation_attributes_values', function (Blueprint $table): void { $table->unsignedBigInteger('variation_id'); $table->unsignedBigInteger('attribute_value_id'); });
        Schema::create('shop_brands', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('slug'); $table->timestamps(); });
        Schema::create('shop_good_brands', function (Blueprint $table): void { $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('brand_id'); });
        Schema::create('shop_properties', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('slug')->nullable(); $table->string('property_type')->nullable(); $table->integer('sort_order')->default(0); $table->timestamps(); });
        Schema::create('shop_property_values', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('property_id'); $table->string('value'); $table->string('color')->nullable(); $table->boolean('is_active')->default(true); $table->integer('sort_order')->default(0); $table->timestamps(); });
        Schema::create('shop_good_properties', function (Blueprint $table): void { $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('property_id'); $table->unsignedBigInteger('shop_property_value_id')->nullable(); $table->timestamps(); });
        Schema::create('shop_stocks', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('variation_id')->nullable(); $table->integer('quantity'); $table->integer('reserved_quantity')->default(0); $table->timestamps(); });
    }
}
