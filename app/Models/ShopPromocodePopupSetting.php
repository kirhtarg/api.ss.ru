<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ShopPromocodePopupSetting extends Model
{
    protected $table = 'shop_promocode_popup_settings';
    protected $fillable = ['title','text','delay_seconds','promocode_id','promocode_mode','promocode_code','discount_percent','rotation_enabled','rotation_minutes','is_active'];
    protected $casts = ['delay_seconds'=>'integer','promocode_id'=>'integer','discount_percent'=>'integer','rotation_enabled'=>'boolean','rotation_minutes'=>'integer','is_active'=>'boolean'];
    public function promocode() { return $this->belongsTo(Promocode::class); }
}
