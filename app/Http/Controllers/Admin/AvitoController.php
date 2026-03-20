<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AvitoApiService;
use App\Services\AvitoFeedService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use App\Models\Setting;
use App\Models\ShopCategory;
use App\Models\ExportFile;
use Illuminate\Support\Facades\Storage;

class AvitoController extends Controller
{
    /**
     * Получить текущие настройки и сопоставление категорий
     */
    public function getSettings()
    {
        $settings = Setting::where('group', 'avito')->get()->pluck('value', 'key');

        // Получаем характеристики для маппинга
        $shopProperties = \App\Models\ShopProperty::orderBy('name')->get();

        // Получаем дерево категорий для маппинга
        $categories = ShopCategory::whereNull('parent_id')
            ->with(['children' => function ($query) {
            $query->with('children');
        }])
            ->get();

        // Получаем историю экспортов Авито
        $history = ExportFile::where('format', 'avito_xml')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Получаем постоянные теги
        $tagsJson = Setting::where('key', 'avito_tags')->value('value');
        $tags = is_string($tagsJson) ? json_decode($tagsJson, true) : ($tagsJson ?: []);

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => $settings,
                'categories' => $categories,
                'shop_properties' => $shopProperties,
                'history' => $history,
                'tags' => $tags,
            ]
        ]);
    }
    
    /**
     * Обновить постоянные теги
     */
    public function updateTags(Request $request)
    {
        $request->validate([
            'tags' => 'required|array'
        ]);

        $setting = Setting::where('key', 'avito_tags')->first();
        if ($setting) {
            $setting->update(['value' => json_encode($request->tags, JSON_UNESCAPED_UNICODE)]);
        }
        else {
            Setting::create([
                'key' => 'avito_tags',
                'value' => json_encode($request->tags, JSON_UNESCAPED_UNICODE),
                'group' => 'avito'
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Обновить общие настройки Авито
     */
    public function updateSettings(Request $request)
    {
        $settings = $request->validate([
            'avito_client_id' => 'nullable|string',
            'avito_api_key' => 'nullable|string',
            'avito_feed_enabled' => 'nullable|boolean',
        ]);

        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => 'avito']
            );
        }

        return response()->json(['success' => true]);
    }

    /**
     * Обновить сопоставление категорий
     */
    public function updateMapping(Request $request)
    {
        $request->validate([
            'mapping' => 'required|array'
        ]);

        $setting = Setting::where('key', 'avito_category_mapping')->first();
        if ($setting) {
            $setting->update(['value' => json_encode($request->mapping, JSON_UNESCAPED_UNICODE)]);
        }
        else {
            Setting::create([
                'key' => 'avito_category_mapping',
                'value' => json_encode($request->mapping, JSON_UNESCAPED_UNICODE),
                'group' => 'avito'
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Обновить сопоставление характеристик с тегами Авито
     */
    public function updatePropertyMapping(Request $request)
    {
        $request->validate([
            'mapping' => 'required|array'
        ]);

        $setting = Setting::where('key', 'avito_property_mapping')->first();
        if ($setting) {
            $setting->update(['value' => json_encode($request->mapping, JSON_UNESCAPED_UNICODE)]);
        }
        else {
            Setting::create([
                'key' => 'avito_property_mapping',
                'value' => json_encode($request->mapping, JSON_UNESCAPED_UNICODE),
                'group' => 'avito'
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Предложить маппинг на основе имен категорий (Smart Suggest)
     */
    public function suggestMapping()
    {
        // В будущем здесь может быть интеграция с API Авито для получения их дерева категорий.
        // Пока реализуем базовый поиск по ключевым словам или просто возвращаем структуру для фронтенда.

        $categories = ShopCategory::all();
        $suggestions = [];

        // Пример упрощенной логики: если в названии "Сноуборды", предлагаем путь Авито
        foreach ($categories as $cat) {
            $name = mb_strtolower($cat->name);
            if (str_contains($name, 'сноуборд')) {
                $suggestions[$cat->id] = 'Хобби и отдых > Спорт и отдых > Зимние виды спорта > Сноубординг > Сноуборды';
            }
            elseif (str_contains($name, 'крепления')) {
                $suggestions[$cat->id] = 'Хобби и отдых > Спорт и отдых > Зимние виды спорта > Сноубординг > Крепления';
            }
        // ... и так далее
        }

        return response()->json([
            'success' => true,
            'data' => $suggestions
        ]);
    }


    /**
     * Получить дерево категорий Авито
     */
    public function getTree(AvitoApiService $apiService)
    {
        try {
            $tree = $apiService->getCachedTree();
            return response()->json([
                'success' => true,
                'data' => $tree
            ]);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить дерево категорий из API Авито
     */
    public function refreshTree(AvitoApiService $apiService)
    {
        try {
            $tree = $apiService->fetchCategoryTree();
            return response()->json([
                'success' => true,
                'data' => $tree
            ]);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить статус постоянного фида Авито
     */
    public function getPermanentFeedStatus(Request $request)
    {
        $permanentFilename = 'avito.xml';
        // Ищем вообще последнюю запись для этого фида (даже если она еще в работе)
        $export = ExportFile::where('filename', $permanentFilename)
            ->latest()
            ->first();

        $publicUrl = url('/api/public/avito/feed/' . $permanentFilename);

        return response()->json([
            'found' => !!$export,
            'status' => $export ? $export->status : 'none',
            'updated_at' => $export ? $export->updated_at : null,
            'file_size' => $export ? $export->file_size : 0,
            'total_rows' => $export ? $export->total_rows : 0,
            'public_url' => $publicUrl,
        ]);
    }

    /**
     * Публичное скачивание фида Авито (без авторизации)
     */
    public function downloadPublicFeed($filename)
    {
        $filePath = 'exports/' . $filename;

        if (!Storage::exists($filePath)) {
            abort(404, 'Фид не найден');
        }

        // Дополнительная проверка, что это именно XML фид
        if (!str_ends_with($filename, '.xml')) {
            abort(403, 'Доступ запрещен для этого типа файлов');
        }

        $content = Storage::get($filePath);

        return response($content, 200)
            ->header('Content-Type', 'application/xml; charset=utf-8')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
