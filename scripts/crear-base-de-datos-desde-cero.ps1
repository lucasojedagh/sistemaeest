<# ##############################################################################
   Script: scripts/crear-base-de-datos-desde-cero.ps1
   Propósito: Elimina y recrea la base de datos `sistema_escuela` desde cero,
              ejecuta todas las migraciones y seeders (incluyendo datos demo).
   Uso: .\scripts\crear-base-de-datos-desde-cero.ps1  (desde la raíz del repo)
   Requisitos: Docker Desktop en ejecución, contenedores levantados
               (ver scripts/iniciar.ps1). Los valores de conexión son los mismos
               que define docker-compose.yml.
   ############################################################################## #>

$ErrorActionPreference = "Stop"
$BACKEND_CONTAINER = "enro-backend"
$DB_CONTAINER      = "enro-mysql"
$DB_NAME           = "sistema_escuela"
$DB_USER           = "root"
$DB_PASS           = "root"

Write-Host "=== SISTEMA ENRO - Limpieza y recreación de BD ===" -ForegroundColor Cyan

# ---- 1. Verificar contenedores en ejecución ----
Write-Host "[1/5] Verificando contenedores..." -ForegroundColor Yellow
$containers = docker ps --format "{{.Names}}" 2>$null
if (-not ($containers -contains $BACKEND_CONTAINER)) {
    Write-Host "ERROR: El contenedor '$BACKEND_CONTAINER' no está en ejecución." -ForegroundColor Red
    Write-Host "Ejecutá 'docker compose up -d' primero." -ForegroundColor Red
    exit 1
}
Write-Host "  OK" -ForegroundColor Green

# ---- 2. Hacer backup de la BD actual (opcional) ----
$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backupDir = Join-Path $PSScriptRoot "..\backups"
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
$backupFile = Join-Path $backupDir "backup-$DB_NAME-$timestamp.sql"
try {
    Write-Host "[2/5] Haciendo backup de la BD actual..." -ForegroundColor Yellow
    # utf8: ascii corrompía tildes/ñ. Sin SilentlyContinue: si falla, se nota.
    docker exec $DB_CONTAINER mysqldump -u$DB_USER -p$DB_PASS $DB_NAME --result-file=/tmp/backup.sql
    if ($LASTEXITCODE -ne 0) { throw "mysqldump salió con código $LASTEXITCODE" }
    docker cp "${DB_CONTAINER}:/tmp/backup.sql" $backupFile
    if ((Get-Item $backupFile).Length -eq 0) { throw "backup vacío" }
    Write-Host "  Backup guardado en: backups\backup-$DB_NAME-$timestamp.sql" -ForegroundColor Green
} catch {
    Write-Host "  Backup omitido (no crítico): $_" -ForegroundColor DarkYellow
}

# ---- 3. Eliminar y recrear la BD ----
Write-Host "[3/5] Eliminando y recreando BD '$DB_NAME'..." -ForegroundColor Yellow
docker exec -i $DB_CONTAINER mysql -u$DB_USER -p$DB_PASS -e "
    DROP DATABASE IF EXISTS $DB_NAME;
    CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR al recrear la BD." -ForegroundColor Red
    exit 1
}
Write-Host "  BD '$DB_NAME' recreada." -ForegroundColor Green

# ---- 4. Ejecutar migraciones ----
Write-Host "[4/5] Ejecutando migraciones..." -ForegroundColor Yellow
# OJO: $LASTEXITCODE se lee ANTES de filtrar: en un pipeline refleja el último
# comando (ForEach), no docker. Por eso se captura la salida primero.
$migrateOut = docker exec -w /var/www $BACKEND_CONTAINER php artisan migrate --force 2>&1
$migrateCode = $LASTEXITCODE
$migrateOut | ForEach-Object {
    $line = $_.ToString().Trim()
    if ($line -match "DONE|Ran|Migrated|Nothing to migrate") { Write-Host "    $line" -ForegroundColor DarkCyan }
}
if ($migrateCode -ne 0) {
    Write-Host "ERROR durante las migraciones." -ForegroundColor Red
    exit 1
}
Write-Host "  Migraciones completadas." -ForegroundColor Green

# ---- 5. Ejecutar seeders ----
Write-Host "[5/5] Ejecutando seeders..." -ForegroundColor Yellow
$seedOut = docker exec -w /var/www $BACKEND_CONTAINER php artisan db:seed --force 2>&1
$seedCode = $LASTEXITCODE
$seedOut | ForEach-Object {
    $line = $_.ToString().Trim()
    if ($line -match "Seeding|creado|exist|Datos") { Write-Host "    $line" -ForegroundColor DarkCyan }
}
if ($seedCode -ne 0) {
    Write-Host "ERROR durante los seeders." -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "=== BD LIMPIA Y OPERATIVA ===" -ForegroundColor Green
Write-Host "Usuario admin: el que muestre la salida del seeder (ADMIN_EMAIL / clave generada o ADMIN_PASSWORD)" -ForegroundColor White
Write-Host "Backend: http://localhost:8000/api" -ForegroundColor White
Write-Host "Frontend: http://localhost:5173" -ForegroundColor White
