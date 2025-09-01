<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ImageUploadController extends Controller
{
    /**
     * Загрузка изображения для категории
     */
    public function uploadCategoryImage(Request $request, $categoryId)
    {
        try {
            Log::info('Начало загрузки изображения для категории: ' . $categoryId);
            Log::info('Данные запроса:', $request->all());
            
            // Валидация
            $validator = Validator::make($request->all(), [
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB
                'image_url' => 'nullable|url',
                'width' => 'nullable|integer|min:1|max:2000',
                'height' => 'nullable|integer|min:1|max:2000',
                'maintainAspectRatio' => 'boolean'
            ]);

            if ($validator->fails()) {
                Log::warning('Ошибка валидации:', $validator->errors()->toArray());
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Проверяем, что категория существует
            $category = \App\Models\ShopCategory::find($categoryId);
            if (!$category) {
                Log::warning('Категория не найдена: ' . $categoryId);
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена'
                ], 404);
            }

            Log::info('Категория найдена:', ['id' => $category->id, 'name' => $category->name]);

            $imagePath = null;

            // Обработка загруженного файла
            if ($request->hasFile('image')) {
                Log::info('Обработка загруженного файла');
                $file = $request->file('image');
                Log::info('Информация о файле:', [
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'extension' => $file->getClientOriginalExtension()
                ]);
                $imagePath = $this->processUploadedImage($file, $request);
            }
            // Обработка URL
            elseif ($request->has('image_url')) {
                Log::info('Обработка изображения из URL: ' . $request->image_url);
                $imagePath = $this->processImageFromUrl($request->image_url, $request);
            }

            if (!$imagePath) {
                Log::error('Не удалось получить путь к изображению');
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось обработать изображение'
                ], 400);
            }

            Log::info('Путь к изображению получен: ' . $imagePath);

            // Обновляем категорию
            $category->update(['image' => $imagePath]);
            Log::info('Категория обновлена с новым изображением');

            // Используем стандартный Storage URL для public диска
            $fullUrl = config('app.url') . '/storage/' . $imagePath;
            Log::info('Полный URL изображения: ' . $fullUrl);

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'image_url' => $fullUrl
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка загрузки изображения категории: ' . $e->getMessage());
            Log::error('Стек вызовов: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Временная загрузка изображения (для создания новой категории)
     */
    public function uploadTempImage(Request $request)
    {
        try {
            // Валидация
            $validator = Validator::make($request->all(), [
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB
                'image_url' => 'nullable|url',
                'width' => 'nullable|integer|min:1|max:2000',
                'height' => 'nullable|integer|min:1|max:2000',
                'maintainAspectRatio' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $imagePath = null;

            // Обработка загруженного файла
            if ($request->hasFile('image')) {
                $imagePath = $this->processUploadedImage($request->file('image'), $request);
            }
            // Обработка URL
            elseif ($request->has('image_url')) {
                $imagePath = $this->processImageFromUrl($request->image_url, $request);
            }

            if (!$imagePath) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось обработать изображение'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'image_url' => config('app.url') . '/storage/' . $imagePath
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка временной загрузки изображения: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получение настроек изображений категорий
     */
    private function getImageSettings()
    {
        return [
            'width' => (int) get_shop_setting('shop_category_img_width', 300),
            'height' => (int) get_shop_setting('shop_category_img_height', 200)
        ];
    }

    /**
     * Обработка загруженного файла
     */
    private function processUploadedImage($file, Request $request)
    {
        try {
            Log::info('Начало обработки загруженного файла');
            
            // Получаем настройки изображений
            $imageSettings = $this->getImageSettings();
            Log::info('Настройки изображения:', $imageSettings);
            
            $width = $request->input('width', $imageSettings['width']);
            $height = $request->input('height', $imageSettings['height']);
            $maintainAspectRatio = $request->input('maintainAspectRatio', true);
            
            Log::info('Параметры обработки:', [
                'width' => $width,
                'height' => $height,
                'maintainAspectRatio' => $maintainAspectRatio
            ]);

                    // Генерируем уникальное имя файла
        $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = 'shop/categories/' . $fileName;
        Log::info('Сгенерированный путь: ' . $path);
        


            // Создаем менеджер изображений
            $manager = new ImageManager(new Driver());
            Log::info('Менеджер изображений создан');

            // Создаем изображение с помощью Intervention Image
            $image = $manager->read($file);
            Log::info('Изображение прочитано');

            // Ресайзим изображение с обрезкой до точных размеров
            if ($maintainAspectRatio) {
                // Обрезаем изображение до точных размеров с сохранением пропорций
                $image->cover($width, $height);
                Log::info('Изображение обрезано до размеров: ' . $width . 'x' . $height . ' с сохранением пропорций');
            } else {
                // Растягиваем изображение до точных размеров (может исказить пропорции)
                $image->resize($width, $height);
                Log::info('Изображение растянуто до размеров: ' . $width . 'x' . $height);
            }

            // Сохраняем изображение
            $imageData = $image->toJpeg();
            Log::info('Изображение сконвертировано в JPEG, размер: ' . strlen($imageData) . ' байт');
            
            // Сохраняем в Storage public (доступно через /storage/ URL)
            $result = Storage::disk('public')->put($path, $imageData);
            Log::info('Результат сохранения в Storage public: ' . ($result ? 'успешно' : 'ошибка'));

            // Проверяем, что файл действительно создался
            if (Storage::disk('public')->exists($path)) {
                Log::info('Файл подтвержден в Storage public: ' . $path);
                Log::info('Размер файла в Storage public: ' . Storage::disk('public')->size($path) . ' байт');
            } else {
                Log::error('Файл не найден в Storage public после сохранения: ' . $path);
            }
            


            return $path;
            
        } catch (\Exception $e) {
            Log::error('Ошибка в processUploadedImage: ' . $e->getMessage());
            Log::error('Стек вызовов: ' . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Обработка изображения из URL
     */
    private function processImageFromUrl($url, Request $request)
    {
        // Получаем настройки изображений
        $imageSettings = $this->getImageSettings();
        
        $width = $request->input('width', $imageSettings['width']);
        $height = $request->input('height', $imageSettings['height']);
        $maintainAspectRatio = $request->input('maintainAspectRatio', true);

        try {
            // Загружаем изображение из URL
            $imageContent = file_get_contents($url);
            if (!$imageContent) {
                throw new \Exception('Не удалось загрузить изображение из URL');
            }

            // Определяем расширение файла
            $extension = 'jpg'; // по умолчанию
            $contentType = get_headers($url, 1)['Content-Type'] ?? '';
            if (strpos($contentType, 'png') !== false) {
                $extension = 'png';
            } elseif (strpos($contentType, 'gif') !== false) {
                $extension = 'gif';
            }

            // Генерируем уникальное имя файла
            $fileName = Str::uuid() . '.' . $extension;
            $path = 'shop/categories/' . $fileName;

            // Создаем менеджер изображений
            $manager = new ImageManager(new Driver());

            // Создаем изображение
            $image = $manager->read($imageContent);

            // Ресайзим изображение с обрезкой до точных размеров
            if ($maintainAspectRatio) {
                // Обрезаем изображение до точных размеров с сохранением пропорций
                $image->cover($width, $height);
            } else {
                // Растягиваем изображение до точных размеров (может исказить пропорции)
                $image->resize($width, $height);
            }

            // Сохраняем изображение в Storage public
            Storage::disk('public')->put($path, $image->toJpeg());

            return $path;

        } catch (\Exception $e) {
            Log::error('Ошибка обработки изображения из URL: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Удаление изображения категории
     */
    public function deleteCategoryImage(Request $request, $categoryId)
    {
        try {
            Log::info('Начало удаления изображения для категории: ' . $categoryId);
            
            // Проверяем, что категория существует
            $category = \App\Models\ShopCategory::find($categoryId);
            if (!$category) {
                Log::warning('Категория не найдена: ' . $categoryId);
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена'
                ], 404);
            }

            // Получаем текущее изображение
            $currentImage = $category->image;
            
            if (!$currentImage) {
                Log::info('У категории нет изображения для удаления');
                return response()->json([
                    'success' => true,
                    'message' => 'У категории нет изображения'
                ]);
            }

            // Удаляем файл из storage
            $imagePath = 'shop/categories/' . basename($currentImage);
            if (Storage::disk('public')->exists($imagePath)) {
                Storage::disk('public')->delete($imagePath);
                Log::info('Файл удален из storage: ' . $imagePath);
            } else {
                Log::warning('Файл не найден в storage: ' . $imagePath);
            }

            // Очищаем поле image в базе данных
            $category->image = null;
            $category->save();

            Log::info('Изображение успешно удалено для категории: ' . $categoryId);

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно удалено'
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка удаления изображения: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления изображения: ' . $e->getMessage()
            ], 500);
        }
    }
}
