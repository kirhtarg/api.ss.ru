<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ImageCleanupService;
use Illuminate\Http\Request;

class ImageCleanupController extends Controller
{
    protected $service;

    public function __construct(ImageCleanupService $service)
    {
        $this->service = $service;
    }

    public function scan(Request $request)
    {
        $limit = $request->input('limit');
        $offset = $request->input('offset', 0);
        $type = $request->input('type', 'duplicates');
        
        if ($type === 'broken') {
            return response()->json($this->service->getBrokenLinks($limit, $offset));
        }

        if ($type === 'external') {
            return response()->json($this->service->getExternalLinks($limit, $offset));
        }
        
        return response()->json($this->service->getDuplicates($limit, $offset));
    }

    public function destroy(Request $request)
    {
        $ids = $request->input('ids', []);
        $type = $request->input('type');
        $items = $request->input('items', []);

        return response()->json($this->service->cleanup($ids, [
            'type' => $type,
            'items' => is_array($items) ? $items : [],
        ]));
    }

    public function downloadExternal(Request $request)
    {
        $ids = $request->input('ids', []);
        $items = $request->input('items', []);

        return response()->json($this->service->downloadExternal($ids, [
            'items' => is_array($items) ? $items : [],
        ]));
    }
}
