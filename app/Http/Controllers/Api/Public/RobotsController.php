<?php

namespace App\Http\Controllers\Api\Public;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class RobotsController extends Controller
{
    public function getRobots(Request $request)
    {
        $filePath = 'public/exports/robots.txt';

        if (!Storage::exists($filePath)) {
            // Если файл не найден, создаем дефолтный
            $content = [
                'User-agent: *',
                'Disallow:',
                'Sitemap: ' . ($request->getSchemeAndHttpHost() ?: 'http://localhost:3000') . '/sitemap.xml'
            ].join("\n");
            
            return response($content, 200)
                ->header('Content-Type', 'text/plain; charset=utf-8')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');
        }

        $file = Storage::get($filePath);

        return response($file, 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
