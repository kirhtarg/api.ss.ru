<?php

namespace Tests\Feature\PartnerApi;

use App\Http\Controllers\Api\Admin\PartnerApi\CredentialController;
use App\Models\Partner;
use App\Models\PartnerApiCredential;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerCredentialTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('partners', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->decimal('commission_rate', 7, 4)->default(0);
            $table->string('commission_status')->default('after_completed');
            $table->text('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->json('allowed_ips')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('partner_api_credentials', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_id');
            $table->string('key_id')->unique();
            $table->text('secret');
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('partner_api_credentials');
        Schema::dropIfExists('partners');
        parent::tearDown();
    }

    public function test_secret_is_returned_on_issue_but_hidden_by_model_serialization(): void
    {
        $partner = Partner::create(['public_id' => (string) Str::uuid(), 'code' => 'credential-test', 'name' => 'Credential Test']);
        $request = Request::create('/admin/partner-api/partners/1/credentials', 'POST', ['scopes' => ['catalog:read']]);

        $response = app(CredentialController::class)->store($request, $partner);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($payload['data']['secret_visible_once']);
        $this->assertNotEmpty($payload['data']['secret']);
        $credential = PartnerApiCredential::firstOrFail();
        $this->assertSame($payload['data']['secret'], $credential->secret);
        $this->assertArrayNotHasKey('secret', $credential->toArray());
    }

    public function test_active_key_is_revoked_before_it_can_be_permanently_deleted(): void
    {
        $partner = Partner::create(['public_id' => (string) Str::uuid(), 'code' => 'delete-test', 'name' => 'Delete Test']);
        $credential = PartnerApiCredential::create([
            'partner_id' => $partner->id,
            'key_id' => 'pk_delete_test',
            'secret' => Str::random(64),
            'scopes' => ['catalog:read'],
        ]);
        $request = Request::create('/admin/partner-api/credentials/'.$credential->id, 'DELETE');
        $controller = app(CredentialController::class);

        $revokeResponse = $controller->destroy($request, $credential);
        $this->assertSame('API-ключ отозван', $revokeResponse->getData(true)['message']);
        $this->assertNotNull($credential->fresh()?->revoked_at);

        $deleteResponse = $controller->destroy($request, $credential->fresh());
        $this->assertSame('Отозванный API-ключ удалён', $deleteResponse->getData(true)['message']);
        $this->assertDatabaseMissing('partner_api_credentials', ['id' => $credential->id]);
    }
}
