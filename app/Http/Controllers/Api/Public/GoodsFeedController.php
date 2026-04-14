<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GoodsFeedController extends Controller
{
    public function getGoodsFeed(Request $request)
    {
        $filePath = 'public/exports/goods_feed.xml';
        if (! Storage::exists($filePath)) {
            $content = `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
  <channel>
    <title>Товары магазина</title>
    <link>{$request->getSchemeAndHttpHost()}</link>
    <description>Каталог товаров</description>
  </channel>
</rss>`;

            return response($content, 200)
                ->header('Content-Type', 'application/xml; charset=utf-8')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');
        }
        $file = Storage::get($filePath);

        $response = response($file, 200)
            ->header('Content-Type', 'application/xml; charset=utf-8')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
            
        if ($request->has('download') && $request->get('download') == '1') {
            $response->header('Content-Disposition', 'attachment; filename="goods_feed.xml"');
        }

        return $response;
    }
}
