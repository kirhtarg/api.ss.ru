<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MobileGame extends Model
{
    protected $fillable = ['name', 'slug', 'type', 'description', 'image_url', 'is_active', 'auth_required', 'starts_at', 'ends_at', 'entry_cost', 'free_attempts_daily', 'cooldown_seconds', 'daily_attempt_limit', 'daily_reward_limit', 'reward_expiry_days', 'ranking_enabled', 'ranking_mode', 'ranking_best_count', 'product_source', 'settings', 'reward_rules', 'sort_order'];

    protected $casts = ['is_active' => 'boolean', 'auth_required' => 'boolean', 'ranking_enabled' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'product_source' => 'array', 'settings' => 'array', 'reward_rules' => 'array'];

    public function seasons(): HasMany
    {
        return $this->hasMany(MobileGameSeason::class, 'game_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(MobileGameSession::class, 'game_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(MobileGameScore::class, 'game_id');
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function activeSeason(): ?MobileGameSeason
    {
        return $this->seasons()->where('is_active', true)->where('starts_at', '<=', now())->where('ends_at', '>=', now())->latest('starts_at')->first();
    }
}
