<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileGameSession extends Model
{
    protected $fillable = ['public_id', 'game_id', 'season_id', 'user_id', 'status', 'seed', 'idempotency_key', 'config_snapshot', 'event_summary', 'entry_cost', 'used_free_attempt', 'score', 'duration_ms', 'reward_points', 'is_suspicious', 'review_status', 'validation_note', 'started_at', 'completed_at'];

    protected $casts = ['config_snapshot' => 'array', 'event_summary' => 'array', 'used_free_attempt' => 'boolean', 'is_suspicious' => 'boolean', 'started_at' => 'datetime', 'completed_at' => 'datetime'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(MobileGame::class, 'game_id');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(MobileGameSeason::class, 'season_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
