<?php

namespace App\Http\Controllers\Api\Admin\PartnerApi;

use App\Http\Controllers\Controller;
use App\Models\PartnerApiRequestLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class RequestLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partner_id' => ['nullable', 'integer', 'exists:partners,id'],
            'endpoint' => ['nullable', 'string', 'max:500'],
            'response_status' => ['nullable', 'integer', 'min:100', 'max:599'],
            'status_group' => ['nullable', Rule::in(['2xx', '3xx', '4xx', '5xx'])],
            'request_id' => ['nullable', 'string', 'max:80'],
            'method' => ['nullable', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        Log::debug('Partner API request journal requested', ['user_id' => $request->user()?->id, 'filters' => $validated]);
        $query = PartnerApiRequestLog::query()
            ->with('partner:id,public_id,code,name')
            ->when($validated['partner_id'] ?? null, fn ($builder, $id) => $builder->where('partner_id', $id))
            ->when($validated['endpoint'] ?? null, fn ($builder, $endpoint) => $builder->where('path', 'like', "%{$endpoint}%"))
            ->when($validated['response_status'] ?? null, fn ($builder, $status) => $builder->where('response_status', $status))
            ->when($validated['status_group'] ?? null, function ($builder, string $group): void {
                $start = ((int) $group[0]) * 100;
                $builder->whereBetween('response_status', [$start, $start + 99]);
            })
            ->when($validated['request_id'] ?? null, fn ($builder, $id) => $builder->where('request_id', 'like', "%{$id}%"))
            ->when($validated['method'] ?? null, fn ($builder, $method) => $builder->where('method', $method))
            ->when($validated['date_from'] ?? null, fn ($builder, $date) => $builder->whereDate('created_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($builder, $date) => $builder->whereDate('created_at', '<=', $date));

        $summary = [
            'total' => (clone $query)->count(),
            'successful' => (clone $query)->whereBetween('response_status', [200, 299])->count(),
            'client_errors' => (clone $query)->whereBetween('response_status', [400, 499])->count(),
            'server_errors' => (clone $query)->whereBetween('response_status', [500, 599])->count(),
            'average_duration_ms' => round((float) ((clone $query)->avg('duration_ms') ?? 0), 1),
        ];

        return response()->json([
            'success' => true,
            'data' => $query->latest('id')->paginate($validated['per_page'] ?? 50),
            'meta' => ['summary' => $summary],
        ]);
    }
}
