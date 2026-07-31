<?php

namespace App\Http\Controllers\Api\Admin\PartnerApi;

use App\Http\Controllers\Controller;
use App\Models\PartnerCommissionEntry;
use App\Models\PartnerPayout;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PayoutController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partner_id' => ['nullable', 'integer', 'exists:partners,id'],
            'status' => ['nullable', Rule::in(['formed', 'paid', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        Log::debug('Partner payout registry requested', ['user_id' => $request->user()?->id, 'filters' => $validated]);
        $query = PartnerPayout::query()
            ->with('partner:id,public_id,code,name')
            ->when($validated['partner_id'] ?? null, fn ($builder, $id) => $builder->where('partner_id', $id))
            ->when($validated['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($validated['search'] ?? null, fn ($builder, $search) => $builder->where(fn ($nested) => $nested->where('number', 'like', "%{$search}%")->orWhere('payment_reference', 'like', "%{$search}%")))
            ->when($validated['date_from'] ?? null, fn ($builder, $date) => $builder->whereDate('created_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($builder, $date) => $builder->whereDate('created_at', '<=', $date));
        $summary = (clone $query)->selectRaw('status, COUNT(*) as payouts_count, COALESCE(SUM(amount), 0) as amount')->groupBy('status')->get()->keyBy('status');

        return response()->json(['success' => true, 'data' => $query->latest('id')->paginate($validated['per_page'] ?? 25), 'meta' => ['summary' => $summary]]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partner_id' => ['required', 'integer', 'exists:partners,id'],
            'commission_ids' => ['nullable', 'array', 'min:1', 'max:1000'],
            'commission_ids.*' => ['integer'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date', 'after_or_equal:period_from'],
            'comment' => ['nullable', 'string', 'max:4000'],
        ]);

        $payout = DB::transaction(function () use ($request, $validated): PartnerPayout {
            $entries = PartnerCommissionEntry::query()
                ->where('partner_id', $validated['partner_id'])
                ->where('status', 'recognized')
                ->whereNull('partner_payout_id')
                ->when($validated['commission_ids'] ?? null, fn ($query, $ids) => $query->whereIn('id', $ids))
                ->when($validated['period_from'] ?? null, fn ($query, $date) => $query->whereDate('recognized_at', '>=', $date))
                ->when($validated['period_to'] ?? null, fn ($query, $date) => $query->whereDate('recognized_at', '<=', $date))
                ->lockForUpdate()->get();
            if ($entries->isEmpty()) {
                throw new UnprocessableEntityHttpException('Нет доступных признанных комиссий для формирования выплаты');
            }

            $payout = PartnerPayout::create([
                'public_id' => (string) Str::uuid(), 'number' => $this->generateNumber(), 'partner_id' => $validated['partner_id'],
                'status' => 'formed', 'amount' => $entries->sum('amount'), 'currency' => 'RUB', 'entries_count' => $entries->count(),
                'period_from' => $validated['period_from'] ?? null, 'period_to' => $validated['period_to'] ?? null,
                'comment' => $validated['comment'] ?? null, 'created_by' => $request->user()?->id,
            ]);
            PartnerCommissionEntry::query()->whereIn('id', $entries->modelKeys())->update(['partner_payout_id' => $payout->id]);
            Log::warning('Partner payout formed', ['user_id' => $request->user()?->id, 'payout_id' => $payout->id, 'partner_id' => $payout->partner_id, 'amount' => $payout->amount, 'entries_count' => $payout->entries_count]);

            return $payout;
        });

        return response()->json(['success' => true, 'message' => 'Выплата сформирована', 'data' => $payout->load('partner:id,code,name')], 201);
    }

    public function show(PartnerPayout $payout): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $payout->load(['partner:id,public_id,code,name', 'commissions.partnerOrder.shopOrder:id,order_number'])]);
    }

    public function markPaid(Request $request, PartnerPayout $payout): JsonResponse
    {
        $validated = $request->validate(['payment_reference' => ['required', 'string', 'max:255'], 'paid_at' => ['nullable', 'date'], 'comment' => ['nullable', 'string', 'max:4000']]);
        DB::transaction(function () use ($request, $payout, $validated): void {
            $payout = PartnerPayout::query()->lockForUpdate()->findOrFail($payout->id);
            if ($payout->status !== 'formed') {
                throw new ConflictHttpException('Выплату можно подтвердить только в статусе formed');
            }

            $entries = PartnerCommissionEntry::query()
                ->where('partner_payout_id', $payout->id)
                ->lockForUpdate()
                ->get();
            $this->assertPayoutIntegrity($payout, $entries);

            $paidAt = $validated['paid_at'] ?? now();
            $payout->update(['status' => 'paid', 'payment_reference' => $validated['payment_reference'], 'paid_at' => $paidAt, 'paid_by' => $request->user()?->id, 'comment' => $validated['comment'] ?? $payout->comment]);
            foreach ($entries as $entry) {
                $entry->update(['status' => 'paid', 'paid_at' => $paidAt]);
                $entry->partnerOrder?->update(['commission_status' => 'paid']);
            }
            Log::warning('Partner payout marked paid', [
                'user_id' => $request->user()?->id,
                'payout_id' => $payout->id,
                'partner_id' => $payout->partner_id,
                'entries_count' => $entries->count(),
            ]);
        });

        return response()->json(['success' => true, 'message' => 'Выплата отмечена оплаченной', 'data' => $payout->fresh()->load('partner:id,code,name')]);
    }

    public function cancel(Request $request, PartnerPayout $payout): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $payout, $validated): void {
            $payout = PartnerPayout::query()->lockForUpdate()->findOrFail($payout->id);
            if ($payout->status !== 'formed') {
                throw new ConflictHttpException('Отменить можно только сформированную выплату');
            }

            $entries = PartnerCommissionEntry::query()
                ->where('partner_payout_id', $payout->id)
                ->lockForUpdate()
                ->get();
            PartnerCommissionEntry::query()
                ->whereKey($entries->modelKeys())
                ->update(['partner_payout_id' => null]);
            $payout->update(['status' => 'cancelled', 'cancelled_at' => now(), 'metadata' => array_merge($payout->metadata ?? [], ['cancellation_reason' => $validated['reason']])]);
            Log::warning('Partner payout cancelled', [
                'user_id' => $request->user()?->id,
                'payout_id' => $payout->id,
                'partner_id' => $payout->partner_id,
                'released_entries_count' => $entries->count(),
            ]);
        });

        return response()->json(['success' => true, 'message' => 'Выплата отменена']);
    }

    private function generateNumber(): string
    {
        do {
            $number = 'PP-'.now()->format('Ymd').'-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (PartnerPayout::query()->where('number', $number)->exists());

        return $number;
    }

    /**
     * @param  Collection<int, PartnerCommissionEntry>  $entries
     */
    private function assertPayoutIntegrity(PartnerPayout $payout, Collection $entries): void
    {
        $reason = null;

        if ($entries->isEmpty() || $entries->count() !== $payout->entries_count) {
            $reason = 'commission_count_mismatch';
        } elseif ($entries->contains(fn (PartnerCommissionEntry $entry): bool => (int) $entry->partner_id !== (int) $payout->partner_id)) {
            $reason = 'commission_partner_mismatch';
        } elseif ($entries->contains(fn (PartnerCommissionEntry $entry): bool => $entry->status !== 'recognized')) {
            $reason = 'commission_status_mismatch';
        } elseif ($entries->contains(fn (PartnerCommissionEntry $entry): bool => (int) $entry->partner_payout_id !== (int) $payout->id)) {
            $reason = 'commission_payout_link_mismatch';
        } elseif ((int) round((float) $entries->sum('amount') * 100) !== (int) round((float) $payout->amount * 100)) {
            $reason = 'commission_amount_mismatch';
        }

        if ($reason === null) {
            return;
        }

        Log::warning('Partner payout integrity check failed', [
            'payout_id' => $payout->id,
            'partner_id' => $payout->partner_id,
            'reason' => $reason,
            'expected_entries_count' => $payout->entries_count,
            'actual_entries_count' => $entries->count(),
            'expected_amount' => round((float) $payout->amount, 2),
            'actual_amount' => round((float) $entries->sum('amount'), 2),
        ]);

        throw new ConflictHttpException('Состав выплаты изменён или повреждён. Обновите данные и сформируйте выплату заново');
    }
}
