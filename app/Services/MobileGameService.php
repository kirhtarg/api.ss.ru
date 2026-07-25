<?php

namespace App\Services;

use App\Models\MobileGame;
use App\Models\MobileGameScore;
use App\Models\MobileGameSession;
use App\Models\Promocode;
use App\Models\ShopGood;
use App\Models\User;
use App\Models\UserBonus;
use App\Models\UserBonusTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileGameService
{
    public function start(MobileGame $game, User $user, string $idempotencyKey): MobileGameSession
    {
        return DB::transaction(function () use ($game, $user, $idempotencyKey) {
            $existing = MobileGameSession::where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }

            $today = now()->startOfDay();
            $attempts = MobileGameSession::where('game_id', $game->id)->where('user_id', $user->id)->where('started_at', '>=', $today)->count();
            if ($game->daily_attempt_limit > 0 && $attempts >= $game->daily_attempt_limit) {
                throw ValidationException::withMessages(['game' => 'Лимит попыток на сегодня исчерпан']);
            }

            $last = MobileGameSession::where('game_id', $game->id)->where('user_id', $user->id)->latest('started_at')->lockForUpdate()->first();
            if ($last && $game->cooldown_seconds > 0 && $last->started_at->addSeconds($game->cooldown_seconds)->isFuture()) {
                throw ValidationException::withMessages(['game' => 'Следующая попытка будет доступна через несколько секунд']);
            }

            $freeUsed = MobileGameSession::where('game_id', $game->id)->where('user_id', $user->id)->where('used_free_attempt', true)->where('started_at', '>=', $today)->count();
            $useFree = $freeUsed < $game->free_attempts_daily;
            $entryCost = $useFree ? 0 : (int) $game->entry_cost;

            UserBonus::firstOrCreate(['user_id' => $user->id], ['points' => 0, 'total_earned' => 0, 'total_spent' => 0]);
            $bonus = UserBonus::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($entryCost > 0) {
                if ($bonus->points < $entryCost) {
                    throw ValidationException::withMessages(['bonuses' => 'Недостаточно бонусов для новой попытки']);
                }
                $bonus->decrement('points', $entryCost);
                $bonus->increment('total_spent', $entryCost);
                UserBonusTransaction::create([
                    'user_id' => $user->id, 'type' => 'spend', 'points' => -$entryCost,
                    'description' => 'Игровая попытка: '.$game->name,
                    'metadata' => ['source' => 'mobile_game', 'game_id' => $game->id, 'idempotency_key' => $idempotencyKey],
                ]);
            }

            $settings = $game->settings ?: [];
            $dailyLayout = $game->type === 'memory' && ($settings['daily_layout'] ?? true);
            $seed = $dailyLayout
                ? hash('sha256', implode('|', [$game->id, now()->toDateString(), $game->activeSeason()?->id ?: 0]))
                : bin2hex(random_bytes(20));

            $session = MobileGameSession::create([
                'public_id' => (string) Str::uuid(), 'game_id' => $game->id, 'season_id' => $game->activeSeason()?->id,
                'user_id' => $user->id, 'status' => 'started', 'seed' => $seed,
                'idempotency_key' => $idempotencyKey, 'config_snapshot' => $settings,
                'entry_cost' => $entryCost, 'used_free_attempt' => $useFree, 'started_at' => now(),
            ]);

            Log::info('Mobile game session started', ['session' => $session->public_id, 'game' => $game->slug, 'user_id' => $user->id, 'entry_cost' => $entryCost]);

            return $session;
        }, 3);
    }

    public function finish(MobileGameSession $session, User $user, int $score, int $durationMs, array $events = []): MobileGameSession
    {
        return DB::transaction(function () use ($session, $user, $score, $durationMs, $events) {
            $session = MobileGameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->user_id !== $user->id) {
                abort(403);
            }
            if ($session->status === 'completed') {
                return $session;
            }
            if ($session->status !== 'started') {
                throw ValidationException::withMessages(['session' => 'Игровая сессия уже закрыта']);
            }

            $settings = $session->config_snapshot ?: [];
            $maxScore = max(1, (int) ($settings['max_score'] ?? 100000));
            $maxDuration = max(15, (int) ($settings['duration_seconds'] ?? 90)) * 1000 + 20000;
            $elapsed = $session->started_at->diffInMilliseconds(now());
            $rateLimits = ['memory' => 1200, 'match3' => 5000, 'tower' => 1200, 'claw' => 1200, 'pinball' => 2500, 'swipe' => 1800, 'rhythm' => 1200, 'breaker' => 5000];
            $scoreRateLimit = ($rateLimits[$session->game->type] ?? 2500) * max(1, $durationMs / 1000) + 2000;
            $suspicious = $score < 0 || $score > $maxScore || $score > $scoreRateLimit || $durationMs < 700 || $durationMs > $maxDuration || $durationMs > $elapsed + 3000;
            $note = $suspicious ? 'Результат вышел за серверные ограничения' : null;
            $reward = $suspicious ? 0 : $this->rewardForScore($session->game, $score);

            $awardedToday = MobileGameSession::where('game_id', $session->game_id)->where('user_id', $user->id)->where('completed_at', '>=', now()->startOfDay())->sum('reward_points');
            $reward = min($reward, max(0, (int) $session->game->daily_reward_limit - (int) $awardedToday));

            $summary = array_slice($events, 0, 99);
            $session->update([
                'status' => 'completed', 'score' => max(0, $score), 'duration_ms' => $durationMs,
                'event_summary' => $summary, 'reward_points' => $reward,
                'is_suspicious' => $suspicious, 'review_status' => $suspicious ? 'pending' : 'automatic',
                'validation_note' => $note, 'completed_at' => now(),
            ]);

            if (! $suspicious) {
                $this->updateScore($session);
            }
            if ($reward > 0) {
                $this->award($session, $user, $reward);
            }
            if (! $suspicious && ($promocode = $this->issuePromocode($session, $user))) {
                $summary[] = ['event' => 'promocode_issued', 'promocode' => $this->promocodeData($promocode)];
                $session->update(['event_summary' => $summary]);
            }

            Log::info('Mobile game session completed', ['session' => $session->public_id, 'score' => $score, 'reward' => $reward, 'suspicious' => $suspicious]);

            return $session->fresh(['game']);
        }, 3);
    }

    public function products(MobileGame $game, ?string $seed = null): array
    {
        $source = $game->product_source ?: [];
        $minimum = $game->type === 'memory' ? 10 : 6;
        $limit = min(60, max($minimum, (int) ($source['limit'] ?? 30)));
        $relations = ['images' => fn ($q) => $q->ordered(), 'allImages' => fn ($q) => $q->ordered()];
        $query = ShopGood::query()
            ->where('is_active', true)
            ->whereHas('allImages')
            ->with($relations);
        $useFullCatalog = $game->type === 'memory' && ($game->settings['use_full_catalog'] ?? true);
        if (! $useFullCatalog && ($source['type'] ?? '') === 'category' && ! empty($source['ids'])) {
            $query->whereHas('categories', fn ($q) => $q->whereIn('shop_categories.id', $source['ids']));
        }
        if (! $useFullCatalog && ($source['type'] ?? '') === 'brand' && ! empty($source['ids'])) {
            $query->whereHas('brands', fn ($q) => $q->whereIn('shop_brands.id', $source['ids']));
        }
        if (! $useFullCatalog && ($source['type'] ?? '') === 'manual' && ! empty($source['ids'])) {
            $query->whereIn('id', $source['ids']);
        }
        if (($source['type'] ?? '') === 'featured' && ! $useFullCatalog) {
            $query->where('is_featured', true);
        }

        if ($seed) {
            $count = (clone $query)->count('shop_goods.id');
            $offset = $count > $limit ? hexdec(substr(hash('sha256', $seed.'|offset'), 0, 8)) % ($count - $limit + 1) : 0;
            $goods = $query->orderBy('shop_goods.id')->offset($offset)->limit($limit)->get();
        } else {
            $goods = $query->inRandomOrder()->limit($limit)->get();
        }
        if ($goods->count() < $minimum) {
            $additionalQuery = ShopGood::query()
                ->where('is_active', true)
                ->whereHas('allImages')
                ->whereNotIn('id', $goods->pluck('id'))
                ->with($relations);
            $additional = $seed
                ? $additionalQuery->orderBy('shop_goods.id')->limit(100)->get()->sortBy(fn ($good) => hash('sha256', $seed.'|fallback|'.$good->id))->take($limit - $goods->count())
                : $additionalQuery->inRandomOrder()->limit($limit - $goods->count())->get();
            $goods = $goods->concat($additional);
        }

        return $goods->map(fn ($good) => ['id' => $good->id, 'name' => $good->name, 'slug' => $good->slug, 'image_url' => ($good->images->first() || $good->allImages->first()) ? route('mobile-games.product-asset', ['good' => $good->id]) : null])->values()->all();
    }

    public function sessionPromocode(MobileGameSession $session): ?array
    {
        $event = collect($session->event_summary ?: [])->first(fn ($item) => ($item['event'] ?? null) === 'promocode_issued');

        return is_array($event['promocode'] ?? null) ? $event['promocode'] : null;
    }

    private function issuePromocode(MobileGameSession $session, User $user): ?Promocode
    {
        $settings = $session->config_snapshot ?: [];
        if (! ($settings['promo_enabled'] ?? false) || $session->score < (int) ($settings['promo_min_score'] ?? 5000)) {
            return null;
        }
        $chance = min(100, max(0, (int) ($settings['promo_chance_percent'] ?? 10)));
        if ($chance < 1 || random_int(1, 100) > $chance) {
            return null;
        }
        $name = 'Игровой приз: '.$session->game->name;
        $dailyLimit = max(1, (int) ($settings['promo_daily_limit'] ?? 1));
        if (Promocode::where('user_id', $user->id)->where('name', $name)->where('created_at', '>=', now()->startOfDay())->count() >= $dailyLimit) {
            return null;
        }
        do {
            $code = 'GAME-'.$user->id.'-'.Str::upper(Str::random(8));
        } while (Promocode::where('code', $code)->exists());

        return Promocode::create([
            'code' => $code,
            'name' => $name,
            'description' => 'Персональная награда за результат в мобильной игре',
            'type' => ($settings['promo_type'] ?? 'percentage') === 'fixed_amount' ? 'fixed_amount' : 'percentage',
            'value' => max(1, (float) ($settings['promo_value'] ?? 5)),
            'min_order_amount' => max(0, (float) ($settings['promo_min_order_amount'] ?? 0)) ?: null,
            'max_discount_amount' => max(0, (float) ($settings['promo_max_discount_amount'] ?? 0)) ?: null,
            'usage_limit' => 1,
            'usage_limit_per_user' => 1,
            'is_active' => true,
            'starts_at' => now(),
            'expires_at' => now()->addDays(max(1, (int) ($settings['promo_valid_days'] ?? 14))),
            'user_id' => $user->id,
        ]);
    }

    private function promocodeData(Promocode $promocode): array
    {
        return [
            'code' => $promocode->code,
            'type' => $promocode->type,
            'value' => (float) $promocode->value,
            'expires_at' => $promocode->expires_at?->toISOString(),
            'min_order_amount' => $promocode->min_order_amount ? (float) $promocode->min_order_amount : null,
        ];
    }

    private function rewardForScore(MobileGame $game, int $score): int
    {
        return collect($game->reward_rules ?: [])->filter(fn ($rule) => $score >= (int) ($rule['min_score'] ?? PHP_INT_MAX))->max(fn ($rule) => (int) ($rule['points'] ?? 0)) ?: 0;
    }

    private function award(MobileGameSession $session, User $user, int $points): void
    {
        UserBonus::firstOrCreate(['user_id' => $user->id], ['points' => 0, 'total_earned' => 0, 'total_spent' => 0]);
        $bonus = UserBonus::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
        $bonus->increment('points', $points);
        $bonus->increment('total_earned', $points);
        UserBonusTransaction::create([
            'user_id' => $user->id, 'type' => 'earn', 'points' => $points,
            'description' => 'Награда за игру: '.$session->game->name,
            'expires_at' => now()->addDays($session->game->reward_expiry_days),
            'metadata' => ['source' => 'mobile_game', 'game_id' => $session->game_id, 'session_id' => $session->public_id],
        ]);
    }

    private function updateScore(MobileGameSession $session): void
    {
        $score = MobileGameScore::firstOrNew(['game_id' => $session->game_id, 'season_id' => $session->season_id, 'user_id' => $session->user_id]);
        $top = collect(array_merge($score->top_scores ?: [], [(int) $session->score]))->sortDesc()->take(max(1, $session->game->ranking_best_count))->values()->all();
        $score->best_score = max((int) $score->best_score, (int) $session->score);
        $score->top_scores = $top;
        $score->ranking_score = $session->game->ranking_mode === 'best_n' ? array_sum($top) : $score->best_score;
        $score->attempts_count = (int) $score->attempts_count + 1;
        $score->total_rewards = (int) $score->total_rewards + (int) $session->reward_points;
        $score->last_played_at = now();
        $score->save();
    }
}
