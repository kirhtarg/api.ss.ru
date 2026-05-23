<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ShopTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'color',
        'slug',
        'is_active',
        'sort_order',
        'disables_bonuses',
        'disables_registered_discount',
        'extra_discount_percent',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'disables_bonuses' => 'boolean',
        'disables_registered_discount' => 'boolean',
        'extra_discount_percent' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tag) {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });

        static::updating(function ($tag) {
            if ($tag->isDirty('name') && empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    /**
     * Товары с этим тегом
     */
    public function goods(): BelongsToMany
    {
        return $this->belongsToMany(ShopGood::class, 'shop_good_tags');
    }

    /**
     * Scope для активных тегов
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
