<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ShopPromocodePopupSetting extends Model
{
    protected $table = 'shop_promocode_popup_settings';
    protected $fillable = ['title','text','delay_seconds','promocode_id','is_active'];
    protected $casts = ['delay_seconds'=>'integer','promocode_id'=>'integer','is_active'=>'boolean'];
    public function promocode() { return $this->belongsTo(Promocode::class); }
}
