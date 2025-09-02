<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ShopProperty extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'is_filterable',
        'sort_order'
    ];

    protected $casts = [
        'is_filterable' => 'boolean',
        'sort_order' => 'integer'
    ];

    protected static function boot()
    {
        parent::boot();
        // Убираем автоматическое создание slug, так как поле не существует в таблице
    }

    /**
     * Товары с этим свойством
     */
    public function goods(): BelongsToMany
    {
        return $this->belongsToMany(ShopGood::class, 'shop_good_properties')
            ->withPivot('value')
            ->withTimestamps();
    }

    /**
     * Scope для фильтруемых свойств
     */
    public function scopeFilterable($query)
    {
        return $query->where('is_filterable', true);
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
