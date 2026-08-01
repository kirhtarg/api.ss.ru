<?php

namespace App\Http\Controllers\Api\Partner\V1;

use App\Http\Controllers\Controller;
use App\Models\PartnerCommissionEntry;
use App\Services\Partner\PartnerSyncCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CommissionController extends Controller
{
    public function __construct(private readonly PartnerSyncCursor $cursor) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'updated_since' => ['nullable', 'date'],
            'cursor' => ['nullable', 'string', 'max:512'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = PartnerCommissionEntry::query()
            ->where('partner_id', $request->attributes->get('partner')->id)
            ->with('partnerOrder.shopOrder');
        $page = $this->cursor->apply($query, $filters['cursor'] ?? null, $filters['updated_since'] ?? null)
            ->paginate($filters['per_page'] ?? 50);

        $items = $page->getCollection()->map(fn (PartnerCommissionEntry $entry): array => [
            'id' => $entry->id,
            'order_id' => $entry->partnerOrder?->public_id,
            'external_order_id' => $entry->partnerOrder?->external_order_id,
            'order_number' => $entry->partnerOrder?->shopOrder?->order_number,
            'type' => $entry->type,
            'status' => $entry->status,
            'amount' => round((float) $entry->amount, 2),
            'currency' => $entry->currency,
            'reason' => $entry->reason,
            'recognized_at' => $entry->recognized_at?->toIso8601String(),
            'paid_at' => $entry->paid_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ])->values();

        Log::info('Partner commission reconciliation completed', [
            'partner_id' => $request->attributes->get('partner')->id,
            'returned_count' => $items->count(),
            'has_more' => $page->hasMorePages(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'next_cursor' => $page->hasMorePages() ? $this->cursor->encode($page->getCollection()->last()) : null,
                'per_page' => $page->perPage(),
                'has_more' => $page->hasMorePages(),
                'sort' => ['updated_at', 'id'],
            ],
        ]);
    }
}
