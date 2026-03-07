$filePath = "app/Http/Controllers/Admin/BulkGoodsImportController.php"
$lines = Get-Content $filePath -Encoding UTF8

$newLines = New-Object System.Collections.Generic.List[string]
$UTF8NoBOM = New-Object System.Text.UTF8Encoding $false

# Helper to get Cyrillic strings from bytes
function Get-Cyrillic($bytes) {
    return [System.Text.Encoding]::UTF8.GetString($bytes)
}

# "НОВОЕ_ФИКС: Если по названию и артикулу..." in UTF8 bytes
$commentLine1 = Get-Cyrillic @(0x2F, 0x2F, 0x20, 0xD0, 0x9D, 0xD0, 0x9E, 0xD0, 0x92, 0xD0, 0x9E, 0xD0, 0x95, 0x5F, 0xD0, 0xA4, 0xD0, 0x98, 0xD0, 0x9A, 0xD0, 0xA1, 0x3A, 0x20, 0xD0, 0x95, 0xD1, 0x81, 0xD0, 0xBB, 0xD0, 0xB8, 0x20, 0xD0, 0xBF, 0xD0, 0xBE, 0x20, 0xD0, 0xBD, 0xD0, 0xB0, 0xD0, 0xB7, 0xD0, 0xB2, 0xD0, 0xB0, 0xD0, 0xBD, 0xD0, 0xB8, 0xD1, 0x8E, 0x20, 0xD0, 0xB8, 0x20, 0xD0, 0xB0, 0xD1, 0x80, 0xD1, 0x82, 0xD0, 0xB8, 0xD0, 0xBA, 0xD1, 0x83, 0xD0, 0xBB, 0xD1, 0x83, 0x20, 0xD0, 0xBE, 0xD1, 0x81, 0xD0, 0xBD, 0xD0, 0xBE, 0xD0, 0xB2, 0xD0, 0xBD, 0xD0, 0xBE, 0xD0, 0xB3, 0xD0, 0xBE, 0x20, 0xD1, 0x82, 0xD0, 0xBE, 0xD0, 0xB2, 0xD0, 0xB0, 0xD1, 0x80, 0xD0, 0xB0, 0x20, 0xD0, 0xBD, 0xD0, 0xB5, 0x20, 0xD0, 0xBD, 0xD0, 0xB0, 0xD1, 0x88, 0xD0, 0xBB, 0xD0, 0xB8, 0x20, 0xE2, 0x80, 0x94, 0x20, 0xD0, 0xB8, 0xD1, 0x89, 0xD0, 0xB5, 0xD0, 0xBC, 0x20, 0xD0, 0xBF, 0xD0, 0xBE, 0x20, 0xD0, 0xB0, 0xD1, 0x80, 0xD1, 0x82, 0xD0, 0xB8, 0xD0, 0xBA, 0xD1, 0x83, 0xD0, 0xBB, 0xD1, 0x83, 0x20, 0xD1, 0x81, 0xD1, 0x80, 0xD0, 0xB5, 0xD0, 0xB4, 0xD0, 0xB8, 0x20, 0xD0, 0x92, 0xD0, 0x90, 0xD0, 0xA0, 0xD0, 0x98, 0xD0, 0x90, 0xD0, 0xA6, 0xD0, 0x98, 0xD0, 0x99)
$variationLabel = Get-Cyrillic @(0xD0, 0xB0, 0xD1, 0x80, 0xD1, 0x82, 0xD0, 0xB8, 0xD0, 0xBA, 0xD1, 0x83, 0xD0, 0xBB, 0x20, 0xD0, 0xB2, 0xD0, 0xB0, 0xD1, 0x80, 0xD0, 0xB8, 0xD0, 0xB0, 0xD1, 0x86, 0xD0, 0xB8, 0xD0, 0xB8, 0x3A, 0x20, 0x27, 0x7B, 0x24, 0x73, 0x6B, 0x75, 0x7D, 0x27, 0x20, 0x28, 0xD0, 0xBF, 0xD0, 0xBE, 0xD0, 0xB8, 0xD1, 0x81, 0xD0, 0xBA, 0x20, 0xD0, 0xB2, 0x20, 0xD0, 0xB2, 0xD0, 0xB0, 0xD1, 0x80, 0xD0, 0xB8, 0xD0, 0xB0, 0xD1, 0x86, 0xD0, 0xB8, 0xD1, 0x8F, 0xD1, 0x85, 0x20, 0xD0, 0xBF, 0xD0, 0xBE, 0x20, 0x53, 0x4B, 0x55, 0x29)
$updateLabel = Get-Cyrillic @(0x2F, 0x2F, 0x20, 0xD0, 0x9E, 0xD0, 0x91, 0xD0, 0x9D, 0xD0, 0x9E, 0xD0, 0x92, 0xD0, 0x9B, 0xD0, 0x95, 0xD0, 0x9D, 0xD0, 0x9E, 0x3A, 0x20, 0xD0, 0x9F, 0xD1, 0x80, 0xD0, 0xB8, 0xD0, 0xBC, 0xD0, 0xB5, 0xD0, 0xBD, 0xD1, 0x8F, 0xD0, 0xB5, 0xD0, 0xBC, 0x20, 0xD1, 0x80, 0xD0, 0xB0, 0xD1, 0x81, 0xD1, 0x81, 0xD1, 0x87, 0xD0, 0xB8, 0xD1, 0x82, 0xD0, 0xB0, 0xD0, 0xBD, 0xD0, 0xBD, 0xD1, 0x83, 0xD1, 0x8E, 0x20, 0xD0, 0xB0, 0xD0, 0xBA, 0xD1, 0x86, 0xD0, 0xB8, 0xD0, 0xBE, 0xD0, 0xBD, 0xD0, 0xBD, 0xD1, 0x83, 0xD1, 0x8E, 0x20, 0xD1, 0x86, 0xD0, 0xB5, 0xD0, 0xBD, 0xD1, 0x83)
$saleComment = Get-Cyrillic @(0x2F, 0x2F, 0x20, 0xD0, 0xA0, 0xD0, 0xB0, 0xD1, 0x81, 0xD1, 0x81, 0xD1, 0x87, 0xD0, 0xB8, 0xD1, 0x82, 0xD1, 0x8B, 0xD0, 0xB2, 0xD0, 0xB0, 0xD0, 0xB5, 0xD0, 0xBC, 0x20, 0xD0, 0xB0, 0xBA, 0xD1, 0x86, 0xD0, 0xB8, 0xD0, 0xBE, 0xD0, 0xBD, 0xD0, 0xBD, 0xD1, 0x83, 0xD1, 0x8E, 0x20, 0xD1, 0x86, 0xD0, 0xB5, 0xD0, 0xBD, 0xD1, 0x83, 0x20, 0xD0, 0xB2, 0xD0, 0xB0, 0xD1, 0x80, 0xD0, 0xB8, 0xD0, 0xB0, 0xD1, 0x86, 0xD0, 0xB8, 0xD0, 0xB8)

