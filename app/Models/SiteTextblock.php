<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteTextblock extends Model
{
    protected $table = 'textblocks';

    protected $fillable = [
        'name',
        'text',
        'background_color',
        'text_color',
        'link',
        'link_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Scope для активных блоков
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
