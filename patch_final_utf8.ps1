$filePath = "app/Http/Controllers/Admin/BulkGoodsImportController.php"

# Explicitly read with UTF8
$lines = Get-Content $filePath -Encoding UTF8

$newLines = New-Object System.Collections.Generic.List[string]
$UTF8NoBOM = New-Object System.Text.UTF8Encoding $false

# State to track what we've done
$addedFallback1 = $false
$addedFallback2 = $false

for ($i = 0; $i -lt $lines.Count; $i++) {
    $line = $lines[$i]
    $trimmed = $line.Trim()

    # --- FIX 1: Fallback SKU matching ---
    
    # Instance 1
    if (!$addedFallback1 -and $trimmed -eq "if (! `$existingGood) {") {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Contains("`$existingGood = \$this->findGoodByNameAndSku(")) {
            $newLines.Add($line) # Add the if (! $existingGood)
            $newLines.Add($lines[++$i]) # Add the findGoodByNameAndSku call
             
            $newLines.Add("")
            $newLines.Add("                // 3) РќРћР’РћР•_Р¤РРљРЎ: Р•СЃР»Рё РїРѕ РЅР°Р·РІР°РЅРёСЋ Рё Р°СЂС‚РёРєСѓР»Сѓ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР° РЅРµ РЅР°С€Р»Рё вЂ” РёС‰РµРј РїРѕ Р°СЂС‚РёРєСѓР»Сѓ СЃСЂРµРґРё Р’РђР РРђР¦РР™")
             $newLines.Add("                if (! `$existingGood && ! empty(`$sku)) { ")
             $newLines.Add("                    \Log::debug(\"Fallback search by variation SKU\", [\"sku\" => `$sku]); ")
             $newLines.Add("                    `$variationQuery = \App\Models\ShopGoodVariation::where('sku', `$sku); ")
             $newLines.Add("                    if (`$supplierName) {
                        ")
             $newLines.Add("                        `$variationQuery->where('supplier', `$supplierName); ")
             $newLines.Add("                    
                    }")
             $newLines.Add("                    `$foundVariation = `$variationQuery->first(); ")
             $newLines.Add("                    ")
             $newLines.Add("                    if (! `$foundVariation && `$supplierName) { ")
             $newLines.Add("                        `$foundVariation = \App\Models\ShopGoodVariation::where('sku', `$sku) - >first(); ")
             $newLines.Add("                    
                    }")
             $newLines.Add("")
             $newLines.Add("                    if (`$foundVariation && `$foundVariation->good) {
                        ")
             $newLines.Add("                        `$existingGood = `$foundVariation->good; ")
             $newLines.Add("                        `$existingVariation = `$foundVariation; ")
             $newLines.Add("                        `$foundByFields[] = \"Р°СЂС‚РёРєСѓР» РІР°СЂРёР°С†РёРё: '{\$sku}' (РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU)\"; ")
             $newLines.Add("                        \Log::debug(\"Found good via variation SKU fallback\", [\"good_id\" => `$existingGood->id]); ")
             $newLines.Add("                    
                    }")
             $newLines.Add("                
                }")
             $addedFallback1 = $true
             continue
        }
    }

    # Instance 2 (Redundant SKU search block)
    if (!$addedFallback2 -and $trimmed -eq "`$existingGood = \App\Models\ShopGood::where(\"sku\", `$sku)->first(); ") {
        $newLines.Add($line)
        $newLines.Add("")
        $newLines.Add("                / / 3) РќРћР’РћР•_Р¤РРљРЎ: Р•СЃР»Рё РїРѕ Р°СЂС‚РёРєСѓР»Сѓ РѕСЃРЅРѕРІРЅРѕРіРѕ С‚РѕРІР°СЂР° РЅРµ РЅР°С€Р»Рё вЂ” РёС‰РµРј РїРѕ Р°СЂС‚РёРєСѓР»Сѓ СЃСЂРµРґРё Р’РђР РРђР¦РР™")
        $newLines.Add("                if (! `$existingGood && ! empty(`$sku)) {")
        $newLines.Add("                    \Log::debug(\"Fallback search (block 2) by variation SKU\", [\"sku\" => `$sku]);")
        $newLines.Add("                    `$variationQuery = \App\Models\ShopGoodVariation::where('sku', `$sku);")
        $newLines.Add("                    if (`$supplierName) {")
        $newLines.Add("                        `$variationQuery->where('supplier', `$supplierName);")
        $newLines.Add("                    }")
        $newLines.Add("                    `$foundVariation = `$variationQuery->first();")
        $newLines.Add("                    if (! `$foundVariation && `$supplierName) {")
        $newLines.Add("                        `$foundVariation = \App\Models\ShopGoodVariation::where('sku', `$sku)->first();")
        $newLines.Add("                    }")
        $newLines.Add("                    if (`$foundVariation && `$foundVariation->good) {")
        $newLines.Add("                        `$existingGood = `$foundVariation->good;")
        $newLines.Add("                        `$existingVariation = `$foundVariation;")
        $newLines.Add("                        `$foundByFields[] = \"Р°СЂС‚РёРєСѓР» РІР°СЂРёР°С†РёРё: '{\$sku}' (РїРѕРёСЃРє РІ РІР°СЂРёР°С†РёСЏС… РїРѕ SKU)\";")
        $newLines.Add("                        \Log::debug(\"Found good via variation SKU fallback (block 2)\", [\"good_id\" => `$existingGood->id]);")
        $newLines.Add("                    }")
        $newLines.Add("                }")
        $addedFallback2 = $true
        continue
    }

    # --- FIX 2: Promotional Price Logic ---

    # processVariation initial prices
    if ($trimmed -eq "`$variationPrice = `$variationData['price'] ?? `$goodData['price'] ?? `$good->price ?? 0;") {
        $newLines.Add("            `$priceModification = `$goodData['price_modification'] ?? null;")
        $newLines.Add("            `$rawVariationPrice = `$variationData['price'] ?? `$goodData['price'] ?? `$good->price ?? 0;")
        $newLines.Add("            `$variationPrice = `$this->applyPriceModification(`$rawVariationPrice, `$priceModification['regular'] ?? null);")
        $newLines.Add("")
        $newLines.Add("            // Р Р°СЃСЃС‡РёС‚С‹РІР°РµРј Р°РєС†РёРѕРЅРЅСѓСЋ С†РµРЅСѓ РІР°СЂРёР°С†РёРё")
        $newLines.Add("            `$tempGoodDataForSale = `$goodData;")
        $newLines.Add("            `$tempGoodDataForSale['price'] = `$rawVariationPrice;")
        $newLines.Add("            `$tempGoodDataForSale['sale_price'] = `$variationData['sale_price'] ?? `$goodData['sale_price'] ?? null;")
        $newLines.Add("            `$variationSalePrice = `$this->applySalePriceModification(`$tempGoodDataForSale, `$priceModification);")
        continue
    }

    # existing variation update sale_price
    if ($trimmed -eq "if (isset(`$variationData['sale_price'])) {") {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Contains("`$existingVariation->sale_price = `$variationData['sale_price'];")) {
            $newLines.Add("                // РћР‘РќРћР’Р›Р•РќРћ: РџСЂРёРјРµРЅСЏРµРј СЂР°СЃСЃС‡РёС‚Р°РЅРЅСѓСЋ Р°РєС†РёРѕРЅРЅСѓСЋ С†РµРЅСѓ")
            $newLines.Add("                `$existingVariation->sale_price = `$variationSalePrice;")
            $i += 4
            continue
        }
    }

    # new variation creation
    if ($trimmed -eq "'sale_price' => null,") {
        $newLines.Add("                        'sale_price' => `$variationSalePrice,")
        continue
    }

    # SKU conflict update sale_price
    if ($trimmed -eq "if (isset(`$variationData['sale_price'])) {") {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Contains("`$existingVariationBySku->sale_price = `$variationData['sale_price'];")) {
            $newLines.Add("                            // РћР‘РќРћР’Р›Р•РќРћ: РџСЂРёРјРµРЅСЏРµРј СЂР°СЃСЃС‡РёС‚Р°РЅРЅСѓСЋ Р°РєС†РёРѕРЅРЅСѓСЋ С†РµРЅСѓ")
            $newLines.Add("                            `$existingVariationBySku->sale_price = `$variationSalePrice;")
            $i += 4
            continue
        }
    }

    # updateVariationFromGoodData regular price
    if ($trimmed -eq "if (isset(`$goodData['price'])) {") {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Contains("`$variation->price = `$goodData['price'];")) {
            $newLines.Add("        `$priceModification = `$goodData['price_modification'] ?? null;")
            $newLines.Add("        if (isset(`$goodData['price'])) {")
            $newLines.Add("            `$variation->price = `$this->applyPriceModification(`$goodData['price'], `$priceModification['regular'] ?? null);")
            $newLines.Add("        }")
            $i += 2
            continue
        }
    }

    # updateVariationFromGoodData sale price
    if ($trimmed -eq "if (isset(`$goodData['sale_price'])) {") {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Contains("`$variation->sale_price = `$goodData['sale_price'];")) {
            $newLines.Add("        if (isset(`$goodData['sale_price']) || (isset(`$priceModification) && isset(`$priceModification['sale']))) {")
            $newLines.Add("            `$variation->sale_price = `$this->applySalePriceModification(`$goodData, `$priceModification);")
            $newLines.Add("        }")
            $i += 2
            continue
        }
    }

    $newLines.Add($line)
}

# Final write with UTF8 NO BOM
[System.IO.File]::WriteAllLines($filePath, $newLines, $UTF8NoBOM)
