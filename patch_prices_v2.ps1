$filePath = "app/Http/Controllers/Admin/BulkGoodsImportController.php"
$lines = Get-Content $filePath

$newLines = New-Object System.Collections.Generic.List[string]

$UTF8NoBOM = New-Object System.Text.UTF8Encoding $false

for ($i = 0; $i -lt $lines.Count; $i++) {
    $line = $lines[$i]
    $trimmed = $line.Trim()

    # 1. Update processVariation price logic
    if ($trimmed -eq "`$variationPrice = `$variationData['price'] ?? `$goodData['price'] ?? `$good->price ?? 0;") {
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

    # 2. Update existing variation update block
    if ($trimmed -eq "if (isset(`$variationData['sale_price'])) {") {
        # Check if we are inside processVariation and after we calculated variationSalePrice
        # To be safe, look for the next line too
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Trim() -eq "`$existingVariation->sale_price = `$variationData['sale_price'];") {
            $newLines.Add("                // ОБНОВЛЕНО: Применяем рассчитанную акционную цену")
            $newLines.Add("                `$existingVariation->sale_price = `$variationSalePrice;")
            $i += 4 # Skip the 4 lines of original if block
            continue
        }
    }

    # 3. Update new variation creation block
    if ($trimmed -eq "'sale_price' => null,") {
        $newLines.Add("                        'sale_price' => `$variationSalePrice,")
        continue
    }

    # 4. Update SKU conflict update block
    # Note: the condition for existingVariationBySku is identical to #2, but used in a different context.
    # We can detect it by checking the variable name in the next line.
    if ($trimmed -eq "if (isset(`$variationData['sale_price'])) {") {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Trim() -eq "`$existingVariationBySku->sale_price = `$variationData['sale_price'];") {
            $newLines.Add("                            // ОБНОВЛЕНО: Применяем рассчитанную акционную цену")
            $newLines.Add("                            `$existingVariationBySku->sale_price = `$variationSalePrice;")
            $i += 4
            continue
        }
    }

    # 5. Update updateVariationFromGoodData method - regular price
    if ($trimmed -eq "if (isset(`$goodData['price'])) {") {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Trim() -eq "`$variation->price = `$goodData['price'];") {
            $newLines.Add("        `$priceModification = `$goodData['price_modification'] ?? null;")
            $newLines.Add("        if (isset(`$goodData['price'])) {")
            $newLines.Add("            `$variation->price = `$this->applyPriceModification(`$goodData['price'], `$priceModification['regular'] ?? null);")
            $newLines.Add("        }")
            $i += 2
            continue
        }
    }

    # 6. Update updateVariationFromGoodData method - sale price
    if ($trimmed -eq "if (isset(`$goodData['sale_price'])) {") {
        if ($i + 1 -lt $lines.Count -and $lines[$i + 1].Trim() -eq "`$variation->sale_price = `$goodData['sale_price'];") {
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
