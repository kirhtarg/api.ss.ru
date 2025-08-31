<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'name',
        'value',
        'type',
        'group',
        'description',
        'image_width',
        'image_height'
    ];

    protected $casts = [
        'image_width' => 'integer',
        'image_height' => 'integer'
    ];

    // Мутатор для правильной обработки типов данных
    public function setValueAttribute($value)
    {
        if ($this->type === 'number') {
            $this->attributes['value'] = is_numeric($value) ? $value : null;
        } elseif ($this->type === 'boolean') {
            $this->attributes['value'] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        } else {
            $this->attributes['value'] = $value;
        }
    }

    // Аксессор для правильного отображения типов данных
    public function getValueAttribute($value)
    {
        if ($this->type === 'number') {
            return is_numeric($value) ? (float) $value : null;
        } elseif ($this->type === 'boolean') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        return $value;
    }

    // Scope для активных настроек
    public function scopeActive($query)
    {
        return $query;
    }

    // Scope для группировки
    public function scopeByGroup($query, $group)
    {
        return $query->where('group', $group);
    }
}
