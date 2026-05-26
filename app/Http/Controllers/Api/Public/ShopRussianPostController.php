<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopRussianPostSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopRussianPostController extends Controller
{
    public function getActiveSettings(): JsonResponse
    {
        $settings = ShopRussianPostSettings::getActive();

        if (! $settings) {
            return response()->json([
                'success' => false,
                'message' => 'Настройки Почты России не найдены',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'sender_city' => $settings->sender_city,
                'sender_postal_code' => $settings->sender_postal_code,
                'default_weight' => (float) ($settings->default_weight ?? 0.5),
                'default_length' => (float) ($settings->default_length ?? 10),
                'default_width' => (float) ($settings->default_width ?? 10),
                'default_height' => (float) ($settings->default_height ?? 10),
            ],
        ]);
    }

    public function getOffices(Request $request): JsonResponse
    {
        $request->validate([
            'city' => 'required|string|min:2|max:255',
            'address' => 'nullable|string|max:255',
        ]);

        try {
            $settings = $this->getSettingsOrFail();
            $query = trim($request->query('address') ?: $request->query('city'));
            $response = Http::withOptions($this->httpOptions())
                ->withHeaders($this->headers($settings))
                ->get('https://otpravka-api.pochta.ru/postoffice/1.0/by-address', [
                    'address' => $query,
                    'top' => 30,
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException($this->extractError($response));
            }

            $data = $response->json();
            $items = is_array($data)
                ? ($data['postoffices'] ?? $data['offices'] ?? $data['data'] ?? $data)
                : [];
            $offices = array_values(array_filter(array_map(fn ($item) => $this->mapOffice($item), $items)));

            return response()->json([
                'success' => true,
                'data' => $offices,
            ]);
        } catch (\Throwable $e) {
            Log::error('RussianPost offices error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения отделений Почты России: '.$e->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    public function getTariffs(Request $request): JsonResponse
    {
        $request->validate([
            'city' => 'required|string|min:2|max:255',
            'delivery_type' => 'required|string|in:address,office',
            'street' => 'nullable|string|max:255',
            'house' => 'nullable|string|max:50',
            'postal_code' => 'nullable|string|max:20',
            'cart_items' => 'nullable',
        ]);

        try {
            $settings = $this->getSettingsOrFail();
            $cartItems = $request->query('cart_items', []);
            if (is_string($cartItems)) {
                $cartItems = json_decode($cartItems, true) ?: [];
            }

            $deliveryType = $request->query('delivery_type');
            $indexTo = preg_replace('/\D+/', '', (string) $request->query('postal_code', ''));

            if ($indexTo === '' && $deliveryType === 'address') {
                $indexTo = $this->normalizeAddressIndex($settings, $this->buildAddress($request));
            }

            if ($indexTo === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось определить индекс получателя для расчета Почты России',
                    'data' => [],
                ]);
            }

            $cargo = $this->calculateCargo($cartItems, $settings);
            $payload = [
                'index-from' => preg_replace('/\D+/', '', (string) $settings->sender_postal_code),
                'index-to' => $indexTo,
                'mail-category' => 'ORDINARY',
                'mail-type' => 'ONLINE_PARCEL',
                'mass' => (int) max(1, round($cargo['weight'] * 1000)),
                'dimension' => [
                    'length' => (int) max(1, round($cargo['length'])),
                    'width' => (int) max(1, round($cargo['width'])),
                    'height' => (int) max(1, round($cargo['height'])),
                ],
                'fragile' => false,
                'inventory' => false,
                'with-order-of-notice' => false,
                'with-simple-notice' => false,
            ];

            if ($deliveryType === 'address') {
                $payload['courier'] = true;
            }

            $response = Http::withOptions($this->httpOptions())
                ->withHeaders($this->headers($settings))
                ->post('https://otpravka-api.pochta.ru/1.0/tariff', $payload);

            if (! $response->successful()) {
                throw new \RuntimeException($this->extractError($response));
            }

            $data = $response->json() ?: [];
            $cost = $this->extractTariffCost($data);

            if ($cost === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Почта России не вернула стоимость доставки для выбранного направления',
                    'data' => [],
                    'meta' => ['response' => $data],
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [[
                    'code' => 'russianpost_'.$deliveryType,
                    'name' => $deliveryType === 'address' ? 'Почта России до адреса' : 'Почта России до отделения',
                    'description' => $deliveryType === 'address' ? $this->buildAddress($request) : 'Индекс отделения: '.$indexTo,
                    'cost' => $cost,
                    'cost_value' => $cost,
                    'type' => $deliveryType,
                    'postal_code' => $indexTo,
                    'period' => $this->extractPeriod($data),
                ]],
                'meta' => [
                    'request_payload' => $payload,
                    'server_response' => $data,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('RussianPost tariff error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка расчета Почты России: '.$e->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    private function getSettingsOrFail(): ShopRussianPostSettings
    {
        $settings = ShopRussianPostSettings::getActive();

        if (! $settings || ! $settings->api_token || ! $settings->login || ! $settings->sender_postal_code) {
            throw new \RuntimeException('Активные настройки Почты России заполнены не полностью');
        }

        return $settings;
    }

    private function headers(ShopRussianPostSettings $settings): array
    {
        return [
            'Authorization' => 'AccessToken '.$settings->api_token,
            'X-User-Authorization' => 'Basic '.base64_encode($settings->login.':'.($settings->password ?? '')),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json;charset=UTF-8',
        ];
    }

    private function httpOptions(): array
    {
        return [
            'verify' => filter_var(config('services.russianpost.verify_ssl', true), FILTER_VALIDATE_BOOLEAN),
            'timeout' => 30,
        ];
    }

    private function normalizeAddressIndex(ShopRussianPostSettings $settings, string $address): string
    {
        $response = Http::withOptions($this->httpOptions())
            ->withHeaders($this->headers($settings))
            ->post('https://otpravka-api.pochta.ru/1.0/clean/address', [[
                'id' => 'checkout',
                'original-address' => $address,
            ]]);

        if (! $response->successful()) {
            return '';
        }

        $data = $response->json();
        $item = is_array($data) ? ($data[0] ?? []) : [];

        return preg_replace('/\D+/', '', (string) ($item['index'] ?? $item['postal-code'] ?? ''));
    }

    private function buildAddress(Request $request): string
    {
        return trim(implode(', ', array_filter([
            $request->query('city'),
            $request->query('street'),
            $request->query('house') ? 'д. '.$request->query('house') : null,
        ])));
    }

    private function mapOffice($item): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $postalCode = $item['postal-code'] ?? $item['index'] ?? null;
        if (! $postalCode) {
            return null;
        }

        return [
            'id' => (string) $postalCode,
            'postal_code' => (string) $postalCode,
            'name' => $item['name'] ?? 'Отделение Почты России',
            'address' => $item['address-source'] ?? $item['address'] ?? $item['full-address'] ?? '',
            'latitude' => $item['latitude'] ?? $item['lat'] ?? null,
            'longitude' => $item['longitude'] ?? $item['lon'] ?? $item['lng'] ?? null,
            'work_time' => $item['schedule'] ?? $item['working-hours'] ?? null,
            'raw' => $item,
        ];
    }

    private function calculateCargo($cartItems, ShopRussianPostSettings $settings): array
    {
        $weight = 0.0;
        $length = 0.0;
        $width = 0.0;
        $height = 0.0;

        foreach ((array) $cartItems as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $fields = $this->getItemDeliveryFields($item, $settings);
            $weight += $fields['weight'] * $quantity;
            $length = max($length, $fields['length']);
            $width = max($width, $fields['width']);
            $height = max($height, $fields['height']);
        }

        return [
            'weight' => $weight ?: (float) ($settings->default_weight ?? 0.5),
            'length' => $length ?: (float) ($settings->default_length ?? 10),
            'width' => $width ?: (float) ($settings->default_width ?? 10),
            'height' => $height ?: (float) ($settings->default_height ?? 10),
        ];
    }

    private function getItemDeliveryFields(array $item, ShopRussianPostSettings $settings): array
    {
        $weight = $this->positiveNumber($item['weight'] ?? null);
        $length = $this->positiveNumber($item['length'] ?? ($item['depth'] ?? null));
        $width = $this->positiveNumber($item['width'] ?? null);
        $height = $this->positiveNumber($item['height'] ?? null);

        if ((! $weight || ! $length || ! $width || ! $height) && ! empty($item['good_id'])) {
            $good = ShopGood::find($item['good_id']);
            if ($good) {
                $weight = $weight ?: $this->positiveNumber($good->weight ?? null);
                $length = $length ?: $this->positiveNumber($good->length ?? ($good->depth ?? null));
                $width = $width ?: $this->positiveNumber($good->width ?? null);
                $height = $height ?: $this->positiveNumber($good->height ?? null);
            }
        }

        return [
            'weight' => $weight ?: (float) ($settings->default_weight ?? 0.5),
            'length' => $length ?: (float) ($settings->default_length ?? 10),
            'width' => $width ?: (float) ($settings->default_width ?? 10),
            'height' => $height ?: (float) ($settings->default_height ?? 10),
        ];
    }

    private function positiveNumber($value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private function extractTariffCost(array $data): ?float
    {
        foreach (['total-rate', 'total-rate-with-vat', 'ground-rate', 'avia-rate'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return round(((float) $data[$key]) / 100, 2);
            }
        }

        return null;
    }

    private function extractPeriod(array $data): ?string
    {
        $min = $data['delivery-time']['min-days'] ?? $data['delivery-time-min'] ?? null;
        $max = $data['delivery-time']['max-days'] ?? $data['delivery-time-max'] ?? null;

        if ($min && $max) {
            return $min === $max ? $min.' дн.' : $min.'-'.$max.' дн.';
        }

        return null;
    }

    private function extractError($response): string
    {
        $data = $response->json();
        if (is_array($data)) {
            return $data['error'] ?? $data['message'] ?? $data['desc'] ?? $response->body();
        }

        return $response->body() ?: 'Ошибка API Почты России';
    }
}
