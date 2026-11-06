# ------------------------------------------------------------------------------
# Esquema de la base `sistema_escuela` - Sistema ENRO.
# Generado el 2026-11-05 desde la BD de desarrollo (todas las migraciones aplicadas):
#   mysqldump -h 127.0.0.1 -uroot --no-data sistema_escuela
# Fuente de verdad: backend/database/migrations/ (este archivo es solo referencia).
# Incluye `migrations` (registro de migraciones). La vieja tabla de Sanctum
# `personal_access_tokens` ya no existe: la autenticacion usa `api_sesiones`.
# OJO: los indices redundantes de columna-prefijo se quitaron con la migracion
# 2026_11_05_000005_quitar_indices_redundantes (ver su comentario).
# OJO: `alumnos_matricula_rescate` la crea la migracion
# 2026_11_05_000006 y guarda el estado anterior de cada matricula movida a
# Observaciones; si se hace migrate:rollback de esa migracion, la tabla se borra.
# Para importar: mysql -u root -p sistema_escuela < schema.sql
# ------------------------------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `user_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `auditable_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auditable_id` bigint unsigned DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `detalles` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_logs_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`),
  KEY `activity_logs_user_id_index` (`user_id`),
  KEY `activity_logs_action_type_index` (`action_type`),
  KEY `activity_logs_created_at_index` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=594 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alumno_calificaciones`
--

DROP TABLE IF EXISTS `alumno_calificaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumno_calificaciones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` bigint unsigned NOT NULL,
  `materia_id` bigint unsigned DEFAULT NULL,
  `anio` tinyint unsigned DEFAULT NULL,
  `division` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `periodo` tinyint DEFAULT NULL COMMENT '1..3=cuatrimestres',
  `tipo_calificacion` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'regular',
  `detalle` json DEFAULT NULL,
  `ciclo_lectivo` smallint unsigned DEFAULT NULL,
  `valor` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha` date DEFAULT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumno_calificaciones_alumno_id_anio_division_index` (`alumno_id`,`anio`,`division`),
  KEY `alumno_calificaciones_materia_id_foreign` (`materia_id`),
  KEY `alumno_calificaciones_tipo_calificacion_index` (`tipo_calificacion`),
  KEY `ac_ciclo_materia_idx` (`ciclo_lectivo`,`materia_id`,`alumno_id`),
  KEY `ac_combo_idx` (`alumno_id`,`materia_id`,`anio`,`division`,`ciclo_lectivo`,`periodo`,`tipo_calificacion`),
  CONSTRAINT `alumno_calificaciones_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `alumno_calificaciones_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=25998 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alumno_direcciones`
--

DROP TABLE IF EXISTS `alumno_direcciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumno_direcciones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` bigint unsigned NOT NULL,
  `titular` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `direccion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumno_direcciones_alumno_id_index` (`alumno_id`),
  CONSTRAINT `alumno_direcciones_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3093 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alumno_horario_overrides`
--

DROP TABLE IF EXISTS `alumno_horario_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumno_horario_overrides` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` bigint unsigned NOT NULL,
  `dia` tinyint unsigned NOT NULL,
  `bloque` tinyint unsigned NOT NULL,
  `materia_id` bigint unsigned DEFAULT NULL,
  `profesor_id` bigint unsigned DEFAULT NULL,
  `materia_nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profesor_nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marcado` enum('normal','recursando','intensificacion') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `alumno_horario_overrides_alumno_id_dia_bloque_unique` (`alumno_id`,`dia`,`bloque`),
  KEY `alumno_horario_overrides_materia_id_index` (`materia_id`),
  KEY `alumno_horario_overrides_profesor_id_index` (`profesor_id`),
  CONSTRAINT `alumno_horario_overrides_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `alumno_horario_overrides_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alumno_intensificaciones`
--

