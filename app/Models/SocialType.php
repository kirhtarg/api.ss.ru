<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialType extends Model
{
    use HasFactory;

    protected $fillable = [
        'social',
        'icon',
    ];

    /**
     * Получить социальные сети этого типа
     */
    public function contactSocials(): HasMany
    {
        return $this->hasMany(ContactSocial::class, 'social_type');
    }
}
