<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class DatabaseRestoreMaintenanceService
{
    private const DISK = 'local';
    private const PATH = 'maintenance/database_restore.json';

    public function activate(string $taskId, string $filename, string $mode = 'full', int $selectedTablesCount = 0): void
    {
        Storage::disk(self::DISK)->makeDirectory('maintenance');
        Storage::disk(self::DISK)->put(self::PATH, json_encode([
            'active' => true,
            'reason' => 'database_restore',
            'task_id' => $taskId,
            'filename' => $filename,
            'mode' => $mode,
            'selected_tables_count' => $selectedTablesCount,
            'started_at' => date('Y-m-d H:i:s'),
            'message' => 'Сайт временно недоступен. Выполняется техническое обслуживание базы данных.',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function deactivate(): void
    {
        Storage::disk(self::DISK)->delete(self::PATH);

        // На некоторых конфигурациях локальный диск мог оставить пустой файл.
        // Такой файл всё равно включает режим обслуживания, поэтому проверяем
        // абсолютный путь и удаляем остаток напрямую.
        $absolutePath = Storage::disk(self::DISK)->path(self::PATH);
        if (File::exists($absolutePath)) {
            File::delete($absolutePath);
        }
    }

    public function status(): array
    {
        if (! Storage::disk(self::DISK)->exists(self::PATH)) {
            return [
                'active' => false,
            ];
        }

        $decoded = json_decode(Storage::disk(self::DISK)->get(self::PATH), true);
        if (! is_array($decoded)) {
            return [
                'active' => true,
                'reason' => 'database_restore',
                'message' => 'Сайт временно недоступен. Выполняется техническое обслуживание базы данных.',
            ];
        }

        return array_merge([
            'active' => true,
            'reason' => 'database_restore',
            'message' => 'Сайт временно недоступен. Выполняется техническое обслуживание базы данных.',
        ], $decoded);
    }

    public function isActive(): bool
    {
        return (bool) ($this->status()['active'] ?? false);
    }
}
