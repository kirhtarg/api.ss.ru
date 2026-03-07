$filePath = "app/Http/Controllers/Admin/BulkGoodsImportController.php"
$lines = Get-Content $filePath -Encoding UTF8

$newLines = New-Object System.Collections.Generic.List[string]
$UTF8NoBOM = New-Object System.Text.UTF8Encoding $false

# State to track fallback matching logic
$addedFallback = $false

for ($i = 0; $i -lt $lines.Count; $i++) {
    $line = $lines[$i]
    $trimmed = $line.Trim()

    # --- FIX 1: Fallback SKU matching ---
    # We'll look for the end of the searchByNameInVariations block for SKU
    if (!$addedFallback -and $trimmed -eq 'if ($searchByNameInVariations && $searchByFieldInVariations === ''sku'') {') {
        # Find the end of this if block
        $bracketCount = 0
        $j = $i
        do {
            if ($lines[$j].Contains('{')) { $bracketCount++ }
            if ($lines[$j].Contains('}')) { $bracketCount-- }
            $newLines.Add($lines[$j])
            $j++
        } while ($bracketCount -gt 0 -and $j -lt $lines.Count)
        
        $newLines.Add('')
        $newLines.Add('                // FALLBACK_FIX: Search by variation SKU if not found by name/main SKU')
        $newLines.Add('                if (! $existingGood && ! empty($sku)) {')
        $newLines.Add('                    \Log::debug("Fallback search by variation SKU", ["sku" => $sku]);')
        $newLines.Add('                    $variationQuery = \App\Models\ShopGoodVariation::where("sku", $sku);')
        $newLines.Add('                    if ($supplierName) { $variationQuery->where("supplier", $supplierName); }')
        $newLines.Add('                    $foundVar = $variationQuery->first();')
        $newLines.Add('                    if (! $foundVar && $supplierName) { $foundVar = \App\Models\ShopGoodVariation::where("sku", $sku)->first(); }')
        $newLines.Add('                    if ($foundVar && $foundVar->good) {')
        $newLines.Add('                        $existingGood = $foundVar->good;')
        $newLines.Add('                        $existingVariation = $foundVar;')
        $newLines.Add('                        $foundByFields[] = "variation SKU (fallback): " . $sku;')
        $newLines.Add('                    }')
        $newLines.Add('                }')
        
        $i = $j - 1
        $addedFallback = $true
        continue
    }

    # --- FIX 2: Promotional Price Logic ---

    if ($trimmed -eq '$variationPrice = $variationData[''price''] ?? $goodData[''price''] ?? $good->price ?? 0;') {
        $newLines.Add('            $priceModification = $goodData["price_modification"] ?? null;')
        $newLines.Add('            $rawVarPrice = $variationData["price"] ?? $goodData["price"] ?? $good->price ?? 0;')
        $newLines.Add('            $variationPrice = $this->applyPriceModification($rawVarPrice, $priceModification["regular"] ?? null);')
        $newLines.Add('')
        $newLines.Add('            // Sale price calculation')
        $newLines.Add('            $tmpData = $goodData;')
        $newLines.Add('            $tmpData["price"] = $rawVarPrice;')
        $newLines.Add('            $tmpData["sale_price"] = $variationData["sale_price"] ?? $goodData["sale_price"] ?? null;')
        $newLines.Add('            $variationSalePrice = $this->applySalePriceModification($tmpData, $priceModification);')
        continue
    }

    if ($trimmed -eq '$existingVariation->sale_price = $variationData[''sale_price''];') {
        $newLines.Add('                $existingVariation->sale_price = $variationSalePrice;')
        continue
    }
    if ($trimmed -eq '$existingVariationBySku->sale_price = $variationData[''sale_price''];') {
        $newLines.Add('                            $existingVariationBySku->sale_price = $variationSalePrice;')
        continue
    }
    if ($trimmed -eq '''sale_price'' => null,') {
        $newLines.Add("                        'sale_price' => `$variationSalePrice,")
        continue
    }

    if ($trimmed -eq '$variation->price = $goodData[''price''];') {
        $newLines.Add('        $priceModification = $goodData["price_modification"] ?? null;')
        $newLines.Add('        $variation->price = $this->applyPriceModification($goodData["price"], $priceModification["regular"] ?? null);')
        continue
    }

    if ($trimmed -eq '$variation->sale_price = $goodData[''sale_price''];') {
        $newLines.Add('        if (isset($goodData["sale_price"]) || (isset($priceModification) && isset($priceModification["sale"]))) {')
        $newLines.Add('            $variation->sale_price = $this->applySalePriceModification($goodData, $priceModification);')
        $newLines.Add('        }')
        continue
    }

    $newLines.Add($line)
}

[System.IO.File]::WriteAllLines($filePath, $newLines, $UTF8NoBOM)
