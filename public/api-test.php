<?php
// Простой тест для проверки работы API на удаленном сервере
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Проверяем, что мы можем подключиться к Laravel
try {
    // Подключаемся к Laravel
    require_once __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    
    // Проверяем маршруты
    $router = $app->make('router');
    $routes = $router->getRoutes();
    
    $apiRoutes = [];
    foreach ($routes as $route) {
        if (strpos($route->uri(), 'api/') === 0) {
            $apiRoutes[] = [
                'uri' => $route->uri(),
                'methods' => $route->methods(),
                'name' => $route->getName()
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Laravel API работает',
        'php_version' => PHP_VERSION,
        'laravel_version' => $app->version(),
        'environment' => $app->environment(),
        'api_routes_count' => count($apiRoutes),
        'api_routes' => $apiRoutes,
        'current_url' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
