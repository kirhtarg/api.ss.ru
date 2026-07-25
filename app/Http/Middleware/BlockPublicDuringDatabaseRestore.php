<?php

namespace App\Http\Middleware;

use App\Services\DatabaseRestoreMaintenanceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockPublicDuringDatabaseRestore
{
    public function __construct(private readonly DatabaseRestoreMaintenanceService $maintenance)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->maintenance->isActive() || $this->shouldBypass($request)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => $this->maintenance->status()['message'] ?? 'Сайт временно недоступен. Выполняется техническое обслуживание.',
            'maintenance' => $this->maintenance->status(),
        ], 503);
    }

    private function shouldBypass(Request $request): bool
    {
        if ($request->isMethod('OPTIONS')) {
            return true;
        }

        if ($request->is('api/admin/*')) {
            return true;
        }

        if ($request->is('api/auth/*') || $request->is('api/login') || $request->is('api/logout')) {
            return true;
        }

        if ($request->is('api/public/maintenance-status')) {
            return true;
        }

        if ($request->is('api/webhooks/*')) {
            return true;
        }

        return false;
    }
}
