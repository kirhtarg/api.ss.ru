<?php

namespace App\Http\Controllers;

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
                'customer_phone' => 'required|string|max:20',
                'customer_company' => 'nullable|string|max:255',
                'city_code' => 'required|string',
                'delivery_address' => 'required|string|max:500',
                'pvz_code' => 'nullable|string|max:50',
                'comment' => 'nullable|string|max:1000',
                'packages' => 'required|array|min:1',
                'packages.*.number' => 'required|string|max:100',
                'packages.*.weight' => 'required|numeric|min:1',
                'packages.*.length' => 'required|numeric|min:1',
                'packages.*.width' => 'required|numeric|min:1',
                'packages.*.height' => 'required|numeric|min:1',
                'packages.*.comment' => 'nullable|string|max:500',
                'services' => 'nullable|array'
            ]);

            if ($validator->fails()) {
                Log::error('SdekOrderController: Validation failed', $validator->errors()->toArray());
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации данных',
                    'errors' => $validator->errors()
                ], 422);
            }

                $orderData = $request->all();
                
                Log::info('SdekOrderController: Creating order', [
                    'order_number' => $orderData['order_number'],
                    'tariff_code' => $orderData['tariff_code'],
                    'customer_name' => $orderData['customer_name'],
                    'full_data' => $orderData
                ]);

                $result = $this->cdekService->createOrder($orderData);
                
                Log::info('SdekOrderController: Order creation result:', $result);

            if ($result['success']) {
                Log::info('SdekOrderController: Returning response with additional_services:', $result['additional_services'] ?? []);
                return response()->json([
                    'success' => true,
                    'data' => $result['data'],
                    'additional_services' => $result['additional_services'] ?? [],
                    'message' => $result['message']
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                    'error' => $result['error'] ?? null
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('SdekOrderController: Exception during order creation', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при создании заказа в СДЭК'
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
                    'message' => 'UUID заказа не указан'
                ], 400);
            }

            $result = $this->cdekService->getOrderStatus($orderUuid);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['data']
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('SdekOrderController: Exception during status check', [
                'order_uuid' => $orderUuid,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при получении статуса заказа'
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
                'total_amount' => 'required|numeric|min:0'
            ]);

            $cdekService = new CdekService();
            $result = $cdekService->getInsuranceInfo(
                $request->tariff_code,
                $request->total_amount
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'insurance_info' => $result['insurance_info']
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('SdekOrderController: Error getting insurance info: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера'
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
                    'message' => 'UUID заказа не указан'
                ], 400);
            }

            $result = $this->cdekService->cancelOrder($orderUuid);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result['data'],
                    'message' => $result['message']
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('SdekOrderController: Exception during order cancellation', [
                'order_uuid' => $orderUuid,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при отмене заказа'
            ], 500);
        }
    }
}
