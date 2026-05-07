<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\ShopGood;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopGoodsController extends Controller
{
    /**
     * Применить фильтрацию по остаткам к запросу товаров
     */
    private function applyStockFilter($query)
    {
        $shopShowGoodMode = Setting::where('key', 'shop_show_good_mode')->first();
        $showGoodMode = $shopShowGoodMode ? (int) $shopShowGoodMode->value : 2;

        $shopRemoteQ = Setting::where('key', 'shop_remote_q')->first();
        $remoteQ = $shopRemoteQ ? (int) $shopRemoteQ->value : 1;

        // Фильтрация по остаткам применяется при shop_show_good_mode = 1
        if ($showGoodMode === 1) {
            // Фильтрация по остаткам: показывать только товары с остатком
            // Для товаров БЕЗ вариаций: проверяем остатки основного товара
            // Для товаров С вариациями: проверяем что сумма остатков всех вариаций > 0
            $query->where(function ($mainQuery) use ($remoteQ) {
                // Вариант 1: Товары БЕЗ вариаций с остатком основного товара
                $mainQuery->where(function ($noVariationsQuery) use ($remoteQ) {
                    $noVariationsQuery->whereDoesntHave('variations')
                        ->where(function ($stockQuery) use ($remoteQ) {
                            // Локальный остаток > 0
                            $stockQuery->where('stock_quantity', '>', 0);

                            // ИЛИ удаленный остаток не пустой (если учитываем удаленный склад)
                            if ($remoteQ === 2 || $remoteQ === 3) {
                                $stockQuery->orWhere(function ($remoteCondition) {
                                    $remoteCondition->whereNotNull('remote_stock_quantity')
                                        ->where('remote_stock_quantity', '!=', '0')
                                        ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                                })
                                    ->orWhere(function ($fastRemoteCondition) {
                                        $fastRemoteCondition->whereNotNull('fast_remote_stock_quantity')
                                            ->where('fast_remote_stock_quantity', '!=', '0')
                                            ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                    });
                            }
                        });
                });

                // Вариант 2: Товары С вариациями, у которых сумма остатков всех вариаций > 0
                $mainQuery->orWhere(function ($hasVariationsQuery) use ($remoteQ) {
                    // Товар должен иметь вариации
                    $hasVariationsQuery->whereHas('variations', function ($varQ) {
                        $varQ->where('is_active', true);
                    });

                    // Проверяем что сумма остатков всех вариаций > 0
                    if ($remoteQ === 2 || $remoteQ === 3) {
                        // Если учитываем удаленный склад, проверяем сумму локальных и удаленных остатков
                        // Для каждой вариации суммируем локальный и удаленный остатки
                        $hasVariationsQuery->whereRaw('(
                            SELECT COALESCE(SUM(
                                COALESCE(stock_quantity, 0) +
                                CASE
                                    WHEN remote_stock_quantity IS NOT NULL
                                         AND remote_stock_quantity != "0"
                                         AND LENGTH(TRIM(remote_stock_quantity)) > 0
                                         AND remote_stock_quantity REGEXP "^[0-9]+$"
                                         AND CAST(remote_stock_quantity AS UNSIGNED) > 0
                                    THEN CAST(remote_stock_quantity AS UNSIGNED)
                                    ELSE 0
                                END +
                                CASE
                                    WHEN fast_remote_stock_quantity IS NOT NULL
                                         AND fast_remote_stock_quantity != "0"
                                         AND LENGTH(TRIM(fast_remote_stock_quantity)) > 0
                                         AND fast_remote_stock_quantity REGEXP "^[0-9]+$"
                                         AND CAST(fast_remote_stock_quantity AS UNSIGNED) > 0
                                    THEN CAST(fast_remote_stock_quantity AS UNSIGNED)
                                    ELSE 0
                                END
                            ), 0)
                            FROM shop_good_variations
                            WHERE shop_good_variations.good_id = shop_goods.id
                            AND shop_good_variations.is_active = 1
                        ) > 0');
                    } else {
                        // Если не учитываем удаленный склад, проверяем только локальные остатки
                        $hasVariationsQuery->whereRaw('(
                            SELECT COALESCE(SUM(stock_quantity), 0)
                            FROM shop_good_variations
                            WHERE shop_good_variations.good_id = shop_goods.id
                            AND shop_good_variations.is_active = 1
                            AND stock_quantity > 0
                        ) > 0');
                    }
                });
            });
        }

        // Фильтр для режима 4: показывать только товары с остатком или специальные товары
        if ($showGoodMode === 4) {
            $query->where(function ($mainQuery) use ($remoteQ) {
                // Условие 1: Товары БЕЗ вариаций
                $mainQuery->where(function ($noVariationsQuery) use ($remoteQ) {
                    $noVariationsQuery->whereDoesntHave('variations')
                        ->where(function ($stockQuery) use ($remoteQ) {
                            // Локальный остаток > 0
                            $stockQuery->where('stock_quantity', '>', 0);

                            // ИЛИ удаленный остаток (если учитываем)
                            if ($remoteQ === 2 || $remoteQ === 3) {
                                $stockQuery->orWhere(function ($remoteCondition) {
                                    $remoteCondition->whereNotNull('remote_stock_quantity')
                                        ->where('remote_stock_quantity', '!=', '0')
                                        ->where('remote_stock_quantity', '!=', '')
                                        ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                                })
                                    ->orWhere(function ($fastRemoteCondition) {
                                        $fastRemoteCondition->whereNotNull('fast_remote_stock_quantity')
                                            ->where('fast_remote_stock_quantity', '!=', '0')
                                            ->where('fast_remote_stock_quantity', '!=', '')
                                            ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                    });
                            }

                            // ИЛИ предзаказы (is_preorder = 1) с нулевым остатком
                            $stockQuery->orWhere(function ($preorderCondition) use ($remoteQ) {
                                $preorderCondition->where('stock_quantity', '<=', 0)
                                    ->where(function ($preorderSubCondition) {
                                        $preorderSubCondition->where('is_preorder', '=', 1)
                                            ->orWhere('is_preorder', '=', true);
                                    });

                                // Проверяем, что удаленный остаток тоже пустой (если учитываем)
                                if ($remoteQ === 2 || $remoteQ === 3) {
                                    $preorderCondition->where(function ($remoteEmptyCondition) {
                                        $remoteEmptyCondition->whereNull('remote_stock_quantity')
                                            ->orWhere('remote_stock_quantity', '=', '0')
                                            ->orWhere('remote_stock_quantity', '=', '')
                                            ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
                                    })
                                        ->where(function ($fastRemoteEmptyCondition) {
                                            $fastRemoteEmptyCondition->whereNull('fast_remote_stock_quantity')
                                                ->orWhere('fast_remote_stock_quantity', '=', '0')
                                                ->orWhere('fast_remote_stock_quantity', '=', '')
                                                ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
                                        });
                                }
                            });

                            // ИЛИ товары с is_show = 1 (независимо от остатка)
                            $stockQuery->orWhere('is_show', '=', 1);
                        });
                });

                // Условие 2: Товары С вариациями
                $mainQuery->orWhere(function ($hasVariationsQuery) use ($remoteQ) {
                    $hasVariationsQuery->whereHas('variations', function ($varQ) {
                        $varQ->where('is_active', true);
                    })
                        ->where(function ($variationsStockQuery) use ($remoteQ) {
                            // Вариант 2a: Есть хотя бы одна вариация с остатком
                            $variationsStockQuery->whereHas('variations', function ($varQ) use ($remoteQ) {
                                $varQ->where('is_active', true)
                                    ->where(function ($subVarQ) use ($remoteQ) {
                                        $subVarQ->where('stock_quantity', '>', 0);

                                        if ($remoteQ === 2 || $remoteQ === 3) {
                                            $subVarQ->orWhere(function ($remoteVarQ) {
                                                $remoteVarQ->whereNotNull('remote_stock_quantity')
                                                    ->where('remote_stock_quantity', '!=', '0')
                                                    ->where('remote_stock_quantity', '!=', '')
                                                    ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                                            })
                                                ->orWhere(function ($fastRemoteVarQ) {
                                                    $fastRemoteVarQ->whereNotNull('fast_remote_stock_quantity')
                                                        ->where('fast_remote_stock_quantity', '!=', '0')
                                                        ->where('fast_remote_stock_quantity', '!=', '')
                                                        ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                                });
                                        }
                                    });
                            });

                            // Вариант 2b: Все вариации без остатка, но товар имеет is_preorder = 1
                            $variationsStockQuery->orWhere(function ($preorderVariationsQuery) use ($remoteQ) {
                                $preorderVariationsQuery->where(function ($preorderCheck) {
                                    $preorderCheck->where('is_preorder', '=', 1)
                                        ->orWhere('is_preorder', '=', true);
                                })
                                    ->whereDoesntHave('variations', function ($varQ) use ($remoteQ) {
                                        $varQ->where('is_active', true)
                                            ->where(function ($subVarQ) use ($remoteQ) {
                                                $subVarQ->where('stock_quantity', '>', 0);

                                                if ($remoteQ === 2 || $remoteQ === 3) {
                                                    $subVarQ->orWhere(function ($remoteVarQ) {
                                                        $remoteVarQ->whereNotNull('remote_stock_quantity')
                                                            ->where('remote_stock_quantity', '!=', '0')
                                                            ->where('remote_stock_quantity', '!=', '')
                                                            ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                                                    })
                                                        ->orWhere(function ($fastRemoteVarQ) {
                                                            $fastRemoteVarQ->whereNotNull('fast_remote_stock_quantity')
                                                                ->where('fast_remote_stock_quantity', '!=', '0')
                                                                ->where('fast_remote_stock_quantity', '!=', '')
                                                                ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                                        });
                                                }
                                            });
                                    });
                            });

                            // Вариант 2c: Все вариации без остатка, но товар имеет is_show = 1
                            $variationsStockQuery->orWhere(function ($showVariationsQuery) use ($remoteQ) {
                                $showVariationsQuery->where('is_show', '=', 1)
                                    ->whereDoesntHave('variations', function ($varQ) use ($remoteQ) {
                                        $varQ->where('is_active', true)
                                            ->where(function ($subVarQ) use ($remoteQ) {
                                                $subVarQ->where('stock_quantity', '>', 0);

                                                if ($remoteQ === 2 || $remoteQ === 3) {
                                                    $subVarQ->orWhere(function ($remoteVarQ) {
                                                        $remoteVarQ->whereNotNull('remote_stock_quantity')
                                                            ->where('remote_stock_quantity', '!=', '0')
                                                            ->where('remote_stock_quantity', '!=', '')
                                                            ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                                                    })
                                                        ->orWhere(function ($fastRemoteVarQ) {
                                                            $fastRemoteVarQ->whereNotNull('fast_remote_stock_quantity')
                                                                ->where('fast_remote_stock_quantity', '!=', '0')
                                                                ->where('fast_remote_stock_quantity', '!=', '')
                                                                ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                                        });
                                                }
                                            });
                                    });
                            });
                        });
                });
            });
        }
    }

    /**
     * Применить пользовательскую фильтрацию по остаткам
     */
    private function applyCustomStockFilter($query, $stockFilter)
    {
        $shopRemoteQ = Setting::where('key', 'shop_remote_q')->first();
        $remoteQ = $shopRemoteQ ? (int) $shopRemoteQ->value : 1;

        if ($stockFilter === 'in_stock') {
            // Только товары в наличии
            // Если у товара есть вариации, остатки основного товара не учитываются
            // Товар в наличии, если:
            // 1. (Нет вариаций И остаток основного товара > 0) ИЛИ
            // 2. (Есть вариации И есть вариации с остатком)
            $query->where(function ($mainQuery) use ($remoteQ) {
                // Вариант 1: Нет вариаций И (остаток основного товара > 0 ИЛИ остаток на у/с не пустой ИЛИ остаток на у/с быстрый не пустой)
                $mainQuery->where(function ($noVariationsQuery) {
                    $noVariationsQuery->whereDoesntHave('variations')
                        ->where(function ($stockQuery) {
                            $stockQuery->where('stock_quantity', '>', 0)
                                ->orWhere(function ($remoteCondition) {
                                    $remoteCondition->whereNotNull('remote_stock_quantity')
                                        ->where('remote_stock_quantity', '!=', '0')
                                        ->where('remote_stock_quantity', '!=', '')
                                        ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                                })
                                ->orWhere(function ($fastRemoteCondition) {
                                    $fastRemoteCondition->whereNotNull('fast_remote_stock_quantity')
                                        ->where('fast_remote_stock_quantity', '!=', '0')
                                        ->where('fast_remote_stock_quantity', '!=', '')
                                        ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                });
                        });
                });

                // Вариант 2: Есть вариации И есть вариации с остатком
                $mainQuery->orWhere(function ($hasVariationsQuery) use ($remoteQ) {
                    $hasVariationsQuery->whereHas('variations')
                        ->whereHas('variations', function ($varQ) use ($remoteQ) {
                            $varQ->where(function ($subVarQ) use ($remoteQ) {
                                $subVarQ->where('stock_quantity', '>', 0);
                                if ($remoteQ === 2 || $remoteQ === 3) {
                                    $subVarQ->orWhere(function ($remoteVarQ) {
                                        $remoteVarQ->whereNotNull('remote_stock_quantity')
                                            ->where('remote_stock_quantity', '!=', '0')
                                            ->where('remote_stock_quantity', '!=', '')
                                            ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                                    })
                                        ->orWhere(function ($fastRemoteVarQ) {
                                            $fastRemoteVarQ->whereNotNull('fast_remote_stock_quantity')
                                                ->where('fast_remote_stock_quantity', '!=', '0')
                                                ->where('fast_remote_stock_quantity', '!=', '')
                                                ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                        });
                                }
                            });
                        });
                });
            });
        } elseif ($stockFilter === 'with_stock') {
            // Фильтр для режима 4: показывать только товары с остатком или специальные товары
            $query->where(function ($mainQuery) use ($remoteQ) {
                // Условие 1: Товары БЕЗ вариаций
                $mainQuery->where(function ($noVariationsQuery) use ($remoteQ) {
                    $noVariationsQuery->whereDoesntHave('variations')
                        ->where(function ($stockQuery) use ($remoteQ) {
                            // Локальный остаток > 0
                            $stockQuery->where('stock_quantity', '>', 0);

                            // ИЛИ удаленный остаток (если учитываем)
                            if ($remoteQ === 2 || $remoteQ === 3) {
                                $stockQuery->orWhere(function ($remoteCondition) {
                                    $remoteCondition->whereNotNull('remote_stock_quantity')
                                        ->where('remote_stock_quantity', '!=', '0')
                                        ->where('remote_stock_quantity', '!=', '')
                                        ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                                })
                                    ->orWhere(function ($fastRemoteCondition) {
                                        $fastRemoteCondition->whereNotNull('fast_remote_stock_quantity')
                                            ->where('fast_remote_stock_quantity', '!=', '0')
                                            ->where('fast_remote_stock_quantity', '!=', '')
                                            ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                    });
                            }

                            // ИЛИ предзаказы (is_preorder = 1) с нулевым остатком
                            $stockQuery->orWhere(function ($preorderCondition) use ($remoteQ) {
                                $preorderCondition->where('stock_quantity', '<=', 0)
                                    ->where(function ($preorderSubCondition) {
                                        $preorderSubCondition->where('is_preorder', '=', 1)
                                            ->orWhere('is_preorder', '=', true);
                                    });

                                // Проверяем, что удаленный остаток тоже пустой (если учитываем)
                                if ($remoteQ === 2 || $remoteQ === 3) {
                                    $preorderCondition->where(function ($remoteEmptyCondition) {
                                        $remoteEmptyCondition->whereNull('remote_stock_quantity')
                                            ->orWhere('remote_stock_quantity', '=', '0')
                                            ->orWhere('remote_stock_quantity', '=', '')
                                            ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
                                    })
                                        ->where(function ($fastRemoteEmptyCondition) {
                                            $fastRemoteEmptyCondition->whereNull('fast_remote_stock_quantity')
                                                ->orWhere('fast_remote_stock_quantity', '=', '0')
                                                ->orWhere('fast_remote_stock_quantity', '=', '')
                                                ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
                                        });
                                }
                            });

                            // ИЛИ товары с is_show = 1 (независимо от остатка)
                            $stockQuery->orWhere('is_show', '=', 1);
                        });
                });

                // Условие 2: Товары С вариациями
                $mainQuery->orWhere(function ($hasVariationsQuery) use ($remoteQ) {
                    $hasVariationsQuery->whereHas('variations', function ($varQ) {
                        $varQ->where('is_active', true);
                    })
                        ->where(function ($variationsStockQuery) use ($remoteQ) {
                            // Вариант 2a: Есть хотя бы одна вариация с остатком
                            $variationsStockQuery->whereHas('variations', function ($varQ) use ($remoteQ) {
                                $varQ->where('is_active', true)
                                    ->where(function ($subVarQ) use ($remoteQ) {
                                        $subVarQ->where('stock_quantity', '>', 0);

                                        if ($remoteQ === 2 || $remoteQ === 3) {
                                            $subVarQ->orWhere(function ($remoteVarQ) {
                                                $remoteVarQ->whereNotNull('remote_stock_quantity')
                                                    ->where('remote_stock_quantity', '!=', '0')
                                                    ->where('remote_stock_quantity', '!=', '')
                                                    ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                                            })
                                                ->orWhere(function ($fastRemoteVarQ) {
                                                    $fastRemoteVarQ->whereNotNull('fast_remote_stock_quantity')
                                                        ->where('fast_remote_stock_quantity', '!=', '0')
                                                        ->where('fast_remote_stock_quantity', '!=', '')
                                                        ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                                });
                                        }
                                    });
                            });

                            // Вариант 2b: Все вариации без остатка, но товар имеет is_preorder = 1
                            $variationsStockQuery->orWhere(function ($preorderVariationsQuery) use ($remoteQ) {
                                $preorderVariationsQuery->where(function ($preorderCheck) {
                                    $preorderCheck->where('is_preorder', '=', 1)
                                        ->orWhere('is_preorder', '=', true);
                                })
                                    ->whereDoesntHave('variations', function ($varQ) use ($remoteQ) {
                                        $varQ->where('is_active', true)
                                            ->where(function ($subVarQ) use ($remoteQ) {
                                                $subVarQ->where('stock_quantity', '>', 0);

                                                if ($remoteQ === 2 || $remoteQ === 3) {
                                                    $subVarQ->orWhere(function ($remoteVarQ) {
                                                        $remoteVarQ->whereNotNull('remote_stock_quantity')
                                                            ->where('remote_stock_quantity', '!=', '0')
                                                            ->where('remote_stock_quantity', '!=', '')
                                                            ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                                                    })
                                                        ->orWhere(function ($fastRemoteVarQ) {
                                                            $fastRemoteVarQ->whereNotNull('fast_remote_stock_quantity')
                                                                ->where('fast_remote_stock_quantity', '!=', '0')
                                                                ->where('fast_remote_stock_quantity', '!=', '')
                                                                ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                                        });
                                                }
                                            });
                                    });
                            });

                            // Вариант 2c: Все вариации без остатка, но товар имеет is_show = 1
                            $variationsStockQuery->orWhere(function ($showVariationsQuery) use ($remoteQ) {
                                $showVariationsQuery->where('is_show', '=', 1)
                                    ->whereDoesntHave('variations', function ($varQ) use ($remoteQ) {
                                        $varQ->where('is_active', true)
                                            ->where(function ($subVarQ) use ($remoteQ) {
                                                $subVarQ->where('stock_quantity', '>', 0);

                                                if ($remoteQ === 2 || $remoteQ === 3) {
                                                    $subVarQ->orWhere(function ($remoteVarQ) {
                                                        $remoteVarQ->whereNotNull('remote_stock_quantity')
                                                            ->where('remote_stock_quantity', '!=', '0')
                                                            ->where('remote_stock_quantity', '!=', '')
                                                            ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                                                    })
                                                        ->orWhere(function ($fastRemoteVarQ) {
                                                            $fastRemoteVarQ->whereNotNull('fast_remote_stock_quantity')
                                                                ->where('fast_remote_stock_quantity', '!=', '0')
                                                                ->where('fast_remote_stock_quantity', '!=', '')
                                                                ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                                        });
                                                }
                                            });
                                    });
                            });
                        });
                });
            });
        } elseif ($stockFilter === 'out_of_stock') {
            // Только товары не в наличии
            // Если у товара есть вариации, остатки основного товара не учитываются
            // Товар не в наличии, если:
            // 1. (Нет вариаций И остаток основного товара = 0) ИЛИ
            // 2. (Есть вариации И нет вариаций с остатком)
            $query->where(function ($mainQuery) use ($remoteQ) {
                // Вариант 1: Нет вариаций И остаток основного товара = 0 И остаток на у/с пустой И остаток на у/с быстрый пустой
                $mainQuery->where(function ($noVariationsQuery) {
                    $noVariationsQuery->whereDoesntHave('variations')
                        ->where('stock_quantity', '=', 0)
                        ->where(function ($remoteCondition) {
                            $remoteCondition->whereNull('remote_stock_quantity')
                                ->orWhere('remote_stock_quantity', '=', '0')
                                ->orWhere('remote_stock_quantity', '=', '')
                                ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
                        })
                        ->where(function ($fastRemoteCondition) {
                            $fastRemoteCondition->whereNull('fast_remote_stock_quantity')
                                ->orWhere('fast_remote_stock_quantity', '=', '0')
                                ->orWhere('fast_remote_stock_quantity', '=', '')
                                ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
                        });
                });

                // Вариант 2: Есть вариации И нет вариаций с остатком
                $mainQuery->orWhere(function ($hasVariationsQuery) use ($remoteQ) {
                    $hasVariationsQuery->whereHas('variations')
                        ->whereDoesntHave('variations', function ($varQ) use ($remoteQ) {
                            $varQ->where(function ($subVarQ) use ($remoteQ) {
                                $subVarQ->where('stock_quantity', '>', 0);
                                if ($remoteQ === 2 || $remoteQ === 3) {
                                    $subVarQ->orWhere(function ($remoteVarQ) {
                                        $remoteVarQ->whereNotNull('remote_stock_quantity')
                                            ->where('remote_stock_quantity', '!=', '0')
                                            ->where('remote_stock_quantity', '!=', '')
                                            ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                                    })
                                        ->orWhere(function ($fastRemoteVarQ) {
                                            $fastRemoteVarQ->whereNotNull('fast_remote_stock_quantity')
                                                ->where('fast_remote_stock_quantity', '!=', '0')
                                                ->where('fast_remote_stock_quantity', '!=', '')
                                                ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                        });
                                }
                            });
                        });
                });
            });
        } elseif ($stockFilter === 'preorder') {
            // Только товары для предзаказа (остатки = 0 и is_preorder = 1)
            $query->where(function ($mainQuery) use ($remoteQ) {
                $mainQuery->where(function ($preorderCondition) {
                    $preorderCondition->where('is_preorder', '=', 1)
                        ->orWhere('is_preorder', '=', true);
                });
                $mainQuery->where('stock_quantity', '=', 0);
                if ($remoteQ === 2 || $remoteQ === 3) {
                    $mainQuery->where(function ($remoteCondition) {
                        $remoteCondition->whereNull('remote_stock_quantity')
                            ->orWhere('remote_stock_quantity', '=', '0')
                            ->orWhere('remote_stock_quantity', '=', '')
                            ->orWhereRaw('LENGTH(TRIM(remote_stock_quantity)) = 0');
                    })
                        ->where(function ($fastRemoteCondition) {
                            $fastRemoteCondition->whereNull('fast_remote_stock_quantity')
                                ->orWhere('fast_remote_stock_quantity', '=', '0')
                                ->orWhere('fast_remote_stock_quantity', '=', '')
                                ->orWhereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) = 0');
                        });
                }
                $mainQuery->whereDoesntHave('variations', function ($varQ) use ($remoteQ) {
                    $varQ->where(function ($subVarQ) use ($remoteQ) {
                        $subVarQ->where('stock_quantity', '>', 0);
                        if ($remoteQ === 2 || $remoteQ === 3) {
                            $subVarQ->orWhere(function ($remoteVarQ) {
                                $remoteVarQ->whereNotNull('remote_stock_quantity')
                                    ->where('remote_stock_quantity', '!=', '0')
                                    ->where('remote_stock_quantity', '!=', '')
                                    ->whereRaw('LENGTH(TRIM(remote_stock_quantity)) > 0');
                            })
                                ->orWhere(function ($fastRemoteVarQ) {
                                    $fastRemoteVarQ->whereNotNull('fast_remote_stock_quantity')
                                        ->where('fast_remote_stock_quantity', '!=', '0')
                                        ->where('fast_remote_stock_quantity', '!=', '')
                                        ->whereRaw('LENGTH(TRIM(fast_remote_stock_quantity)) > 0');
                                });
                        }
                    });
                });
            });
        }
        // Если stockFilter === 'all', не применяем фильтрацию (показываем все)
    }

    /**
     * Переключить избранное для товара
     */
    public function toggleFavorite(Request $request): JsonResponse
    {
        try {
            $goodId = $request->input('good_id');

            if (! $goodId) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID товара не указан',
                ], 400);
            }

            // Получаем пользователя из токена
            $user = auth('sanctum')->user();
            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не авторизован',
                ], 401);
            }

            // Проверяем, существует ли товар
            $good = \App\Models\ShopGood::find($goodId);
            if (! $good) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден',
                ], 404);
            }

            // Проверяем, есть ли уже товар в избранном
            $existingFavorite = \App\Models\ShopFavorite::where('user_id', $user->id)
                ->where('good_id', $goodId)
                ->first();

            if ($existingFavorite) {
                // Удаляем из избранного
                $existingFavorite->delete();

                return response()->json([
                    'success' => true,
                    'is_favorite' => false,
                    'good_name' => $good->name,
                    'message' => 'Товар удален из избранного',
                ]);
            } else {
                // Добавляем в избранное
                \App\Models\ShopFavorite::create([
                    'user_id' => $user->id,
                    'good_id' => $goodId,
                ]);

                return response()->json([
                    'success' => true,
                    'is_favorite' => true,
                    'good_name' => $good->name,
                    'message' => 'Товар добавлен в избранное',
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Ошибка переключения избранного: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка сервера: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить список товаров
     */
    public function index(Request $request): JsonResponse
    {
        try {

            $query = ShopGood::with([
                'variations' => function ($query) {
                    $query->with([
                        'images' => function ($q) {
                            $q->orderBy('sort_order');
                        },
                        'attributeValues.attribute'
                    ]);
                },
                'images' => function ($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'videos' => function ($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'properties' => function ($query) {
                    // Поддержка обеих схем pivot: shop_property_value_id и/или value
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug', 'shop_properties.show_on_site')
                        ->withPivot(['shop_property_value_id']);
                },
                'categories' => function ($query) {
                    $query->select('shop_categories.id', 'shop_categories.name', 'shop_categories.slug', 'shop_categories.image', 'shop_categories.icon');
                },
                'brands' => function ($query) {
                    $query->select('shop_brands.id', 'shop_brands.name', 'shop_brands.slug', 'shop_brands.logo');
                },
                'label' => function ($query) {
                    $query->select('shop_labels.id', 'shop_labels.name', 'shop_labels.color');
                },
            ])
                ->where('is_active', true);

            // Переменная для хранения расширенных категорий для проверки товаров
            $allCategoryIds = [];

            // Фильтрация по категориям (с рекурсивным поиском в подкатегориях)
            // Приоритет: если есть categories[] или categories, используем их (игнорируем category_id)
            // Если есть только category_id, используем его
            $categoryIds = null;

            // Проверяем все возможные варианты передачи категорий
            // Сначала проверяем categories[] (может быть в query string как categories[]=1&categories[]=2)
            if ($request->has('categories[]')) {
                $categoryIds = $request->input('categories[]');
            }

            // Затем проверяем categories (может быть строкой через запятую или массивом)
            if (! $categoryIds && $request->has('categories')) {
                $categoryIds = $request->input('categories');
                // Если передан строкой через запятую, преобразуем в массив
                if (is_string($categoryIds)) {
                    $categoryIds = array_filter(explode(',', $categoryIds));
                }
            }

            // Также проверяем все параметры запроса на наличие categories
            if (! $categoryIds) {
                $allParams = $request->all();
                foreach ($allParams as $key => $value) {
                    if (strpos($key, 'categories') !== false && $key !== 'category_id') {
                        if (is_array($value)) {
                            $categoryIds = $value;
                        } elseif (is_string($value) && ! empty($value)) {
                            $categoryIds = array_filter(explode(',', $value));
                        }
                        break;
                    }
                }
            }

            // Если есть множественные категории, используем их
            if (is_array($categoryIds) && ! empty($categoryIds)) {
                // Преобразуем в массив целых чисел
                $categoryIds = array_map('intval', $categoryIds);
                $categoryIds = array_filter($categoryIds);

                if (! empty($categoryIds)) {
                    // Получаем все дочерние категории рекурсивно
                    $allCategoryIds = \App\Models\ShopCategory::getAllDescendantIds($categoryIds);

                    // Ищем товары, у которых есть хотя бы одна категория из списка (включая подкатегории)
                    // whereHas с whereIn находит товары, у которых есть хотя бы одна категория из списка
                    $query->whereHas('categories', function ($q) use ($allCategoryIds) {
                        $q->whereIn('shop_categories.id', $allCategoryIds);
                    });

                }
            }
            // Если нет множественных категорий, проверяем category_id
            elseif ($request->has('category_id')) {
                $categoryId = (int) $request->input('category_id');
                if ($categoryId > 0) {
                    // Получаем все дочерние категории рекурсивно
                    $allCategoryIds = \App\Models\ShopCategory::getAllDescendantIds([$categoryId]);

                    // Ищем товары, у которых есть хотя бы одна категория из списка (включая подкатегории)
                    $query->whereHas('categories', function ($q) use ($allCategoryIds) {
                        $q->whereIn('shop_categories.id', $allCategoryIds);
                    });
                }
            }

            // Фильтрация по бренду
            if ($request->has('brand_id')) {
                $query->whereHas('brands', function ($q) use ($request) {
                    $q->where('shop_brands.id', $request->input('brand_id'));
                });
            }

            // Фильтрация по поставщику (текстовое поле)
            if ($request->has('supplier')) {
                $supplier = $request->input('supplier');
                Log::info('Supplier filter received', ['supplier' => $supplier, 'trimmed' => trim($supplier), 'is_empty' => trim($supplier) === '']);

                if (trim($supplier) === '') {
                    // Фильтр "Без поставщиков" - товары где supplier пустой или null
                    Log::info('Applying "no supplier" filter');

                    // Для отладки - давайте посмотрим SQL запрос
                    $query->where(function ($q) {
                        $q->whereNull('supplier')
                            ->orWhere('supplier', '');
                    });

                    // Временно добавим логирование количества найденных товаров
                    $countBefore = $query->count();
                    Log::info('Goods count with no supplier filter', ['count' => $countBefore]);
                } elseif ($supplier && trim($supplier) !== '') {
                    // Фильтр по конкретному поставщику
                    Log::info('Applying specific supplier filter', ['supplier' => trim($supplier)]);
                    $query->where('supplier', trim($supplier));
                }
            }

            // Фильтр "Без поставщиков" (альтернативный параметр для совместимости)
            if ($request->has('supplier_empty') && $request->input('supplier_empty') == '1') {
                Log::info('Applying "no supplier" filter via supplier_empty parameter');

                $query->where(function ($q) {
                    $q->whereNull('supplier')
                        ->orWhere('supplier', '');
                });

                $countBefore = $query->count();
                Log::info('Goods count with no supplier filter (supplier_empty)', ['count' => $countBefore]);
            }

            // Фильтрация по множественным поставщикам
            if ($request->has('suppliers')) {
                $supplierIds = $request->input('suppliers');
                if (is_array($supplierIds) && ! empty($supplierIds)) {
                    Log::info('Applying multiple suppliers filter', ['suppliers' => $supplierIds]);
                    $query->whereIn('supplier', $supplierIds);
                }
            }

            // Фильтрация по множественным брендам
            if ($request->has('brands')) {
                $brandIds = $request->input('brands');
                if (is_array($brandIds) && ! empty($brandIds)) {
                    $query->whereHas('brands', function ($q) use ($brandIds) {
                        $q->whereIn('shop_brands.id', $brandIds);
                    });
                }
            }

            // Фильтрация по множественным брендам (альтернативный формат brands[])
            if ($request->has('brands[]')) {
                $brandIds = $request->input('brands[]');
                if (is_array($brandIds) && ! empty($brandIds)) {
                    $query->whereHas('brands', function ($q) use ($brandIds) {
                        $q->whereIn('shop_brands.id', $brandIds);
                    });
                }
            }

            // Также проверяем все параметры запроса на наличие brands
            if (! $request->has('brands') && ! $request->has('brands[]')) {
                $allParams = $request->all();
                foreach ($allParams as $key => $value) {
                    if (strpos($key, 'brands') !== false) {
                        $brandIds = is_array($value) ? $value : (is_string($value) ? array_filter(explode(',', $value)) : []);
                        if (! empty($brandIds)) {
                            $query->whereHas('brands', function ($q) use ($brandIds) {
                                $q->whereIn('shop_brands.id', $brandIds);
                            });
                        }
                        break;
                    }
                }
            }

            // Поиск по каталогу: все слова запроса должны встретиться в name/sku/sku вариаций
            if ($request->has('search')) {
                $search = trim((string) $request->input('search'));
                $searchLower = mb_strtolower($search);

                $words = preg_split('/\s+/', $searchLower);
                $words = array_values(array_filter(array_map('trim', $words), function ($w) {
                    return mb_strlen($w) >= 2;
                }));

                if (count($words) === 0) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->where(function ($q) use ($words) {
                        foreach ($words as $w) {
                            $q->where(function ($qw) use ($w) {
                                $qw->whereRaw('LOWER(name) LIKE ?', ['%'.$w.'%'])
                                    ->orWhereRaw('LOWER(sku) LIKE ?', ['%'.$w.'%'])
                                    ->orWhereHas('variations', function ($varQuery) use ($w) {
                                        $varQuery->where('is_active', true)
                                            ->whereRaw('LOWER(sku) LIKE ?', ['%'.$w.'%']);
                                    });
                            });
                        }
                    });
                }
            }

            // Фильтрация по цене (с учетом минимальной цены вариаций)
            if ($request->has('min_price') || $request->has('max_price')) {
                $minPrice = $request->has('min_price') ? (float) $request->input('min_price') : null;
                $maxPrice = $request->has('max_price') ? (float) $request->input('max_price') : null;
                $includeZeroPrice = $request->has('include_zero_price') && $request->input('include_zero_price');

                // Если min_price = 0 и include_zero_price не выбран, устанавливаем min_price = 0.01
                // чтобы исключить товары с ценой 0
                if ($minPrice !== null && $minPrice == 0 && ! $includeZeroPrice) {
                    $minPrice = 0.01;
                }

                $query->where(function ($priceQuery) use ($minPrice, $maxPrice, $includeZeroPrice) {
                    // Логика фильтрации по цене:
                    // 1. Для товаров с вариациями: используем минимальную и максимальную цену вариаций
                    // 2. Для товаров без вариаций: используем цену основного товара
                    // 3. Если include_zero_price не выбран, исключаем товары с ценой 0

                    // Проверка минимальной цены
                    if ($minPrice !== null) {
                        $priceQuery->whereRaw('(
                            CASE 
                                WHEN EXISTS (
                                    SELECT 1 FROM shop_good_variations 
                                    WHERE shop_good_variations.good_id = shop_goods.id 
                                    AND shop_good_variations.is_active = 1
                                ) THEN
                                    -- Для товаров с вариациями: минимальная цена среди вариаций с ценой > 0
                                    COALESCE((
                                        SELECT MIN(COALESCE(sale_price, price, 999999999))
                                        FROM shop_good_variations
                                        WHERE shop_good_variations.good_id = shop_goods.id
                                        AND shop_good_variations.is_active = 1
                                        AND (COALESCE(sale_price, price, 0) > 0)
                                    ), 999999999)
                                ELSE
                                    -- Для товаров без вариаций: цена основного товара
                                    COALESCE(shop_goods.sale_price, shop_goods.price, 999999999)
                            END
                        ) >= ?', [$minPrice]);
                    }

                    // Проверка максимальной цены
                    if ($maxPrice !== null) {
                        $priceQuery->whereRaw('(
                            CASE 
                                WHEN EXISTS (
                                    SELECT 1 FROM shop_good_variations 
                                    WHERE shop_good_variations.good_id = shop_goods.id 
                                    AND shop_good_variations.is_active = 1
                                ) THEN
                                    -- Для товаров с вариациями: максимальная цена среди вариаций с ценой > 0
                                    COALESCE((
                                        SELECT MAX(COALESCE(sale_price, price, 0))
                                        FROM shop_good_variations
                                        WHERE shop_good_variations.good_id = shop_goods.id
                                        AND shop_good_variations.is_active = 1
                                        AND (COALESCE(sale_price, price, 0) > 0)
                                    ), 0)
                                ELSE
                                    -- Для товаров без вариаций: цена основного товара
                                    COALESCE(shop_goods.sale_price, shop_goods.price, 0)
                            END
                        ) <= ?', [$maxPrice]);
                    }

                    // Если include_zero_price не выбран, исключаем товары с ценой 0
                    if (! $includeZeroPrice) {
                        $priceQuery->whereRaw('(
                            CASE 
                                WHEN EXISTS (
                                    SELECT 1 FROM shop_good_variations 
                                    WHERE shop_good_variations.good_id = shop_goods.id 
                                    AND shop_good_variations.is_active = 1
                                ) THEN
                                    -- Для товаров с вариациями: должна быть хотя бы одна вариация с ценой > 0
                                    EXISTS (
                                        SELECT 1 FROM shop_good_variations
                                        WHERE shop_good_variations.good_id = shop_goods.id
                                        AND shop_good_variations.is_active = 1
                                        AND (COALESCE(sale_price, price, 0) > 0)
                                    )
                                ELSE
                                    -- Для товаров без вариаций: цена основного товара должна быть > 0
                                    (COALESCE(shop_goods.sale_price, shop_goods.price, 0) > 0)
                            END
                        )');
                    }
                });
            }

            // Фильтрация по товарам с неопределенной ценой (цена = 0)
            // Если передан параметр include_zero_price и он true, показываем ТОЛЬКО товары с ценой 0
            // Иначе проверяем параметр hidden_0_price из настроек сайта
            $includeZeroPrice = $request->has('include_zero_price') && $request->input('include_zero_price');

            if ($includeZeroPrice) {
                // Показываем ТОЛЬКО товары с ценой 0
                $query->where(function ($q) {
                    // Товары, у которых и price = 0, и sale_price = 0 (или null)
                    $q->where(function ($priceQ) {
                        $priceQ->where('price', '<=', 0)
                            ->where(function ($salePriceQ) {
                                $salePriceQ->where('sale_price', '<=', 0)
                                    ->orWhereNull('sale_price');
                            });
                    })
                    // И нет активных вариаций с ценой > 0
                    // (если есть вариации, они все должны иметь цену <= 0)
                        ->whereDoesntHave('variations', function ($varQ) {
                            $varQ->where('is_active', true)
                                ->where(function ($varPriceQ) {
                                    $varPriceQ->where('price', '>', 0)
                                        ->orWhere('sale_price', '>', 0);
                                });
                        });
                });
            } else {
                // Получаем параметр hidden_0_price из настроек
                $hidden0PriceSetting = Setting::where('key', 'hidden_0_price')->first();
                $hidden0Price = $hidden0PriceSetting ? (int) $hidden0PriceSetting->value : 0;

                // Если hidden_0_price = 1, не показываем товары с ценой 0
                if ($hidden0Price === 1) {
                    $query->where(function ($q) {
                        // Показываем товары, у которых цена > 0 (проверяем и price, и sale_price)
                        $q->where(function ($priceQ) {
                            $priceQ->where('price', '>', 0)
                                ->orWhere('sale_price', '>', 0);
                        })
                        // ИЛИ есть вариации с ценой > 0
                            ->orWhereHas('variations', function ($varQ) {
                                $varQ->where('is_active', true)
                                    ->where(function ($varPriceQ) {
                                        $varPriceQ->where('price', '>', 0)
                                            ->orWhere('sale_price', '>', 0);
                                    });
                            });
                    });
                }
            }

            // Фильтрация по атрибутам вариаций
            if ($request->has('attributes')) {
                $attributes = $request->input('attributes');
                // Поддержка формата attributes[id][]=value
                if (is_array($attributes) && ! empty($attributes)) {
                    foreach ($attributes as $attributeId => $values) {
                        if (is_array($values) && ! empty($values)) {
                            // Фильтруем товары, у которых есть вариация с указанным атрибутом и одним из выбранных значений
                            $query->whereHas('variations', function ($q) use ($attributeId, $values) {
                                $q->where('is_active', true)
                                    ->whereHas('attributeValues', function ($avQ) use ($attributeId, $values) {
                                        $avQ->where('attribute_id', $attributeId)
                                            ->whereIn('value', $values);
                                    });
                            });
                        }
                    }
                }
            }

            // Фильтрация по свойствам
            if ($request->has('properties')) {
                $properties = $request->input('properties');
                if (is_array($properties) && ! empty($properties)) {
                    foreach ($properties as $propertyId => $values) {
                        if (is_array($values) && ! empty($values)) {
                            // Получаем ID значений из таблицы shop_property_values по их строковым значениям
                            $valueIds = \App\Models\Shop\PropertyValue::where('property_id', $propertyId)
                                ->whereIn('value', $values)
                                ->pluck('id')
                                ->toArray();

                            if (! empty($valueIds)) {
                                // Фильтруем товары, у которых есть свойство с указанным ID и одним из выбранных значений
                                $query->whereHas('properties', function ($q) use ($propertyId, $valueIds) {
                                    $q->where('shop_properties.id', $propertyId)
                                        ->whereIn('shop_good_properties.shop_property_value_id', $valueIds);
                                });
                            }
                        }
                    }
                }
            }

            // Исключение товара по ID
            if ($request->has('exclude_id')) {
                $excludeId = $request->input('exclude_id');
                if ($excludeId) {
                    $query->where('id', '!=', $excludeId);
                }
            }

            // Сортировка
            if ($request->has('random') && $request->input('random')) {
                $query->inRandomOrder();
            } else {
                $sortBy = $request->input('sort_by', 'created_at');
                $sortOrder = $request->input('sort_order', 'desc');
                
                if ($sortBy === 'price') {
                    // Сложная логика вычисления эффективной цены (учитываем демпинг, акции и вариации)
                    $effectivePriceSql = "(CASE 
                        WHEN EXISTS (SELECT 1 FROM shop_good_variations WHERE good_id = shop_goods.id AND is_active = 1) THEN
                            (SELECT MIN(
                                CASE 
                                    WHEN show_demping = 1 AND demping_price > 0 THEN demping_price
                                    WHEN sale_price > 0 AND sale_price < price THEN sale_price
                                    ELSE price
                                END
                             ) FROM shop_good_variations WHERE good_id = shop_goods.id AND is_active = 1)
                        ELSE
                            (CASE 
                                WHEN show_demping = 1 AND demping_price > 0 THEN demping_price
                                WHEN sale_price > 0 AND sale_price < price THEN sale_price
                                ELSE price
                            END)
                    END)";
                    
                    // Товары с ценой 0 всегда выводим в конце при любой сортировке
                    $query->orderByRaw("(CASE WHEN ($effectivePriceSql > 0) THEN 0 ELSE 1 END) ASC");
                    $query->orderByRaw("$effectivePriceSql $sortOrder");
                } else {
                    $query->orderBy($sortBy, $sortOrder);
                }
            }

            // Фильтрация по остаткам (shop_show_good_mode)
            // Если передан параметр stock_filter, используем его вместо автоматической фильтрации
            if ($request->has('stock_filter')) {
                $stockFilter = $request->input('stock_filter');
                // Если stock_filter = 'all', не применяем фильтрацию
                if ($stockFilter !== 'all') {
                    $this->applyCustomStockFilter($query, $stockFilter);
                }
                // Если stock_filter = 'all', фильтрация не применяется (показываем все товары)
            } else {
                // Если stock_filter не передан, применяем автоматическую фильтрацию по настройкам
                $this->applyStockFilter($query);
            }

            // Пагинация
            $perPage = $request->input('limit', 20);
            $goods = $query->paginate($perPage);

            // Получаем информацию о пользователе для проверки избранного
            $token = request()->bearerToken();
            $user = null;

            if ($token) {
                // Ищем пользователя по токену
                $user = \App\Models\User::where('remember_token', $token)->first();
                if (! $user) {
                    $personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
                    if ($personalAccessToken) {
                        $user = $personalAccessToken->tokenable;
                    }
                }
            }

            // Добавляем image_url, is_favorite и обрабатываем характеристики для обратной совместимости
            $collection = $goods->getCollection();
            
            // Собираем все ID значений свойств для оптимизации (один запрос для получения всех строк)
            $propertyValueIds = $collection->flatMap(function ($good) {
                return $good->properties->pluck('pivot.shop_property_value_id');
            })->unique()->filter()->toArray();
            
            $propertyValuesMap = [];
            if (! empty($propertyValueIds)) {
                $propertyValuesMap = \App\Models\Shop\PropertyValue::whereIn('id', $propertyValueIds)
                    ->pluck('value', 'id')
                    ->toArray();
            }

            $collection->transform(function ($good) use ($user, $propertyValuesMap) {
                if ($good->images && $good->images->count() > 0) {
                    // Ищем главное изображение
                    $mainImage = $good->images->where('is_main', true)->first();
                    if (! $mainImage) {
                        // Если главного нет, берем первое
                        $mainImage = $good->images->first();
                    }
                    if ($mainImage) {
                        // Добавляем ведущий слэш если его нет
                        $imagePath = $mainImage->file_path;
                        if ($imagePath && ! str_starts_with($imagePath, '/')) {
                            $imagePath = '/'.$imagePath;
                        }
                        $good->image_url = $imagePath;
                    }
                }

                // Проверяем, находится ли товар в избранном у текущего пользователя
                $isFavorite = false;
                if ($user) {
                    $isFavorite = \App\Models\ShopFavorite::where('user_id', $user->id)
                        ->where('good_id', $good->id)
                        ->exists();
                }
                $good->is_favorite = $isFavorite;

                // Прикрепляем текстовые значения характеристик напрямую к объекту property
                // Это решит проблему с асинхронной загрузкой на фронтенде
                $good->properties->each(function ($prop) use ($propertyValuesMap) {
                    $valId = $prop->pivot->shop_property_value_id ?? null;
                    if ($valId && isset($propertyValuesMap[$valId])) {
                        $prop->value = $propertyValuesMap[$valId];
                    }
                });

                // Форматируем данные вариаций для SsGoodCard.vue (приводим к ожидаемому формату свойств)
                if ($good->relationLoaded('variations')) {
                    $good->variations->each(function ($variation) {
                        if ($variation->relationLoaded('attributeValues')) {
                            $variation->properties = $variation->attributeValues->map(function ($av) {
                                return [
                                    'id' => $av->id,
                                    'value' => $av->value,
                                    'property' => $av->relationLoaded('attribute') && $av->attribute ? [
                                        'id' => $av->attribute->id,
                                        'name' => $av->attribute->name,
                                        'slug' => $av->attribute->slug,
                                    ] : null,
                                ];
                            })->toArray();
                        }
                    });
                }

                return $good;
            });

            return response()->json([
                'success' => true,
                'data' => $goods->items(),
                'pagination' => [
                    'current_page' => $goods->currentPage(),
                    'last_page' => $goods->lastPage(),
                    'per_page' => $goods->perPage(),
                    'total' => $goods->total(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('ShopGoodsController::index - Ошибка', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_params' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения списка товаров: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить значения характеристик по их ID
     */
    public function getPropertyValues(Request $request): JsonResponse
    {
        try {
            $ids = $request->input('ids', '');

            if (empty($ids)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указаны ID значений характеристик',
                ], 400);
            }

            // Преобразуем строку в массив
            $idsArray = is_string($ids) ? explode(',', $ids) : $ids;
            $idsArray = array_filter(array_map('intval', $idsArray));

            if (empty($idsArray)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Некорректные ID значений характеристик',
                ], 400);
            }

            // Получаем значения характеристик
            $propertyValues = \App\Models\Shop\PropertyValue::whereIn('id', $idsArray)
                ->where('is_active', true)
                ->get(['id', 'value'])
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => $propertyValues,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения значений характеристик',
            ], 500);
        }
    }

    /**
     * Получить детальную информацию о товарах по их ID
     */
    public function getGoodsDetails(Request $request): JsonResponse
    {
        try {
            $goodIds = $request->input('good_ids', []);
            $variationIds = $request->input('variation_ids', []);

            if (empty($goodIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указаны ID товаров',
                ], 400);
            }

            // Загружаем товары с вариациями, изображениями, видео и свойствами
            $goods = ShopGood::with([
                'variations' => function ($query) {
                    $query->with([
                        'images' => function ($q) {
                            $q->orderBy('sort_order');
                        },
                        'videos' => function ($q) {
                            $q->orderBy('sort_order');
                        },
                    ]);
                },
                'images' => function ($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'videos' => function ($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'properties' => function ($query) {
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug', 'shop_properties.show_on_site')
                        ->withPivot((function () {
                            $fields = ['shop_property_value_id'];
                            if (\Illuminate\Support\Facades\Schema::hasColumn('shop_good_properties', 'value')) {
                                $fields[] = 'value';
                            }

                            return $fields;
                        })());
                },
                'categories' => function ($query) {
                    $query->select('shop_categories.id', 'shop_categories.name', 'shop_categories.slug', 'shop_categories.image', 'shop_categories.icon');
                },
                'brands' => function ($query) {
                    $query->select('shop_brands.id', 'shop_brands.name', 'shop_brands.slug', 'shop_brands.logo');
                },
                'tags' => function ($query) {
                    $query->select('shop_tags.id', 'shop_tags.name', 'shop_tags.color', 'shop_tags.slug');
                },
            ])
                ->whereIn('id', $goodIds)
                ->where('is_active', true)
                ->get();

            $result = [];

            // Получаем информацию о пользователе для проверки избранного
            $isFavorite = false;
            $token = request()->bearerToken();
            $user = null;

            if ($token) {
                // Ищем пользователя по токену
                $user = \App\Models\User::where('remember_token', $token)->first();
                if (! $user) {
                    $personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
                    if ($personalAccessToken) {
                        $user = $personalAccessToken->tokenable;
                    }
                }
            }

            foreach ($goods as $good) {
                // Получаем главное изображение из связанной таблицы
                $mainImage = null;
                if ($good->images && $good->images->count() > 0) {
                    $mainImg = $good->images->where('is_main', true)->first();
                    if (! $mainImg) {
                        $mainImg = $good->images->first();
                    }
                    if ($mainImg) {
                        $mainImage = $mainImg->file_path;
                    }
                }

                // Проверяем, находится ли товар в избранном у текущего пользователя
                $isFavorite = false;
                if ($user) {
                    $isFavorite = \App\Models\ShopFavorite::where('user_id', $user->id)
                        ->where('good_id', $good->id)
                        ->exists();
                }

                $goodData = [
                    'id' => $good->id,
                    'name' => $good->name,
                    'sku' => $good->sku,
                    'slug' => $good->slug,
                    'price' => $good->price,
                    'sale_price' => $good->sale_price,
                    'demping_price' => $good->demping_price,
                    'show_demping' => $good->show_demping,
                    'is_preorder' => $good->is_preorder,
                    'old_price' => $good->old_price,
                    'image_url' => $mainImage ?: $good->image_url,
                    'images' => $good->images ? $good->images->toArray() : [],
                    'videos' => $good->videos ? $good->videos->toArray() : [],
                    'properties' => $good->properties ? $good->properties->toArray() : [],
                    'categories' => $good->categories ? $good->categories->toArray() : [],
                    'brands' => $good->brands ? $good->brands->toArray() : [],
                    'tags' => $good->tags ? $good->tags->toArray() : [],
                    'variations' => [],
                    'is_favorite' => $isFavorite,
                    // Добавляем поля размеров и веса
                    'weight' => $good->weight,
                    'length' => $good->depth, // В базе данных поле называется depth, но в API возвращаем как length
                    'width' => $good->width,
                    'height' => $good->height,
                    // Добавляем информацию об остатках
                    'stock_quantity' => $good->stock_quantity ?? 0,
                    'remote_stock_quantity' => $good->remote_stock_quantity ?? '',
                    'fast_remote_stock_quantity' => $good->fast_remote_stock_quantity ?? '',
                ];

                // Добавляем вариации с атрибутами
                foreach ($good->variations as $variation) {
                    // Загружаем атрибуты вариации из новой схемы
                    $variationAttributes = [];
                    $variationIds = [$variation->id];

                    $attributeRows = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereIn('vav.variation_id', $variationIds)
                        ->select(
                            'a.id as attribute_id', 'a.name as attribute_name',
                            'av.id as value_id', 'av.value as value_value'
                        )
                        ->get();

                    foreach ($attributeRows as $row) {
                        $variationAttributes[] = [
                            'id' => $row->attribute_id,
                            'name' => $row->attribute_name,
                            'value' => $row->value_value,
                        ];
                    }

                    $goodData['variations'][] = [
                        'id' => $variation->id,
                        'name' => $variation->name,
                        'sku' => $variation->sku,
                        'price' => $variation->price,
                        'sale_price' => $variation->sale_price,
                        'demping_price' => $variation->demping_price,
                        'show_demping' => $variation->show_demping,
                        'old_price' => $variation->old_price,
                        'final_price' => $variation->final_price,
                        'attributes' => $variationAttributes,
                        'is_active' => $variation->is_active,
                        'images' => $variation->images ? $variation->images->toArray() : [],
                        'videos' => $variation->videos ? $variation->videos->toArray() : [],
                        // Добавляем поля размеров и веса для вариаций
                        'weight' => $variation->weight,
                        'length' => $variation->length,
                        'width' => $variation->width,
                        'height' => $variation->height,
                        // Добавляем информацию об остатках для вариаций
                        'stock_quantity' => $variation->stock_quantity ?? 0,
                        'remote_stock_quantity' => $variation->remote_stock_quantity ?? '',
                        'fast_remote_stock_quantity' => $variation->fast_remote_stock_quantity ?? '',
                    ];
                }

                $result[] = $goodData;
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения информации о товарах',
            ], 500);
        }
    }

    /**
     * Получить главные блоки товаров
     */
    public function getMainBlocks(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 10);

            // Получаем хиты продаж (featured) - показываем напрямую товары с is_featured = true без дополнительных условий
            $featuredQuery = ShopGood::with(['images', 'variations' => function ($query) {
                $query->where('is_active', true)->with(['images' => function ($q) {
                    $q->orderBy('sort_order');
                }]);
            }, 'categories', 'brands'])
                ->featured() // Только товары с is_featured = true
                ->active() // Только активные товары
                ->orderBy('created_at', 'desc')
                ->limit($limit);
            // Не применяем applyStockFilter - показываем все товары с is_featured = true независимо от настроек показа
            $featured = $featuredQuery->get();

            // Получаем товары со скидками (sale) - показываем напрямую товары с is_sale = true без дополнительных условий
            $saleQuery = ShopGood::with(['images', 'variations' => function ($query) {
                $query->where('is_active', true)->with(['images' => function ($q) {
                    $q->orderBy('sort_order');
                }]);
            }, 'categories', 'brands'])
                ->sale() // Только товары с is_sale = true
                ->active() // Только активные товары
                ->orderBy('created_at', 'desc')
                ->limit($limit);
            // Не применяем applyStockFilter - показываем все товары с is_sale = true независимо от настроек показа
            $sale = $saleQuery->get();

            // Получаем новинки (new)
            $newQuery = ShopGood::with(['images', 'variations' => function ($query) {
                $query->where('is_active', true)->with(['images' => function ($q) {
                    $q->orderBy('sort_order');
                }]);
            }, 'categories', 'brands'])
                ->new() // Используем scope метод для правильной фильтрации boolean поля
                ->active() // Используем scope метод для правильной фильтрации boolean поля
                ->orderBy('created_at', 'desc')
                ->limit($limit);
            $this->applyStockFilter($newQuery);
            $new = $newQuery->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'featured' => $featured,
                    'sale' => $sale,
                    'new' => $new,
                ],
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения главных блоков',
            ], 500);
        }
    }

    /**
     * Получить товар по ID
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $variationId = $request->input('variation_id');
            $variationId = $variationId ? (int) $variationId : null;

            $good = ShopGood::with([
                'variations' => function ($query) {
                    $query->with([
                        'images' => function ($q) {
                            $q->orderBy('sort_order');
                        },
                        'videos' => function ($q) {
                            $q->orderBy('sort_order');
                        },
                    ]);
                },
                'images' => function ($query) {
                    $query->orderBy('sort_order');
                },
                'videos' => function ($query) {
                    $query->orderBy('sort_order');
                },
                'properties' => function ($query) {
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug', 'shop_properties.show_on_site')
                        ->withPivot((function () {
                            $fields = ['shop_property_value_id'];
                            if (\Illuminate\Support\Facades\Schema::hasColumn('shop_good_properties', 'value')) {
                                $fields[] = 'value';
                            }

                            return $fields;
                        })());
                },
                'categories' => function ($query) {
                    $query->select('shop_categories.id', 'shop_categories.name', 'shop_categories.slug', 'shop_categories.image', 'shop_categories.icon');
                },
                'brands' => function ($query) {
                    $query->select('shop_brands.id', 'shop_brands.name', 'shop_brands.slug', 'shop_brands.logo');
                },
                'tags' => function ($query) {
                    $query->select('shop_tags.id', 'shop_tags.name', 'shop_tags.color', 'shop_tags.slug');
                },
                'label' => function ($query) {
                    $query->select('id', 'name', 'color');
                },
            ])
                ->where('id', $id)
                ->where('is_active', true)
                ->first();

            if (! $good) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден',
                ], 404);
            }

            // Если передан variation_id, используем медиа вариации
            if ($variationId) {
                // Находим вариацию
                $variation = $good->variations->where('id', $variationId)->first();

                if ($variation) {
                    // Заменяем основные медиа товара на медиа вариации
                    if ($variation->images && $variation->images->count() > 0) {
                        $good->setRelation('images', $variation->images);
                    } else {
                        // Если у вариации нет изображений, не показываем изображения вообще
                        $good->setRelation('images', collect([]));
                    }

                    if ($variation->videos && $variation->videos->count() > 0) {
                        $good->setRelation('videos', $variation->videos);
                    } else {
                        // Если у вариации нет видео, не показываем видео вообще
                        $good->setRelation('videos', collect([]));
                    }
                } else {
                    // Если вариация не найдена, используем медиа основного товара
                    $good->setRelation('images', $good->images->whereNull('variation_id'));
                    $good->setRelation('videos', $good->videos->whereNull('variation_id'));
                }
            }

            // Добавляем атрибуты к вариациям
            $goodData = $good->toArray();
            if (isset($goodData['variations'])) {
                foreach ($goodData['variations'] as &$variation) {
                    $variationAttributes = [];
                    $variationIds = [$variation['id']];

                    $attributeRows = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereIn('vav.variation_id', $variationIds)
                        ->select(
                            'a.id as attribute_id', 'a.name as attribute_name',
                            'av.id as value_id', 'av.value as value_value'
                        )
                        ->get();

                    foreach ($attributeRows as $row) {
                        $variationAttributes[] = [
                            'id' => $row->attribute_id,
                            'name' => $row->attribute_name,
                            'value' => $row->value_value,
                        ];
                    }

                    $variation['attributes'] = $variationAttributes;
                }
            }

            // Преобразуем brands в brand (берем первый бренд, если есть)
            if (isset($goodData['brands']) && is_array($goodData['brands']) && count($goodData['brands']) > 0) {
                $goodData['brand'] = $goodData['brands'][0];
            } else {
                $goodData['brand'] = null;
            }

            return response()->json([
                'success' => true,
                'data' => $goodData,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения товара',
            ], 500);
        }
    }

    /**
     * Получить товар по slug
     */
    public function getGoodBySlug($slug): JsonResponse
    {
        try {
            // Обработка slug с учетом суффиксов из параметров сайта
            $slug = $this->normalizeSlug($slug);

            $good = ShopGood::with([
                'variations' => function ($query) {
                    $query->where('is_active', true)
                        ->select('*'); // Включаем все поля, включая remote_stock_quantity
                },
                'images' => function ($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'videos' => function ($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'properties' => function ($query) {
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug', 'shop_properties.show_on_site')
                        ->withPivot((function () {
                            $fields = ['shop_property_value_id'];
                            if (\Illuminate\Support\Facades\Schema::hasColumn('shop_good_properties', 'value')) {
                                $fields[] = 'value';
                            }

                            return $fields;
                        })());
                },
                'categories' => function ($query) {
                    $query->select('shop_categories.id', 'shop_categories.name', 'shop_categories.slug', 'shop_categories.image', 'shop_categories.icon');
                },
                'brands' => function ($query) {
                    $query->select('shop_brands.id', 'shop_brands.name', 'shop_brands.slug', 'shop_brands.logo');
                },
                'tags' => function ($query) {
                    $query->select('shop_tags.id', 'shop_tags.name', 'shop_tags.color', 'shop_tags.slug');
                },
                'label' => function ($query) {
                    $query->select('id', 'name', 'color');
                },
            ])
                ->where('slug', $slug)
                ->where('is_active', true)
                ->first();

            if (! $good) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден',
                ], 404);
            }

            // Проверяем, находится ли товар в избранном у текущего пользователя
            $isFavorite = false;
            $token = request()->bearerToken();

            if ($token) {
                // Ищем пользователя по токену
                $user = \App\Models\User::where('remember_token', $token)->first();
                if (! $user) {
                    $personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
                    if ($personalAccessToken) {
                        $user = $personalAccessToken->tokenable;
                    }
                }

                if ($user) {
                    $isFavorite = \App\Models\ShopFavorite::where('user_id', $user->id)
                        ->where('good_id', $good->id)
                        ->exists();
                }
            }

            // Добавляем поле is_favorite к товару и нормализуем свойства
            $goodData = $good->toArray();
            $goodData['is_favorite'] = $isFavorite;
            // Явно добавляем is_preorder, если его нет (для совместимости)
            if (! isset($goodData['is_preorder'])) {
                $goodData['is_preorder'] = $good->is_preorder ?? 0;
            }

            // Нормализуем свойства до {id, name, value}
            if (isset($goodData['properties'])) {
                $goodData['properties'] = collect($goodData['properties'])->toArray();
            }

            // Добавляем атрибуты к вариациям
            if (isset($goodData['variations'])) {
                foreach ($goodData['variations'] as &$variation) {
                    $variationAttributes = [];
                    $variationIds = [$variation['id']];

                    $attributeRows = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values as vav')
                        ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
                        ->whereIn('vav.variation_id', $variationIds)
                        ->select(
                            'a.id as attribute_id', 'a.name as attribute_name',
                            'av.id as value_id', 'av.value as value_value'
                        )
                        ->get();

                    foreach ($attributeRows as $row) {
                        $variationAttributes[] = [
                            'id' => $row->attribute_id,
                            'name' => $row->attribute_name,
                            'value' => $row->value_value,
                        ];
                    }

                    $variation['attributes'] = $variationAttributes;
                }
            }

            // Обрабатываем все бренды (логотипы)
            if (isset($goodData['brands']) && is_array($goodData['brands'])) {
                foreach ($goodData['brands'] as &$brand) {
                    // Обрабатываем логотип бренда
                    if (isset($brand['logo']) && $brand['logo']) {
                        $logoPath = $brand['logo'];
                        if (! str_starts_with($logoPath, '/') && ! str_starts_with($logoPath, 'http')) {
                            $cleanPath = ltrim($logoPath, '/');
                            // Проверяем, не начинается ли путь уже с images/
                            if (! str_starts_with($cleanPath, 'images/')) {
                                $logoPath = '/images/'.$cleanPath;
                            } else {
                                $logoPath = '/'.$cleanPath;
                            }
                        }
                        $brand['logo'] = $logoPath;
                    }
                }
                // Преобразуем brands в brand (берем первый бренд, если есть) для обратной совместимости
                if (count($goodData['brands']) > 0) {
                    $goodData['brand'] = $goodData['brands'][0];
                } else {
                    $goodData['brand'] = null;
                }
            } else {
                $goodData['brand'] = null;
            }

            // Обрабатываем изображения и иконки категорий
            if (isset($goodData['categories']) && is_array($goodData['categories'])) {
                foreach ($goodData['categories'] as &$category) {
                    // Обрабатываем изображение категории
                    if (isset($category['image']) && $category['image']) {
                        $imagePath = $category['image'];
                        if (! str_starts_with($imagePath, '/') && ! str_starts_with($imagePath, 'http')) {
                            $cleanPath = ltrim($imagePath, '/');
                            // Проверяем, не начинается ли путь уже с images/
                            if (! str_starts_with($cleanPath, 'images/')) {
                                $imagePath = '/images/'.$cleanPath;
                            } else {
                                $imagePath = '/'.$cleanPath;
                            }
                        }
                        $category['image'] = $imagePath;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $goodData,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения товара',
            ], 500);
        }
    }

    /**
     * Получить изображения товара
     */
    public function getGoodImages($id): JsonResponse
    {
        try {
            $good = ShopGood::with(['images' => function ($query) {
                $query->whereNull('variation_id')->orderBy('sort_order');
            }])
                ->where('id', $id)
                ->where('is_active', true)
                ->first();

            if (! $good) {
                return response()->json([
                    'success' => false,
                    'message' => 'Товар не найден',
                ], 404);
            }

            $images = $good->images ? $good->images->toArray() : [];

            return response()->json([
                'success' => true,
                'data' => $images,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения изображений товара',
            ], 500);
        }
    }

    /**
     * Получить товары пакетом
     */
    public function getBatch(Request $request): JsonResponse
    {
        try {
            $goodIds = $request->input('good_ids', []);

            if (empty($goodIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указаны ID товаров',
                ], 400);
            }

            $goods = ShopGood::with([
                'variations' => function ($query) {
                    $query->where('is_active', true)->with(['images' => function ($q) {
                        $q->orderBy('sort_order');
                    }]);
                },
                'images' => function ($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'videos' => function ($query) {
                    $query->whereNull('variation_id')->orderBy('sort_order');
                },
                'properties' => function ($query) {
                    $query->select('shop_properties.id', 'shop_properties.name', 'shop_properties.slug', 'shop_good_properties.shop_property_value_id');
                },
                'categories' => function ($query) {
                    $query->select('shop_categories.id', 'shop_categories.name', 'shop_categories.slug', 'shop_categories.image', 'shop_categories.icon');
                },
                'brands' => function ($query) {
                    $query->select('shop_brands.id', 'shop_brands.name', 'shop_brands.slug', 'shop_brands.logo');
                },
                'label' => function ($query) {
                    $query->select('shop_labels.id', 'shop_labels.name', 'shop_labels.color');
                },
            ])
                ->whereIn('id', $goodIds)
                ->where('is_active', true)
                ->get();

            // Добавляем image_url для обратной совместимости
            $goods->transform(function ($good) {
                if ($good->images && $good->images->count() > 0) {
                    // Ищем главное изображение
                    $mainImage = $good->images->where('is_main', true)->first();
                    if (! $mainImage) {
                        // Если главного нет, берем первое
                        $mainImage = $good->images->first();
                    }
                    if ($mainImage) {
                        // Добавляем ведущий слэш если его нет
                        $imagePath = $mainImage->file_path;
                        if ($imagePath && ! str_starts_with($imagePath, '/')) {
                            $imagePath = '/'.$imagePath;
                        }
                        $good->image_url = $imagePath;
                    }
                }

                return $good;
            });

            return response()->json([
                'success' => true,
                'data' => $goods,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения товаров',
            ], 500);
        }
    }

    /**
     * Получить категорию по slug
     */
    public function getCategoryBySlug($slug): JsonResponse
    {
        try {
            // Здесь нужно будет добавить модель Category, если её нет
            // Пока возвращаем заглушку
            return response()->json([
                'success' => true,
                'data' => null,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения категории',
            ], 500);
        }
    }

    /**
     * Получить изображения вариации
     */
    public function getVariationImages($variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::with(['images' => function ($query) {
                $query->orderBy('sort_order');
            }])
                ->where('id', $variationId)
                ->where('is_active', true)
                ->first();

            if (! $variation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Вариация не найдена',
                ], 404);
            }

            $images = $variation->images ? $variation->images->toArray() : [];

            return response()->json([
                'success' => true,
                'data' => $images,
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting variation images: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения изображений вариации',
            ], 500);
        }
    }

    /**
     * Получить видео вариации
     */
    public function getVariationVideos($variationId): JsonResponse
    {
        try {
            $variation = \App\Models\ShopGoodVariation::with(['videos' => function ($query) {
                $query->orderBy('sort_order');
            }])
                ->where('id', $variationId)
                ->where('is_active', true)
                ->first();

            if (! $variation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Вариация не найдена',
                ], 404);
            }

            $videos = $variation->videos ? $variation->videos->toArray() : [];

            return response()->json([
                'success' => true,
                'data' => $videos,
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting variation videos: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения видео вариации',
            ], 500);
        }
    }

    // DEPRECATED: These methods are no longer used - replaced by getVariationsMedia
    // public function getVariationsImages(Request $request): JsonResponse { ... }
    // public function getVariationsVideos(Request $request): JsonResponse { ... }

    /**
     * Получить все медиа (изображения + видео) для нескольких вариаций одним запросом
     */
    public function getVariationsMedia(Request $request): JsonResponse
    {
        try {
            $variationIds = $request->input('variation_ids', []);

            if (empty($variationIds) || ! is_array($variationIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указаны ID вариаций',
                ], 400);
            }

            $variations = \App\Models\ShopGoodVariation::with([
                'images' => function ($query) {
                    $query->orderBy('sort_order');
                },
                'videos' => function ($query) {
                    $query->orderBy('sort_order');
                },
            ])
                ->whereIn('id', $variationIds)
                ->where('is_active', true)
                ->get();

            $result = [];

            foreach ($variations as $variation) {
                $images = $variation->images ? $variation->images->toArray() : [];
                $videos = $variation->videos ? $variation->videos->toArray() : [];

                $result[$variation->id] = [
                    'images' => $images,
                    'videos' => $videos,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting variations media: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения медиа вариаций',
            ], 500);
        }
    }

    /**
     * Нормализация slug с учетом суффиксов из параметров сайта
     *
     * @param  string  $slug
     */
    private function normalizeSlug($slug): string
    {
        if (empty($slug) || ! is_string($slug)) {
            return $slug;
        }

        // Получаем список суффиксов из параметров сайта
        $suffSupport = Setting::where('key', 'suff_support')->first();

        if (! $suffSupport || empty($suffSupport->value)) {
            return $slug;
        }

        // Разбираем суффиксы (могут быть через запятую)
        $suffixes = array_filter(
            array_map('trim', explode(',', $suffSupport->value)),
            function ($suffix) {
                return ! empty($suffix);
            }
        );

        // Проверяем, заканчивается ли slug на любой из суффиксов
        foreach ($suffixes as $suffix) {
            if (str_ends_with($slug, $suffix)) {
                return substr($slug, 0, -strlen($suffix));
            }
        }

        return $slug;
    }

    /**
     * Получить диапазон цен для категорий с учетом вариаций
     */
    public function getPriceRange(Request $request): JsonResponse
    {
        try {
            $categoryIds = [];

            if ($request->filled('categories') && is_array($request->get('categories'))) {
                $categoryIds = array_filter(array_map('intval', $request->get('categories')));
            } elseif ($request->filled('category_id')) {
                $categoryIds = [(int) $request->get('category_id')];
            }

            // Если нужно включить подкатегории
            $includeSubcategories = $request->filled('include_subcategories')
                && ($request->get('include_subcategories') == '1' || $request->get('include_subcategories') === true);

            if ($includeSubcategories && ! empty($categoryIds)) {
                $categoryIds = \App\Models\ShopCategory::getAllDescendantIds($categoryIds);
            }

            // Получаем настройки
            $settings = $this->getStockSettings();
            $hidden0Price = $settings['hidden0Price'];
            $showGoodMode = $settings['showGoodMode'];
            $remoteQ = $settings['remoteQ'];

            // Используем более простой подход через DB::table с whereHas/whereDoesntHave
            // Но для SQL-запроса используем упрощенную версию без сложных условий по остаткам
            // (остатки будут учитываться через фильтрацию товаров)

            $prices = [];

            if (! empty($categoryIds)) {
                // Товары БЕЗ вариаций
                $goodsQuery = DB::table('shop_goods')
                    ->select('shop_goods.price', 'shop_goods.sale_price')
                    ->join('shop_good_categories', 'shop_goods.id', '=', 'shop_good_categories.good_id')
                    ->where('shop_goods.is_active', true)
                    ->whereIn('shop_good_categories.category_id', $categoryIds)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('shop_good_variations')
                            ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                            ->where('shop_good_variations.is_active', true);
                    });

                // Применяем фильтры по остаткам на уровне SQL
                if ($showGoodMode === 1) {
                    $goodsQuery->where(function ($q) use ($remoteQ) {
                        $q->where('shop_goods.stock_quantity', '>', 0);
                        if ($remoteQ === 2 || $remoteQ === 3) {
                            $q->orWhere(function ($remoteQuery) {
                                $remoteQuery->whereNotNull('shop_goods.remote_stock_quantity')
                                    ->where('shop_goods.remote_stock_quantity', '!=', '0')
                                    ->whereRaw('LENGTH(TRIM(shop_goods.remote_stock_quantity)) > 0');
                            })
                                ->orWhere(function ($fastRemoteQ) {
                                    $fastRemoteQ->whereNotNull('shop_goods.fast_remote_stock_quantity')
                                        ->where('shop_goods.fast_remote_stock_quantity', '!=', '0')
                                        ->whereRaw('LENGTH(TRIM(shop_goods.fast_remote_stock_quantity)) > 0');
                                });
                        }
                    });
                }

                // Применяем фильтр по цене
                if ($hidden0Price === 1) {
                    $goodsQuery->where(function ($q) {
                        $q->where('shop_goods.price', '>', 0)
                            ->orWhere('shop_goods.sale_price', '>', 0);
                    });
                }

                $goodsWithoutVariations = $goodsQuery->get();

                foreach ($goodsWithoutVariations as $good) {
                    $price = $good->sale_price > 0 && $good->sale_price < $good->price ? $good->sale_price : $good->price;
                    if ($price > 0) {
                        $prices[] = (float) $price;
                    }
                }

                // Товары С вариациями - только цены вариаций
                $variationsQuery = DB::table('shop_good_variations')
                    ->select('shop_good_variations.price', 'shop_good_variations.sale_price',
                        'shop_good_variations.stock_quantity', 'shop_good_variations.remote_stock_quantity', 'shop_good_variations.fast_remote_stock_quantity')
                    ->join('shop_goods', 'shop_good_variations.good_id', '=', 'shop_goods.id')
                    ->join('shop_good_categories', 'shop_goods.id', '=', 'shop_good_categories.good_id')
                    ->where('shop_good_variations.is_active', true)
                    ->where('shop_goods.is_active', true)
                    ->whereIn('shop_good_categories.category_id', $categoryIds);

                // Применяем фильтры по остаткам для вариаций
                if ($showGoodMode === 1) {
                    $variationsQuery->where(function ($q) use ($remoteQ) {
                        $q->where('shop_good_variations.stock_quantity', '>', 0);
                        if ($remoteQ === 2 || $remoteQ === 3) {
                            $q->orWhere(function ($remoteQuery) {
                                $remoteQuery->whereNotNull('shop_good_variations.remote_stock_quantity')
                                    ->where('shop_good_variations.remote_stock_quantity', '!=', '0')
                                    ->whereRaw('LENGTH(TRIM(shop_good_variations.remote_stock_quantity)) > 0');
                            })
                                ->orWhere(function ($fastRemoteQ) {
                                    $fastRemoteQ->whereNotNull('shop_good_variations.fast_remote_stock_quantity')
                                        ->where('shop_good_variations.fast_remote_stock_quantity', '!=', '0')
                                        ->whereRaw('LENGTH(TRIM(shop_good_variations.fast_remote_stock_quantity)) > 0');
                                });
                        }
                    });
                }

                // Применяем фильтр по цене для вариаций
                if ($hidden0Price === 1) {
                    $variationsQuery->where(function ($q) {
                        $q->where('shop_good_variations.price', '>', 0)
                            ->orWhere('shop_good_variations.sale_price', '>', 0);
                    });
                }

                $variations = $variationsQuery->get();

                foreach ($variations as $variation) {
                    $price = $variation->sale_price > 0 && $variation->sale_price < $variation->price ? $variation->sale_price : $variation->price;
                    if ($price > 0) {
                        $prices[] = (float) $price;
                    }
                }
            } else {
                // Без категорий - аналогично, но без join с категориями
                $goodsQuery = DB::table('shop_goods')
                    ->select('shop_goods.price', 'shop_goods.sale_price')
                    ->where('shop_goods.is_active', true)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('shop_good_variations')
                            ->whereColumn('shop_good_variations.good_id', 'shop_goods.id')
                            ->where('shop_good_variations.is_active', true);
                    });

                // Применяем фильтры по остаткам на уровне SQL
                if ($showGoodMode === 1) {
                    $goodsQuery->where(function ($q) use ($remoteQ) {
                        $q->where('shop_goods.stock_quantity', '>', 0);
                        if ($remoteQ === 2 || $remoteQ === 3) {
                            $q->orWhere(function ($remoteQuery) {
                                $remoteQuery->whereNotNull('shop_goods.remote_stock_quantity')
                                    ->where('shop_goods.remote_stock_quantity', '!=', '0')
                                    ->whereRaw('LENGTH(TRIM(shop_goods.remote_stock_quantity)) > 0');
                            })
                                ->orWhere(function ($fastRemoteQ) {
                                    $fastRemoteQ->whereNotNull('shop_goods.fast_remote_stock_quantity')
                                        ->where('shop_goods.fast_remote_stock_quantity', '!=', '0')
                                        ->whereRaw('LENGTH(TRIM(shop_goods.fast_remote_stock_quantity)) > 0');
                                });
                        }
                    });
                }

                // Применяем фильтр по цене
                if ($hidden0Price === 1) {
                    $goodsQuery->where(function ($q) {
                        $q->where('shop_goods.price', '>', 0)
                            ->orWhere('shop_goods.sale_price', '>', 0);
                    });
                }

                $goodsWithoutVariations = $goodsQuery->get();

                foreach ($goodsWithoutVariations as $good) {
                    $price = $good->sale_price > 0 && $good->sale_price < $good->price ? $good->sale_price : $good->price;
                    if ($price > 0) {
                        $prices[] = (float) $price;
                    }
                }

                $variationsQuery = DB::table('shop_good_variations')
                    ->select('shop_good_variations.price', 'shop_good_variations.sale_price',
                        'shop_good_variations.stock_quantity', 'shop_good_variations.remote_stock_quantity', 'shop_good_variations.fast_remote_stock_quantity')
                    ->join('shop_goods', 'shop_good_variations.good_id', '=', 'shop_goods.id')
                    ->where('shop_good_variations.is_active', true)
                    ->where('shop_goods.is_active', true);

                // Применяем фильтры по остаткам для вариаций
                if ($showGoodMode === 1) {
                    $variationsQuery->where(function ($q) use ($remoteQ) {
                        $q->where('shop_good_variations.stock_quantity', '>', 0);
                        if ($remoteQ === 2 || $remoteQ === 3) {
                            $q->orWhere(function ($remoteQuery) {
                                $remoteQuery->whereNotNull('shop_good_variations.remote_stock_quantity')
                                    ->where('shop_good_variations.remote_stock_quantity', '!=', '0')
                                    ->whereRaw('LENGTH(TRIM(shop_good_variations.remote_stock_quantity)) > 0');
                            })
                                ->orWhere(function ($fastRemoteQ) {
                                    $fastRemoteQ->whereNotNull('shop_good_variations.fast_remote_stock_quantity')
                                        ->where('shop_good_variations.fast_remote_stock_quantity', '!=', '0')
                                        ->whereRaw('LENGTH(TRIM(shop_good_variations.fast_remote_stock_quantity)) > 0');
                                });
                        }
                    });
                }

                // Применяем фильтр по цене для вариаций
                if ($hidden0Price === 1) {
                    $variationsQuery->where(function ($q) {
                        $q->where('shop_good_variations.price', '>', 0)
                            ->orWhere('shop_good_variations.sale_price', '>', 0);
                    });
                }

                $variations = $variationsQuery->get();

                foreach ($variations as $variation) {
                    $price = $variation->sale_price > 0 && $variation->sale_price < $variation->price ? $variation->sale_price : $variation->price;
                    if ($price > 0) {
                        $prices[] = (float) $price;
                    }
                }
            }

            $priceValues = array_unique($prices);

            if (empty($priceValues)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'min' => 0,
                        'max' => 0,
                    ],
                ]);
            }

            $minPrice = (int) min($priceValues);
            $maxPrice = (int) max($priceValues);

            return response()->json([
                'success' => true,
                'data' => [
                    'min' => $minPrice,
                    'max' => $maxPrice,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting price range: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения диапазона цен',
                'data' => [
                    'min' => 0,
                    'max' => 100000,
                ],
            ], 500);
        }
    }

    /**
     * Получить настройки остатков
     */
    private function getStockSettings(): array
    {
        $shopShowGoodMode = Setting::where('key', 'shop_show_good_mode')->first();
        $shopRemoteQ = Setting::where('key', 'shop_remote_q')->first();
        $hidden0PriceSetting = Setting::where('key', 'hidden_0_price')->first();

        return [
            'showGoodMode' => $shopShowGoodMode ? (int) $shopShowGoodMode->value : 2,
            'remoteQ' => $shopRemoteQ ? (int) $shopRemoteQ->value : 1,
            'hidden0Price' => $hidden0PriceSetting ? (int) $hidden0PriceSetting->value : 0,
        ];
    }
}
