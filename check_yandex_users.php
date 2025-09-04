<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Yandex Users Check ===\n";

$users = \App\Models\User::whereNotNull('yandex_id')->get();

echo "Found " . $users->count() . " users with Yandex ID:\n\n";

foreach ($users as $user) {
    echo "ID: " . $user->id . "\n";
    echo "Name: " . $user->name . "\n";
    echo "Email: " . $user->email . "\n";
    echo "Yandex ID: " . $user->yandex_id . "\n";
    echo "Avatar URL: " . ($user->avatar_url ?? 'NULL') . "\n";
    echo "Updated: " . $user->updated_at . "\n";
    echo "---\n";
}
