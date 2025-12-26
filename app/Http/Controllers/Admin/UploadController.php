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

            // Получаем путь к фронтенду
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            $frontendPublicPath = base_path($frontendPath . '/public');
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

                // Вычисляем позицию для центрирования
                $x = (int)(($width - $fittedWidth) / 2);
                $y = (int)(($height - $fittedHeight) / 2);

                // Накладываем вписанное изображение на белый фон
                $canvas->place($fittedImage, 'top-left', $x, $y);
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
}
