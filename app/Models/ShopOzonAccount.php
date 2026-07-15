<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopOzonAccount extends Model
{
    protected $fillable = ['name', 'client_id', 'api_key', 'api_url', 'warehouse_id', 'image_base_url', 'vat', 'is_active', 'last_connection_at', 'last_error'];
    protected $hidden = ['api_key'];
    protected $casts = ['api_key' => 'encrypted', 'is_active' => 'boolean', 'last_connection_at' => 'datetime'];

    public function categoryMappings(): HasMany
    {
        return $this->hasMany(ShopOzonCategoryMapping::class, 'account_id');
    }
}
