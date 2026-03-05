<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ConstructorPage;

class PageBuilderController extends Controller
{
    /**
     * Получить опубликованную страницу по slug
     */
    public function getPublishedPageBySlug($slug)
    {
        try {
            $page = ConstructorPage::where('slug', $slug)
                ->published()
                ->first();

            if (! $page) {
                return response()->json([
                    'success' => false,
                    'message' => 'Страница не найдена',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $page,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения страницы: '.$e->getMessage(),
            ], 500);
        }
    }
}
