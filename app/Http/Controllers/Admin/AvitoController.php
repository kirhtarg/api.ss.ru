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
use Illuminate\Support\Facades\Artisan;
use App\Jobs\ProcessExportJob;
use Illuminate\Support\Facades\Storage;

class AvitoController extends Controller
{
    /**
     * Получить текущие настройки и сопоставление категорий
     */
    public function getSettings()
    {
        $settings = Setting::where('group', 'avito')->get()->pluck('value', 'key');

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

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => $settings,
                'categories' => $categories,
                'history' => $history,
            ]
        ]);
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
            $setting->update(['value' => $request->mapping]);
        }
        else {
            Setting::create([
                'key' => 'avito_category_mapping',
                'value' => $request->mapping,
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
     * Запустить генерацию фида немедленно
     */
    public function generateNow(Request $request)
    {
        $id = $request->input('file_id');
        $exportFile = null;

        if ($id) {
            $exportFile = ExportFile::find($id);
        }

        if (!$exportFile) {
            $exportFile = ExportFile::create([
                'filename' => 'avito_feed_' . date('Y-m-d_H-i-s') . '.xml',
                'format' => 'avito_xml',
                'status' => 'pending',
                'export_config' => [
                    'is_avito' => true,
                    'filename' => 'avito_feed.xml'
                ]
            ]);
        }
        else {
            $exportFile->update(['status' => 'pending']);
        }

        ProcessExportJob::dispatch($exportFile);

        return response()->json([
            'success' => true,
            'message' => 'Генерация фида запущена в фоновом режиме',
            'data' => $exportFile
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
