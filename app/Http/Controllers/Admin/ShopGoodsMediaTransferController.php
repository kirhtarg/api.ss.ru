<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ShopGoodsMediaTransferController extends Controller
{
    /**
     * Перенос медиа из одного основного товара в другой (main-to-main)
     */
    public function transferMediaMainToMain(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'target_good_id' => 'required|exists:shop_goods,id',
            'donor_good_ids' => 'required|array|min:1',
            'donor_good_ids.*' => 'exists:shop_goods,id',
            'copy_description' => 'boolean',
            'copy_short_description' => 'boolean',
            'description_source_id' => 'nullable|exists:shop_goods,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $targetGoodId = $request->input('target_good_id');
            $donorGoodIds = $request->input('donor_good_ids');
            $copyDescription = $request->boolean('copy_description', true);
            $copyShortDescription = $request->boolean('copy_short_description', true);
            $descriptionSourceId = $request->input('description_source_id');

            $targetGood = ShopGood::find($targetGoodId);
            if (!$targetGood) {
                throw new \Exception("Целевой товар не найден");
            }

            // Перенос описаний
            if (($copyDescription || $copyShortDescription) && $descriptionSourceId) {
                $sourceGood = ShopGood::find($descriptionSourceId);
                if ($sourceGood && in_array($descriptionSourceId, $donorGoodIds)) {
                    $updateData = [];
                    if ($copyDescription && $sourceGood->description) {
                        $updateData['description'] = $sourceGood->description;
                    }
                    if ($copyShortDescription && $sourceGood->short_description) {
                        $updateData['short_description'] = $sourceGood->short_description;
                    }

                    if (!empty($updateData)) {
                        $targetGood->update($updateData);
                    }
                }
            }

            $totalImagesTransferred = 0;
            $deletedGoodsCount = 0;

            foreach ($donorGoodIds as $donorId) {
                if ($donorId == $targetGoodId) {
                    continue;
                }

                // Переносим изображения (только основные, без variation_id)
                // Сбрасываем статус главного (is_main), чтобы не было конфликтов в целевом товаре
                $imagesTransferred = DB::table('shop_good_images')
                    ->where('good_id', $donorId)
                    ->whereNull('variation_id')
                    ->update([
                        'good_id' => $targetGoodId,
                        'is_main' => 0,
                        'updated_at' => now(),
                    ]);

                $totalImagesTransferred += $imagesTransferred;

                // Удаляем товар-донор
                $donorGood = ShopGood::find($donorId);
                if ($donorGood) {
                    $donorGood->delete();
                    $deletedGoodsCount++;
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Медиа успешно перенесено. Перенесено изображений: {$totalImagesTransferred}. Удалено товаров: {$deletedGoodsCount}.",
                'data' => [
                    'images_transferred_count' => $totalImagesTransferred,
                    'deleted_goods_count' => $deletedGoodsCount,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Ошибка переноса медиа main-to-main: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка переноса медиа: '.$e->getMessage(),
            ], 500);
        }
    }

}
