<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopGoodVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            'data' => $videos,
        ]);
    }

    /**
     * Получить все видео товара с группировкой по вариациям
     */
    public function getAllWithVariations(Request $request, $goodId): JsonResponse
    {
        $good = ShopGood::findOrFail($goodId);

        // Получаем все видео товара (где good_id = $goodId И variation_id = null)
        $goodVideos = ShopGoodVideo::where('good_id', $goodId)
            ->whereNull('variation_id')
            ->ordered()
            ->get();

        // Получаем все видео вариаций этого товара (где good_id = null И variation_id принадлежит вариациям товара)
        $variationIds = ShopGoodVariation::where('good_id', $goodId)->pluck('id');
        $variationVideos = ShopGoodVideo::whereIn('variation_id', $variationIds)
            ->whereNull('good_id')
            ->ordered()
            ->get()
            ->groupBy('variation_id');

        return response()->json([
            'success' => true,
            'data' => [
                'good' => $goodVideos,
                'variations' => $variationVideos,
            ],
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
            'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB
        ]);

        if ($validator->fails()) {
            Log::error('Video validation failed', [
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Проверяем, что указан либо файл, либо URL
        if (! $request->hasFile('video') && ! $request->filled('external_url')) {
            Log::error('No video file or external URL provided', [
                'has_file' => $request->hasFile('video'),
                'external_url' => $request->get('external_url'),
                'filled_external_url' => $request->filled('external_url'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Необходимо указать либо файл видео, либо URL',
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
                    'file_name' => $file->getClientOriginalName(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Размер файла превышает 100MB. Пожалуйста, выберите файл меньшего размера или используйте внешнюю ссылку.',
                ], 413);
            }
        }

        try {
            $videoData = [
                'title' => $request->get('title') ?: null,
            ];

            // Определяем, для товара или вариации создаем видео
            if ($request->filled('variation_id') && $request->get('variation_id') !== null) {
                // Для вариации: good_id = null, variation_id = ID вариации
                $variation = ShopGoodVariation::where('good_id', $goodId)
                    ->findOrFail($request->get('variation_id'));
                $videoData['variation_id'] = $variation->id;
                $videoData['good_id'] = null;
            } else {
                // Для товара: good_id = ID товара, variation_id = null
                $videoData['good_id'] = $goodId;
                $videoData['variation_id'] = null;
            }

            // Загрузка файла видео
            if ($request->hasFile('video')) {
                $video = $request->file('video');
                $filename = uniqid().'.'.$video->getClientOriginalExtension();
                $path = 'videos/goods/'.$goodId.'/'.$filename;

                // Путь к папке public фронтенда
                $frontendPublicPath = frontend_public_path();
                $fullPath = $frontendPublicPath.'/'.$path;
                $dir = dirname($fullPath);

                Log::info('Video upload debug:', [
                    'filename' => $filename,
                    'path' => $path,
                    'frontendPublicPath' => $frontendPublicPath,
                    'fullPath' => $fullPath,
                    'dir' => $dir,
                ]);

                // Создаем директорию, если её нет
                if (! is_dir($dir)) {
                    Log::info('Creating directory: '.$dir);
                    $mkdirResult = mkdir($dir, 0755, true);
                    Log::info('mkdir result: '.($mkdirResult ? 'success' : 'failed'));
                }

                Log::info('Moving file to: '.$fullPath);
                $moveResult = $video->move($dir, $filename);
                Log::info('File move result: '.($moveResult ? 'success' : 'failed'));

                if (file_exists($fullPath)) {
                    Log::info('File successfully saved: '.$fullPath);
                    Log::info('File size: '.filesize($fullPath).' bytes');
                } else {
                    Log::error('File was not saved: '.$fullPath);
                }

                $videoData['video_path'] = $path;
            }

            // URL видео
            if ($request->filled('external_url')) {
                $videoData['external_url'] = $request->get('external_url');
                Log::info('External URL added', ['external_url' => $videoData['external_url']]);
            }

            // Загрузка превью
            if ($request->hasFile('thumbnail')) {
                $thumbnail = $request->file('thumbnail');
                $filename = uniqid().'.'.$thumbnail->getClientOriginalExtension();
                $path = 'images/shop/videos/thumbnails/'.$goodId.'/'.$filename;

                // Путь к папке public фронтенда
                $frontendPublicPath = frontend_public_path();
                $fullPath = $frontendPublicPath.'/'.$path;
                $dir = dirname($fullPath);

                Log::info('Thumbnail upload debug:', [
                    'filename' => $filename,
                    'path' => $path,
                    'fullPath' => $fullPath,
                    'dir' => $dir,
                ]);

                // Создаем директорию, если её нет
                if (! is_dir($dir)) {
                    Log::info('Creating thumbnail directory: '.$dir);
                    $mkdirResult = mkdir($dir, 0755, true);
                    Log::info('Thumbnail mkdir result: '.($mkdirResult ? 'success' : 'failed'));
                }

                Log::info('Moving thumbnail to: '.$fullPath);
                $moveResult = $thumbnail->move($dir, $filename);
                Log::info('Thumbnail move result: '.($moveResult ? 'success' : 'failed'));

                if (file_exists($fullPath)) {
                    Log::info('Thumbnail successfully saved: '.$fullPath);
                    Log::info('Thumbnail size: '.filesize($fullPath).' bytes');
                } else {
                    Log::error('Thumbnail was not saved: '.$fullPath);
                }

                $videoData['thumbnail'] = $path;
            }

            // Устанавливаем sort_order (следующий номер после последнего видео)
            if ($videoData['variation_id']) {
                // Для вариации
                $lastVideo = ShopGoodVideo::where('variation_id', $videoData['variation_id'])
                    ->orderBy('sort_order', 'desc')->first();
            } else {
                // Для товара
                $lastVideo = ShopGoodVideo::where('good_id', $goodId)
                    ->whereNull('variation_id')
                    ->orderBy('sort_order', 'desc')->first();
            }
            $videoData['sort_order'] = $lastVideo ? $lastVideo->sort_order + 1 : 1;

            Log::info('Creating video record', $videoData);

            // Проверяем, что все необходимые поля заполнены
            if (empty($videoData['external_url']) && empty($videoData['video_path'])) {
                Log::error('Neither external_url nor video_path provided', $videoData);

                return response()->json([
                    'success' => false,
                    'message' => 'Необходимо указать либо файл видео, либо URL',
                ], 422);
            }

            $goodVideo = ShopGoodVideo::create($videoData);

            // Перезагружаем модель, чтобы получить accessors
            $goodVideo->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Видео успешно загружено',
                'data' => $goodVideo,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Video store error', [
                'good_id' => $goodId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки видео: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Обновить видео
     */
    public function update(Request $request, $goodId, $videoId): JsonResponse
    {
        // Определяем, для товара или вариации обновляем видео
        if ($request->filled('variation_id')) {
            // Для вариации: ищем видео по variation_id
            $video = ShopGoodVideo::where('variation_id', $request->get('variation_id'))
                ->whereNull('good_id')
                ->findOrFail($videoId);
        } else {
            // Для товара: ищем видео по good_id
            $video = ShopGoodVideo::where('good_id', $goodId)
                ->whereNull('variation_id')
                ->findOrFail($videoId);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'external_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $video->update($request->only(['title', 'external_url']));

        return response()->json([
            'success' => true,
            'message' => 'Видео успешно обновлено',
            'data' => $video,
        ]);
    }

    /**
     * Удалить видео
     */
    public function destroy(Request $request, $goodId, $videoId): JsonResponse
    {
        // Определяем, для товара или вариации удаляем видео
        if ($request->filled('variation_id')) {
            // Для вариации: ищем видео по variation_id
            $video = ShopGoodVideo::where('variation_id', $request->get('variation_id'))
                ->whereNull('good_id')
                ->findOrFail($videoId);
        } else {
            // Для товара: ищем видео по good_id
            $video = ShopGoodVideo::where('good_id', $goodId)
                ->whereNull('variation_id')
                ->findOrFail($videoId);
        }

        try {
            // Удаляем файлы с фронтенда
            $frontendPublicPath = frontend_public_path();

            if ($video->video_path) {
                $videoPath = $frontendPublicPath.'/'.$video->video_path;
                if (file_exists($videoPath)) {
                    unlink($videoPath);
                }
            }

            if ($video->thumbnail) {
                $thumbnailPath = $frontendPublicPath.'/'.$video->thumbnail;
                if (file_exists($thumbnailPath)) {
                    unlink($thumbnailPath);
                }
            }

            $video->delete();

            return response()->json([
                'success' => true,
                'message' => 'Видео успешно удалено',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления видео: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Установить главное видео
     */
    public function setMain(Request $request, $goodId, $videoId): JsonResponse
    {
        // Определяем, для товара или вариации устанавливаем главное видео
        if ($request->filled('variation_id')) {
            // Для вариации: ищем видео по variation_id
            $video = ShopGoodVideo::where('variation_id', $request->get('variation_id'))
                ->whereNull('good_id')
                ->findOrFail($videoId);
        } else {
            // Для товара: ищем видео по good_id
            $video = ShopGoodVideo::where('good_id', $goodId)
                ->whereNull('variation_id')
                ->findOrFail($videoId);
        }

        // Снимаем флаг с других видео того же типа (товар или вариация)
        if ($video->variation_id) {
            // Для вариации: снимаем флаг с других видео этой вариации
            ShopGoodVideo::where('variation_id', $video->variation_id)
                ->where('id', '!=', $video->id)
                ->update(['is_main' => false]);
        } else {
            // Для товара: снимаем флаг с других видео товара (где variation_id = null)
            ShopGoodVideo::where('good_id', $goodId)
                ->whereNull('variation_id')
                ->where('id', '!=', $video->id)
                ->update(['is_main' => false]);
        }

        // Устанавливаем флаг для выбранного видео
        $video->update(['is_main' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Главное видео установлено',
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
            'videos.*.sort_order' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        foreach ($request->get('videos') as $videoData) {
            ShopGoodVideo::where('good_id', $goodId)
                ->where('id', $videoData['id'])
                ->update(['sort_order' => $videoData['sort_order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Порядок видео обновлен',
        ]);
    }
}
