<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsImportBackup extends Model
{
    protected $fillable = [
        'name',
        'filename',
        'shop_id',
        'user_id',
        'size',
        'records_count',
        'tables_backed_up',
        'status',
        'error_message',
    ];

    protected $casts = [
        'tables_backed_up' => 'array',
        'size' => 'integer',
        'records_count' => 'integer',
        'shop_id' => 'integer',
        'user_id' => 'integer',
    ];

    /**
     * Пользователь, создавший резервную копию
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить путь к файлу резервной копии
     */
    public function getFilePath(): string
    {
        return storage_path('app/backups/goods_import/'.$this->filename);
    }

    /**
     * Проверить существует ли файл резервной копии
     */
    public function fileExists(): bool
    {
        return file_exists($this->getFilePath());
    }

    /**
     * Удалить файл резервной копии
     */
    public function deleteFile(): bool
    {
        if ($this->fileExists()) {
            return unlink($this->getFilePath());
        }

        return true;
    }
}
