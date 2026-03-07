$filePath = "app/Http/Controllers/Admin/BulkGoodsImportController.php"
$lines = Get-Content $filePath -Encoding UTF8

$newLines = New-Object System.Collections.Generic.List[string]
$UTF8NoBOM = New-Object System.Text.UTF8Encoding $false

$addedFallback1 = $false
$addedFallback2 = $false

for ($i = 0; $i -lt $lines.Count; $i++) {
    $line = $lines[$i]
    $trimmed = $line.Trim()

    if (!$addedFallback1 -and $trimmed -eq "if (! `$existingGood) {") {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Contains("`$existingGood = \$this->findGoodByNameAndSku(")) {
            $newLines.Add($line) 
            $newLines.Add($lines[++$i]) 
            $newLines.Add("")
            $newLines.Add('                // NEW_FIX: Fallback search by variation SKU')
            $newLines.Add('                if (! $existingGood && ! empty($sku)) {')
            $newLines.Add('                    \Log::debug("Fallback search by variation SKU", ["sku" => $sku]);')
            $newLines.Add('                    $variationQuery = \App\Models\ShopGoodVariation::where("sku", $sku);')
            $newLines.Add('                    if ($supplierName) {')
            $newLines.Add('                        $variationQuery->where("supplier", $supplierName);')
            $newLines.Add('                    }')
            $newLines.Add('                    $foundVariation = $variationQuery->first();')
            $newLines.Add('                    ')
            $newLines.Add('                    if (! $foundVariation && $supplierName) {')
            $newLines.Add('                        $foundVariation = \App\Models\ShopGoodVariation::where("sku", $sku)->first();')
            $newLines.Add('                    }')
            $newLines.Add('')
            $newLines.Add('                    if ($foundVariation && $foundVariation->good) {')
            $newLines.Add('                        $existingGood = $foundVariation->good;')
            $newLines.Add('                        $existingVariation = $foundVariation;')
            $newLines.Add('                        $foundByFields[] = "variation SKU: {$sku} (fallback search)";')
            $newLines.Add('                        \Log::debug("Found good via variation SKU fallback", ["good_id" => $existingGood->id]);')
            $newLines.Add('                    }')
            $newLines.Add('                }')
            $addedFallback1 = $true
            continue
        }
    }

    if (!$addedFallback2 -and $trimmed -eq '$existingGood = \App\Models\ShopGood::where("sku", $sku)->first();') {
        $newLines.Add($line)
        $newLines.Add("")
        $newLines.Add('                // NEW_FIX: Fallback search (block 2) by variation SKU')
        $newLines.Add('                if (! $existingGood && ! empty($sku)) {')
        $newLines.Add('                    \Log::debug("Fallback search (block 2) by variation SKU", ["sku" => $sku]);')
        $newLines.Add('                    $variationQuery = \App\Models\ShopGoodVariation::where("sku", $sku);')
        $newLines.Add('                    if ($supplierName) {')
        $newLines.Add('                        $variationQuery->where("supplier", $supplierName);')
        $newLines.Add('                    }')
        $newLines.Add('                    $foundVariation = $variationQuery->first();')
        $newLines.Add('                    if (! $foundVariation && $supplierName) {')
        $newLines.Add('                        $foundVariation = \App\Models\ShopGoodVariation::where("sku", $sku)->first();')
        $newLines.Add('                    }')
        $newLines.Add('                    if ($foundVariation && $foundVariation->good) {')
        $newLines.Add('                        $existingGood = $foundVariation->good;')
        $newLines.Add('                        $existingVariation = $foundVariation;')
        $newLines.Add('                        $foundByFields[] = "variation SKU: {$sku} (fallback search block 2)";')
        $newLines.Add('                        \Log::debug("Found good via variation SKU fallback (block 2)", ["good_id" => $existingGood->id]);')
        $newLines.Add('                    }')
        $newLines.Add('                }')
        $addedFallback2 = $true
        continue
    }

    if ($trimmed -eq '$variationPrice = $variationData["price"] ?? $goodData["price"] ?? $good->price ?? 0;') {
        $newLines.Add('            $priceModification = $goodData["price_modification"] ?? null;')
        $newLines.Add('            $rawVariationPrice = $variationData["price"] ?? $goodData["price"] ?? $good->price ?? 0;')
        $newLines.Add('            $variationPrice = $this->applyPriceModification($rawVariationPrice, $priceModification["regular"] ?? null);')
        $newLines.Add("")
        $newLines.Add('            // Calculate sale price for variation')
        $newLines.Add('            $tempGoodDataForSale = $goodData;')
        $newLines.Add('            $tempGoodDataForSale["price"] = $rawVariationPrice;')
        $newLines.Add('            $tempGoodDataForSale["sale_price"] = $variationData["sale_price"] ?? $goodData["sale_price"] ?? null;')
        $newLines.Add('            $variationSalePrice = $this->applySalePriceModification($tempGoodDataForSale, $priceModification);')
        continue
    }

    if ($trimmed -eq 'if (isset($variationData["sale_price"])) {') {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Contains('$existingVariation->sale_price = $variationData["sale_price"];')) {
            $newLines.Add('                // UPDATED: Apply calculated sale price')
            $newLines.Add('                $existingVariation->sale_price = $variationSalePrice;')
            $i += 4
            continue
        }
    }

    if ($trimmed -eq "'sale_price' => null,") {
        $newLines.Add("                        'sale_price' => `$variationSalePrice,")
        continue
    }

    if ($trimmed -eq 'if (isset($variationData["sale_price"])) {') {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Contains('$existingVariationBySku->sale_price = $variationData["sale_price"];')) {
            $newLines.Add('                            // UPDATED: Apply calculated sale price')
            $newLines.Add('                            $existingVariationBySku->sale_price = $variationSalePrice;')
            $i += 4
            continue
        }
    }

    if ($trimmed -eq 'if (isset($goodData["price"])) {') {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Contains('$variation->price = $goodData["price"];')) {
            $newLines.Add('        $priceModification = $goodData["price_modification"] ?? null;')
            $newLines.Add('        if (isset($goodData["price"])) {')
            $newLines.Add('            $variation->price = $this->applyPriceModification($goodData["price"], $priceModification["regular"] ?? null);')
            $newLines.Add('        }')
            $i += 2
            continue
        }
    }

    if ($trimmed -eq 'if (isset($goodData["sale_price"])) {') {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Contains('$variation->sale_price = $goodData["sale_price"];')) {
            $newLines.Add('        if (isset($goodData["sale_price"]) || (isset($priceModification) && isset($priceModification["sale"]))) {')
            $newLines.Add('            $variation->sale_price = $this->applySalePriceModification($goodData, $priceModification);')
            $newLines.Add('        }')
            $i += 2
            continue
        }
    }

    $newLines.Add($line)
}

[System.IO.File]::WriteAllLines($filePath, $newLines, $UTF8NoBOM)
