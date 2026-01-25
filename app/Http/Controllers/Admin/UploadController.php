<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class UploadController extends Controller
{
    public function uploadGoodTextImage(Request $request)
    {
        try {
            // Validate request
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:51200' // 50MB max
            ]);

            // Get uploaded file
            $file = $request->file('image');
            
            // Generate unique filename
            $extension = $file->getClientOriginalExtension();
            $filename = Str::uuid() . '.' . $extension;
            
            // Create directory if it doesn't exist
            $directory = storage_path('app/public/images/good_texts');
            if (!\App\Helpers\StorageHelper::createDirectory($directory)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось создать директорию для изображения'
                ], 500);
            }
            
            // Process and optimize image
            $manager = new ImageManager(new Driver());
            $image = $manager->read($file);
            
            // Resize if width > 1024px
            if ($image->width() > 1024) {
                $image->resize(1024, null, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            }
            
            // Optimize quality (85% for JPEG, 90% for others)
            $quality = in_array(strtolower($extension), ['jpg', 'jpeg']) ? 85 : 90;
            
            // Save optimized image based on format
            if (in_array(strtolower($extension), ['jpg', 'jpeg'])) {
                $image->toJpeg($quality)->save($directory . '/' . $filename);
            } elseif (strtolower($extension) === 'png') {
                $image->toPng()->save($directory . '/' . $filename);
            } elseif (strtolower($extension) === 'webp') {
                $image->toWebp($quality)->save($directory . '/' . $filename);
            } else {
                // Default to JPEG
                $image->toJpeg($quality)->save($directory . '/' . $filename);
            }
            
            return response()->json([
                'success' => true,
                'filename' => $filename,
                'url' => Storage::url('images/good_texts/' . $filename)
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Временная загрузка изображения цвета для значения характеристики
     * Используется когда valueId еще не известен (при добавлении нового значения)
     */
    public function uploadColorImage(Request $request)
    {
        try {
            // Валидация
            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB
                'image_url' => 'nullable|url',
                'width' => 'nullable|integer|min:1|max:2000',
                'height' => 'nullable|integer|min:1|max:2000',
                'maintainAspectRatio' => 'boolean',
                'fit_with_white_background' => 'boolean',
                'convert_to_jpg' => 'boolean',
                'white_background' => 'boolean',
                'value_id' => 'nullable|integer' // Если указан, используется для имени файла
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Настройки по умолчанию
            $width = $request->input('width', 30);
            $height = $request->input('height', 30);
            $maintainAspectRatio = $request->input('maintainAspectRatio', true);
            $fitWithWhiteBackground = $request->input('fit_with_white_background', true);
            $convertToJpg = $request->input('convert_to_jpg', true);
            $whiteBackground = $request->input('white_background', true);
            $valueId = $request->input('value_id');

            // Генерируем имя файла
            $fileExtension = 'jpg';
            if ($valueId) {
                $fileName = 'color-image' . $valueId . '.' . $fileExtension;
            } else {
                // Временное имя файла с UUID
                $fileName = 'color-image-temp-' . Str::uuid() . '.' . $fileExtension;
            }
            $relativePath = 'color-images/' . $fileName;

            // Получаем путь к фронтенду (из FRONTEND_PATH в .env)
            $frontendPublicPath = frontend_public_path();
            $fullPath = $frontendPublicPath . '/' . $relativePath;
            $dir = dirname($fullPath);

            // Создаем директорию если не существует
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Создаем менеджер изображений
            $manager = new ImageManager(new Driver());
            $image = null;
            $sourceFile = null;
            $imageContent = null;

            // Обработка загруженного файла
            if ($request->hasFile('image')) {
                $sourceFile = $request->file('image');
                $image = $manager->read($sourceFile);
            }
            // Обработка URL
            elseif ($request->has('image_url')) {
                $imageContent = file_get_contents($request->image_url);
                if (!$imageContent) {
                    throw new \Exception('Не удалось загрузить изображение из URL');
                }
                $image = $manager->read($imageContent);
            }

            if (!$image) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось обработать изображение'
                ], 400);
            }

            // Обработка изображения в зависимости от режима
            if ($fitWithWhiteBackground) {
                // Вписываем изображение в размеры с белым фоном (без обрезки)
                // Создаем копию изображения для вписывания (читаем файл заново)
                $fittedImage = $sourceFile ? $manager->read($sourceFile) : $manager->read($imageContent);
                $fittedImage->contain($width, $height);

                // Получаем размеры вписанного изображения
                $fittedWidth = $fittedImage->width();
                $fittedHeight = $fittedImage->height();

                // Создаем новое изображение с белым фоном нужного размера
                $canvas = $manager->create($width, $height);
                $canvas->fill('ffffff'); // Белый фон

                // Накладываем вписанное изображение на белый фон с центрированием
                $canvas->place($fittedImage, 'center');
                $image = $canvas;
            } elseif ($maintainAspectRatio) {
                // Обрезаем изображение до точных размеров с сохранением пропорций
                $image->cover($width, $height);
            } else {
                // Растягиваем изображение до точных размеров
                $image->resize($width, $height);
            }

            // Конвертируем в JPG
            $imageData = $image->toJpeg(90); // Качество 90%

            // Сохраняем файл на фронтенд
            file_put_contents($fullPath, $imageData);

            // Возвращаем путь относительно корня фронтенда
            $relativePath = '/' . $relativePath;

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'path' => $relativePath,
                'image_url' => $relativePath
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Ошибка загрузки изображения цвета: ' . $e->getMessage());
            \Illuminate\Support\Facades\Log::error('Стек вызовов: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    public function uploadTempFile(Request $request)
    {
        try {
            // Validate request
            $request->validate([
                'file' => 'required|file|mimes:xml,yml,txt|max:51200', // 50MB max for XML/YML files
                'type' => 'required|string|in:yml'
            ]);

            // Get uploaded file
            $file = $request->file('file');

            // Generate unique filename
            $extension = $file->getClientOriginalExtension();
            $filename = 'temp_' . Str::uuid() . '.' . $extension;

            // Create temp directory if it doesn't exist
            $tempDir = storage_path('app/temp');
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // Save file to temp directory
            $filePath = $tempDir . '/' . $filename;
            $file->move($tempDir, $filename);

            // Generate URL for frontend
            $url = route('temp-file', ['filename' => $filename]);

            return response()->json([
                'success' => true,
                'message' => 'Файл успешно загружен',
                'filename' => $filename,
                'url' => $url,
                'path' => $filePath
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Ошибка загрузки временного файла: ' . $e->getMessage());
            \Illuminate\Support\Facades\Log::error('Стек вызовов: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки файла: ' . $e->getMessage()
            ], 500);
        }
    }

    public function deleteTempFile(Request $request, $filename)
    {
        try {
            // Validate filename to prevent directory traversal
            if (!preg_match('/^temp_[a-f0-9\-]+\.(xml|yml|txt)$/i', $filename)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверное имя файла'
                ], 400);
            }

            $filePath = storage_path('app/temp/' . $filename);

            if (file_exists($filePath)) {
                unlink($filePath);
            }

            return response()->json([
                'success' => true,
                'message' => 'Файл успешно удален'
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Ошибка удаления временного файла: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления файла: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Загрузка встроенных изображений из Excel файлов
     */
    public function uploadEmbeddedImage(Request $request)
    {

        try {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp,bmp|max:10240', // 10MB max для изображений из Excel
                'imageId' => 'required|string|max:100'
            ]);

            $file = $request->file('image');
            $imageId = $request->input('imageId');

            // Генерируем уникальное имя файла (как для обычных изображений товаров)
            $extension = $file->getClientOriginalExtension();
            $filename = 'excel_' . $imageId . '_' . Str::uuid() . '.' . $extension;

            // Используем тот же путь, что и для обычных изображений товаров
            $storagePath = '/images/shop/goods';
            $fullPath = $storagePath . '/' . $filename;

            // Получаем путь к фронтенду (из FRONTEND_PATH в .env)
            $frontendPublicPath = frontend_public_path();
            $storageFullPath = $frontendPublicPath . '/' . ltrim($fullPath, '/');

            // Создаем директорию если не существует
            $directory = dirname($storageFullPath);
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            // Сохраняем файл
            $file->move($directory, $filename);

            // Оптимизируем изображение если нужно
            try {
                $manager = new ImageManager(new Driver());
                $image = $manager->read($storageFullPath);

                // Ограничиваем размер до 1920x1920, сохраняя пропорции
                $image->scaleDown(1920, 1920);

                // Сохраняем оптимизированное изображение
                $image->save($storageFullPath, quality: 85);
            } catch (\Exception $e) {
                // Если оптимизация не удалась, продолжаем с оригинальным файлом
            }

            // Используем тот же формат пути, что и для обычных изображений товаров
            $publicUrl = $fullPath;

            return response()->json([
                'success' => true,
                'url' => $publicUrl,
                'imageId' => $imageId,
                'filename' => $filename
            ]);

        } catch (\Exception $e) {
            \Log::error('UploadController::uploadEmbeddedImage - Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при загрузке встроенного изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обработка встроенного изображения аналогично обычным изображениям товаров
     * Применяет fit_with_white, добавляет белый фон для PNG, не оптимизирует
     */
    private function processEmbeddedImage($file, $goodId, $variationId, $filename, $frontendPublicPath, $storagePath)
    {
        try {
            $manager = new ImageManager(new Driver());
            $image = $manager->read($file);

            // Получаем расширение файла
            $extension = strtolower($file->getClientOriginalExtension());

            // Используем системные размеры (500x500 по умолчанию, как для обычных изображений)
            $width = 500;
            $height = 500;

            // Проверяем, является ли формат прозрачным (нуждается в белом фоне)
            $isTransparentFormat = in_array($extension, ['png', 'gif', 'webp']);

            // Создаем уникальное имя файла с учетом товара/вариации
            $goodPart = $goodId ? "good_{$goodId}" : 'good_0';
            $varPart = $variationId ? "var_{$variationId}" : 'var_0';
            $baseFilename = "excel_{$goodPart}_{$varPart}_" . pathinfo($filename, PATHINFO_FILENAME);

            // Всегда обрабатываем изображения с белым фоном для PNG/GIF/WebP
            if ($isTransparentFormat) {
                // Создаем новое изображение с белым фоном
                $canvas = $manager->create($width, $height);
                $canvas->fill('ffffff'); // Белый фон

                // Вписываем изображение в размеры (fit_with_white)
                $image->contain($width, $height);

                // Накладываем изображение на белый фон с центрированием
                $canvas->place($image, 'center');

                // ВСЕГДА конвертируем в JPG для удаления прозрачности (не оптимизируем)
                $imageData = $canvas->toJpeg(100); // 100% качество, без оптимизации

                // Меняем расширение на jpg
                $newFilename = $baseFilename . '.jpg';
                $fullPath = $storagePath . '/' . $newFilename;
                $storageFullPath = $frontendPublicPath . $fullPath;

                // Создаем директорию если не существует
                $directory = dirname($storageFullPath);
                if (!file_exists($directory)) {
                    mkdir($directory, 0755, true);
                }

                // Сохраняем обработанное изображение
                file_put_contents($storageFullPath, $imageData);

                return $fullPath;
            } else {
                // Для обычных форматов (JPEG) применяем fit_with_white без оптимизации
                // Создаем новое изображение с белым фоном
                $canvas = $manager->create($width, $height);
                $canvas->fill('ffffff'); // Белый фон

                // Вписываем изображение в размеры
                $image->contain($width, $height);

                // Накладываем изображение на белый фон с центрированием
                $canvas->place($image, 'center');

                // Сохраняем в оригинальном формате без оптимизации
                if (strtolower($extension) === 'jpg' || strtolower($extension) === 'jpeg') {
                    $imageData = $canvas->toJpeg(100); // 100% качество, без оптимизации
                } elseif (strtolower($extension) === 'webp') {
                    $imageData = $canvas->toWebp(100); // 100% качество, без оптимизации
                } else {
                    $imageData = $canvas->toJpeg(100); // Fallback to JPEG
                }

                $newFilename = $baseFilename . '.' . $extension;
                $fullPath = $storagePath . '/' . $newFilename;
                $storageFullPath = $frontendPublicPath . $fullPath;

                // Создаем директорию если не существует
                $directory = dirname($storageFullPath);
                if (!file_exists($directory)) {
                    mkdir($directory, 0755, true);
                }

                // Сохраняем обработанное изображение
                file_put_contents($storageFullPath, $imageData);

                return $fullPath;
            }

        } catch (\Exception $e) {
            \Log::error('Error processing embedded image: ' . $e->getMessage());
            return null; // Возвращаем null, чтобы использовать fallback логику
        }
    }

    public function uploadEmbeddedImagesBatch(Request $request)
    {
        try {
            // Получаем все файлы и данные из FormData
            $allFiles = $request->allFiles();
            $allInput = $request->all();

            // Группируем файлы и данные по индексу
            $images = [];
            $imagesData = [];

            foreach ($allFiles as $key => $file) {
                if (preg_match('/^images_(\d+)_image$/', $key, $matches)) {
                    $index = $matches[1];
                    $images[$index] = $file;
                }
            }

            foreach ($allInput as $key => $value) {
                if (preg_match('/^images_(\d+)_imageId$/', $key, $matches)) {
                    $index = $matches[1];
                    if (!isset($imagesData[$index])) {
                        $imagesData[$index] = [];
                    }
                    $imagesData[$index]['imageId'] = $value;
                } elseif (preg_match('/^images_(\d+)_good_id$/', $key, $matches)) {
                    $index = $matches[1];
                    if (!isset($imagesData[$index])) {
                        $imagesData[$index] = [];
                    }
                    $imagesData[$index]['good_id'] = $value ?: null;
                } elseif (preg_match('/^images_(\d+)_variation_id$/', $key, $matches)) {
                    $index = $matches[1];
                    if (!isset($imagesData[$index])) {
                        $imagesData[$index] = [];
                    }
                    $imagesData[$index]['variation_id'] = $value ?: null;
                }
            }

            if (empty($images) || count($images) > 50) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid number of images (1-50 required)'
                ], 400);
            }
            $results = [];
            $errors = [];

            // Получаем путь к фронтенду (из FRONTEND_PATH в .env)
            $frontendPublicPath = frontend_public_path();

            foreach ($images as $index => $file) {
                try {
                    // Валидация файла
                    if (!$file || !$file->isValid()) {
                        $errors[] = [
                            'index' => $index,
                            'error' => 'Invalid file'
                        ];
                        continue;
                    }

                    // Проверка размера файла (10MB)
                    if ($file->getSize() > 10240 * 1024) {
                        $errors[] = [
                            'index' => $index,
                            'error' => 'File too large (max 10MB)'
                        ];
                        continue;
                    }

                    // Проверка типа файла
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp', 'image/bmp'];
                    if (!in_array($file->getMimeType(), $allowedMimes)) {
                        $errors[] = [
                            'index' => $index,
                            'error' => 'Invalid file type'
                        ];
                        continue;
                    }

                    $imageData = $imagesData[$index] ?? [];
                    $imageId = $imageData['imageId'] ?? null;
                    $goodId = $imageData['good_id'] ?? null;
                    $variationId = $imageData['variation_id'] ?? null;

                    if (!$imageId) {
                        $errors[] = [
                            'index' => $index,
                            'error' => 'Missing imageId'
                        ];
                        continue;
                    }

                    // Используем тот же путь, что и для обычных изображений товаров
                    $storagePath = '/images/shop/goods';

                    // Обрабатываем изображение аналогично обычным изображениям товаров
                    $processedImagePath = $this->processEmbeddedImage($file, $goodId, $variationId, $imageId, $frontendPublicPath, $storagePath);

                    // Если обработка удалась, используем новый путь, иначе возвращаем ошибку
                    if (!$processedImagePath) {
                        $errors[] = [
                            'index' => $index,
                            'error' => 'Failed to process image'
                        ];
                        continue;
                    }

                    $publicUrl = $processedImagePath;
                    $results[] = [
                        'imageId' => $imageId,
                        'url' => $publicUrl
                    ];

                } catch (\Exception $e) {
                    $errors[] = [
                        'index' => $index,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'success' => count($errors) === 0,
                'results' => $results,
                'errors' => $errors,
                'total_processed' => count($results),
                'total_errors' => count($errors)
            ]);

        } catch (\Exception $e) {
            \Log::error('UploadController::uploadEmbeddedImagesBatch - Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при пакетной загрузке встроенных изображений: ' . $e->getMessage()
            ], 500);
        }
    }
}
