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
            ->assertJsonPath('info.version', '1.1.0')
            ->assertJsonPath('components.securitySchemes.PartnerSignature.name', 'X-Partner-Signature')
            ->assertJsonPath('components.parameters.IdempotencyKey.schema.minLength', 8)
            ->assertJsonPath('components.parameters.IdempotencyKey.schema.maxLength', 128)
            ->assertJsonPath('components.parameters.IdempotencyKey.schema.pattern', '^[A-Za-z0-9._:-]+$')
            ->assertJsonPath('components.schemas.Product.additionalProperties', false)
            ->assertJsonPath('components.schemas.Variation.additionalProperties', false)
            ->assertJsonPath('components.schemas.Category.additionalProperties', false)
            ->assertJsonPath('components.schemas.Brand.additionalProperties', false)
            ->assertJsonPath('components.schemas.Brand.properties.logo_url.format', 'uri')
            ->assertJsonPath('webhooks.partnerEvent.post.parameters.0.name', 'X-Partner-Delivery')
            ->assertJsonStructure(['paths' => [
                '/orders', '/orders/{externalOrderId}', '/orders/{externalOrderId}/cancel',
                '/checkout/options', '/checkout/quote', '/delivery/cities', '/delivery/tariffs',
                '/delivery/pickup-points', '/commissions',
            ]]);
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

    public function test_operational_endpoints_have_strict_success_schemas_and_idempotency_headers(): void
    {
        $document = json_decode(file_get_contents(resource_path('openapi/partner-v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $operations = [
            ['/delivery/cities', 'get'], ['/delivery/tariffs', 'post'],
            ['/delivery/pickup-points', 'get'], ['/delivery/pickup-points/validate', 'post'],
            ['/orders', 'get'], ['/orders/{externalOrderId}/cancel', 'post'],
            ['/commissions', 'get'], ['/orders/{externalOrderId}/payment', 'post'],
        ];
        foreach ($operations as [$path, $method]) {
            $this->assertArrayHasKey($path, $document['paths']);
            $success = $document['paths'][$path][$method]['responses']['200']
                ?? $document['paths'][$path][$method]['responses']['201']
                ?? null;
            $this->assertNotNull($success, $path);
            $this->assertArrayHasKey('schema', $success['content']['application/json'], $path);
        }
        foreach (['/orders', '/orders/{externalOrderId}/cancel', '/orders/{externalOrderId}/payment'] as $path) {
            $parameters = $document['paths'][$path]['post']['parameters'] ?? [];
            $this->assertTrue(collect($parameters)->contains(
                fn (array $parameter): bool => ($parameter['$ref'] ?? null) === '#/components/parameters/IdempotencyKey',
            ), $path.' must document Idempotency-Key');
        }
        foreach (['QuoteItem', 'QuoteDelivery', 'QuoteTotals', 'DeliveryCity', 'DeliveryTariff', 'PickupPoint', 'PickupPointValidation', 'Order', 'OrderPage', 'Commission', 'CommissionPage', 'Payment', 'ErrorResponse'] as $schema) {
            $this->assertArrayHasKey($schema, $document['components']['schemas']);
        }
        foreach (['Product', 'Variation'] as $schema) {
            foreach (['stock_quantity', 'remote_stock_quantity', 'fast_remote_stock_quantity', 'is_preorder', 'purchase_mode', 'can_order', 'can_preorder'] as $field) {
                $this->assertContains($field, $document['components']['schemas'][$schema]['required']);
                $this->assertArrayHasKey($field, $document['components']['schemas'][$schema]['properties']);
            }
        }
        $this->assertSame(['id', 'name', 'slug', 'logo_url'], $document['components']['schemas']['Brand']['required']);
        $raw = json_encode($document, JSON_THROW_ON_ERROR);
        foreach (['purchase_price', 'supplier_data', 'internal_comment'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }
}
