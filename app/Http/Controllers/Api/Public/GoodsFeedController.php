<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GoodsFeedController extends Controller
{
    public function getYandexProductsFeed(Request $request)
    {
        return $this->getGoodsFeed($request);
    }

    public function getGoodsFeed(Request $request)
    {
        $fileName = 'exports/goods_feed.xml';
        $disk = Storage::disk('public');
        
        if (!$disk->exists($fileName)) {
            return response('<?xml version="1.0" encoding="UTF-8"?><rss><channel><title>Фид не найден</title></channel></rss>', 404)
                ->header('Content-Type', 'application/xml');
        }

        $fullPath = $disk->path($fileName);
        
        // Очищаем буфер вывода, чтобы избежать проблем с лишними символами или 0-байтовыми файлами
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Если передан параметр download=1, принудительно скачиваем файл
        if ($request->query('download') == '1') {
            return $disk->download($fileName, 'goods_feed.xml', [
                'Content-Type' => 'application/xml; charset=utf-8',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        }

        // По умолчанию (для ботов Яндекса) просто выводим содержимое файла
        return response()->file($fullPath, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
