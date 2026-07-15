<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopOzonCategoryMapping extends Model
{
    protected $fillable = ['account_id', 'category_id', 'description_category_id', 'type_id', 'ozon_category_name', 'attribute_mappings', 'is_active'];
    protected $casts = ['attribute_mappings' => 'array', 'is_active' => 'boolean'];

    public function account(): BelongsTo { return $this->belongsTo(ShopOzonAccount::class, 'account_id'); }
    public function category(): BelongsTo { return $this->belongsTo(ShopCategory::class, 'category_id'); }
}
