<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ShopDellinSettings;
use App\Models\ShopGood;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
                    'create_order_in_account' => (bool) $settings->create_order_in_account,
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

            $city = trim((string) $request->query('city'));
            if (mb_strlen($city) < 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Выберите город из подсказки',
                    'data' => [],
                ], 422);
            }

            $settings = $this->getSettingsOrFail();
            $terminals = $this->getDirectoryTerminalsByCity(
                $settings,
                $request->query('city'),
                $request->query('direction', 'arrival')
            );
            $diagnostics = $this->getTerminalsDiagnostics($terminals);

            return response()->json([
                'success' => true,
                'data' => $terminals,
                'meta' => [
                    'diagnostics' => $diagnostics,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Dellin Terminals Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Сервис Деловых линий временно недоступен',
                'data' => [],
            ], 503);
        }
    }

    public function getTariffs(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'city' => 'required|string|min:2|max:255',
                'street' => 'nullable|string|max:255',
                'house' => 'nullable|string|max:50',
                'delivery_type' => 'nullable|string|in:address,terminal',
                'terminal_id' => 'nullable|string|max:100',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'cart_items' => 'nullable',
            ]);

            $settings = $this->getSettingsOrFail();
            $city = trim((string) $request->query('city'));
            $street = trim((string) $request->query('street', ''));
            $house = trim((string) $request->query('house', ''));
            $deliveryType = $request->query('delivery_type');
            $selectedTerminalId = trim((string) $request->query('terminal_id', ''));
            $latitude = $request->query('latitude');
            $longitude = $request->query('longitude');
            $addressPoint = is_numeric($latitude) && is_numeric($longitude)
                ? ['latitude' => (float) $latitude, 'longitude' => (float) $longitude]
                : null;
            $cartItems = $request->query('cart_items', []);

            if (is_string($cartItems)) {
                $cartItems = json_decode($cartItems, true) ?: [];
            }

            $senderTerminals = $this->fetchTerminals($settings, (string) $settings->sender_city, 'derival');
            $arrivalTerminals = $this->fetchTerminals($settings, $city, 'arrival');
            $directoryArrivalTerminals = $this->getDirectoryTerminalsByCity($settings, $city, 'arrival');
            $senderTerminal = $this->getDefaultTerminal($senderTerminals, (string) $settings->sender_city);
            $arrivalTerminal = $selectedTerminalId !== ''
                ? ($this->findTerminalById($arrivalTerminals, $selectedTerminalId) ?? $this->findTerminalById($directoryArrivalTerminals, $selectedTerminalId))
                : $this->getDefaultTerminal($arrivalTerminals, $city, $addressPoint);
            $isSameSenderCity = $this->normalizeCityName((string) $settings->sender_city) === $this->normalizeCityName($city);

            if (! $senderTerminal || (($deliveryType === null || $deliveryType === 'terminal') && ! $arrivalTerminal)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось найти терминалы Деловых линий для отправки или получения',
                    'data' => [],
                ], 404);
            }

            $cargo = $this->calculateCargo($cartItems, $settings);
            $tariffs = [];

            if (($deliveryType === null || $deliveryType === 'terminal') && $arrivalTerminal) {
                if ($isSameSenderCity) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Доставка Деловыми линиями до терминала внутри города отправления недоступна. Выберите доставку до дома или другой город.',
                        'data' => [],
                    ]);
                }

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
                        'code' => 'dellin_terminal_'.$arrivalTerminal['id'],
                        'name' => 'До терминала Деловых линий',
                        'description' => $arrivalTerminal['name'].' - '.$arrivalTerminal['address'],
                        'cost' => $terminalCost,
                        'cost_value' => $terminalCost,
                        'type' => 'terminal',
                        'terminal' => $arrivalTerminal,
                        'period' => $this->extractPeriod($terminalResult),
                    ];
                }
            }

            if (($deliveryType === null || $deliveryType === 'address') && $street !== '') {
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
                    'selected_arrival_terminal' => $arrivalTerminal,
                    'cargo' => $cargo,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Dellin Get Tariffs Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Сервис Деловых линий временно недоступен',
                'data' => [],
            ], 503);
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
            'direction' => $direction,
            'search' => $city,
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
                'cityID' => $terminal['cityID'] ?? $terminal['city_id'] ?? null,
                'city_code' => $terminal['city_code'] ?? $terminal['cityCode'] ?? null,
                'name' => $terminal['name'] ?? $terminal['terminalName'] ?? 'Терминал Деловых линий',
                'address' => $terminal['address'] ?? $terminal['address_full'] ?? $terminal['fullAddress'] ?? '',
                'latitude' => $this->extractCoordinate($terminal, 'latitude'),
                'longitude' => $this->extractCoordinate($terminal, 'longitude'),
                'default' => (bool) ($terminal['default'] ?? $terminal['isDefault'] ?? false),
                'raw' => $terminal,
            ];
        }, $terminals))) : [];

        $terminals = $this->enrichTerminalsFromDirectory($settings, $terminals);
        $terminals = array_values(array_filter($terminals, function ($terminal) use ($direction) {
            return $this->supportsTerminalDirection($terminal['directory_raw'] ?? $terminal['raw'] ?? $terminal, $direction);
        }));

        return $this->filterTerminalsByCityName($terminals, $city);
    }

    private function enrichTerminalsFromDirectory(ShopDellinSettings $settings, array $terminals): array
    {
        if (empty($terminals)) {
            return $terminals;
        }

        try {
            $directoryById = $this->getTerminalsDirectoryById($settings);
            if (empty($directoryById)) {
                return $terminals;
            }

            return array_map(function ($terminal) use ($directoryById) {
                $directoryTerminal = $directoryById[(string) $terminal['id']] ?? null;
                if (!$directoryTerminal) {
                    return $terminal;
                }

                $terminal['latitude'] = $terminal['latitude'] ?: $this->extractCoordinate($directoryTerminal, 'latitude');
                $terminal['longitude'] = $terminal['longitude'] ?: $this->extractCoordinate($directoryTerminal, 'longitude');
                $terminal['address'] = $terminal['address'] ?: ($directoryTerminal['address'] ?? '');
                $terminal['cityID'] = $terminal['cityID'] ?? ($directoryTerminal['cityID'] ?? null);
                $terminal['city_code'] = $terminal['city_code'] ?? ($directoryTerminal['city_code'] ?? $directoryTerminal['cityCode'] ?? null);
                $terminal['work_time'] = $directoryTerminal['timetable'] ?? $directoryTerminal['worktime'] ?? null;
                $terminal['directory_raw'] = $directoryTerminal;

                return $terminal;
            }, $terminals);
        } catch (\Throwable $e) {
            Log::warning('Dellin terminals directory enrichment failed', [
                'message' => $e->getMessage(),
            ]);

            return $terminals;
        }
    }

    private function getTerminalsDirectoryById(ShopDellinSettings $settings): array
    {
        return Cache::remember('dellin_terminals_directory_by_id_v2_'.md5((string) $settings->appkey), now()->addHours(12), function () use ($settings) {
            $payload = ['appkey' => $settings->appkey];
            if ($settings->session_id) {
                $payload['sessionID'] = $settings->session_id;
            }

            $response = Http::withOptions([
                'verify' => $this->getDellinVerifyOption(),
                'timeout' => 45,
            ])->post('https://api.dellin.ru/v3/public/terminals.json', $payload);

            $data = $response->json();
            if (! $response->successful() || ! is_array($data)) {
                throw new \RuntimeException($this->extractDellinError($data, 'Ошибка получения справочника терминалов Деловых линий'));
            }

            $catalogUrl = $data['url'] ?? null;
            if ($catalogUrl) {
                $catalogResponse = Http::withOptions([
                    'verify' => $this->getDellinVerifyOption(),
                    'timeout' => 60,
                ])->get($catalogUrl);

                $catalogData = $catalogResponse->json();
                if (! $catalogResponse->successful() || ! is_array($catalogData)) {
                    throw new \RuntimeException($this->extractDellinError($catalogData, 'Ошибка загрузки файла справочника терминалов Деловых линий'));
                }

                $data = $catalogData;
            }

            $cities = $data['city'] ?? $data['cities'] ?? $data['data']['city'] ?? [];
            $directory = [];

            foreach ((array) $cities as $city) {
                $cityTerminals = $city['terminals']['terminal']
                    ?? $city['terminals']
                    ?? $city['terminal']
                    ?? [];

                if (isset($cityTerminals['id'])) {
                    $cityTerminals = [$cityTerminals];
                }

                foreach ((array) $cityTerminals as $terminal) {
                    if (! is_array($terminal) || empty($terminal['id'])) {
                        continue;
                    }

                    $terminal['city'] = $terminal['city'] ?? ($city['name'] ?? null);
                    $terminal['cityID'] = $terminal['cityID'] ?? ($city['cityID'] ?? $city['id'] ?? null);
                    $directory[(string) $terminal['id']] = $terminal;
                }
            }

            return $directory;
        });
    }

    private function getTerminalsDiagnostics(array $terminals): array
    {
        $total = count($terminals);
        $withCoordinates = count(array_filter($terminals, fn ($terminal) => ! empty($terminal['latitude']) && ! empty($terminal['longitude'])));

        return [
            'total' => $total,
            'with_coordinates' => $withCoordinates,
            'without_coordinates' => max(0, $total - $withCoordinates),
            'source_note' => 'request_terminals не возвращает координаты; координаты подставляются из v3/public/terminals.json по id терминала',
        ];
    }

    private function getDirectoryTerminalsByCity(ShopDellinSettings $settings, string $city, string $direction = 'arrival'): array
    {
        $normalizedCity = $this->normalizeCityName($city);
        $cityCenter = $this->getKnownCityCenter($normalizedCity);
        $isMoscowRegionQuery = $this->isMoscowRegionQuery($normalizedCity);
        $maxDistance = $this->getTerminalRadiusKm($normalizedCity);
        $directoryById = $this->getTerminalsDirectoryById($settings);
        $terminals = [];

        foreach ($directoryById as $terminal) {
            $terminalCity = $this->normalizeCityName((string) ($terminal['city'] ?? ''));
            $latitude = $this->extractCoordinate($terminal, 'latitude');
            $longitude = $this->extractCoordinate($terminal, 'longitude');

            if ($cityCenter && $latitude && $longitude) {
                $distance = $this->distanceKm($cityCenter['latitude'], $cityCenter['longitude'], $latitude, $longitude);
                if ($distance > $maxDistance) {
                    continue;
                }
            } else {
                if ($isMoscowRegionQuery) {
                    if (! $this->isMoscowRegionTerminal($terminal)) {
                        continue;
                    }
                } elseif ($terminalCity !== $normalizedCity) {
                    continue;
                }
            }

            $id = $terminal['id'] ?? null;
            if (!$id) {
                continue;
            }

            if (! $this->supportsTerminalDirection($terminal, $direction)) {
                continue;
            }

            $terminals[] = [
                'id' => $id,
                'cityID' => $terminal['cityID'] ?? null,
                'city_code' => $terminal['city_code'] ?? $terminal['cityCode'] ?? null,
                'name' => $terminal['name'] ?? $terminal['terminalName'] ?? 'Терминал Деловых линий',
                'address' => $terminal['address'] ?? '',
                'latitude' => $latitude,
                'longitude' => $longitude,
                'default' => (bool) ($terminal['default'] ?? $terminal['isDefault'] ?? false),
                'receive_cargo' => $terminal['receiveCargo'] ?? null,
                'giveout_cargo' => $terminal['giveoutCargo'] ?? null,
                'work_time' => $terminal['timetable'] ?? $terminal['worktime'] ?? null,
                'raw' => $terminal,
            ];
        }

        return array_values($terminals);
    }

    private function supportsTerminalDirection(array $terminal, string $direction): bool
    {
        $direction = $direction === 'derival' ? 'derival' : 'arrival';
        $keys = $direction === 'arrival'
            ? ['giveoutCargo', 'giveout_cargo', 'giveout']
            : ['receiveCargo', 'receive_cargo', 'receive'];

        foreach ($keys as $key) {
            if (array_key_exists($key, $terminal)) {
                return filter_var($terminal[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return true;
    }

    private function getKnownCityCenter(string $normalizedCity): ?array
    {
        if ($this->isMoscowRegionQuery($normalizedCity)) {
            return ['latitude' => 55.7558, 'longitude' => 37.6176];
        }

        if ($this->isSaintPetersburgQuery($normalizedCity)) {
            return ['latitude' => 59.9311, 'longitude' => 30.3609];
        }

        return null;
    }

    private function getTerminalRadiusKm(string $normalizedCity): int
    {
        if ($this->isMoscowRegionQuery($normalizedCity)) {
            return 140;
        }

        if ($this->isSaintPetersburgQuery($normalizedCity)) {
            return 45;
        }

        return 80;
    }

    private function isSaintPetersburgQuery(string $normalizedCity): bool
    {
        return in_array($normalizedCity, [
            'санкт петербург',
            'санкт-петербург',
            'спб',
            'петербург',
        ], true);
    }

    private function isMoscowRegionQuery(string $normalizedCity): bool
    {
        if ($normalizedCity === 'москва' || $normalizedCity === 'moscow') {
            return true;
        }

        $moscowRegionCities = [
            'химки',
            'балашиха',
            'мытищи',
            'люберцы',
            'красногорск',
            'одинцово',
            'долгопрудный',
            'реутов',
            'видное',
            'королев',
            'подольск',
            'домодедово',
            'щелково',
            'лобня',
            'зеленоград',
            'котельники',
            'дзержинский',
            'ивантеевка',
            'пушкино',
            'электросталь',
            'ногинск',
            'серпухов',
            'коломна',
            'воскресенск',
            'раменское',
            'жуковский',
            'фрязино',
            'дедовск',
            'истра',
            'клин',
            'дмитров',
            'чехов',
            'наро фоминск',
            'наро-фоминск',
            'сергиев посад',
            'орехово зуево',
            'орехово-зуево',
        ];

        return in_array($normalizedCity, $moscowRegionCities, true);
    }

    private function isMoscowRegionTerminal(array $terminal): bool
    {
        $city = $this->normalizeCityName((string) ($terminal['city'] ?? ''));
        $region = $this->normalizeCityName((string) (
            $terminal['region'] ??
            $terminal['subject'] ??
            $terminal['regionName'] ??
            $terminal['region_name'] ??
            ''
        ));
        $address = $this->normalizeCityName((string) ($terminal['address'] ?? ''));

        if ($city === 'москва' || $region === 'московская' || str_contains($region, 'московск')) {
            return true;
        }

        return str_contains($address, 'москва')
            || str_contains($address, 'московская')
            || str_contains($address, 'московск');
    }

    private function filterTerminalsByCityName(array $terminals, string $city): array
    {
        $normalizedCity = $this->normalizeCityName($city);
        if ($normalizedCity === '') {
            return $terminals;
        }

        $filtered = array_values(array_filter($terminals, function ($terminal) use ($normalizedCity) {
            $terminalCity = $this->normalizeCityName((string) ($terminal['raw']['city'] ?? $terminal['city'] ?? ''));

            return $terminalCity !== '' && $terminalCity === $normalizedCity;
        }));

        return ! empty($filtered) ? $filtered : $terminals;
    }

    private function normalizeCityName(string $city): string
    {
        $city = mb_strtolower(trim($city));
        $city = preg_replace('/\b(г|город|обл|область|край|респ|республика|ао)\.?\b/u', '', $city);
        $city = preg_replace('/[^а-яёa-z0-9\- ]/u', ' ', $city);
        $city = preg_replace('/\s+/u', ' ', $city);

        return trim((string) $city);
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

    private function getDefaultTerminal(array $terminals, ?string $city = null, ?array $nearestTo = null): ?array
    {
        if ($nearestTo) {
            $nearest = collect($terminals)
                ->filter(fn ($terminal) => ! empty($terminal['latitude']) && ! empty($terminal['longitude']))
                ->sortBy(fn ($terminal) => $this->distanceKm(
                    (float) $nearestTo['latitude'],
                    (float) $nearestTo['longitude'],
                    (float) $terminal['latitude'],
                    (float) $terminal['longitude']
                ))
                ->first();

            if ($nearest) {
                return $nearest;
            }
        }

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

    private function findTerminalById(array $terminals, string $terminalId): ?array
    {
        foreach ($terminals as $terminal) {
            if ((string) ($terminal['id'] ?? '') === $terminalId) {
                return $terminal;
            }
        }

        return null;
    }

    private function extractCoordinate(array $terminal, string $type): ?float
    {
        $keys = $type === 'latitude'
            ? ['latitude', 'lat', 'Latitude', 'LAT']
            : ['longitude', 'lon', 'lng', 'Longitude', 'LON', 'LNG'];

        foreach ($keys as $key) {
            if (isset($terminal[$key]) && is_numeric($terminal[$key])) {
                return (float) $terminal[$key];
            }
        }

        $coordinateContainers = [
            $terminal['coordinates'] ?? null,
            $terminal['location'] ?? null,
            $terminal['geo'] ?? null,
            $terminal['maps'] ?? null,
            $terminal['map'] ?? null,
        ];

        foreach ($coordinateContainers as $container) {
            if (! is_array($container)) {
                continue;
            }

            if (isset($container['map']) && is_array($container['map'])) {
                $nestedMaps = isset($container['map']['latitude']) || isset($container['map']['longitude'])
                    ? [$container['map']]
                    : (array) $container['map'];

                foreach ($nestedMaps as $nestedMap) {
                    if (! is_array($nestedMap)) {
                        continue;
                    }

                    foreach ($keys as $key) {
                        if (isset($nestedMap[$key]) && is_numeric($nestedMap[$key])) {
                            return (float) $nestedMap[$key];
                        }
                    }
                }
            }

            foreach ($keys as $key) {
                if (isset($container[$key]) && is_numeric($container[$key])) {
                    return (float) $container[$key];
                }
            }
        }

        return null;
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $earthRadius * atan2(sqrt($a), sqrt(1 - $a));
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
