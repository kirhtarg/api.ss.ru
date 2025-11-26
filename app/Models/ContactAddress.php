<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'id_contact',
        'name',
        'address',
        'address_short',
        'longitude',
        'latitude',
        'howtogo',
        'work_mode',
        'is_main',
        'is_delivery',
    ];

    protected $casts = [
        'longitude' => 'decimal:7',
        'latitude' => 'decimal:7',
        'is_main' => 'boolean',
        'is_delivery' => 'boolean',
    ];

    /**
     * Получить контакт, которому принадлежит адрес
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'id_contact');
    }
}
