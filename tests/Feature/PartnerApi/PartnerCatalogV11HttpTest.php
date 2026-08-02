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
            $mock->shouldReceive('calculateFinalUnitPrice')->andReturnUsing(function ($good, $variation = null): array {
                $priced = $variation ?: $good;
                $final = $priced->show_demping && $priced->demping_price
                    ? $priced->demping_price
                    : ($priced->sale_price ?: $priced->price);

                return ['base_price' => (float) $priced->price, 'sale_price' => (float) ($priced->sale_price ?? 0), 'final_price' => (float) $final, 'discounts' => []];
            });
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

    public function test_normal_catalog_hides_non_displayed_and_unavailable_goods_but_keeps_preorder(): void
    {
        $hidden = $this->insertGood('HIDDEN', true, now(), ['is_show' => false]);
        $unavailable = $this->insertGood('UNAVAILABLE', true, now(), ['stock_quantity' => 0]);
        $preorder = $this->insertGood('PREORDER', true, now(), ['stock_quantity' => 0, 'is_preorder' => true]);
        $path = '/api/partner/v1/catalog/products';
        $query = 'per_page=100';
        $ids = collect($this->withHeaders($this->signedHeaders($path, $query, 'catalog-eligibility'))->get($path.'?'.$query)->assertOk()->json('data.data'))->pluck('id')->all();

        $this->assertNotContains($hidden, $ids);
        $this->assertNotContains($unavailable, $ids);
        $this->assertContains($preorder, $ids);

        $incrementalQuery = http_build_query(['updated_since' => now()->subMinute()->toIso8601String(), 'per_page' => 100]);
        $hiddenData = collect($this->withHeaders($this->signedHeaders($path, $incrementalQuery, 'hidden-incremental'))
            ->get($path.'?'.$incrementalQuery)->assertOk()->json('data.data'))->firstWhere('id', $hidden);
        $this->assertFalse($hiddenData['is_active']);
        $this->assertFalse($hiddenData['is_available']);
        $this->assertFalse($hiddenData['can_order']);
        $this->assertSame('unavailable', $hiddenData['purchase_mode']);
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
            ->assertJsonPath('data.demping_price', null)
            ->assertJsonPath('data.show_demping', false)
            ->assertJsonMissingPath('data.metadata');
    }

    public function test_sale_price_wins_when_demping_is_disabled(): void
    {
        $goodId = $this->insertGood('SALE-NO-DEMPING', true, now(), [
            'sale_price' => 900, 'demping_price' => 800, 'show_demping' => false,
        ]);
        $path = '/api/partner/v1/catalog/products/'.$goodId;

        $this->withHeaders($this->signedHeaders($path, '', 'sale-no-demping'))->get($path)->assertOk()
            ->assertJsonPath('data.price', 1000)
            ->assertJsonPath('data.old_price', 1000)
            ->assertJsonPath('data.sale_price', 900)
            ->assertJsonPath('data.demping_price', null)
            ->assertJsonPath('data.show_demping', false)
            ->assertJsonPath('data.final_price', 900);
    }

    public function test_brands_endpoint_returns_only_active_brands_with_catalog_eligible_goods(): void
    {
        config()->set('app.frontend_url', 'https://skateandsnow.ru');
        $eligibleBrand = DB::table('shop_brands')->insertGetId(['name' => 'Eligible', 'slug' => 'eligible', 'logo' => '/images/brands/eligible.svg', 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $inactiveBrand = DB::table('shop_brands')->insertGetId(['name' => 'Inactive', 'slug' => 'inactive', 'logo' => null, 'is_active' => false, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $emptyBrand = DB::table('shop_brands')->insertGetId(['name' => 'Empty', 'slug' => 'empty', 'logo' => null, 'is_active' => true, 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $goodId = $this->insertGood('BRAND-ELIGIBLE', true, now());
        DB::table('shop_good_brands')->insert([['good_id' => $goodId, 'brand_id' => $eligibleBrand], ['good_id' => $goodId, 'brand_id' => $inactiveBrand]]);

        $path = '/api/partner/v1/catalog/brands';
        $response = $this->withHeaders($this->signedHeaders($path, '', 'brands-success'))->get($path)->assertOk();
        $response->assertExactJson(['success' => true, 'data' => [[
            'id' => $eligibleBrand, 'name' => 'Eligible', 'slug' => 'eligible',
            'logo_url' => 'https://skateandsnow.ru/images/brands/eligible.svg',
        ]]]);
        $this->assertNotContains($inactiveBrand, collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains($emptyBrand, collect($response->json('data'))->pluck('id')->all());

        $this->getJson($path)->assertUnauthorized();
        $this->credential->update(['scopes' => ['orders:read']]);
        $this->credential->refresh();
        $this->withHeaders($this->signedHeaders($path, '', 'brands-forbidden'))->get($path)->assertForbidden();
    }

    public function test_brand_filter_is_signed_paginated_and_returns_only_matching_goods(): void
    {
        $firstBrand = DB::table('shop_brands')->insertGetId(['name' => 'First', 'slug' => 'first', 'logo' => null, 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $secondBrand = DB::table('shop_brands')->insertGetId(['name' => 'Second', 'slug' => 'second', 'logo' => null, 'is_active' => true, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $first = $this->insertGood('FIRST-BRAND-1', true, now()->subMinute());
        $next = $this->insertGood('FIRST-BRAND-2', true, now());
        $other = $this->insertGood('SECOND-BRAND', true, now());
        DB::table('shop_good_brands')->insert([['good_id' => $first, 'brand_id' => $firstBrand], ['good_id' => $next, 'brand_id' => $firstBrand], ['good_id' => $other, 'brand_id' => $secondBrand]]);
        $path = '/api/partner/v1/catalog/products';
        $query = http_build_query(['brand_id' => $firstBrand, 'per_page' => 1]);
        $page = $this->withHeaders($this->signedHeaders($path, $query, 'brand-filter-1'))->get($path.'?'.$query)->assertOk();
        $page->assertJsonPath('data.data.0.id', $first);
        $this->assertNotEmpty($page->json('meta.next_cursor'));
        $cursorQuery = http_build_query(['brand_id' => $firstBrand, 'per_page' => 1, 'cursor' => $page->json('meta.next_cursor')]);
        $this->withHeaders($this->signedHeaders($path, $cursorQuery, 'brand-filter-2'))->get($path.'?'.$cursorQuery)
            ->assertOk()->assertJsonPath('data.data.0.id', $next);
        $unknown = 'brand_id=999999';
        $this->withHeaders($this->signedHeaders($path, $unknown, 'brand-filter-empty'))->get($path.'?'.$unknown)
            ->assertOk()->assertJsonCount(0, 'data.data');
        $this->withHeaders($this->signedHeaders($path, 'brand_id='.$secondBrand, 'brand-filter-tamper'))
            ->get($path.'?brand_id='.$firstBrand)->assertUnauthorized();
    }

    public function test_list_and_detail_share_stock_price_variation_and_preorder_contract(): void
    {
        $goodId = $this->insertGood('PREORDER-CONTRACT', true, now(), [
            'stock_quantity' => 0, 'remote_stock_quantity' => '>10', 'fast_remote_stock_quantity' => null,
            'is_preorder' => true, 'sale_price' => 900, 'demping_price' => 800, 'show_demping' => true,
        ]);
        DB::table('shop_good_variations')->insert([
            ['good_id' => $goodId, 'name' => 'Active', 'sku' => 'VAR-A', 'price' => 1000, 'sale_price' => 900, 'demping_price' => 800, 'show_demping' => true, 'stock_quantity' => 0, 'remote_stock_quantity' => '7', 'fast_remote_stock_quantity' => '>10', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['good_id' => $goodId, 'name' => 'Inactive', 'sku' => 'VAR-X', 'price' => 1000, 'sale_price' => null, 'demping_price' => null, 'show_demping' => false, 'stock_quantity' => 4, 'remote_stock_quantity' => null, 'fast_remote_stock_quantity' => null, 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $listPath = '/api/partner/v1/catalog/products';
        $query = 'per_page=100';
        $listItem = collect($this->withHeaders($this->signedHeaders($listPath, $query, 'contract-list'))->get($listPath.'?'.$query)->assertOk()->json('data.data'))->firstWhere('id', $goodId);
        $showPath = $listPath.'/'.$goodId;
        $showItem = $this->withHeaders($this->signedHeaders($showPath, '', 'contract-show'))->get($showPath)->assertOk()->json('data');

        $this->assertSame(array_keys($showItem), array_keys($listItem));
        $this->assertSame('>10', $showItem['remote_stock_quantity']);
        $this->assertNull($showItem['fast_remote_stock_quantity']);
        $this->assertSame('preorder', $showItem['purchase_mode']);
        $this->assertSame(800, $showItem['final_price']);
        $this->assertSame(800, $showItem['demping_price']);
        $this->assertTrue($showItem['show_demping']);
        $this->assertFalse($showItem['is_available']);
        $this->assertTrue($showItem['can_preorder']);
        $this->assertCount(1, $showItem['variations']);
        $this->assertSame(7, $showItem['variations'][0]['remote_stock_quantity']);
        $this->assertSame('>10', $showItem['variations'][0]['fast_remote_stock_quantity']);
        $this->assertSame('preorder', $showItem['variations'][0]['purchase_mode']);
        $this->assertSame(800, $showItem['variations'][0]['final_price']);
        foreach (['supplier', 'stock_source', 'last_stock_import_run_id', 'metadata'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $showItem);
        }
    }

    public function test_product_availability_is_derived_from_active_variation_stock(): void
    {
        $goodId = $this->insertGood('VARIATION-STOCK', true, now(), ['stock_quantity' => 0]);
        $this->insertVariation($goodId, 'AVAILABLE-VARIATION', 5);

        $data = $this->getProduct($goodId, 'variation-stock');

        $this->assertSame(5, $data['available_quantity']);
        $this->assertTrue($data['is_available']);
        $this->assertTrue($data['can_order']);
        $this->assertFalse($data['can_preorder']);
        $this->assertSame('regular', $data['purchase_mode']);
        $this->assertTrue($data['variations'][0]['is_available']);
        $this->assertSame('regular', $data['variations'][0]['purchase_mode']);
    }

    public function test_zero_stock_variations_follow_parent_preorder_state(): void
    {
        $goodId = $this->insertGood('VARIATION-PREORDER', true, now(), ['stock_quantity' => 0, 'is_preorder' => true]);
        $this->insertVariation($goodId, 'PREORDER-VARIATION-A', 0);
        $this->insertVariation($goodId, 'PREORDER-VARIATION-B', 0);

        $data = $this->getProduct($goodId, 'variation-preorder');

        $this->assertSame(0, $data['available_quantity']);
        $this->assertFalse($data['is_available']);
        $this->assertFalse($data['can_order']);
        $this->assertTrue($data['can_preorder']);
        $this->assertSame('preorder', $data['purchase_mode']);
        $this->assertSame(['preorder', 'preorder'], collect($data['variations'])->pluck('purchase_mode')->all());
        $this->assertNotContains(true, collect($data['variations'])->pluck('can_order')->all());
        $this->assertNotContains(false, collect($data['variations'])->pluck('can_preorder')->all());
    }

    public function test_zero_stock_variations_without_preorder_are_unavailable_in_incremental_feed(): void
    {
        $goodId = $this->insertGood('VARIATION-UNAVAILABLE', true, now(), ['stock_quantity' => 0]);
        $this->insertVariation($goodId, 'UNAVAILABLE-VARIATION', 0);
        $path = '/api/partner/v1/catalog/products';
        $query = http_build_query(['updated_since' => now()->subMinute()->toIso8601String(), 'per_page' => 100]);
        $data = collect($this->withHeaders($this->signedHeaders($path, $query, 'variation-unavailable'))
            ->get($path.'?'.$query)->assertOk()->json('data.data'))->firstWhere('id', $goodId);

        $this->assertSame(0, $data['available_quantity']);
        $this->assertFalse($data['is_available']);
        $this->assertFalse($data['can_order']);
        $this->assertFalse($data['can_preorder']);
        $this->assertSame('unavailable', $data['purchase_mode']);
        $this->assertSame('unavailable', $data['variations'][0]['purchase_mode']);
    }

    public function test_product_and_variations_can_expose_regular_and_preorder_modes_together(): void
    {
        $goodId = $this->insertGood('MIXED-VARIATION-MODES', true, now(), ['stock_quantity' => 0, 'is_preorder' => true]);
        $this->insertVariation($goodId, 'REGULAR-VARIATION', 3);
        $this->insertVariation($goodId, 'PREORDER-VARIATION', 0);

        $data = $this->getProduct($goodId, 'mixed-variation-modes');
        $modes = collect($data['variations'])->mapWithKeys(
            fn (array $variation): array => [$variation['sku'] => $variation['purchase_mode']],
        )->all();

        $this->assertSame(3, $data['available_quantity']);
        $this->assertTrue($data['is_available']);
        $this->assertSame('regular', $data['purchase_mode']);
        $this->assertSame('regular', $modes['REGULAR-VARIATION']);
        $this->assertSame('preorder', $modes['PREORDER-VARIATION']);
        $variations = collect($data['variations'])->keyBy('sku');
        $this->assertTrue($variations['REGULAR-VARIATION']['can_order']);
        $this->assertFalse($variations['REGULAR-VARIATION']['can_preorder']);
        $this->assertFalse($variations['PREORDER-VARIATION']['can_order']);
        $this->assertTrue($variations['PREORDER-VARIATION']['can_preorder']);
    }

    public function test_remote_only_stock_is_informational_and_does_not_enable_partner_checkout(): void
    {
        $goodId = $this->insertGood('REMOTE-ONLY', true, now(), [
            'stock_quantity' => 0,
            'remote_stock_quantity' => '>10',
            'fast_remote_stock_quantity' => '4',
        ]);
        $path = '/api/partner/v1/catalog/products';
        $normalQuery = 'per_page=100';
        $normalIds = collect($this->withHeaders($this->signedHeaders($path, $normalQuery, 'remote-only-normal'))
            ->get($path.'?'.$normalQuery)->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertNotContains($goodId, $normalIds);

        $incrementalQuery = http_build_query(['updated_since' => now()->subMinute()->toIso8601String(), 'per_page' => 100]);
        $data = collect($this->withHeaders($this->signedHeaders($path, $incrementalQuery, 'remote-only-incremental'))
            ->get($path.'?'.$incrementalQuery)->assertOk()->json('data.data'))->firstWhere('id', $goodId);
        $this->assertSame('>10', $data['remote_stock_quantity']);
        $this->assertSame(4, $data['fast_remote_stock_quantity']);
        $this->assertSame(0, $data['available_quantity']);
        $this->assertFalse($data['can_order']);
    }

    private function insertGood(string $sku, bool $active, $updatedAt, array $overrides = []): int
    {
        return DB::table('shop_goods')->insertGetId(array_merge([
            'name' => $sku, 'slug' => strtolower($sku), 'sku' => $sku, 'description' => 'Description',
            'short_description' => 'Short', 'price' => 1000, 'sale_price' => null,
            'demping_price' => null, 'show_demping' => false, 'stock_quantity' => 5,
            'remote_stock_quantity' => null, 'fast_remote_stock_quantity' => null,
            'is_active' => $active, 'is_show' => true, 'is_preorder' => false, 'supplier' => 'must-not-leak',
            'created_at' => $updatedAt, 'updated_at' => $updatedAt,
        ], $overrides));
    }

    private function insertVariation(int $goodId, string $sku, int $stockQuantity, array $overrides = []): int
    {
        return DB::table('shop_good_variations')->insertGetId(array_merge([
            'good_id' => $goodId, 'name' => $sku, 'sku' => $sku, 'price' => 1000,
            'sale_price' => null, 'demping_price' => null, 'show_demping' => false,
            'stock_quantity' => $stockQuantity, 'remote_stock_quantity' => null,
            'fast_remote_stock_quantity' => null, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function getProduct(int $goodId, string $nonce): array
    {
        $path = '/api/partner/v1/catalog/products/'.$goodId;

        return $this->withHeaders($this->signedHeaders($path, '', $nonce))->get($path)->assertOk()->json('data');
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
        Schema::create('shop_goods', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('slug'); $table->string('sku'); $table->text('description')->nullable(); $table->text('short_description')->nullable(); $table->decimal('price', 12, 2); $table->decimal('sale_price', 12, 2)->nullable(); $table->decimal('demping_price', 12, 2)->nullable(); $table->boolean('show_demping')->default(false); $table->integer('stock_quantity'); $table->string('remote_stock_quantity')->nullable(); $table->string('fast_remote_stock_quantity')->nullable(); $table->boolean('is_active'); $table->boolean('is_show')->default(true); $table->boolean('is_preorder')->default(false); $table->string('supplier')->nullable(); $table->decimal('width', 8, 2)->nullable(); $table->decimal('height', 8, 2)->nullable(); $table->decimal('depth', 8, 2)->nullable(); $table->decimal('weight', 8, 2)->nullable(); $table->timestamps(); });
        Schema::create('shop_categories', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('parent_id')->nullable(); $table->string('name'); $table->string('slug'); $table->string('image')->nullable(); $table->boolean('is_active'); $table->integer('sort_order'); $table->timestamps(); });
        Schema::create('shop_good_categories', function (Blueprint $table): void { $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('category_id'); });
        Schema::create('shop_good_variations', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('good_id'); $table->string('name')->nullable(); $table->string('sku')->nullable(); $table->decimal('price', 12, 2)->default(0); $table->decimal('sale_price', 12, 2)->nullable(); $table->decimal('demping_price', 12, 2)->nullable(); $table->boolean('show_demping')->default(false); $table->integer('stock_quantity')->default(0); $table->string('remote_stock_quantity')->nullable(); $table->string('fast_remote_stock_quantity')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps(); });
        Schema::create('shop_good_images', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('variation_id')->nullable(); $table->string('file_path'); $table->string('alt_text')->nullable(); $table->boolean('is_main')->default(false); $table->integer('sort_order')->default(0); $table->timestamps(); });
        Schema::create('shop_variation_attributes', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('type')->nullable(); $table->timestamps(); });
        Schema::create('shop_variation_attribute_values', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('attribute_id'); $table->string('value'); $table->string('color')->nullable(); $table->boolean('is_active')->default(true); $table->integer('sort_order')->default(0); $table->timestamps(); });
        Schema::create('shop_variation_attributes_values', function (Blueprint $table): void { $table->unsignedBigInteger('variation_id'); $table->unsignedBigInteger('attribute_value_id'); });
        Schema::create('shop_brands', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('slug'); $table->string('logo')->nullable(); $table->boolean('is_active')->default(true); $table->integer('sort_order')->default(0); $table->timestamps(); });
        Schema::create('shop_good_brands', function (Blueprint $table): void { $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('brand_id'); });
        Schema::create('shop_properties', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('slug')->nullable(); $table->string('property_type')->nullable(); $table->integer('sort_order')->default(0); $table->timestamps(); });
        Schema::create('shop_property_values', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('property_id'); $table->string('value'); $table->string('color')->nullable(); $table->boolean('is_active')->default(true); $table->integer('sort_order')->default(0); $table->timestamps(); });
        Schema::create('shop_good_properties', function (Blueprint $table): void { $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('property_id'); $table->unsignedBigInteger('shop_property_value_id')->nullable(); $table->timestamps(); });
        Schema::create('shop_stocks', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('good_id'); $table->unsignedBigInteger('variation_id')->nullable(); $table->integer('quantity'); $table->integer('reserved_quantity')->default(0); $table->timestamps(); });
    }
}
