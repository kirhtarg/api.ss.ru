<?php

namespace App\Traits;

use App\Models\ShopGoodsActionLog;

trait HasGoodsLogging
{
    public static function bootHasGoodsLogging()
    {
        static::updating(function ($model) {
            $context = request()->input('log_context', request()->header('X-Log-Context', 'Система'));
            if (! $context) {
                $context = 'Система';
            }

            $dirty = $model->getDirty();

            if (! empty($dirty)) {
                $changes = [];
                $changedFields = [];

                foreach ($dirty as $key => $newValue) {
                    if ($key === 'log' || $key === 'updated_at') {
                        continue;
                    }

                    $oldValue = $model->getOriginal($key);

                    $oldStr = is_array($oldValue) || is_object($oldValue) ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : (string) $oldValue;
                    $newStr = is_array($newValue) || is_object($newValue) ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : (string) $newValue;

                    $oldStrShort = mb_substr($oldStr, 0, 10);
                    if (mb_strlen($oldStr) > 10) {
                        $oldStrShort .= '...';
                    }

                    $newStrShort = mb_substr($newStr, 0, 10);
                    if (mb_strlen($newStr) > 10) {
                        $newStrShort .= '...';
                    }

                    $changedFields[] = $key;
                    $changes[] = "{$oldStrShort}->{$newStrShort}";
                }

                if (! empty($changes)) {
                    $date = now()->format('d.m.Y H:i');
                    $fieldsStr = implode(',', $changedFields);
                    $valuesStr = implode(',', $changes);

                    $logLine = "$date | $context | $fieldsStr | $valuesStr";

                    $currentLog = $model->getOriginal('log');
                    $model->log = $currentLog ? $currentLog.'<br>'.$logLine : $logLine;
                }
            }
        });

        static::created(function ($model) {
            $context = request()->input('log_context', request()->header('X-Log-Context', 'Система'));
            if (! $context) {
                $context = 'Система';
            }

            $date = now()->format('d.m.Y H:i');
            $logLine = "$date | $context | created | -";
            $model->log = $logLine;
            $model->saveQuietly();

            ShopGoodsActionLog::create([
                'good_id' => $model->getTable() === 'shop_goods' ? $model->id : ($model->good_id ?? null),
                'variation_id' => $model->getTable() === 'shop_good_variations' ? $model->id : null,
                'action' => 'created',
                'action_type' => $context,
                'comment' => 'Запись создана',
            ]);
        });

        static::deleted(function ($model) {
            $context = request()->input('log_context', request()->header('X-Log-Context', 'Система'));
            if (! $context) {
                $context = 'Система';
            }

            ShopGoodsActionLog::create([
                'good_id' => $model->getTable() === 'shop_goods' ? $model->id : ($model->good_id ?? null),
                'variation_id' => $model->getTable() === 'shop_good_variations' ? $model->id : null,
                'action' => 'deleted',
                'action_type' => $context,
                'comment' => 'Запись удалена',
            ]);
        });
    }
}
