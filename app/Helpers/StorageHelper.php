<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class StorageHelper
{
    /**
     * Safely create directory with proper error handling
     *
     * @param string $path
     * @param int $permissions
     * @return bool
     */
    public static function createDirectory(string $path, int $permissions = 0755): bool
    {
        try {
            // Check if directory already exists
            if (is_dir($path)) {
                return true;
            }

            // Create directory recursively
            if (mkdir($path, $permissions, true)) {
                Log::info("Directory created successfully: {$path}");
                return true;
            } else {
                Log::error("Failed to create directory: {$path}");
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Exception while creating directory {$path}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get storage path for images
     *
     * @param string $type
     * @return string
     */
    public static function getImageStoragePath(string $type = 'goods'): string
    {
        $basePath = storage_path('app/public/images/shop/' . $type);
        
        // Ensure directory exists
        self::createDirectory($basePath);
        
        return $basePath;
    }

    /**
     * Get full storage path for file
     *
     * @param string $relativePath
     * @return string
     */
    public static function getStoragePath(string $relativePath): string
    {
        $fullPath = storage_path('app/public/' . ltrim($relativePath, '/'));
        
        // Ensure parent directory exists
        $parentDir = dirname($fullPath);
        self::createDirectory($parentDir);
        
        return $fullPath;
    }
}
