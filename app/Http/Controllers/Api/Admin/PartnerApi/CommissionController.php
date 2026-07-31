<?php

namespace App\Http\Controllers\Api\Admin\PartnerApi;

use App\Http\Controllers\Controller;
use App\Models\PartnerCommissionEntry;
use App\Models\PartnerPayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CommissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partner_id' => ['nullable', 'integer', 'exists:partners,id'],
            'status' => ['nullable', Rule::in(['pending', 'recognized', 'paid', 'cancelled'])],
            'type' => ['nullable', Rule::in(['accrual', 'adjustment', 'reversal'])],
            'search' => ['nullable', 'string', 'max:128'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        Log::debug('Partner commission journal requested', [
            'user_id' => $request->user()?->id,
            'filters' => $validated,
        ]);

        $baseQuery = PartnerCommissionEntry::query()
            ->when($validated['partner_id'] ?? null, fn ($query, $partnerId) => $query->where('partner_id', $partnerId))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('reason', 'like', "%{$search}%")
                        ->orWhereHas('partnerOrder', function ($orderQuery) use ($search): void {
                            $orderQuery->where('external_order_id', 'like', "%{$search}%")
                                ->orWhereHas('shopOrder', fn ($shopOrder) => $shopOrder->where('order_number', 'like', "%{$search}%"));
                        });
                });
            })
            ->when($validated['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date));

        $summary = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) as entries_count, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $entries = $baseQuery
            ->with([
                'partner:id,public_id,code,name',
                'partnerOrder:id,public_id,external_order_id,shop_order_id,total_amount',
                'partnerOrder.shopOrder:id,order_number',
            ])
            ->latest('id')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'success' => true,
            'data' => $entries,
            'meta' => ['summary' => $summary],
        ]);
    }

    public function updateStatus(Request $request, PartnerCommissionEntry $commission): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'recognized', 'cancelled'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $entry = DB::transaction(function () use ($commission, $validated): PartnerCommissionEntry {
            $entry = PartnerCommissionEntry::query()->with('partnerOrder')->lockForUpdate()->findOrFail($commission->id);
            if ($entry->partner_payout_id !== null) {
                $payout = PartnerPayout::query()->lockForUpdate()->find($entry->partner_payout_id);
                $message = $payout?->status === 'paid'
                    ? 'Комиссию из оплаченной выплаты нельзя изменить'
                    : 'Комиссия включена в сформированную выплату. Сначала отмените выплату';

                Log::warning('Partner commission status change rejected because it belongs to payout', [
                    'partner_commission_id' => $entry->id,
                    'partner_id' => $entry->partner_id,
                    'partner_payout_id' => $entry->partner_payout_id,
                    'payout_status' => $payout?->status,
                ]);

                throw new ConflictHttpException($message);
            }
            if ($entry->status === 'paid') {
                throw new ConflictHttpException('Оплаченную комиссию нельзя изменить вручную');
            }

            $oldStatus = $entry->status;
            $newStatus = $validated['status'];
            $metadata = array_merge($entry->metadata ?? [], ['last_status_change' => [
                'from' => $oldStatus,
                'to' => $newStatus,
                'reason' => $validated['reason'] ?? null,
                'changed_at' => now()->toIso8601String(),
            ]]);
            $entry->update([
                'status' => $newStatus,
                'recognized_at' => $newStatus === 'recognized' ? ($entry->recognized_at ?? now()) : null,
                'metadata' => $metadata,
            ]);
            if ($entry->type === 'accrual' && $entry->partnerOrder) {
                $entry->partnerOrder->update(['commission_status' => $newStatus]);
            }

            Log::warning('Partner commission status changed manually', [
                'partner_commission_id' => $entry->id,
                'partner_id' => $entry->partner_id,
                'partner_order_id' => $entry->partner_order_id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            return $entry;
        });

        return response()->json([
            'success' => true,
            'message' => 'Статус комиссии обновлён',
            'data' => $entry->load(['partner:id,code,name', 'partnerOrder.shopOrder:id,order_number']),
        ]);
    }
}
