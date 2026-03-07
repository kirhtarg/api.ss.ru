$filePath = "app/Http/Controllers/Admin/BulkGoodsImportController.php"
$outputFile = "app/Http/Controllers/Admin/BulkGoodsImportController_fixed.php"

# Read the corrupted file as UTF-8
$content = [System.IO.File]::ReadAllText($filePath, [System.Text.Encoding]::UTF8)

# Function to fix "mojibake"
function Fix-Mojibake($text) {
    # Convert string back to bytes using ISO-8859-1 (which maps 0-255 to first 256 Unicode points)
    $latin1 = [System.Text.Encoding]::GetEncoding("ISO-8859-1")
    $utf8 = [System.Text.Encoding]::UTF8
    
    $bytes = $latin1.GetBytes($text)
    return $utf8.GetString($bytes)
}

# The corruption might be double-layered
$fixedOnce = Fix-Mojibake $content

# Check if it still looks like mojibake (contains 'Р' followed by characters)
# Just to be safe, we'll try to find a known string
if ($fixedOnce.Contains("РћР±РЅСѓР»РµРЅС‹") -or $fixedOnce.Contains("Р Р€Р В±Р С‘РЎР‚")) {
    Write-Host "Detected double corruption, fixing again..."
    $fixedOnce = Fix-Mojibake $fixedOnce
}

[System.IO.File]::WriteAllText($outputFile, $fixedOnce, (New-Object System.Text.UTF8Encoding $false))
Write-Host "Fixed file written to $outputFile"
