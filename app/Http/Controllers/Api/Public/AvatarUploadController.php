<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
// Intervention Image больше не используется - используем встроенные функции PHP

class AvatarUploadController extends Controller
{
    /**
     * Оптимизировать и изменить размер изображения
     */
    private function optimizeImage($filePath, $outputPath, $width = 500, $height = 500, $quality = 85)
    {
        try {
            // Проверяем, что файл существует и читается
            if (!file_exists($filePath)) {
                Log::error("File does not exist: {$filePath}");
                return false;
            }
            
            if (!is_readable($filePath)) {
                Log::error("File is not readable: {$filePath}");
                return false;
            }
            
            // Дополнительная проверка - файл должен быть больше 0 байт
            if (filesize($filePath) === 0) {
                Log::error("File is empty: {$filePath}");
                return false;
            }
            
            $fileSize = filesize($filePath);
            Log::info("File size before optimization: {$fileSize} bytes");
            
            // Проверяем MIME тип файла
            $mimeType = mime_content_type($filePath);
            Log::info("File MIME type: {$mimeType}");
            
            if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                Log::error("Unsupported MIME type: {$mimeType}");
                return false;
            }
            
            // Используем встроенные функции PHP для изменения размера
            $imageInfo = getimagesize($filePath);
            if (!$imageInfo) {
                throw new \Exception('Не удалось получить информацию об изображении');
            }
            
            $originalWidth = $imageInfo[0];
            $originalHeight = $imageInfo[1];
            $imageType = $imageInfo[2];
            
            Log::info("Original image size: {$originalWidth}x{$originalHeight}, type: {$imageType}");
            
            // Загружаем изображение в зависимости от типа
            $sourceImage = null;
            switch ($imageType) {
                case IMAGETYPE_JPEG:
                    $sourceImage = imagecreatefromjpeg($filePath);
                    break;
                case IMAGETYPE_PNG:
                    $sourceImage = imagecreatefrompng($filePath);
                    break;
                case IMAGETYPE_GIF:
                    $sourceImage = imagecreatefromgif($filePath);
                    break;
                case IMAGETYPE_WEBP:
                    $sourceImage = imagecreatefromwebp($filePath);
                    break;
                default:
                    throw new \Exception('Неподдерживаемый тип изображения');
            }
            
            if (!$sourceImage) {
                throw new \Exception('Не удалось загрузить изображение');
            }
            
            // Вычисляем новые размеры с сохранением пропорций
            $ratio = min($width / $originalWidth, $height / $originalHeight);
            $newWidth = (int) round($originalWidth * $ratio);
            $newHeight = (int) round($originalHeight * $ratio);
            
            Log::info("Calculated new size: {$newWidth}x{$newHeight}");
            
            // Создаем новое изображение нужного размера
            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Включаем прозрачность для PNG
            if ($imageType == IMAGETYPE_PNG) {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
                $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
                imagefill($resizedImage, 0, 0, $transparent);
            }
            
            // Изменяем размер изображения
            imagecopyresampled(
                $resizedImage, $sourceImage,
                0, 0, 0, 0,
                $newWidth, $newHeight,
                $originalWidth, $originalHeight
            );
            
            // Создаем квадратное изображение
            $canvas = imagecreatetruecolor($width, $height);
            
            // Заливаем фон белым цветом
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
            
            // Вычисляем позицию для центрирования
            $x = (int) (($width - $newWidth) / 2);
            $y = (int) (($height - $newHeight) / 2);
            
            // Вставляем изображение в центр квадрата
            imagecopy($canvas, $resizedImage, $x, $y, 0, 0, $newWidth, $newHeight);
            
            // Сохраняем как JPEG
            $result = imagejpeg($canvas, $outputPath, $quality);
            
            // Освобождаем память
            imagedestroy($sourceImage);
            imagedestroy($resizedImage);
            imagedestroy($canvas);
            
            if (!$result) {
                throw new \Exception('Не удалось сохранить оптимизированное изображение');
            }
            
            Log::info("Image optimized and saved to: {$outputPath}");
            Log::info("Final file size: " . filesize($outputPath) . " bytes");
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Ошибка оптимизации изображения: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Загрузить аватар пользователя
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        try {
            // Отладочная информация
            Log::info('AvatarUploadController::uploadAvatar called', [
                'user_id' => $request->user()?->id,
                'has_file' => $request->hasFile('avatar'),
                'method' => $request->method(),
                'url' => $request->url(),
                'headers' => $request->headers->all()
            ]);
            
            // Проверяем авторизацию
            if (!$request->user()) {
                Log::warning('Unauthorized access to uploadAvatar');
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }
            
            // Валидация файла
            $request->validate([
                'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB max
            ], [
                'avatar.required' => 'Файл аватара обязателен',
                'avatar.image' => 'Файл должен быть изображением',
                'avatar.mimes' => 'Поддерживаются только форматы: jpeg, png, jpg, gif, webp',
                'avatar.max' => 'Размер файла не должен превышать 5MB',
            ]);

            $file = $request->file('avatar');
            
            // Получаем информацию о файле ДО перемещения
            $fileSize = $file->getSize();
            $mimeType = $file->getMimeType();
            
            // Получаем ID пользователя
            $userId = $request->user()->id;
            
            // Генерируем имя файла как user_{id} (всегда jpg после оптимизации)
            $filename = 'user_' . $userId . '.jpg';
            
            // Путь к папке на фронтенде из переменной окружения
            $frontendPath = dirname(base_path()) . '/' . ltrim(env('FRONTEND_PATH', 'admin.skateandsnow.ru'), './') . '/public/images/users/';
            
            Log::info('Frontend path: ' . $frontendPath);
            Log::info('Frontend path exists: ' . (file_exists($frontendPath) ? 'yes' : 'no'));
            
            // Создаем папку если не существует
            if (!file_exists($frontendPath)) {
                if (!mkdir($frontendPath, 0755, true)) {
                    throw new \Exception('Не удалось создать папку для аватаров');
                }
                Log::info('Created frontend directory: ' . $frontendPath);
            }
            
            // Удаляем старый аватар пользователя, если он есть
            $oldAvatarPath = $frontendPath . 'user_' . $userId . '.jpg';
            $oldAvatarPathPng = $frontendPath . 'user_' . $userId . '.png';
            $oldAvatarPathGif = $frontendPath . 'user_' . $userId . '.gif';
            $oldAvatarPathWebp = $frontendPath . 'user_' . $userId . '.webp';
            
            if (file_exists($oldAvatarPath)) {
                unlink($oldAvatarPath);
                Log::info('Deleted old avatar: ' . $oldAvatarPath);
            }
            if (file_exists($oldAvatarPathPng)) {
                unlink($oldAvatarPathPng);
                Log::info('Deleted old avatar: ' . $oldAvatarPathPng);
            }
            if (file_exists($oldAvatarPathGif)) {
                unlink($oldAvatarPathGif);
                Log::info('Deleted old avatar: ' . $oldAvatarPathGif);
            }
            if (file_exists($oldAvatarPathWebp)) {
                unlink($oldAvatarPathWebp);
                Log::info('Deleted old avatar: ' . $oldAvatarPathWebp);
            }
            
            // Полный путь к файлу
            $fullPath = $frontendPath . $filename;
            
            // Временно сохраняем файл для обработки
            $tempFilename = 'temp_' . uniqid() . '_' . $file->getClientOriginalName();
            $tempFullPath = storage_path('app/temp/' . $tempFilename);
            
            // Создаем папку temp если не существует
            $tempDir = storage_path('app/temp');
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            // Перемещаем файл напрямую
            if (!$file->move($tempDir, $tempFilename)) {
                throw new \Exception('Не удалось сохранить временный файл');
            }
            
            Log::info('Temporary file saved to: ' . $tempFullPath);
            Log::info('Temporary file exists: ' . (file_exists($tempFullPath) ? 'yes' : 'no'));
            Log::info('Temporary file size: ' . (file_exists($tempFullPath) ? filesize($tempFullPath) : 'N/A') . ' bytes');
            
            // Оптимизируем изображение
            if (!$this->optimizeImage($tempFullPath, $fullPath, 500, 500, 85)) {
                Log::warning('Image optimization failed, copying file without optimization');
                
                // Fallback: просто копируем файл без оптимизации
                if (!copy($tempFullPath, $fullPath)) {
                    Log::error('Failed to copy file from: ' . $tempFullPath . ' to: ' . $fullPath);
                    // Удаляем временный файл
                    if (file_exists($tempFullPath)) {
                        unlink($tempFullPath);
                    }
                    throw new \Exception('Не удалось сохранить файл аватара');
                }
                
                Log::info('File copied without optimization to: ' . $fullPath);
            }
            
            // Удаляем временный файл только после успешного сохранения
            if (file_exists($tempFullPath)) {
                unlink($tempFullPath);
                Log::info('Temporary file deleted: ' . $tempFullPath);
            }
            
            // Проверяем, что файл действительно сохранился
            if (!file_exists($fullPath)) {
                Log::error('Optimized file not saved to: ' . $fullPath);
                throw new \Exception('Оптимизированный файл не был сохранен');
            }
            
            Log::info('Optimized file saved successfully to: ' . $fullPath);
            Log::info('Final file size: ' . filesize($fullPath) . ' bytes');
            
            // Путь для базы данных (относительно фронтенда)
            $dbPath = '/images/users/' . $filename;
            
            // Получаем информацию об оптимизированном файле
            $optimizedFileSize = filesize($fullPath);
            $compressionRatio = round((1 - $optimizedFileSize / $fileSize) * 100, 2);
            
            // Аватар сохраняется только в папке images/users/user_{id}.jpg
            // Поля avatar и avatar_url больше не используются в базе данных
            Log::info('Avatar saved to: ' . $fullPath);

            // Возвращаем успешный ответ
            return response()->json([
                'success' => true,
                'message' => 'Аватар успешно загружен и оптимизирован',
                'data' => [
                    'path' => $dbPath,
                    'url' => $dbPath,
                    'filename' => $filename,
                    'original_size' => $fileSize,
                    'optimized_size' => $optimizedFileSize,
                    'compression_ratio' => $compressionRatio . '%',
                    'dimensions' => '500x500',
                    'mime_type' => 'image/jpeg',
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Ошибка загрузки аватара: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки файла',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    /**
     * Удалить файл аватара по ID пользователя
     */
    public function deleteAvatarByUserId(Request $request): JsonResponse
    {
        try {
            // Проверяем авторизацию
            if (!$request->user()) {
                Log::warning('Unauthorized access to deleteAvatarByUserId');
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован'
                ], 401);
            }

            $userId = $request->user()->id;
            
            // Получаем пользователя из базы данных
            $user = $request->user();

            // Путь к папке на фронтенде
            $frontendPath = dirname(base_path()) . '/' . ltrim(env('FRONTEND_PATH', 'admin.skateandsnow.ru'), './') . '/public/images/users/';
            
            // Всегда используем стандартное имя файла user_{id}.jpg
            // Не проверяем БД - просто удаляем файл, если он есть
            $filename = 'user_' . $userId . '.jpg';
            $fullPath = $frontendPath . $filename;
            
            
            // Проверяем существование файла
            if (file_exists($fullPath)) {
                // Удаляем файл
                if (unlink($fullPath)) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Аватар успешно удален с диска'
                    ], 200);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Не удалось удалить файл аватара'
                    ], 500);
                }
            } else {
                return response()->json([
                    'success' => true,
                    'message' => 'Файл аватара не найден (уже удален)'
                ], 200);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка удаления аватара по ID пользователя: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления файла',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    /**
     * Удалить файл аватара (старый метод для совместимости)
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        try {
            $avatarPath = $request->input('avatar_path');
            
            if (!$avatarPath) {
                return response()->json([
                    'success' => false,
                    'message' => 'Путь к аватару не указан'
                ], 400);
            }

            // Убираем /storage/ префикс для работы с Storage
            $filePath = str_replace('/storage/', '', $avatarPath);
            
            // Проверяем существование файла
            if (Storage::disk('public')->exists($filePath)) {
                // Удаляем файл
                Storage::disk('public')->delete($filePath);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Аватар успешно удален'
                ], 200);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл не найден'
                ], 404);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка удаления аватара: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления файла',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    /**
     * Получить информацию о файле
     */
    public function getFileInfo(Request $request): JsonResponse
    {
        try {
            $filePath = $request->input('path');
            
            if (!$filePath) {
                return response()->json([
                    'success' => false,
                    'message' => 'Путь к файлу не указан'
                ], 400);
            }

            // Убираем /storage/ префикс
            $filePath = str_replace('/storage/', '', $filePath);
            
            if (!Storage::disk('public')->exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Файл не найден'
                ], 404);
            }

            $fullPath = Storage::disk('public')->path($filePath);
            $fileInfo = [
                'path' => '/storage/' . $filePath,
                'size' => Storage::disk('public')->size($filePath),
                'last_modified' => Storage::disk('public')->lastModified($filePath),
                'mime_type' => mime_content_type($fullPath),
            ];

            return response()->json([
                'success' => true,
                'data' => $fileInfo
            ], 200);

        } catch (\Exception $e) {
            Log::error('Ошибка получения информации о файле: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения информации о файле',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
            ], 500);
        }
    }
}
