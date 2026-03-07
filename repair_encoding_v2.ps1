$filePath = "app/Http/Controllers/Admin/BulkGoodsImportController.php"
$outputFile = "app/Http/Controllers/Admin/BulkGoodsImportController_fixed.php"

# Function to attempt repair
function Repair-Content($text) {
    # Convert string back to bytes using ISO-8859-1 mapping
    $latin1 = [System.Text.Encoding]::GetEncoding("ISO-8859-1")
    $utf8 = [System.Text.Encoding]::UTF8
    
    $bytes = $latin1.GetBytes($text)
    return $utf8.GetString($bytes)
}

# Read bytes directly if possible
$rawBytes = [System.IO.File]::ReadAllBytes($filePath)

# If the file was written as UTF-8 but the original bytes were interpreted as ANSI,
# then what we have is a UTF-8 file where each byte was expanded.
# We need to reverse this.

$content = [System.IO.File]::ReadAllText($filePath, [System.Text.Encoding]::UTF8)

$fixed = $content
# Try up to 3 times (for deep corruption)
for ($i = 0; $i -lt 3; $i++) {
    # Check if we have Russian characters (U+0400 to U+04FF)
    $hasRussian = $fixed.ToCharArray() | Where-Object { $_ -ge 0x0400 -and $_ -le 0x04FF } | Select-Object -First 1
    if ($hasRussian -and !($fixed -match "[РРЎ][РІР…Р°РґРµ]")) {
        # Simple check for common mojibake patterns
        Write-Host "Found Russian characters, stopping repair at iteration $i"
        break
    }
    Write-Host "Repairing iteration $i..."
    $fixed = Repair-Content $fixed
}

[System.IO.File]::WriteAllText($outputFile, $fixed, (New-Object System.Text.UTF8Encoding $false))
Write-Host "Fixed file written to $outputFile"
