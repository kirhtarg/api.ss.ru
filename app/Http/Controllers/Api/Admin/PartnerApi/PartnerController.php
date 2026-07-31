<?php

namespace App\Http\Controllers\Api\Admin\PartnerApi;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerCommissionEntry;
use App\Models\PartnerOrder;
use App\Models\PartnerWebhookDelivery;
use App\Services\Partner\PartnerWebhookUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PartnerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', Rule::in(['name', 'code', 'commission_rate', 'created_at', 'updated_at'])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        Log::debug('Partner admin list requested', [
            'user_id' => $request->user()?->id,
            'filters' => $validated,
        ]);

        $partners = Partner::query()
            ->withCount(['credentials', 'orders'])
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('public_id', 'like', "%{$search}%");
                });
            })
            ->when(array_key_exists('is_active', $validated), fn ($query) => $query->where('is_active', $validated['is_active']))
            ->orderBy($validated['sort_by'] ?? 'created_at', $validated['sort_order'] ?? 'desc')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json(['success' => true, 'data' => $partners]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePartner($request);
        $partner = Partner::create([
            ...$validated,
            'public_id' => (string) Str::uuid(),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        Log::info('Partner created by administrator', [
            'user_id' => $request->user()?->id,
            'partner_id' => $partner->id,
            'partner_code' => $partner->code,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Партнёр создан',
            'data' => $partner,
        ], 201);
    }

    public function show(Partner $partner): JsonResponse
    {
        $partner->loadCount(['credentials', 'orders']);

        return response()->json([
            'success' => true,
            'data' => $partner,
        ]);
    }

    public function update(Request $request, Partner $partner): JsonResponse
    {
        $validated = $this->validatePartner($request, $partner);
        $partner->update($validated);

        Log::info('Partner updated by administrator', [
            'user_id' => $request->user()?->id,
            'partner_id' => $partner->id,
            'changed_fields' => array_keys($partner->getChanges()),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Партнёр обновлён',
            'data' => $partner->fresh(),
        ]);
    }

    public function destroy(Request $request, Partner $partner): JsonResponse
    {
        if ($partner->orders()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Партнёра с заказами нельзя удалить. Деактивируйте его.',
            ], 409);
        }

        $partnerId = $partner->id;
        $partnerCode = $partner->code;
        $partner->delete();

        Log::warning('Partner deleted by administrator', [
            'user_id' => $request->user()?->id,
            'partner_id' => $partnerId,
            'partner_code' => $partnerCode,
        ]);

        return response()->json(['success' => true, 'message' => 'Партнёр удалён']);
    }

    public function statistics(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'partners' => [
                    'total' => Partner::query()->count(),
                    'active' => Partner::query()->where('is_active', true)->count(),
                ],
                'orders' => [
                    'total' => PartnerOrder::query()->count(),
                    'amount' => (float) PartnerOrder::query()->sum('total_amount'),
                ],
                'commissions' => [
                    'pending' => (float) PartnerCommissionEntry::query()->where('status', 'pending')->sum('amount'),
                    'recognized' => (float) PartnerCommissionEntry::query()->where('status', 'recognized')->sum('amount'),
                    'paid' => (float) PartnerCommissionEntry::query()->where('status', 'paid')->sum('amount'),
                ],
                'webhooks' => [
                    'pending' => PartnerWebhookDelivery::query()->whereIn('status', ['pending', 'processing', 'retrying'])->count(),
                    'failed' => PartnerWebhookDelivery::query()->where('status', 'failed')->count(),
                ],
            ],
        ]);
    }

    private function validatePartner(Request $request, ?Partner $partner = null): array
    {
        return $request->validate([
            'code' => [
                'required',
                'string',
                'max:64',
                'alpha_dash:ascii',
                Rule::unique('partners', 'code')->ignore($partner?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'commission_status' => ['required', Rule::in(['after_paid', 'after_completed'])],
            'webhook_url' => [
                'nullable',
                'url:http,https',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    try {
                        app(PartnerWebhookUrlGuard::class)->assertAllowed($value);
                    } catch (\RuntimeException $exception) {
                        Log::warning('Partner webhook URL rejected during admin validation', [
                            'host' => parse_url($value, PHP_URL_HOST),
                            'reason' => $exception->getMessage(),
                        ]);
                        $fail('Webhook URL должен использовать разрешённый публичный HTTPS-адрес.');
                    }
                },
            ],
            'allowed_ips' => ['nullable', 'array', 'max:100'],
            'allowed_ips.*' => ['required', 'ip'],
            'metadata' => ['nullable', 'array'],
        ]);
    }
}
