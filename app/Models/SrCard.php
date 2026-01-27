<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SrCard extends Model
{
    protected $table = 'sr_cards';

    protected $fillable = [
        'name',
        'description',
        'image'
    ];

    /**
     * Получить категории карты (many-to-many)
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            SrCategory::class,
            'sr_card_category',
            'sr_card_id',
            'sr_category_id'
        )->withTimestamps();
    }
}
