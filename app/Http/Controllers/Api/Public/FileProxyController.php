<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

class FileProxyController extends Controller
{
    public function get(Request $request, $filename)
    {
        $filePath = 'public/exports/' . $filename;

        if (!Storage::exists($filePath)) {
            return response()->json(['error' => 'File not found.'], 404);
        }

        $file = Storage::get($filePath);
        $mimeType = Storage::mimeType($filePath);

        return response($file, 200)
            ->header('Content-Type', $mimeType)
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function getSeoFile(Request $request, $filename)
    {
        // Тот же функционал, но через альтернативный маршрут
        return $this->get($request, $filename);
    }
}
