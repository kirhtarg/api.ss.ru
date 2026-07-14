<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopCsvApiSource extends Model
{
    protected $fillable = [
        'name',
        'username',
        'password',
        'url',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'encrypted',
    ];
}
