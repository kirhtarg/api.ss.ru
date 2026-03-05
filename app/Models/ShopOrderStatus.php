<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopOrderStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'color',
        'is_active',
        'is_finished',
        'is_cancelled',
        'sort_order',
        'description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_finished' => 'boolean',
        'is_cancelled' => 'boolean',
    ];

    public function orders()
    {
        return $this->hasMany(ShopOrder::class, 'status_id');
    }
}
