$ErrorActionPreference = 'Stop'
$path = Join-Path $PSScriptRoot 'frontend\app\components\AuthCard.vue'
Write-Host "Checking $path"
$style = Select-String -Path $path -Pattern '<style|</style>' -SimpleMatch:$false
if ($style) {
  Write-Error 'AuthCard.vue unexpectedly contains a <style> block.'
}
$first = (Get-Content $path -TotalCount 1)
if ($first -ne '<script setup lang="ts">') {
  Write-Error "Unexpected first line: $first"
}
Write-Host 'OK: AuthCard.vue is clean and contains no style block.' -ForegroundColor Green
