<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_games', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type', 40);
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('auth_required')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('entry_cost')->default(0);
            $table->unsignedSmallInteger('free_attempts_daily')->default(1);
            $table->unsignedInteger('cooldown_seconds')->default(0);
            $table->unsignedSmallInteger('daily_attempt_limit')->default(10);
            $table->unsignedInteger('daily_reward_limit')->default(500);
            $table->unsignedSmallInteger('reward_expiry_days')->default(90);
            $table->boolean('ranking_enabled')->default(true);
            $table->string('ranking_mode', 20)->default('best');
            $table->unsignedTinyInteger('ranking_best_count')->default(3);
            $table->json('product_source')->nullable();
            $table->json('settings')->nullable();
            $table->json('reward_rules')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('mobile_game_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('mobile_games')->cascadeOnDelete();
            $table->string('name');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(true);
            $table->json('reward_tiers')->nullable();
            $table->timestamp('rewards_issued_at')->nullable();
            $table->timestamps();
            $table->index(['game_id', 'is_active', 'starts_at', 'ends_at'], 'mobile_game_season_active_idx');
        });

        Schema::create('mobile_game_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('game_id')->constrained('mobile_games')->cascadeOnDelete();
            $table->foreignId('season_id')->nullable()->constrained('mobile_game_seasons')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('started');
            $table->string('seed', 80);
            $table->string('idempotency_key', 80);
            $table->json('config_snapshot')->nullable();
            $table->json('event_summary')->nullable();
            $table->unsignedInteger('entry_cost')->default(0);
            $table->boolean('used_free_attempt')->default(false);
            $table->unsignedBigInteger('score')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('reward_points')->default(0);
            $table->boolean('is_suspicious')->default(false);
            $table->string('review_status', 20)->default('automatic');
            $table->string('validation_note')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key'], 'mobile_game_session_idempotency');
            $table->index(['game_id', 'user_id', 'status', 'completed_at'], 'mobile_game_session_rank_idx');
        });

        Schema::create('mobile_game_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('mobile_games')->cascadeOnDelete();
            $table->foreignId('season_id')->nullable()->constrained('mobile_game_seasons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('best_score')->default(0);
            $table->unsignedBigInteger('ranking_score')->default(0);
            $table->json('top_scores')->nullable();
            $table->unsignedInteger('attempts_count')->default(0);
            $table->unsignedInteger('total_rewards')->default(0);
            $table->timestamp('last_played_at')->nullable();
            $table->timestamps();
            $table->unique(['game_id', 'season_id', 'user_id'], 'mobile_game_score_unique');
            $table->index(['game_id', 'season_id', 'ranking_score'], 'mobile_game_leaderboard_idx');
        });

        $games = [
            ['Собери пару', 'memory', 'memory', 'Открывайте карточки с товарами и находите пары.', 1, 90, 50000, [1000 => 10, 2500 => 25, 5000 => 50]],
            ['Gear Blast', 'gear-blast', 'match3', 'Яркие комбинации товаров, каскады и усилители.', 2, 75, 100000, [2500 => 10, 6000 => 30, 12000 => 70]],
            ['Башня экипировки', 'gear-tower', 'tower', 'Постройте устойчивую башню из товаров магазина.', 3, 75, 50000, [800 => 10, 2000 => 30, 4000 => 65]],
            ['Хватай бонус', 'bonus-claw', 'claw', 'Управляйте захватом и собирайте товары на время.', 4, 60, 30000, [600 => 10, 1500 => 25, 3000 => 60]],
            ['Skate&Snow Pinball', 'gear-pinball', 'pinball', 'Динамичный пинбол с целями, комбо и товарами.', 5, 90, 250000, [10000 => 10, 30000 => 35, 70000 => 80]],
            ['Товарный шторм', 'product-storm', 'swipe', 'Свайпайте товары по правильным категориям.', 6, 60, 50000, [1500 => 10, 4000 => 30, 8000 => 70]],
            ['Trick Combo', 'trick-combo', 'rhythm', 'Повторяйте световые и свайп-комбинации без ошибок.', 7, 75, 100000, [2000 => 10, 6000 => 35, 12000 => 80]],
            ['Gear Breaker', 'gear-breaker', 'breaker', 'Разбивайте блоки, собирайте усилители и комбо.', 8, 90, 200000, [8000 => 10, 25000 => 35, 60000 => 80]],
        ];

        $now = now();
        foreach ($games as [$name, $slug, $type, $description, $sort, $time, $maxScore, $rewards]) {
            $gameId = DB::table('mobile_games')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'type' => $type,
                'description' => $description,
                'is_active' => true,
                'auth_required' => true,
                'entry_cost' => 10,
                'free_attempts_daily' => 1,
                'cooldown_seconds' => 15,
                'daily_attempt_limit' => 10,
                'daily_reward_limit' => 200,
                'reward_expiry_days' => 90,
                'ranking_enabled' => true,
                'ranking_mode' => 'best',
                'ranking_best_count' => 3,
                'product_source' => json_encode(['type' => 'featured', 'limit' => 18], JSON_UNESCAPED_UNICODE),
                'settings' => json_encode(['duration_seconds' => $time, 'max_score' => $maxScore, 'difficulty' => 'normal'], JSON_UNESCAPED_UNICODE),
                'reward_rules' => json_encode(collect($rewards)->map(fn ($points, $score) => ['min_score' => (int) $score, 'points' => $points])->values(), JSON_UNESCAPED_UNICODE),
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('mobile_game_seasons')->insert([
                'game_id' => $gameId,
                'name' => 'Стартовый сезон',
                'starts_at' => $now->copy()->startOfDay(),
                'ends_at' => $now->copy()->addDays(28)->endOfDay(),
                'is_active' => true,
                'reward_tiers' => json_encode([
                    ['place_from' => 1, 'place_to' => 1, 'points' => 1000],
                    ['place_from' => 2, 'place_to' => 3, 'points' => 500],
                    ['place_from' => 4, 'place_to' => 10, 'points' => 200],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_game_scores');
        Schema::dropIfExists('mobile_game_sessions');
        Schema::dropIfExists('mobile_game_seasons');
        Schema::dropIfExists('mobile_games');
    }
};
