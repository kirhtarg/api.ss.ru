<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
// use Intervention\Image\Facades\Image;

class PaymentMethodImageController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'payment_method_id' => 'required|exists:shop_payment_methods,id'
        ]);

        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($request->payment_method_id);
            
            // Delete old image if exists
            if ($paymentMethod->image_url) {
                $this->deleteImage($paymentMethod->image_url);
            }

            // Get the uploaded file
            $file = $request->file('image');
            
            // Generate filename based on payment method ID
            $filename = 'payment_' . $paymentMethod->id . '.jpg';
            
            // Get frontend path from environment variable (path from backend root to frontend root)
            $frontendPath = env('FRONTEND_PATH', '../admin.skateandsnow.ru');
            
            // Build the correct path to frontend public folder
            $imagePath = base_path($frontendPath . '/public/images/payment/');
            
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

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно загружено',
                'data' => [
                    'image_url' => '/images/payment/' . $filename
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Payment method image upload error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка загрузки изображения: ' . $e->getMessage()
            ], 500);
        }
    }

    public function remove(Request $request)
    {
        $request->validate([
            'payment_method_id' => 'required|exists:shop_payment_methods,id'
        ]);

        try {
            $paymentMethod = ShopPaymentMethod::findOrFail($request->payment_method_id);
            
            // Delete image file
            $imagePath = '/images/payment/payment_' . $paymentMethod->id . '.jpg';
            
            $this->deleteImage($imagePath);

            return response()->json([
                'success' => true,
                'message' => 'Изображение успешно удалено'
            ]);

        } catch (\Exception $e) {
            \Log::error('Payment method image remove error: ' . $e->getMessage());
            
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
