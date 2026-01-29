<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Laravel\Sanctum\PersonalAccessToken;

$token = PersonalAccessToken::findToken('2stV20vrvmVbxPMB6jVejoZlAFySFMUH011QyZZ6a9449961');

if ($token) {
    echo "Token found\n";
    echo "Tokenable ID: {$token->tokenable_id}\n";
    echo "Tokenable Type: {$token->tokenable_type}\n";
    echo "Abilities: " . json_encode($token->abilities) . "\n";
    echo "Created at: {$token->created_at}\n";
    echo "Last used at: {$token->last_used_at}\n";
} else {
    echo "Token not found\n";
}

// Также проверим все токены
$allTokens = PersonalAccessToken::all();
echo "Total tokens: {$allTokens->count()}\n";

foreach ($allTokens as $t) {
    echo "Token ID: {$t->id}, Tokenable ID: {$t->tokenable_id}, Abilities: " . json_encode($t->abilities) . "\n";
}