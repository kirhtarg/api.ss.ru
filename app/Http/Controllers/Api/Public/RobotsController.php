<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RobotsController extends Controller
{
    public function getRobots(Request $request)
    {
        $catalogFilterParamLine = 'Clean-param: attributes&stock_filter&page&sort&price_from&price_to&search /catalog/';
        $disk = Storage::disk('public');
        $filePath = 'exports/robots.txt';

        if (! $disk->exists($filePath)) {
            // Если файл не найден, создаем дефолтный
            $content = [
                'User-agent: *',
                'Disallow:',
                'Clean-param: etext&utm_source&utm_medium&utm_campaign&utm_content&utm_term&yclid&ymclid&gclid&fbclid&from&roistat&openstat /',
                $catalogFilterParamLine,
                'Sitemap: '.($request->getSchemeAndHttpHost() ?: 'http://localhost:3000').'/sitemap.xml',
            ].implode("\n");

            return response($content, 200)
                ->header('Content-Type', 'text/plain; charset=utf-8')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');
        }

        $file = $disk->get($filePath);
        if (! str_contains($file, $catalogFilterParamLine)) {
            $file = str_contains($file, "\nSitemap:")
                ? str_replace("\nSitemap:", "\n{$catalogFilterParamLine}\nSitemap:", $file)
                : rtrim($file)."\n{$catalogFilterParamLine}\n";
        }

        return response($file, 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
