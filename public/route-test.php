<?php
// Тест для проверки маршрутизации на удаленном сервере
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    // Подключаемся к Laravel
    require_once __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    
    // Получаем маршруты
    $router = $app->make('router');
    $routes = $router->getRoutes();
    
    // Ищем конкретный маршрут
    $targetRoute = null;
    $allApiRoutes = [];
    
    foreach ($routes as $route) {
        $uri = $route->uri();
        if (strpos($uri, 'api/') === 0) {
            $allApiRoutes[] = [
                'uri' => $uri,
                'methods' => $route->methods(),
                'name' => $route->getName(),
                'action' => $route->getActionName()
            ];
            
            if ($uri === 'api/public/site-info') {
                $targetRoute = [
                    'uri' => $uri,
                    'methods' => $route->methods(),
                    'name' => $route->getName(),
                    'action' => $route->getActionName()
                ];
            }
        }
    }
    
    // Проверяем, есть ли маршрут
    $hasTargetRoute = $targetRoute !== null;
    
    // Пытаемся найти маршрут по паттерну
    $similarRoutes = array_filter($allApiRoutes, function($route) {
        return strpos($route['uri'], 'site-info') !== false || 
               strpos($route['uri'], 'public') !== false;
    });
    
    echo json_encode([
        'success' => true,
        'message' => 'Диагностика маршрутизации',
        'target_route_found' => $hasTargetRoute,
        'target_route' => $targetRoute,
        'similar_routes' => array_values($similarRoutes),
        'total_api_routes' => count($allApiRoutes),
        'all_api_routes' => $allApiRoutes,
        'laravel_version' => $app->version(),
        'environment' => $app->environment(),
        'current_url' => $_SERVER['REQUEST_URI'] ?? 'unknown'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
