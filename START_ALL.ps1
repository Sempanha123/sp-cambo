$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
& (Join-Path $root 'scripts\START_ALL.ps1') -ProjectRoot $root
