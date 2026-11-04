# Inicia el sistema completo. Se puede ejecutar desde la raíz:
#   .\scripts\iniciar.ps1
# o con doble clic (el script vuelve solo a la raíz del repo).
Set-Location -LiteralPath (Join-Path $PSScriptRoot "..")

Write-Host "=== Iniciando Sistema ENRO con Docker ===" -ForegroundColor Cyan

Write-Host "`n[1/4] Construyendo imagenes..." -ForegroundColor Yellow
docker compose build

Write-Host "`n[2/4] Levantando contenedores..." -ForegroundColor Yellow
docker compose up -d

Write-Host "`n[3/4] Instalando dependencias de Composer (si no existen)..." -ForegroundColor Yellow
docker compose exec backend sh -c "[ ! -d vendor ] && composer install || echo 'vendor ya existe'"

Write-Host "`n[4/4] Ejecutando migraciones..." -ForegroundColor Yellow
docker compose exec backend php artisan migrate --force

Write-Host "`n=== Listo! ===" -ForegroundColor Green
Write-Host "  Frontend: http://localhost:5173" -ForegroundColor Cyan
Write-Host "  Backend:  http://localhost:8000" -ForegroundColor Cyan
Write-Host "  MySQL:    localhost:3307 (user: root, pass: root)" -ForegroundColor Cyan
Write-Host "`nPara ver logs: docker compose logs -f" -ForegroundColor Gray
Write-Host "Para detener:  docker compose down" -ForegroundColor Gray
