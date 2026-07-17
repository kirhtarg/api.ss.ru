<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\TopSportsImportController;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TopSportsImportControllerTest extends TestCase
{
    #[DataProvider('tokenResponses')]
    public function test_it_extracts_token_from_supported_login_responses(mixed $json, string $body, string $expected): void
    {
        $response = $this->createMock(Response::class);
        $response->method('json')->willReturn($json);
        $response->method('body')->willReturn($body);

        $method = new \ReflectionMethod(TopSportsImportController::class, 'extractToken');

        $this->assertSame($expected, $method->invoke(new TopSportsImportController(), $response));
    }

    public static function tokenResponses(): array
    {
        return [
            'json token' => [['token' => 'jwt-token'], '{"token":"jwt-token"}', 'jwt-token'],
            'nested access token' => [['data' => ['access_token' => 'nested-token']], '{}', 'nested-token'],
            'plain response' => [null, "  long-token-string\n", 'long-token-string'],
            'quoted response' => [null, '"quoted-token"', 'quoted-token'],
            'unknown json' => [['message' => 'ok'], '{"message":"ok"}', ''],
        ];
    }

    public function test_it_supports_documented_bearer_and_raw_authorization_formats(): void
    {
        $method = new \ReflectionMethod(TopSportsImportController::class, 'authorizationCandidates');
        $controller = new TopSportsImportController();

        $this->assertSame(['jwt-token', 'Bearer jwt-token'], $method->invoke($controller, 'jwt-token'));
        $this->assertSame(['Bearer ready-token'], $method->invoke($controller, 'Bearer ready-token'));
    }
}
