<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SrCategory extends Model
{
    protected $table = 'sr_categories';

    protected $fillable = [
        'name',
        'description',
        'icon',
        'image'
    ];

    /**
     * Получить карты этой категории (many-to-many)
     */
    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(
            SrCard::class,
            'sr_card_category',
            'sr_category_id',
            'sr_card_id'
        )->withTimestamps();
    }

    /**
     * Получить количество карт в категории (accessor)
     */
    public function getCardsCountAttribute(): int
    {
        // Если уже загружено через withCount, используем это значение
        if (isset($this->attributes['cards_count'])) {
            return (int) $this->attributes['cards_count'];
        }
        return $this->cards()->count();
    }
}
