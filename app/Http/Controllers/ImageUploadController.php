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

            // Возвращаем путь относительно корня фронтенда
            $relativePath = '/' . $imagePath;
            Log::info('Относительный путь изображения: ' . $relativePath);

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'image_url' => $relativePath
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

            // Возвращаем путь относительно корня фронтенда
            $relativePath = '/' . $imagePath;
            
            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'image_url' => $relativePath
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
     * Загрузка изображения для in_figure (без изменения размера)
     */
    public function uploadCategoryFigureImage(Request $request, $categoryId)
    {
        try {
            Log::info('Начало загрузки изображения in_figure для категории: ' . $categoryId);
            
            // Валидация
            $validator = Validator::make($request->all(), [
                'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:10240', // 10MB
                'image_url' => 'nullable|url'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Проверяем, что категория существует
            $category = \App\Models\ShopCategory::find($categoryId);
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена'
                ], 404);
            }

            $imagePath = null;

            // Обработка загруженного файла
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                
                // Генерируем уникальное имя файла
                $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $relativePath = 'images/shop/categories/' . $fileName;
                
                // Получаем путь к фронтенду
                $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
                $frontendPublicPath = base_path($frontendPath . '/public');
                $fullPath = $frontendPublicPath . '/' . $relativePath;
                $dir = dirname($fullPath);
                
                // Создаем директорию если не существует
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                // Сохраняем файл БЕЗ изменения размера
                $file->move($dir, $fileName);
                
                $imagePath = $relativePath;
            }
            // Обработка URL
            elseif ($request->has('image_url')) {
                // Для URL тоже сохраняем как есть
                $imagePath = $this->processImageFromUrlAsIs($request->image_url);
            }

            if (!$imagePath) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось обработать изображение'
                ], 400);
            }

            // Обновляем категорию
            $category->update(['in_figure_img' => $imagePath]);

            // Возвращаем путь относительно корня фронтенда
            $relativePath = '/' . $imagePath;

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'image_url' => $relativePath
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка загрузки изображения in_figure: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обработка изображения из URL без изменения размера
     */
    private function processImageFromUrlAsIs($url)
    {
        try {
            Log::info('Обработка изображения из URL (как есть): ' . $url);
            
            // Загружаем изображение
            $imageData = file_get_contents($url);
            if ($imageData === false) {
                throw new \Exception('Не удалось загрузить изображение из URL');
            }
            
            // Определяем расширение из URL или Content-Type
            $extension = 'jpg';
            $urlInfo = parse_url($url);
            $pathInfo = pathinfo($urlInfo['path'] ?? '');
            if (isset($pathInfo['extension'])) {
                $extension = strtolower($pathInfo['extension']);
            }
            
            // Генерируем уникальное имя файла
            $fileName = Str::uuid() . '.' . $extension;
            $relativePath = 'images/shop/categories/' . $fileName;
            
            // Получаем путь к фронтенду
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            $frontendPublicPath = base_path($frontendPath . '/public');
            $fullPath = $frontendPublicPath . '/' . $relativePath;
            $dir = dirname($fullPath);
            
            // Создаем директорию если не существует
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            // Сохраняем файл как есть
            file_put_contents($fullPath, $imageData);
            
            return $relativePath;
            
        } catch (\Exception $e) {
            Log::error('Ошибка обработки изображения из URL (как есть): ' . $e->getMessage());
            throw $e;
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
            $convertToJpg = $request->input('convert_to_jpg', false);
            $whiteBackground = $request->input('white_background', false);
            
            Log::info('Параметры обработки:', [
                'width' => $width,
                'height' => $height,
                'maintainAspectRatio' => $maintainAspectRatio,
                'convert_to_jpg' => $convertToJpg,
                'white_background' => $whiteBackground
            ]);

            // Определяем расширение файла в зависимости от параметров конвертации
            $fileExtension = 'jpg'; // По умолчанию JPG
            if (!$convertToJpg && !$whiteBackground) {
                $originalExtension = strtolower($file->getClientOriginalExtension());
                if ($originalExtension === 'png' || $originalExtension === 'gif') {
                    $fileExtension = $originalExtension;
                }
            }
            
            // Генерируем уникальное имя файла
            $fileName = Str::uuid() . '.' . $fileExtension;
            $relativePath = 'images/shop/categories/' . $fileName;
            
            // Получаем путь к фронтенду из переменной окружения
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            $frontendPublicPath = base_path($frontendPath . '/public');
            $fullPath = $frontendPublicPath . '/' . $relativePath;
            $dir = dirname($fullPath);
            
            Log::info('Путь к фронтенду: ' . $frontendPath);
            Log::info('Полный путь к public фронтенда: ' . $frontendPublicPath);
            Log::info('Полный путь к файлу: ' . $fullPath);
            Log::info('Директория для сохранения: ' . $dir);

            // Создаем директорию если не существует
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                Log::info('Директория создана: ' . $dir);
            }

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

            // Конвертируем в JPG если нужно или если указан белый фон для прозрачных изображений
            if ($convertToJpg || $whiteBackground) {
                $imageData = $image->toJpeg(90); // Качество 90%
                Log::info('Изображение сконвертировано в JPEG, размер: ' . strlen($imageData) . ' байт');
            } else {
                // Сохраняем в оригинальном формате, если это возможно
                $extension = strtolower($file->getClientOriginalExtension());
                if ($extension === 'png') {
                    $imageData = $image->toPng();
                    Log::info('Изображение сохранено в PNG, размер: ' . strlen($imageData) . ' байт');
                } elseif ($extension === 'gif') {
                    $imageData = $image->toGif();
                    Log::info('Изображение сохранено в GIF, размер: ' . strlen($imageData) . ' байт');
                } else {
                    $imageData = $image->toJpeg(90); // По умолчанию JPEG
                    Log::info('Изображение сконвертировано в JPEG, размер: ' . strlen($imageData) . ' байт');
                }
            }
            
            // Сохраняем файл на фронтенд
            file_put_contents($fullPath, $imageData);
            Log::info('Файл сохранен на фронтенд: ' . $fullPath);

            // Проверяем, что файл действительно создался
            if (file_exists($fullPath)) {
                Log::info('Файл подтвержден на фронтенде: ' . $fullPath);
                Log::info('Размер файла: ' . filesize($fullPath) . ' байт');
            } else {
                Log::error('Файл не найден на фронтенде после сохранения: ' . $fullPath);
            }

            return $relativePath;
            
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
            Log::info('Обработка изображения из URL: ' . $url);
            
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
            $relativePath = 'images/shop/categories/' . $fileName;
            
            // Получаем путь к фронтенду из переменной окружения
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            $frontendPublicPath = base_path($frontendPath . '/public');
            $fullPath = $frontendPublicPath . '/' . $relativePath;
            $dir = dirname($fullPath);
            
            Log::info('Путь к фронтенду: ' . $frontendPath);
            Log::info('Полный путь к файлу: ' . $fullPath);
            Log::info('Директория для сохранения: ' . $dir);

            // Создаем директорию если не существует
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                Log::info('Директория создана: ' . $dir);
            }

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

            // Сохраняем изображение на фронтенд
            $imageData = $image->toJpeg();
            file_put_contents($fullPath, $imageData);
            Log::info('Файл сохранен на фронтенд: ' . $fullPath);

            return $relativePath;

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

            // Удаляем файл с фронтенда
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            $frontendPublicPath = base_path($frontendPath . '/public');
            
            // Обрабатываем разные форматы пути
            $imagePathToDelete = $currentImage;
            if (strpos($imagePathToDelete, '/') === 0) {
                // Если путь начинается с /, убираем его
                $imagePathToDelete = ltrim($imagePathToDelete, '/');
            }
            
            $fullPath = $frontendPublicPath . '/' . $imagePathToDelete;
            
            Log::info('Попытка удаления файла: ' . $fullPath);
            
            if (file_exists($fullPath)) {
                unlink($fullPath);
                Log::info('Файл удален с фронтенда: ' . $fullPath);
            } else {
                Log::warning('Файл не найден на фронтенде: ' . $fullPath);
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

    /**
     * Удаление изображения фигуры категории
     */
    public function deleteCategoryFigureImage(Request $request, $categoryId)
    {
        try {
            Log::info('Начало удаления изображения in_figure для категории: ' . $categoryId);
            
            // Проверяем, что категория существует
            $category = \App\Models\ShopCategory::find($categoryId);
            if (!$category) {
                Log::warning('Категория не найдена: ' . $categoryId);
                return response()->json([
                    'success' => false,
                    'message' => 'Категория не найдена'
                ], 404);
            }

            // Получаем текущее изображение фигуры
            $currentImage = $category->in_figure_img;
            
            if (!$currentImage) {
                Log::info('У категории нет изображения фигуры для удаления');
                return response()->json([
                    'success' => true,
                    'message' => 'У категории нет изображения фигуры'
                ]);
            }

            // Удаляем файл с фронтенда
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            $frontendPublicPath = base_path($frontendPath . '/public');
            
            // Обрабатываем разные форматы пути
            $imagePathToDelete = $currentImage;
            if (strpos($imagePathToDelete, '/') === 0) {
                // Если путь начинается с /, убираем его
                $imagePathToDelete = ltrim($imagePathToDelete, '/');
            }
            
            $fullPath = $frontendPublicPath . '/' . $imagePathToDelete;
            
            Log::info('Попытка удаления файла: ' . $fullPath);
            
            if (file_exists($fullPath)) {
                unlink($fullPath);
                Log::info('Файл удален с фронтенда: ' . $fullPath);
            } else {
                Log::warning('Файл не найден на фронтенде: ' . $fullPath);
            }

            // Очищаем поле in_figure_img в базе данных
            $category->in_figure_img = null;
            $category->save();

            Log::info('Изображение фигуры успешно удалено для категории: ' . $categoryId);

            return response()->json([
                'success' => true,
                'message' => 'Изображение фигуры успешно удалено'
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка удаления изображения фигуры: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления изображения фигуры: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Загрузка изображения для бренда
     */
    public function uploadBrandImage(Request $request, $brandId)
    {
        try {
            Log::info('Начало загрузки изображения для бренда: ' . $brandId);
            Log::info('Данные запроса:', $request->all());
            
            // Валидация
            $validator = Validator::make($request->all(), [
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB
                'image_url' => 'nullable|url',
                'width' => 'nullable|integer|min:1|max:2000',
                'height' => 'nullable|integer|min:1|max:2000',
                'maintainAspectRatio' => 'boolean',
                'fit_with_white_background' => 'boolean',
                'convert_to_jpg' => 'boolean',
                'white_background' => 'boolean'
            ]);

            if ($validator->fails()) {
                Log::warning('Ошибка валидации:', $validator->errors()->toArray());
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Проверяем, что бренд существует
            $brand = \App\Models\ShopBrand::find($brandId);
            if (!$brand) {
                Log::warning('Бренд не найден: ' . $brandId);
                return response()->json([
                    'success' => false,
                    'message' => 'Бренд не найден'
                ], 404);
            }

            Log::info('Бренд найден:', ['id' => $brand->id, 'name' => $brand->name]);

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
                $imagePath = $this->processUploadedBrandImage($file, $request);
            }
            // Обработка URL
            elseif ($request->has('image_url')) {
                Log::info('Обработка изображения из URL: ' . $request->image_url);
                $imagePath = $this->processBrandImageFromUrl($request->image_url, $request);
            }

            if (!$imagePath) {
                Log::error('Не удалось получить путь к изображению');
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось обработать изображение'
                ], 400);
            }

            Log::info('Путь к изображению получен: ' . $imagePath);

            // Обновляем бренд
            $brand->update(['logo' => $imagePath]);
            Log::info('Бренд обновлен с новым изображением');

            // Возвращаем путь относительно корня фронтенда
            $relativePath = '/' . $imagePath;
            Log::info('Относительный путь изображения: ' . $relativePath);

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'image_url' => $relativePath
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка загрузки изображения бренда: ' . $e->getMessage());
            Log::error('Стек вызовов: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обработка загруженного файла для бренда
     */
    private function processUploadedBrandImage($file, Request $request)
    {
        try {
            Log::info('Начало обработки загруженного файла для бренда');
            
            // Получаем настройки изображений (используем те же, что и для категорий)
            $imageSettings = $this->getImageSettings();
            Log::info('Настройки изображения:', $imageSettings);
            
            $width = $request->input('width', $imageSettings['width']);
            $height = $request->input('height', $imageSettings['height']);
            $maintainAspectRatio = $request->input('maintainAspectRatio', true);
            $fitWithWhiteBackground = $request->input('fit_with_white_background', false);
            $convertToJpg = $request->input('convert_to_jpg', false);
            $whiteBackground = $request->input('white_background', false);
            
            Log::info('Параметры обработки:', [
                'width' => $width,
                'height' => $height,
                'maintainAspectRatio' => $maintainAspectRatio,
                'fit_with_white_background' => $fitWithWhiteBackground,
                'convert_to_jpg' => $convertToJpg,
                'white_background' => $whiteBackground
            ]);

            // Генерируем уникальное имя файла
            $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $relativePath = 'images/shop/brands/' . $fileName;
            
            // Получаем путь к фронтенду из переменной окружения
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            $frontendPublicPath = base_path($frontendPath . '/public');
            $fullPath = $frontendPublicPath . '/' . $relativePath;
            $dir = dirname($fullPath);
            
            Log::info('Путь к фронтенду: ' . $frontendPath);
            Log::info('Полный путь к public фронтенда: ' . $frontendPublicPath);
            Log::info('Полный путь к файлу: ' . $fullPath);
            Log::info('Директория для сохранения: ' . $dir);

            // Создаем директорию если не существует
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                Log::info('Директория создана: ' . $dir);
            }

            // Создаем менеджер изображений
            $manager = new ImageManager(new Driver());
            Log::info('Менеджер изображений создан');

            // Создаем изображение с помощью Intervention Image
            $image = $manager->read($file);
            Log::info('Изображение прочитано');

            // Обработка изображения в зависимости от режима
            if ($fitWithWhiteBackground) {
                // Вписываем изображение в размеры с белым фоном (без обрезки)
                // Создаем копию изображения для вписывания (читаем файл заново)
                $fittedImage = $manager->read($file);
                $fittedImage->contain($width, $height);
                
                // Получаем размеры вписанного изображения
                $fittedWidth = $fittedImage->width();
                $fittedHeight = $fittedImage->height();
                
                // Создаем новое изображение с белым фоном нужного размера
                $canvas = $manager->create($width, $height);
                $canvas->fill('ffffff'); // Белый фон
                
                // Вычисляем позицию для центрирования
                $x = (int)(($width - $fittedWidth) / 2);
                $y = (int)(($height - $fittedHeight) / 2);
                
                // Накладываем вписанное изображение на белый фон
                $canvas->place($fittedImage, 'top-left', $x, $y);
                $image = $canvas;
                
                Log::info('Изображение вписано в размеры: ' . $width . 'x' . $height . ' с белым фоном');
            } elseif ($maintainAspectRatio) {
                // Обрезаем изображение до точных размеров с сохранением пропорций
                $image->cover($width, $height);
                Log::info('Изображение обрезано до размеров: ' . $width . 'x' . $height . ' с сохранением пропорций');
            } else {
                // Растягиваем изображение до точных размеров (может исказить пропорции)
                $image->resize($width, $height);
                Log::info('Изображение растянуто до размеров: ' . $width . 'x' . $height);
            }

            // Конвертируем в JPG если нужно или если указан белый фон для прозрачных изображений
            if ($convertToJpg || $whiteBackground) {
                $imageData = $image->toJpeg(90); // Качество 90%
            } else {
                $imageData = $image->toJpeg(90);
            }
            Log::info('Изображение сконвертировано в JPEG, размер: ' . strlen($imageData) . ' байт');
            
            // Сохраняем файл на фронтенд
            file_put_contents($fullPath, $imageData);
            Log::info('Файл сохранен на фронтенд: ' . $fullPath);

            // Проверяем, что файл действительно создался
            if (file_exists($fullPath)) {
                Log::info('Файл подтвержден на фронтенде: ' . $fullPath);
                Log::info('Размер файла: ' . filesize($fullPath) . ' байт');
            } else {
                Log::error('Файл не найден на фронтенде после сохранения: ' . $fullPath);
            }

            return $relativePath;
            
        } catch (\Exception $e) {
            Log::error('Ошибка в processUploadedBrandImage: ' . $e->getMessage());
            Log::error('Стек вызовов: ' . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Обработка изображения из URL для бренда
     */
    private function processBrandImageFromUrl($url, Request $request)
    {
        // Получаем настройки изображений
        $imageSettings = $this->getImageSettings();
        
        $width = $request->input('width', $imageSettings['width']);
        $height = $request->input('height', $imageSettings['height']);
        $maintainAspectRatio = $request->input('maintainAspectRatio', true);
        $fitWithWhiteBackground = $request->input('fit_with_white_background', false);
        $convertToJpg = $request->input('convert_to_jpg', false);
        $whiteBackground = $request->input('white_background', false);

        try {
            Log::info('Обработка изображения бренда из URL: ' . $url);
            
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
            $relativePath = 'images/shop/brands/' . $fileName;
            
            // Получаем путь к фронтенду из переменной окружения
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            $frontendPublicPath = base_path($frontendPath . '/public');
            $fullPath = $frontendPublicPath . '/' . $relativePath;
            $dir = dirname($fullPath);
            
            Log::info('Путь к фронтенду: ' . $frontendPath);
            Log::info('Полный путь к файлу: ' . $fullPath);
            Log::info('Директория для сохранения: ' . $dir);

            // Создаем директорию если не существует
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                Log::info('Директория создана: ' . $dir);
            }

            // Создаем менеджер изображений
            $manager = new ImageManager(new Driver());

            // Создаем изображение
            $image = $manager->read($imageContent);

            // Обработка изображения в зависимости от режима
            if ($fitWithWhiteBackground) {
                // Вписываем изображение в размеры с белым фоном (без обрезки)
                // Создаем копию изображения для вписывания (читаем из данных изображения)
                $fittedImage = $manager->read($imageContent);
                $fittedImage->contain($width, $height);
                
                // Получаем размеры вписанного изображения
                $fittedWidth = $fittedImage->width();
                $fittedHeight = $fittedImage->height();
                
                // Создаем новое изображение с белым фоном нужного размера
                $canvas = $manager->create($width, $height);
                $canvas->fill('ffffff'); // Белый фон
                
                // Вычисляем позицию для центрирования
                $x = (int)(($width - $fittedWidth) / 2);
                $y = (int)(($height - $fittedHeight) / 2);
                
                // Накладываем вписанное изображение на белый фон
                $canvas->place($fittedImage, 'top-left', $x, $y);
                $image = $canvas;
                
                Log::info('Изображение вписано в размеры: ' . $width . 'x' . $height . ' с белым фоном');
            } elseif ($maintainAspectRatio) {
                // Обрезаем изображение до точных размеров с сохранением пропорций
                $image->cover($width, $height);
            } else {
                // Растягиваем изображение до точных размеров (может исказить пропорции)
                $image->resize($width, $height);
            }

            // Конвертируем в JPG если нужно или если указан белый фон для прозрачных изображений
            if ($convertToJpg || $whiteBackground) {
                $imageData = $image->toJpeg(90); // Качество 90%
            } else {
                $imageData = $image->toJpeg(90);
            }
            file_put_contents($fullPath, $imageData);
            Log::info('Файл сохранен на фронтенд: ' . $fullPath);

            return $relativePath;

        } catch (\Exception $e) {
            Log::error('Ошибка обработки изображения бренда из URL: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Удаление изображения бренда
     */
    public function deleteBrandImage(Request $request, $brandId)
    {
        try {
            Log::info('Начало удаления изображения для бренда: ' . $brandId);
            
            // Проверяем, что бренд существует
            $brand = \App\Models\ShopBrand::find($brandId);
            if (!$brand) {
                Log::warning('Бренд не найден: ' . $brandId);
                return response()->json([
                    'success' => false,
                    'message' => 'Бренд не найден'
                ], 404);
            }

            // Получаем текущее изображение
            $currentImage = $brand->logo;
            
            if (!$currentImage) {
                Log::info('У бренда нет изображения для удаления');
                return response()->json([
                    'success' => true,
                    'message' => 'У бренда нет изображения'
                ]);
            }

            // Удаляем файл с фронтенда
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            $frontendPublicPath = base_path($frontendPath . '/public');
            
            // Обрабатываем разные форматы пути
            $imagePathToDelete = $currentImage;
            if (strpos($imagePathToDelete, '/') === 0) {
                // Если путь начинается с /, убираем его
                $imagePathToDelete = ltrim($imagePathToDelete, '/');
            }
            
            $fullPath = $frontendPublicPath . '/' . $imagePathToDelete;
            
            Log::info('Попытка удаления файла: ' . $fullPath);
            
            if (file_exists($fullPath)) {
                unlink($fullPath);
                Log::info('Файл удален с фронтенда: ' . $fullPath);
            } else {
                Log::warning('Файл не найден на фронтенде: ' . $fullPath);
            }

            // Очищаем поле logo в базе данных
            $brand->logo = null;
            $brand->save();

            Log::info('Изображение успешно удалено для бренда: ' . $brandId);

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно удалено'
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка удаления изображения бренда: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления изображения: ' . $e->getMessage()
            ], 500);
        }
    }
}
