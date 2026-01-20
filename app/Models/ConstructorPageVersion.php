<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConstructorPageVersion extends Model
{
    use HasFactory;

    protected $table = 'constructor_page_versions';

    public $timestamps = false;

    protected $fillable = [
        'page_id',
        'version_number',
        'title',
        'slug',
        'meta_title',
        'meta_description',
        'css_class',
        'structure',
        'is_published',
        'published_at',
        'created_by'
    ];

    protected $casts = [
        'structure' => 'array',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'created_at' => 'datetime'
    ];

    /**
     * Страница
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(ConstructorPage::class, 'page_id');
    }

    /**
     * Пользователь, создавший версию
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Восстановить страницу из версии
     */
    public function restore()
    {
        return $this->page->update([
            'title' => $this->title,
            'slug' => $this->slug,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'css_class' => $this->css_class,
            'structure' => $this->structure,
            'is_published' => $this->is_published,
            'published_at' => $this->published_at
        ]);
    }
}