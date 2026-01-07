-- database.sql zmonitoring
-- MySQL 8.0+ / InnoDB / utf8mb4
-- New-install ready (single pass)

SET SQL_MODE = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET time_zone = '+00:00';

-- Create DB (optional; keep if you want installer to create database)
CREATE DATABASE IF NOT EXISTS `zmonitoring`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_0900_ai_ci;

USE `zmonitoring`;

START TRANSACTION;

-- ---------------------------------------------------------------------
-- Table: admins
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `login` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('superadmin','admin','viewer') NOT NULL DEFAULT 'admin',
  `level` tinyint UNSIGNED NOT NULL DEFAULT 2,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varbinary(16) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_login` (`login`),
  KEY `idx_admins_role` (`role`),
  KEY `idx_admins_active` (`is_active`),
  KEY `idx_admins_role_level` (`role`,`level`),

  -- Optional guardrails (adjust to your policy)
  CONSTRAINT `chk_admins_level_range` CHECK (`level` BETWEEN 1 AND 3),
  CONSTRAINT `chk_admins_active_bool` CHECK (`is_active` IN (0,1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Table: pupils
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `pupils`;
CREATE TABLE `pupils` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `surname` varchar(40) NOT NULL,
  `name` varchar(40) NOT NULL,
  `middle_name` varchar(40) DEFAULT NULL,
  `class_code` varchar(30) NOT NULL,
  `class_group` tinyint UNSIGNED NOT NULL DEFAULT 1,
  `track` enum('Aniq','Tabiiy') NOT NULL,
  `student_login` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pupils_student_login` (`student_login`),
  KEY `idx_pupils_class_code` (`class_code`),
  KEY `idx_pupils_track` (`track`),
  KEY `idx_pupils_surname_name` (`surname`,`name`),
  KEY `idx_pupils_class_id` (`class_code`,`id`),
  KEY `idx_pupils_track_class` (`track`,`class_code`),

  CONSTRAINT `chk_pupils_class_group` CHECK (`class_group` IN (1,2))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Table: subjects
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `subjects`;
CREATE TABLE `subjects` (
  `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `max_points` tinyint UNSIGNED NOT NULL DEFAULT 40,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subjects_code` (`code`),
  UNIQUE KEY `uq_subjects_name` (`name`),

  CONSTRAINT `chk_subjects_max_points` CHECK (`max_points` BETWEEN 1 AND 100)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;

-- Seed subjects (id is optional; leaving explicit ids for predictability)
INSERT INTO `subjects` (`id`, `code`, `name`, `max_points`) VALUES
(1, 'MATHEMATICS', 'Matematika', 40),
(2, 'ENGLISH', 'Ingliz tili', 40),
(3, 'ALGEBRA', 'Algebra', 40),
(4, 'GEOMETRY', 'Geometriya', 40),
(5, 'PHYSICS', 'Fizika', 40),
(6, 'BIOLOGY', 'Biologiya', 40),
(7, 'CHEMISTRY', 'Kimyo', 40)
ON DUPLICATE KEY UPDATE
  `code`=VALUES(`code`),
  `name`=VALUES(`name`),
  `max_points`=VALUES(`max_points`);

-- Keep AUTO_INCREMENT aligned if you inserted explicit ids
ALTER TABLE `subjects` AUTO_INCREMENT = 8;

-- ---------------------------------------------------------------------
-- Table: exams
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `exams`;
CREATE TABLE `exams` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `academic_year` varchar(9) NOT NULL,      -- e.g. 2025-2026
  `term` tinyint UNSIGNED DEFAULT NULL,      -- nullable if some exams are not term-based
  `exam_name` varchar(120) NOT NULL,
  `exam_date` date DEFAULT NULL,

  PRIMARY KEY (`id`),
  -- NOTE: MySQL UNIQUE allows multiple NULLs for term.
  -- If term is frequently NULL and you want uniqueness anyway, see comment at end.
  UNIQUE KEY `uq_exams_identity` (`academic_year`,`term`,`exam_name`),
  KEY `idx_exams_year_term` (`academic_year`,`term`),
  KEY `idx_exams_date` (`exam_date`),

  CONSTRAINT `chk_exams_year_format` CHECK (`academic_year` REGEXP '^[0-9]{4}-[0-9]{4}$'),
  CONSTRAINT `chk_exams_term_range` CHECK (`term` IS NULL OR (`term` BETWEEN 1 AND 6))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Table: results
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `results`;
CREATE TABLE `results` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `pupil_id` int UNSIGNED NOT NULL,
  `subject_id` smallint UNSIGNED NOT NULL,
  `exam_id` int UNSIGNED NOT NULL,
  `score` decimal(4,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- Prevent duplicate result for same pupil/subject/exam
  UNIQUE KEY `uq_results_one` (`pupil_id`,`subject_id`,`exam_id`),

  -- Analytics indexes
  KEY `idx_results_exam_subject` (`exam_id`,`subject_id`),
  KEY `idx_results_pupil_exam` (`pupil_id`,`exam_id`),
  KEY `idx_results_exam_pupil` (`exam_id`,`pupil_id`),
  KEY `idx_results_subject` (`subject_id`),

  -- Integrity
  CONSTRAINT `fk_results_exam`
    FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_results_pupil`
    FOREIGN KEY (`pupil_id`) REFERENCES `pupils` (`id`)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_results_subject`
    FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT,

  -- Decimal score allowed, but bounded
  CONSTRAINT `chk_results_score_range` CHECK (`score` >= 0 AND `score` <= 40)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;

COMMIT;

-- ---------------------------------------------------------------------
-- Notes / Optional Enhancements
-- ---------------------------------------------------------------------
-- 1) If you want exam uniqueness even when term is NULL:
--    Create a generated column term_norm and index it instead:
--    ALTER TABLE exams
--      ADD term_norm tinyint UNSIGNED GENERATED ALWAYS AS (COALESCE(term,0)) STORED,
--      DROP INDEX uq_exams_identity,
--      ADD UNIQUE KEY uq_exams_identity (academic_year, term_norm, exam_name);
--
-- 2) If you later support subjects with max_points != 40,
--    DB CHECK cannot reference subjects.max_points (cross-table).
--    Enforce that rule in import/app validation, or use triggers if you must.
