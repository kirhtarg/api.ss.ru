<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopBonusSettings;
use Illuminate\Http\Request;

class BonusSettingImageController extends Controller
{
    public function upload(Request $request)
    {
        try {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:10240',
                'bonus_setting_id' => 'required|exists:shop_bonus_settings,id',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Bonus setting image upload validation error', [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации: '.implode(', ', array_map(function ($errors) {
                    return implode(', ', $errors);
                }, $e->errors())),
            ], 422);
        }

        try {
            $bonusSetting = ShopBonusSettings::findOrFail($request->bonus_setting_id);

            // Delete old image if exists (by template name)
            $oldImagePath = $this->getImagePath($bonusSetting->id);
            if (file_exists($oldImagePath)) {
                unlink($oldImagePath);
            }

            // Get the uploaded file
            $file = $request->file('image');

            if (! $file || ! $file->isValid()) {
                throw new \Exception('Файл не был загружен или поврежден');
            }

            // Generate filename based on bonus setting ID
            $filename = 'bsys_'.$bonusSetting->id.'.jpg';

            // Путь к public фронтенда (из FRONTEND_PATH в .env)
            $imagePath = frontend_public_path('images/bsys/');

            // Ensure directory exists
            if (! file_exists($imagePath)) {
                if (! mkdir($imagePath, 0755, true)) {
                    throw new \Exception('Не удалось создать директорию для изображений: '.$imagePath);
                }
            }

            // Check if directory is writable
            if (! is_writable($imagePath)) {
                throw new \Exception('Директория не доступна для записи: '.$imagePath);
            }

            // Process and save image using GD (built-in PHP)
            $imageInfo = getimagesize($file->getPathname());
            if ($imageInfo === false) {
                throw new \Exception('Не удалось определить тип изображения');
            }
            $imageType = $imageInfo[2];

            // Create image resource based on type
            switch ($imageType) {
                case IMAGETYPE_JPEG:
                    $sourceImage = imagecreatefromjpeg($file->getPathname());
                    break;
                case IMAGETYPE_PNG:
                    $sourceImage = imagecreatefrompng($file->getPathname());
                    break;
                case IMAGETYPE_GIF:
                    $sourceImage = imagecreatefromgif($file->getPathname());
                    break;
                default:
                    throw new \Exception('Неподдерживаемый тип изображения');
            }

            // Get original dimensions
            $originalWidth = imagesx($sourceImage);
            $originalHeight = imagesy($sourceImage);

            // Target dimensions with cropping
            $targetWidth = 300;
            $targetHeight = 200;

            // Calculate scaling to cover the target size (crop mode)
            $scaleX = $targetWidth / $originalWidth;
            $scaleY = $targetHeight / $originalHeight;
            $scale = max($scaleX, $scaleY); // Use larger scale to ensure coverage

            // Calculate scaled dimensions
            $scaledWidth = (int) ($originalWidth * $scale);
            $scaledHeight = (int) ($originalHeight * $scale);

            // Calculate crop position (center crop)
            $cropX = (int) (($scaledWidth - $targetWidth) / 2);
            $cropY = (int) (($scaledHeight - $targetHeight) / 2);

            // Create temporary image for scaling
            $tempImage = imagecreatetruecolor($scaledWidth, $scaledHeight);

            // Preserve transparency for PNG
            if ($imageType == IMAGETYPE_PNG) {
                imagealphablending($tempImage, false);
                imagesavealpha($tempImage, true);
                $transparent = imagecolorallocatealpha($tempImage, 255, 255, 255, 127);
                imagefilledrectangle($tempImage, 0, 0, $scaledWidth, $scaledHeight, $transparent);
            }

            // Scale image
            imagecopyresampled($tempImage, $sourceImage, 0, 0, 0, 0, $scaledWidth, $scaledHeight, $originalWidth, $originalHeight);

            // Create final image with target dimensions
            $newImage = imagecreatetruecolor($targetWidth, $targetHeight);

            // Preserve transparency for PNG
            if ($imageType == IMAGETYPE_PNG) {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
                imagefilledrectangle($newImage, 0, 0, $targetWidth, $targetHeight, $transparent);
            }

            // Crop and copy to final image (use imagecopy for cropping)
            imagecopy($newImage, $tempImage, 0, 0, $cropX, $cropY, $targetWidth, $targetHeight);

            // Clean up temporary image
            imagedestroy($tempImage);

            // Save the image
            $fullPath = $imagePath.$filename;

            switch ($imageType) {
                case IMAGETYPE_JPEG:
                    if (! imagejpeg($newImage, $fullPath, 80)) {
                        throw new \Exception('Не удалось сохранить JPEG изображение');
                    }
                    break;
                case IMAGETYPE_PNG:
                    if (! imagepng($newImage, $fullPath, 8)) {
                        throw new \Exception('Не удалось сохранить PNG изображение');
                    }
                    break;
                case IMAGETYPE_GIF:
                    if (! imagegif($newImage, $fullPath)) {
                        throw new \Exception('Не удалось сохранить GIF изображение');
                    }
                    break;
            }

            // Clean up memory
            imagedestroy($sourceImage);
            imagedestroy($newImage);

            // Verify file was saved
            if (! file_exists($fullPath)) {
                throw new \Exception('Файл не был сохранен: '.$fullPath);
            }

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'data' => [
                    'image_url' => '/images/bsys/'.$filename,
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Bonus setting image upload error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: '.$e->getMessage(),
            ], 500);
        }
    }

    public function remove(Request $request)
    {
        $request->validate([
            'bonus_setting_id' => 'required|exists:shop_bonus_settings,id',
        ]);

        try {
            $bonusSetting = ShopBonusSettings::findOrFail($request->bonus_setting_id);

            // Delete image file by template name
            $imagePath = $this->getImagePath($bonusSetting->id);
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно удалено',
            ]);

        } catch (\Exception $e) {
            \Log::error('Bonus setting image remove error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления изображения: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get image path by bonus setting ID
     */
    private function getImagePath($bonusSettingId)
    {
        $imagePath = frontend_public_path('images/bsys/');
        $filename = 'bsys_'.$bonusSettingId.'.jpg';

        return $imagePath.$filename;
    }
}
