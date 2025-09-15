<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'short_name',
        'is_main',
    ];

    protected $casts = [
        'is_main' => 'boolean',
    ];

    /**
     * Получить телефоны контакта
     */
    public function phones(): HasMany
    {
        return $this->hasMany(ContactPhone::class, 'id_contact');
    }

    /**
     * Получить адреса контакта
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(ContactAddress::class, 'id_contact');
    }

    /**
     * Получить социальные сети контакта
     */
    public function socials(): HasMany
    {
        return $this->hasMany(ContactSocial::class, 'id_contact');
    }

    /**
     * Получить главный адрес контакта
     */
    public function mainAddress()
    {
        return $this->addresses()->where('is_main', true)->first() 
            ?? $this->addresses()->first();
    }

    /**
     * Получить главный телефон контакта
     */
    public function mainPhone()
    {
        return $this->phones()->where('is_main', true)->first() 
            ?? $this->phones()->first();
    }

    /**
     * Получить главный контакт
     */
    public static function getMainContact()
    {
        return self::where('is_main', true)->first() 
            ?? self::first();
    }
}
