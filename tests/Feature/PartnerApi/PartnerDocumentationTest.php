<?php

namespace Tests\Feature\PartnerApi;

use Tests\TestCase;

class PartnerDocumentationTest extends TestCase
{
    public function test_openapi_31_document_is_public_and_describes_partner_security(): void
    {
        $this->getJson('/api/partner/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.0')
            ->assertJsonPath('components.securitySchemes.PartnerSignature.name', 'X-Partner-Signature')
            ->assertJsonStructure(['paths' => ['/orders', '/orders/{externalOrderId}', '/checkout/options']]);
    }

    public function test_interactive_reference_is_available(): void
    {
        $this->get('/api/partner/v1/docs')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy')
            ->assertSee('@scalar/api-reference@1.63.0', false)
            ->assertSee('/api/partner/v1/openapi.json', false);
    }
}
