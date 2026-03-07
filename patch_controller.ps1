$filePath = "f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\BulkGoodsImportController.php"
$content = [System.IO.File]::ReadAllText($filePath)

# Literal string for block 1 target (lines ~497)
$target1 = @'
                            // 2) Если по названию не нашли — по артикулу в основных товарах (с учётом поставщика), найденное — обновить
                            if (! $existingGood && ! empty($sku)) {
                                if ($supplierName) {
                                    $existingGood = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                                    if ($existingGood) {
                                        $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара, поставщик: {$supplierName})";
                                    } else {
                                        $existingGood = ShopGood::where('sku', $sku)->first();
                                        if ($existingGood) {
                                            $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара, без фильтра поставщика)";
                                        }
                                    }
                                } else {
                                    $existingGood = ShopGood::where('sku', $sku)->first();
                                    if ($existingGood) {
                                        $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара)";
                                    }
                                }
                            }
'@

$replacement1 = @'
                            // 2) Если по названию не нашли — по артикулу в основных товарах (с учётом поставщика), найденное — обновить
                            if (! $existingGood && ! empty($sku)) {
                                if ($supplierName) {
                                    $existingGood = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                                    if ($existingGood) {
                                        $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара, поставщик: {$supplierName})";
                                    } else {
                                        $existingGood = ShopGood::where('sku', $sku)->first();
                                        if ($existingGood) {
                                            $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара, без фильтра поставщика)";
                                        }
                                    }
                                } else {
                                    $existingGood = ShopGood::where('sku', $sku)->first();
                                    if ($existingGood) {
                                        $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара)";
                                    }
                                }
                            }

                            // 3) НОВОЕ_ФИКС: Если по названию и артикулу основного товара не нашли — ищем по артикулу среди ВАРИАЦИЙ
                            if (! $existingGood && ! empty($sku)) {
                                \Log::debug("Fallback search by variation SKU", ["sku" => $sku]);
                                $variationQuery = \App\Models\ShopGoodVariation::where('sku', $sku);
                                if ($supplierName) {
                                    $variationQuery->where('supplier', $supplierName);
                                }
                                $foundVariation = $variationQuery->first();
                                
                                if (! $foundVariation && $supplierName) {
                                    $foundVariation = \App\Models\ShopGoodVariation::where('sku', $sku)->first();
                                }

                                if ($foundVariation && $foundVariation->good) {
                                    $existingGood = $foundVariation->good;
                                    $existingVariation = $foundVariation;
                                    $foundByFields[] = "артикул вариации: '{$sku}' (поиск в вариациях по SKU)";
                                    \Log::debug("Found good via variation SKU fallback", ["good_id" => $existingGood->id]);
                                }
                            }
'@

# Literal string for block 2 target (lines ~682)
$target2 = @'
                            if (! $existingGood && ! empty($sku)) {
                                if ($supplierName) {
                                    $foundBySku = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                                    if ($foundBySku) {
                                        $existingGood = $foundBySku;
                                        $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара, поставщик: {$supplierName})";
                                    } else {
                                        $foundBySku = ShopGood::where('sku', $sku)->first();
                                        if ($foundBySku) {
                                            $existingGood = $foundBySku;
                                            $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара, без фильтра поставщика)";
                                        }
                                    }
                                } else {
                                    $foundBySku = ShopGood::where('sku', $sku)->first();
                                    if ($foundBySku) {
                                        $existingGood = $foundBySku;
                                        $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара)";
                                    }
                                }
                            }
'@

$replacement2 = @'
                            if (! $existingGood && ! empty($sku)) {
                                if ($supplierName) {
                                    $foundBySku = ShopGood::where('sku', $sku)->where('supplier', $supplierName)->first();
                                    if ($foundBySku) {
                                        $existingGood = $foundBySku;
                                        $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара, поставщик: {$supplierName})";
                                    } else {
                                        $foundBySku = ShopGood::where('sku', $sku)->first();
                                        if ($foundBySku) {
                                            $existingGood = $foundBySku;
                                            $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара, без фильтра поставщика)";
                                        }
                                    }
                                } else {
                                    $foundBySku = ShopGood::where('sku', $sku)->first();
                                    if ($foundBySku) {
                                        $existingGood = $foundBySku;
                                        $foundByFields[] = "SKU: '{$sku}' (поиск в вариациях по SKU, обновление основного товара)";
                                    }
                                }
                            }

                            // 3) НОВОЕ_ФИКС: Если по названию и артикулу основного товара не нашли — ищем по артикулу среди ВАРИАЦИЙ (блок 2)
                            if (! $existingGood && ! empty($sku)) {
                                \Log::debug("Fallback search by variation SKU (block 2)", ["sku" => $sku]);
                                $variationQuery = \App\Models\ShopGoodVariation::where('sku', $sku);
                                if ($supplierName) {
                                    $variationQuery->where('supplier', $supplierName);
                                }
                                $foundVariation = $variationQuery->first();
                                
                                if (! $foundVariation && $supplierName) {
                                    $foundVariation = \App\Models\ShopGoodVariation::where('sku', $sku)->first();
                                }

                                if ($foundVariation && $foundVariation->good) {
                                    $existingGood = $foundVariation->good;
                                    $existingVariation = $foundVariation;
                                    $foundByFields[] = "артикул вариации: '{$sku}' (поиск в вариациях по SKU, блок 2)";
                                    \Log::debug("Found good via variation SKU fallback (block 2)", ["good_id" => $existingGood->id]);
                                }
                            }
'@

$foundAny = $false
if ($content.Contains($target1)) {
    Write-Host "Block 1 found. Replacing..."
    $content = $content.Replace($target1, $replacement1)
    $foundAny = $true
} else {
    Write-Host "Block 1 NOT found literal."
}

if ($content.Contains($target2)) {
    Write-Host "Block 2 found. Replacing..."
    $content = $content.Replace($target2, $replacement2)
    $foundAny = $true
} else {
    Write-Host "Block 2 NOT found literal."
}

if ($foundAny) {
    [System.IO.File]::WriteAllText($filePath, $content)
    Write-Host "Patch applied successfully."
} else {
    Write-Host "Search patterns not found. Patch failed."
}
