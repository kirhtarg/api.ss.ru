<?php

namespace App\Http\Controllers\Api\Admin\PartnerApi;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerApiCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CredentialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partner_id' => ['nullable', 'integer', 'exists:partners,id'],
            'status' => ['nullable', Rule::in(['active', 'revoked', 'expired'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $credentials = PartnerApiCredential::query()
            ->with('partner:id,public_id,code,name')
            ->when($validated['partner_id'] ?? null, fn ($query, $partnerId) => $query->where('partner_id', $partnerId))
            ->when($validated['status'] ?? null, function ($query, string $status): void {
                match ($status) {
                    'active' => $query->whereNull('revoked_at')->where(fn ($expires) => $expires->whereNull('expires_at')->orWhere('expires_at', '>', now())),
                    'revoked' => $query->whereNotNull('revoked_at'),
                    'expired' => $query->whereNull('revoked_at')->where('expires_at', '<=', now()),
                };
            })
            ->latest()
            ->paginate($validated['per_page'] ?? 50);

        return response()->json([
            'success' => true,
            'data' => $credentials,
            'meta' => ['available_scopes' => config('partners.available_scopes', [])],
        ]);
    }

    public function store(Request $request, Partner $partner): JsonResponse
    {
        $validated = $this->validateCredential($request);
        [$credential, $secret] = $this->issue($partner, $validated);

        Log::warning('Partner API credential issued', [
            'user_id' => $request->user()?->id,
            'partner_id' => $partner->id,
            'credential_id' => $credential->id,
            'key_id' => $credential->key_id,
            'scopes' => $credential->scopes,
        ]);

        return $this->secretResponse($credential, $secret, 'API-ключ выпущен');
    }

    public function rotate(Request $request, PartnerApiCredential $credential): JsonResponse
    {
        $validated = $request->validate([
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', Rule::in(config('partners.available_scopes', []))],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        [$replacement, $secret] = DB::transaction(function () use ($credential, $validated): array {
            $credential = PartnerApiCredential::query()->with('partner')->lockForUpdate()->findOrFail($credential->id);
            $credential->update(['revoked_at' => now()]);

            return $this->issue($credential->partner, [
                'scopes' => $validated['scopes'] ?? $credential->scopes,
                'expires_at' => array_key_exists('expires_at', $validated) ? $validated['expires_at'] : $credential->expires_at,
            ]);
        });

        Log::warning('Partner API credential rotated', [
            'user_id' => $request->user()?->id,
            'partner_id' => $credential->partner_id,
            'revoked_credential_id' => $credential->id,
            'new_credential_id' => $replacement->id,
            'new_key_id' => $replacement->key_id,
        ]);

        return $this->secretResponse($replacement, $secret, 'API-ключ ротирован');
    }

    public function destroy(Request $request, PartnerApiCredential $credential): JsonResponse
    {
        if (! $credential->revoked_at) {
            $credential->update(['revoked_at' => now()]);
        }

        Log::warning('Partner API credential revoked', [
            'user_id' => $request->user()?->id,
            'partner_id' => $credential->partner_id,
            'credential_id' => $credential->id,
            'key_id' => $credential->key_id,
        ]);

        return response()->json(['success' => true, 'message' => 'API-ключ отозван']);
    }

    private function validateCredential(Request $request): array
    {
        return $request->validate([
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', Rule::in(config('partners.available_scopes', []))],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
    }

    private function issue(Partner $partner, array $attributes): array
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $credential = $partner->credentials()->create([
            'key_id' => 'pk_live_'.Str::lower(Str::random(24)),
            'secret' => $secret,
            'scopes' => array_values(array_unique($attributes['scopes'])),
            'expires_at' => $attributes['expires_at'] ?? null,
        ]);

        return [$credential->load('partner:id,public_id,code,name'), $secret];
    }

    private function secretResponse(PartnerApiCredential $credential, string $secret, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'credential' => $credential,
                'secret' => $secret,
                'secret_visible_once' => true,
            ],
        ], 201);
    }
}
