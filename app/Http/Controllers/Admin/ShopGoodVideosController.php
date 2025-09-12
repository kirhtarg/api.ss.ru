<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopGoodVideo;
use App\Models\ShopGoodVariation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ShopGoodVideosController extends Controller
{
    /**
     * Получить видео товара
     */
    public function index(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);
        
        $query = $good->videos();
        
        // Фильтр по вариации
        if ($request->filled('variation_id')) {
            $query->where('variation_id', $request->get('variation_id'));
        }

        $videos = $query->ordered()->get();
        

        return response()->json([
            'success' => true,
            'data' => $videos
        ]);
    }

    /**
     * Загрузить видео
     */
    public function store(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);

        $validator = Validator::make($request->all(), [
            'video' => 'nullable|file|mimes:mp4,avi,mov,wmv,flv,webm|max:102400', // 100MB
            'external_url' => 'nullable|url',
            'variation_id' => 'nullable|integer|exists:shop_good_variations,id',
            'title' => 'nullable|string|max:255',
            'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120' // 5MB
        ]);

        if ($validator->fails()) {
            Log::error('Video validation failed', [
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        // Проверяем, что указан либо файл, либо URL
        if (!$request->hasFile('video') && !$request->filled('external_url')) {
            Log::error('No video file or external URL provided', [
                'has_file' => $request->hasFile('video'),
                'external_url' => $request->get('external_url'),
                'filled_external_url' => $request->filled('external_url')
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Необходимо указать либо файл видео, либо URL'
            ], 422);
        }

        // Дополнительная проверка размера файла
        if ($request->hasFile('video')) {
            $file = $request->file('video');
            $fileSize = $file->getSize();
            $maxSize = 100 * 1024 * 1024; // 100MB в байтах
            
            if ($fileSize > $maxSize) {
                Log::error('Video file too large', [
                    'file_size' => $fileSize,
                    'max_size' => $maxSize,
                    'file_name' => $file->getClientOriginalName()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Размер файла превышает 100MB. Пожалуйста, выберите файл меньшего размера или используйте внешнюю ссылку.'
                ], 413);
            }
        }

        try {
            $videoData = [
                'good_id' => $goodId,
                'title' => $request->get('title') ?: null
            ];


            if ($request->filled('variation_id') && $request->get('variation_id') !== null) {
                $variation = ShopGoodVariation::where('good_id', $goodId)
                    ->findOrFail($request->get('variation_id'));
                $videoData['variation_id'] = $variation->id;
            }

            // Загрузка файла видео
            if ($request->hasFile('video')) {
                $video = $request->file('video');
                $videoData['video_path'] = $video->store('videos/goods', 'public');
            }

            // URL видео
            if ($request->filled('external_url')) {
                $videoData['external_url'] = $request->get('external_url');
                Log::info('External URL added', ['external_url' => $videoData['external_url']]);
            }

            // Загрузка превью
            if ($request->hasFile('thumbnail')) {
                $thumbnail = $request->file('thumbnail');
                $videoData['thumbnail'] = $thumbnail->store('shop/videos/thumbnails', 'public');
            }

            Log::info('Creating video record', $videoData);
            
            // Проверяем, что все необходимые поля заполнены
            if (empty($videoData['external_url']) && empty($videoData['video_path'])) {
                Log::error('Neither external_url nor video_path provided', $videoData);
                return response()->json([
                    'success' => false,
                    'message' => 'Необходимо указать либо файл видео, либо URL'
                ], 422);
            }
            
            $goodVideo = ShopGoodVideo::create($videoData);
            
            // Перезагружаем модель, чтобы получить accessors
            $goodVideo->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Видео успешно загружено',
                'data' => $goodVideo
            ], 201);

        } catch (\Exception $e) {
            Log::error('Video store error', [
                'good_id' => $goodId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки видео: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить видео
     */
    public function update(Request $request, $goodId, $videoId): JsonResponse
    {
        $video = ShopGoodVideo::where('good_id', $goodId)->findOrFail($videoId);

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'external_url' => 'nullable|url'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $video->update($request->only(['title', 'external_url']));

        return response()->json([
            'success' => true,
            'message' => 'Видео успешно обновлено',
            'data' => $video
        ]);
    }

    /**
     * Удалить видео
     */
    public function destroy($goodId, $videoId): JsonResponse
    {
        $video = ShopGoodVideo::where('good_id', $goodId)->findOrFail($videoId);

        try {
            // Удаляем файлы
            if ($video->video_path && Storage::disk('public')->exists($video->video_path)) {
                Storage::disk('public')->delete($video->video_path);
            }

            if ($video->thumbnail && Storage::disk('public')->exists($video->thumbnail)) {
                Storage::disk('public')->delete($video->thumbnail);
            }

            $video->delete();

            return response()->json([
                'success' => true,
                'message' => 'Видео успешно удалено'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления видео: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Установить главное видео
     */
    public function setMain($goodId, $videoId): JsonResponse
    {
        $video = ShopGoodVideo::where('good_id', $goodId)->findOrFail($videoId);

        // Снимаем флаг с других видео
        ShopGoodVideo::where('good_id', $goodId)
            ->where('id', '!=', $video->id)
            ->where('variation_id', $video->variation_id)
            ->update(['is_main' => false]);

        // Устанавливаем флаг для выбранного видео
        $video->update(['is_main' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Главное видео установлено'
        ]);
    }

    /**
     * Изменить порядок видео
     */
    public function reorder(Request $request, $goodId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'videos' => 'required|array',
            'videos.*.id' => 'required|exists:shop_good_videos,id',
            'videos.*.sort_order' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        foreach ($request->get('videos') as $videoData) {
            ShopGoodVideo::where('good_id', $goodId)
                ->where('id', $videoData['id'])
                ->update(['sort_order' => $videoData['sort_order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Порядок видео обновлен'
        ]);
    }
}
