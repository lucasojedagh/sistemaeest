# Base de datos

- **Fuente de verdad:** `../backend/database/migrations/` (se aplican con `php artisan migrate`).
- **`schema.sql`:** foto de referencia del esquema (regenerada 2026-11-05:
  31 tablas, 299 columnas, 106 índices).
  Para regenerarla:
  `mysqldump -h 127.0.0.1 -uroot --no-data sistema_escuela`
  (en Laragon el binario está en `C:/laragon/bin/mysql/*/bin/`) más el encabezado de
  este archivo. Para importar:
  `mysql -u root -p sistema_escuela < schema.sql`.
- **Ningún `ALTER` a mano sin su migración.** La foto y la base real se pueden
  desincronizar en silencio, y el server se construye solo con las migraciones:
  pasó con `cursos.especialidad` y con el `UNIQUE` funcional de `materias`, que
  estaban en la base de trabajo y en `schema.sql` pero en ninguna migración
  (`php artisan test` no las corre nunca, porque phpunit usa `sqlite :memory:`).
  Para comprobarlo, `php artisan esquema:verificar`: migra una base auxiliar
  desde cero, la compara contra la base de trabajo y sale con código 1 si
  algo está `FALTA` o `SOBRA`. Se corre antes de cada despliegue
  (`scripts/iniciar.sh` lo hace en el paso `[4/5]`). Detalle en
  `docs/DESPLEGUE-LINUX.md` §12.
  Después de aplicar una migración nueva, regenerar la foto **desde una base
  migrada desde cero**, no desde la del colegio.
- **`respaldos-antiguos/`:** dumps viejos guardados por historia (`basededatos-2026.sql`,
  `esquema_db-2026.sql`). No se usan para levantar el sistema, no los borres por las dudas.
- **Backups nuevos:** el script `scripts/crear-base-de-datos-desde-cero.*` los guarda en
  `../backups/`.
