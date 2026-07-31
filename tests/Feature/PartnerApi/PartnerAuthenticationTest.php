<?php

namespace Tests\Feature\PartnerApi;

use App\Models\Partner;
use App\Models\PartnerApiCredential;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerAuthenticationTest extends TestCase
{
    private PartnerApiCredential $credential;

    private string $secret = 'partner-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        Cache::flush();

        $partner = Partner::create([
            'public_id' => (string) Str::uuid(),
            'code' => 'test-partner',
            'name' => 'Test Partner',
            'is_active' => true,
            'commission_rate' => 0.1,
        ]);
        $this->credential = $partner->credentials()->create([
            'key_id' => 'pk_test_authentication',
            'secret' => $this->secret,
            'scopes' => ['catalog:read'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('partner_api_request_logs');
        Schema::dropIfExists('partner_api_credentials');
        Schema::dropIfExists('partners');
        parent::tearDown();
    }

    public function test_valid_hmac_signature_allows_request_and_writes_request_log(): void
    {
        $headers = $this->signedHeaders('GET', '/api/partner/v1/health', '', 'nonce-valid');

        $this->withHeaders($headers)->get('/api/partner/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertHeader('X-Request-ID');

        $this->assertDatabaseHas('partner_api_request_logs', [
            'partner_id' => $this->credential->partner_id,
            'response_status' => 200,
            'path' => '/api/partner/v1/health',
        ]);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $headers = $this->signedHeaders('GET', '/api/partner/v1/health', '', 'nonce-invalid');
        $headers['X-Partner-Signature'] = str_repeat('0', 64);

        $this->withHeaders($headers)->get('/api/partner/v1/health')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_signature');
    }

    public function test_nonce_cannot_be_replayed(): void
    {
        $headers = $this->signedHeaders('GET', '/api/partner/v1/health', '', 'nonce-replay');

        $this->withHeaders($headers)->get('/api/partner/v1/health')->assertOk();
        $this->withHeaders($headers)->get('/api/partner/v1/health')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'replayed_request');
    }

    public function test_missing_scope_is_rejected_before_checkout_handler(): void
    {
        $path = '/api/partner/v1/checkout/options';

        $this->withHeaders($this->signedHeaders('GET', $path, '', 'nonce-scope'))->get($path)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_scope');
    }

    private function signedHeaders(string $method, string $path, string $body, string $nonce): array
    {
        $timestamp = (string) now()->timestamp;
        $canonical = implode("\n", [$method, $path, '', hash('sha256', $body), $timestamp, $nonce]);

        return [
            'X-Partner-Key' => $this->credential->key_id,
            'X-Partner-Timestamp' => $timestamp,
            'X-Partner-Nonce' => $nonce,
            'X-Partner-Signature' => hash_hmac('sha256', $canonical, $this->secret),
        ];
    }

    private function createTables(): void
    {
        Schema::create('partners', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code')->unique();
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
            $table->foreignId('partner_id');
            $table->string('key_id')->unique();
            $table->text('secret');
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('partner_api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->string('request_id')->unique();
            $table->string('method');
            $table->string('path');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip_address')->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
        });
    }
}