DROP TABLE IF EXISTS `alumno_intensificaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumno_intensificaciones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` bigint unsigned NOT NULL,
  `anio` tinyint unsigned DEFAULT NULL,
  `division` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `materia_id` bigint unsigned DEFAULT NULL,
  `materia_nombre` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumno_intensificaciones_materia_id_index` (`materia_id`),
  KEY `ai_alumno_materia_idx` (`alumno_id`,`materia_id`),
  CONSTRAINT `alumno_intensificaciones_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alumno_intensificaciones_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alumno_materias_estado`
--

DROP TABLE IF EXISTS `alumno_materias_estado`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumno_materias_estado` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` bigint unsigned NOT NULL,
  `materia_id` bigint unsigned NOT NULL,
  `ciclo_lectivo` int NOT NULL,
  `anio` tinyint NOT NULL,
  `division` char(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p1_estado` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p2_estado` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nota_final` decimal(4,2) DEFAULT NULL,
  `estado_final` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nota_intensificacion_febrero` decimal(4,2) DEFAULT NULL,
  `nota_intensificacion_1cuatri` decimal(4,2) DEFAULT NULL,
  `nota_intensificacion_diciembre` decimal(4,2) DEFAULT NULL,
  `nota_intensificacion_marzo_abril` decimal(4,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ame_unique` (`alumno_id`,`materia_id`,`ciclo_lectivo`),
  KEY `alumno_materias_estado_materia_id_index` (`materia_id`),
  KEY `alumno_materias_estado_ciclo_lectivo_index` (`ciclo_lectivo`),
  KEY `alumno_materias_estado_estado_final_index` (`estado_final`),
  CONSTRAINT `alumno_materias_estado_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `alumno_materias_estado_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=3149 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alumno_promedios`
--

DROP TABLE IF EXISTS `alumno_promedios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumno_promedios` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` bigint unsigned NOT NULL,
  `materia_id` bigint unsigned NOT NULL,
  `anio` smallint unsigned NOT NULL,
  `division` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ciclo_lectivo` smallint unsigned NOT NULL,
  `periodo` tinyint unsigned NOT NULL,
  `promedio` decimal(5,2) DEFAULT NULL,
  `notas_count` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ap_u_combo` (`alumno_id`,`materia_id`,`anio`,`division`,`ciclo_lectivo`,`periodo`),
  KEY `ap_ix_mat` (`materia_id`),
  CONSTRAINT `alumno_promedios_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `alumno_promedios_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=4427 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alumno_recursandos`
--

DROP TABLE IF EXISTS `alumno_recursandos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumno_recursandos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` bigint unsigned NOT NULL,
  `anio` tinyint unsigned DEFAULT NULL,
  `division` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `materia_id` bigint unsigned DEFAULT NULL,
  `materia_nombre` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumno_recursandos_materia_id_index` (`materia_id`),
  KEY `ar_alumno_materia_idx` (`alumno_id`,`materia_id`),
  CONSTRAINT `alumno_recursandos_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alumno_recursandos_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alumno_responsables`
--

DROP TABLE IF EXISTS `alumno_responsables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumno_responsables` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` bigint unsigned NOT NULL,
  `nombre` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `apellido` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parentesco` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `convive` tinyint(1) NOT NULL DEFAULT '0',
  `tutor_legal` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumno_responsables_alumno_id_index` (`alumno_id`),
  CONSTRAINT `alumno_responsables_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1149 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alumno_telefonos`
--

DROP TABLE IF EXISTS `alumno_telefonos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumno_telefonos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` bigint unsigned NOT NULL,
  `titular` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumno_telefonos_alumno_id_index` (`alumno_id`),
  CONSTRAINT `alumno_telefonos_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4190 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alumnos`
--

DROP TABLE IF EXISTS `alumnos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumnos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `genero` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nacionalidad` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dni` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `matricula` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `anio` tinyint unsigned DEFAULT NULL,
  `division` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grupo` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `especialidad` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observaciones` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `egreso_year` smallint DEFAULT NULL,
  `egreso_anio` tinyint DEFAULT NULL,
  `egreso_division` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `promedio_general` decimal(5,2) DEFAULT NULL,
  `estado` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Activo',
  `tiene_ppi` tinyint(1) NOT NULL DEFAULT '0',
  `tiene_ae` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `alumnos_dni_unique` (`dni`),
  KEY `alumnos_anio_div_idx` (`anio`,`division`),
  KEY `alumnos_apellido_nombre_index` (`apellido`,`nombre`),
  KEY `alumnos_promedio_general_index` (`promedio_general`),
  KEY `alumnos_estado_index` (`estado`),
  KEY `alumnos_especialidad_idx` (`especialidad`),
  KEY `alumnos_matricula_idx` (`matricula`),
  KEY `alumnos_email_idx` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3623 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alumnos_matricula_rescate`
--

