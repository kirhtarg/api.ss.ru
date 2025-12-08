<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopYMLFeedAutoParsing extends Model
{
    use HasFactory;

    protected $table = 'shop_yml_feed_auto_parsing';

    protected $fillable = [
        'name',
        'description',
        'yml_feed_url',
        'field_mapping',
        'parse_options',
        'settings'
    ];

    protected $casts = [
        'field_mapping' => 'array',
        'parse_options' => 'array',
        'settings' => 'array'
    ];
}
