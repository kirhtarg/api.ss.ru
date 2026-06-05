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
use App\Models\ShopSupplier;
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

        // Получаем обертки описания
        $descBefore = Setting::where('key', 'avito_description_before')->value('value') ?: '';
        $descAfter = Setting::where('key', 'avito_description_after')->value('value') ?: '';
        
        return response()->json([
            'success' => true,
            'data' => [
                'settings' => $settings,
                'categories' => $categories,
                'shop_properties' => $shopProperties,
                'history' => $history,
                'tags' => $tags,
                'description_before' => $descBefore,
                'description_after' => $descAfter,
            ]
        ]);
    }

    /**
     * Получить всех поставщиков для маппинга доставки
     */
    public function getSuppliers()
    {
        $suppliers = ShopSupplier::orderBy('name')->get();
        return response()->json([
            'success' => true,
            'data' => $suppliers
        ]);
    }

    /**
     * Обновить способы доставки для поставщика
     */
    public function updateSupplierDelivery(Request $request, $id)
    {
        $validated = $request->validate([
            'delivery_options' => 'nullable|string'
        ]);

        $supplier = ShopSupplier::findOrFail($id);
        $supplier->update([
            'avito_delivery_options' => $this->normalizeAvitoDeliveryOptions($validated['delivery_options'] ?? null)
        ]);

        return response()->json(['success' => true]);
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
            'avito_description_before' => 'nullable|string',
            'avito_description_after' => 'nullable|string',
            'avito_default_delivery' => 'nullable|string',
            'avito_brand_whitelist' => 'nullable|string',
            'avito_model_whitelist' => 'nullable|string',
            'avito_brand_external_url' => 'nullable|string',
            'avito_model_external_url' => 'nullable|string',
        ]);

        foreach (['avito_description_before', 'avito_description_after'] as $descriptionKey) {
            if (array_key_exists($descriptionKey, $settings)) {
                $settings[$descriptionKey] = $this->sanitizeDescriptionWrapperHtml($settings[$descriptionKey] ?? '');
            }
        }

        if (array_key_exists('avito_default_delivery', $settings)) {
            $settings['avito_default_delivery'] = $this->normalizeAvitoDeliveryOptions($settings['avito_default_delivery'] ?? null);
        }

        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'group' => 'avito', 'type' => 'string']
            );
        }

        return response()->json(['success' => true]);
    }

    private function normalizeAvitoDeliveryOptions(?string $options): ?string
    {
        if ($options === null) {
            return null;
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $options)), fn ($item) => $item !== ''));

        foreach ($items as $item) {
            if (mb_strtolower($item) === 'выключена') {
                return 'Выключена';
            }
        }

        $items = array_values(array_unique($items));

        return empty($items) ? null : implode(', ', $items);
    }

    private function sanitizeDescriptionWrapperHtml(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $html = htmlspecialchars_decode($html, ENT_QUOTES | ENT_HTML5);

        // Убираем служебные inline-стили редактора/браузера, включая CSS-переменные Tailwind.
        $html = preg_replace('/\s(?:style|class|id|data-[\w-]+)\s*=\s*"[^"]*"/iu', '', $html);
        $html = preg_replace("/\s(?:style|class|id|data-[\w-]+)\s*=\s*'[^']*'/iu", '', $html);
        $html = preg_replace('/\s(?:style|class|id|data-[\w-]+)\s*=\s*[^\s>]+/iu', '', $html);

        // Span обычно появляется только как техническая обертка форматирования.
        $html = preg_replace('/<span\b[^>]*>/iu', '', $html);
        $html = preg_replace('/<\/span>/iu', '', $html);

        // Сохраняем только теги, которые допустимы для описания и нормально обрабатываются фидом.
        $html = strip_tags($html, '<br><b><strong><i><em><ul><ol><li><div><p>');

        $html = preg_replace('/<p\b[^>]*>/iu', '<div>', $html);
        $html = preg_replace('/<\/p>/iu', '</div>', $html);
        $html = preg_replace('/<div>\s*(?:<br\s*\/?>)?\s*<\/div>/iu', '<br>', $html);
        $html = preg_replace('/(?:<br\s*\/?>\s*){3,}/iu', '<br><br>', $html);
        $html = preg_replace('/^(?:\s|<br\s*\/?>)+/iu', '', $html);
        $html = preg_replace('/(?:\s|<br\s*\/?>)+$/iu', '', $html);

        return trim($html);
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
        $stocksUrl = url('/api/public/avito/feed/avito_stocks.xml');

        return response()->json([
            'found' => !!$export,
            'status' => $export ? $export->status : 'none',
            'updated_at' => $export ? $export->updated_at : null,
            'file_size' => $export ? $export->file_size : 0,
            'total_rows' => $export ? $export->total_rows : 0,
            'public_url' => $publicUrl,
            'stocks_url' => $stocksUrl,
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
