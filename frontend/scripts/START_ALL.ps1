$ErrorActionPreference = "Stop"
$frontend = Split-Path -Parent $PSScriptRoot
$root = Split-Path -Parent $frontend
& (Join-Path $root 'scripts\START_ALL.ps1') -ProjectRoot $root
