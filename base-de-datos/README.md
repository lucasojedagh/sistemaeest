# Base de datos

- **Fuente de verdad:** `../backend/database/migrations/` (se aplican con `php artisan migrate`).
- **`schema.sql`:** foto de referencia del esquema (regenerada 2026-09-29 desde la BD
  de desarrollo con todas las migraciones aplicadas). Para regenerarla:
  `docker compose exec -T mysql mysqldump -uroot -proot --no-data sistema_escuela`
  más el encabezado de este archivo. Para importar:
  `mysql -u root -p sistema_escuela < schema.sql`.
- **`respaldos-antiguos/`:** dumps viejos guardados por historia (`basededatos-2026.sql`,
  `esquema_db-2026.sql`). No se usan para levantar el sistema, no los borres por las dudas.
- **Backups nuevos:** el script `scripts/crear-base-de-datos-desde-cero.*` los guarda en
  `../backups/`.
