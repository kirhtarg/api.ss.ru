<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopGoodImage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopGoodImageController extends Controller
{
    /**
     * Получить изображения товара
     */
    public function index(ShopGood $good): JsonResponse
    {
        try {
            $images = $good->images()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $images
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображений: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Загрузить изображения товара
     */
    public function store(Request $request, ShopGood $good): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'images' => 'required|array|max:10',
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240', // 10MB max
            'upload_type' => 'required|in:original,system_crop,system_fit,custom_fit',
            'custom_width' => 'required_if:upload_type,custom_fit|integer|min:1|max:4000',
            'custom_height' => 'required_if:upload_type,custom_fit|integer|min:1|max:4000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $uploadedImages = [];
            $uploadType = $request->get('upload_type');
            $customWidth = $request->get('custom_width', 800);
            $customHeight = $request->get('custom_height', 600);

            // Получаем системные размеры из настроек
            $systemWidth = 800; // По умолчанию
            $systemHeight = 600; // По умолчанию
            
            // Пытаемся получить из настроек
            $widthSetting = \App\Models\Setting::where('key', 'shop_good_width')->first();
            $heightSetting = \App\Models\Setting::where('key', 'shop_good_height')->first();
            
            if ($widthSetting) {
                $systemWidth = (int)$widthSetting->value;
            }
            if ($heightSetting) {
                $systemHeight = (int)$heightSetting->value;
            }

            foreach ($request->file('images') as $file) {
                $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $path = 'shop/goods/' . $good->id . '/' . $filename;

                // Обрабатываем изображение в зависимости от типа
                $processedImage = $this->processImage($file, $uploadType, $systemWidth, $systemHeight, $customWidth, $customHeight);
                
                // Сохраняем файл
                Storage::disk('public')->put($path, $processedImage);

                // Получаем следующий порядок сортировки
                $nextSortOrder = $good->images()->max('sort_order') + 1;

                // Создаем запись в базе данных
                $image = ShopGoodImage::create([
                    'good_id' => $good->id,
                    'file_path' => $path,
                    'alt_text' => $file->getClientOriginalName(),
                    'is_main' => $good->images()->count() === 0, // Первое изображение становится главным
                    'sort_order' => $nextSortOrder
                ]);

                $uploadedImages[] = $image;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Изображения успешно загружены',
                'data' => $uploadedImages
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображений: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Установить главное изображение
     */
    public function setMain(ShopGood $good, ShopGoodImage $image): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Сбрасываем все изображения товара
            $good->images()->update(['is_main' => false]);

            // Устанавливаем выбранное как главное
            $image->update(['is_main' => true]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Главное изображение установлено'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ошибка установки главного изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить изображение
     */
    public function destroy(ShopGood $good, ShopGoodImage $image): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Удаляем файл
            if (Storage::disk('public')->exists($image->file_path)) {
                Storage::disk('public')->delete($image->file_path);
            }

            // Удаляем запись из базы
            $image->delete();

            // Если это было главное изображение, устанавливаем новое главное
            if ($image->is_main) {
                $newMainImage = $good->images()->first();
                if ($newMainImage) {
                    $newMainImage->update(['is_main' => true]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Изображение удалено'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Изменить порядок изображений
     */
    public function reorder(Request $request, ShopGood $good): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order' => 'required|array',
            'order.*.id' => 'required|integer',
            'order.*.sort_order' => 'required|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        // Проверяем, что все изображения принадлежат данному товару
        $imageIds = collect($request->get('order'))->pluck('id');
        $existingImages = $good->images()->whereIn('id', $imageIds)->pluck('id');
        
        if ($existingImages->count() !== $imageIds->count()) {
            return response()->json([
                'success' => false,
                'message' => 'Некоторые изображения не принадлежат данному товару'
            ], 422);
        }

        try {
            DB::beginTransaction();

            foreach ($request->get('order') as $item) {
                ShopGoodImage::where('id', $item['id'])
                    ->where('good_id', $good->id)
                    ->update(['sort_order' => $item['sort_order']]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Порядок изображений обновлен'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления порядка: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обработать изображение в зависимости от типа
     */
    private function processImage($file, $uploadType, $systemWidth, $systemHeight, $customWidth = null, $customHeight = null)
    {
        $image = imagecreatefromstring(file_get_contents($file->getRealPath()));
        
        if (!$image) {
            throw new \Exception('Не удалось обработать изображение');
        }

        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);

        switch ($uploadType) {
            case 'original':
                // Возвращаем оригинальное изображение
                ob_start();
                imagejpeg($image, null, 90);
                $result = ob_get_contents();
                ob_end_clean();
                imagedestroy($image);
                return $result;

            case 'system_crop':
                return $this->cropImage($image, $systemWidth, $systemHeight);

            case 'system_fit':
                return $this->fitImage($image, $systemWidth, $systemHeight);

            case 'custom_fit':
                return $this->fitImage($image, $customWidth, $customHeight);

            default:
                imagedestroy($image);
                throw new \Exception('Неизвестный тип обработки изображения');
        }
    }

    /**
     * Обрезать изображение под заданные размеры
     */
    private function cropImage($image, $targetWidth, $targetHeight)
    {
        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);

        // Вычисляем масштаб
        $scale = max($targetWidth / $originalWidth, $targetHeight / $originalHeight);
        
        // Новые размеры с сохранением пропорций
        $newWidth = (int)($originalWidth * $scale);
        $newHeight = (int)($originalHeight * $scale);

        // Создаем временное изображение
        $tempImage = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($tempImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        // Создаем финальное изображение
        $finalImage = imagecreatetruecolor($targetWidth, $targetHeight);
        
        // Вычисляем координаты для обрезки по центру
        $x = (int)(($newWidth - $targetWidth) / 2);
        $y = (int)(($newHeight - $targetHeight) / 2);
        
        imagecopy($finalImage, $tempImage, 0, 0, $x, $y, $targetWidth, $targetHeight);

        ob_start();
        imagejpeg($finalImage, null, 90);
        $result = ob_get_contents();
        ob_end_clean();

        imagedestroy($image);
        imagedestroy($tempImage);
        imagedestroy($finalImage);

        return $result;
    }

    /**
     * Подогнать изображение под заданные размеры с заполнением
     */
    private function fitImage($image, $targetWidth, $targetHeight)
    {
        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);

        // Вычисляем масштаб
        $scale = min($targetWidth / $originalWidth, $targetHeight / $originalHeight);
        
        // Новые размеры с сохранением пропорций
        $newWidth = (int)($originalWidth * $scale);
        $newHeight = (int)($originalHeight * $scale);

        // Создаем финальное изображение с белым фоном
        $finalImage = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($finalImage, 255, 255, 255);
        imagefill($finalImage, 0, 0, $white);

        // Вычисляем координаты для центрирования
        $x = (int)(($targetWidth - $newWidth) / 2);
        $y = (int)(($targetHeight - $newHeight) / 2);

        // Создаем масштабированное изображение
        $scaledImage = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($scaledImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        // Копируем на белый фон
        imagecopy($finalImage, $scaledImage, $x, $y, 0, 0, $newWidth, $newHeight);

        ob_start();
        imagejpeg($finalImage, null, 90);
        $result = ob_get_contents();
        ob_end_clean();

        imagedestroy($image);
        imagedestroy($scaledImage);
        imagedestroy($finalImage);

        return $result;
    }
}
