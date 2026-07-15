<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileGameSeason extends Model
{
    protected $fillable = ['game_id', 'name', 'starts_at', 'ends_at', 'is_active', 'reward_tiers', 'rewards_issued_at'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'reward_tiers' => 'array', 'rewards_issued_at' => 'datetime'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(MobileGame::class, 'game_id');
    }
}
