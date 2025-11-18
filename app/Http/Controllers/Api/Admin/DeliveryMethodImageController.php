<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopDeliveryMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DeliveryMethodImageController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'delivery_method_id' => 'required|exists:shop_delivery_methods,id'
        ]);

        try {
            $deliveryMethod = ShopDeliveryMethod::findOrFail($request->delivery_method_id);
            
            // Delete old image if exists (путь формируется автоматически по ID)
            $oldImagePath = '/images/deliveries/delivery_' . $deliveryMethod->id . '.jpg';
            $this->deleteImage($oldImagePath);

            // Get the uploaded file
            $file = $request->file('image');
            
            // Generate filename based on delivery method ID
            $filename = 'delivery_' . $deliveryMethod->id . '.jpg';
            
            // Get frontend path from environment variable (path from backend root to frontend root)
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            
            // Build the correct path to frontend public folder
            $imagePath = base_path($frontendPath . '/public/images/deliveries/');
            
            // Ensure directory exists
            if (!file_exists($imagePath)) {
                mkdir($imagePath, 0755, true);
            }
            
            
            // Process and save image using GD (built-in PHP)
            $imageInfo = getimagesize($file->getPathname());
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
            
            // Calculate new dimensions maintaining aspect ratio
            $maxWidth = 200;
            $maxHeight = 150;
            
            $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
            $newWidth = (int)($originalWidth * $ratio);
            $newHeight = (int)($originalHeight * $ratio);
            
            // Create new image
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency for PNG
            if ($imageType == IMAGETYPE_PNG) {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
                imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
            }
            
            // Resize image
            imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
            
            // Save the image
            $fullPath = $imagePath . $filename;
            
            switch ($imageType) {
                case IMAGETYPE_JPEG:
                    imagejpeg($newImage, $fullPath, 80);
                    break;
                case IMAGETYPE_PNG:
                    imagepng($newImage, $fullPath, 8);
                    break;
                case IMAGETYPE_GIF:
                    imagegif($newImage, $fullPath);
                    break;
            }
            
            // Clean up memory
            imagedestroy($sourceImage);
            imagedestroy($newImage);

            // Изображение сохраняется в файл, URL формируется автоматически по ID
            $imageUrl = '/images/deliveries/' . $filename;

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'data' => [
                    'image_url' => $imageUrl
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Delivery method image upload error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    public function remove(Request $request)
    {
        $request->validate([
            'delivery_method_id' => 'required|exists:shop_delivery_methods,id'
        ]);

        try {
            $deliveryMethod = ShopDeliveryMethod::findOrFail($request->delivery_method_id);
            
            // Delete image file
            $imagePath = '/images/deliveries/delivery_' . $deliveryMethod->id . '.jpg';
            
            $this->deleteImage($imagePath);

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно удалено'
            ]);

        } catch (\Exception $e) {
            \Log::error('Delivery method image remove error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    private function deleteImage($imageUrl)
    {
        try {
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            $fullPath = base_path($frontendPath . '/public' . $imageUrl);
            
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        } catch (\Exception $e) {
            \Log::error('Error deleting image: ' . $e->getMessage());
        }
    }
}

