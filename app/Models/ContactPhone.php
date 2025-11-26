<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactPhone extends Model
{
    use HasFactory;

    protected $fillable = [
        'id_contact',
        'phone_name',
        'phone_number',
        'is_main',
    ];

    protected $casts = [
        'is_main' => 'boolean',
    ];

    /**
     * Получить контакт, которому принадлежит телефон
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'id_contact');
    }
}
