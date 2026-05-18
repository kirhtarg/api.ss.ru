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
                
                $fileName = ltrim($image->file_path, '/\\');
                $fullPath = $this->imagesBaseDir . DIRECTORY_SEPARATOR . $fileName;

                if (!file_exists($fullPath) || is_dir($fullPath)) {
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
            
            $fileName = ltrim($image->file_path, '/\\');
            $fullPath = $this->imagesBaseDir . DIRECTORY_SEPARATOR . $fileName;

            if (!file_exists($fullPath) || is_dir($fullPath)) {
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

    public function cleanup($ids = [])
    {
        if (empty($ids)) {
            return [
                'success' => false,
                'message' => 'Не выбраны элементы для удаления'
            ];
        }

        $deletedCount = 0;
        $bytesDeleted = 0;

        $images = ShopGoodImage::whereIn('id', $ids)->get();

        foreach ($images as $image) {
            $fileName = ltrim($image->file_path, '/\\');
            $fullPath = $this->imagesBaseDir . DIRECTORY_SEPARATOR . $fileName;

            // Если это не битая ссылка (т.е. файл существует)
            if (file_exists($fullPath) && !is_dir($fullPath)) {
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
        }

        return [
            'success' => true,
            'deleted_count' => $deletedCount,
            'bytes_deleted' => $bytesDeleted,
            'savings_human' => round($bytesDeleted / 1024 / 1024, 2) . ' MB',
        ];
    }
}
