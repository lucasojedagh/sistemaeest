-- MySQL dump 10.13  Distrib 8.4.3, for Win64 (x86_64)
--
-- Host: localhost    Database: sistema_escuela
-- ------------------------------------------------------
-- Server version	8.4.3

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
-- Current Database: `sistema_escuela`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `sistema_escuela` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;

USE `sistema_escuela`;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `user_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `auditable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auditable_id` bigint unsigned DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `detalles` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_logs_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`),
  KEY `activity_logs_user_id_index` (`user_id`),
  KEY `activity_logs_action_type_index` (`action_type`),
  KEY `activity_logs_created_at_index` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=203 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES (147,1,'admin@admin.com','admin','Importó backup SQL (sistema_escuela_backup_2026-08-26.sql, 74 sentencias)','backup.import_sql',NULL,NULL,NULL,NULL,NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 07:19:12'),(148,1,'admin@admin.com','admin','Modificó calificaciones de 1 alumno','calificaciones.update','Alumno',NULL,'{\"García, Camila\": {\"p1_notas\": \"9, 8, 8, 7, 10, 6\", \"p2_notas\": \"10, 10, 8, 7\"}}','{\"García, Camila\": {\"p1_notas\": \"9, 8, 8, 7, 10, 6\", \"p2_notas\": \"10, 10, 8, 7\", \"p1_preliminar_notas\": \"8\"}}',NULL,'172.18.0.1','curl/8.13.0','2026-08-26 18:41:03'),(149,1,'admin@admin.com','admin','Modificó calificaciones de 1 alumno','calificaciones.update','Alumno',NULL,'{\"García, Camila\": {\"p1_notas\": \"9, 8, 8, 7, 10, 6\", \"p2_notas\": \"10, 10, 8, 7\"}}','{\"García, Camila\": {\"p1_notas\": \"9, 8, 8, 7, 10, 6\", \"p2_notas\": \"10, 10, 8, 7\", \"final_anio\": \"9\"}}',NULL,'172.18.0.1','curl/8.13.0','2026-08-26 21:32:10'),(150,5,'lucasojeda@gmail.com','profesor','Modificó calificaciones de 1 alumno','calificaciones.update','Alumno',NULL,'{\"García, Camila\": {\"p1_notas\": \"9, 8, 8, 7, 10, 6\", \"p2_notas\": \"10, 10, 8, 7\"}}','{\"García, Camila\": {\"p1_notas\": \"9, 8, 8, 7, 10, 6\", \"p2_notas\": \"10, 10, 8, 7\", \"final_anio\": \"8\"}}',NULL,'172.18.0.1','curl/8.13.0','2026-08-26 22:08:11'),(151,1,'admin@admin.com','admin','Descargó backup SQL','backup.download_sql',NULL,NULL,NULL,NULL,NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 22:47:09'),(152,1,'admin@admin.com','admin','Descargó backup SQL','backup.download_sql',NULL,NULL,NULL,NULL,NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-26 22:47:21'),(153,1,'admin@admin.com','admin','Eliminó documento: testing','documento.destroy','Documento',1,NULL,NULL,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-27 00:53:31'),(154,1,'admin@admin.com','admin','Subió documento: hola','documento.store','Documento',6,NULL,NULL,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-27 00:53:55'),(155,1,'admin@admin.com','admin','Actualizó divisiones','divisiones.update','ParametroDivision',NULL,'{\"Año 1\": \"1, 2, 3, 4, 5, 6, 7, 8, 9\", \"Año 2\": \"1, 2, 3, 4, 5, 6, 7, 8, 9\", \"Año 3\": \"1, 2, 3, 4, 5, 6, 7, 8, 9\", \"Año 4\": \"1, 2, 3, 4, 5, 6, 7, 8, 9\", \"Año 5\": \"1, 2, 3, 4, 5, 6, 7, 8, 9\", \"Año 6\": \"1, 2, 3, 4, 5, 6, 7, 8, 9\", \"Año 7\": \"1, 2, 3, 4, 5, 6, 7, 8, 9\"}','{\"Año 1\": \"1, 2, 3, 4, 5, 6, 7, 8\", \"Año 2\": \"1, 2, 3, 4, 5, 6, 7\", \"Año 3\": \"1, 2, 3, 4, 5, 6\", \"Año 4\": \"1, 2, 3, 4, 5, 6\", \"Año 5\": \"1, 2, 3, 4, 5\", \"Año 6\": \"1, 2, 3, 4, 5\", \"Año 7\": \"1, 2, 3, 4, 5\"}','ajustes en las divisiones','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-27 02:31:56'),(156,1,'admin@admin.com','admin','Creó alumno Podestá, Acercandro (44444444)','alumno.store','Alumno',57,NULL,'{\"dni\": \"44444444\", \"anio\": 1, \"nombre\": \"Acercandro\", \"apellido\": \"Podestá\", \"division\": \"1\"}','agregacion de alumno','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-27 23:58:20'),(157,1,'admin@admin.com','admin','Actualizó profesor Ojeda, Lucas','profesor.update','Profesor',3,NULL,NULL,'cambio de dni','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-27 23:59:00'),(158,1,'admin@admin.com','admin','Actualizó profesor Ojeda, Lucas','profesor.update','Profesor',3,NULL,NULL,'cambio','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-27 23:59:15'),(159,1,'admin@admin.com','admin','Actualizó profesor Ojeda, Lucas','profesor.update','Profesor',3,NULL,NULL,'cambio','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-27 23:59:32'),(160,1,'admin@admin.com','admin','Actualizó horario de 1.º 1.ª','horario.update','Curso',1,NULL,NULL,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 00:01:49'),(161,1,'admin@admin.com','admin','Actualizó horario de 1.º 1.ª','horario.update','Curso',1,NULL,'{\"_detalles\": \"hola amigos dl outube\"}','hola amigos dl outube','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 00:29:12'),(162,1,'admin@admin.com','admin','Actualizó horario de 1.º 1.ª','horario.update','Curso',1,NULL,'{\"_detalles\": \"\"}',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 00:29:36'),(163,1,'admin@admin.com','admin','Reemplazó materias de 1.º','materias.replace','Materia',NULL,'{\"#6\": {\"tipo\": \"aula\", \"nombre\": \"Educación Física\", \"especialidad\": null}, \"Eliminada: Danza\": {\"tipo\": \"aula\", \"nombre\": \"Danza\", \"especialidad\": null}, \"Eliminada: Taller de Huerta\": {\"tipo\": \"aula\", \"nombre\": \"Taller de Huerta\", \"especialidad\": null}, \"Eliminada: Formación Ética\": {\"tipo\": \"aula\", \"nombre\": \"Formación Ética\", \"especialidad\": null}, \"Eliminada: Taller de Carpintería\": {\"tipo\": \"aula\", \"nombre\": \"Taller de Carpintería\", \"especialidad\": null}}','{\"#6\": {\"tipo\": \"taller\", \"nombre\": \"Educación Física\", \"especialidad\": null}}','cambios de materias','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 00:34:38'),(164,1,'admin@admin.com','admin','Reemplazó materias de 1.º','materias.replace','Materia',NULL,'{\"#6\": {\"tipo\": \"taller\", \"nombre\": \"Educación Física\", \"especialidad\": null}, \"Eliminada: Danza\": {\"tipo\": \"aula\", \"nombre\": \"Danza\", \"especialidad\": null}, \"Eliminada: Taller de Huerta\": {\"tipo\": \"aula\", \"nombre\": \"Taller de Huerta\", \"especialidad\": null}, \"Eliminada: Formación Ética\": {\"tipo\": \"aula\", \"nombre\": \"Formación Ética\", \"especialidad\": null}, \"Eliminada: Taller de Carpintería\": {\"tipo\": \"aula\", \"nombre\": \"Taller de Carpintería\", \"especialidad\": null}}','{\"#6\": {\"tipo\": \"aula\", \"nombre\": \"Educación Física\", \"especialidad\": null}}','camibios','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 00:44:10'),(166,1,'admin@admin.com','admin','Reemplazó materias de 1.º','materias.replace','Materia',NULL,'{\"#6\": {\"tipo\": \"aula\", \"nombre\": \"Educación Física\", \"especialidad\": null}}','{\"#6\": {\"tipo\": \"taller\", \"nombre\": \"Educación Física\", \"especialidad\": null}}','cosas varias','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-27 22:10:29'),(167,1,'admin@admin.com','admin','Actualizó usuario lucasojeda@abc.gob.ar','user.update','User',2,'{\"name\": \"Lucas\"}','{\"name\": \"Lucas hola\"}','cambio de nombre','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-27 22:11:13'),(168,1,'admin@admin.com','admin','Reemplazó materias de 1.º','materias.replace','Materia',NULL,'{\"#6\": {\"tipo\": \"taller\", \"nombre\": \"Educación Física\", \"especialidad\": null}}','{\"#6\": {\"tipo\": \"aula\", \"nombre\": \"Educación Física\", \"especialidad\": null}}','variedad','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-27 22:11:26'),(169,1,'admin@admin.com','admin','Reemplazó materias de 1.º','materias.replace','Materia',NULL,'{\"#6\": {\"tipo\": \"aula\", \"nombre\": \"Educación Física\", \"especialidad\": null}}','{\"#6\": {\"tipo\": \"taller\", \"nombre\": \"Educación Física\", \"especialidad\": null}}','prueba de deteccion de cambios','127.0.0.1','Symfony','2026-08-27 22:14:58'),(170,1,'admin@admin.com','admin','Reemplazó materias de 1.º','materias.replace','Materia',NULL,'{\"#6\": {\"tipo\": \"taller\", \"nombre\": \"Educación Física\", \"especialidad\": null}}','{\"#6\": {\"tipo\": \"aula\", \"nombre\": \"Educación Física\", \"especialidad\": null}}','prueba de deteccion de cambios','127.0.0.1','Symfony','2026-08-27 22:15:21'),(171,1,'admin@admin.com','admin','Reemplazó materias de 1.º','materias.replace','Materia',NULL,'{\"#6\": {\"tipo\": \"aula\", \"nombre\": \"Educación Física\", \"especialidad\": null}}','{\"#6\": {\"tipo\": \"taller\", \"nombre\": \"Educación Física\", \"especialidad\": null}}','prueba deteccion cambios','127.0.0.1','Symfony','2026-08-28 01:23:04'),(172,1,'admin@admin.com','admin','Reemplazó materias de 1.º','materias.replace','Materia',NULL,'{\"#6\": {\"tipo\": \"taller\", \"nombre\": \"Educación Física\", \"especialidad\": null}, \"#70\": {\"tipo\": \"taller\", \"nombre\": \"Dibujo Técnico\", \"especialidad\": null}}','{\"#6\": {\"tipo\": \"aula\", \"nombre\": \"Educación Física\", \"especialidad\": null}, \"#70\": {\"tipo\": \"aula\", \"nombre\": \"Dibujo Técnico\", \"especialidad\": null}}','cambios en materias','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 01:24:43'),(173,1,'admin@admin.com','admin','Actualizó alumno García, Benjamín Lucas José','alumno.update','Alumno',6,'{\"grupo\": \"A\", \"recursando\": [], \"intensificacion\": [], \"fecha_nacimiento\": \"2019-07-05\"}','{\"grupo\": null, \"recursando\": [{\"anio\": 4, \"division\": \"5\", \"materia_id\": 62}], \"intensificacion\": [{\"anio\": 4, \"division\": \"5\", \"materia_id\": 62}], \"fecha_nacimiento\": null}','prueba fix recursando','127.0.0.1','Symfony','2026-08-28 01:30:07'),(174,1,'admin@admin.com','admin','Actualizó alumno García, Benjamín Lucas José','alumno.update','Alumno',6,'{\"grupo\": null, \"recursando\": [], \"fecha_nacimiento\": null}','{\"grupo\": \"A\", \"recursando\": [{\"anio\": 1, \"division\": \"1\", \"materia_id\": 4}], \"fecha_nacimiento\": \"2019-07-05\"}','no estudia el vago','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 01:30:40'),(175,1,'admin@admin.com','admin','Fallo envío de backup a lucasojeda.facu@gmail.com','backup.email_error',NULL,NULL,NULL,NULL,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 01:39:51'),(176,1,'admin@admin.com','admin','Descargó backup SQL','backup.download_sql',NULL,NULL,NULL,NULL,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 01:40:08'),(177,1,'admin@admin.com','admin','Creó usuario gagusti@abc.gob.ar con rol profesor','user.store','User',29,NULL,'{\"name\": \"Gisela\", \"role\": \"profesor\", \"email\": \"gagusti@abc.gob.ar\"}','Parcial','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 01:42:41'),(178,1,'admin@admin.com','admin','Actualizó asignaciones de gagusti@abc.gob.ar','user.assignments','User',29,'[]','{\"asignaciones\": [{\"anio\": 1, \"division\": null}]}','testsss','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 01:44:17'),(179,1,'admin@admin.com','admin','Actualizó horario de 1.º 1.ª','horario.update','Curso',1,NULL,'{\"_detalles\": \"\"}',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 01:47:22'),(180,1,'admin@admin.com','admin','Actualizó horario de 1.º 1.ª','horario.update','Curso',1,NULL,'{\"_detalles\": \"ssdfsfssdf\"}','ssdfsfssdf','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 01:47:54'),(181,1,'admin@admin.com','admin','Registró clase #2 de 1.º 1.ª (2026-08-27) con 7 alumnos','asistencia.store','Clase',3,NULL,'{\"curso\": \"1.º 1.ª\", \"fecha\": \"2026-08-27\", \"estado\": \"dictada\", \"alumnos\": 7}','asistencia','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 01:50:36'),(182,1,'admin@admin.com','admin','Modificó calificaciones de 1 alumno','calificaciones.update','Alumno',NULL,'{\"García, Camila\": {\"p1_notas\": \"9, 8, 8, 7, 10, 6\", \"p2_notas\": \"10, 10, 8, 7\"}}','{\"García, Camila\": {\"p1_notas\": \"9, 8, 8, 7, 6, 10\", \"p2_notas\": \"10, 10, 8, 7\", \"p1_preliminar_notas\": \"9.5\"}}',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 01:52:52'),(183,1,'admin@admin.com','admin','Modificó calificaciones de 1 alumno','calificaciones.update','Alumno',NULL,'{\"García, Camila\": {\"p1_notas\": \"9, 8, 8, 7, 6, 10\", \"p2_notas\": \"10, 10, 8, 7\", \"p1_preliminar_notas\": \"9.5\"}}','{\"García, Camila\": {\"p1_notas\": \"9, 8, 8, 7, 6, 9.5\", \"p2_notas\": \"10, 10, 8, 7\", \"p1_preliminar_notas\": \"9.5\"}}',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 01:53:33'),(184,1,'admin@admin.com','admin','Editó visibilidad de documento: hola','documento.update','Documento',6,NULL,NULL,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 01:54:25'),(185,1,'admin@admin.com','admin','Actualizó usuario lucasojeda@abc.gob.ar','user.update','User',2,'{\"apellido\": \"Ojeda\", \"password\": \"(anterior)\"}','{\"apellido\": \"Ojedaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\", \"password\": \"actualizada\"}','asdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadadasdadasdadadad','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 02:01:40'),(186,1,'admin@admin.com','admin','Eliminó usuario lucasojeda@abc.gob.ar','user.destroy','User',2,'{\"role\": \"preceptor\", \"email\": \"lucasojeda@abc.gob.ar\"}',NULL,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 02:16:55'),(187,1,'admin@admin.com','admin','Actualizó asignaciones de gagusti@abc.gob.ar (incluye materias que enseña)','user.assignments','User',29,'{\"asignaciones\": [{\"anio\": 1, \"division\": null}]}','{\"asignaciones\": [{\"anio\": 1, \"division\": \"6\"}], \"materias_ficha\": [{\"curso\": \"1° 6\", \"materia_id\": 70}]}','cositas','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 02:17:47'),(188,1,'admin@admin.com','admin','Creó usuario lucasiojeda@abc.gob.ar con rol profesor','user.store','User',30,NULL,'{\"name\": \"Lucasi\", \"role\": \"profesor\", \"email\": \"lucasiojeda@abc.gob.ar\"}','profesor','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 02:21:44'),(189,1,'admin@admin.com','admin','Creó usuario alepode@abc.gob.ar con rol preceptor','user.store','User',31,NULL,'{\"name\": \"Acercandro\", \"role\": \"preceptor\", \"email\": \"alepode@abc.gob.ar\"}','precetor nuevo','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 02:27:41'),(190,1,'admin@admin.com','admin','Eliminó usuario lucasojeda@gmail.com','user.destroy','User',5,'{\"role\": \"profesor\", \"email\": \"lucasojeda@gmail.com\"}',NULL,'se elimina','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 02:36:14'),(191,1,'admin@admin.com','admin','Eliminó usuario alepodesta@gmail.com','user.destroy','User',6,'{\"role\": \"jefe_preceptores\", \"email\": \"alepodesta@gmail.com\"}',NULL,'se elimina','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 02:36:32'),(192,1,'admin@admin.com','admin','Creó usuario leoleo@abc.gob.ar con rol profesor','user.store','User',32,NULL,'{\"name\": \"Leonardo\", \"role\": \"profesor\", \"email\": \"leoleo@abc.gob.ar\"}','nuevovrofe','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 02:40:40'),(193,1,'admin@admin.com','admin','Eliminó usuario leoleo@abc.gob.ar','user.destroy','User',32,'{\"role\": \"profesor\", \"email\": \"leoleo@abc.gob.ar\"}',NULL,'dar de baja','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 02:46:35'),(194,1,'admin@admin.com','admin','Creó usuario leoleo@abc.gob.ar con rol profesor','user.store','User',34,NULL,'{\"name\": \"leonidas\", \"role\": \"profesor\", \"email\": \"leoleo@abc.gob.ar\"}','nuevo profesor','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 02:47:10'),(195,1,'admin@admin.com','admin','Creó usuario leoleoleoleo@abc.gob.ar con rol profesor','user.store','User',35,NULL,'{\"name\": \"leoleo\", \"role\": \"profesor\", \"email\": \"leoleoleoleo@abc.gob.ar\"}','nurvo profe','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 02:50:17'),(196,1,'admin@admin.com','admin','Creó usuario aleale@abc.gob.ar con rol preceptor','user.store','User',36,NULL,'{\"name\": \"aleale\", \"role\": \"preceptor\", \"email\": \"aleale@abc.gob.ar\"}',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 02:51:04'),(197,1,'admin@admin.com','admin','Creó usuario asd@abc.gob.ar con rol profesor','user.store','User',37,NULL,'{\"name\": \"asdasd\", \"role\": \"profesor\", \"email\": \"asd@abc.gob.ar\"}','adsadasd','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-08-28 02:55:11'),(198,1,'admin@admin.com','admin','Actualizó horario de 1.º 1.ª (Grupo A)','horario.update','Curso',1,NULL,NULL,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 00:13:54'),(199,1,'admin@admin.com','admin','Actualizó horario de 1.º 1.ª','horario.update','Curso',1,NULL,NULL,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 00:14:09'),(200,1,'admin@admin.com','admin','Actualizó alumno García, Benjamín Lucas Joséeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','alumno.update','Alumno',6,'{\"nombre\": \"Benjamín Lucas José\"}','{\"nombre\": \"Benjamín Lucas Joséeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee\"}','nombre','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 00:16:47'),(201,1,'admin@admin.com','admin','Actualizó profesor Agustiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiii, Gisela','profesor.update','Profesor',8,NULL,NULL,'nombre cambio changes','127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 00:17:53'),(202,1,'admin@admin.com','admin','Actualizó profesor Agustiiiiiiiiiiiiiiiiii, Gisela','profesor.update','Profesor',8,NULL,NULL,NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','2026-09-03 00:18:47');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

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
  `division` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `periodo` tinyint DEFAULT NULL COMMENT '1..3=cuatrimestres',
  `tipo_calificacion` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'regular',
  `detalle` json DEFAULT NULL,
  `ciclo_lectivo` smallint unsigned DEFAULT NULL,
  `valor` decimal(4,1) NOT NULL,
  `fecha` date DEFAULT NULL,
  `descripcion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumno_calificaciones_alumno_id_anio_division_index` (`alumno_id`,`anio`,`division`),
  KEY `alumno_calificaciones_alumno_id_materia_id_index` (`alumno_id`,`materia_id`),
  KEY `idx_calif_agregados` (`alumno_id`,`materia_id`,`anio`,`division`,`ciclo_lectivo`,`periodo`),
  KEY `alumno_calificaciones_materia_id_foreign` (`materia_id`),
  KEY `alumno_calificaciones_tipo_calificacion_index` (`tipo_calificacion`),
  KEY `ac_materia_ciclo_alumno_idx` (`materia_id`,`ciclo_lectivo`,`alumno_id`),
  KEY `ac_combo_idx` (`alumno_id`,`materia_id`,`anio`,`division`,`ciclo_lectivo`,`periodo`,`tipo_calificacion`),
  CONSTRAINT `alumno_calificaciones_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alumno_calificaciones_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=527 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumno_calificaciones`
--

LOCK TABLES `alumno_calificaciones` WRITE;
/*!40000 ALTER TABLE `alumno_calificaciones` DISABLE KEYS */;
INSERT INTO `alumno_calificaciones` VALUES (1,3,56,1,'1',1,'regular',NULL,2026,8.0,'2026-06-26',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(2,3,56,1,'1',2,'regular',NULL,2026,9.0,'2026-09-03',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(3,3,55,1,'1',1,'regular',NULL,2026,10.0,'2026-03-05',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(4,3,55,1,'1',2,'regular',NULL,2026,6.0,'2026-09-09',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(5,3,7,1,'1',1,'regular',NULL,2026,6.0,'2026-06-22',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(6,3,7,1,'1',2,'regular',NULL,2026,7.0,'2026-11-14',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(7,3,2,1,'1',1,'regular',NULL,2026,9.0,'2026-03-09',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(8,3,2,1,'1',2,'regular',NULL,2026,6.0,'2026-11-08',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(9,4,6,1,'1',1,'regular',NULL,2026,10.0,'2026-03-08',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(10,4,6,1,'1',2,'regular',NULL,2026,7.0,'2026-09-13',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(11,4,5,1,'1',1,'regular',NULL,2026,6.0,'2026-04-16',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(12,4,5,1,'1',2,'regular',NULL,2026,10.0,'2026-10-04',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(13,4,7,1,'1',1,'regular',NULL,2026,6.0,'2026-04-18',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(14,4,7,1,'1',2,'regular',NULL,2026,8.0,'2026-11-22',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(15,4,43,1,'1',1,'regular',NULL,2026,8.0,'2026-03-18',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(16,4,43,1,'1',2,'regular',NULL,2026,9.0,'2026-09-13',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(19,5,43,1,'1',1,'regular',NULL,2026,7.0,'2026-03-26',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(20,5,43,1,'1',2,'regular',NULL,2026,8.0,'2026-09-06',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(21,5,7,1,'1',1,'regular',NULL,2026,8.0,'2026-04-04',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(22,5,7,1,'1',2,'regular',NULL,2026,6.0,'2026-11-11',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(23,5,55,1,'1',1,'regular',NULL,2026,7.0,'2026-03-14',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(24,5,55,1,'1',2,'regular',NULL,2026,7.0,'2026-09-21',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(25,6,55,1,'1',1,'regular',NULL,2026,9.0,'2026-03-19',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(26,6,55,1,'1',2,'regular',NULL,2026,6.0,'2026-11-25',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(27,6,1,1,'1',1,'regular',NULL,2026,10.0,'2026-05-28',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(28,6,1,1,'1',2,'regular',NULL,2026,9.0,'2026-10-13',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(29,6,44,1,'1',1,'regular',NULL,2026,7.0,'2026-05-21',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(30,6,44,1,'1',2,'regular',NULL,2026,10.0,'2026-10-25',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(31,6,7,1,'1',1,'regular',NULL,2026,6.0,'2026-05-15',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(32,6,7,1,'1',2,'regular',NULL,2026,6.0,'2026-10-07',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(33,7,5,1,'1',1,'regular',NULL,2026,9.0,'2026-03-28',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(34,7,5,1,'1',2,'regular',NULL,2026,10.0,'2026-09-13',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(35,7,55,1,'1',1,'regular',NULL,2026,8.0,'2026-03-02',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(36,7,55,1,'1',2,'regular',NULL,2026,10.0,'2026-09-26',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(37,7,2,1,'1',1,'regular',NULL,2026,8.0,'2026-03-28',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(38,7,2,1,'1',2,'regular',NULL,2026,8.0,'2026-09-16',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(41,8,7,1,'1',1,'regular',NULL,2026,7.0,'2026-04-08',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(42,8,7,1,'1',2,'regular',NULL,2026,7.0,'2026-11-17',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(43,8,2,1,'1',1,'regular',NULL,2026,8.0,'2026-06-20',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(44,8,2,1,'1',2,'regular',NULL,2026,7.0,'2026-09-09',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(45,8,6,1,'1',1,'regular',NULL,2026,10.0,'2026-04-02',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(46,8,6,1,'1',2,'regular',NULL,2026,10.0,'2026-09-27',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(49,9,56,1,'2',1,'regular',NULL,2026,9.0,'2026-03-06',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(50,9,56,1,'2',2,'regular',NULL,2026,7.0,'2026-09-12',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(51,9,44,1,'2',1,'regular',NULL,2026,6.0,'2026-06-23',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(52,9,44,1,'2',2,'regular',NULL,2026,6.0,'2026-10-17',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(53,9,6,1,'2',1,'regular',NULL,2026,6.0,'2026-04-21',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(54,9,6,1,'2',2,'regular',NULL,2026,9.0,'2026-10-25',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(55,9,2,1,'2',1,'regular',NULL,2026,10.0,'2026-06-18',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(56,9,2,1,'2',2,'regular',NULL,2026,8.0,'2026-11-18',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(57,10,6,1,'2',1,'regular',NULL,2026,9.0,'2026-05-21',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(58,10,6,1,'2',2,'regular',NULL,2026,8.0,'2026-09-28',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(59,10,43,1,'2',1,'regular',NULL,2026,6.0,'2026-05-05',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(60,10,43,1,'2',2,'regular',NULL,2026,10.0,'2026-10-16',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(61,10,5,1,'2',1,'regular',NULL,2026,10.0,'2026-03-25',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(62,10,5,1,'2',2,'regular',NULL,2026,9.0,'2026-10-28',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(63,10,3,1,'2',1,'regular',NULL,2026,6.0,'2026-05-01',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(64,10,3,1,'2',2,'regular',NULL,2026,10.0,'2026-10-05',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(65,11,3,1,'2',1,'regular',NULL,2026,10.0,'2026-06-23',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(66,11,3,1,'2',2,'regular',NULL,2026,7.0,'2026-09-27',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(67,11,4,1,'2',1,'regular',NULL,2026,9.0,'2026-05-12',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(68,11,4,1,'2',2,'regular',NULL,2026,8.0,'2026-10-23',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(69,11,56,1,'2',1,'regular',NULL,2026,7.0,'2026-06-27',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(70,11,56,1,'2',2,'regular',NULL,2026,9.0,'2026-09-06',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(71,11,44,1,'2',1,'regular',NULL,2026,10.0,'2026-03-09',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(72,11,44,1,'2',2,'regular',NULL,2026,7.0,'2026-10-08',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(73,12,3,1,'2',1,'regular',NULL,2026,10.0,'2026-04-12',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(74,12,3,1,'2',2,'regular',NULL,2026,7.0,'2026-09-11',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(75,12,2,1,'2',1,'regular',NULL,2026,8.0,'2026-05-01',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(76,12,2,1,'2',2,'regular',NULL,2026,9.0,'2026-10-17',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(77,12,56,1,'2',1,'regular',NULL,2026,10.0,'2026-06-09',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(78,12,56,1,'2',2,'regular',NULL,2026,9.0,'2026-10-11',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(79,12,5,1,'2',1,'regular',NULL,2026,8.0,'2026-05-25',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(80,12,5,1,'2',2,'regular',NULL,2026,10.0,'2026-11-26',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(81,13,44,1,'2',1,'regular',NULL,2026,10.0,'2026-05-26',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(82,13,44,1,'2',2,'regular',NULL,2026,9.0,'2026-11-20',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(83,13,2,1,'2',1,'regular',NULL,2026,8.0,'2026-03-26',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(84,13,2,1,'2',2,'regular',NULL,2026,10.0,'2026-10-21',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(85,13,7,1,'2',1,'regular',NULL,2026,7.0,'2026-04-15',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(86,13,7,1,'2',2,'regular',NULL,2026,7.0,'2026-11-11',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(87,13,5,1,'2',1,'regular',NULL,2026,8.0,'2026-05-13',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(88,13,5,1,'2',2,'regular',NULL,2026,8.0,'2026-11-21',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(89,14,43,1,'2',1,'regular',NULL,2026,8.0,'2026-05-09',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(90,14,43,1,'2',2,'regular',NULL,2026,9.0,'2026-10-09',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(91,14,5,1,'2',1,'regular',NULL,2026,7.0,'2026-03-05',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(92,14,5,1,'2',2,'regular',NULL,2026,9.0,'2026-10-09',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(93,14,4,1,'2',1,'regular',NULL,2026,6.0,'2026-03-11',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(94,14,4,1,'2',2,'regular',NULL,2026,7.0,'2026-09-15',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(95,14,3,1,'2',1,'regular',NULL,2026,10.0,'2026-04-25',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(96,14,3,1,'2',2,'regular',NULL,2026,9.0,'2026-11-13',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(97,15,4,1,'3',1,'regular',NULL,2026,8.0,'2026-05-27',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(98,15,4,1,'3',2,'regular',NULL,2026,9.0,'2026-10-23',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(99,15,5,1,'3',1,'regular',NULL,2026,8.0,'2026-05-14',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(100,15,5,1,'3',2,'regular',NULL,2026,9.0,'2026-11-08',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(101,15,44,1,'3',1,'regular',NULL,2026,10.0,'2026-06-18',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(102,15,44,1,'3',2,'regular',NULL,2026,7.0,'2026-11-07',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(103,15,7,1,'3',1,'regular',NULL,2026,7.0,'2026-04-06',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(104,15,7,1,'3',2,'regular',NULL,2026,8.0,'2026-09-25',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(105,16,56,1,'3',1,'regular',NULL,2026,9.0,'2026-05-23',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(106,16,56,1,'3',2,'regular',NULL,2026,8.0,'2026-09-13',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(107,16,43,1,'3',1,'regular',NULL,2026,9.0,'2026-06-11',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(108,16,43,1,'3',2,'regular',NULL,2026,10.0,'2026-11-04',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(109,16,55,1,'3',1,'regular',NULL,2026,10.0,'2026-06-13',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(110,16,55,1,'3',2,'regular',NULL,2026,9.0,'2026-11-08',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(111,16,7,1,'3',1,'regular',NULL,2026,9.0,'2026-06-02',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(112,16,7,1,'3',2,'regular',NULL,2026,10.0,'2026-09-22',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(113,17,4,1,'3',1,'regular',NULL,2026,10.0,'2026-04-27',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(114,17,4,1,'3',2,'regular',NULL,2026,6.0,'2026-09-15',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(115,17,5,1,'3',1,'regular',NULL,2026,6.0,'2026-03-08',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(116,17,5,1,'3',2,'regular',NULL,2026,8.0,'2026-11-13',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(117,17,56,1,'3',1,'regular',NULL,2026,8.0,'2026-05-08',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(118,17,56,1,'3',2,'regular',NULL,2026,6.0,'2026-09-13',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(119,17,7,1,'3',1,'regular',NULL,2026,9.0,'2026-04-25',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(120,17,7,1,'3',2,'regular',NULL,2026,9.0,'2026-11-17',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(121,18,7,1,'3',1,'regular',NULL,2026,8.0,'2026-04-12',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(122,18,7,1,'3',2,'regular',NULL,2026,9.0,'2026-10-11',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(123,18,4,1,'3',1,'regular',NULL,2026,10.0,'2026-03-26',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(124,18,4,1,'3',2,'regular',NULL,2026,9.0,'2026-09-02',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(125,18,43,1,'3',1,'regular',NULL,2026,8.0,'2026-04-15',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(126,18,43,1,'3',2,'regular',NULL,2026,6.0,'2026-10-25',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(127,18,2,1,'3',1,'regular',NULL,2026,10.0,'2026-03-01',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(128,18,2,1,'3',2,'regular',NULL,2026,9.0,'2026-11-25',NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(129,19,1,1,'3',1,'regular',NULL,2026,7.0,'2026-06-12',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(130,19,1,1,'3',2,'regular',NULL,2026,9.0,'2026-11-10',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(131,19,44,1,'3',1,'regular',NULL,2026,10.0,'2026-04-28',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(132,19,44,1,'3',2,'regular',NULL,2026,9.0,'2026-09-22',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(133,19,56,1,'3',1,'regular',NULL,2026,6.0,'2026-06-09',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(134,19,56,1,'3',2,'regular',NULL,2026,8.0,'2026-09-17',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(135,19,6,1,'3',1,'regular',NULL,2026,10.0,'2026-05-27',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(136,19,6,1,'3',2,'regular',NULL,2026,7.0,'2026-10-18',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(137,20,3,1,'3',1,'regular',NULL,2026,6.0,'2026-03-22',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(138,20,3,1,'3',2,'regular',NULL,2026,10.0,'2026-10-12',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(139,20,1,1,'3',1,'regular',NULL,2026,7.0,'2026-05-12',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(140,20,1,1,'3',2,'regular',NULL,2026,8.0,'2026-10-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(141,20,7,1,'3',1,'regular',NULL,2026,8.0,'2026-04-19',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(142,20,7,1,'3',2,'regular',NULL,2026,10.0,'2026-09-02',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(143,20,6,1,'3',1,'regular',NULL,2026,6.0,'2026-04-17',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(144,20,6,1,'3',2,'regular',NULL,2026,7.0,'2026-10-12',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(145,21,9,2,'1',1,'regular',NULL,2026,7.0,'2026-04-10',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(146,21,9,2,'1',2,'regular',NULL,2026,8.0,'2026-10-12',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(147,21,13,2,'1',1,'regular',NULL,2026,9.0,'2026-05-27',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(148,21,13,2,'1',2,'regular',NULL,2026,8.0,'2026-10-05',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(149,21,45,2,'1',1,'regular',NULL,2026,8.0,'2026-04-23',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(150,21,45,2,'1',2,'regular',NULL,2026,9.0,'2026-10-21',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(151,21,57,2,'1',1,'regular',NULL,2026,6.0,'2026-04-22',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(152,21,57,2,'1',2,'regular',NULL,2026,8.0,'2026-10-08',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(153,22,13,2,'1',1,'regular',NULL,2026,9.0,'2026-03-19',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(154,22,13,2,'1',2,'regular',NULL,2026,7.0,'2026-09-28',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(155,22,14,2,'1',1,'regular',NULL,2026,6.0,'2026-04-15',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(156,22,14,2,'1',2,'regular',NULL,2026,8.0,'2026-09-21',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(157,22,8,2,'1',1,'regular',NULL,2026,8.0,'2026-04-04',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(158,22,8,2,'1',2,'regular',NULL,2026,10.0,'2026-11-22',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(159,22,10,2,'1',1,'regular',NULL,2026,10.0,'2026-06-21',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(160,22,10,2,'1',2,'regular',NULL,2026,8.0,'2026-11-27',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(161,23,11,2,'1',1,'regular',NULL,2026,7.0,'2026-03-12',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(162,23,11,2,'1',2,'regular',NULL,2026,8.0,'2026-11-15',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(163,23,9,2,'1',1,'regular',NULL,2026,7.0,'2026-03-22',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(164,23,9,2,'1',2,'regular',NULL,2026,7.0,'2026-11-02',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(165,23,46,2,'1',1,'regular',NULL,2026,8.0,'2026-04-19',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(166,23,46,2,'1',2,'regular',NULL,2026,10.0,'2026-09-27',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(167,23,12,2,'1',1,'regular',NULL,2026,10.0,'2026-03-06',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(168,23,12,2,'1',2,'regular',NULL,2026,6.0,'2026-11-05',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(169,24,9,2,'1',1,'regular',NULL,2026,10.0,'2026-04-04',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(170,24,9,2,'1',2,'regular',NULL,2026,6.0,'2026-10-03',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(171,24,12,2,'1',1,'regular',NULL,2026,9.0,'2026-03-15',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(172,24,12,2,'1',2,'regular',NULL,2026,8.0,'2026-11-15',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(173,24,57,2,'1',1,'regular',NULL,2026,6.0,'2026-03-07',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(174,24,57,2,'1',2,'regular',NULL,2026,9.0,'2026-09-04',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(175,24,14,2,'1',1,'regular',NULL,2026,8.0,'2026-05-27',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(176,24,14,2,'1',2,'regular',NULL,2026,10.0,'2026-11-02',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(177,25,12,2,'1',1,'regular',NULL,2026,8.0,'2026-03-21',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(178,25,12,2,'1',2,'regular',NULL,2026,6.0,'2026-11-17',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(179,25,8,2,'1',1,'regular',NULL,2026,10.0,'2026-05-14',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(180,25,8,2,'1',2,'regular',NULL,2026,8.0,'2026-11-16',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(181,25,58,2,'1',1,'regular',NULL,2026,8.0,'2026-04-07',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(182,25,58,2,'1',2,'regular',NULL,2026,9.0,'2026-11-15',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(183,25,10,2,'1',1,'regular',NULL,2026,10.0,'2026-04-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(184,25,10,2,'1',2,'regular',NULL,2026,9.0,'2026-09-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(185,26,9,2,'1',1,'regular',NULL,2026,7.0,'2026-06-15',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(186,26,9,2,'1',2,'regular',NULL,2026,8.0,'2026-10-02',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(187,26,13,2,'1',1,'regular',NULL,2026,6.0,'2026-04-01',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(188,26,13,2,'1',2,'regular',NULL,2026,9.0,'2026-10-03',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(189,26,45,2,'1',1,'regular',NULL,2026,6.0,'2026-04-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(190,26,45,2,'1',2,'regular',NULL,2026,6.0,'2026-10-09',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(191,26,14,2,'1',1,'regular',NULL,2026,9.0,'2026-04-14',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(192,26,14,2,'1',2,'regular',NULL,2026,9.0,'2026-10-17',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(193,27,12,2,'2',1,'regular',NULL,2026,10.0,'2026-06-15',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(194,27,12,2,'2',2,'regular',NULL,2026,9.0,'2026-11-24',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(195,27,58,2,'2',1,'regular',NULL,2026,7.0,'2026-06-27',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(196,27,58,2,'2',2,'regular',NULL,2026,7.0,'2026-11-22',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(197,27,46,2,'2',1,'regular',NULL,2026,6.0,'2026-06-20',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(198,27,46,2,'2',2,'regular',NULL,2026,6.0,'2026-10-16',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(199,27,14,2,'2',1,'regular',NULL,2026,7.0,'2026-06-28',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(200,27,14,2,'2',2,'regular',NULL,2026,8.0,'2026-10-17',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(201,28,11,2,'2',1,'regular',NULL,2026,10.0,'2026-05-27',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(202,28,11,2,'2',2,'regular',NULL,2026,9.0,'2026-09-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(203,28,45,2,'2',1,'regular',NULL,2026,10.0,'2026-05-08',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(204,28,45,2,'2',2,'regular',NULL,2026,8.0,'2026-10-04',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(205,28,13,2,'2',1,'regular',NULL,2026,8.0,'2026-05-10',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(206,28,13,2,'2',2,'regular',NULL,2026,10.0,'2026-09-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(207,28,14,2,'2',1,'regular',NULL,2026,8.0,'2026-04-26',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(208,28,14,2,'2',2,'regular',NULL,2026,6.0,'2026-09-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(209,29,10,2,'2',1,'regular',NULL,2026,8.0,'2026-04-07',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(210,29,10,2,'2',2,'regular',NULL,2026,10.0,'2026-10-01',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(211,29,46,2,'2',1,'regular',NULL,2026,9.0,'2026-03-05',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(212,29,46,2,'2',2,'regular',NULL,2026,7.0,'2026-11-24',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(213,29,58,2,'2',1,'regular',NULL,2026,7.0,'2026-03-08',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(214,29,58,2,'2',2,'regular',NULL,2026,9.0,'2026-09-16',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(215,29,9,2,'2',1,'regular',NULL,2026,9.0,'2026-03-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(216,29,9,2,'2',2,'regular',NULL,2026,10.0,'2026-10-18',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(217,30,46,2,'2',1,'regular',NULL,2026,7.0,'2026-06-02',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(218,30,46,2,'2',2,'regular',NULL,2026,9.0,'2026-11-05',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(219,30,13,2,'2',1,'regular',NULL,2026,8.0,'2026-06-09',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(220,30,13,2,'2',2,'regular',NULL,2026,9.0,'2026-09-28',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(221,30,8,2,'2',1,'regular',NULL,2026,6.0,'2026-03-23',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(222,30,8,2,'2',2,'regular',NULL,2026,7.0,'2026-11-14',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(223,30,11,2,'2',1,'regular',NULL,2026,7.0,'2026-03-01',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(224,30,11,2,'2',2,'regular',NULL,2026,8.0,'2026-09-01',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(225,31,12,2,'2',1,'regular',NULL,2026,10.0,'2026-06-18',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(226,31,12,2,'2',2,'regular',NULL,2026,10.0,'2026-10-07',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(227,31,13,2,'2',1,'regular',NULL,2026,9.0,'2026-05-28',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(228,31,13,2,'2',2,'regular',NULL,2026,9.0,'2026-09-08',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(229,31,9,2,'2',1,'regular',NULL,2026,9.0,'2026-03-03',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(230,31,9,2,'2',2,'regular',NULL,2026,9.0,'2026-11-01',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(231,31,45,2,'2',1,'regular',NULL,2026,10.0,'2026-05-14',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(232,31,45,2,'2',2,'regular',NULL,2026,9.0,'2026-11-14',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(233,32,11,2,'2',1,'regular',NULL,2026,6.0,'2026-04-21',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(234,32,11,2,'2',2,'regular',NULL,2026,9.0,'2026-11-07',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(235,32,9,2,'2',1,'regular',NULL,2026,8.0,'2026-06-04',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(236,32,9,2,'2',2,'regular',NULL,2026,8.0,'2026-11-26',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(237,32,8,2,'2',1,'regular',NULL,2026,9.0,'2026-05-20',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(238,32,8,2,'2',2,'regular',NULL,2026,10.0,'2026-10-18',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(239,32,46,2,'2',1,'regular',NULL,2026,7.0,'2026-06-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(240,32,46,2,'2',2,'regular',NULL,2026,6.0,'2026-09-28',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(241,33,10,2,'3',1,'regular',NULL,2026,8.0,'2026-05-26',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(242,33,10,2,'3',2,'regular',NULL,2026,9.0,'2026-09-21',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(243,33,11,2,'3',1,'regular',NULL,2026,7.0,'2026-06-02',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(244,33,11,2,'3',2,'regular',NULL,2026,9.0,'2026-11-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(245,33,46,2,'3',1,'regular',NULL,2026,10.0,'2026-03-19',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(246,33,46,2,'3',2,'regular',NULL,2026,8.0,'2026-10-17',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(247,33,45,2,'3',1,'regular',NULL,2026,9.0,'2026-06-21',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(248,33,45,2,'3',2,'regular',NULL,2026,8.0,'2026-11-14',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(249,34,14,2,'3',1,'regular',NULL,2026,7.0,'2026-04-09',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(250,34,14,2,'3',2,'regular',NULL,2026,10.0,'2026-09-20',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(251,34,12,2,'3',1,'regular',NULL,2026,10.0,'2026-05-17',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(252,34,12,2,'3',2,'regular',NULL,2026,6.0,'2026-11-12',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(253,34,13,2,'3',1,'regular',NULL,2026,10.0,'2026-04-15',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(254,34,13,2,'3',2,'regular',NULL,2026,9.0,'2026-10-07',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(255,34,9,2,'3',1,'regular',NULL,2026,9.0,'2026-06-18',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(256,34,9,2,'3',2,'regular',NULL,2026,6.0,'2026-09-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(257,35,46,2,'3',1,'regular',NULL,2026,9.0,'2026-03-06',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(258,35,46,2,'3',2,'regular',NULL,2026,8.0,'2026-09-16',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(259,35,10,2,'3',1,'regular',NULL,2026,9.0,'2026-05-17',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(260,35,10,2,'3',2,'regular',NULL,2026,10.0,'2026-10-03',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(261,35,8,2,'3',1,'regular',NULL,2026,10.0,'2026-05-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(262,35,8,2,'3',2,'regular',NULL,2026,8.0,'2026-11-26',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(263,35,14,2,'3',1,'regular',NULL,2026,9.0,'2026-05-14',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(264,35,14,2,'3',2,'regular',NULL,2026,6.0,'2026-09-03',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(265,36,14,2,'3',1,'regular',NULL,2026,9.0,'2026-03-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(266,36,14,2,'3',2,'regular',NULL,2026,10.0,'2026-11-04',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(267,36,13,2,'3',1,'regular',NULL,2026,8.0,'2026-04-05',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(268,36,13,2,'3',2,'regular',NULL,2026,9.0,'2026-10-24',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(269,36,8,2,'3',1,'regular',NULL,2026,6.0,'2026-06-03',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(270,36,8,2,'3',2,'regular',NULL,2026,10.0,'2026-11-10',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(271,36,45,2,'3',1,'regular',NULL,2026,10.0,'2026-06-28',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(272,36,45,2,'3',2,'regular',NULL,2026,6.0,'2026-11-03',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(273,37,12,2,'3',1,'regular',NULL,2026,9.0,'2026-03-22',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(274,37,12,2,'3',2,'regular',NULL,2026,8.0,'2026-09-24',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(275,37,10,2,'3',1,'regular',NULL,2026,7.0,'2026-05-19',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(276,37,10,2,'3',2,'regular',NULL,2026,9.0,'2026-11-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(277,37,11,2,'3',1,'regular',NULL,2026,10.0,'2026-06-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(278,37,11,2,'3',2,'regular',NULL,2026,10.0,'2026-11-04',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(279,37,45,2,'3',1,'regular',NULL,2026,9.0,'2026-03-01',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(280,37,45,2,'3',2,'regular',NULL,2026,9.0,'2026-10-09',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(281,38,45,2,'3',1,'regular',NULL,2026,10.0,'2026-06-04',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(282,38,45,2,'3',2,'regular',NULL,2026,7.0,'2026-11-07',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(283,38,13,2,'3',1,'regular',NULL,2026,9.0,'2026-06-07',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(284,38,13,2,'3',2,'regular',NULL,2026,9.0,'2026-10-17',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(285,38,8,2,'3',1,'regular',NULL,2026,10.0,'2026-06-18',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(286,38,8,2,'3',2,'regular',NULL,2026,8.0,'2026-10-05',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(287,38,57,2,'3',1,'regular',NULL,2026,9.0,'2026-05-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(288,38,57,2,'3',2,'regular',NULL,2026,8.0,'2026-10-04',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(289,39,20,3,'1',1,'regular',NULL,2026,10.0,'2026-06-13',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(290,39,20,3,'1',2,'regular',NULL,2026,9.0,'2026-11-27',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(291,39,19,3,'1',1,'regular',NULL,2026,10.0,'2026-03-01',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(292,39,19,3,'1',2,'regular',NULL,2026,9.0,'2026-11-05',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(293,39,60,3,'1',1,'regular',NULL,2026,6.0,'2026-06-15',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(294,39,60,3,'1',2,'regular',NULL,2026,6.0,'2026-11-06',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(295,39,47,3,'1',1,'regular',NULL,2026,6.0,'2026-05-08',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(296,39,47,3,'1',2,'regular',NULL,2026,7.0,'2026-11-03',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(297,40,15,3,'1',1,'regular',NULL,2026,7.0,'2026-05-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(298,40,15,3,'1',2,'regular',NULL,2026,6.0,'2026-11-15',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(299,40,59,3,'1',1,'regular',NULL,2026,7.0,'2026-04-17',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(300,40,59,3,'1',2,'regular',NULL,2026,10.0,'2026-11-09',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(301,40,18,3,'1',1,'regular',NULL,2026,7.0,'2026-03-26',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(302,40,18,3,'1',2,'regular',NULL,2026,10.0,'2026-11-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(303,40,21,3,'1',1,'regular',NULL,2026,8.0,'2026-06-26',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(304,40,21,3,'1',2,'regular',NULL,2026,9.0,'2026-10-23',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(305,41,59,3,'1',1,'regular',NULL,2026,9.0,'2026-04-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(306,41,59,3,'1',2,'regular',NULL,2026,6.0,'2026-10-02',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(307,41,48,3,'1',1,'regular',NULL,2026,10.0,'2026-06-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(308,41,48,3,'1',2,'regular',NULL,2026,7.0,'2026-09-22',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(309,41,17,3,'1',1,'regular',NULL,2026,6.0,'2026-06-24',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(310,41,17,3,'1',2,'regular',NULL,2026,9.0,'2026-09-15',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(311,41,19,3,'1',1,'regular',NULL,2026,9.0,'2026-03-09',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(312,41,19,3,'1',2,'regular',NULL,2026,7.0,'2026-09-27',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(313,42,47,3,'1',1,'regular',NULL,2026,9.0,'2026-03-20',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(314,42,47,3,'1',2,'regular',NULL,2026,8.0,'2026-10-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(315,42,59,3,'1',1,'regular',NULL,2026,8.0,'2026-05-27',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(316,42,59,3,'1',2,'regular',NULL,2026,6.0,'2026-09-22',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(317,42,18,3,'1',1,'regular',NULL,2026,7.0,'2026-03-26',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(318,42,18,3,'1',2,'regular',NULL,2026,10.0,'2026-10-17',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(319,42,60,3,'1',1,'regular',NULL,2026,9.0,'2026-03-18',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(320,42,60,3,'1',2,'regular',NULL,2026,8.0,'2026-09-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(321,43,60,3,'1',1,'regular',NULL,2026,6.0,'2026-04-19',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(322,43,60,3,'1',2,'regular',NULL,2026,9.0,'2026-10-08',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(323,43,18,3,'1',1,'regular',NULL,2026,8.0,'2026-05-20',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(324,43,18,3,'1',2,'regular',NULL,2026,6.0,'2026-10-10',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(325,43,19,3,'1',1,'regular',NULL,2026,9.0,'2026-06-03',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(326,43,19,3,'1',2,'regular',NULL,2026,8.0,'2026-11-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(327,43,59,3,'1',1,'regular',NULL,2026,7.0,'2026-04-21',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(328,43,59,3,'1',2,'regular',NULL,2026,7.0,'2026-11-09',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(329,44,48,3,'1',1,'regular',NULL,2026,9.0,'2026-05-08',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(330,44,48,3,'1',2,'regular',NULL,2026,6.0,'2026-09-12',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(331,44,59,3,'1',1,'regular',NULL,2026,9.0,'2026-04-06',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(332,44,59,3,'1',2,'regular',NULL,2026,7.0,'2026-09-18',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(333,44,16,3,'1',1,'regular',NULL,2026,6.0,'2026-06-09',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(334,44,16,3,'1',2,'regular',NULL,2026,8.0,'2026-11-22',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(335,44,15,3,'1',1,'regular',NULL,2026,9.0,'2026-06-24',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(336,44,15,3,'1',2,'regular',NULL,2026,7.0,'2026-11-07',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(337,45,47,3,'2',1,'regular',NULL,2026,6.0,'2026-03-03',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(338,45,47,3,'2',2,'regular',NULL,2026,9.0,'2026-10-18',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(339,45,18,3,'2',1,'regular',NULL,2026,8.0,'2026-06-06',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(340,45,18,3,'2',2,'regular',NULL,2026,7.0,'2026-11-05',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(341,45,17,3,'2',1,'regular',NULL,2026,10.0,'2026-05-10',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(342,45,17,3,'2',2,'regular',NULL,2026,9.0,'2026-09-01',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(343,45,60,3,'2',1,'regular',NULL,2026,9.0,'2026-06-06',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(344,45,60,3,'2',2,'regular',NULL,2026,7.0,'2026-09-17',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(345,46,19,3,'2',1,'regular',NULL,2026,6.0,'2026-04-02',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(346,46,19,3,'2',2,'regular',NULL,2026,8.0,'2026-09-14',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(347,46,18,3,'2',1,'regular',NULL,2026,10.0,'2026-05-02',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(348,46,18,3,'2',2,'regular',NULL,2026,9.0,'2026-09-12',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(349,46,20,3,'2',1,'regular',NULL,2026,9.0,'2026-03-06',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(350,46,20,3,'2',2,'regular',NULL,2026,9.0,'2026-11-06',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(351,46,48,3,'2',1,'regular',NULL,2026,8.0,'2026-06-21',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(352,46,48,3,'2',2,'regular',NULL,2026,8.0,'2026-09-17',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(353,47,17,3,'2',1,'regular',NULL,2026,10.0,'2026-03-08',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(354,47,17,3,'2',2,'regular',NULL,2026,7.0,'2026-10-22',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(355,47,47,3,'2',1,'regular',NULL,2026,9.0,'2026-04-28',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(356,47,47,3,'2',2,'regular',NULL,2026,6.0,'2026-09-05',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(357,47,21,3,'2',1,'regular',NULL,2026,8.0,'2026-04-14',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(358,47,21,3,'2',2,'regular',NULL,2026,10.0,'2026-09-23',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(359,47,60,3,'2',1,'regular',NULL,2026,6.0,'2026-03-13',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(360,47,60,3,'2',2,'regular',NULL,2026,9.0,'2026-11-05',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(361,48,47,3,'2',1,'regular',NULL,2026,8.0,'2026-05-26',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(362,48,47,3,'2',2,'regular',NULL,2026,7.0,'2026-10-10',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(363,48,18,3,'2',1,'regular',NULL,2026,8.0,'2026-04-19',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(364,48,18,3,'2',2,'regular',NULL,2026,8.0,'2026-11-07',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(365,48,19,3,'2',1,'regular',NULL,2026,8.0,'2026-03-14',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(366,48,19,3,'2',2,'regular',NULL,2026,7.0,'2026-09-12',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(367,48,48,3,'2',1,'regular',NULL,2026,6.0,'2026-03-27',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(368,48,48,3,'2',2,'regular',NULL,2026,9.0,'2026-11-05',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(369,49,16,3,'2',1,'regular',NULL,2026,10.0,'2026-06-18',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(370,49,16,3,'2',2,'regular',NULL,2026,7.0,'2026-09-02',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(371,49,18,3,'2',1,'regular',NULL,2026,9.0,'2026-04-22',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(372,49,18,3,'2',2,'regular',NULL,2026,7.0,'2026-10-06',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(373,49,59,3,'2',1,'regular',NULL,2026,6.0,'2026-06-14',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(374,49,59,3,'2',2,'regular',NULL,2026,10.0,'2026-10-12',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(375,49,15,3,'2',1,'regular',NULL,2026,6.0,'2026-03-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(376,49,15,3,'2',2,'regular',NULL,2026,6.0,'2026-11-15',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(377,50,59,3,'2',1,'regular',NULL,2026,9.0,'2026-03-02',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(378,50,59,3,'2',2,'regular',NULL,2026,9.0,'2026-09-09',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(379,50,15,3,'2',1,'regular',NULL,2026,8.0,'2026-05-16',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(380,50,15,3,'2',2,'regular',NULL,2026,9.0,'2026-11-07',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(381,50,47,3,'2',1,'regular',NULL,2026,6.0,'2026-06-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(382,50,47,3,'2',2,'regular',NULL,2026,8.0,'2026-11-20',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(383,50,18,3,'2',1,'regular',NULL,2026,8.0,'2026-03-28',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(384,50,18,3,'2',2,'regular',NULL,2026,7.0,'2026-11-02',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(385,51,15,3,'3',1,'regular',NULL,2026,8.0,'2026-03-27',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(386,51,15,3,'3',2,'regular',NULL,2026,10.0,'2026-10-01',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(387,51,17,3,'3',1,'regular',NULL,2026,7.0,'2026-03-24',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(388,51,17,3,'3',2,'regular',NULL,2026,10.0,'2026-10-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(389,51,18,3,'3',1,'regular',NULL,2026,7.0,'2026-05-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(390,51,18,3,'3',2,'regular',NULL,2026,6.0,'2026-10-04',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(391,51,60,3,'3',1,'regular',NULL,2026,6.0,'2026-05-19',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(392,51,60,3,'3',2,'regular',NULL,2026,6.0,'2026-10-28',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(393,52,16,3,'3',1,'regular',NULL,2026,10.0,'2026-03-01',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(394,52,16,3,'3',2,'regular',NULL,2026,9.0,'2026-11-28',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(395,52,17,3,'3',1,'regular',NULL,2026,6.0,'2026-04-06',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(396,52,17,3,'3',2,'regular',NULL,2026,6.0,'2026-11-10',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(397,52,19,3,'3',1,'regular',NULL,2026,9.0,'2026-06-06',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(398,52,19,3,'3',2,'regular',NULL,2026,9.0,'2026-09-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(399,52,15,3,'3',1,'regular',NULL,2026,7.0,'2026-06-08',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(400,52,15,3,'3',2,'regular',NULL,2026,6.0,'2026-09-20',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(401,53,60,3,'3',1,'regular',NULL,2026,9.0,'2026-03-20',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(402,53,60,3,'3',2,'regular',NULL,2026,6.0,'2026-11-15',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(403,53,59,3,'3',1,'regular',NULL,2026,6.0,'2026-03-22',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(404,53,59,3,'3',2,'regular',NULL,2026,7.0,'2026-10-26',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(405,53,20,3,'3',1,'regular',NULL,2026,6.0,'2026-04-13',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(406,53,20,3,'3',2,'regular',NULL,2026,9.0,'2026-10-01',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(407,53,21,3,'3',1,'regular',NULL,2026,10.0,'2026-04-26',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(408,53,21,3,'3',2,'regular',NULL,2026,8.0,'2026-09-04',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(409,54,16,3,'3',1,'regular',NULL,2026,10.0,'2026-06-14',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(410,54,16,3,'3',2,'regular',NULL,2026,7.0,'2026-09-23',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(411,54,18,3,'3',1,'regular',NULL,2026,9.0,'2026-04-03',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(412,54,18,3,'3',2,'regular',NULL,2026,10.0,'2026-10-14',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(413,54,17,3,'3',1,'regular',NULL,2026,7.0,'2026-03-01',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(414,54,17,3,'3',2,'regular',NULL,2026,7.0,'2026-11-18',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(415,54,21,3,'3',1,'regular',NULL,2026,8.0,'2026-06-05',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(416,54,21,3,'3',2,'regular',NULL,2026,10.0,'2026-10-18',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(417,55,48,3,'3',1,'regular',NULL,2026,8.0,'2026-04-02',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(418,55,48,3,'3',2,'regular',NULL,2026,6.0,'2026-10-16',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(419,55,17,3,'3',1,'regular',NULL,2026,8.0,'2026-03-25',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(420,55,17,3,'3',2,'regular',NULL,2026,8.0,'2026-10-06',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(421,55,18,3,'3',1,'regular',NULL,2026,10.0,'2026-04-01',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(422,55,18,3,'3',2,'regular',NULL,2026,10.0,'2026-10-27',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(423,55,47,3,'3',1,'regular',NULL,2026,6.0,'2026-04-18',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(424,55,47,3,'3',2,'regular',NULL,2026,6.0,'2026-11-11',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(425,56,59,3,'3',1,'regular',NULL,2026,7.0,'2026-06-19',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(426,56,59,3,'3',2,'regular',NULL,2026,10.0,'2026-10-27',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(427,56,17,3,'3',1,'regular',NULL,2026,10.0,'2026-03-24',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(428,56,17,3,'3',2,'regular',NULL,2026,8.0,'2026-11-28',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(429,56,48,3,'3',1,'regular',NULL,2026,6.0,'2026-05-02',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(430,56,48,3,'3',2,'regular',NULL,2026,6.0,'2026-10-13',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(431,56,47,3,'3',1,'regular',NULL,2026,10.0,'2026-03-04',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(432,56,47,3,'3',2,'regular',NULL,2026,6.0,'2026-09-22',NULL,'2026-07-02 20:13:52','2026-07-02 20:13:52'),(498,7,4,1,'1',1,'regular',NULL,2026,7.0,NULL,'7','2026-07-17 20:27:37','2026-07-17 20:27:37'),(499,4,4,1,'1',1,'regular',NULL,2026,10.0,NULL,'10','2026-07-17 20:27:37','2026-07-17 20:27:37'),(500,8,4,1,'1',1,'regular',NULL,2026,3.0,NULL,'3','2026-07-17 20:27:37','2026-07-17 20:27:37'),(501,3,4,1,'1',1,'regular',NULL,2026,6.0,NULL,'6','2026-07-17 20:27:37','2026-07-17 20:27:37'),(502,5,4,1,'1',1,'regular',NULL,2026,10.0,NULL,'10','2026-07-17 20:27:37','2026-07-17 20:27:37'),(503,1,4,1,'1',1,'regular',NULL,2026,8.0,NULL,'8','2026-07-17 20:27:37','2026-07-17 20:27:37'),(509,7,56,1,'1',2,'regular',NULL,2026,7.0,'2026-11-01',NULL,'2026-08-23 08:34:19','2026-08-23 08:34:19'),(513,4,67,1,'1',1,'regular','\"Carga de calificaciones (formulario)\"',2026,8.0,'2026-08-23',NULL,'2026-08-23 08:57:22','2026-08-23 08:57:22'),(514,8,67,1,'1',1,'regular','\"Carga de calificaciones (formulario)\"',2026,7.0,'2026-08-23',NULL,'2026-08-23 08:57:23','2026-08-23 08:57:23'),(515,1,56,1,'1',1,'regular',NULL,2026,9.0,'2026-08-23',NULL,'2026-08-23 09:04:23','2026-08-23 09:04:23'),(516,7,56,1,'1',1,'regular',NULL,2026,6.0,'2026-08-23',NULL,'2026-08-23 09:04:23','2026-08-23 09:04:23'),(524,7,67,1,'1',1,'regular','\"Carga de calificaciones (formulario)\"',2026,9.5,'2026-08-27',NULL,'2026-08-28 01:53:33','2026-08-28 01:53:33'),(525,7,67,1,'1',1,'preliminar','\"Carga de calificaciones (formulario)\"',2026,10.0,'2026-08-27',NULL,'2026-08-28 01:53:33','2026-08-28 01:53:33'),(526,7,67,1,'1',1,'preliminar','\"Carga de calificaciones (formulario)\"',2026,9.5,'2026-08-27',NULL,'2026-08-28 01:53:33','2026-08-28 01:53:33');
/*!40000 ALTER TABLE `alumno_calificaciones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `alumno_direcciones`
--

DROP TABLE IF EXISTS `alumno_direcciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumno_direcciones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` bigint unsigned NOT NULL,
  `titular` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `direccion` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumno_direcciones_alumno_id_index` (`alumno_id`),
  CONSTRAINT `alumno_direcciones_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumno_direcciones`
--

LOCK TABLES `alumno_direcciones` WRITE;
/*!40000 ALTER TABLE `alumno_direcciones` DISABLE KEYS */;
INSERT INTO `alumno_direcciones` VALUES (1,57,'Padre','Avenida 123','2026-08-27 23:58:20','2026-08-27 23:58:20'),(2,57,'Alumno/a','hola123','2026-08-27 23:58:20','2026-08-27 23:58:20');
/*!40000 ALTER TABLE `alumno_direcciones` ENABLE KEYS */;
UNLOCK TABLES;

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
  `materia_nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profesor_nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marcado` enum('normal','recursando','intensificacion') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `alumno_horario_overrides_alumno_id_dia_bloque_unique` (`alumno_id`,`dia`,`bloque`),
  KEY `alumno_horario_overrides_materia_id_index` (`materia_id`),
  KEY `alumno_horario_overrides_profesor_id_index` (`profesor_id`),
  CONSTRAINT `alumno_horario_overrides_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alumno_horario_overrides_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumno_horario_overrides`
--

LOCK TABLES `alumno_horario_overrides` WRITE;
/*!40000 ALTER TABLE `alumno_horario_overrides` DISABLE KEYS */;
/*!40000 ALTER TABLE `alumno_horario_overrides` ENABLE KEYS */;
UNLOCK TABLES;

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
  `division` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `materia_id` bigint unsigned DEFAULT NULL,
  `materia_nombre` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumno_intensificaciones_alumno_id_index` (`alumno_id`),
  KEY `alumno_intensificaciones_materia_id_index` (`materia_id`),
  CONSTRAINT `alumno_intensificaciones_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alumno_intensificaciones_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumno_intensificaciones`
--

LOCK TABLES `alumno_intensificaciones` WRITE;
/*!40000 ALTER TABLE `alumno_intensificaciones` DISABLE KEYS */;
/*!40000 ALTER TABLE `alumno_intensificaciones` ENABLE KEYS */;
UNLOCK TABLES;

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
  `division` char(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p1_estado` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p2_estado` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nota_final` decimal(4,2) DEFAULT NULL,
  `estado_final` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nota_intensificacion_febrero` decimal(4,2) DEFAULT NULL,
  `nota_intensificacion_julio` decimal(4,2) DEFAULT NULL,
  `nota_intensificacion_diciembre_pre` decimal(4,2) DEFAULT NULL,
  `nota_intensificacion_diciembre_post` decimal(4,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ame_unique` (`alumno_id`,`materia_id`,`ciclo_lectivo`),
  KEY `alumno_materias_estado_materia_id_index` (`materia_id`),
  KEY `alumno_materias_estado_ciclo_lectivo_index` (`ciclo_lectivo`),
  KEY `alumno_materias_estado_estado_final_index` (`estado_final`),
  KEY `ame_materia_ciclo_alumno_idx` (`materia_id`,`ciclo_lectivo`,`alumno_id`),
  CONSTRAINT `alumno_materias_estado_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alumno_materias_estado_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumno_materias_estado`
--

LOCK TABLES `alumno_materias_estado` WRITE;
/*!40000 ALTER TABLE `alumno_materias_estado` DISABLE KEYS */;
INSERT INTO `alumno_materias_estado` VALUES (1,6,4,2026,1,'1','pendiente','pendiente',NULL,'pendiente',NULL,NULL,NULL,NULL,'2026-07-07 21:12:07','2026-07-08 19:58:52'),(2,7,4,2026,1,'1','aprobado','pendiente',NULL,'intensifica_diciembre',NULL,NULL,NULL,NULL,'2026-07-08 19:58:52','2026-07-17 20:27:37'),(3,4,4,2026,1,'1','aprobado','pendiente',NULL,'intensifica_diciembre',NULL,NULL,NULL,NULL,'2026-07-08 19:58:52','2026-07-17 20:27:37'),(4,8,4,2026,1,'1','desaprobado','pendiente',NULL,'intensifica_diciembre',NULL,NULL,NULL,NULL,'2026-07-08 19:58:52','2026-07-17 20:27:37'),(5,3,4,2026,1,'1','desaprobado','pendiente',NULL,'intensifica_diciembre',NULL,NULL,NULL,NULL,'2026-07-08 19:58:52','2026-07-17 20:27:37'),(6,5,4,2026,1,'1','aprobado','pendiente',NULL,'intensifica_diciembre',NULL,NULL,NULL,NULL,'2026-07-08 19:58:52','2026-07-17 20:27:37'),(7,1,4,2026,1,'1','aprobado','pendiente',NULL,'intensifica_diciembre',NULL,NULL,NULL,NULL,'2026-07-08 19:58:52','2026-07-17 20:27:37'),(8,1,56,2026,1,'1','aprobado','pendiente',NULL,'intensifica_diciembre',NULL,NULL,NULL,NULL,'2026-08-23 08:21:29','2026-08-23 09:04:23'),(9,7,56,2026,1,'1','desaprobado','aprobado',8.00,'aprobada',NULL,NULL,NULL,NULL,'2026-08-23 08:33:39','2026-08-26 22:08:33'),(10,7,67,2026,1,'1','aprobado','pendiente',NULL,'intensifica_diciembre',NULL,NULL,NULL,NULL,'2026-08-23 08:47:43','2026-08-28 01:53:33'),(11,4,67,2026,1,'1','aprobado','pendiente',NULL,'intensifica_diciembre',NULL,NULL,NULL,NULL,'2026-08-23 08:57:22','2026-08-23 08:57:22'),(12,8,67,2026,1,'1','aprobado','pendiente',NULL,'intensifica_diciembre',NULL,NULL,NULL,NULL,'2026-08-23 08:57:23','2026-08-23 08:57:23');
/*!40000 ALTER TABLE `alumno_materias_estado` ENABLE KEYS */;
UNLOCK TABLES;

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
  `division` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ciclo_lectivo` smallint unsigned NOT NULL,
  `periodo` tinyint unsigned NOT NULL,
  `promedio` decimal(5,2) DEFAULT NULL,
  `notas_count` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ap_u_combo` (`alumno_id`,`materia_id`,`anio`,`division`,`ciclo_lectivo`,`periodo`),
  KEY `ap_ix_al` (`alumno_id`),
  KEY `ap_ix_mat` (`materia_id`),
  CONSTRAINT `alumno_promedios_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alumno_promedios_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumno_promedios`
--

LOCK TABLES `alumno_promedios` WRITE;
/*!40000 ALTER TABLE `alumno_promedios` DISABLE KEYS */;
INSERT INTO `alumno_promedios` VALUES (1,6,1,1,'1',2026,1,10.00,1,'2026-07-08 19:58:52','2026-07-08 19:58:52'),(2,6,1,1,'1',2026,2,9.00,1,'2026-07-08 19:58:52','2026-07-08 19:58:52'),(4,6,7,1,'1',2026,1,6.00,1,'2026-07-08 19:58:52','2026-07-08 19:58:52'),(5,6,7,1,'1',2026,2,6.00,1,'2026-07-08 19:58:52','2026-07-08 19:58:52'),(6,6,44,1,'1',2026,1,7.00,1,'2026-07-08 19:58:52','2026-07-08 19:58:52'),(7,6,44,1,'1',2026,2,10.00,1,'2026-07-08 19:58:52','2026-07-08 19:58:52'),(8,6,55,1,'1',2026,1,9.00,1,'2026-07-08 19:58:52','2026-07-08 19:58:52'),(9,6,55,1,'1',2026,2,6.00,1,'2026-07-08 19:58:52','2026-07-08 19:58:52'),(10,7,2,1,'1',2026,1,8.00,1,'2026-08-28 01:53:33','2026-08-28 01:53:33'),(11,7,2,1,'1',2026,2,8.00,1,'2026-08-28 01:53:33','2026-08-28 01:53:33'),(12,7,5,1,'1',2026,1,9.00,1,'2026-08-28 01:53:33','2026-08-28 01:53:33'),(13,7,5,1,'1',2026,2,10.00,1,'2026-08-28 01:53:33','2026-08-28 01:53:33'),(14,7,55,1,'1',2026,1,8.00,1,'2026-08-28 01:53:33','2026-08-28 01:53:33'),(15,7,55,1,'1',2026,2,10.00,1,'2026-08-28 01:53:33','2026-08-28 01:53:33'),(16,7,56,1,'1',2026,1,6.00,1,'2026-08-28 01:53:33','2026-08-28 01:53:33'),(17,7,56,1,'1',2026,2,7.00,1,'2026-08-28 01:53:33','2026-08-28 01:53:33'),(18,4,5,1,'1',2026,1,6.00,1,'2026-08-23 08:57:22','2026-08-23 08:57:22'),(19,4,5,1,'1',2026,2,10.00,1,'2026-08-23 08:57:22','2026-08-23 08:57:22'),(20,4,6,1,'1',2026,1,10.00,1,'2026-08-23 08:57:22','2026-08-23 08:57:22'),(21,4,6,1,'1',2026,2,7.00,1,'2026-08-23 08:57:22','2026-08-23 08:57:22'),(22,4,7,1,'1',2026,1,6.00,1,'2026-08-23 08:57:22','2026-08-23 08:57:22'),(23,4,7,1,'1',2026,2,8.00,1,'2026-08-23 08:57:22','2026-08-23 08:57:22'),(24,4,43,1,'1',2026,1,8.00,1,'2026-08-23 08:57:22','2026-08-23 08:57:22'),(25,4,43,1,'1',2026,2,9.00,1,'2026-08-23 08:57:22','2026-08-23 08:57:22'),(26,8,2,1,'1',2026,1,8.00,1,'2026-08-23 08:57:23','2026-08-23 08:57:23'),(27,8,2,1,'1',2026,2,7.00,1,'2026-08-23 08:57:23','2026-08-23 08:57:23'),(28,8,6,1,'1',2026,1,10.00,1,'2026-08-23 08:57:23','2026-08-23 08:57:23'),(29,8,6,1,'1',2026,2,10.00,1,'2026-08-23 08:57:23','2026-08-23 08:57:23'),(30,8,7,1,'1',2026,1,7.00,1,'2026-08-23 08:57:23','2026-08-23 08:57:23'),(31,8,7,1,'1',2026,2,7.00,1,'2026-08-23 08:57:23','2026-08-23 08:57:23'),(32,3,2,1,'1',2026,1,9.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(33,3,2,1,'1',2026,2,6.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(34,3,7,1,'1',2026,1,6.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(35,3,7,1,'1',2026,2,7.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(36,3,55,1,'1',2026,1,10.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(37,3,55,1,'1',2026,2,6.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(38,3,56,1,'1',2026,1,8.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(39,3,56,1,'1',2026,2,9.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(40,5,7,1,'1',2026,1,8.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(41,5,7,1,'1',2026,2,6.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(42,5,43,1,'1',2026,1,7.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(43,5,43,1,'1',2026,2,8.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(44,5,55,1,'1',2026,1,7.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(45,5,55,1,'1',2026,2,7.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(46,7,4,1,'1',2026,1,7.00,1,'2026-08-28 01:53:33','2026-08-28 01:53:33'),(47,4,4,1,'1',2026,1,10.00,1,'2026-08-23 08:57:22','2026-08-23 08:57:22'),(48,8,4,1,'1',2026,1,3.00,1,'2026-08-23 08:57:23','2026-08-23 08:57:23'),(49,3,4,1,'1',2026,1,6.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(50,5,4,1,'1',2026,1,10.00,1,'2026-07-17 20:27:37','2026-07-17 20:27:37'),(51,1,4,1,'1',2026,1,8.00,1,'2026-08-23 09:04:23','2026-08-23 09:04:23'),(52,1,56,1,'1',2026,1,9.00,1,'2026-08-23 09:04:23','2026-08-23 09:04:23'),(53,7,67,1,'1',2026,1,9.50,1,'2026-08-28 01:53:33','2026-08-28 01:53:33'),(54,4,67,1,'1',2026,1,8.00,1,'2026-08-23 08:57:22','2026-08-23 08:57:22'),(55,8,67,1,'1',2026,1,7.00,1,'2026-08-23 08:57:23','2026-08-23 08:57:23');
/*!40000 ALTER TABLE `alumno_promedios` ENABLE KEYS */;
UNLOCK TABLES;

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
  `division` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `materia_id` bigint unsigned DEFAULT NULL,
  `materia_nombre` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumno_recursandos_alumno_id_index` (`alumno_id`),
  KEY `alumno_recursandos_materia_id_index` (`materia_id`),
  CONSTRAINT `alumno_recursandos_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alumno_recursandos_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumno_recursandos`
--

LOCK TABLES `alumno_recursandos` WRITE;
/*!40000 ALTER TABLE `alumno_recursandos` DISABLE KEYS */;
INSERT INTO `alumno_recursandos` VALUES (3,6,1,'1',4,'Ciencias Naturales','2026-09-03 00:16:47','2026-09-03 00:16:47');
/*!40000 ALTER TABLE `alumno_recursandos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `alumno_responsables`
--

DROP TABLE IF EXISTS `alumno_responsables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumno_responsables` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` bigint unsigned NOT NULL,
  `nombre` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `apellido` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parentesco` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `convive` tinyint(1) NOT NULL DEFAULT '0',
  `tutor_legal` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumno_responsables_alumno_id_index` (`alumno_id`),
  CONSTRAINT `alumno_responsables_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumno_responsables`
--

LOCK TABLES `alumno_responsables` WRITE;
/*!40000 ALTER TABLE `alumno_responsables` DISABLE KEYS */;
INSERT INTO `alumno_responsables` VALUES (1,57,'Lucas','Ojeda','Padre',1,1,'2026-08-27 23:58:20','2026-08-27 23:58:20');
/*!40000 ALTER TABLE `alumno_responsables` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `alumno_telefonos`
--

DROP TABLE IF EXISTS `alumno_telefonos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumno_telefonos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumno_id` bigint unsigned NOT NULL,
  `titular` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumno_telefonos_alumno_id_index` (`alumno_id`),
  CONSTRAINT `alumno_telefonos_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumno_telefonos`
--

LOCK TABLES `alumno_telefonos` WRITE;
/*!40000 ALTER TABLE `alumno_telefonos` DISABLE KEYS */;
INSERT INTO `alumno_telefonos` VALUES (1,57,'Abuelo/a','3364555555',NULL,NULL);
/*!40000 ALTER TABLE `alumno_telefonos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `alumnos`
--

DROP TABLE IF EXISTS `alumnos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumnos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `genero` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `dni` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `anio` tinyint unsigned DEFAULT NULL,
  `division` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grupo` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `especialidad` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observaciones` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `egreso_year` smallint DEFAULT NULL,
  `egreso_anio` tinyint DEFAULT NULL,
  `egreso_division` char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `promedio_general` decimal(5,2) DEFAULT NULL,
  `estado` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Activo',
  `tiene_ppi` tinyint(1) NOT NULL DEFAULT '0',
  `tiene_ae` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `alumnos_dni_unique` (`dni`),
  KEY `alumnos_anio_div_idx` (`anio`,`division`),
  KEY `alumnos_apellido_nombre_index` (`apellido`,`nombre`),
  KEY `alumnos_promedio_general_index` (`promedio_general`),
  KEY `alumnos_estado_index` (`estado`),
  KEY `alumnos_especialidad_idx` (`especialidad`)
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumnos`
--

LOCK TABLES `alumnos` WRITE;
/*!40000 ALTER TABLE `alumnos` DISABLE KEYS */;
INSERT INTO `alumnos` VALUES (1,'Ana','Gómez','F','40123456',NULL,1,'1',NULL,NULL,NULL,'2026-07-02 20:13:50','2026-07-02 20:13:50',NULL,NULL,NULL,NULL,'Activo',0,0),(2,'Bruno','Martínez','M','39111222',NULL,3,'3',NULL,NULL,NULL,'2026-07-02 20:13:50','2026-07-02 20:13:50',NULL,NULL,NULL,NULL,'Activo',0,0),(3,'Sofía','García','F','40000000','2019-07-02',1,'1','A',NULL,NULL,'2026-07-02 20:13:51','2026-08-22 20:04:59',NULL,NULL,NULL,NULL,'Activo',0,0),(4,'Mateo','García','M','40000001','2019-07-03',1,'1','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(5,'Valentina','García','M','40000002','2019-07-04',1,'1','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(6,'Benjamín Lucas Joséeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','García','O','40000003','2019-07-05',4,'5','A','informatica',NULL,'2026-07-02 20:13:51','2026-09-03 00:16:47',NULL,NULL,NULL,NULL,'Activo',0,0),(7,'Camila','García','O','40000004','2019-07-06',1,'1',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-08-22 20:22:44',NULL,NULL,NULL,NULL,'Activo',0,0),(8,'Santiago','García','F','40000005','2019-07-07',1,'1',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(9,'Isabella','García','F','40000006','2019-07-08',1,'2','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(10,'Sebastián','García','M','40000007','2019-07-09',1,'2','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(11,'Luciana','García','F','40000008','2019-07-10',1,'2','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(12,'Facundo','García','M','40000009','2019-07-11',1,'2','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(13,'Martina','García','F','40000010','2019-07-12',1,'2',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(14,'Joaquín','García','M','40000011','2019-07-13',1,'2',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(15,'Emilia','García','F','40000012','2019-07-14',1,'3','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(16,'Tomás','García','M','40000013','2019-07-15',1,'3','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(17,'Julieta','García','F','40000014','2019-07-16',1,'3','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(18,'Nicolás','García','M','40000015','2019-07-17',1,'3','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(19,'Catalina','García','F','40000016','2019-07-18',1,'3',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(20,'Diego','García','M','40000017','2019-07-19',1,'3',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(21,'Victoria','García','F','40000018','2018-07-20',2,'1','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(22,'Lautaro','García','M','40000019','2018-07-21',2,'1','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(23,'Sofía','Rodríguez','F','40000020','2018-07-22',2,'1','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(24,'Mateo','Rodríguez','M','40000021','2018-07-23',2,'1','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(25,'Valentina','Rodríguez','M','40000022','2018-07-24',2,'1',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(26,'Benjamín','Rodríguez','F','40000023','2018-07-25',2,'1',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(27,'Camila','Rodríguez','M','40000024','2018-07-26',2,'2','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(28,'Santiago','Rodríguez','F','40000025','2018-07-27',2,'2','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(29,'Isabella','Rodríguez','F','40000026','2018-07-28',2,'2','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(30,'Sebastián','Rodríguez','M','40000027','2018-07-29',2,'2','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(31,'Luciana','Rodríguez','F','40000028','2018-07-30',2,'2',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(32,'Facundo','Rodríguez','M','40000029','2018-07-31',2,'2',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(33,'Martina','Rodríguez','F','40000030','2018-08-01',2,'3','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(34,'Joaquín','Rodríguez','M','40000031','2018-08-02',2,'3','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(35,'Emilia','Rodríguez','F','40000032','2018-08-03',2,'3','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(36,'Tomás','Rodríguez','M','40000033','2018-08-04',2,'3','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(37,'Julieta','Rodríguez','F','40000034','2018-08-05',2,'3',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(38,'Nicolás','Rodríguez','M','40000035','2018-08-06',2,'3',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(39,'Catalina','Rodríguez','F','40000036','2017-08-07',3,'1','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(40,'Diego','Rodríguez','M','40000037','2017-08-08',3,'1','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(41,'Victoria','Rodríguez','F','40000038','2017-08-09',3,'1','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(42,'Lautaro','Rodríguez','M','40000039','2017-08-10',3,'1','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(43,'Sofía','Martínez','F','40000040','2017-08-11',3,'1',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(44,'Mateo','Martínez','M','40000041','2017-08-12',3,'1',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(45,'Valentina','Martínez','M','40000042','2017-08-13',3,'2','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(46,'Benjamín','Martínez','F','40000043','2017-08-14',3,'2','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(47,'Camila','Martínez','M','40000044','2017-08-15',3,'2','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(48,'Santiago','Martínez','F','40000045','2017-08-16',3,'2','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(49,'Isabella','Martínez','F','40000046','2017-08-17',3,'2',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(50,'Sebastián','Martínez','M','40000047','2017-08-18',3,'2',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(51,'Luciana','Martínez','F','40000048','2017-08-19',3,'3','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(52,'Facundo','Martínez','M','40000049','2017-08-20',3,'3','A',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(53,'Martina','Martínez','F','40000050','2017-08-21',3,'3','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(54,'Joaquín','Martínez','M','40000051','2017-08-22',3,'3','B',NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(55,'Emilia','Martínez','F','40000052','2017-08-23',3,'3',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(56,'Tomás','Martínez','M','40000053','2017-08-24',3,'3',NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51',NULL,NULL,NULL,NULL,'Activo',0,0),(57,'Acercandro','Podestá','O','44444444','2012-07-27',1,'1',NULL,NULL,NULL,'2026-08-27 23:58:20','2026-08-27 23:58:20',NULL,NULL,NULL,NULL,'Activo',0,0);
/*!40000 ALTER TABLE `alumnos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asistencia_justificaciones`
--

DROP TABLE IF EXISTS `asistencia_justificaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asistencia_justificaciones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `asistencia_id` bigint unsigned NOT NULL,
  `motivo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `observacion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `documento_id` bigint unsigned DEFAULT NULL,
  `estado` enum('pendiente','aprobada','rechazada') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `registrado_por` bigint unsigned NOT NULL,
  `aprobado_por` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ajust_asistencia_unique` (`asistencia_id`),
  KEY `asistencia_justificaciones_estado_index` (`estado`),
  KEY `asistencia_justificaciones_documento_id_foreign` (`documento_id`),
  KEY `asistencia_justificaciones_registrado_por_foreign` (`registrado_por`),
  KEY `asistencia_justificaciones_aprobado_por_foreign` (`aprobado_por`),
  CONSTRAINT `asistencia_justificaciones_aprobado_por_foreign` FOREIGN KEY (`aprobado_por`) REFERENCES `users` (`id`),
  CONSTRAINT `asistencia_justificaciones_asistencia_id_foreign` FOREIGN KEY (`asistencia_id`) REFERENCES `asistencias` (`id`) ON DELETE CASCADE,
  CONSTRAINT `asistencia_justificaciones_documento_id_foreign` FOREIGN KEY (`documento_id`) REFERENCES `documentos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `asistencia_justificaciones_registrado_por_foreign` FOREIGN KEY (`registrado_por`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asistencia_justificaciones`
--

LOCK TABLES `asistencia_justificaciones` WRITE;
/*!40000 ALTER TABLE `asistencia_justificaciones` DISABLE KEYS */;
/*!40000 ALTER TABLE `asistencia_justificaciones` ENABLE KEYS */;
UNLOCK TABLES;

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
  `estado` enum('presente','ausente','justificado','tarde') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'presente',
  `anotacion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `asistencias_clase_alumno_unique` (`clase_id`,`alumno_id`),
  KEY `asistencias_clase_estado_idx` (`clase_id`,`estado`),
  KEY `asistencias_alumno_id_index` (`alumno_id`),
  CONSTRAINT `asistencias_alumno_id_foreign` FOREIGN KEY (`alumno_id`) REFERENCES `alumnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `asistencias_clase_id_foreign` FOREIGN KEY (`clase_id`) REFERENCES `clases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asistencias`
--

LOCK TABLES `asistencias` WRITE;
/*!40000 ALTER TABLE `asistencias` DISABLE KEYS */;
INSERT INTO `asistencias` VALUES (13,1,7,'presente',NULL,'2026-08-20 20:44:37','2026-08-20 20:44:37'),(14,1,4,'presente',NULL,'2026-08-20 20:44:37','2026-08-20 20:44:37'),(15,1,8,'presente',NULL,'2026-08-20 20:44:37','2026-08-20 20:44:37'),(16,1,3,'presente',NULL,'2026-08-20 20:44:37','2026-08-20 20:44:37'),(17,1,5,'presente',NULL,'2026-08-20 20:44:37','2026-08-20 20:44:37'),(18,1,1,'ausente',NULL,'2026-08-20 20:44:37','2026-08-20 20:44:37'),(19,2,19,'presente',NULL,'2026-08-20 22:18:09','2026-08-20 22:18:09'),(20,2,20,'presente',NULL,'2026-08-20 22:18:09','2026-08-20 22:18:09'),(21,2,15,'presente',NULL,'2026-08-20 22:18:09','2026-08-20 22:18:09'),(22,2,17,'presente',NULL,'2026-08-20 22:18:09','2026-08-20 22:18:09'),(23,2,18,'presente',NULL,'2026-08-20 22:18:09','2026-08-20 22:18:09'),(24,2,16,'presente',NULL,'2026-08-20 22:18:09','2026-08-20 22:18:09'),(25,3,7,'justificado',NULL,'2026-08-28 01:50:36','2026-08-28 01:50:36'),(26,3,4,'ausente',NULL,'2026-08-28 01:50:36','2026-08-28 01:50:36'),(27,3,8,'presente',NULL,'2026-08-28 01:50:36','2026-08-28 01:50:36'),(28,3,3,'presente',NULL,'2026-08-28 01:50:36','2026-08-28 01:50:36'),(29,3,5,'tarde',NULL,'2026-08-28 01:50:36','2026-08-28 01:50:36'),(30,3,1,'presente',NULL,'2026-08-28 01:50:36','2026-08-28 01:50:36'),(31,3,57,'presente',NULL,'2026-08-28 01:50:36','2026-08-28 01:50:36');
/*!40000 ALTER TABLE `asistencias` ENABLE KEYS */;
UNLOCK TABLES;

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
  `responsable_id` bigint unsigned NOT NULL,
  `tipo_responsable` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado` enum('dictada','suspendida') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dictada',
  `comentario` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clases_curso_numero_unique` (`curso_id`,`numero_clase`),
  UNIQUE KEY `clases_curso_fecha_unique` (`curso_id`,`fecha`),
  KEY `clases_fecha_index` (`fecha`),
  KEY `clases_responsable_id_foreign` (`responsable_id`),
  CONSTRAINT `clases_curso_id_foreign` FOREIGN KEY (`curso_id`) REFERENCES `cursos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clases_responsable_id_foreign` FOREIGN KEY (`responsable_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clases`
--

LOCK TABLES `clases` WRITE;
/*!40000 ALTER TABLE `clases` DISABLE KEYS */;
INSERT INTO `clases` VALUES (1,1,'2026-08-20',1,NULL,NULL,1,'admin','dictada',NULL,'2026-08-20 20:02:43','2026-08-20 20:02:43'),(2,3,'2026-08-20',1,NULL,NULL,1,'admin','dictada',NULL,'2026-08-20 22:18:09','2026-08-20 22:18:09'),(3,1,'2026-08-27',2,'19:49:00','19:51:00',1,'admin','dictada','Test','2026-08-28 01:50:36','2026-08-28 01:50:36');
/*!40000 ALTER TABLE `clases` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cursos`
--

DROP TABLE IF EXISTS `cursos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cursos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `anio` tinyint unsigned NOT NULL,
  `division` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `turno` enum('manana','tarde','noche') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cupo` smallint unsigned DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cursos_anio_division_unique` (`anio`,`division`)
) ENGINE=InnoDB AUTO_INCREMENT=65 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cursos`
--

LOCK TABLES `cursos` WRITE;
/*!40000 ALTER TABLE `cursos` DISABLE KEYS */;
INSERT INTO `cursos` VALUES (1,1,'1',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(2,1,'2',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(3,1,'3',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(4,1,'4',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(5,1,'5',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(6,1,'6',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(7,1,'7',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(8,1,'8',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(9,1,'9',NULL,NULL,0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(10,2,'1',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(11,2,'2',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(12,2,'3',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(13,2,'4',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(14,2,'5',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(15,2,'6',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(16,2,'7',NULL,NULL,1,'2026-07-02 20:13:49','2026-07-02 20:13:49'),(17,2,'8',NULL,NULL,0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(18,2,'9',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(19,3,'1',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(20,3,'2',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(21,3,'3',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(22,3,'4',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(23,3,'5',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(24,3,'6',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(25,3,'7',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(26,3,'8',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(27,3,'9',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(28,4,'1',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(29,4,'2',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(30,4,'3',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(31,4,'4',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(32,4,'5',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(33,4,'6',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(34,4,'7',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(35,4,'8',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(36,4,'9',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(37,5,'1',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(38,5,'2',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(39,5,'3',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(40,5,'4',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(41,5,'5',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(42,5,'6',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(43,5,'7',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(44,5,'8',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(45,5,'9',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(46,6,'1',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(47,6,'2',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(48,6,'3',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(49,6,'4',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(50,6,'5',NULL,NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(51,6,'6',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(52,6,'7',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(53,6,'8',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(54,6,'9',NULL,NULL,0,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(55,7,'1',NULL,NULL,1,'2026-07-07 19:35:20','2026-07-07 19:35:20'),(56,7,'2',NULL,NULL,1,'2026-07-07 19:35:20','2026-07-07 19:35:20'),(57,7,'3',NULL,NULL,1,'2026-07-07 19:35:20','2026-07-07 19:35:20'),(58,7,'4',NULL,NULL,1,'2026-07-07 19:35:20','2026-07-07 19:35:20'),(59,7,'5',NULL,NULL,1,'2026-07-07 19:35:20','2026-07-07 19:35:20'),(60,7,'6',NULL,NULL,0,'2026-07-07 19:35:20','2026-08-27 02:31:56'),(61,7,'7',NULL,NULL,0,'2026-07-07 19:35:20','2026-08-27 02:31:56'),(62,7,'8',NULL,NULL,0,'2026-07-07 19:35:20','2026-08-27 02:31:56'),(63,7,'9',NULL,NULL,0,'2026-07-07 19:35:20','2026-08-27 02:31:56'),(64,1,'A',NULL,NULL,0,'2026-08-23 01:24:47','2026-08-27 02:31:56');
/*!40000 ALTER TABLE `cursos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `documentos`
--

DROP TABLE IF EXISTS `documentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre_archivo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ruta` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tamano` int unsigned NOT NULL DEFAULT '0',
  `roles` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `documentos_user_id_index` (`user_id`),
  CONSTRAINT `documentos_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `documentos`
--

LOCK TABLES `documentos` WRITE;
/*!40000 ALTER TABLE `documentos` DISABLE KEYS */;
INSERT INTO `documentos` VALUES (6,1,'hola','schema_reordenado.sql','documentos/avi8QOXNeaIgNmZmCcurswOOB7tVLaKSg93pBI7B.txt','text/plain',24370,'[\"admin\", \"jefe_preceptores\", \"preceptor\", \"profesor\"]','2026-08-27 00:53:55','2026-08-28 01:54:25');
/*!40000 ALTER TABLE `documentos` ENABLE KEYS */;
UNLOCK TABLES;

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
  `slot_key` varchar(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `grupo` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `materia_id` bigint unsigned DEFAULT NULL,
  `profesor_id` bigint unsigned DEFAULT NULL,
  `aula` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hora_inicio` time DEFAULT NULL,
  `hora_fin` time DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `horario_celdas_curso_dia_slot_grupo_unique` (`curso_id`,`dia`,`slot_key`,`grupo`),
  KEY `horario_celdas_curso_id_index` (`curso_id`),
  KEY `horario_celdas_materia_id_index` (`materia_id`),
  KEY `horario_celdas_profesor_id_index` (`profesor_id`),
  CONSTRAINT `horario_celdas_curso_id_foreign` FOREIGN KEY (`curso_id`) REFERENCES `cursos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `horario_celdas_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=87 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `horario_celdas`
--

LOCK TABLES `horario_celdas` WRITE;
/*!40000 ALTER TABLE `horario_celdas` DISABLE KEYS */;
INSERT INTO `horario_celdas` VALUES (2,1,1,'h1','A',44,NULL,NULL,'07:30:00','08:30:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(3,1,1,'h1','B',1,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(4,1,1,'h2',NULL,67,NULL,NULL,'08:40:00','09:40:00','2026-07-02 20:13:51','2026-09-03 00:14:08'),(5,1,1,'h2','A',2,NULL,NULL,'08:40:00','09:40:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(6,1,1,'h2','B',2,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(7,1,1,'h3',NULL,3,NULL,NULL,'09:50:00','10:50:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(8,1,1,'h3','A',3,NULL,NULL,'09:50:00','10:50:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(9,1,1,'h3','B',3,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(10,1,1,'h4',NULL,4,NULL,NULL,'13:05:00','14:05:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(11,1,1,'h4','A',4,NULL,NULL,'13:05:00','14:05:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(12,1,1,'h4','B',4,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(13,1,1,'h5',NULL,5,NULL,NULL,'14:10:00','15:10:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(14,1,1,'h5','A',5,NULL,NULL,'14:10:00','15:10:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(15,1,1,'h5','B',5,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(16,1,1,'h6',NULL,6,NULL,NULL,'15:15:00','16:15:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(17,1,1,'h6','A',6,NULL,NULL,'15:15:00','16:15:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(18,1,1,'h6','B',6,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(19,1,2,'h1',NULL,2,NULL,NULL,'07:30:00','08:30:00','2026-07-02 20:13:51','2026-09-03 00:14:08'),(20,1,2,'h1','A',2,NULL,NULL,'07:30:00','08:30:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(21,1,2,'h1','B',2,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(22,1,2,'h2',NULL,4,NULL,NULL,'08:40:00','09:40:00','2026-07-02 20:13:51','2026-09-03 00:14:08'),(23,1,2,'h2','A',4,NULL,NULL,'08:40:00','09:40:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(24,1,2,'h2','B',4,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(25,1,2,'h3',NULL,6,NULL,NULL,'09:50:00','10:50:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(26,1,2,'h3','A',6,NULL,NULL,'09:50:00','10:50:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(27,1,2,'h3','B',6,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(28,1,2,'h4',NULL,1,NULL,NULL,'13:05:00','14:05:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(29,1,2,'h4','A',1,NULL,NULL,'13:05:00','14:05:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(30,1,2,'h4','B',1,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(31,1,2,'h5',NULL,7,NULL,NULL,'14:10:00','15:10:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(32,1,2,'h5','A',7,NULL,NULL,'14:10:00','15:10:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(33,1,2,'h5','B',7,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(34,1,3,'h1',NULL,3,NULL,NULL,'07:30:00','08:30:00','2026-07-02 20:13:51','2026-09-03 00:14:08'),(35,1,3,'h1','A',3,NULL,NULL,'07:30:00','08:30:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(36,1,3,'h1','B',3,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(37,1,3,'h2',NULL,1,NULL,NULL,'08:40:00','09:40:00','2026-07-02 20:13:51','2026-09-03 00:14:08'),(38,1,3,'h2','A',1,NULL,NULL,'08:40:00','09:40:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(39,1,3,'h2','B',1,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(40,1,3,'h3',NULL,5,NULL,NULL,'09:50:00','10:50:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(41,1,3,'h3','A',5,NULL,NULL,'09:50:00','10:50:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(42,1,3,'h3','B',5,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(43,1,3,'h4',NULL,2,NULL,NULL,'13:05:00','14:05:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(44,1,3,'h4','A',2,NULL,NULL,'13:05:00','14:05:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(45,1,3,'h4','B',2,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(46,1,3,'h5',NULL,6,NULL,NULL,'14:10:00','15:10:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(47,1,3,'h5','A',6,NULL,NULL,'14:10:00','15:10:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(48,1,3,'h5','B',6,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(49,1,4,'h1',NULL,1,NULL,NULL,'07:30:00','08:30:00','2026-07-02 20:13:51','2026-09-03 00:14:08'),(50,1,4,'h1','A',1,NULL,NULL,'07:30:00','08:30:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(51,1,4,'h1','B',1,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(52,1,4,'h2',NULL,2,NULL,NULL,'08:40:00','09:40:00','2026-07-02 20:13:51','2026-09-03 00:14:08'),(53,1,4,'h2','A',2,NULL,NULL,'08:40:00','09:40:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(54,1,4,'h2','B',2,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(55,1,4,'h3',NULL,4,NULL,NULL,'09:50:00','10:50:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(56,1,4,'h3','A',4,NULL,NULL,'09:50:00','10:50:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(57,1,4,'h3','B',4,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(58,1,4,'h4',NULL,3,NULL,NULL,'13:05:00','14:05:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(59,1,4,'h4','A',3,NULL,NULL,'13:05:00','14:05:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(60,1,4,'h4','B',3,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(61,1,4,'h5',NULL,43,NULL,NULL,'14:10:00','15:10:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(62,1,4,'h5','A',43,NULL,NULL,'14:10:00','15:10:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(63,1,4,'h5','B',55,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(64,1,4,'h6',NULL,44,NULL,NULL,'15:15:00','16:15:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(65,1,4,'h6','A',44,NULL,NULL,'15:15:00','16:15:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(66,1,4,'h6','B',56,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(67,1,5,'h1',NULL,2,NULL,NULL,'07:30:00','08:30:00','2026-07-02 20:13:51','2026-09-03 00:14:08'),(68,1,5,'h1','A',2,NULL,NULL,'07:30:00','08:30:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(69,1,5,'h1','B',2,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(70,1,5,'h2',NULL,5,NULL,NULL,'08:40:00','09:40:00','2026-07-02 20:13:51','2026-09-03 00:14:08'),(71,1,5,'h2','A',5,NULL,NULL,'08:40:00','09:40:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(72,1,5,'h2','B',5,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(73,1,5,'h3',NULL,6,NULL,NULL,'09:50:00','10:50:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(74,1,5,'h3','A',6,NULL,NULL,'09:50:00','10:50:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(75,1,5,'h3','B',6,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(76,1,5,'h4',NULL,7,NULL,NULL,'13:05:00','14:05:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(77,1,5,'h4','A',7,NULL,NULL,'13:05:00','14:05:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(78,1,5,'h4','B',7,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(79,1,5,'h5',NULL,44,NULL,NULL,'14:10:00','15:10:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(80,1,5,'h5','A',44,NULL,NULL,'14:10:00','15:10:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(81,1,5,'h5','B',56,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(82,1,5,'h6',NULL,43,NULL,NULL,'15:15:00','16:15:00','2026-07-02 20:13:51','2026-09-03 00:14:09'),(83,1,5,'h6','A',43,NULL,NULL,'15:15:00','16:15:00','2026-07-02 20:13:51','2026-09-03 00:13:54'),(84,1,5,'h6','B',55,NULL,NULL,NULL,NULL,'2026-07-02 20:13:51','2026-07-02 20:13:51'),(85,1,1,'h1',NULL,70,3,NULL,'07:30:00','08:30:00','2026-08-23 00:58:10','2026-09-03 00:14:08'),(86,1,1,'h8',NULL,67,NULL,NULL,'11:00:00','12:00:00','2026-08-28 01:47:22','2026-09-03 00:14:09');
/*!40000 ALTER TABLE `horario_celdas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `materias`
--

DROP TABLE IF EXISTS `materias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `materias` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `anio` tinyint unsigned NOT NULL,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aula',
  `especialidad` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `materias_anio_nombre_tipo_unique` (`anio`,`nombre`,`tipo`)
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `materias`
--

LOCK TABLES `materias` WRITE;
/*!40000 ALTER TABLE `materias` DISABLE KEYS */;
INSERT INTO `materias` VALUES (1,1,'Matemática','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(2,1,'Lengua','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(3,1,'Inglés','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(4,1,'Ciencias Naturales','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(5,1,'Ciencias Sociales','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(6,1,'Educación Física','aula',NULL,1,'2026-07-02 20:13:50','2026-08-28 01:24:43'),(7,1,'Formación Ética','aula',NULL,0,'2026-07-02 20:13:50','2026-08-28 01:24:43'),(8,2,'Matemática','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(9,2,'Lengua','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(10,2,'Inglés','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(11,2,'Ciencias Naturales','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(12,2,'Ciencias Sociales','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(13,2,'Educación Física','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(14,2,'Formación Ética','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(15,3,'Matemática','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(16,3,'Lengua','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(17,3,'Inglés','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(18,3,'Ciencias Naturales','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(19,3,'Ciencias Sociales','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(20,3,'Educación Física','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(21,3,'Formación Ética','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(22,4,'Matemática','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(23,4,'Lengua','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(24,4,'Inglés','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(25,4,'Biología','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(26,4,'Historia','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(27,4,'Educación Física','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(28,4,'Formación Ética','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(29,5,'Matemática','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(30,5,'Lengua','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(31,5,'Inglés','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(32,5,'Biología','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(33,5,'Historia','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(34,5,'Educación Física','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(35,5,'Formación Ética','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(36,6,'Matemática','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(37,6,'Lengua','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(38,6,'Inglés','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(39,6,'Biología','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(40,6,'Historia','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(41,6,'Educación Física','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(42,6,'Formación Ética','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(43,1,'Taller de Huerta','aula',NULL,0,'2026-07-02 20:13:50','2026-08-28 01:24:43'),(44,1,'Música','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(45,2,'Taller de Huerta','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(46,2,'Música','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(47,3,'Taller de Huerta','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(48,3,'Música','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(49,4,'Taller de Robótica','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(50,4,'Teatro','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(51,5,'Taller de Robótica','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(52,5,'Teatro','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(53,6,'Taller de Robótica','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(54,6,'Teatro','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(55,1,'Taller de Carpintería','aula',NULL,0,'2026-07-02 20:13:50','2026-08-28 01:24:43'),(56,1,'Danza','aula',NULL,0,'2026-07-02 20:13:50','2026-08-28 01:24:43'),(57,2,'Taller de Carpintería','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(58,2,'Danza','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(59,3,'Taller de Carpintería','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(60,3,'Danza','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(61,4,'Taller de Gastronomía','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(62,4,'Artes Visuales','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(63,5,'Taller de Gastronomía','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(64,5,'Artes Visuales','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(65,6,'Taller de Gastronomía','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(66,6,'Artes Visuales','aula',NULL,1,'2026-07-02 20:13:50','2026-07-02 20:13:50'),(67,1,'Carpintería','taller',NULL,1,'2026-08-22 20:22:01','2026-08-22 20:22:01'),(68,1,'Electricidad','taller',NULL,1,'2026-08-22 20:22:01','2026-08-22 20:22:01'),(69,1,'Ojalatería','taller',NULL,1,'2026-08-22 20:24:07','2026-08-22 20:24:07'),(70,1,'Dibujo Técnico','aula',NULL,1,'2026-08-22 20:58:03','2026-08-28 01:24:43');
/*!40000 ALTER TABLE `materias` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'2019_12_14_000001_create_personal_access_tokens_table',1),(2,'2026_07_02_171233_create_activity_logs_table',1),(3,'2026_07_02_171233_create_alumno_calificaciones_table',1),(4,'2026_07_02_171233_create_alumno_direcciones_table',1),(5,'2026_07_02_171233_create_alumno_horario_overrides_table',1),(6,'2026_07_02_171233_create_alumno_intensificaciones_table',1),(7,'2026_07_02_171233_create_alumno_materias_estado_table',1),(8,'2026_07_02_171233_create_alumno_promedios_table',1),(9,'2026_07_02_171233_create_alumno_recursandos_table',1),(10,'2026_07_02_171233_create_alumno_responsables_table',1),(11,'2026_07_02_171233_create_alumno_telefonos_table',1),(12,'2026_07_02_171233_create_alumnos_table',1),(13,'2026_07_02_171233_create_cursos_table',1),(14,'2026_07_02_171233_create_documentos_table',1),(15,'2026_07_02_171233_create_horario_celdas_table',1),(16,'2026_07_02_171233_create_materias_table',1),(17,'2026_07_02_171233_create_parametro_divisiones_table',1),(18,'2026_07_02_171233_create_profesor_asignaciones_table',1),(19,'2026_07_02_171233_create_profesor_bajas_table',1),(20,'2026_07_02_171233_create_profesores_table',1),(21,'2026_07_02_171233_create_user_anio_division_table',1),(22,'2026_07_02_171233_create_users_table',1),(23,'2026_07_02_171236_add_foreign_keys_to_alumno_calificaciones_table',1),(24,'2026_07_02_171236_add_foreign_keys_to_alumno_direcciones_table',1),(25,'2026_07_02_171236_add_foreign_keys_to_alumno_horario_overrides_table',1),(26,'2026_07_02_171236_add_foreign_keys_to_alumno_intensificaciones_table',1),(27,'2026_07_02_171236_add_foreign_keys_to_alumno_materias_estado_table',1),(28,'2026_07_02_171236_add_foreign_keys_to_alumno_promedios_table',1),(29,'2026_07_02_171236_add_foreign_keys_to_alumno_recursandos_table',1),(30,'2026_07_02_171236_add_foreign_keys_to_alumno_responsables_table',1),(31,'2026_07_02_171236_add_foreign_keys_to_alumno_telefonos_table',1),(32,'2026_07_02_171236_add_foreign_keys_to_documentos_table',1),(33,'2026_07_02_171236_add_foreign_keys_to_horario_celdas_table',1),(34,'2026_07_02_171236_add_foreign_keys_to_profesor_asignaciones_table',1),(35,'2026_07_02_171236_add_foreign_keys_to_profesor_bajas_table',1),(36,'2026_07_02_171236_add_foreign_keys_to_user_anio_division_table',1),(37,'2026_07_08_000001_add_performance_indexes',2),(38,'2026_07_10_000001_add_profesor_to_alumno_horario_overrides_table',3),(39,'2026_07_15_000001_unify_director_role',3),(40,'2026_07_17_000001_add_especialidad_to_alumnos_table',4),(41,'2026_07_17_000002_add_dni_to_profesores_table',5),(42,'2026_08_19_000001_drop_ac_unique_from_alumno_calificaciones',6),(43,'2026_08_19_100001_create_clases_table',6),(44,'2026_08_19_100002_create_asistencias_table',6),(45,'2026_08_19_100003_create_asistencia_justificaciones_table',6),(46,'2026_08_19_100004_add_comentario_to_clases_table',6),(47,'2026_08_20_000001_add_tipo_especialidad_to_materias',7),(48,'2026_08_21_000001_add_hora_to_horario_celdas',8),(49,'2026_08_23_100000_add_sin_rol_and_rol_solicitado_to_users',9),(50,'2026_08_24_000001_add_roles_to_documentos_table',10),(51,'2025_08_26_120000_create_ventana_nota_final_table',11);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parametro_divisiones`
--

DROP TABLE IF EXISTS `parametro_divisiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parametro_divisiones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `anio` tinyint unsigned NOT NULL,
  `division` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `parametro_divisiones_anio_division_unique` (`anio`,`division`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parametro_divisiones`
--

LOCK TABLES `parametro_divisiones` WRITE;
/*!40000 ALTER TABLE `parametro_divisiones` DISABLE KEYS */;
INSERT INTO `parametro_divisiones` VALUES (1,1,'1',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(2,1,'2',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(3,1,'3',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(4,1,'4',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(5,1,'5',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(6,1,'6',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(7,1,'7',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(8,1,'8',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(9,1,'9',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(10,2,'1',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(11,2,'2',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(12,2,'3',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(13,2,'4',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(14,2,'5',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(15,2,'6',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(16,2,'7',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(17,2,'8',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(18,2,'9',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(19,3,'1',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(20,3,'2',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(21,3,'3',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(22,3,'4',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(23,3,'5',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(24,3,'6',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(25,3,'7',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(26,3,'8',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(27,3,'9',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(28,4,'1',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(29,4,'2',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(30,4,'3',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(31,4,'4',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(32,4,'5',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(33,4,'6',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(34,4,'7',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(35,4,'8',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(36,4,'9',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(37,5,'1',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(38,5,'2',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(39,5,'3',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(40,5,'4',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(41,5,'5',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(42,5,'6',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(43,5,'7',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(44,5,'8',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(45,5,'9',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(46,6,'1',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(47,6,'2',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(48,6,'3',1,'2026-07-02 20:13:50','2026-08-27 02:31:56'),(49,6,'4',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(50,6,'5',1,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(51,6,'6',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(52,6,'7',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(53,6,'8',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(54,6,'9',0,'2026-07-02 20:13:49','2026-08-27 02:31:56'),(55,7,'1',1,'2026-07-07 19:35:20','2026-08-27 02:31:56'),(56,7,'2',1,'2026-07-07 19:35:20','2026-08-27 02:31:56'),(57,7,'3',1,'2026-07-07 19:35:20','2026-08-27 02:31:56'),(58,7,'4',1,'2026-07-07 19:35:20','2026-08-27 02:31:56'),(59,7,'5',1,'2026-07-07 19:35:20','2026-08-27 02:31:56'),(60,7,'6',0,'2026-07-07 19:35:20','2026-08-27 02:31:56'),(61,7,'7',0,'2026-07-07 19:35:20','2026-08-27 02:31:56'),(62,7,'8',0,'2026-07-07 19:35:20','2026-08-27 02:31:56'),(63,7,'9',0,'2026-07-07 19:35:20','2026-08-27 02:31:56');
/*!40000 ALTER TABLE `parametro_divisiones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
INSERT INTO `personal_access_tokens` VALUES (1,'App\\Models\\User',1,'diag','55aac9b0b3240d1e741edf33e8e0fe571bac95a19f504c2c2b9aa6d7b42952b0','[\"*\"]',NULL,NULL,'2026-08-23 08:19:32','2026-08-23 08:19:32');
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

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
  `division` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grupo` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `materia_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prof_asig_unique` (`profesor_id`,`anio`,`division`,`grupo`,`materia_id`),
  KEY `profesor_asignaciones_anio_division_materia_id_index` (`anio`,`division`,`materia_id`),
  KEY `prof_asignaciones_profesor_id_idx` (`profesor_id`),
  KEY `profesor_asignaciones_materia_id_foreign` (`materia_id`),
  KEY `profesor_asignaciones_deleted_at_index` (`deleted_at`),
  CONSTRAINT `profesor_asignaciones_materia_id_foreign` FOREIGN KEY (`materia_id`) REFERENCES `materias` (`id`) ON DELETE SET NULL,
  CONSTRAINT `profesor_asignaciones_profesor_id_foreign` FOREIGN KEY (`profesor_id`) REFERENCES `profesores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `profesor_asignaciones`
--

LOCK TABLES `profesor_asignaciones` WRITE;
/*!40000 ALTER TABLE `profesor_asignaciones` DISABLE KEYS */;
INSERT INTO `profesor_asignaciones` VALUES (24,4,1,'3',NULL,67,'2026-08-23 10:10:29','2026-08-23 10:10:29',NULL),(29,3,1,'1','A',70,'2026-08-27 23:59:32','2026-08-27 23:59:32',NULL),(30,3,1,'3','B',70,'2026-08-27 23:59:32','2026-08-27 23:59:32',NULL),(33,8,1,'6',NULL,70,'2026-09-03 00:18:47','2026-09-03 00:18:47',NULL);
/*!40000 ALTER TABLE `profesor_asignaciones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `profesor_bajas`
--

DROP TABLE IF EXISTS `profesor_bajas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `profesor_bajas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `profesor_id` bigint unsigned DEFAULT NULL,
  `motivo` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `profesor_bajas_profesor_id_index` (`profesor_id`),
  KEY `profesor_bajas_user_id_index` (`user_id`),
  CONSTRAINT `profesor_bajas_profesor_id_foreign` FOREIGN KEY (`profesor_id`) REFERENCES `profesores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `profesor_bajas_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `profesor_bajas`
--

LOCK TABLES `profesor_bajas` WRITE;
/*!40000 ALTER TABLE `profesor_bajas` DISABLE KEYS */;
INSERT INTO `profesor_bajas` VALUES (1,NULL,'sd',NULL,'2026-07-17 21:12:07','2026-07-17 21:12:07'),(2,NULL,'asd',NULL,'2026-07-17 21:12:15','2026-07-17 21:12:15'),(3,NULL,'se proto mal',NULL,'2026-07-17 21:55:35','2026-07-17 21:55:35');
/*!40000 ALTER TABLE `profesor_bajas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `profesores`
--

DROP TABLE IF EXISTS `profesores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `profesores` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dni` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` enum('Activo','Licencia') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Activo',
  `suplente` tinyint(1) NOT NULL DEFAULT '0',
  `observaciones` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `deleted_by` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `profesores_email_unique` (`email`),
  KEY `profesores_apellido_nombre_index` (`apellido`,`nombre`),
  KEY `profesores_estado_index` (`estado`),
  KEY `profesores_deleted_at_index` (`deleted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `profesores`
--

LOCK TABLES `profesores` WRITE;
/*!40000 ALTER TABLE `profesores` DISABLE KEYS */;
INSERT INTO `profesores` VALUES (3,'Lucas','Ojeda','lucasojeda@abc.gob.ar','44114655','Activo',0,NULL,'2026-07-18 05:39:40','2026-08-27 23:59:32',NULL,NULL,NULL),(4,'Lucas','Ojeda','lucasojeda123@abc.gob.ar','44114658','Activo',0,NULL,'2026-08-23 07:14:24','2026-08-23 07:14:24',NULL,NULL,NULL),(8,'Gisela','Agustiiiiiiiiiiiiiiiiii','gagusti@abc.gob.ar','12345678','Activo',0,NULL,'2026-08-28 02:17:47','2026-09-03 00:18:47',NULL,NULL,NULL),(13,'leonidas','leonel','leoleo@abc.gob.ar',NULL,'Activo',0,NULL,'2026-08-28 02:47:10','2026-08-28 02:47:10',NULL,NULL,NULL),(14,'leoleo','leoleo','leoleoleoleo@abc.gob.ar',NULL,'Activo',0,NULL,'2026-08-28 02:50:17','2026-08-28 02:50:17',NULL,NULL,NULL),(15,'asdasd','asdasd','asd@abc.gob.ar',NULL,'Activo',0,NULL,'2026-08-28 02:55:11','2026-08-28 02:55:11',NULL,NULL,NULL);
/*!40000 ALTER TABLE `profesores` ENABLE KEYS */;
UNLOCK TABLES;

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
  `division` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_anio_division_user_id_anio_division_unique` (`user_id`,`anio`,`division`),
  CONSTRAINT `user_anio_division_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_anio_division`
--

LOCK TABLES `user_anio_division` WRITE;
/*!40000 ALTER TABLE `user_anio_division` DISABLE KEYS */;
INSERT INTO `user_anio_division` VALUES (17,10,1,'3','2026-08-23 09:18:00','2026-08-23 09:18:00'),(22,29,1,'6','2026-08-28 02:17:47','2026-08-28 02:17:47');
/*!40000 ALTER TABLE `user_anio_division` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dni` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_token` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('admin','directivo','jefe_preceptores','preceptor','profesor','sin_rol') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'sin_rol',
  `rol_solicitado` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','active','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `remember_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_dni_unique` (`dni`),
  UNIQUE KEY `users_api_token_unique` (`api_token`),
  KEY `users_role_index` (`role`),
  KEY `users_status_index` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Admin','Sistema',NULL,'admin@admin.com',NULL,'$2y$12$FcEZdImTzJyBO27z34M9PuNJIG.qhtTdEQVBw52U/6vvkZWF84lwC',NULL,'admin',NULL,'active',NULL,'2026-08-28 01:59:48','2026-07-02 20:13:49','2026-09-03 00:20:31'),(4,'Alejandro','Podestá','44114611','alepodesta@abc.gob.ar',NULL,'$2y$12$jg7fC18sLsY4/gVlormSWORtvvIdcqeVjCP9V8yR/s5ZiCZVeNp7e',NULL,'jefe_preceptores',NULL,'active',NULL,NULL,'2026-07-16 18:38:42','2026-07-17 20:55:17'),(10,'Lucas','Ojeda','44114658','lucasojeda123@abc.gob.ar',NULL,'$2y$12$WG/M9yCaBotFKAZKyajZE.kyfLDp0nKhM/51rlwT/wE7hbmaEkS.S','o6f0IFWI2cZ7vd1Nedd1ukjC8NuJjVYDDDS8oNxoW8kvijcthbw2Uo7LzDTf','profesor','quiero el rol de profesor','active',NULL,'2026-09-03 00:21:02','2026-08-23 06:58:31','2026-09-03 00:21:02'),(29,'Gisela','Agusti','12345678','gagusti@abc.gob.ar',NULL,'$2y$12$ETwlA.9ZKnR.PoDGN0GN5e0DHERk.vQPzj57XCJGqpQc94CJOc0cq',NULL,'profesor',NULL,'active',NULL,NULL,'2026-08-28 01:42:41','2026-08-28 01:42:41'),(30,'Lucasi','Ojedaa','44411655','lucasiojeda@abc.gob.ar',NULL,'$2y$12$hBKwCT4xXF1lpnhOUPxmsusK4m0PMQflTTyC2UDr4MhyUV62YwsxK',NULL,'profesor',NULL,'active',NULL,NULL,'2026-08-28 02:21:44','2026-08-28 02:21:44'),(31,'Acercandro','Podestá','44114619','alepode@abc.gob.ar',NULL,'$2y$12$TL6iuFb7gBlPqu/d33C7cOtvUccUwWN7HDtNbXY.Ej23NZmYpjNIu',NULL,'preceptor',NULL,'active',NULL,NULL,'2026-08-28 02:27:41','2026-08-28 02:27:41'),(34,'leonidas','leonel','44123321','leoleo@abc.gob.ar',NULL,'$2y$12$FwGeH/rwUUV2W4Fxq9Ds1OhLxPzVfHZ4.zNWVl2KZAZZiJ/I/sSLO',NULL,'profesor',NULL,'active',NULL,NULL,'2026-08-28 02:47:10','2026-08-28 02:47:10'),(35,'leoleo','leoleo','11222333','leoleoleoleo@abc.gob.ar',NULL,'$2y$12$..Dy10hCJb5FxAIDQEQDxOAi1M0O0wCOC9615XhStY3604yP/3GT6',NULL,'profesor',NULL,'active',NULL,NULL,'2026-08-28 02:50:17','2026-08-28 02:50:17'),(36,'aleale','aleale','33123321','aleale@abc.gob.ar',NULL,'$2y$12$UzE9WG44snV6LCZG2Tanzuiz7G.EJA7wcZPpJBAk.9a.Gq7aU.Noq',NULL,'preceptor',NULL,'active',NULL,NULL,'2026-08-28 02:51:04','2026-08-28 02:51:04'),(37,'asdasd','asdasd','11222334','asd@abc.gob.ar',NULL,'$2y$12$JpwnFwTQ.RmmczTqpuRaNOE/yP7Kv1Higic753adp7MdzEwzVboza',NULL,'profesor',NULL,'active',NULL,NULL,'2026-08-28 02:55:11','2026-08-28 02:55:11');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

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
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ventana_nota_final`
--

LOCK TABLES `ventana_nota_final` WRITE;
/*!40000 ALTER TABLE `ventana_nota_final` DISABLE KEYS */;
INSERT INTO `ventana_nota_final` VALUES (6,'2026-08-26 00:00:00','2026-08-27 23:59:00',2026,1,'2026-08-27 03:05:07','2026-08-27 03:05:07');
/*!40000 ALTER TABLE `ventana_nota_final` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'sistema_escuela'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-02 19:32:57
