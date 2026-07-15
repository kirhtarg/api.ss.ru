<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MobileGame;
use App\Models\MobileGameSeason;
use App\Models\MobileGameSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MobileGameController extends Controller
{
    public function index()
    {
        $games = MobileGame::with(['seasons' => fn ($q) => $q->latest('starts_at')])->withCount(['sessions', 'scores'])->orderBy('sort_order')->get();

        return response()->json(['success' => true, 'data' => $games]);
    }

    public function store(Request $request)
    {
        $game = MobileGame::create($this->validated($request));
        $game->seasons()->create(['name' => 'Первый сезон', 'starts_at' => now()->startOfDay(), 'ends_at' => now()->addDays(28)->endOfDay(), 'is_active' => true, 'reward_tiers' => [['place_from' => 1, 'place_to' => 1, 'points' => 1000], ['place_from' => 2, 'place_to' => 3, 'points' => 500], ['place_from' => 4, 'place_to' => 10, 'points' => 200]]]);

        return response()->json(['success' => true, 'data' => $game, 'message' => 'Игра создана'], 201);
    }

    public function update(Request $request, MobileGame $game)
    {
        $game->update($this->validated($request, $game));

        return response()->json(['success' => true, 'data' => $game->fresh('seasons'), 'message' => 'Настройки игры сохранены']);
    }

    public function destroy(MobileGame $game)
    {
        $game->delete();

        return response()->json(['success' => true, 'message' => 'Игра удалена']);
    }

    public function uploadImage(Request $request, MobileGame $game)
    {
        $request->validate(['image' => 'required|image|mimes:jpeg,png,webp,gif|max:10240']);
        if ($game->image_url && str_starts_with($game->image_url, '/storage/mobile-games/')) {
            Storage::disk('public')->delete(Str::after($game->image_url, '/storage/'));
        }
        $path = $request->file('image')->store('mobile-games', 'public');
        $game->update(['image_url' => Storage::url($path)]);

        return response()->json(['success' => true, 'data' => ['image_url' => $game->image_url]]);
    }

    public function storeSeason(Request $request, MobileGame $game)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'is_active' => 'boolean', 'reward_tiers' => 'nullable|array']);
        $season = $game->seasons()->create($data);

        return response()->json(['success' => true, 'data' => $season], 201);
    }

    public function updateSeason(Request $request, MobileGameSeason $season)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'is_active' => 'boolean', 'reward_tiers' => 'nullable|array']);
        $season->update($data);

        return response()->json(['success' => true, 'data' => $season]);
    }

    public function sessions(Request $request)
    {
        $rows = MobileGameSession::with(['game:id,name,slug', 'user:id,name,first_name,last_name,email'])->when($request->boolean('suspicious'), fn ($q) => $q->where('is_suspicious', true))->latest('started_at')->paginate(50);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function review(Request $request, MobileGameSession $session)
    {
        $data = $request->validate(['review_status' => ['required', Rule::in(['approved', 'rejected'])], 'validation_note' => 'nullable|string|max:255']);
        $session->update($data);

        return response()->json(['success' => true, 'data' => $session]);
    }

    public function issueSeasonRewards(MobileGameSeason $season)
    {
        DB::transaction(function () use ($season) {
            $season = MobileGameSeason::whereKey($season->id)->lockForUpdate()->firstOrFail();
            if ($season->rewards_issued_at) {
                throw \Illuminate\Validation\ValidationException::withMessages(['season' => 'Награды этого сезона уже выданы']);
            }
            $scores = \App\Models\MobileGameScore::where('game_id', $season->game_id)->where('season_id', $season->id)->orderByDesc('ranking_score')->get();
            foreach ($scores as $index => $score) {
                $place = $index + 1;
                $tier = collect($season->reward_tiers ?: [])->first(fn ($item) => $place >= (int) ($item['place_from'] ?? 0) && $place <= (int) ($item['place_to'] ?? 0));
                $points = (int) ($tier['points'] ?? 0);
                if ($points < 1) {
                    continue;
                }
                \App\Models\UserBonus::firstOrCreate(['user_id' => $score->user_id], ['points' => 0, 'total_earned' => 0, 'total_spent' => 0]);
                $bonus = \App\Models\UserBonus::where('user_id', $score->user_id)->lockForUpdate()->firstOrFail();
                $bonus->increment('points', $points);
                $bonus->increment('total_earned', $points);
                \App\Models\UserBonusTransaction::create(['user_id' => $score->user_id, 'type' => 'earn', 'points' => $points, 'description' => 'Награда за сезон: '.$season->name, 'metadata' => ['source' => 'mobile_game_season', 'season_id' => $season->id, 'place' => $place]]);
            }
            $season->update(['rewards_issued_at' => now(), 'is_active' => false]);
        }, 3);

        return response()->json(['success' => true, 'message' => 'Сезонные награды выданы']);
    }

    private function validated(Request $request, ?MobileGame $game = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255', 'slug' => ['required', 'string', 'max:255', Rule::unique('mobile_games', 'slug')->ignore($game?->id)],
            'type' => ['required', Rule::in(['memory', 'match3', 'tower', 'claw', 'pinball', 'swipe', 'rhythm', 'breaker'])],
            'description' => 'nullable|string', 'image_url' => 'nullable|string|max:500', 'is_active' => 'boolean', 'auth_required' => 'boolean',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after:starts_at', 'entry_cost' => 'integer|min:0|max:100000',
            'free_attempts_daily' => 'integer|min:0|max:100', 'cooldown_seconds' => 'integer|min:0|max:86400', 'daily_attempt_limit' => 'integer|min:1|max:1000',
            'daily_reward_limit' => 'integer|min:0|max:1000000', 'reward_expiry_days' => 'integer|min:1|max:3650', 'ranking_enabled' => 'boolean',
            'ranking_mode' => ['required', Rule::in(['best', 'best_n'])], 'ranking_best_count' => 'integer|min:1|max:20',
            'product_source' => 'nullable|array', 'settings' => 'nullable|array', 'reward_rules' => 'nullable|array', 'sort_order' => 'integer|min:0',
        ]);
    }
}
