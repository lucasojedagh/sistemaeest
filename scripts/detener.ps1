# Detiene el sistema. Uso: .\scripts\detener.ps1 (desde la raíz del repo).
Set-Location -LiteralPath (Join-Path $PSScriptRoot "..")

Write-Host "Deteniendo contenedores..." -ForegroundColor Yellow
docker compose down
Write-Host "Contenedores detenidos." -ForegroundColor Green
