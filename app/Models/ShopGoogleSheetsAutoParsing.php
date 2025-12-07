<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopGoogleSheetsAutoParsing extends Model
{
    use HasFactory;

    protected $table = 'shop_google_sheets_auto_parsing';

    protected $fillable = [
        'name',
        'description',
        'google_sheet_url',
        'field_mapping',
        'skip_rows',
        'sheet_number',
        'settings'
    ];

    protected $casts = [
        'field_mapping' => 'array',
        'settings' => 'array',
        'skip_rows' => 'integer',
        'sheet_number' => 'integer'
    ];
}
