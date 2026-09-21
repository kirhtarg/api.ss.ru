<?php

namespace Tests\Unit;

use App\Services\YcpSettingsService;
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

        self::assertSame('merchant', $service->get()['delivery_mode']);

        $saved = $service->save([
            'enabled' => true,
            'access_token' => str_repeat('a', 64),
            'delivery_mode' => 'merchant',
        ]);

        self::assertTrue($saved['enabled']);
        self::assertSame('merchant', $saved['delivery_mode']);
        self::assertSame(str_repeat('a', 64), $saved['access_token']);
    }
}
