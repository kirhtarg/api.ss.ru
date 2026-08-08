<?php

namespace Tests\Feature\Seo;

use App\Http\Controllers\Api\Public\SitemapController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SitemapFallbackTest extends TestCase
{
    public function test_missing_export_returns_valid_minimal_sitemap(): void
    {
        Storage::fake('public');
        Log::spy();

        $request = Request::create('https://skateandsnow.ru/api/public/get-sitemap', 'GET');

        $response = app(SitemapController::class)->getSitemap($request);
        $content = $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/xml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<loc>https://skateandsnow.ru/</loc>', $content);
        $this->assertNotFalse(simplexml_load_string($content));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool =>
                $message === '[FIX:seo] Generated emergency sitemap because export file is missing'
                && $context['path'] === 'exports/sitemap.xml'
            );
    }

    public function test_existing_export_is_read_from_public_disk(): void
    {
        Storage::fake('public');
        $expected = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
        Storage::disk('public')->put('exports/sitemap.xml', $expected);

        $request = Request::create('https://skateandsnow.ru/api/public/get-sitemap', 'GET');
        $response = app(SitemapController::class)->getSitemap($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($expected, $response->getContent());
    }
}
