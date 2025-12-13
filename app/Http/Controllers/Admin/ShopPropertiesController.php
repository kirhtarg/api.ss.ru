<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop\Property;
use App\Models\Shop\PropertyValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ShopPropertiesController extends Controller
{
    /**
     * Получить список всех свойств для импорта
     */
    public function list()
    {
        try {
            $properties = Property::with('values')
                ->orderBy('name')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $properties->map(function($property) {
                    return [
                        'id' => $property->id,
                        'name' => $property->name,
                        'property_type' => $property->property_type,
                        'description' => $property->description,
                        'values' => $property->values->map(function($value) {
                            return [
                                'id' => $value->id,
                                'value' => $value->value,
                                'color' => $value->color
                            ];
                        })
                    ];
                })
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка получения списка свойств: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения списка свойств',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Загрузка изображения для значения характеристики
     */
    public function uploadPropertyValueImage(Request $request, $propertyId, $valueId)
    {
        try {
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
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Проверяем, что значение характеристики существует
            $valueIdInt = (int) $valueId;
            $propertyValue = PropertyValue::find($valueIdInt);
            
            if (!$propertyValue) {
                $propertyValue = PropertyValue::where('id', $valueIdInt)->first();
                
                if (!$propertyValue) {
                    $propertyValue = PropertyValue::where('id', $valueId)->first();
                    
                    if (!$propertyValue) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Значение характеристики не найдено'
                        ], 404);
                    }
                }
            }

            $imagePath = null;

            // Обработка загруженного файла
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $imagePath = $this->processUploadedPropertyValueImage($file, $request, $valueId);
            }
            // Обработка URL
            elseif ($request->has('image_url')) {
                $imagePath = $this->processPropertyValueImageFromUrl($request->image_url, $request, $valueId);
            }

            if (!$imagePath) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось обработать изображение'
                ], 400);
            }

            // Обновляем значение характеристики
            $propertyValue->update(['image_path' => $imagePath]);

            // Возвращаем путь относительно корня фронтенда
            $relativePath = '/' . $imagePath;

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'image_url' => $relativePath
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка загрузки изображения значения характеристики: ' . $e->getMessage());
            Log::error('Стек вызовов: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удаление изображения значения характеристики
     */
    public function deletePropertyValueImage(Request $request, $propertyId, $valueId)
    {
        try {
            Log::info('Начало удаления изображения для значения характеристики: ' . $valueId);

            // Проверяем, что значение характеристики существует
            $propertyValue = PropertyValue::find($valueId);
            if (!$propertyValue) {
                Log::warning('Значение характеристики не найдено: ' . $valueId);
                return response()->json([
                    'success' => false,
                    'message' => 'Значение характеристики не найдено'
                ], 404);
            }

            // Получаем текущее изображение
            $currentImage = $propertyValue->image_path;

            if (!$currentImage) {
                Log::info('У значения характеристики нет изображения для удаления');
                return response()->json([
                    'success' => true,
                    'message' => 'У значения характеристики нет изображения'
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

            // Очищаем поле image_path в базе данных
            $propertyValue->image_path = null;
            $propertyValue->save();

            Log::info('Изображение успешно удалено для значения характеристики: ' . $valueId);

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно удалено'
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка удаления изображения значения характеристики: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обработка загруженного файла для значения характеристики
     */
    private function processUploadedPropertyValueImage($file, Request $request, $valueId)
    {
        try {
            Log::info('Начало обработки загруженного файла для значения характеристики');

            // Настройки по умолчанию для изображений характеристик
            $width = $request->input('width', 30);
            $height = $request->input('height', 30);
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

            // Сохраняем исходный формат файла
            $originalExtension = strtolower($file->getClientOriginalExtension());
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            // Если расширение не поддерживается, используем JPG
            if (!in_array($originalExtension, $allowedExtensions)) {
                $originalExtension = 'jpg';
            }
            
            // Если конвертация в JPG отключена, используем исходный формат
            $fileExtension = $convertToJpg ? 'jpg' : $originalExtension;

            // Генерируем уникальное имя файла в формате color-image-{property_id}
            $fileName = 'color-image-' . $valueId . '.' . $fileExtension;
            $relativePath = 'color-images/' . $fileName;

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

            // Обработка изображения - просто ресайзим до нужного размера
            if ($maintainAspectRatio) {
                // Обрезаем изображение до точных размеров с сохранением пропорций (без фона)
                $image->cover($width, $height);
                Log::info('Изображение обрезано до размеров: ' . $width . 'x' . $height . ' с сохранением пропорций');
            } else {
                // Растягиваем изображение до точных размеров (может исказить пропорции)
                $image->resize($width, $height);
                Log::info('Изображение растянуто до размеров: ' . $width . 'x' . $height);
            }

            // Сохраняем в нужном формате
            if ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                $imageData = $image->toJpeg(90); // Качество 90%
                Log::info('Изображение сохранено в JPEG, размер: ' . strlen($imageData) . ' байт');
            } elseif ($fileExtension === 'png') {
                $imageData = $image->toPng();
                Log::info('Изображение сохранено в PNG, размер: ' . strlen($imageData) . ' байт');
            } elseif ($fileExtension === 'webp') {
                $imageData = $image->toWebp(90);
                Log::info('Изображение сохранено в WebP, размер: ' . strlen($imageData) . ' байт');
            } else {
                // По умолчанию JPG
                $imageData = $image->toJpeg(90);
                Log::info('Изображение сохранено в JPEG (по умолчанию), размер: ' . strlen($imageData) . ' байт');
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
            Log::error('Ошибка в processUploadedPropertyValueImage: ' . $e->getMessage());
            Log::error('Стек вызовов: ' . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Обработка изображения из URL для значения характеристики
     */
    private function processPropertyValueImageFromUrl($url, Request $request, $valueId)
    {
        // Получаем настройки изображений
        $width = $request->input('width', 30);
        $height = $request->input('height', 30);
        $maintainAspectRatio = $request->input('maintainAspectRatio', true);
        $fitWithWhiteBackground = $request->input('fit_with_white_background', false);
        $convertToJpg = $request->input('convert_to_jpg', false);
        $whiteBackground = $request->input('white_background', false);

        try {
            Log::info('Обработка изображения значения характеристики из URL: ' . $url);

            // Загружаем изображение из URL
            $imageContent = file_get_contents($url);
            if (!$imageContent) {
                throw new \Exception('Не удалось загрузить изображение из URL');
            }

            // Генерируем уникальное имя файла в формате color-image-{property_value_id}
            $fileName = 'color-image-' . $valueId . '.jpg';
            $relativePath = 'color-images/' . $fileName;

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

            // Обработка изображения - просто ресайзим до нужного размера
            if ($maintainAspectRatio) {
                // Обрезаем изображение до точных размеров с сохранением пропорций (без фона)
                $image->cover($width, $height);
            } else {
                // Растягиваем изображение до точных размеров (может исказить пропорции)
                $image->resize($width, $height);
            }

            // Сохраняем в нужном формате
            if ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                $imageData = $image->toJpeg(90);
            } elseif ($fileExtension === 'png') {
                $imageData = $image->toPng();
            } elseif ($fileExtension === 'webp') {
                $imageData = $image->toWebp(90);
            } else {
                // По умолчанию JPG
                $imageData = $image->toJpeg(90);
            }
            
            file_put_contents($fullPath, $imageData);
            Log::info('Файл сохранен на фронтенд: ' . $fullPath);

            return $relativePath;

        } catch (\Exception $e) {
            Log::error('Ошибка обработки изображения значения характеристики из URL: ' . $e->getMessage());
            throw $e;
        }
    }
}