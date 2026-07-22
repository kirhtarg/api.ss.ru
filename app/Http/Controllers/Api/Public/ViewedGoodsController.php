<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ViewedGoodsController extends Controller
{
    private const LIMIT = 50;

    public function index(Request $request): JsonResponse
    {
        $items = DB::table('shop_good_views')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('viewed_at')
            ->limit(self::LIMIT)
            ->get(['good_id', 'viewed_at']);

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'good_id' => ['required', 'integer', 'exists:shop_goods,id'],
        ]);
        $userId = $request->user()->id;
        $now = now();

        DB::transaction(function () use ($userId, $validated, $now): void {
            DB::table('shop_good_views')->updateOrInsert(
                ['user_id' => $userId, 'good_id' => $validated['good_id']],
                ['viewed_at' => $now, 'updated_at' => $now, 'created_at' => $now]
            );

            $staleIds = DB::table('shop_good_views')
                ->where('user_id', $userId)
                ->orderByDesc('viewed_at')
                ->orderByDesc('id')
                ->skip(self::LIMIT)
                ->pluck('id');

            if ($staleIds->isNotEmpty()) {
                DB::table('shop_good_views')->whereIn('id', $staleIds)->delete();
            }
        });

        return response()->json(['success' => true, 'data' => ['good_id' => (int) $validated['good_id'], 'viewed_at' => $now->toISOString()]]);
    }

    public function destroy(Request $request, int $goodId): JsonResponse
    {
        DB::table('shop_good_views')
            ->where('user_id', $request->user()->id)
            ->where('good_id', $goodId)
            ->delete();

        return response()->json(['success' => true]);
    }

    public function clear(Request $request): JsonResponse
    {
        DB::table('shop_good_views')->where('user_id', $request->user()->id)->delete();

        return response()->json(['success' => true]);
    }
}
