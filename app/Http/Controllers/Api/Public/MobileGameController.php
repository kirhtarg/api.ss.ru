<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\MobileGame;
use App\Models\MobileGameScore;
use App\Models\MobileGameSession;
use App\Services\MobileGameService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileGameController extends Controller
{
    public function __construct(private readonly MobileGameService $games) {}

    public function index(Request $request)
    {
        $userId = $request->user('sanctum')?->id;
        $items = MobileGame::available()->orderBy('sort_order')->get()->map(fn ($game) => $this->gameData($game, $userId));

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function show(Request $request, string $slug)
    {
        $game = MobileGame::available()->where('slug', $slug)->firstOrFail();

        return response()->json(['success' => true, 'data' => array_merge($this->gameData($game, $request->user('sanctum')?->id), ['products' => $this->games->products($game)])]);
    }

    public function start(Request $request, string $slug)
    {
        $data = $request->validate(['idempotency_key' => 'required|string|max:80']);
        $game = MobileGame::available()->where('slug', $slug)->firstOrFail();
        $session = $this->games->start($game, $request->user(), $data['idempotency_key']);

        return response()->json(['success' => true, 'data' => ['session_id' => $session->public_id, 'seed' => $session->seed, 'settings' => $session->config_snapshot, 'entry_cost' => $session->entry_cost, 'used_free_attempt' => $session->used_free_attempt, 'products' => $this->games->products($game)]]);
    }

    public function finish(Request $request, string $publicId)
    {
        $data = $request->validate(['score' => 'required|integer|min:0', 'duration_ms' => 'required|integer|min:1', 'events' => 'nullable|array|max:100']);
        $session = MobileGameSession::where('public_id', $publicId)->firstOrFail();
        $session = $this->games->finish($session, $request->user(), $data['score'], $data['duration_ms'], $data['events'] ?? []);

        return response()->json(['success' => true, 'data' => ['score' => $session->score, 'reward_points' => $session->reward_points, 'is_suspicious' => $session->is_suspicious, 'message' => $session->is_suspicious ? 'Результат отправлен на проверку' : 'Результат сохранен']]);
    }

    public function leaderboard(Request $request, string $slug)
    {
        $game = MobileGame::where('slug', $slug)->firstOrFail();
        $period = $request->string('period', 'season')->toString();
        $query = $period === 'season'
            ? MobileGameScore::query()->where('game_id', $game->id)->where('season_id', $game->activeSeason()?->id)
            : MobileGameSession::query()->select('user_id', DB::raw('MAX(score) as ranking_score'), DB::raw('MAX(score) as best_score'))->where('game_id', $game->id)->where('status', 'completed')->where('is_suspicious', false)->where('completed_at', '>=', $period === 'daily' ? now()->startOfDay() : now()->startOfWeek())->groupBy('user_id');
        $rows = $query->with('user:id,name,first_name,last_name,avatar_url')->orderByDesc('ranking_score')->limit(100)->get();
        $total = max(1, $rows->count());
        $data = $rows->values()->map(function ($row, $index) use ($total) {
            $percentile = 1 - ($index / $total);
            $league = $percentile >= .99 ? 'Легенда' : ($percentile >= .9 ? 'Эксперт' : ($percentile >= .65 ? 'Профи' : ($percentile >= .35 ? 'Райдер' : 'Новичок')));

            return ['place' => $index + 1, 'score' => (int) $row->ranking_score, 'best_score' => (int) $row->best_score, 'league' => $league, 'user' => ['id' => $row->user->id, 'name' => trim($row->user->first_name.' '.$row->user->last_name) ?: $row->user->name, 'avatar_url' => $row->user->avatar_url]];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function history(Request $request)
    {
        $rows = MobileGameSession::with('game:id,name,slug')->where('user_id', $request->user()->id)->where('status', 'completed')->latest('completed_at')->paginate(30);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function overallLeaderboard()
    {
        $games = MobileGame::available()->where('ranking_enabled', true)->get();
        $points = [];
        foreach ($games as $game) {
            $rows = MobileGameScore::where('game_id', $game->id)->where('season_id', $game->activeSeason()?->id)->orderByDesc('ranking_score')->get(['user_id', 'ranking_score']);
            $count = max(1, $rows->count());
            foreach ($rows as $index => $row) {
                $points[$row->user_id] = ($points[$row->user_id] ?? 0) + (int) round((1 - ($index / $count)) * 1000);
            }
        }
        arsort($points);
        $userIds = array_keys($points);
        $users = \App\Models\User::whereIn('id', $userIds)->get(['id', 'name', 'first_name', 'last_name', 'avatar_url'])->keyBy('id');
        $data = collect($points)->take(100)->map(function ($score, $userId) use ($users) {
            $user = $users->get($userId);

            return ['score' => $score, 'user' => ['id' => (int) $userId, 'name' => trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')) ?: $user?->name, 'avatar_url' => $user?->avatar_url]];
        })->values()->map(fn ($row, $index) => ['place' => $index + 1] + $row);

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function gameData(MobileGame $game, ?int $userId): array
    {
        $todayAttempts = $userId ? $game->sessions()->where('user_id', $userId)->where('started_at', '>=', now()->startOfDay())->count() : 0;
        $freeUsed = $userId ? $game->sessions()->where('user_id', $userId)->where('used_free_attempt', true)->where('started_at', '>=', now()->startOfDay())->count() : 0;

        return ['id' => $game->id, 'name' => $game->name, 'slug' => $game->slug, 'type' => $game->type, 'description' => $game->description, 'image_url' => $game->image_url, 'entry_cost' => $game->entry_cost, 'free_attempts_left' => max(0, $game->free_attempts_daily - $freeUsed), 'attempts_left' => max(0, $game->daily_attempt_limit - $todayAttempts), 'ranking_enabled' => $game->ranking_enabled, 'settings' => $game->settings, 'reward_rules' => $game->reward_rules, 'season' => $game->activeSeason()?->only(['id', 'name', 'starts_at', 'ends_at'])];
    }
}
