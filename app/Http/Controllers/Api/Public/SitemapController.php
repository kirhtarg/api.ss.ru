<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class SitemapController extends Controller
{
    public function getSitemap(Request $request)
    {
        $filePath = 'public/exports/sitemap.xml';
        if (! Storage::exists($filePath)) {
            $host = htmlspecialchars($request->getSchemeAndHttpHost(), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $content = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>{$host}/</loc>
    <changefreq>daily</changefreq>
    <priority>1.0</priority>
  </url>
</urlset>
XML;

            Log::warning('[FIX:seo] Generated emergency sitemap because export file is missing', [
                'path' => $filePath,
                'host' => $request->getSchemeAndHttpHost(),
            ]);

            return response($content, 200)
                ->header('Content-Type', 'application/xml; charset=utf-8')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');
        }
        $file = Storage::get($filePath);

        return response($file, 200)
            ->header('Content-Type', 'application/xml; charset=utf-8')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
