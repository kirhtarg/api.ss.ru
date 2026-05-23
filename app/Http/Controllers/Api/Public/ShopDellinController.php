<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopDellinSettings;
use App\Models\ShopGood;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopDellinController extends Controller
{
    public function getActiveSettings(): JsonResponse
    {
        try {
            $settings = ShopDellinSettings::getActive();

            if (! $settings) {
                return response()->json([
                    'success' => false,
                    'message' => 'Настройки Деловых линий не найдены',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'sender_city' => $settings->sender_city,
                    'default_weight' => (float) ($settings->default_weight ?? 0.5),
                    'default_length' => (float) ($settings->default_length ?? 10),
                    'default_width' => (float) ($settings->default_width ?? 10),
                    'default_height' => (float) ($settings->default_height ?? 10),
                    'cash_on_delivery_enabled' => (bool) $settings->cash_on_delivery_enabled,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Dellin Active Settings Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения настроек Деловых линий',
            ], 500);
        }
    }

    public function getTerminals(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'city' => 'required|string|min:2|max:255',
                'direction' => 'nullable|string|in:derival,arrival',
            ]);

            $settings = $this->getSettingsOrFail();
            $terminals = $this->fetchTerminals(
                $settings,
                $request->query('city'),
                $request->query('direction', 'arrival')
            );

            return response()->json([
                'success' => true,
                'data' => $terminals,
            ]);
        } catch (\Throwable $e) {
            Log::error('Dellin Terminals Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    public function getTariffs(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'city' => 'required|string|min:2|max:255',
                'street' => 'nullable|string|max:255',
                'house' => 'nullable|string|max:50',
                'cart_items' => 'nullable',
            ]);

            $settings = $this->getSettingsOrFail();
            $city = trim((string) $request->query('city'));
            $street = trim((string) $request->query('street', ''));
            $house = trim((string) $request->query('house', ''));
            $cartItems = $request->query('cart_items', []);

            if (is_string($cartItems)) {
                $cartItems = json_decode($cartItems, true) ?: [];
            }

            $senderTerminals = $this->fetchTerminals($settings, (string) $settings->sender_city, 'derival');
            $arrivalTerminals = $this->fetchTerminals($settings, $city, 'arrival');
            $senderTerminal = $this->getDefaultTerminal($senderTerminals, (string) $settings->sender_city);
            $arrivalTerminal = $this->getDefaultTerminal($arrivalTerminals, $city);

            if (! $senderTerminal || ! $arrivalTerminal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось найти терминалы Деловых линий для отправки или получения',
                    'data' => [],
                ], 404);
            }

            $cargo = $this->calculateCargo($cartItems, $settings);
            $tariffs = [];

            $terminalResult = $this->calculate($settings, [
                'delivery' => [
                    'deliveryType' => ['type' => 'auto'],
                    'derival' => [
                        'produceDate' => $this->getNextProduceDate(),
                        'variant' => 'terminal',
                        'terminalID' => (string) $senderTerminal['id'],
                    ],
                    'arrival' => [
                        'variant' => 'terminal',
                        'terminalID' => (string) $arrivalTerminal['id'],
                    ],
                ],
                'cargo' => $cargo,
            ]);

            $terminalCost = $this->extractPrice($terminalResult);
            if ($terminalCost !== null) {
                $tariffs[] = [
                    'code' => 'dellin_terminal',
                    'name' => 'До терминала Деловых линий',
                    'description' => $arrivalTerminal['name'].' - '.$arrivalTerminal['address'],
                    'cost' => $terminalCost,
                    'cost_value' => $terminalCost,
                    'type' => 'terminal',
                    'terminal' => $arrivalTerminal,
                    'period' => $this->extractPeriod($terminalResult),
                ];
            }

            if ($street !== '') {
                $addressSearch = $city.', '.$street.($house !== '' ? ', '.$house : '');
                $addressResult = $this->calculate($settings, [
                    'delivery' => [
                        'deliveryType' => ['type' => 'auto'],
                        'derival' => [
                            'produceDate' => $this->getNextProduceDate(),
                            'variant' => 'terminal',
                            'terminalID' => (string) $senderTerminal['id'],
                        ],
                        'arrival' => [
                            'variant' => 'address',
                            'address' => [
                                'search' => $addressSearch,
                            ],
                            'time' => [
                                'worktimeStart' => '09:00',
                                'worktimeEnd' => '18:00',
                            ],
                        ],
                    ],
                    'cargo' => $cargo,
                ]);

                $addressCost = $this->extractPrice($addressResult);
                if ($addressCost !== null) {
                    $tariffs[] = [
                        'code' => 'dellin_address',
                        'name' => 'До адреса получателя',
                        'description' => $addressSearch,
                        'cost' => $addressCost,
                        'cost_value' => $addressCost,
                        'type' => 'address',
                        'address' => $addressSearch,
                        'period' => $this->extractPeriod($addressResult),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => $tariffs,
                'meta' => [
                    'sender_terminal' => $senderTerminal,
                    'arrival_terminals' => $arrivalTerminals,
                    'cargo' => $cargo,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Dellin Get Tariffs Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка расчета Деловых линий: '.$e->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    private function getSettingsOrFail(): ShopDellinSettings
    {
        $settings = ShopDellinSettings::getActive();

        if (! $settings || ! $settings->appkey) {
            throw new \RuntimeException('Активные настройки Деловых линий не найдены');
        }

        if (! $settings->sender_city) {
            throw new \RuntimeException('В настройках Деловых линий не указан город отправителя');
        }

        return $settings;
    }

    private function fetchTerminals(ShopDellinSettings $settings, string $city, string $direction): array
    {
        $payload = [
            'appkey' => $settings->appkey,
            'search' => $city,
            'direction' => $direction,
        ];

        if ($settings->session_id) {
            $payload['sessionID'] = $settings->session_id;
        }

        $response = Http::withOptions([
            'verify' => $this->getDellinVerifyOption(),
            'timeout' => 30,
        ])->post('https://api.dellin.ru/v1/public/request_terminals.json', $payload);

        $data = $response->json();
        if (! $response->successful()) {
            throw new \RuntimeException($this->extractDellinError($data, 'Ошибка получения терминалов Деловых линий'));
        }

        $terminals = $data['terminals'] ?? $data['data']['terminals'] ?? $data['data'] ?? [];
        $terminals = is_array($terminals) ? array_values(array_filter(array_map(function ($terminal) {
            if (! is_array($terminal)) {
                return null;
            }

            $id = $terminal['id'] ?? $terminal['terminalID'] ?? $terminal['terminal_id'] ?? null;
            if (! $id) {
                return null;
            }

            return [
                'id' => $id,
                'name' => $terminal['name'] ?? $terminal['terminalName'] ?? 'Терминал Деловых линий',
                'address' => $terminal['address'] ?? $terminal['address_full'] ?? $terminal['fullAddress'] ?? '',
                'default' => (bool) ($terminal['default'] ?? $terminal['isDefault'] ?? false),
                'raw' => $terminal,
            ];
        }, $terminals))) : [];

        return $terminals;
    }

    private function calculate(ShopDellinSettings $settings, array $payload): array
    {
        $payload['appkey'] = $settings->appkey;
        if ($settings->session_id) {
            $payload['sessionID'] = $settings->session_id;
        }

        $response = Http::withOptions([
            'verify' => $this->getDellinVerifyOption(),
            'timeout' => 30,
        ])->post('https://api.dellin.ru/v2/calculator.json', $payload);

        $data = $response->json();
        if (! $response->successful()) {
            throw new \RuntimeException($this->extractDellinError($data, 'Ошибка расчета доставки Деловыми линиями'));
        }

        return is_array($data) ? $data : [];
    }

    private function calculateCargo($cartItems, ShopDellinSettings $settings): array
    {
        $totalWeight = 0.0;
        $totalVolume = 0.0;
        $maxLength = 0.0;
        $maxWidth = 0.0;
        $maxHeight = 0.0;
        $quantityTotal = 0;

        foreach ((array) $cartItems as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $fields = $this->getItemDeliveryFields($item, $settings);

            $weight = $fields['weight'];
            $length = $fields['length'];
            $width = $fields['width'];
            $height = $fields['height'];

            $totalWeight += $weight * $quantity;
            $totalVolume += ($length / 100) * ($width / 100) * ($height / 100) * $quantity;
            $maxLength = max($maxLength, $length / 100);
            $maxWidth = max($maxWidth, $width / 100);
            $maxHeight = max($maxHeight, $height / 100);
            $quantityTotal += $quantity;
        }

        if ($quantityTotal === 0) {
            $quantityTotal = 1;
            $weight = (float) ($settings->default_weight ?? 0.5);
            $length = (float) ($settings->default_length ?? 10);
            $width = (float) ($settings->default_width ?? 10);
            $height = (float) ($settings->default_height ?? 10);
            $totalWeight = $weight;
            $totalVolume = ($length / 100) * ($width / 100) * ($height / 100);
            $maxLength = $length / 100;
            $maxWidth = $width / 100;
            $maxHeight = $height / 100;
        }

        return [
            'quantity' => $quantityTotal,
            'length' => max(0.01, round($maxLength, 3)),
            'width' => max(0.01, round($maxWidth, 3)),
            'height' => max(0.01, round($maxHeight, 3)),
            'weight' => max(0.1, round($totalWeight, 3)),
            'totalVolume' => max(0.001, round($totalVolume, 3)),
            'totalWeight' => max(0.1, round($totalWeight, 3)),
            'hazardClass' => 0,
            'insurance' => [
                'statedValue' => 1,
                'term' => true,
            ],
        ];
    }

    private function getItemDeliveryFields(array $item, ShopDellinSettings $settings): array
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

    private function getDefaultTerminal(array $terminals, ?string $city = null): ?array
    {
        $normalizedCity = mb_strtolower(trim((string) $city));

        if ($normalizedCity !== '') {
            foreach ($terminals as $terminal) {
                $terminalCity = mb_strtolower(trim((string) ($terminal['raw']['city'] ?? '')));
                if ($terminalCity === $normalizedCity) {
                    return $terminal;
                }
            }
        }

        foreach ($terminals as $terminal) {
            if (! empty($terminal['default'])) {
                return $terminal;
            }
        }

        return $terminals[0] ?? null;
    }

    private function extractPrice(array $data): ?float
    {
        $raw = $data['data']['price']
            ?? $data['data']['auto']['price']
            ?? $data['data']['express']['price']
            ?? $data['price']
            ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        return round((float) str_replace(',', '.', (string) $raw), 2);
    }

    private function extractPeriod(array $data): ?string
    {
        $period = $data['data']['term']
            ?? $data['data']['auto']['term']
            ?? $data['data']['period']
            ?? null;

        return $period ? (string) $period : null;
    }

    private function positiveNumber($value): ?float
    {
        $number = is_numeric($value) ? (float) $value : null;

        return $number && $number > 0 ? $number : null;
    }

    private function extractDellinError($data, string $fallback): string
    {
        if (! is_array($data)) {
            return $fallback;
        }

        return $data['errors'][0]['detail']
            ?? $data['errors'][0]['message']
            ?? $data['error']['message']
            ?? $data['message']
            ?? $fallback;
    }

    private function getDellinVerifyOption()
    {
        $caBundle = config('services.dellin.ca_bundle_path');
        if ($caBundle && file_exists($caBundle)) {
            return $caBundle;
        }

        return filter_var(config('services.dellin.verify_ssl', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function getNextProduceDate(): string
    {
        $date = now()->addDays(3);

        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $date->format('Y-m-d');
    }
}
