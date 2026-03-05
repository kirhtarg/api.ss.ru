<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\SiteTextblock;

class TextblockController extends Controller
{
    /**
     * Получить конкретный текстовый блок по ID (только если он активен)
     */
    public function show($id)
    {
        try {
            $textblock = SiteTextblock::active()->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $textblock->id,
                    'name' => $textblock->name,
                    'text' => $textblock->text,
                    'background_color' => $textblock->background_color,
                    'text_color' => $textblock->text_color,
                    'link' => $textblock->link,
                    'link_type' => $textblock->link_type,
                    'is_active' => $textblock->is_active,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения текстового блока: '.$e->getMessage(),
            ], 404);
        }
    }
}
