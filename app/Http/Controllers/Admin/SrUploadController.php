<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SrUploadController extends Controller
{
    /**
     * Загрузить изображение для SR модуля
     */
    public function upload(Request $request): JsonResponse
    {
        try {
            // Проверяем, загружается ли файл или URL
            $imageUrl = $request->input('image_url');
            
            if ($imageUrl) {
                // Загрузка по URL
                return $this->uploadFromUrl($request, $imageUrl);
            }
            
            // Загрузка файла
            $validator = Validator::make($request->all(), [
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:51200', // 50MB максимум
                'width' => 'nullable|integer|min:1',
                'height' => 'nullable|integer|min:1',
                'image_url' => 'prohibited' // Запрещаем image_url при загрузке файла
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('image');

            // Создаем уникальное имя файла
            $filename = 'sr_' . Str::uuid() . '_' . time() . '.' . $file->getClientOriginalExtension();

            // Путь для сохранения на фронтенде
            $path = 'images/sr/' . $filename;
            
            // Путь к папке public фронтенда
            $frontendPublicPath = frontend_public_path();
            $fullPath = $frontendPublicPath . '/' . $path;
            $dir = dirname($fullPath);

            // Создаем директорию, если её нет
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Сохраняем файл на фронтенд
            $file->move($dir, $filename);
            
            // Проверяем, что файл действительно создался
            if (!file_exists($fullPath)) {
                Log::error('SrUploadController::upload: File was not saved: ' . $fullPath);
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось сохранить файл'
                ], 500);
            }

            // Получаем размеры изображения
            $imageDimensions = null;
            $imageInfo = getimagesize($fullPath);
            if ($imageInfo) {
                $imageDimensions = [
                    'width' => $imageInfo[0],
                    'height' => $imageInfo[1]
                ];
            }

            // Если пользователь задал желаемые размеры, изменяем размер изображения при загрузке
            $requestedWidth = (int)$request->input('width');
            $requestedHeight = (int)$request->input('height');

            if ($requestedWidth > 0 && $requestedHeight > 0) {
                try {
                    // Изменяем размер изображения
                    $this->resizeImageFile($fullPath, $requestedWidth, $requestedHeight);

                    // Проверяем, что размер действительно изменился
                    $imageInfo = getimagesize($fullPath);
                    if ($imageInfo) {
                        $imageDimensions = [
                            'width' => $imageInfo[0],
                            'height' => $imageInfo[1]
                        ];
                    } else {
                        $imageDimensions = [
                            'width' => $requestedWidth,
                            'height' => $requestedHeight
                        ];
                    }
                } catch (\Exception $e) {
                    // Если изменение размера не удалось, используем оригинальные размеры
                    Log::error('Не удалось изменить размер изображения: ' . $e->getMessage());

                    if (file_exists($fullPath)) {
                        $imageInfo = getimagesize($fullPath);
                        if ($imageInfo) {
                            $imageDimensions = [
                                'width' => $imageInfo[0],
                                'height' => $imageInfo[1]
                            ];
                        }
                    }
                }
            }

            $message = 'Изображение успешно загружено';
            if ($requestedWidth && $requestedHeight) {
                $message .= " с размерами {$requestedWidth}×{$requestedHeight}px";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'path' => $path,
                    'url' => '/' . ltrim($path, '/'),
                    'image_width' => $imageDimensions['width'] ?? null,
                    'image_height' => $imageDimensions['height'] ?? null,
                    'requested_width' => $requestedWidth,
                    'requested_height' => $requestedHeight
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('SrUploadController::upload: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Изменить размер изображения с обрезкой
     */
    private function resizeImageFile($imagePath, $width, $height)
    {
        try {
            // Проверяем, что расширение GD установлено
            if (!extension_loaded('gd')) {
                throw new \Exception('Расширение GD не установлено. Установите php-gd для работы с изображениями.');
            }

            // Получаем информацию об изображении
            $imageInfo = getimagesize($imagePath);
            if (!$imageInfo) {
                throw new \Exception('Не удалось получить информацию об изображении');
            }

            $originalWidth = $imageInfo[0];
            $originalHeight = $imageInfo[1];
            $mimeType = $imageInfo['mime'];

            // Создаем изображение в зависимости от типа
            switch ($mimeType) {
                case 'image/jpeg':
                    $sourceImage = imagecreatefromjpeg($imagePath);
                    break;
                case 'image/png':
                    $sourceImage = imagecreatefrompng($imagePath);
                    break;
                case 'image/gif':
                    $sourceImage = imagecreatefromgif($imagePath);
                    break;
                case 'image/webp':
                    $sourceImage = imagecreatefromwebp($imagePath);
                    break;
                default:
                    throw new \Exception('Неподдерживаемый тип изображения: ' . $mimeType);
            }

            if (!$sourceImage) {
                throw new \Exception('Не удалось создать изображение из файла');
            }

            // Создаем новое изображение с нужными размерами
            $newImage = imagecreatetruecolor($width, $height);

            // Сохраняем прозрачность для PNG и GIF
            if (in_array($mimeType, ['image/png', 'image/gif'])) {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
                imagefill($newImage, 0, 0, $transparent);
            } else {
                // Белый фон для JPEG и WebP
                $white = imagecolorallocate($newImage, 255, 255, 255);
                imagefill($newImage, 0, 0, $white);
            }

            // Обрезаем изображение под требуемый размер (crop)
            // Вычисляем соотношение для масштабирования с обрезкой
            // Используем max, чтобы изображение полностью покрыло целевой размер
            $ratio = max($width / $originalWidth, $height / $originalHeight);
            
            // Новые размеры после масштабирования (будут больше или равны целевому размеру)
            $scaledWidth = (int)($originalWidth * $ratio);
            $scaledHeight = (int)($originalHeight * $ratio);
            
            // Вычисляем координаты для обрезки по центру
            $srcX = (int)(($scaledWidth - $width) / 2);
            $srcY = (int)(($scaledHeight - $height) / 2);
            
            // Создаем временное изображение для масштабирования
            $scaledImage = imagecreatetruecolor($scaledWidth, $scaledHeight);
            
            // Сохраняем прозрачность для PNG и GIF
            if (in_array($mimeType, ['image/png', 'image/gif'])) {
                imagealphablending($scaledImage, false);
                imagesavealpha($scaledImage, true);
                $transparent = imagecolorallocatealpha($scaledImage, 0, 0, 0, 127);
                imagefill($scaledImage, 0, 0, $transparent);
            } else {
                $white = imagecolorallocate($scaledImage, 255, 255, 255);
                imagefill($scaledImage, 0, 0, $white);
            }
            
            // Масштабируем исходное изображение
            imagecopyresampled(
                $scaledImage, 
                $sourceImage, 
                0, 0, 0, 0, 
                $scaledWidth, $scaledHeight, 
                $originalWidth, $originalHeight
            );
            
            // Обрезаем масштабированное изображение до нужного размера (копируем центральную часть)
            // Параметры imagecopyresampled:
            // dst_image, src_image, dst_x, dst_y, src_x, src_y, dst_w, dst_h, src_w, src_h
            imagecopyresampled(
                $newImage,      // целевое изображение
                $scaledImage,   // исходное масштабированное изображение
                0,              // x координата в целевом изображении
                0,              // y координата в целевом изображении
                $srcX,          // x координата в исходном изображении (начало обрезки)
                $srcY,          // y координата в исходном изображении (начало обрезки)
                $width,         // ширина в целевом изображении
                $height,        // высота в целевом изображении
                $width,         // ширина области в исходном изображении
                $height         // высота области в исходном изображении
            );
            
            // Освобождаем память от временного изображения
            imagedestroy($scaledImage);

            // Сохраняем измененное изображение (перезаписываем оригинальный файл)
            $success = false;
            switch ($mimeType) {
                case 'image/jpeg':
                    $success = imagejpeg($newImage, $imagePath, 90);
                    break;
                case 'image/png':
                    $success = imagepng($newImage, $imagePath, 9);
                    break;
                case 'image/gif':
                    $success = imagegif($newImage, $imagePath);
                    break;
                case 'image/webp':
                    $success = imagewebp($newImage, $imagePath, 90);
                    break;
            }

            // Освобождаем память
            imagedestroy($sourceImage);
            imagedestroy($newImage);

            if (!$success) {
                throw new \Exception('Не удалось сохранить измененное изображение');
            }
            
            // Проверяем, что файл действительно был перезаписан с правильными размерами
            $checkInfo = getimagesize($imagePath);
            if ($checkInfo) {
                $checkWidth = $checkInfo[0];
                $checkHeight = $checkInfo[1];
                if ($checkWidth != $width || $checkHeight != $height) {
                    Log::error("Изображение сохранено с неправильными размерами. Ожидалось: {$width}×{$height}, получено: {$checkWidth}×{$checkHeight}");
                    throw new \Exception("Изображение сохранено с неправильными размерами: {$checkWidth}×{$checkHeight} вместо {$width}×{$height}");
                }
            }

        } catch (\Exception $e) {
            Log::error('Ошибка изменения размера изображения: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Загрузить изображение по URL и обработать
     */
    private function uploadFromUrl(Request $request, string $imageUrl): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'image_url' => 'required|url',
                'width' => 'nullable|integer|min:1',
                'height' => 'nullable|integer|min:1'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $requestedWidth = (int)$request->input('width');
            $requestedHeight = (int)$request->input('height');

            // Скачиваем изображение
            $imageContent = @file_get_contents($imageUrl);
            if ($imageContent === false) {
                throw new \Exception('Не удалось загрузить изображение по URL');
            }

            // Определяем расширение из URL или Content-Type
            $extension = 'jpg';
            $urlPath = parse_url($imageUrl, PHP_URL_PATH);
            if ($urlPath) {
                $urlExtension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
                if (in_array($urlExtension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $extension = $urlExtension === 'jpeg' ? 'jpg' : $urlExtension;
                }
            }

            // Создаем уникальное имя файла
            $filename = 'sr_' . Str::uuid() . '_' . time() . '.' . $extension;

            // Путь для сохранения на фронтенде
            $path = 'images/sr/' . $filename;
            
            // Путь к папке public фронтенда
            $frontendPublicPath = frontend_public_path();
            $fullPath = $frontendPublicPath . '/' . $path;
            $dir = dirname($fullPath);

            // Создаем директорию, если её нет
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Сохраняем временный файл
            file_put_contents($fullPath, $imageContent);
            
            // Проверяем, что файл действительно создался
            if (!file_exists($fullPath)) {
                Log::error('SrUploadController::uploadFromUrl: File was not saved: ' . $fullPath);
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось сохранить файл'
                ], 500);
            }

            // Получаем размеры изображения
            $imageDimensions = null;
            $imageInfo = getimagesize($fullPath);
            if ($imageInfo) {
                $imageDimensions = [
                    'width' => $imageInfo[0],
                    'height' => $imageInfo[1]
                ];
            }

            // Если пользователь задал желаемые размеры, изменяем размер изображения
            if ($requestedWidth > 0 && $requestedHeight > 0) {
                try {
                    // Изменяем размер изображения
                    $this->resizeImageFile($fullPath, $requestedWidth, $requestedHeight);

                    // Проверяем, что размер действительно изменился
                    $imageInfo = getimagesize($fullPath);
                    if ($imageInfo) {
                        $imageDimensions = [
                            'width' => $imageInfo[0],
                            'height' => $imageInfo[1]
                        ];
                    } else {
                        $imageDimensions = [
                            'width' => $requestedWidth,
                            'height' => $requestedHeight
                        ];
                    }
                } catch (\Exception $e) {
                    Log::error('Не удалось изменить размер изображения: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());

                    if (file_exists($fullPath)) {
                        $imageInfo = getimagesize($fullPath);
                        if ($imageInfo) {
                            $imageDimensions = [
                                'width' => $imageInfo[0],
                                'height' => $imageInfo[1]
                            ];
                        }
                    }
                }
            }

            $message = 'Изображение успешно загружено по URL';
            if ($requestedWidth > 0 && $requestedHeight > 0) {
                $message .= " с размерами {$requestedWidth}×{$requestedHeight}px";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'path' => $path,
                    'url' => '/' . ltrim($path, '/'),
                    'image_width' => $imageDimensions['width'] ?? null,
                    'image_height' => $imageDimensions['height'] ?? null,
                    'requested_width' => $requestedWidth,
                    'requested_height' => $requestedHeight
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('SrUploadController::uploadFromUrl: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения по URL: ' . $e->getMessage()
            ], 500);
        }
    }
}
