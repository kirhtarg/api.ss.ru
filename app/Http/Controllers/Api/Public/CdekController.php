<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\CdekService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CdekController extends Controller
{
    private $cdekService;

    public function __construct(CdekService $cdekService)
    {
        $this->cdekService = $cdekService;
    }

    public function searchCities(Request $request): JsonResponse
    {
        try {
            $query = $request->get('q', '');
            if (strlen($query) < 2) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $cities = $this->cdekService->searchCities($query);
            return response()->json(['success' => true, 'data' => $cities]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function searchStreets(Request $request): JsonResponse
    {
        try {
            $cityCode = $request->get('city_code');
            $query = $request->get('q', '');
            
            if (!$cityCode || strlen($query) < 2) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $streets = $this->cdekService->searchStreets($cityCode, $query);
            return response()->json(['success' => true, 'data' => $streets]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function calculateDelivery(Request $request): JsonResponse
    {
        try {
            $from = $request->get('from');
            $to = $request->get('to');
            $packages = $request->get('packages', []);

            $result = $this->cdekService->calculateDelivery($from, $to, $packages);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getPvzList(Request $request): JsonResponse
    {
        try {
            $cityCode = $request->get('city_code');
            if (!$cityCode) {
                return response()->json(['success' => false, 'message' => 'City code required'], 400);
            }

            $pvzList = $this->cdekService->getPvzList($cityCode);
            return response()->json(['success' => true, 'data' => $pvzList]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
