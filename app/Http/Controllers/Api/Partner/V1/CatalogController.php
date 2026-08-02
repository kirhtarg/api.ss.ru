<?php

namespace App\Http\Controllers\Api\Partner\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Partner\V1\CategoryResource;
use App\Http\Resources\Partner\V1\BrandResource;
use App\Http\Resources\Partner\V1\ProductResource;
use App\Models\ShopGood;
use App\Services\Partner\PartnerCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CatalogController extends Controller
{
    public function __construct(private PartnerCatalogService $catalog) {}

    public function categories(Request $request)
    {
        $filters = $request->validate($this->syncRules());
        if (! $request->hasAny(['page', 'per_page', 'updated_since', 'cursor'])) {
            return response()->json([
                'success' => true,
                'data' => CategoryResource::collection($this->catalog->activeCategories())->resolve($request),
            ]);
        }
        $page = $this->catalog->categories($filters);

        return response()->json($this->paginated($page, CategoryResource::collection($page->getCollection())->resolve($request)));
    }

    public function index(Request $request)
    {
        $startedAt = hrtime(true);
        try {
            $data = $request->validate(array_merge($this->syncRules(), [
                'category_id' => 'nullable|integer',
                'brand_id' => 'nullable|integer',
                'q' => 'nullable|string|max:120',
                'include_unavailable' => 'nullable|boolean',
            ]));
            $page = $this->catalog->products($data);
            $this->logResult($request, 'catalog/products', $data, $page->count(), $startedAt);

            return response()->json($this->paginated($page, ProductResource::collection($page->getCollection())->resolve($request)));
        } catch (Throwable $exception) {
            $this->logError($request, 'catalog/products', $startedAt, $exception);
            throw $exception;
        }
    }

    public function brands(Request $request)
    {
        $startedAt = hrtime(true);
        try {
            $brands = $this->catalog->activeBrands();
            $this->logResult($request, 'catalog/brands', [], $brands->count(), $startedAt);

            return response()->json([
                'success' => true,
                'data' => BrandResource::collection($brands)->resolve($request),
            ]);
        } catch (Throwable $exception) {
            $this->logError($request, 'catalog/brands', $startedAt, $exception);
            throw $exception;
        }
    }

    public function show(Request $request, ShopGood $good)
    {
        $startedAt = hrtime(true);
        try {
            $product = $this->catalog->product($good);
            $this->logResult($request, 'catalog/products/{good}', ['product_id' => $good->id], 1, $startedAt);

            return response()->json(['success' => true, 'data' => (new ProductResource($product))->resolve($request)]);
        } catch (Throwable $exception) {
            $this->logError($request, 'catalog/products/{good}', $startedAt, $exception);
            throw $exception;
        }
    }

    private function syncRules(): array
    {
        return [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'updated_since' => 'nullable|date',
            'cursor' => 'nullable|string|max:512',
        ];
    }

    private function paginated($page, array $items): array
    {
        $last = $page->getCollection()->last();

        return [
            'success' => true,
            'data' => [
                'current_page' => $page->currentPage(),
                'data' => $items,
                'first_page_url' => $page->url(1),
                'from' => $page->firstItem(),
                'last_page' => $page->lastPage(),
                'last_page_url' => $page->url($page->lastPage()),
                'next_page_url' => $page->nextPageUrl(),
                'path' => $page->path(),
                'per_page' => $page->perPage(),
                'prev_page_url' => $page->previousPageUrl(),
                'to' => $page->lastItem(),
                'total' => $page->total(),
            ],
            'meta' => [
                'next_cursor' => $page->hasMorePages() ? $this->catalog->cursorFor($last) : null,
                'sort' => ['updated_at', 'id'],
                'inactive_in_incremental_feed' => true,
            ],
        ];
    }

    private function logResult(Request $request, string $endpoint, array $filters, int $count, int $startedAt): void
    {
        Log::debug('[FIX:partner-catalog-contract] Partner catalog request completed', [
            'endpoint' => $endpoint,
            'partner_id' => $request->attributes->get('partner')?->id,
            'filters' => collect($filters)->only(['product_id', 'category_id', 'brand_id', 'q', 'page', 'per_page', 'updated_since', 'include_unavailable'])->all(),
            'result_count' => $count,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'error_code' => null,
        ]);
    }

    private function logError(Request $request, string $endpoint, int $startedAt, Throwable $exception): void
    {
        Log::warning('[FIX:partner-catalog-contract] Partner catalog request failed', [
            'endpoint' => $endpoint,
            'partner_id' => $request->attributes->get('partner')?->id,
            'filters' => $request->only(['category_id', 'brand_id', 'q', 'page', 'per_page', 'updated_since']),
            'result_count' => 0,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'error_code' => class_basename($exception),
        ]);
    }
}
