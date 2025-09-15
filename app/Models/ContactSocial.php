<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactSocial extends Model
{
    use HasFactory;

    protected $fillable = [
        'id_contact',
        'social_type',
        'social_name',
        'social_url',
    ];

    /**
     * Получить контакт, которому принадлежит социальная сеть
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'id_contact');
    }

    /**
     * Получить тип социальной сети
     */
    public function socialType(): BelongsTo
    {
        return $this->belongsTo(SocialType::class, 'social_type');
    }
}
