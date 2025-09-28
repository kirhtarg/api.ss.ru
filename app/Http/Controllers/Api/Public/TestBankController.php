<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\TestBankService;
use App\Models\ShopPaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class TestBankController extends Controller
{
    private $testBankService;

    public function __construct(TestBankService $testBankService)
    {
        $this->testBankService = $testBankService;
    }

    public function createPayment(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_id' => 'required|integer',
                'amount' => 'required|numeric|min:0.01',
                'card_data' => 'required|array',
                'card_data.number' => 'required|string',
                'card_data.expiry' => 'required|string',
                'card_data.cvv' => 'required|string',
                'card_data.name' => 'required|string',
                'card_data.email' => 'nullable|email'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Валидация карты
            if (!$this->testBankService->validateCard($request->get('card_data'))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный номер карты'
                ], 400);
            }

            $orderData = [
                'order_id' => $request->get('order_id'),
                'amount' => $request->get('amount'),
                'description' => "Оплата заказа #{$request->get('order_id')}",
                'return_url' => config('app.url') . '/order-success',
                'webhook_url' => config('app.url') . '/api/webhooks/testbank'
            ];

            $payment = $this->testBankService->createPayment($orderData, $request->get('card_data'));

            // Создаем транзакцию
            $transaction = ShopPaymentTransaction::create([
                'order_id' => $request->get('order_id'),
                'payment_method_id' => 3, // Test Bank ID
                'status' => 'pending',
                'amount' => $request->get('amount'),
                'transaction_id' => $payment['transaction_id'] ?? null,
                'request_data' => $request->all(),
                'response_data' => $payment
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'transaction_id' => $transaction->id,
                    'payment_id' => $payment['payment_id'] ?? null,
                    'status' => $payment['status'] ?? 'pending'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getPaymentStatus(Request $request): JsonResponse
    {
        try {
            $transactionId = $request->get('transaction_id');
            $transaction = ShopPaymentTransaction::findOrFail($transactionId);

            $status = $this->testBankService->getPaymentStatus($transaction->transaction_id);

            // Обновляем статус транзакции
            if ($status['status'] === 'success') {
                $transaction->markAsSuccessful($status['transaction_id'], $status);
            } elseif ($status['status'] === 'failed') {
                $transaction->markAsFailed($status['error_message'] ?? 'Payment failed', $status);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => $status['status'],
                    'transaction_id' => $transaction->transaction_id
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function webhook(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            $transactionId = $data['transaction_id'] ?? null;

            if (!$transactionId) {
                return response()->json(['success' => false, 'message' => 'Transaction ID required'], 400);
            }

            $transaction = ShopPaymentTransaction::where('transaction_id', $transactionId)->first();
            
            if (!$transaction) {
                return response()->json(['success' => false, 'message' => 'Transaction not found'], 404);
            }

            // Обновляем статус транзакции
            if ($data['status'] === 'success') {
                $transaction->markAsSuccessful($transactionId, $data);
            } elseif ($data['status'] === 'failed') {
                $transaction->markAsFailed($data['error_message'] ?? 'Payment failed', $data);
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('TestBank webhook error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Webhook error'], 500);
        }
    }
}
