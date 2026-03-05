<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ExportFile extends Model
{
    protected $fillable = [
        'created_by',
        'filename',
        'original_filename',
        'file_path',
        'format',
        'status',
        'total_rows',
        'file_size',
        'error_message',
        'export_config',
    ];

    protected $casts = [
        'export_config' => 'array',
        'total_rows' => 'integer',
        'file_size' => 'integer',
    ];

    /**
     * Пользователь, который создал файл экспорта
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope для активных файлов
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'processing', 'completed']);
    }

    /**
     * Scope для завершенных файлов
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Проверяет, доступен ли файл для скачивания
     */
    public function isDownloadable(): bool
    {
        return $this->status === 'completed' && $this->file_path && Storage::exists($this->file_path);
    }

    /**
     * Получает полный путь к файлу
     */
    public function getFullPath(): string
    {
        return Storage::path($this->file_path);
    }

    /**
     * Форматирует размер файла для отображения
     */
    public function formatFileSize(): string
    {
        $bytes = $this->file_size;
        if ($bytes === 0) {
            return '0 B';
        }

        $k = 1024;
        $sizes = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes) / log($k));

        return round($bytes / pow($k, $i), 1).' '.$sizes[$i];
    }

    /**
     * Получает текстовое представление статуса
     */
    public function getStatusText(): string
    {
        return match ($this->status) {
            'pending' => 'Ожидает',
            'processing' => 'Обрабатывается',
            'completed' => 'Готов',
            'failed' => 'Ошибка',
            default => 'Неизвестно'
        };
    }
}
