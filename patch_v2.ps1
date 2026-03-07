$filePath = "f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\BulkGoodsImportController.php"
$lines = [System.IO.File]::ReadAllLines($filePath)

$fix1 = @'

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

$fix2 = @'

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

# Insert from bottom to top
$newLines = [System.Collections.Generic.List[string]]::new($lines)

# Split and insert fix2 (after original line 702, which is index 702)
# Note: indexes are 0-based, so line 702 is index 701. To insert AFTER 702, we insert at index 702.
$fix2Lines = $fix2 -split "`r?`n"
$newLines.InsertRange(702, $fix2Lines)

# Split and insert fix1 (after original line 515, which is index 515)
$fix1Lines = $fix1 -split "`r?`n"
$newLines.InsertRange(515, $fix1Lines)

# No BOM UTF8
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllLines($filePath, $newLines, $utf8NoBom)
Write-Host "Success: Patch applied at lines 515 and 702."
