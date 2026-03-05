<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopGoodsActionLog extends Model
{
    protected $table = 'shop_goods_action_logs';

    protected $fillable = [
        'good_id',
        'variation_id',
        'action',
        'action_type',
        'comment',
    ];

    public function good()
    {
        return $this->belongsTo(ShopGood::class, 'good_id');
    }

    public function variation()
    {
        return $this->belongsTo(ShopGoodVariation::class, 'variation_id');
    }
}
