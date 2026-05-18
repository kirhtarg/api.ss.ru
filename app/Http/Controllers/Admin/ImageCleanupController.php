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
        
        return response()->json($this->service->getDuplicates($limit, $offset));
    }

    public function destroy(Request $request)
    {
        $ids = $request->input('ids', []);
        return response()->json($this->service->cleanup($ids));
    }
}
