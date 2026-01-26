<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConstructorPage;
use App\Models\ConstructorPageVersion;
use App\Models\ConstructorBlockSetting;
use App\Models\Slider;
use App\Models\ShopGood;
use App\Models\ShopCategory;
use App\Models\ShopBrand;
use App\Models\ShopBonusSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PageBuilderController extends Controller
{
    /**
     * Получить список всех страниц
     */
    public function index()
    {
        try {
            $pages = ConstructorPage::orderBy('updated_at', 'desc')
                ->get()
                ->map(function ($page) {
                    try {
                        $versionsCount = ConstructorPageVersion::where('page_id', $page->id)->count();
                    } catch (\Exception $e) {
                        \Log::error('Error counting versions for page ' . $page->id, [
                            'error' => $e->getMessage(),
                            'page_id' => $page->id
                        ]);
                        $versionsCount = 0;
                    }

                    return [
                        'id' => $page->id,
                        'title' => $page->title,
                        'slug' => $page->slug,
                        'is_published' => $page->is_published,
                        'published_at' => $page->published_at,
                        'updated_at' => $page->updated_at,
                        'blocks_count' => $this->countBlocks($page->structure),
                        'versions_count' => $versionsCount
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $pages
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения страниц: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить страницу по ID
     */
    public function show($id)
    {
        try {
            $page = ConstructorPage::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $page
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Страница не найдена'
            ], 404);
        }
    }

    /**
     * Создать новую страницу
     */
    public function store(Request $request)
    {
        \Log::info('PageBuilder store START', [
            'method' => $request->method(),
            'path' => $request->path(),
            'all_data' => $request->all(),
            'raw_content' => $request->getContent(),
            'headers' => $request->headers->all(),
            'user_id' => auth()->id(),
            'user' => auth()->user() ? auth()->user()->toArray() : null,
            'auth_header' => $request->header('Authorization')
        ]);

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:constructor_pages,slug',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'css_class' => 'nullable|string|max:255',
            'structure' => 'nullable|array' // Temporarily make structure optional for testing
        ]);

        if ($validator->fails()) {
            \Log::info('PageBuilder validation failed', [
                'request_all' => $request->all(),
                'request_has_structure' => $request->has('structure'),
                'structure_value' => $request->input('structure'),
                'errors' => $validator->errors()->toArray()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $page = ConstructorPage::create($request->all());

            return response()->json([
                'success' => true,
                'data' => $page,
                'message' => 'Страница создана'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания страницы: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить страницу
     */
    public function update(Request $request, $id)
    {
        $page = ConstructorPage::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:constructor_pages,slug,' . $id,
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'css_class' => 'nullable|string|max:255',
            'structure' => 'required|array',
            'settings' => 'nullable|array',
            'is_published' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->all();
            
            // Обрабатываем settings - удаляем background_color если он null
            if (isset($data['settings']) && is_array($data['settings'])) {
                if (isset($data['settings']['background_color']) && 
                    ($data['settings']['background_color'] === null || 
                     $data['settings']['background_color'] === '')) {
                    unset($data['settings']['background_color']);
                }
            }
            
            $page->update($data);

            return response()->json([
                'success' => true,
                'data' => $page,
                'message' => 'Страница обновлена'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления страницы: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Удалить страницу
     */
    public function destroy($id)
    {
        try {
            $page = ConstructorPage::findOrFail($id);

            // Не позволяем удалять опубликованные страницы
            if ($page->is_published) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя удалить опубликованную страницу'
                ], 422);
            }

            $page->delete();

            return response()->json([
                'success' => true,
                'message' => 'Страница удалена'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления страницы: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Опубликовать страницу
     */
    public function publish($id)
    {
        try {
            $page = ConstructorPage::findOrFail($id);

            if (empty($page->slug)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя опубликовать страницу без URL'
                ], 422);
            }

            $page->publish();
            $page->refresh();

            return response()->json([
                'success' => true,
                'data' => $page,
                'message' => 'Страница опубликована'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка публикации страницы: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Снять страницу с публикации
     */
    public function unpublish($id)
    {
        try {
            $page = ConstructorPage::findOrFail($id);

            $page->unpublish();
            $page->refresh();

            return response()->json([
                'success' => true,
                'data' => $page,
                'message' => 'Страница снята с публикации'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка снятия с публикации: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Дублировать страницу
     */
    public function duplicate($id)
    {
        try {
            $originalPage = ConstructorPage::findOrFail($id);

            $duplicatedPage = ConstructorPage::create([
                'title' => $originalPage->title . ' (Копия)',
                'slug' => $this->generateUniqueSlug($originalPage->slug . '-copy'),
                'meta_title' => $originalPage->meta_title,
                'meta_description' => $originalPage->meta_description,
                'css_class' => $originalPage->css_class,
                'structure' => $originalPage->structure,
                'is_published' => false
            ]);

            return response()->json([
                'success' => true,
                'data' => $duplicatedPage,
                'message' => 'Страница дублирована'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка дублирования страницы: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Проверить уникальность slug
     */
    public function checkSlug(Request $request)
    {
        \Log::info('PageBuilder checkSlug START', [
            'method' => $request->method(),
            'path' => $request->path(),
            'query' => $request->query(),
            'headers' => $request->headers->all(),
            'auth_header' => $request->header('Authorization'),
            'user_id' => auth()->id(),
            'user' => auth()->user() ? auth()->user()->toArray() : null
        ]);

        $slug = $request->query('slug');
        $excludeId = $request->query('exclude_id');

        \Log::info('PageBuilder checkSlug called', [
            'slug' => $slug,
            'exclude_id' => $excludeId,
            'user_id' => auth()->id(),
            'user_roles' => auth()->user() ? auth()->user()->roles->pluck('name')->toArray() : null
        ]);

        $isUnique = ConstructorPage::isSlugUnique($slug, $excludeId);

        \Log::info('PageBuilder checkSlug result', [
            'slug' => $slug,
            'is_unique' => $isUnique
        ]);

        return response()->json([
            'success' => true,
            'exists' => !$isUnique
        ]);
    }

    /**
     * Получить настройки блоков
     */
    public function getBlocks()
    {
        try {
            $blocks = ConstructorBlockSetting::getGroupedByCategory();

            return response()->json([
                'success' => true,
                'data' => $blocks
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения блоков: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить данные для динамических блоков
     */
    public function getDynamicData(Request $request)
    {
        try {
            $type = $request->query('type');

            $data = [];

            switch ($type) {
                case 'sliders':
                    $data = Slider::with('images')->active()->ordered()->get();
                    break;
                case 'categories':
                    $data = ShopCategory::active()->ordered()->get();
                    break;
                case 'brands':
                    $data = ShopBrand::active()->ordered()->get();
                    break;
                case 'bonus_settings':
                    $data = ShopBonusSettings::active()->get();
                    break;
                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Неизвестный тип данных'
                    ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения данных: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Подсчитать количество блоков в структуре
     */
    private function countBlocks($structure)
    {
        if (!$structure || !is_array($structure)) {
            return 0;
        }

        $count = 0;
        foreach ($structure as $row) {
            if (isset($row['columns']) && is_array($row['columns'])) {
                foreach ($row['columns'] as $column) {
                    if (isset($column['blocks']) && is_array($column['blocks'])) {
                        $count += count($column['blocks']);
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Генерировать уникальный slug
     */
    private function generateUniqueSlug($baseSlug)
    {
        $slug = $baseSlug;
        $counter = 1;

        while (!ConstructorPage::isSlugUnique($slug)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}