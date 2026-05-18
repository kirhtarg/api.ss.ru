<?php

namespace App\Services;

use App\Models\ShopGoodImage;
use Illuminate\Support\Facades\DB;

class ImageCleanupService
{
    protected $imagesBaseDir;

    public function __construct()
    {
        $this->imagesBaseDir = base_path('..' . DIRECTORY_SEPARATOR . 'admin.skateandsnow.ru' . DIRECTORY_SEPARATOR . 'public');
    }

    public function getDuplicates($limit = null, $offset = 0)
    {
        $totalChecked = 0;
        $duplicatesFound = 0;
        $notFound = 0;
        $bytesSaved = 0;
        $logs = [];

        // Исключаем бесхозные записи из группировки поиска дубликатов (где нет ни товара, ни вариации)
        $groupsQuery = ShopGoodImage::select('good_id', 'variation_id')
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->whereNotNull('good_id')->where('good_id', '>', 0);
                })->orWhere(function ($q) {
                    $q->whereNotNull('variation_id')->where('variation_id', '>', 0);
                });
            })
            ->groupBy('good_id', 'variation_id')
            ->orderBy('good_id', 'asc')
            ->orderBy('variation_id', 'asc');
            
        if ($limit) {
            $groupsQuery->limit($limit);
        }
        
        if ($offset) {
            $groupsQuery->offset($offset);
        }
        
        $groups = $groupsQuery->get();

        foreach ($groups as $group) {
            $imagesQuery = ShopGoodImage::with(['variation', 'good', 'variation.good'])
                ->orderBy('id', 'asc');
                
            if (is_null($group->good_id)) {
                $imagesQuery->whereNull('good_id');
            } else {
                $imagesQuery->where('good_id', $group->good_id);
            }
            
            if (is_null($group->variation_id)) {
                $imagesQuery->whereNull('variation_id');
            } else {
                $imagesQuery->where('variation_id', $group->variation_id);
            }
            
            $images = $imagesQuery->get();

            $hashes = [];

            foreach ($images as $image) {
                $totalChecked++;

                if ($this->isExternalUrl($image->file_path)) {
                    continue;
                }
                
                $fullPath = $this->getFullPath($image->file_path);

                if (!$fullPath || !file_exists($fullPath) || is_dir($fullPath)) {
                    $notFound++;
                    continue;
                }

                $hash = md5_file($fullPath);

                if (isset($hashes[$hash])) {
                    $duplicatesFound++;
                    $fileSize = filesize($fullPath);
                    $bytesSaved += $fileSize;

                    $targetId = $hashes[$hash];
                    
                    $goodId = null;
                    $isOrphan = false;

                    if ($image->good_id && $image->good_id > 0) {
                        $goodId = $image->good_id;
                        if (!$image->good) {
                            $isOrphan = true;
                        }
                    } elseif ($image->variation_id && $image->variation_id > 0) {
                        if ($image->variation) {
                            $goodId = $image->variation->good_id;
                            if (!$image->variation->good) {
                                $isOrphan = true;
                            }
                        } else {
                            $isOrphan = true;
                        }
                    } else {
                        $isOrphan = true;
                    }
                    
                    $logs[] = [
                        'id' => $image->id,
                        'file_path' => $image->file_path,
                        'good_id' => $goodId,
                        'variation_id' => $image->variation_id,
                        'duplicate_of' => $targetId,
                        'size' => $fileSize,
                        'size_human' => round($fileSize / 1024, 2) . ' KB',
                        'type' => 'duplicate',
                        'is_orphan' => $isOrphan
                    ];
                } else {
                    $hashes[$hash] = $image->id;
                }
            }
        }

        // Считаем общее число валидных групп (исключая полностью пустые)
        $totalGroups = DB::table(DB::raw('(
            select 1 from shop_good_images 
            where (good_id is not null and good_id > 0) 
               or (variation_id is not null and variation_id > 0) 
            group by good_id, variation_id
        ) as temp'))->count();

        return [
            'stats' => [
                'total_in_db' => $totalGroups,
                'total_images' => ShopGoodImage::count(),
                'total_checked' => $totalChecked,
                'groups_checked' => count($groups),
                'files_found' => $totalChecked - $notFound,
                'files_not_found' => $notFound,
                'duplicates_found' => $duplicatesFound,
                'potential_savings' => round($bytesSaved / 1024 / 1024, 2) . ' MB',
                'bytes_saved' => $bytesSaved,
                'next_offset' => $offset + count($groups),
            ],
            'items' => $logs
        ];
    }

    public function getBrokenLinks($limit = null, $offset = 0)
    {
        $totalChecked = 0;
        $brokenFound = 0;
        $logs = [];

        $query = ShopGoodImage::with(['variation', 'good', 'variation.good']);
            
        if ($limit) {
            $query->limit($limit); 
        }
        
        if ($offset) {
            $query->offset($offset);
        }
        
        $images = $query->orderBy('id', 'desc')->get();

        foreach ($images as $image) {
            $totalChecked++;

            if ($this->isExternalUrl($image->file_path)) {
                continue;
            }
            
            $fullPath = $this->getFullPath($image->file_path);

            if (!$fullPath || !file_exists($fullPath) || is_dir($fullPath)) {
                $brokenFound++;
                
                $goodId = null;
                $isOrphan = false;

                if ($image->good_id && $image->good_id > 0) {
                    $goodId = $image->good_id;
                    if (!$image->good) {
                        $isOrphan = true;
                    }
                } elseif ($image->variation_id && $image->variation_id > 0) {
                    if ($image->variation) {
                        $goodId = $image->variation->good_id;
                        if (!$image->variation->good) {
                            $isOrphan = true;
                        }
                    } else {
                        $isOrphan = true;
                    }
                } else {
                    $isOrphan = true;
                }
                
                $logs[] = [
                    'id' => $image->id,
                    'file_path' => $image->file_path,
                    'good_id' => $goodId,
                    'variation_id' => $image->variation_id,
                    'type' => 'broken',
                    'is_orphan' => $isOrphan
                ];
                
                if ($limit && $brokenFound >= $limit) {
                    break;
                }
            }
        }

        return [
            'stats' => [
                'total_in_db' => ShopGoodImage::count(),
                'total_images' => ShopGoodImage::count(),
                'total_checked' => $totalChecked,
                'broken_found' => $brokenFound,
                'next_offset' => $offset + $totalChecked,
            ],
            'items' => $logs
        ];
    }

    public function getExternalLinks($limit = null, $offset = 0)
    {
        $totalChecked = 0;
        $availableFound = 0;
        $brokenFound = 0;
        $logs = [];

        $query = ShopGoodImage::with(['variation', 'good', 'variation.good'])
            ->where(function ($q) {
                $q->where('file_path', 'like', 'http://%')
                    ->orWhere('file_path', 'like', 'https://%');
            })
            ->orderBy('id', 'desc');

        if ($limit) {
            $query->limit($limit);
        }

        if ($offset) {
            $query->offset($offset);
        }

        $images = $query->get();

        foreach ($images as $image) {
            $totalChecked++;

            $check = $this->checkExternalImageUrl($image->file_path);
            $isAvailable = $check['available'];
            $availableFound += $isAvailable ? 1 : 0;
            $brokenFound += $isAvailable ? 0 : 1;

            $logs[] = array_merge($this->imageContext($image), [
                'id' => $image->id,
                'file_path' => $image->file_path,
                'type' => $isAvailable ? 'external_available' : 'external_broken',
                'http_code' => $check['http_code'],
                'content_type' => $check['content_type'],
                'error' => $check['error'],
            ]);
        }

        $totalExternal = ShopGoodImage::where('file_path', 'like', 'http://%')
            ->orWhere('file_path', 'like', 'https://%')
            ->count();

        return [
            'stats' => [
                'total_in_db' => $totalExternal,
                'total_images' => ShopGoodImage::count(),
                'total_checked' => $totalChecked,
                'external_available' => $availableFound,
                'external_broken' => $brokenFound,
                'next_offset' => $offset + $totalChecked,
            ],
            'items' => $logs,
        ];
    }

    public function cleanup($ids = [], array $options = [])
    {
        if (empty($ids)) {
            return [
                'success' => false,
                'message' => 'Не выбраны элементы для удаления'
            ];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $type = $options['type'] ?? null;
        $items = $options['items'] ?? [];
        $expectedItemsById = [];

        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }

            $expectedItemsById[(int) $item['id']] = $item;
        }

        $deletedCount = 0;
        $bytesDeleted = 0;
        $deletedIds = [];
        $skipped = [];

        $images = ShopGoodImage::whereIn('id', $ids)->get();

        foreach ($images as $image) {
            $fullPath = $this->getFullPath($image->file_path);
            $expectedItem = $expectedItemsById[$image->id] ?? null;

            if ($this->isExternalUrl($image->file_path) && $type !== 'external') {
                $skipped[] = [
                    'id' => $image->id,
                    'reason' => 'external_url',
                    'file_path' => $image->file_path,
                ];
                continue;
            }

            if ($type === 'broken') {
                if (!$expectedItem || !$this->pathsMatch($image->file_path, $expectedItem['file_path'] ?? null)) {
                    $skipped[] = [
                        'id' => $image->id,
                        'reason' => 'path_mismatch',
                        'expected_file_path' => $expectedItem['file_path'] ?? null,
                        'actual_file_path' => $image->file_path,
                    ];
                    continue;
                }

                if ($fullPath && file_exists($fullPath) && !is_dir($fullPath)) {
                    $skipped[] = [
                        'id' => $image->id,
                        'reason' => 'file_exists',
                        'file_path' => $image->file_path,
                    ];
                    continue;
                }
            }

            if ($type === 'external') {
                if (!$expectedItem || !$this->pathsMatch($image->file_path, $expectedItem['file_path'] ?? null)) {
                    $skipped[] = [
                        'id' => $image->id,
                        'reason' => 'path_mismatch',
                        'expected_file_path' => $expectedItem['file_path'] ?? null,
                        'actual_file_path' => $image->file_path,
                    ];
                    continue;
                }

                if (!$this->isExternalUrl($image->file_path)) {
                    $skipped[] = [
                        'id' => $image->id,
                        'reason' => 'not_external_url',
                        'file_path' => $image->file_path,
                    ];
                    continue;
                }

                $check = $this->checkExternalImageUrl($image->file_path);
                if ($check['available']) {
                    $skipped[] = [
                        'id' => $image->id,
                        'reason' => 'external_url_available',
                        'file_path' => $image->file_path,
                    ];
                    continue;
                }
            }

            // Если это не битая ссылка (т.е. файл существует)
            if ($fullPath && file_exists($fullPath) && !is_dir($fullPath)) {
                // ЗАЩИТА: Проверяем, используется ли этот же файл другими записями в БД,
                // которые НЕ входят в список удаляемых в данный момент ($ids).
                $isUsedElsewhere = ShopGoodImage::where('file_path', $image->file_path)
                    ->whereNotIn('id', $ids)
                    ->exists();

                if (!$isUsedElsewhere) {
                    // Файл уникален и нигде больше не используется, можно безопасно удалять с диска
                    $bytesDeleted += filesize($fullPath);
                    @unlink($fullPath);
                }
            }
            
            $image->delete();
            $deletedCount++;
            $deletedIds[] = $image->id;
        }

        return [
            'success' => true,
            'deleted_count' => $deletedCount,
            'deleted_ids' => $deletedIds,
            'skipped_count' => count($skipped),
            'skipped' => $skipped,
            'bytes_deleted' => $bytesDeleted,
            'savings_human' => round($bytesDeleted / 1024 / 1024, 2) . ' MB',
        ];
    }

    public function downloadExternal($ids = [], array $options = [])
    {
        if (empty($ids)) {
            return [
                'success' => false,
                'message' => 'Не выбраны внешние ссылки для скачивания',
            ];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $items = $options['items'] ?? [];
        $expectedItemsById = [];

        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }

            $expectedItemsById[(int) $item['id']] = $item;
        }

        $downloaded = [];
        $skipped = [];
        $failed = [];
        $bytesDownloaded = 0;

        $images = ShopGoodImage::whereIn('id', $ids)->get();

        foreach ($images as $image) {
            $expectedItem = $expectedItemsById[$image->id] ?? null;

            if (!$expectedItem || !$this->pathsMatch($image->file_path, $expectedItem['file_path'] ?? null)) {
                $skipped[] = [
                    'id' => $image->id,
                    'reason' => 'path_mismatch',
                    'expected_file_path' => $expectedItem['file_path'] ?? null,
                    'actual_file_path' => $image->file_path,
                ];
                continue;
            }

            if (!$this->isExternalUrl($image->file_path)) {
                $skipped[] = [
                    'id' => $image->id,
                    'reason' => 'not_external_url',
                    'file_path' => $image->file_path,
                ];
                continue;
            }

            $download = $this->downloadExternalImage($image->file_path);
            if (!$download['success']) {
                $failed[] = [
                    'id' => $image->id,
                    'file_path' => $image->file_path,
                    'reason' => $download['error'],
                    'http_code' => $download['http_code'] ?? null,
                ];
                continue;
            }

            $newPath = $this->saveExternalImageData($image, $download['data'], $download['content_type']);
            if (!$newPath) {
                $failed[] = [
                    'id' => $image->id,
                    'file_path' => $image->file_path,
                    'reason' => 'save_failed',
                ];
                continue;
            }

            $oldPath = $image->file_path;
            $image->file_path = $newPath;
            $image->save();

            $bytesDownloaded += strlen($download['data']);
            $downloaded[] = [
                'id' => $image->id,
                'old_file_path' => $oldPath,
                'new_file_path' => $newPath,
                'size' => strlen($download['data']),
            ];
        }

        return [
            'success' => true,
            'downloaded_count' => count($downloaded),
            'downloaded_ids' => array_column($downloaded, 'id'),
            'downloaded' => $downloaded,
            'failed_count' => count($failed),
            'failed' => $failed,
            'skipped_count' => count($skipped),
            'skipped' => $skipped,
            'bytes_downloaded' => $bytesDownloaded,
            'downloaded_human' => round($bytesDownloaded / 1024 / 1024, 2) . ' MB',
        ];
    }

    private function pathsMatch($actualPath, $expectedPath)
    {
        if ($actualPath === null || $expectedPath === null) {
            return false;
        }

        return $this->normalizeDbPath($actualPath) === $this->normalizeDbPath($expectedPath);
    }

    private function normalizeDbPath($path)
    {
        $path = str_replace('\\', '/', trim((string) $path));
        $path = preg_replace('#/+#', '/', $path);

        return ltrim($path, '/');
    }

    private function isExternalUrl($path)
    {
        return is_string($path) && preg_match('#^https?://#i', trim($path)) === 1;
    }

    private function imageContext(ShopGoodImage $image)
    {
        $goodId = null;
        $isOrphan = false;

        if ($image->good_id && $image->good_id > 0) {
            $goodId = $image->good_id;
            if (!$image->good) {
                $isOrphan = true;
            }
        } elseif ($image->variation_id && $image->variation_id > 0) {
            if ($image->variation) {
                $goodId = $image->variation->good_id;
                if (!$image->variation->good) {
                    $isOrphan = true;
                }
            } else {
                $isOrphan = true;
            }
        } else {
            $isOrphan = true;
        }

        return [
            'good_id' => $goodId,
            'variation_id' => $image->variation_id,
            'is_orphan' => $isOrphan,
        ];
    }

    private function checkExternalImageUrl($url)
    {
        $download = $this->downloadExternalImage($url, 4096, true);

        return [
            'available' => $download['success'],
            'http_code' => $download['http_code'] ?? null,
            'content_type' => $download['content_type'] ?? null,
            'error' => $download['error'] ?? null,
        ];
    }

    private function downloadExternalImage($url, $maxBytes = 31457280, $allowPartial = false)
    {
        $data = '';
        $tooLarge = false;
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$data, &$tooLarge, $maxBytes) {
            $data .= $chunk;
            if (strlen($data) > $maxBytes) {
                $tooLarge = true;

                return 0;
            }

            return strlen($chunk);
        });
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($tooLarge && !$allowPartial) {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'content_type' => $contentType,
                'error' => 'file_too_large',
            ];
        }

        if (($errno !== 0 && !($allowPartial && $tooLarge)) || $data === '' || $httpCode < 200 || $httpCode >= 400) {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'content_type' => $contentType,
                'error' => $error ?: 'http_error',
            ];
        }

        $mimeType = $this->detectMimeType($data, $contentType);
        if (!str_starts_with($mimeType, 'image/')) {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'content_type' => $contentType,
                'error' => 'not_image',
            ];
        }

        return [
            'success' => true,
            'http_code' => $httpCode,
            'content_type' => $mimeType,
            'data' => $data,
        ];
    }

    private function saveExternalImageData(ShopGoodImage $image, $data, $contentType)
    {
        $extension = $this->extensionFromMimeType($contentType);
        $directory = $this->imagesBaseDir . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'shop' . DIRECTORY_SEPARATOR . 'goods' . DIRECTORY_SEPARATOR . 'external';

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return null;
        }

        $fileName = $image->id . '_' . substr(sha1($image->file_path), 0, 16) . '.' . $extension;
        $absolutePath = $directory . DIRECTORY_SEPARATOR . $fileName;

        if (file_put_contents($absolutePath, $data) === false) {
            return null;
        }

        return '/images/shop/goods/external/' . $fileName;
    }

    private function detectMimeType($data, $contentType)
    {
        $contentType = strtolower(trim((string) $contentType));
        if ($contentType !== '') {
            $contentType = trim(explode(';', $contentType)[0]);
            if (str_starts_with($contentType, 'image/')) {
                return $contentType;
            }
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $data) ?: 'application/octet-stream';
        finfo_close($finfo);

        return strtolower($mimeType);
    }

    private function extensionFromMimeType($mimeType)
    {
        return match (strtolower((string) $mimeType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp', 'image/x-ms-bmp' => 'bmp',
            'image/avif' => 'avif',
            default => 'jpg',
        };
    }

    /**
     * Получить абсолютный путь к файлу на сервере с учетом папки images/
     */
    private function getFullPath($filePath)
    {
        if (empty($filePath)) {
            return null;
        }

        if ($this->isExternalUrl($filePath)) {
            return null;
        }

        $fileName = ltrim($filePath, '/\\');
        
        // Если путь не начинается с 'images/' или 'images\', то файл на самом деле лежит в папке images/
        if (!str_starts_with($fileName, 'images/') && !str_starts_with($fileName, 'images\\')) {
            $fileName = 'images' . DIRECTORY_SEPARATOR . $fileName;
        }

        return $this->imagesBaseDir . DIRECTORY_SEPARATOR . $fileName;
    }
}
