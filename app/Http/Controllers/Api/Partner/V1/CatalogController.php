<?php

namespace App\Http\Controllers\Api\Partner\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Partner\V1\CategoryResource;
use App\Http\Resources\Partner\V1\ProductResource;
use App\Models\ShopGood;
use App\Services\Partner\PartnerCatalogService;
use Illuminate\Http\Request;

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
        $data = $request->validate(array_merge($this->syncRules(), [
            'category_id' => 'nullable|integer',
            'q' => 'nullable|string|max:120',
        ]));
        $page = $this->catalog->products($data);

        return response()->json($this->paginated($page, ProductResource::collection($page->getCollection())->resolve($request)));
    }

    public function show(ShopGood $good)
    {
        return response()->json(['success' => true, 'data' => (new ProductResource($this->catalog->product($good)))->resolve(request())]);
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
}
