<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Slider;
use Illuminate\Http\Request;

class SliderController extends Controller
{
    /**
     * Получить все активные слайдеры с изображениями
     */
    public function index()
    {
        try {
            $sliders = Slider::active()
                ->ordered()
                ->with(['activeImages' => function($query) {
                    $query->ordered();
                }])
                ->get();

            return response()->json([
                'success' => true,
                'data' => $sliders->map(function($slider) {
                    return [
                        'id' => $slider->id,
                        'name' => $slider->name,
                        'transition_type' => $slider->transition_type,
                        'control_type' => $slider->control_type,
                        'auto_interval' => $slider->auto_interval,
                        'transition_duration' => $slider->transition_duration,
                        'title_position' => $slider->title_position,
                        'text_position' => $slider->text_position,
                        'images' => $slider->activeImages->map(function($image) {
                            return [
                                'id' => $image->id,
                                'image_url' => $this->getImageUrl($image->image_url),
                                'title' => $image->title,
                                'text' => $image->text,
                                'link' => $image->link,
                                'link_type' => $image->link_type,
                            ];
                        })
                    ];
                })
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения слайдеров: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить конкретный слайдер по ID
     */
    public function show($id)
    {
        try {
            $slider = Slider::active()
                ->with(['activeImages' => function($query) {
                    $query->ordered();
                }])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $slider->id,
                    'name' => $slider->name,
                    'transition_type' => $slider->transition_type,
                    'control_type' => $slider->control_type,
                    'auto_interval' => $slider->auto_interval,
                    'transition_duration' => $slider->transition_duration,
                    'title_position' => $slider->title_position,
                    'text_position' => $slider->text_position,
                    'images' => $slider->activeImages->map(function($image) {
                        return [
                            'id' => $image->id,
                            'image_url' => $this->getImageUrl($image->image_url),
                            'title' => $image->title,
                            'text' => $image->text,
                            'link' => $image->link,
                            'link_type' => $image->link_type,
                        ];
                    })
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения слайдера: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить полный URL изображения
     */
    private function getImageUrl($filePath): ?string
    {
        if (!$filePath) {
            return null;
        }

        // Убираем возможные префиксы API сервера
        $cleanPath = $filePath;
        
        // Если в пути есть полный URL, извлекаем только относительный путь
        if (preg_match('/https?:\/\/[^\/]+(.*)/', $filePath, $matches)) {
            $cleanPath = $matches[1];
        }
        
        // Если это уже полный URL, проверяем домен
        if (str_starts_with($cleanPath, 'http')) {
            // Заменяем старый домен на новый фронтенд домен
            $frontendUrl = config('app.frontend_url', 'https://admin.skateandsnow.ru');
            $oldDomains = [
                'https://ss75.kirhtarg.ru',
                'https://api.ss.ru',
                'https://ss75-api.kirhtarg.ru'
            ];
            
            foreach ($oldDomains as $oldDomain) {
                if (str_starts_with($cleanPath, $oldDomain)) {
                    return str_replace($oldDomain, $frontendUrl, $cleanPath);
                }
            }
            
            // Если это другой домен, возвращаем как есть
            return $cleanPath;
        }

        // Убираем лишний префикс images/ если он уже есть
        $cleanPath = ltrim($cleanPath, '/');
        if (str_starts_with($cleanPath, 'images/')) {
            // Убираем префикс images/ и возвращаем полный URL с фронтенда
            $cleanPath = substr($cleanPath, 7); // Убираем "images/"
        }

        // Возвращаем полный URL к файлу на фронтенде (без /images/)
        $frontendUrl = config('app.frontend_url', 'https://admin.skateandsnow.ru');
        return $frontendUrl . '/' . $cleanPath;
    }
}
