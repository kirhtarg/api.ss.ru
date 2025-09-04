<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$user = \App\Models\User::where('yandex_id', '1765537444')->first();

if ($user) {
    echo "User: " . $user->name . "\n";
    echo "Email: " . $user->email . "\n";
    echo "Avatar URL: " . ($user->avatar_url ?? 'NULL') . "\n";
    echo "Updated: " . $user->updated_at . "\n";
} else {
    echo "User not found\n";
}
