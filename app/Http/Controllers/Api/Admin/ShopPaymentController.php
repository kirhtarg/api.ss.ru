<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use App\Models\ShopOrderStatus;
use App\Models\ShopPaymentMethod;
use App\Models\ShopPaymentStatus;
use App\Models\ShopPaymentTransaction;
use App\Services\DolyamePartnerService;
use App\Services\TbankPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ShopPaymentController extends Controller
{
    /**
     * Получить список способов оплаты
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ShopPaymentMethod::query();

            // Фильтрация по активности
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Фильтрация по типу
            if ($request->has('type')) {
                $query->where('type', $request->get('type'));
            }

            // Поиск
            if ($request->has('search') && $request->get('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Сортировка
            $sortBy = $request->get('sort_by', 'sort_order');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Пагинация
            $perPage = $request->get('per_page', 15);
            $paymentMethods = $query->paginate($perPage);

            // Безопасная сериализация данных с image_url
            $items = [];
            foreach ($paymentMethods->items() as $method) {
                try {
                    $item = [
                        'id' => $method->id,
                        'name' => $method->name,
                        'type' => $method->type,
                        'is_active' => $method->is_active,
                        'description' => $method->description,
                        'settings' => $method->settings,
                        'sort_order' => $method->sort_order,
                        'is_default' => $method->is_default,
                        'can_disable_default' => $method->can_disable_default,
                        'created_at' => $method->created_at,
                        'updated_at' => $method->updated_at,
                    ];

                    try {
                        $imageUrl = $method->image_url;
                        if ($imageUrl) {
                            $version = $method->updated_at ? $method->updated_at->timestamp : time();
                            $item['image_url'] = $imageUrl.'?v='.$version;
                        } else {
                            $item['image_url'] = null;
                        }
                    } catch (\Exception $e) {
                        \Log::warning('Error getting image_url for payment method '.$method->id.': '.$e->getMessage());
                        $item['image_url'] = null;
                    }

                    $items[] = $item;
                } catch (\Exception $e) {
                    \Log::error('Error serializing payment method item: '.$e->getMessage());

                    continue;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $items,
                'pagination' => [
                    'current_page' => $paymentMethods->currentPage(),
                    'last_page' => $paymentMethods->lastPage(),
                    'per_page' => $paymentMethods->perPage(),
                    'total' => $paymentMethods->total(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения способов оплаты',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить способ оплаты по ID
     */
    public function show(int $id): JsonResponse
    {
        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($id);

            $data = [
                'id' => $paymentMethod->id,
                'name' => $paymentMethod->name,
                'type' => $paymentMethod->type,
                'is_active' => $paymentMethod->is_active,
                'description' => $paymentMethod->description,
                'settings' => $paymentMethod->settings,
                'sort_order' => $paymentMethod->sort_order,
                'is_default' => $paymentMethod->is_default,
                'can_disable_default' => $paymentMethod->can_disable_default,
                'created_at' => $paymentMethod->created_at,
                'updated_at' => $paymentMethod->updated_at,
            ];

            try {
                $imageUrl = $paymentMethod->image_url;
                if ($imageUrl) {
                    $version = $paymentMethod->updated_at ? $paymentMethod->updated_at->timestamp : time();
                    $data['image_url'] = $imageUrl.'?v='.$version;
                } else {
                    $data['image_url'] = null;
                }
            } catch (\Exception $e) {
                \Log::warning('Error getting image_url for payment method '.$id.': '.$e->getMessage());
                $data['image_url'] = null;
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Способ оплаты не найден',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Создать новый способ оплаты
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'type' => 'required|string|max:50',
                'is_active' => 'boolean',
                'description' => 'nullable|string',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
                'is_default' => 'boolean',
                'can_disable_default' => 'boolean',
            ]);

            // Дополнительная валидация для Яндекс Пэй
            if ($request->get('type') === 'yandex_pay' || $request->get('type') === 'yandex_split') {
                $yandexValidator = Validator::make($request->get('settings', []), [
                    'merchant_id' => 'required|string|max:255',
                    'secret_key' => 'required|string|max:255',
                    'mode' => 'required|string|in:test,live',
                    'currency' => 'required|string|in:RUB,USD,EUR',
                    'return_url' => 'nullable|url',
                    'webhook_url' => 'nullable|url',
                    'additional_settings' => 'nullable|string',
                    'split_min_amount' => 'nullable|numeric|min:0',
                    'split_max_amount' => 'nullable|numeric|min:0|gte:split_min_amount',
                    'split_settings' => 'nullable|string',
                ]);

                if ($yandexValidator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка валидации настроек Яндекс Пэй',
                        'errors' => $yandexValidator->errors(),
                    ], 422);
                }
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Если устанавливается как способ по умолчанию, снимаем флаг с других
            if ($request->boolean('is_default')) {
                ShopPaymentMethod::where('is_default', true)->update(['is_default' => false]);
            }

            $paymentMethod = ShopPaymentMethod::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Способ оплаты создан успешно',
                'data' => $paymentMethod,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания способа оплаты',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить способ оплаты
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'type' => 'sometimes|required|string|max:50',
                'is_active' => 'boolean',
                'description' => 'nullable|string',
                'settings' => 'nullable|array',
                'sort_order' => 'integer|min:0',
                'is_default' => 'boolean',
                'can_disable_default' => 'boolean',
            ]);

            // Дополнительная валидация для Яндекс Пэй
            if ($request->get('type') === 'yandex_pay' || $request->get('type') === 'yandex_split') {
                $yandexValidator = Validator::make($request->get('settings', []), [
                    'merchant_id' => 'required|string|max:255',
                    'secret_key' => 'required|string|max:255',
                    'mode' => 'required|string|in:test,live',
                    'currency' => 'required|string|in:RUB,USD,EUR',
                    'return_url' => 'nullable|url',
                    'webhook_url' => 'nullable|url',
                    'additional_settings' => 'nullable|string',
                    'split_min_amount' => 'nullable|numeric|min:0',
                    'split_max_amount' => 'nullable|numeric|min:0|gte:split_min_amount',
                    'split_settings' => 'nullable|string',
                ]);

                if ($yandexValidator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибка валидации настроек Яндекс Пэй',
                        'errors' => $yandexValidator->errors(),
                    ], 422);
                }
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Если устанавливается как способ по умолчанию, снимаем флаг с других
            if ($request->boolean('is_default') && ! $paymentMethod->is_default) {
                ShopPaymentMethod::where('is_default', true)->update(['is_default' => false]);
            }

            $paymentMethod->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Способ оплаты обновлен успешно',
                'data' => $paymentMethod,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления способа оплаты',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить способ оплаты
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($id);

            // Нельзя удалить способ по умолчанию, если он не может быть отключен
            if ($paymentMethod->is_default && ! $paymentMethod->can_disable_default) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить этот способ оплаты по умолчанию',
                ], 400);
            }

            // Нельзя удалить способ по умолчанию, если нет других активных
            if ($paymentMethod->is_default) {
                $hasOtherActive = ShopPaymentMethod::where('id', '!=', $id)
                    ->where('is_active', true)
                    ->exists();

                if (! $hasOtherActive) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Нельзя удалить способ оплаты по умолчанию, если нет других активных способов',
                    ], 400);
                }
            }

            $paymentMethod->delete();

            return response()->json([
                'success' => true,
                'message' => 'Способ оплаты удален успешно',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления способа оплаты',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Изменить порядок сортировки
     */
    public function reorder(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'items' => 'required|array',
                'items.*.id' => 'required|integer|exists:shop_payment_methods,id',
                'items.*.sort_order' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422);
            }

            foreach ($request->get('items') as $item) {
                ShopPaymentMethod::where('id', $item['id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Порядок сортировки обновлен успешно',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления порядка сортировки',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function createDolyameTestOrder(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'scenario' => 'required|string|in:success_one,success_two,reject',
            'settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($id);
            if ($paymentMethod->type !== 'tbank_dolyame') {
                return response()->json([
                    'success' => false,
                    'message' => 'Тестовые заказы Долями доступны только для способа оплаты "Долями"',
                ], 422);
            }

            $scenario = $request->string('scenario')->toString();
            $items = $this->buildDolyameTestItems($scenario);
            $amount = round(array_reduce($items, fn ($sum, $item) => $sum + $item['price'] * $item['quantity'], 0), 2);
            $orderNumber = $this->generateDolyameTestOrderNumber();

            $settings = $this->normalizeDolyamePartnerSettings(array_replace(
                $this->normalizePaymentSettings($paymentMethod->settings),
                $request->get('settings', [])
            ));

            $demoFlow = $scenario === 'reject' ? 'reject' : null;
            $service = new DolyamePartnerService($settings);
            $response = $service->createOrder(
                $orderNumber,
                $amount,
                $items,
                url('/api/webhooks/dolyame'),
                url('/api/public/shop/payment/return?payment_type=tbank_dolyame&status=success&order_number='.urlencode($orderNumber)),
                url('/api/public/shop/payment/return?payment_type=tbank_dolyame&status=fail&order_number='.urlencode($orderNumber)),
                $demoFlow
            );

            if (empty($response['success'])) {
                return response()->json([
                    'success' => false,
                    'message' => $response['message'] ?? 'Ошибка создания тестового заказа Долями',
                    'order_id' => null,
                    'order_number' => $orderNumber,
                    'response' => $response,
                ], 422);
            }

            \Illuminate\Support\Facades\Cache::put('dolyame:test:'.$orderNumber, [
                'order_number' => $orderNumber,
                'payment_method_id' => $paymentMethod->id,
                'scenario' => $scenario,
                'scenario_title' => $this->getDolyameScenarioTitle($scenario),
                'amount' => $amount,
                'items' => $items,
                'payment_url' => $response['payment_url'] ?? $response['link'] ?? null,
                'created_at' => now()->toDateTimeString(),
                'webhook_received' => false,
                'webhook_received_at' => null,
                'webhook_status' => null,
                'webhook_payload' => null,
                'commit_response' => null,
            ], now()->addDays(7));

            return response()->json([
                'success' => true,
                'message' => 'Тестовая заявка Долями создана без заказа в системе',
                'data' => [
                    'order_id' => null,
                    'order_number' => $orderNumber,
                    'scenario' => $scenario,
                    'scenario_title' => $this->getDolyameScenarioTitle($scenario),
                    'amount' => $amount,
                    'items' => $items,
                    'payment_url' => $response['payment_url'] ?? $response['link'] ?? null,
                    'response' => $response['response'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания тестового заказа Долями',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getDolyameTestOrderStatus(Request $request, int $id, string $orderNumber): JsonResponse
    {
        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($id);
            $settings = $this->normalizeDolyamePartnerSettings(array_replace(
                $this->normalizePaymentSettings($paymentMethod->settings),
                $request->get('settings', [])
            ));
            $response = (new DolyamePartnerService($settings))->getOrderInfo($orderNumber);
            $order = ShopOrder::where('order_number', $orderNumber)->first();
            $testData = \Illuminate\Support\Facades\Cache::get('dolyame:test:'.$orderNumber);

            return response()->json([
                'success' => ! empty($response['success']),
                'data' => [
                    'order_id' => $order?->id,
                    'order_number' => $orderNumber,
                    'local_payed' => (bool) ($order?->payed),
                    'local_payment_status' => $order?->paymentStatus?->name,
                    'provider_status' => $response['response']['status'] ?? null,
                    'webhook_received' => (bool) ($testData['webhook_received'] ?? false),
                    'webhook_received_at' => $testData['webhook_received_at'] ?? null,
                    'webhook_status' => $testData['webhook_status'] ?? null,
                    'webhook_payload' => $testData['webhook_payload'] ?? null,
                    'webhook_info_response' => $testData['webhook_info_response'] ?? null,
                    'commit_response' => $testData['commit_response'] ?? null,
                    'response' => $response['response'] ?? $response,
                ],
            ], ! empty($response['success']) ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка проверки статуса тестового заказа Долями',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function refundDolyameTestOrder(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_number' => 'required|string',
            'refund_type' => 'required|string|in:full,partial',
            'settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($id);
            $order = ShopOrder::where('order_number', $request->get('order_number'))->first();
            $items = $request->get('items', []);
            if (! is_array($items) || empty($items)) {
                $items = is_array($order?->items) ? $order->items : [];
            }

            if ($request->get('refund_type') === 'partial') {
                $firstItem = $items[0] ?? null;
                if (! $firstItem) {
                    return response()->json(['success' => false, 'message' => 'В заказе нет позиций для частичного возврата'], 422);
                }
                $items = [[
                    'name' => $firstItem['name'] ?? $firstItem['good_name'] ?? 'Товар',
                    'quantity' => 1,
                    'price' => (float) ($firstItem['price'] ?? $firstItem['final_price'] ?? 0),
                ]];
            }

            $items = array_values(array_filter(array_map(function ($item) {
                return [
                    'name' => $item['name'] ?? $item['good_name'] ?? 'Товар',
                    'quantity' => (float) ($item['quantity'] ?? 1),
                    'price' => (float) ($item['price'] ?? $item['final_price'] ?? 0),
                ];
            }, $items), fn ($item) => $item['price'] > 0 && $item['quantity'] > 0));

            $amount = round(array_reduce($items, fn ($sum, $item) => $sum + $item['price'] * $item['quantity'], 0), 2);
            $settings = $this->normalizeDolyamePartnerSettings(array_replace(
                $this->normalizePaymentSettings($paymentMethod->settings),
                $request->get('settings', [])
            ));
            $orderNumber = $request->get('order_number');
            $response = (new DolyamePartnerService($settings))->refundOrder($orderNumber, $amount, $items);

            if ($order) {
                ShopPaymentTransaction::create([
                    'order_id' => $order->id,
                    'payment_method_id' => $paymentMethod->id,
                    'status' => empty($response['success'])
                        ? 'failed'
                        : ($request->get('refund_type') === 'full' ? 'refunded' : 'partial_refunded'),
                    'amount' => $amount,
                    'transaction_id' => $orderNumber.'-refund-'.now()->format('His'),
                    'request_data' => [
                        'refund_type' => $request->get('refund_type'),
                        'items' => $items,
                    ],
                    'response_data' => $response['response'] ?? $response,
                    'error_message' => empty($response['success']) ? ($response['message'] ?? 'Ошибка возврата Долями') : null,
                ]);
            }

            if ($order && ! empty($response['success']) && $request->get('refund_type') === 'full') {
                $refundedStatusId = ShopPaymentStatus::where('name', 'refunded')->value('id')
                    ?? ShopPaymentStatus::where('name', 'canceled')->value('id');
                if ($refundedStatusId) {
                    $order->update(['payment_status_id' => $refundedStatusId]);
                }
            }

            return response()->json([
                'success' => ! empty($response['success']),
                'message' => ! empty($response['success']) ? 'Возврат отправлен в Долями' : ($response['message'] ?? 'Ошибка возврата Долями'),
                'data' => [
                    'order_id' => $order?->id,
                    'order_number' => $orderNumber,
                    'refund_type' => $request->get('refund_type'),
                    'amount' => $amount,
                    'items' => $items,
                    'response' => $response['response'] ?? $response,
                ],
            ], ! empty($response['success']) ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка возврата тестового заказа Долями',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function createYookassaTestPayment(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'nullable|numeric|min:1|max:100000',
            'settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($id);
            if ($paymentMethod->type !== 'yookassa') {
                return response()->json(['success' => false, 'message' => 'Диагностика доступна только для Ю-Касса'], 422);
            }

            $settings = array_replace(
                $this->normalizePaymentSettings($paymentMethod->settings),
                $request->get('settings', [])
            );
            $shopId = $settings['shop_id'] ?? null;
            $secretKey = $settings['secret_key'] ?? null;
            if (empty($shopId) || empty($secretKey)) {
                return response()->json(['success' => false, 'message' => 'ЮКасса: отсутствует shop_id/secret_key'], 422);
            }

            $testId = (string) Str::uuid();
            $orderNumber = $this->generatePaymentTestOrderNumber('YKT');
            $amount = number_format((float) ($request->get('amount') ?: 10), 2, '.', '');
            $customerEmail = $settings['test_customer_email'] ?? $settings['receipt_email'] ?? 'test@skateandsnow.ru';
            $customerPhone = $settings['test_customer_phone'] ?? $settings['receipt_phone'] ?? '+79999999999';
            $taxSystemCode = $this->normalizeYookassaTaxSystemCode($settings['tax_system_code'] ?? $settings['taxation'] ?? null);
            $vatCode = (int) ($settings['vat_code'] ?? $settings['item_vat_code'] ?? 1);
            if ($vatCode < 1 || $vatCode > 6) {
                $vatCode = 1;
            }
            $payload = [
                'amount' => ['value' => $amount, 'currency' => $settings['currency'] ?? 'RUB'],
                'capture' => true,
                'description' => 'Тестовая оплата ЮКасса '.$orderNumber,
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => config('app.frontend_url').'/checkout?payment=yookassa_test_return',
                ],
                'metadata' => [
                    'is_test' => true,
                    'test_id' => $testId,
                    'order_number' => $orderNumber,
                    'payment_method_id' => $paymentMethod->id,
                ],
                'receipt' => [
                    'customer' => [
                        'email' => $customerEmail,
                        'phone' => $customerPhone,
                    ],
                    'items' => [[
                        'description' => 'Тестовый товар ЮКасса',
                        'quantity' => '1.00',
                        'amount' => [
                            'value' => $amount,
                            'currency' => $settings['currency'] ?? 'RUB',
                        ],
                        'vat_code' => $vatCode,
                        'payment_subject' => $settings['payment_subject'] ?? 'commodity',
                        'payment_mode' => $settings['payment_mode'] ?? 'full_payment',
                    ]],
                ],
            ];
            if ($taxSystemCode !== null) {
                $payload['receipt']['tax_system_code'] = $taxSystemCode;
            }

            $proxy = env('HTTP_CLIENT_PROXY');
            $verify = config('services.tbank.verify_ssl', true);
            $caBundle = config('services.tbank.ca_bundle_path');
            $response = Http::timeout(30)->retry(2, 1000, null, false)->withOptions([
                'connect_timeout' => 10,
                'proxy' => $proxy ?: null,
                'verify' => app()->environment('production') ? ($caBundle ?: $verify) : false,
                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            ])->withHeaders([
                'Idempotence-Key' => 'test_'.$testId,
                'Content-Type' => 'application/json',
            ])->withBasicAuth($shopId, $secretKey)
                ->post('https://api.yookassa.ru/v3/payments', $payload);

            $responseData = $response->json();
            $rawBody = $response->body();
            $paymentId = $responseData['id'] ?? null;
            $testData = [
                'test_id' => $testId,
                'order_number' => $orderNumber,
                'payment_method_id' => $paymentMethod->id,
                'provider' => 'yookassa',
                'amount' => $amount,
                'payment_id' => $paymentId,
                'payment_url' => $responseData['confirmation']['confirmation_url'] ?? null,
                'request_payload' => $payload,
                'server_response' => $responseData,
                'server_response_raw' => $rawBody,
                'http_status' => $response->status(),
                'webhook_url' => url('/api/webhooks/yookassa'),
                'created_at' => now()->toDateTimeString(),
                'webhook_received' => false,
            ];

            Cache::put('payment:test:yookassa:'.$testId, $testData, now()->addHours(4));
            if ($paymentId) {
                Cache::put('payment:test:yookassa:payment:'.$paymentId, $testId, now()->addHours(4));
            }

            return response()->json([
                'success' => $response->successful() && ! empty($testData['payment_url']),
                'message' => $response->successful()
                    ? 'Тестовый платеж ЮКасса создан без заказа в системе'
                    : ($responseData['description'] ?? $responseData['message'] ?? 'ЮКасса вернула ошибку'),
                'test_id' => $testId,
                'data' => $testData,
            ], $response->successful() ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания тестового платежа ЮКасса',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function createTbankEacqTestPayment(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'nullable|numeric|min:1|max:100000',
            'settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($id);
            if ($paymentMethod->type !== 'tbank_eacq') {
                return response()->json(['success' => false, 'message' => 'Диагностика доступна только для Т-Банк e-acq'], 422);
            }

            $settings = array_replace(
                $this->normalizePaymentSettings($paymentMethod->settings),
                $request->get('settings', [])
            );
            if (empty($settings['terminal_key']) || empty($settings['terminal_password'])) {
                return response()->json(['success' => false, 'message' => 'Т-Банк: отсутствует TerminalKey/Password'], 422);
            }

            $testId = (string) Str::uuid();
            $orderNumber = $this->generatePaymentTestOrderNumber('TBT');
            $amount = round((float) ($request->get('amount') ?: 10), 2);
            $orderStub = (object) [
                'id' => 0,
                'order_number' => $orderNumber,
                'total_amount' => $amount,
                'customer_email' => 'test@skateandsnow.ru',
                'customer_phone' => '+79999999999',
                'user_id' => null,
                'delivery_cost' => 0,
                'items' => [[
                    'name' => 'Тестовый товар Т-Банк',
                    'quantity' => 1,
                    'final_price' => $amount,
                    'price' => $amount,
                ]],
            ];

            $result = (new TbankPaymentService($settings))->initiatePayment($orderStub);
            $paymentId = $result['transaction_id'] ?? null;
            $testData = [
                'test_id' => $testId,
                'order_number' => $orderNumber,
                'payment_method_id' => $paymentMethod->id,
                'provider' => 'tbank_eacq',
                'amount' => $amount,
                'payment_id' => $paymentId,
                'payment_url' => $result['payment_url'] ?? null,
                'request_payload' => $result['request_data'] ?? null,
                'server_response' => $result['response_data'] ?? $result,
                'webhook_url' => url('/api/webhooks/tbank'),
                'settings' => $settings,
                'created_at' => now()->toDateTimeString(),
                'webhook_received' => false,
            ];

            Cache::put('payment:test:tbank:'.$testId, $testData, now()->addHours(4));
            Cache::put('payment:test:tbank:order:'.$orderNumber, $testId, now()->addHours(4));
            if ($paymentId) {
                Cache::put('payment:test:tbank:payment:'.$paymentId, $testId, now()->addHours(4));
            }
            $responseTestData = $testData;
            unset($responseTestData['settings']);

            return response()->json([
                'success' => ! empty($result['success']),
                'message' => ! empty($result['success']) ? 'Тестовый платеж Т-Банк создан без заказа в системе' : ($result['message'] ?? 'Т-Банк вернул ошибку'),
                'test_id' => $testId,
                'data' => $responseTestData,
            ], ! empty($result['success']) ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания тестового платежа Т-Банк',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getPaymentTestWebhookStatus(string $provider, string $testId): JsonResponse
    {
        if (! in_array($provider, ['yookassa', 'tbank'], true)) {
            return response()->json(['success' => false, 'message' => 'Неизвестный провайдер'], 422);
        }

        $data = Cache::get('payment:test:'.$provider.':'.$testId);
        if (is_array($data)) {
            unset($data['settings']);
        }

        return response()->json([
            'success' => true,
            'received' => (bool) ($data['webhook_received'] ?? false),
            'data' => $data,
        ]);
    }

    protected function normalizePaymentSettings($settings): array
    {
        if (is_array($settings)) {
            return $settings;
        }
        if (is_string($settings) && $settings !== '') {
            $decoded = json_decode($settings, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    protected function normalizeYookassaTaxSystemCode($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $code = (int) $value;

            return ($code >= 1 && $code <= 6) ? $code : null;
        }

        $map = [
            'osn' => 1,
            'general' => 1,
            'usn_income' => 2,
            'usn_income_outcome' => 3,
            'envd' => 4,
            'esn' => 5,
            'patent' => 6,
        ];

        return $map[(string) $value] ?? null;
    }

    protected function normalizeDolyamePartnerSettings(array $settings): array
    {
        $settings['dolyame_provider'] = 'partner';
        $settings['api_url'] = $settings['dolyame_api_url'] ?? $settings['api_url'] ?? 'https://partner.dolyame.ru/v1';
        $settings['dolyame_login'] = $settings['dolyame_login1'] ?? $settings['dolyame_login'] ?? '';
        $settings['dolyame_password'] = $settings['dolyame_password1'] ?? $settings['dolyame_password'] ?? '';

        return $settings;
    }

    protected function buildDolyameTestItems(string $scenario): array
    {
        if ($scenario === 'success_two') {
            return [
                ['name' => 'Dolyame test item 1', 'quantity' => 1, 'price' => 600.0],
                ['name' => 'Dolyame test item 2', 'quantity' => 1, 'price' => 700.0],
            ];
        }

        return [
            ['name' => $scenario === 'reject' ? 'Dolyame reject test item' : 'Dolyame success test item', 'quantity' => 1, 'price' => 1000.0],
        ];
    }

    protected function getDolyameScenarioTitle(string $scenario): string
    {
        return match ($scenario) {
            'success_one' => 'успешная оплата, 1 позиция',
            'success_two' => 'успешная оплата, 2 позиции',
            'reject' => 'отказ в услуге',
            default => $scenario,
        };
    }

    protected function generateDolyameTestOrderNumber(): string
    {
        do {
            $orderNumber = 'SST'.now()->format('ymdHis').random_int(100, 999);
        } while (ShopOrder::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    protected function generatePaymentTestOrderNumber(string $prefix): string
    {
        do {
            $orderNumber = $prefix.now()->format('ymdHis').random_int(100, 999);
        } while (ShopOrder::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }
}
