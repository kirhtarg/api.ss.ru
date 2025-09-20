<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Slider;
use App\Models\SliderImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SliderController extends Controller
{
    /**
     * Получить все слайдеры с изображениями
     */
    public function index()
    {
        try {
            $sliders = Slider::with(['images' => function($query) {
                $query->ordered();
            }])->ordered()->get();

            // Добавляем image_url для каждого изображения в каждом слайдере
            $sliders->transform(function ($slider) {
                $slider->images->transform(function ($image) {
                    $image->image_url = $image->image_url;
                    return $image;
                });
                return $slider;
            });

            return response()->json([
                'success' => true,
                'data' => $sliders
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения слайдеров: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать новый слайдер
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'transition_type' => 'required|in:fade,slide,slide_left,slide_right,zoom',
            'control_type' => 'required|in:auto,manual',
            'auto_interval' => 'required|integer|min:1000',
            'transition_duration' => 'required|integer|min:100|max:5000',
            'title_position' => 'required|in:top-left,top-center,top-right,center-left,center,center-right,bottom-left,bottom-center,bottom-right',
            'text_position' => 'required|in:top-left,top-center,top-right,center-left,center,center-right,bottom-left,bottom-center,bottom-right',
            'is_active' => 'boolean',
            'sort_order' => 'integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $slider = Slider::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Слайдер успешно создан',
                'data' => $slider
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания слайдера: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить конкретный слайдер
     */
    public function show(string $id)
    {
        try {
            $slider = Slider::with(['images' => function($query) {
                $query->ordered();
            }])->findOrFail($id);

            // Добавляем image_url для каждого изображения
            $slider->images->transform(function ($image) {
                $image->image_url = $image->image_url;
                return $image;
            });

            return response()->json([
                'success' => true,
                'data' => $slider
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения слайдера: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить слайдер
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'transition_type' => 'sometimes|in:fade,slide,slide_left,slide_right,zoom',
            'control_type' => 'sometimes|in:auto,manual',
            'auto_interval' => 'sometimes|integer|min:1000',
            'transition_duration' => 'sometimes|integer|min:100|max:5000',
            'title_position' => 'sometimes|in:top-left,top-center,top-right,center-left,center,center-right,bottom-left,bottom-center,bottom-right',
            'text_position' => 'sometimes|in:top-left,top-center,top-right,center-left,center,center-right,bottom-left,bottom-center,bottom-right',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $slider = Slider::findOrFail($id);
            $slider->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Слайдер успешно обновлен',
                'data' => $slider
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления слайдера: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить слайдер
     */
    public function destroy(string $id)
    {
        try {
            $slider = Slider::findOrFail($id);
            
            // Удаляем все изображения слайдера
            foreach ($slider->images as $image) {
                if (Storage::disk('public')->exists('sliders/' . $image->image_path)) {
                    Storage::disk('public')->delete('sliders/' . $image->image_path);
                }
            }
            
            $slider->delete();

            return response()->json([
                'success' => true,
                'message' => 'Слайдер успешно удален'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления слайдера: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Загрузить изображение для слайдера
     */
    public function uploadImage(Request $request, string $sliderId)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240', // 10MB max
            'upload_type' => 'nullable|in:system_crop,system_fit,custom_fit,original',
            'width' => 'nullable|integer|min:100|max:4000',
            'height' => 'nullable|integer|min:100|max:4000',
            'custom_width' => 'nullable|integer|min:100|max:4000',
            'custom_height' => 'nullable|integer|min:100|max:4000',
            'title' => 'nullable|string|max:255',
            'text' => 'nullable|string',
            'link' => 'nullable|string|max:500',
            'link_type' => 'nullable|in:internal,external',
            'sort_order' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $slider = Slider::findOrFail($sliderId);
            
            $image = $request->file('image');
            $uploadType = $request->input('upload_type', 'original');
            $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
            
            // Определяем размеры для обработки
            $width = null;
            $height = null;
            
            if ($uploadType === 'system_crop' || $uploadType === 'system_fit') {
                $width = $request->input('width');
                $height = $request->input('height');
            } elseif ($uploadType === 'custom_fit') {
                $width = $request->input('custom_width');
                $height = $request->input('custom_height');
            }
            
            // Обрабатываем изображение в зависимости от типа
            if ($uploadType !== 'original' && $width && $height) {
                $this->processImage($image, $width, $height, $uploadType, public_path('sliders/' . $filename));
            } else {
                // Сохраняем оригинальное изображение
                $image->move(public_path('sliders'), $filename);
            }
            
            // Копируем файл на фронтенд
            $frontendPath = base_path('../admin.skateandsnow.ru/public/sliders/');
            if (!is_dir($frontendPath)) {
                mkdir($frontendPath, 0755, true);
            }
            copy(public_path('sliders/' . $filename), $frontendPath . $filename);
            
            // Создаем запись в базе данных
            $sliderImage = SliderImage::create([
                'slider_id' => $slider->id,
                'image_path' => $filename,
                'title' => $request->title,
                'text' => $request->text,
                'link' => $request->link,
                'link_type' => $request->link_type ?? 'internal',
                'sort_order' => $request->sort_order ?? 0
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'data' => [
                    'id' => $sliderImage->id,
                    'slider_id' => $sliderImage->slider_id,
                    'image_path' => $sliderImage->image_path,
                    'image_url' => $sliderImage->image_url,
                    'title' => $sliderImage->title,
                    'text' => $sliderImage->text,
                    'link' => $sliderImage->link,
                    'link_type' => $sliderImage->link_type,
                    'is_active' => $sliderImage->is_active,
                    'sort_order' => $sliderImage->sort_order,
                    'created_at' => $sliderImage->created_at,
                    'updated_at' => $sliderImage->updated_at
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обработать изображение в зависимости от типа
     */
    private function processImage($image, $width, $height, $uploadType, $outputPath)
    {
        // Создаем изображение из загруженного файла
        $sourceImage = null;
        $extension = strtolower($image->getClientOriginalExtension());
        
        switch ($extension) {
            case 'jpg':
            case 'jpeg':
                $sourceImage = imagecreatefromjpeg($image->getPathname());
                break;
            case 'png':
                $sourceImage = imagecreatefrompng($image->getPathname());
                break;
            case 'gif':
                $sourceImage = imagecreatefromgif($image->getPathname());
                break;
            case 'webp':
                $sourceImage = imagecreatefromwebp($image->getPathname());
                break;
            default:
                throw new \Exception('Неподдерживаемый формат изображения');
        }
        
        if (!$sourceImage) {
            throw new \Exception('Не удалось создать изображение из файла');
        }
        
        // Получаем размеры исходного изображения
        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        
        // Создаем новое изображение с нужными размерами
        $newImage = imagecreatetruecolor($width, $height);
        
        if ($uploadType === 'system_fit' || $uploadType === 'custom_fit') {
            // Вписываем изображение с белым фоном
            $white = imagecolorallocate($newImage, 255, 255, 255);
            imagefill($newImage, 0, 0, $white);
            
            // Вычисляем коэффициенты масштабирования
            $scaleX = $width / $sourceWidth;
            $scaleY = $height / $sourceHeight;
            $scale = min($scaleX, $scaleY);
            
            $newWidth = (int)($sourceWidth * $scale);
            $newHeight = (int)($sourceHeight * $scale);
            
            // Центрируем изображение
            $x = (int)(($width - $newWidth) / 2);
            $y = (int)(($height - $newHeight) / 2);
            
            imagecopyresampled($newImage, $sourceImage, $x, $y, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);
        } else {
            // Обрезаем изображение (system_crop)
            // Вычисляем коэффициенты масштабирования для обрезки
            $scaleX = $width / $sourceWidth;
            $scaleY = $height / $sourceHeight;
            $scale = max($scaleX, $scaleY);
            
            $newWidth = (int)($sourceWidth * $scale);
            $newHeight = (int)($sourceHeight * $scale);
            
            // Создаем временное изображение
            $tempImage = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($tempImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);
            
            // Обрезаем до нужных размеров
            $x = (int)(($newWidth - $width) / 2);
            $y = (int)(($newHeight - $height) / 2);
            
            imagecopy($newImage, $tempImage, 0, 0, $x, $y, $width, $height);
            imagedestroy($tempImage);
        }
        
        // Сохраняем обработанное изображение
        $extension = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
        switch ($extension) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($newImage, $outputPath, 90);
                break;
            case 'png':
                imagepng($newImage, $outputPath, 9);
                break;
            case 'gif':
                imagegif($newImage, $outputPath);
                break;
            case 'webp':
                imagewebp($newImage, $outputPath, 90);
                break;
        }
        
        // Освобождаем память
        imagedestroy($sourceImage);
        imagedestroy($newImage);
    }

    /**
     * Обновить изображение слайдера
     */
    public function updateImage(Request $request, string $sliderId, string $imageId)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'text' => 'nullable|string',
            'link' => 'nullable|string|max:500',
            'link_type' => 'nullable|in:internal,external',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $image = SliderImage::where('slider_id', $sliderId)->findOrFail($imageId);
            $image->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно обновлено',
                'data' => $image
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить изображение слайдера
     */
    public function deleteImage(string $sliderId, string $imageId)
    {
        try {
            $image = SliderImage::where('slider_id', $sliderId)->findOrFail($imageId);
            
            // Удаляем файл изображения с бэкенда
            $imagePath = public_path('sliders/' . $image->image_path);
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
            
            // Удаляем файл изображения с фронтенда
            $frontendPath = base_path('../admin.skateandsnow.ru/public/sliders/' . $image->image_path);
            if (file_exists($frontendPath)) {
                unlink($frontendPath);
            }
            
            $image->delete();

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно удалено'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления изображения: ' . $e->getMessage()
            ], 500);
        }
    }
}
