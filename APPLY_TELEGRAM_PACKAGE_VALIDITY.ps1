param(
    [string]$ProjectRoot = (Get-Location).Path
)

$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "SP Cambo - Telegram package validity fix" -ForegroundColor Cyan

$target = Join-Path $ProjectRoot "backend\app\Services\TelegramStorefrontUiService.php"

if (-not (Test-Path $target)) {
    throw "File not found: $target`nRun this script from the SP Cambo project root."
}

$content = [System.IO.File]::ReadAllText($target)

if ($content.Contains("`$validity = '⏳'.`$this->durationLabel((int) `$package->duration_seconds);")) {
    Write-Host "Already applied. No changes needed." -ForegroundColor Green
    exit 0
}

$backup = "$target.bak-validity-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
Copy-Item $target $backup -Force
Write-Host "Backup created: $backup" -ForegroundColor DarkGray

$old = @'
    private function packageButtonLabel(Package $package): string
    {
        $family = trim((string) ($package->family_label ?: $package->name));
        $family = preg_replace('/\s+(credits?|tokens?)$/i', '', $family) ?: $family;
        $family = mb_substr($family, 0, 12);
        $stock = '📦'.$this->stockButtonLabel($package);

        if ($this->isCreditPackage($package)) {
            return '💳 '.$family.' '.$this->creditDisplay($package).' · '.$this->packagePrice($package).' · '.$stock;
        }

        return '🪙 '.$family.' '.$this->compactUnits((int) $package->advertised_units).' · '.$this->packagePrice($package).' · '.$stock;
    }
'@

$new = @'
    private function packageButtonLabel(Package $package): string
    {
        $family = trim((string) ($package->family_label ?: $package->name));
        $family = preg_replace('/\s+(credits?|tokens?)$/i', '', $family) ?: $family;
        $family = mb_substr($family, 0, 12);
        $validity = '⏳'.$this->durationLabel((int) $package->duration_seconds);
        $stock = '📦'.$this->stockButtonLabel($package);

        if ($this->isCreditPackage($package)) {
            return '💳 '.$family.' '.$this->creditDisplay($package).' · '.$this->packagePrice($package).' · '.$validity.' · '.$stock;
        }

        return '🪙 '.$family.' '.$this->compactUnits((int) $package->advertised_units).' · '.$this->packagePrice($package).' · '.$validity.' · '.$stock;
    }
'@

if (-not $content.Contains($old)) {
    throw "Expected packageButtonLabel() block was not found. No changes were made."
}

$content = $content.Replace($old, $new)

$content = $content.Replace(
    '📦 Stock is shown on every package button.',
    '⏳ Validity and 📦 stock are shown on every package button.'
)

$content = $content.Replace(
    '📦 បង្ហាញស្តុកនៅលើប៊ូតុងនីមួយៗ។',
    '⏳ សុពលភាព និង 📦 ស្តុក បង្ហាញនៅលើប៊ូតុងនីមួយៗ។'
)

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($target, $content, $utf8NoBom)

$verify = [System.IO.File]::ReadAllText($target)
if (-not $verify.Contains("`$validity = '⏳'.`$this->durationLabel((int) `$package->duration_seconds);")) {
    Copy-Item $backup $target -Force
    throw "Verification failed. Original file restored."
}

Write-Host ""
Write-Host "DONE" -ForegroundColor Green
Write-Host "Updated: backend\app\Services\TelegramStorefrontUiService.php"
Write-Host "Buttons now show: Tokens · Price · Validity · Stock"
Write-Host "Example: Claude 10M · `$0.39 · ⏳1 day · 📦97"
