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
            'video_url' => 'nullable|url',
            'variation_id' => 'nullable|exists:shop_good_variations,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'duration' => 'nullable|integer|min:0',
            'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB
            'is_main' => 'boolean',
            'sort_order' => 'integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        // Проверяем, что указан либо файл, либо URL
        if (!$request->hasFile('video') && !$request->filled('video_url')) {
            return response()->json([
                'success' => false,
                'message' => 'Необходимо указать либо файл видео, либо URL'
            ], 422);
        }

        try {
            $videoData = [
                'good_id' => $goodId,
                'title' => $request->get('title'),
                'description' => $request->get('description'),
                'duration' => $request->get('duration'),
                'is_main' => $request->boolean('is_main', false),
                'sort_order' => $request->get('sort_order', 0)
            ];

            if ($request->filled('variation_id')) {
                $variation = ShopGoodVariation::where('good_id', $goodId)
                    ->findOrFail($request->get('variation_id'));
                $videoData['variation_id'] = $variation->id;
            }

            // Загрузка файла видео
            if ($request->hasFile('video')) {
                $video = $request->file('video');
                $videoData['video_path'] = $video->store('shop/videos', 'public');
            }

            // URL видео
            if ($request->filled('video_url')) {
                $videoData['video_url'] = $request->get('video_url');
            }

            // Загрузка превью
            if ($request->hasFile('thumbnail')) {
                $thumbnail = $request->file('thumbnail');
                $videoData['thumbnail'] = $thumbnail->store('shop/videos/thumbnails', 'public');
            }

            $goodVideo = ShopGoodVideo::create($videoData);

            // Если это главное видео, снимаем флаг с других
            if ($goodVideo->is_main) {
                ShopGoodVideo::where('good_id', $goodId)
                    ->where('id', '!=', $goodVideo->id)
                    ->where('variation_id', $goodVideo->variation_id)
                    ->update(['is_main' => false]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Видео успешно загружено',
                'data' => $goodVideo
            ], 201);

        } catch (\Exception $e) {
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
            'description' => 'nullable|string',
            'duration' => 'nullable|integer|min:0',
            'video_url' => 'nullable|url',
            'is_main' => 'boolean',
            'sort_order' => 'integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        $video->update($request->only(['title', 'description', 'duration', 'video_url', 'is_main', 'sort_order']));

        // Если это главное видео, снимаем флаг с других
        if ($video->is_main) {
            ShopGoodVideo::where('good_id', $goodId)
                ->where('id', '!=', $video->id)
                ->where('variation_id', $video->variation_id)
                ->update(['is_main' => false]);
        }

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
