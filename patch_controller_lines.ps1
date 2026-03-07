$filePath = "f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\BulkGoodsImportController.php"
$lines = Get-Content $filePath

# Replace Block 1 (at line 497-515)
$block1 = @'
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

# Replace Block 2 (at line 682-702)
$block2 = @'
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

$newLines = @()
# Lines 1 to 496 (indices 0..495)
$newLines += $lines[0..495]
$newLines += $block1 -split "\r?\n"
# Lines 516 to 681 (indices 515..680)
$newLines += $lines[515..680]
$newLines += $block2 -split "\r?\n"
# Lines 703 to END (indices 702..end)
$newLines += $lines[702..($lines.Count - 1)]

[System.IO.File]::WriteAllLines($filePath, $newLines)
Write-Host "Patch applied successfully via line numbers."
