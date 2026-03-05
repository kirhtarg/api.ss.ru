<?php

namespace App\Http\Controllers;

use App\Models\ShopOrder;
use App\Services\CdekService;
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
