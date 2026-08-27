$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
& (Join-Path $root 'scripts\FIX_PLAYGROUND_PROTOCOL.ps1') -ProjectRoot $root
