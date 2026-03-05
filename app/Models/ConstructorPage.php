<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConstructorPage extends Model
{
    use HasFactory;

    protected $table = 'constructor_pages';

    protected $fillable = [
        'title',
        'slug',
        'meta_title',
        'meta_description',
        'css_class',
        'structure',
        'settings',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'structure' => 'array',
        'settings' => 'array',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    /**
     * Accessor для structure - гарантирует возврат массива
     */
    public function getStructureAttribute($value)
    {
        if (is_string($value)) {
            return json_decode($value, true) ?: [];
        }

        return $value ?: [];
    }

    /**
     * Accessor для settings - гарантирует возврат массива
     */
    public function getSettingsAttribute($value)
    {
        if (is_string($value)) {
            return json_decode($value, true) ?: [];
        }

        return $value ?: [];
    }

    /**
     * Версии страницы
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ConstructorPageVersion::class, 'page_id')->orderBy('version_number', 'desc');
    }

    /**
     * Scope для опубликованных страниц
     */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /**
     * Найти страницу по slug
     */
    public static function findBySlug($slug)
    {
        return static::where('slug', $slug)->first();
    }

    /**
     * Создать версию страницы перед сохранением
     */
    protected static function booted()
    {
        static::updating(function ($page) {
            // Создаем версию только если структура или основные поля изменились
            $changedAttributes = array_intersect_key(
                $page->getDirty(),
                array_flip(['title', 'slug', 'meta_title', 'meta_description', 'css_class', 'structure'])
            );

            if (! empty($changedAttributes)) {
                ConstructorPageVersion::create([
                    'page_id' => $page->id,
                    'version_number' => ConstructorPageVersion::where('page_id', $page->id)->max('version_number') + 1 ?? 1,
                    'title' => $page->title,
                    'slug' => $page->slug,
                    'meta_title' => $page->meta_title,
                    'meta_description' => $page->meta_description,
                    'css_class' => $page->css_class,
                    'structure' => $page->structure,
                    'is_published' => $page->is_published,
                    'published_at' => $page->published_at,
                    'created_by' => auth()->id(),
                ]);
            }
        });
    }

    /**
     * Опубликовать страницу
     */
    public function publish()
    {
        $this->update([
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    /**
     * Снять с публикации
     */
    public function unpublish()
    {
        $this->update([
            'is_published' => false,
            'published_at' => null,
        ]);
    }

    /**
     * Проверить уникальность slug
     */
    public static function isSlugUnique($slug, $excludeId = null)
    {
        $query = static::where('slug', $slug);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->doesntExist();
    }
}
