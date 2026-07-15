<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileGameScore extends Model
{
    protected $fillable = ['game_id', 'season_id', 'user_id', 'best_score', 'ranking_score', 'top_scores', 'attempts_count', 'total_rewards', 'last_played_at'];

    protected $casts = ['top_scores' => 'array', 'last_played_at' => 'datetime'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(MobileGame::class, 'game_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
