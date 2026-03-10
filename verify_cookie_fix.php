<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\CookieConsent;
use Illuminate\Support\Str;

echo "--- Testing Long User Agent ---" . PHP_EOL;
$longUA = str_repeat('Mozilla/5.0 (Windows NT 10.0; Win64; x64) ', 10);
$sessionId = Str::uuid()->toString();

try {
    $consent = CookieConsent::updateOrCreateConsent($sessionId, [
        'user_agent' => $longUA,
        'necessary_cookies' => true,
    ]);
    echo "Success: User Agent of length " . strlen($longUA) . " saved." . PHP_EOL;
}
catch (\Exception $e) {
    echo "Error: Failed to save long User Agent: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "--- Testing Race Condition (Duplicate Entry) ---" . PHP_EOL;
$sessionId2 = Str::uuid()->toString();

try {
    // Simulate first request
    CookieConsent::updateOrCreateConsent($sessionId2, ['analytics_cookies' => true]);
    echo "First request for $sessionId2 saved." . PHP_EOL;

    // Simulate second request (update)
    CookieConsent::updateOrCreateConsent($sessionId2, ['marketing_cookies' => true]);
    echo "Second request for $sessionId2 updated successfully." . PHP_EOL;

    $final = CookieConsent::where('session_id', $sessionId2)->first();
    echo "Final state: analytics=" . ($final->analytics_cookies ? 'true' : 'false') . ", marketing=" . ($final->marketing_cookies ? 'true' : 'false') . PHP_EOL;
}
catch (\Exception $e) {
    echo "Error: Race condition test failed: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "--- Verification Complete ---" . PHP_EOL;
