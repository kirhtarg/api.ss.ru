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
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
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
}
