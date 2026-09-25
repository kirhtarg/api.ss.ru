<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopDescriptionDictionary extends Model
{
    protected $table = 'shop_description_dictionary';

    protected $fillable = ['name', 'aliases', 'category_id', 'priority', 'is_active'];

    protected $casts = [
        'aliases' => 'array',
        'category_id' => 'integer',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ShopCategory::class, 'category_id');
    }
}
