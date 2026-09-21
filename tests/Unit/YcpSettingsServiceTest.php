<?php

namespace Tests\Unit;

use App\Services\YcpSettingsService;
use App\Models\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class YcpSettingsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('settings');
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->text('default_value')->nullable();
            $table->string('type')->default('string');
            $table->string('group')->default('general');
            $table->text('description')->nullable();
            $table->integer('image_width')->nullable();
            $table->integer('image_height')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('settings');

        parent::tearDown();
    }

    public function test_empty_ycp_settings_default_to_merchant_delivery_and_can_be_saved(): void
    {
        $service = app(YcpSettingsService::class);

        Setting::create([
            'key' => 'enabled',
            'name' => 'Existing global enabled setting',
            'value' => '1',
            'type' => 'boolean',
            'group' => 'general',
        ]);

        self::assertSame('merchant', $service->get()['delivery_mode']);

        $saved = $service->save([
            'enabled' => true,
            'access_token' => str_repeat('a', 64),
            'api_token' => 'ycp-issued-api-secret',
            'delivery_mode' => 'merchant',
        ]);

        self::assertTrue($saved['enabled']);
        self::assertSame('merchant', $saved['delivery_mode']);
        self::assertSame(str_repeat('a', 64), $saved['access_token']);
        self::assertSame('ycp-issued-api-secret', $saved['api_token']);
        self::assertNotSame('ycp-issued-api-secret', Setting::query()->where('key', 'ycp_api_token')->value('value'));
        self::assertSame('1', Setting::query()->where('key', 'enabled')->value('value'));
        self::assertSame(4, Setting::query()->where('group', 'ycp')->count());

        $savedAgain = $service->save([
            'enabled' => true,
            'access_token' => '',
            'api_token' => '',
            'delivery_mode' => 'merchant',
        ]);
        self::assertSame('ycp-issued-api-secret', $savedAgain['api_token']);
    }
}
