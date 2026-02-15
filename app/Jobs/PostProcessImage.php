<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PostProcessImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $path;
    public string $resize;
    public ?int $width;
    public ?int $height;

    public function __construct(string $path, string $resize = 'no_change', ?int $width = null, ?int $height = null)
    {
        $this->path = $path;
        $this->resize = $resize;
        $this->width = $width;
        $this->height = $height;
        $this->onConnection('redis');
        $this->onQueue('images');
    }

    public function handle(): void
    {
        $filePath = $this->path;
        if (!file_exists($filePath)) {
            return;
        }

        $imageInfo = @getimagesize($filePath);
        if (!$imageInfo) {
            return;
        }

        $originalWidth = $imageInfo[0];
        $originalHeight = $imageInfo[1];
        $mimeType = $imageInfo['mime'] ?? null;

        $width = $this->width ?: $originalWidth;
        $height = $this->height ?: $originalHeight;

        if ($this->resize === 'no_change') {
            $this->optimize($filePath, $mimeType, $originalWidth, $originalHeight);
            return;
        }

        $sourceImage = null;
        switch ($mimeType) {
            case 'image/jpeg':
                $sourceImage = @imagecreatefromjpeg($filePath);
                break;
            case 'image/png':
                $sourceImage = @imagecreatefrompng($filePath);
                break;
            case 'image/gif':
                $sourceImage = @imagecreatefromgif($filePath);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $sourceImage = @imagecreatefromwebp($filePath);
                }
                break;
        }

        if (!$sourceImage) {
            return;
        }

        $newImage = null;
        if ($this->resize === 'crop_proportional') {
            $newImage = $this->cropProportional($sourceImage, $originalWidth, $originalHeight, $width, $height, $mimeType);
        } elseif ($this->resize === 'fit_with_white') {
            $newImage = $this->fitWithWhiteBackground($sourceImage, $originalWidth, $originalHeight, $width, $height, $mimeType);
        } elseif ($this->resize === 'fit_system' || $this->resize === 'custom') {
            $newImage = $this->fitSystemSize($sourceImage, $originalWidth, $originalHeight, $width, $height, $mimeType);
        }

        if ($newImage) {
            switch ($mimeType) {
                case 'image/jpeg':
                    @imagejpeg($newImage, $filePath, 85);
                    break;
                case 'image/png':
                    @imagepng($newImage, $filePath, 8);
                    break;
                case 'image/gif':
                    @imagegif($newImage, $filePath);
                    break;
                case 'image/webp':
                    if (function_exists('imagewebp')) {
                        @imagewebp($newImage, $filePath, 85);
                    }
                    break;
            }
            @imagedestroy($newImage);
        }

        @imagedestroy($sourceImage);
    }

    private function optimize(string $filePath, ?string $mimeType, int $width, int $height): void
    {
        if ($width <= 2000 && $height <= 2000) {
            return;
        }
        $newWidth = $width > $height ? 2000 : intval(2000 * $width / $height);
        $newHeight = $height > $width ? 2000 : intval(2000 * $height / $width);
        $sourceImage = null;
        switch ($mimeType) {
            case 'image/jpeg':
                $sourceImage = @imagecreatefromjpeg($filePath);
                break;
            case 'image/png':
                $sourceImage = @imagecreatefrompng($filePath);
                break;
            case 'image/gif':
                $sourceImage = @imagecreatefromgif($filePath);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $sourceImage = @imagecreatefromwebp($filePath);
                }
                break;
        }
        if (!$sourceImage) {
            return;
        }
        $resizedImage = @imagecreatetruecolor($newWidth, $newHeight);
        if ($mimeType === 'image/png') {
            @imagealphablending($resizedImage, false);
            @imagesavealpha($resizedImage, true);
            $transparent = @imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
            @imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
        }
        @imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        switch ($mimeType) {
            case 'image/jpeg':
                @imagejpeg($resizedImage, $filePath, 85);
                break;
            case 'image/png':
                @imagepng($resizedImage, $filePath, 8);
                break;
            case 'image/gif':
                @imagegif($resizedImage, $filePath);
                break;
            case 'image/webp':
                if (function_exists('imagewebp')) {
                    @imagewebp($resizedImage, $filePath, 85);
                }
                break;
        }
        @imagedestroy($sourceImage);
        @imagedestroy($resizedImage);
    }

    private function cropProportional($sourceImage, int $originalWidth, int $originalHeight, int $targetWidth, int $targetHeight, string $mimeType)
    {
        $scaleX = $targetWidth / $originalWidth;
        $scaleY = $targetHeight / $originalHeight;
        $scale = max($scaleX, $scaleY);
        $newWidth = intval($originalWidth * $scale);
        $newHeight = intval($originalHeight * $scale);
        $newImage = @imagecreatetruecolor($targetWidth, $targetHeight);
        if ($mimeType === 'image/png') {
            @imagealphablending($newImage, false);
            @imagesavealpha($newImage, true);
            $transparent = @imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            @imagefilledrectangle($newImage, 0, 0, $targetWidth, $targetHeight, $transparent);
        }
        $cropX = intval(($newWidth - $targetWidth) / 2);
        $cropY = intval(($newHeight - $targetHeight) / 2);
        @imagecopyresampled($newImage, $sourceImage, -$cropX, -$cropY, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
        return $newImage;
    }

    private function fitWithWhiteBackground($sourceImage, int $originalWidth, int $originalHeight, int $targetWidth, int $targetHeight, string $mimeType)
    {
        $scaleX = $targetWidth / $originalWidth;
        $scaleY = $targetHeight / $originalHeight;
        $scale = min($scaleX, $scaleY);
        $newWidth = intval($originalWidth * $scale);
        $newHeight = intval($originalHeight * $scale);
        $newImage = @imagecreatetruecolor($targetWidth, $targetHeight);
        $white = @imagecolorallocate($newImage, 255, 255, 255);
        @imagefilledrectangle($newImage, 0, 0, $targetWidth, $targetHeight, $white);
        $dstX = intval(($targetWidth - $newWidth) / 2);
        $dstY = intval(($targetHeight - $newHeight) / 2);
        @imagecopyresampled($newImage, $sourceImage, $dstX, $dstY, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
        return $newImage;
    }

    private function fitSystemSize($sourceImage, int $originalWidth, int $originalHeight, int $targetWidth, int $targetHeight, string $mimeType)
    {
        $scaleX = $targetWidth / $originalWidth;
        $scaleY = $targetHeight / $originalHeight;
        $scale = min($scaleX, $scaleY);
        $newWidth = intval($originalWidth * $scale);
        $newHeight = intval($originalHeight * $scale);
        $newImage = @imagecreatetruecolor($newWidth, $newHeight);
        if ($mimeType === 'image/png') {
            @imagealphablending($newImage, false);
            @imagesavealpha($newImage, true);
            $transparent = @imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            @imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
        }
        @imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
        return $newImage;
    }
}