DROP TABLE IF EXISTS `alumnos_matricula_rescate`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumnos_matricula_rescate` (
  `alumno_id` bigint unsigned NOT NULL,
  `matricula` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observaciones_antes` text COLLATE utf8mb4_unicode_ci,
  `movida_el` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`alumno_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `api_sesiones`
--

DROP TABLE IF EXISTS `api_sesiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_sesiones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `token` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `dispositivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ultimo_uso_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_sesiones_token_unique` (`token`),
  KEY `api_sesiones_user_id_ultimo_uso_at_index` (`user_id`,`ultimo_uso_at`)
) ENGINE=InnoDB AUTO_INCREMENT=247 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asistencia_justificaciones`
--

DROP TABLE IF EXISTS `asistencia_justificaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asistencia_justificaciones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `asistencia_id` bigint unsigned NOT NULL,
  `motivo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `observacion` text COLLATE utf8mb4_unicode_ci,
  `documento_id` bigint unsigned DEFAULT NULL,
  `estado` enum('pendiente','aprobada','rechazada') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `registrado_por` bigint unsigned DEFAULT NULL,
  `aprobado_por` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ajust_asistencia_unique` (`asistencia_id`),
  KEY `asistencia_justificaciones_estado_index` (`estado`),
  KEY `asistencia_justificaciones_documento_id_foreign` (`documento_id`),
  KEY `asistencia_justificaciones_registrado_por_foreign` (`registrado_por`),
  KEY `asistencia_justificaciones_aprobado_por_foreign` (`aprobado_por`),
  CONSTRAINT `asistencia_justificaciones_aprobado_por_foreign` FOREIGN KEY (`aprobado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `asistencia_justificaciones_asistencia_id_foreign` FOREIGN KEY (`asistencia_id`) REFERENCES `asistencias` (`id`) ON DELETE CASCADE,
  CONSTRAINT `asistencia_justificaciones_documento_id_foreign` FOREIGN KEY (`documento_id`) REFERENCES `documentos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `asistencia_justificaciones_registrado_por_foreign` FOREIGN KEY (`registrado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asistencias`
--

DROP TABLE IF EXISTS `asistencias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asistencias` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `clase_id` bigint unsigned NOT NULL,
  `alumno_id` bigint unsigned NOT NULL,
  `estado` enum('presente','ausente','justificado','tarde') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'presente',
  `anotacion` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `asistencias_clase_alumno_unique` (`clase_id`,`alumno_id`),
  KEY `asistencias_clase_estado_idx` (`clase_id`,`estado`),
  KEY `asistencias_alumno_id_index` (`alumno_id`),
  CONSTRAINT `asistencias_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `asistencias_clase_id_foreign` FOREIGN KEY (`clase_id`) REFERENCES `clases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=50476 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `clases`
--

DROP TABLE IF EXISTS `clases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `curso_id` bigint unsigned NOT NULL,
  `fecha` date NOT NULL,
  `numero_clase` int unsigned NOT NULL,
  `hora_inicio` time DEFAULT NULL,
  `hora_fin` time DEFAULT NULL,
  `responsable_id` bigint unsigned DEFAULT NULL,
  `tipo_responsable` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado` enum('dictada','suspendida') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dictada',
  `comentario` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clases_curso_numero_unique` (`curso_id`,`numero_clase`),
  UNIQUE KEY `clases_curso_fecha_unique` (`curso_id`,`fecha`),
  KEY `clases_fecha_index` (`fecha`),
  KEY `clases_responsable_id_foreign` (`responsable_id`),
  CONSTRAINT `clases_curso_id_foreign` FOREIGN KEY (`curso_id`) REFERENCES `cursos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `clases_responsable_id_foreign` FOREIGN KEY (`responsable_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=25816 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cursos`
--

DROP TABLE IF EXISTS `cursos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cursos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `anio` tinyint unsigned NOT NULL,
  `division` char(1) COLLATE utf8mb4_unicode_ci NOT NULL,
  `turno` enum('manana','tarde','noche') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cupo` smallint unsigned DEFAULT NULL,
  `especialidad` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cursos_anio_division_unique` (`anio`,`division`)
) ENGINE=InnoDB AUTO_INCREMENT=75 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `documentos`
--

DROP TABLE IF EXISTS `documentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre_archivo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ruta` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tamano` int unsigned NOT NULL DEFAULT '0',
  `roles` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `documentos_user_id_index` (`user_id`),
  CONSTRAINT `documentos_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `horario_celdas`
--

DROP TABLE IF EXISTS `horario_celdas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `horario_celdas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `curso_id` bigint unsigned NOT NULL,
  `dia` tinyint unsigned NOT NULL,
  `slot_key` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grupo` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `materia_id` bigint unsigned DEFAULT NULL,
  `profesor_id` bigint unsigned DEFAULT NULL,
  `aula` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hora_inicio` time DEFAULT NULL,
  `hora_fin` time DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `horario_celdas_curso_dia_slot_grupo_unique` (`curso_id`,`dia`,`slot_key`,`grupo`),
  KEY `horario_celdas_materia_id_index` (`materia_id`),
  KEY `horario_celdas_profesor_id_index` (`profesor_id`),
  KEY `hc_curso_materia_dia_slot_idx` (`curso_id`,`materia_id`,`dia`,`slot_key`),
  CONSTRAINT `horario_celdas_curso_id_foreign` FOREIGN KEY (`curso_id`) REFERENCES `cursos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `horario_celdas_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2939 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `materias`
--

DROP TABLE IF EXISTS `materias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `materias` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `anio` tinyint unsigned NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aula',
  `especialidad` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `materias_anio_nombre_tipo_especialidad_unique` (`anio`,`nombre`,`tipo`,(coalesce(`especialidad`,_utf8mb4'')))
) ENGINE=InnoDB AUTO_INCREMENT=325 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `parametro_divisiones`
--

DROP TABLE IF EXISTS `parametro_divisiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parametro_divisiones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `anio` tinyint unsigned NOT NULL,
  `division` char(1) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parametro_divisiones_anio_division_unique` (`anio`,`division`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `profesor_asignaciones`
--

DROP TABLE IF EXISTS `profesor_asignaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `profesor_asignaciones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `profesor_id` bigint unsigned NOT NULL,
  `anio` tinyint unsigned NOT NULL,
  `division` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grupo` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `materia_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prof_asig_unique` (`profesor_id`,`anio`,`division`,`grupo`,`materia_id`),
  KEY `profesor_asignaciones_anio_division_materia_id_index` (`anio`,`division`,`materia_id`),
  KEY `profesor_asignaciones_materia_id_foreign` (`materia_id`),
  KEY `profesor_asignaciones_deleted_at_index` (`deleted_at`),
  CONSTRAINT `profesor_asignaciones_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE SET NULL,
  CONSTRAINT `profesor_asignaciones_profesor_id_foreign` FOREIGN KEY (`profesor_id`) REFERENCES `profesores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=809 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `profesor_bajas`
--

DROP TABLE IF EXISTS `profesor_bajas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `profesor_bajas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `profesor_id` bigint unsigned DEFAULT NULL,
  `motivo` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `profesor_bajas_user_id_index` (`user_id`),
  KEY `pb_profesor_fecha_idx` (`profesor_id`,`created_at`),
  CONSTRAINT `profesor_bajas_profesor_id_foreign` FOREIGN KEY (`profesor_id`) REFERENCES `profesores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `profesor_bajas_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `profesor_suplencias`
--

DROP TABLE IF EXISTS `profesor_suplencias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `profesor_suplencias` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `titular_id` bigint unsigned NOT NULL,
  `suplente_id` bigint unsigned NOT NULL,
  `anio` tinyint unsigned NOT NULL,
  `division` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `materia_id` bigint unsigned NOT NULL,
  `motivo` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prof_suplencias_unique` (`titular_id`,`suplente_id`,`anio`,`division`,`materia_id`),
  KEY `prof_suplencias_supl_idx` (`suplente_id`,`anio`,`division`,`materia_id`),
  KEY `profesor_suplencias_materia_id_index` (`materia_id`),
  CONSTRAINT `profesor_suplencias_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE CASCADE,
  CONSTRAINT `profesor_suplencias_suplente_id_foreign` FOREIGN KEY (`suplente_id`) REFERENCES `profesores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `profesor_suplencias_titular_id_foreign` FOREIGN KEY (`titular_id`) REFERENCES `profesores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `profesores`
--

DROP TABLE IF EXISTS `profesores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `profesores` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dni` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` enum('Activo','Licencia') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Activo',
  `suplente` tinyint(1) NOT NULL DEFAULT '0',
  `observaciones` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_reason` text COLLATE utf8mb4_unicode_ci,
  `deleted_by` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `profesores_email_unique` (`email`),
  KEY `profesores_apellido_nombre_index` (`apellido`,`nombre`),
  KEY `profesores_estado_index` (`estado`),
  KEY `profesores_deleted_at_index` (`deleted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles_config`
--

DROP TABLE IF EXISTS `roles_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles_config` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rol` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nivel` tinyint NOT NULL DEFAULT '1',
  `tipo` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'personalizado',
  `permitidos` json DEFAULT NULL,
  `negados` json DEFAULT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `alcance` json DEFAULT NULL,
  `modulos` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_config_rol_unique` (`rol`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_anio_division`
--

DROP TABLE IF EXISTS `user_anio_division`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_anio_division` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `anio` tinyint unsigned NOT NULL,
  `division` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_anio_division_user_id_anio_division_unique` (`user_id`,`anio`,`division`),
  CONSTRAINT `user_anio_division_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dni` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_token` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT 'profesor',
  `rol_solicitado` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','active','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `debe_cambiar_password` tinyint(1) NOT NULL DEFAULT '0',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_dni_unique` (`dni`),
  UNIQUE KEY `users_api_token_unique` (`api_token`),
  KEY `users_role_index` (`role`),
  KEY `users_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ventana_nota_final`
--

DROP TABLE IF EXISTS `ventana_nota_final`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ventana_nota_final` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fecha_inicio` datetime NOT NULL,
  `fecha_fin` datetime NOT NULL,
  `ciclo_lectivo` int DEFAULT NULL,
  `alcance` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ambos',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-05 20:09:49
