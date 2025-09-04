<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== All Users Check ===\n";

$users = \App\Models\User::orderBy('updated_at', 'desc')->limit(10)->get();

echo "Found " . $users->count() . " recent users:\n\n";

foreach ($users as $user) {
    echo "ID: " . $user->id . "\n";
    echo "Name: " . $user->name . "\n";
    echo "Email: " . $user->email . "\n";
    echo "Yandex ID: " . ($user->yandex_id ?? 'NULL') . "\n";
    echo "Google ID: " . ($user->google_id ?? 'NULL') . "\n";
    echo "VK ID: " . ($user->vk_id ?? 'NULL') . "\n";
    echo "Avatar URL: " . ($user->avatar_url ?? 'NULL') . "\n";
    echo "Updated: " . $user->updated_at . "\n";
    echo "---\n";
}
