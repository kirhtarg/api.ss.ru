<?php

namespace App\Http\Controllers\Api\Partner\V1;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Services\Partner\PartnerCatalogService;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(private PartnerCatalogService $catalog) {}

    public function categories()
    {
        return response()->json(['success' => true, 'data' => $this->catalog->categories()]);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['category_id' => 'nullable|integer', 'q' => 'nullable|string|max:120', 'per_page' => 'nullable|integer|min:1|max:100']);

        return response()->json(['success' => true, 'data' => $this->catalog->products($data)]);
    }

    public function show(ShopGood $good)
    {
        return response()->json(['success' => true, 'data' => $this->catalog->product($good)]);
    }
}
