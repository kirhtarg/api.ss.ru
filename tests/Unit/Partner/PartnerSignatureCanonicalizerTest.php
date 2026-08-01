<?php

namespace Tests\Unit\Partner;

use App\Services\Partner\PartnerSignatureCanonicalizer;
use PHPUnit\Framework\TestCase;

class PartnerSignatureCanonicalizerTest extends TestCase
{
    public function test_parameter_order_does_not_change_canonical_query(): void
    {
        $service = new PartnerSignatureCanonicalizer();
        $this->assertSame($service->query('b=2&a=1'), $service->query('a=1&b=2'));
    }

    public function test_special_characters_cyrillic_spaces_empty_and_repeated_values_are_unambiguous(): void
    {
        $service = new PartnerSignatureCanonicalizer();
        $first = $service->query('q=%D0%B2%D0%B5%D0%BB%D0%BE+%D1%81%D0%B8%D0%BF%D0%B5%D0%B4&tag=b&tag=a&empty=&items%5B%5D=1');
        $second = $service->query('items[]=1&empty=&tag=a&q=%D0%B2%D0%B5%D0%BB%D0%BE%20%D1%81%D0%B8%D0%BF%D0%B5%D0%B4&tag=b');

        $this->assertSame($first, $second);
        $this->assertStringContainsString('%20', $first);
        $this->assertStringContainsString('empty=', $first);
    }

    public function test_changed_value_changes_signature_input(): void
    {
        $service = new PartnerSignatureCanonicalizer();
        $this->assertNotSame(
            $service->request('GET', '/api/partner/v1/catalog/products', 'page=1', '', '1', 'nonce'),
            $service->request('GET', '/api/partner/v1/catalog/products', 'page=2', '', '1', 'nonce'),
        );
    }
}
