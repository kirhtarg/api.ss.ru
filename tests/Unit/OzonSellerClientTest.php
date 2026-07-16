<?php

namespace Tests\Unit;

use App\Models\ShopOzonAccount;
use App\Services\Ozon\OzonSellerClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OzonSellerClientTest extends TestCase
{
    public function test_empty_post_payload_is_sent_as_json_object(): void
    {
        Http::fake(['https://api-seller.ozon.ru/*' => Http::response(['result' => []])]);
        $account = new ShopOzonAccount([
            'client_id' => '12345',
            'api_key' => 'secret',
            'api_url' => 'https://api-seller.ozon.ru',
        ]);

        (new OzonSellerClient($account))->post('/v4/product/info/limit');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api-seller.ozon.ru/v4/product/info/limit'
                && $request->body() === '{}'
                && $request->header('Client-Id')[0] === '12345'
                && $request->header('Api-Key')[0] === 'secret';
        });
    }
}
