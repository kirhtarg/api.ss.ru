<?php

namespace App\Http\Controllers\Api\Partner\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DocumentationController extends Controller
{
    public function specification(): JsonResponse
    {
        Log::debug('Partner OpenAPI specification requested');
        $document = json_decode(file_get_contents(resource_path('openapi/partner-v1.json')), true, 512, JSON_THROW_ON_ERROR);

        return response()->json($document, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function reference(): SymfonyResponse
    {
        Log::debug('Partner interactive API reference requested');
        $specificationUrl = url('/api/partner/v1/openapi.json');
        $html = <<<'HTML'
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Partner API Reference</title>
</head>
<body>
  <script id="api-reference" data-configuration="CONFIG"></script>
  <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.63.0"></script>
</body>
</html>
HTML;
        $configuration = json_encode([
            'url' => $specificationUrl,
            'theme' => 'default',
            'layout' => 'modern',
            'hideModels' => false,
            'hideDownloadButton' => false,
            'showSidebar' => true,
            'darkMode' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return Response::make(str_replace('CONFIG', htmlspecialchars($configuration, ENT_QUOTES), $html))
            ->header('Content-Security-Policy', "default-src 'none'; script-src https://cdn.jsdelivr.net; style-src 'unsafe-inline'; img-src data: https:; connect-src 'self'; font-src data:; base-uri 'none'; form-action 'none'; frame-ancestors https://skateandsnow.ru https://www.skateandsnow.ru")
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
