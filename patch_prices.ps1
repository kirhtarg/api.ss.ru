$filePath = "app/Http/Controllers/Admin/BulkGoodsImportController.php"
$lines = Get-Content $filePath

$newLines = New-Object System.Collections.Generic.List[string]

for ($i = 0; $i -lt $lines.Count; $i++) {
    $line = $lines[$i]
    $lineNumber = $i + 1

    # 1. Update processVariation price logic (around line 3918)
    if ($line -match "^\s+\$variationPrice = \$variationData\['price'\]\s\?\?.*$") {
        $newLines.Add("            `$priceModification = `$goodData['price_modification'] ?? null;")
        $newLines.Add("            `$rawVariationPrice = `$variationData['price'] ?? `$goodData['price'] ?? `$good->price ?? 0;")
        $newLines.Add("            `$variationPrice = `$this->applyPriceModification(`$rawVariationPrice, `$priceModification['regular'] ?? null);")
        $newLines.Add("")
        $newLines.Add("            // Рассчитываем акционную цену вариации")
        $newLines.Add("            `$tempGoodDataForSale = `$goodData;")
        $newLines.Add("            `$tempGoodDataForSale['price'] = `$rawVariationPrice;")
        $newLines.Add("            `$tempGoodDataForSale['sale_price'] = `$variationData['sale_price'] ?? `$goodData['sale_price'] ?? null;")
        $newLines.Add("            `$variationSalePrice = `$this->applySalePriceModification(`$tempGoodDataForSale, `$priceModification);")
        continue
    }

    # 2. Update existing variation update block (around line 4017)
    if ($line -match "^\s+if\s\(isset\(\$variationData\['sale_price'\]\)\)\s\{$") {
        $newLines.Add("                // ОБНОВЛЕНО: Применяем рассчитанную акционную цену")
        $newLines.Add("                `$existingVariation->sale_price = `$variationSalePrice;")
        # Skip the next 4 lines of original sale_price assignment
        $i += 4
        continue
    }

    # 3. Update new variation creation block (around line 4116)
    if ($line -match "^\s+'sale_price'\s=>\snull,$") {
        $newLines.Add("                        'sale_price' => `$variationSalePrice,")
        continue
    }

    # 4. Update SKU conflict update block (around line 4162)
    if ($line -match "^\s+if\s\(isset\(\$variationData\['sale_price'\]\)\)\s\{$") {
        $newLines.Add("                            // ОБНОВЛЕНО: Применяем рассчитанную акционную цену")
        $newLines.Add("                            `$existingVariationBySku->sale_price = `$variationSalePrice;")
        # Skip the next 4 lines
        $i += 4
        continue
    }

    # 5. Update updateVariationFromGoodData method (around line 4289)
    if ($line -match "^\s+if\s\(isset\(\$goodData\['price'\]\)\)\s\{$") {
        $newLines.Add("        `$priceModification = `$goodData['price_modification'] ?? null;")
        $newLines.Add("        if (isset(`$goodData['price'])) {")
        $newLines.Add("            `$variation->price = `$this->applyPriceModification(`$goodData['price'], `$priceModification['regular'] ?? null);")
        $newLines.Add("        }")
        $i += 2 # Skip original simple assignment
        continue
    }

    # 6. Update sale price in updateVariationFromGoodData (around line 4294)
    if ($line -match "^\s+if\s\(isset\(\$goodData\['sale_price'\]\)\)\s\{$") {
        $newLines.Add("        if (isset(`$goodData['sale_price']) || (isset(`$priceModification) && isset(`$priceModification['sale']))) {")
        $newLines.Add("            `$variation->sale_price = `$this->applySalePriceModification(`$goodData, `$priceModification);")
        $newLines.Add("        }")
        $i += 2 # Skip original simple assignment
        continue
    }

    $newLines.Add($line)
}

[System.IO.File]::WriteAllLines($filePath, $newLines, $UTF8NoBOM)
