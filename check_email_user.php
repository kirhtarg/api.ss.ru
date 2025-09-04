<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Check User by Email ===\n";

$user = \App\Models\User::where('email', 'kirhtarg@yandex.ru')->first();

if ($user) {
    echo "User found by email:\n";
    echo "ID: " . $user->id . "\n";
    echo "Name: " . $user->name . "\n";
    echo "Email: " . $user->email . "\n";
    echo "Yandex ID: " . ($user->yandex_id ?? 'NULL') . "\n";
    echo "Avatar URL: " . ($user->avatar_url ?? 'NULL') . "\n";
    echo "Updated: " . $user->updated_at . "\n";
} else {
    echo "User not found by email\n";
}

echo "\n=== Check All Users with Yandex ID ===\n";
$yandexUsers = \App\Models\User::whereNotNull('yandex_id')->get();
echo "Found " . $yandexUsers->count() . " users with Yandex ID:\n";

foreach ($yandexUsers as $user) {
    echo "- ID: {$user->id}, Name: {$user->name}, Email: {$user->email}, Yandex ID: {$user->yandex_id}\n";
}
