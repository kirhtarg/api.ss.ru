<?php

namespace App\Http\Controllers;

use App\Helpers\PriceHelper;
use App\Models\ShopCdekSettings;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopOrder;
use App\Services\CdekService;
use App\Services\DeliveryPackageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SdekOrderController extends Controller
{
    protected $cdekService;

    public function __construct(CdekService $cdekService)
    {
        $this->cdekService = $cdekService;
    }

    /**
     * Создать заказ в СДЭК
     */
    public function createOrder(Request $request)
    {
        try {

            // Валидация входных данных
            $validator = Validator::make($request->all(), [
                'order_number' => 'required|string|max:255',
                'tariff_code' => 'required|integer',
                'delivery_type' => 'nullable|in:pvz,door',
                'customer_name' => 'required|string|max:255',
                'customer_email' => 'required|email|max:255',
                'customer_phone' => 'nullable|string|max:20',
                'customer_company' => 'nullable|string|max:255',
                // city_code может приходить числом или строкой
                'city_code' => 'required',
                // адрес обязательно проверяем отдельно в зависимости от pvz_code
                'delivery_address' => 'nullable',
                'pvz_code' => 'nullable|string|max:50',
                'comment' => 'nullable|string|max:1000',
                'packages' => 'required|array|min:1',
                'packages.*.number' => 'required|string|max:100',
                'packages.*.weight' => 'required|numeric|min:1',
                'packages.*.length' => 'required|numeric|min:1',
                'packages.*.width' => 'required|numeric|min:1',
                'packages.*.height' => 'required|numeric|min:1',
                'packages.*.comment' => 'nullable|string|max:500',
                'services' => 'nullable|array',
                'delivery_recipient_cost' => 'nullable|array',
                'delivery_recipient_cost.value' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                Log::error('SdekOrderController: Validation failed', $validator->errors()->toArray());

                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации данных',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $pvzCode = $request->input('pvz_code');
            $deliveryAddress = $request->input('delivery_address');
            if (! $pvzCode) {
                if (! is_string($deliveryAddress) || trim($deliveryAddress) === '') {
                    $message = 'Не указан адрес доставки';
                    Log::error('SdekOrderController: Address required when pvz_code is empty');

                    return response()->json([
                        'success' => false,
                        'message' => $message,
                        'errors' => [
                            'delivery_address' => [$message],
                        ],
                    ], 422);
                }
            }

            $orderData = $request->all();
            $settings = ShopCdekSettings::getActive();
            $configuredTariff = collect($settings?->tariffs ?? [])->first(
                fn ($tariff) => (int) ($tariff['tariff_code'] ?? 0) === (int) ($orderData['tariff_code'] ?? 0)
            );
            $requestedDeliveryType = (string) ($orderData['delivery_type'] ?? '');
            $configuredDeliveryType = $this->resolveTariffDeliveryType($configuredTariff);

            if ($configuredDeliveryType !== '' && $requestedDeliveryType !== '' && $configuredDeliveryType !== $requestedDeliveryType) {
                return response()->json([
                    'success' => false,
                    'message' => 'Тип выбранного тарифа не совпадает с типом доставки. Повторно выберите тариф через «Настроить адрес и тариф».',
                ], 422);
            }

            $effectiveDeliveryType = $configuredDeliveryType !== ''
                ? $configuredDeliveryType
                : $requestedDeliveryType;
            $requiresPvz = $effectiveDeliveryType === 'pvz';
            $requiresAddress = $effectiveDeliveryType === 'door';

            if ($requiresPvz && empty($orderData['pvz_code'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Для выбранного тарифа до ПВЗ не указан пункт выдачи. Откройте «Настроить адрес и тариф» и выберите ПВЗ на карте.',
                    'errors' => ['pvz_code' => ['Не указан пункт выдачи СДЭК']],
                ], 422);
            }

            if (! $requiresPvz && $requiresAddress && trim((string) ($orderData['delivery_address'] ?? '')) === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Для курьерского тарифа не указан адрес получателя.',
                    'errors' => ['delivery_address' => ['Не указан адрес получателя']],
                ], 422);
            }

            $order = $this->findOrderByNumber($orderData['order_number'] ?? null);
            if ($order) {
                $orderData['packages'] = $this->buildCdekPackagesFromOrder($order, $orderData['packages'] ?? []);
            }

            $result = $this->cdekService->createOrder($orderData);

            if ($result['success']) {
                // Получаем UUID заказа из ответа СДЭК
                $cdekOrderUuid = null;
                $deliveryStatus = null;
                $requestState = null;

                if (isset($result['data']['entity']['uuid'])) {
                    $cdekOrderUuid = $result['data']['entity']['uuid'];

                    // Проверяем статус запроса создания заказа
                    if (isset($result['data']['requests']) && is_array($result['data']['requests']) && count($result['data']['requests']) > 0) {
                        $request = $result['data']['requests'][0];
                        $requestState = $request['state'] ?? null;

                        // Проверяем ошибки в начальном ответе
                        if (isset($request['errors']) && is_array($request['errors']) && count($request['errors']) > 0) {
                            Log::error('SdekOrderController: CDEK order creation errors in initial response', [
                                'uuid' => $cdekOrderUuid,
                                'order_number' => $orderData['order_number'] ?? null,
                                'errors' => $request['errors'],
                            ]);

                            // Сохраняем информацию об ошибках в delivery_status
                            $errorMessages = array_map(function ($error) {
                                return $error['message'] ?? ($error['code'] ?? 'Unknown error');
                            }, $request['errors']);

                            $deliveryStatus = [
                                'request_state' => $requestState,
                                'code' => 'INVALID',
                                'name' => 'Ошибка создания заказа',
                                'errors' => $errorMessages,
                            ];
                        }
                    }

                    // Делаем одну быструю проверку статуса заказа (как было в CdekService)
                    if (! $deliveryStatus && $requestState !== 'INVALID') {
                        try {
                            // Используем ту же логику, что и в CdekService - ждем 5 секунд и проверяем
                            sleep(5);
                            $statusResult = $this->cdekService->getOrderStatus($cdekOrderUuid);
                            if ($statusResult['success'] && isset($statusResult['data'])) {
                                $statusData = $statusResult['data'];

                                // Извлекаем статус доставки из entity.statuses (приоритет)
                                if (isset($statusData['entity']['statuses']) && is_array($statusData['entity']['statuses']) && count($statusData['entity']['statuses']) > 0) {
                                    // Берем последний статус
                                    $lastStatus = end($statusData['entity']['statuses']);
                                    $deliveryStatus = [
                                        'code' => $lastStatus['code'] ?? null,
                                        'name' => $lastStatus['name'] ?? null,
                                        'date_time' => $lastStatus['date_time'] ?? null,
                                        'city' => $lastStatus['city'] ?? null,
                                    ];
                                } elseif (isset($statusData['statuses']) && is_array($statusData['statuses']) && count($statusData['statuses']) > 0) {
                                    // Берем последний статус
                                    $lastStatus = end($statusData['statuses']);
                                    $deliveryStatus = [
                                        'code' => $lastStatus['code'] ?? null,
                                        'name' => $lastStatus['name'] ?? null,
                                        'date_time' => $lastStatus['date_time'] ?? null,
                                        'city' => $lastStatus['city'] ?? null,
                                    ];
                                }

                                // Проверяем ошибки в статусе
                                if (isset($statusData['requests']) && is_array($statusData['requests']) && count($statusData['requests']) > 0) {
                                    $latestRequest = end($statusData['requests']);
                                    if (isset($latestRequest['errors']) && is_array($latestRequest['errors']) && count($latestRequest['errors']) > 0) {
                                        $errorMessages = array_map(function ($error) {
                                            return $error['message'] ?? ($error['code'] ?? 'Unknown error');
                                        }, $latestRequest['errors']);

                                        $deliveryStatus = [
                                            'request_state' => $latestRequest['state'] ?? $requestState,
                                            'code' => 'INVALID',
                                            'name' => 'Ошибка создания заказа',
                                            'errors' => $errorMessages,
                                        ];
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            // Игнорируем ошибки получения статуса
                        }
                    }

                    // Сохраняем статус запроса в delivery_status, если нет статуса доставки
                    if (! $deliveryStatus && $requestState) {
                        $deliveryStatus = [
                            'request_state' => $requestState,
                            'code' => $requestState,
                            'name' => $requestState === 'ACCEPTED' ? 'Принят на обработку' :
                                     ($requestState === 'SUCCESSFUL' ? 'Успешно создан' :
                                     ($requestState === 'INVALID' ? 'Ошибка создания' : $requestState)),
                        ];
                    }
                }

                // Если после всех проверок статус INVALID — считаем, что заказ не создан
                $hasInvalidErrors = false;
                if ($deliveryStatus) {
                    $state = $deliveryStatus['request_state'] ?? null;
                    $code = $deliveryStatus['code'] ?? null;
                    if ($state === 'INVALID' || $code === 'INVALID') {
                        $hasInvalidErrors = true;
                    }
                }

                if ($hasInvalidErrors) {
                    return response()->json([
                        'success' => false,
                        'data' => $result['data'],
                        'delivery_status' => $deliveryStatus,
                        'message' => $deliveryStatus['name'] ?? 'Ошибка создания заказа в СДЭК',
                        'errors' => $deliveryStatus['errors'] ?? [],
                    ], 400);
                }

                // Обновляем заказ в базе данных
                if ($cdekOrderUuid && isset($orderData['order_number'])) {
                    // Пытаемся обновить заказ с несколькими попытками (на случай, если заказ еще не успел сохраниться)
                    $maxAttempts = 3;
                    $attempt = 0;
                    $orderUpdated = false;

                    while ($attempt < $maxAttempts && ! $orderUpdated) {
                        try {
                            $attempt++;

                            // Небольшая задержка перед первой попыткой, если это не первая попытка
                            if ($attempt > 1) {
                                sleep(1);
                            }

                            // Нормализуем order_number для поиска (приводим к строке и убираем пробелы)
                            $orderNumber = trim((string) $orderData['order_number']);

                            // Пытаемся найти заказ по нормализованному номеру
                            $order = ShopOrder::where('order_number', $orderNumber)->first();

                            // Если не нашли, пробуем найти без учета регистра и пробелов
                            if (! $order) {
                                $order = ShopOrder::whereRaw('TRIM(order_number) = ?', [$orderNumber])->first();
                            }

                            if ($order) {
                                $updateData = [
                                    'cdek_order_uuid' => $cdekOrderUuid,
                                ];

                                if (isset($orderData['delivery_recipient_cost']) && is_array($orderData['delivery_recipient_cost'])) {
                                    $deliveryCostValue = $orderData['delivery_recipient_cost']['value'] ?? null;
                                    if ($deliveryCostValue !== null && is_numeric($deliveryCostValue)) {
                                        $updateData['delivery_cost'] = (float) $deliveryCostValue;
                                    }
                                }

                                if (isset($orderData['delivery_cost_for_order'])) {
                                    $deliveryCostForOrder = $orderData['delivery_cost_for_order'];
                                    if ($deliveryCostForOrder !== null && is_numeric($deliveryCostForOrder)) {
                                        $updateData['delivery_cost'] = (float) $deliveryCostForOrder;
                                    }
                                }

                                if ($deliveryStatus) {
                                    $updateData['delivery_status'] = json_encode($deliveryStatus, JSON_UNESCAPED_UNICODE);
                                }

                                $metadata = is_array($order->metadata) ? $order->metadata : [];
                                $metadata['cdek_tariff_code'] = (int) ($orderData['tariff_code'] ?? 0);
                                $metadata['cdek_delivery_type'] = $requestedDeliveryType !== ''
                                    ? $requestedDeliveryType
                                    : ($requiresPvz ? 'pvz' : 'door');
                                if (! empty($orderData['pvz_code'])) {
                                    $metadata['cdek_pvz_code'] = (string) $orderData['pvz_code'];
                                }
                                if (! empty($orderData['delivery_address'])) {
                                    $metadata['cdek_delivery_address'] = (string) $orderData['delivery_address'];
                                }
                                $updateData['metadata'] = $metadata;

                                $order->update($updateData);
                                $order->refresh();
                                $orderUpdated = true;
                            }
                        } catch (\Exception $e) {
                            Log::error('SdekOrderController: Failed to update order with CDEK data', [
                                'attempt' => $attempt,
                                'order_number' => $orderData['order_number'],
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);

                            // Если это последняя попытка, не прерываем процесс, так как заказ в СДЭК уже создан
                            if ($attempt >= $maxAttempts) {
                                break;
                            }
                        }
                    }
                }

                return response()->json([
                    'success' => true,
                    'data' => $result['data'],
                    'additional_services' => $result['additional_services'] ?? [],
                    'message' => $result['message'],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                    'error' => $result['error'] ?? null,
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('SdekOrderController: Exception during order creation', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при создании заказа в СДЭК',
            ], 500);
        }
    }

    /**
     * Получить статус заказа в СДЭК
     */
    public function getOrderStatus($orderUuid)
    {
        try {
            if (empty($orderUuid)) {
                return response()->json([
                    'success' => false,
                    'message' => 'UUID заказа не указан',
                ], 400);
            }

            $result = $this->cdekService->getOrderStatus($orderUuid);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['data'],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('SdekOrderController: Exception during status check', [
                'order_uuid' => $orderUuid,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при получении статуса заказа',
            ], 500);
        }
    }

    private function findOrderByNumber($orderNumber): ?ShopOrder
    {
        $orderNumber = trim((string) $orderNumber);
        if ($orderNumber === '') {
            return null;
        }

        $order = ShopOrder::where('order_number', $orderNumber)->first();

        if (! $order) {
            $order = ShopOrder::whereRaw('TRIM(order_number) = ?', [$orderNumber])->first();
        }

        return $order;
    }

    private function buildCdekPackagesFromOrder(ShopOrder $order, array $fallbackPackages): array
    {
        $packages = app(DeliveryPackageService::class)->fromOrder($order, ShopCdekSettings::getActive());
        $items = method_exists($order, 'getItemsWithDetails')
            ? $order->getItemsWithDetails()
            : (is_array($order->items) ? $order->items : json_decode($order->items, true));

        $items = is_array($items) ? $items : [];

        if (empty($items)) {
            return $fallbackPackages;
        }

        return array_map(function (array $package) use ($items) {
            $itemRef = $package['items'][0] ?? [];
            $item = $items[$itemRef['item_index'] ?? 0] ?? [];
            $number = 'PKG_'.($package['number'] ?? 1);
            $groupedRefs = collect($package['items'] ?? [])->groupBy('item_index');
            $totalUnits = collect($package['items'] ?? [])->sum(fn ($ref) => max(1, (int) ($ref['quantity'] ?? 1)));
            $unitWeightGrams = max(1, (int) round(($package['weight'] / max(1, $totalUnits)) * 1000));
            $generatedItems = $groupedRefs->map(function ($refs, $itemIndex) use ($items, $number, $unitWeightGrams) {
                $orderItem = $items[$itemIndex] ?? [];
                // Для оценки содержимого используем фактическую цену покупателя.
                // unit_price хранит цену до скидки зарегистрированного пользователя.
                $unitCost = PriceHelper::roundPrice((float) (
                    $orderItem['customer_unit_price']
                    ?? $orderItem['payable_unit_price']
                    ?? $orderItem['unit_price']
                    ?? $orderItem['final_price']
                    ?? $orderItem['price']
                    ?? 0
                ));

                return [
                    'name' => $orderItem['good_name'] ?? $orderItem['name'] ?? 'Товар',
                    'ware_key' => $orderItem['variation_sku'] ?? $orderItem['good_sku'] ?? $number.'-'.$itemIndex,
                    'cost' => $unitCost,
                    'amount' => $refs->sum(fn ($ref) => max(1, (int) ($ref['quantity'] ?? 1))),
                    'weight' => $unitWeightGrams,
                ];
            })->values()->all();
            $cost = collect($generatedItems)->sum(fn ($entry) => $entry['cost'] * $entry['amount']);

            return [
                'number' => $number,
                'weight' => max(1, (int) round($package['weight'] * 1000)),
                'length' => max(1, (int) ceil($package['length'])),
                'width' => max(1, (int) ceil($package['width'])),
                'height' => max(1, (int) ceil($package['height'])),
                'comment' => (string) ($item['good_name'] ?? $item['name'] ?? 'Товар'),
                'cost' => $cost,
                'items' => $generatedItems,
            ];
        }, $packages);
    }

    private function resolveCdekItemDimensions(array $item): array
    {
        $weight = null;
        $length = null;
        $width = null;
        $height = null;

        if (! empty($item['variation_id'])) {
            $variation = ShopGoodVariation::find($item['variation_id']);
            if ($variation) {
                $weight = $this->positiveFloat($variation->weight ?? null);
                $length = $this->positiveFloat($variation->length ?? $variation->depth ?? null);
                $width = $this->positiveFloat($variation->width ?? null);
                $height = $this->positiveFloat($variation->height ?? null);
            }
        }

        if (! empty($item['good_id'])) {
            $good = ShopGood::find($item['good_id']);
            if ($good) {
                $weight = $weight ?: $this->positiveFloat($good->weight ?? null);
                $length = $length ?: $this->positiveFloat($good->length ?? $good->depth ?? null);
                $width = $width ?: $this->positiveFloat($good->width ?? null);
                $height = $height ?: $this->positiveFloat($good->height ?? null);
            }
        }

        $weight = $weight ?: $this->positiveFloat($item['weight'] ?? null);
        $length = $length ?: $this->positiveFloat($item['length'] ?? $item['depth'] ?? null);
        $width = $width ?: $this->positiveFloat($item['width'] ?? null);
        $height = $height ?: $this->positiveFloat($item['height'] ?? null);

        $settings = ShopCdekSettings::getActive();

        return [
            'weight' => $weight ?: (float) ($settings->default_weight ?? 1),
            'length' => $length ?: (float) ($settings->default_length ?? 10),
            'width' => $width ?: (float) ($settings->default_width ?? 10),
            'height' => $height ?: (float) ($settings->default_height ?? 10),
        ];
    }

    private function resolveTariffDeliveryType(?array $tariff): string
    {
        if (! $tariff) {
            return '';
        }

        $routeName = mb_strtolower(implode(' ', array_filter([
            $tariff['name'] ?? null,
            $tariff['custom_name'] ?? null,
            $tariff['tariff_name'] ?? null,
            $tariff['title'] ?? null,
            $tariff['description'] ?? null,
        ])));
        $routeName = str_replace('ё', 'е', $routeName);

        foreach (['склад-двер', 'склад дверь', 'дверь-двер', 'дверь дверь', 'до двери', 'курьер'] as $needle) {
            if (str_contains($routeName, $needle)) {
                return 'door';
            }
        }
        foreach (['склад-склад', 'склад склад', 'дверь-склад', 'дверь склад', 'до склада', 'до пвз', 'постамат'] as $needle) {
            if (str_contains($routeName, $needle)) {
                return 'pvz';
            }
        }

        $deliveryMode = (int) ($tariff['delivery_mode'] ?? 0);
        if (in_array($deliveryMode, [2, 4], true)) {
            return 'pvz';
        }
        if (in_array($deliveryMode, [1, 3], true)) {
            return 'door';
        }

        return '';
    }

    private function positiveFloat($value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * Получить информацию о страховке для тарифа
     */
    public function getInsuranceInfo(Request $request)
    {
        try {
            $request->validate([
                'tariff_code' => 'required|integer',
                'total_amount' => 'required|numeric|min:0',
            ]);

            $cdekService = new CdekService;
            $result = $cdekService->getInsuranceInfo(
                $request->tariff_code,
                $request->total_amount
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'insurance_info' => $result['insurance_info'],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('SdekOrderController: Error getting insurance info: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера',
            ], 500);
        }
    }

    /**
     * Отменить заказ в СДЭК
     */
    public function cancelOrder($orderUuid)
    {
        try {
            if (empty($orderUuid)) {
                return response()->json([
                    'success' => false,
                    'message' => 'UUID заказа не указан',
                ], 400);
            }

            $result = $this->cdekService->cancelOrder($orderUuid);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['data'],
                    'message' => $result['message'],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('SdekOrderController: Exception during order cancellation', [
                'order_uuid' => $orderUuid,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при отмене заказа',
            ], 500);
        }
    }
}