$addedFallback1 = $false
$addedFallback2 = $false

for ($i = 0; $i -lt $lines.Count; $i++) {
    $line = $lines[$i]
    $trimmed = $line.Trim()

    # --- FIX 1: Fallback SKU matching ---
    if (!$addedFallback1 -and $trimmed -eq "if (! `$existingGood) {") {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Contains("`$existingGood = \$this->findGoodByNameAndSku(")) {
            $newLines.Add($line) 
            $newLines.Add($lines[++$i]) 
            $newLines.Add("")
            $newLines.Add("                " + $commentLine1)
            $newLines.Add("                if (! `$existingGood && ! empty(`$sku)) {")
            $newLines.Add("                    \Log::debug(\"Fallback search by variation SKU\", [\"sku\" => `$sku]);")
            $newLines.Add("                    `$variationQuery = \App\Models\ShopGoodVariation::where('sku', `$sku);")
            $newLines.Add("                    if (`$supplierName) {")
            $newLines.Add("                        `$variationQuery->where('supplier', `$supplierName);")
            $newLines.Add("                    }")
            $newLines.Add("                    `$foundVariation = `$variationQuery->first();")
            $newLines.Add("                    ")
            $newLines.Add("                    if (! `$foundVariation && `$supplierName) {")
            $newLines.Add("                        `$foundVariation = \App\Models\ShopGoodVariation::where('sku', `$sku)->first();")
            $newLines.Add("                    }")
            $newLines.Add("")
            $newLines.Add("                    if (`$foundVariation && `$foundVariation->good) {")
            $newLines.Add("                        `$existingGood = `$foundVariation->good;")
            $newLines.Add("                        `$existingVariation = `$foundVariation;")
            $newLines.Add("                        `$foundByFields[] = \"$variationLabel\";")
            $newLines.Add("                        \Log::debug(\"Found good via variation SKU fallback\", [\"good_id\" => `$existingGood->id]);")
            $newLines.Add("                    }")
            $newLines.Add("                }")
            $addedFallback1 = $true
            continue
        }
    }

    if (!$addedFallback2 -and $trimmed -eq "`$existingGood = \App\Models\ShopGood::where(\"sku\", `$sku)->first();") {
        $newLines.Add($line)
        $newLines.Add("")
        $newLines.Add("                " + $commentLine1)
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
        $newLines.Add("                        `$foundByFields[] = \"$variationLabel\";")
        $newLines.Add("                        \Log::debug(\"Found good via variation SKU fallback (block 2)\", [\"good_id\" => `$existingGood->id]);")
        $newLines.Add("                    }")
        $newLines.Add("                }")
        $addedFallback2 = $true
        continue
    }

    # --- FIX 2: Promotional Price Logic ---
    if ($trimmed -eq "`$variationPrice = `$variationData['price'] ?? `$goodData['price'] ?? `$good->price ?? 0;") {
        $newLines.Add("            `$priceModification = `$goodData['price_modification'] ?? null;")
        $newLines.Add("            `$rawVariationPrice = `$variationData['price'] ?? `$goodData['price'] ?? `$good->price ?? 0;")
        $newLines.Add("            `$variationPrice = `$this->applyPriceModification(`$rawVariationPrice, `$priceModification['regular'] ?? null);")
        $newLines.Add("")
        $newLines.Add("            " + $saleComment)
        $newLines.Add("            `$tempGoodDataForSale = `$goodData;")
        $newLines.Add("            `$tempGoodDataForSale['price'] = `$rawVariationPrice;")
        $newLines.Add("            `$tempGoodDataForSale['sale_price'] = `$variationData['sale_price'] ?? `$goodData['sale_price'] ?? null;")
        $newLines.Add("            `$variationSalePrice = `$this->applySalePriceModification(`$tempGoodDataForSale, `$priceModification);")
        continue
    }

    if ($trimmed -eq "if (isset(`$variationData['sale_price'])) {") {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Contains("`$existingVariation->sale_price = `$variationData['sale_price'];")) {
            $newLines.Add("                " + $updateLabel)
            $newLines.Add("                `$existingVariation->sale_price = `$variationSalePrice;")
            $i += 4
            continue
        }
    }

    if ($trimmed -eq "'sale_price' => null,") {
        $newLines.Add("                        'sale_price' => `$variationSalePrice,")
        continue
    }

    if ($trimmed -eq "if (isset(`$variationData['sale_price'])) {") {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Contains("`$existingVariationBySku->sale_price = `$variationData['sale_price'];")) {
            $newLines.Add("                            " + $updateLabel)
            $newLines.Add("                            `$existingVariationBySku->sale_price = `$variationSalePrice;")
            $i += 4
            continue
        }
    }

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

[System.IO.File]::WriteAllLines($filePath, $newLines, $UTF8NoBOM)
