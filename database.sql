-- eMonitoring fresh install schema (rewritten from dump)
-- Purpose: create a clean schema on a new database instance.
-- Notes:
-- 1) Create/select the target database before running this file (example: zmonitoring)
-- 2) This script DROPS existing tables listed below.

SET NAMES utf8mb4;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET FOREIGN_KEY_CHECKS = 0;

-- Optional (uncomment if needed):
-- CREATE DATABASE IF NOT EXISTS `zmonitoring` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `zmonitoring`;

-- Drop existing tables for fresh install
DROP TABLE IF EXISTS
  `wm_results`,
  `results`,
  `certificates`,
  `otm_results`,
  `otm_major`,
  `wm_exams`,
  `wm_subjects`,
  `otm_exams`,
  `otm_subjects`,
  `teachers`,
  `pupils`,
  `exams`,
  `subjects`,
  `study_year`,
  `classes`,
  `certificate_subjects`,
  `admins`;

CREATE TABLE `admins` (
  `id` int UNSIGNED NOT NULL,
  `login` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('superadmin','admin','viewer') NOT NULL DEFAULT 'admin',
  `level` tinyint UNSIGNED NOT NULL DEFAULT '2',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varbinary(16) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `certificates`
--

CREATE TABLE `certificates` (
  `id` int UNSIGNED NOT NULL,
  `pupil_id` int UNSIGNED NOT NULL,
  `subject` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('Xalqaro','Milliy') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `serial_number` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `level` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `percentage` decimal(5,2) NOT NULL,
  `certificate_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issued_time` datetime NOT NULL,
  `expire_time` datetime DEFAULT NULL,
  `certificate_subject_id` int UNSIGNED DEFAULT NULL,
  `status` enum('active','expired','revoked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `notes` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `certificate_subjects`
--

CREATE TABLE `certificate_subjects` (
  `id` int UNSIGNED NOT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` bigint UNSIGNED NOT NULL,
  `class_code` varchar(20) NOT NULL,
  `grade` tinyint UNSIGNED DEFAULT NULL,
  `section` varchar(5) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exams`
--

CREATE TABLE `exams` (
  `id` int UNSIGNED NOT NULL,
  `academic_year` varchar(9) NOT NULL,
  `term` tinyint UNSIGNED DEFAULT NULL,
  `exam_name` varchar(120) NOT NULL,
  `exam_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otm_exams`
--

CREATE TABLE `otm_exams` (
  `id` bigint UNSIGNED NOT NULL,
  `study_year_id` smallint UNSIGNED NOT NULL,
  `otm_kind` enum('mock','repetition') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mock',
  `exam_title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exam_date` date NOT NULL,
  `attempt_no` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int UNSIGNED DEFAULT NULL,
  `updated_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `otm_major`
--

CREATE TABLE `otm_major` (
  `id` bigint UNSIGNED NOT NULL,
  `pupil_id` int UNSIGNED NOT NULL,
  `major1_subject_id` smallint UNSIGNED NOT NULL,
  `major2_subject_id` smallint UNSIGNED NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int UNSIGNED DEFAULT NULL,
  `updated_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otm_results`
--

CREATE TABLE `otm_results` (
  `id` bigint UNSIGNED NOT NULL,
  `pupil_id` int UNSIGNED NOT NULL,
  `study_year_id` smallint UNSIGNED DEFAULT NULL,
  `otm_kind` enum('mock','repetition') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mock',
  `exam_title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exam_date` date NOT NULL,
  `attempt_no` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `otm_exam_id` bigint UNSIGNED NOT NULL,
  `major1_subject_id` smallint UNSIGNED NOT NULL,
  `major2_subject_id` smallint UNSIGNED NOT NULL,
  `major1_correct` tinyint UNSIGNED NOT NULL DEFAULT '0' COMMENT '0..30',
  `major2_correct` tinyint UNSIGNED NOT NULL DEFAULT '0' COMMENT '0..30',
  `mandatory_ona_tili_correct` tinyint UNSIGNED NOT NULL DEFAULT '0' COMMENT '0..10',
  `mandatory_matematika_correct` tinyint UNSIGNED NOT NULL DEFAULT '0' COMMENT '0..10',
  `mandatory_uzb_tarix_correct` tinyint UNSIGNED NOT NULL DEFAULT '0' COMMENT '0..10',
  `major1_certificate_percent` decimal(5,2) DEFAULT NULL COMMENT '0..100',
  `major2_certificate_percent` decimal(5,2) DEFAULT NULL COMMENT '0..100',
  `mandatory_ona_tili_certificate_percent` decimal(5,2) DEFAULT NULL COMMENT '0..100',
  `mandatory_matematika_certificate_percent` decimal(5,2) DEFAULT NULL COMMENT '0..100',
  `mandatory_uzb_tarix_certificate_percent` decimal(5,2) DEFAULT NULL COMMENT '0..100',
  `major1_score` decimal(6,2) GENERATED ALWAYS AS (round((`major1_correct` * 3.1),2)) STORED,
  `major2_score` decimal(6,2) GENERATED ALWAYS AS (round((`major2_correct` * 2.1),2)) STORED,
  `mandatory_ona_tili_score` decimal(6,2) GENERATED ALWAYS AS (round((`mandatory_ona_tili_correct` * 1.1),2)) STORED,
  `mandatory_matematika_score` decimal(6,2) GENERATED ALWAYS AS (round((`mandatory_matematika_correct` * 1.1),2)) STORED,
  `mandatory_uzb_tarix_score` decimal(6,2) GENERATED ALWAYS AS (round((`mandatory_uzb_tarix_correct` * 1.1),2)) STORED,
  `major1_certificate_score` decimal(6,2) GENERATED ALWAYS AS ((case when (`major1_certificate_percent` is null) then NULL else round(((`major1_certificate_percent` / 100) * 93.0),2) end)) STORED,
  `major2_certificate_score` decimal(6,2) GENERATED ALWAYS AS ((case when (`major2_certificate_percent` is null) then NULL else round(((`major2_certificate_percent` / 100) * 63.0),2) end)) STORED,
  `mandatory_ona_tili_certificate_score` decimal(6,2) GENERATED ALWAYS AS ((case when (`mandatory_ona_tili_certificate_percent` is null) then NULL else round(((`mandatory_ona_tili_certificate_percent` / 100) * 11.0),2) end)) STORED,
  `mandatory_matematika_certificate_score` decimal(6,2) GENERATED ALWAYS AS ((case when (`mandatory_matematika_certificate_percent` is null) then NULL else round(((`mandatory_matematika_certificate_percent` / 100) * 11.0),2) end)) STORED,
  `mandatory_uzb_tarix_certificate_score` decimal(6,2) GENERATED ALWAYS AS ((case when (`mandatory_uzb_tarix_certificate_percent` is null) then NULL else round(((`mandatory_uzb_tarix_certificate_percent` / 100) * 11.0),2) end)) STORED,
  `major1_final_score` decimal(6,2) GENERATED ALWAYS AS (coalesce((case when (`major1_certificate_percent` is null) then NULL else round(((`major1_certificate_percent` / 100) * 93.0),2) end),round((`major1_correct` * 3.1),2))) STORED,
  `major2_final_score` decimal(6,2) GENERATED ALWAYS AS (coalesce((case when (`major2_certificate_percent` is null) then NULL else round(((`major2_certificate_percent` / 100) * 63.0),2) end),round((`major2_correct` * 2.1),2))) STORED,
  `mandatory_ona_tili_final_score` decimal(6,2) GENERATED ALWAYS AS (coalesce((case when (`mandatory_ona_tili_certificate_percent` is null) then NULL else round(((`mandatory_ona_tili_certificate_percent` / 100) * 11.0),2) end),round((`mandatory_ona_tili_correct` * 1.1),2))) STORED,
  `mandatory_matematika_final_score` decimal(6,2) GENERATED ALWAYS AS (coalesce((case when (`mandatory_matematika_certificate_percent` is null) then NULL else round(((`mandatory_matematika_certificate_percent` / 100) * 11.0),2) end),round((`mandatory_matematika_correct` * 1.1),2))) STORED,
  `mandatory_uzb_tarix_final_score` decimal(6,2) GENERATED ALWAYS AS (coalesce((case when (`mandatory_uzb_tarix_certificate_percent` is null) then NULL else round(((`mandatory_uzb_tarix_certificate_percent` / 100) * 11.0),2) end),round((`mandatory_uzb_tarix_correct` * 1.1),2))) STORED,
  `total_score` decimal(7,2) GENERATED ALWAYS AS (round(((((round((`major1_correct` * 3.1),2) + round((`major2_correct` * 2.1),2)) + round((`mandatory_ona_tili_correct` * 1.1),2)) + round((`mandatory_matematika_correct` * 1.1),2)) + round((`mandatory_uzb_tarix_correct` * 1.1),2)),2)) STORED,
  `total_score_withcert` decimal(7,2) GENERATED ALWAYS AS (round(((((coalesce((case when (`major1_certificate_percent` is null) then NULL else round(((`major1_certificate_percent` / 100) * 93.0),2) end),round((`major1_correct` * 3.1),2)) + coalesce((case when (`major2_certificate_percent` is null) then NULL else round(((`major2_certificate_percent` / 100) * 63.0),2) end),round((`major2_correct` * 2.1),2))) + coalesce((case when (`mandatory_ona_tili_certificate_percent` is null) then NULL else round(((`mandatory_ona_tili_certificate_percent` / 100) * 11.0),2) end),round((`mandatory_ona_tili_correct` * 1.1),2))) + coalesce((case when (`mandatory_matematika_certificate_percent` is null) then NULL else round(((`mandatory_matematika_certificate_percent` / 100) * 11.0),2) end),round((`mandatory_matematika_correct` * 1.1),2))) + coalesce((case when (`mandatory_uzb_tarix_certificate_percent` is null) then NULL else round(((`mandatory_uzb_tarix_certificate_percent` / 100) * 11.0),2) end),round((`mandatory_uzb_tarix_correct` * 1.1),2))),2)) STORED,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int UNSIGNED DEFAULT NULL,
  `updated_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `otm_subjects`
--

CREATE TABLE `otm_subjects` (
  `id` smallint UNSIGNED NOT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` smallint UNSIGNED NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pupils`
--

CREATE TABLE `pupils` (
  `id` int UNSIGNED NOT NULL,
  `surname` varchar(40) NOT NULL,
  `name` varchar(40) NOT NULL,
  `middle_name` varchar(40) DEFAULT NULL,
  `class_code` varchar(30) NOT NULL,
  `class_id` bigint UNSIGNED DEFAULT NULL,
  `class_group` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `track` enum('Aniq','Tabiiy') NOT NULL,
  `student_login` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `results`
--

CREATE TABLE `results` (
  `id` bigint UNSIGNED NOT NULL,
  `pupil_id` int UNSIGNED NOT NULL,
  `subject_id` smallint UNSIGNED NOT NULL,
  `exam_id` int UNSIGNED NOT NULL,
  `score` decimal(4,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `study_year`
--

CREATE TABLE `study_year` (
  `id` smallint UNSIGNED NOT NULL,
  `year_code` varchar(9) GENERATED ALWAYS AS (concat(year(`start_date`),_utf8mb4'-',year(`end_date`))) STORED,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` smallint UNSIGNED NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `max_points` tinyint UNSIGNED NOT NULL DEFAULT '40'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `id` bigint UNSIGNED NOT NULL,
  `login` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `level` enum('1','2','3') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '3',
  `full_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `short_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `class_id` bigint UNSIGNED DEFAULT NULL,
  `failed_attempts` int UNSIGNED NOT NULL DEFAULT '0',
  `last_failed_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wm_exams`
--

CREATE TABLE `wm_exams` (
  `id` int UNSIGNED NOT NULL,
  `study_year_id` smallint UNSIGNED DEFAULT NULL,
  `cycle_no` smallint UNSIGNED DEFAULT NULL,
  `exam_name` varchar(120) NOT NULL,
  `exam_date` date NOT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `wm_results`
--

CREATE TABLE `wm_results` (
  `id` bigint UNSIGNED NOT NULL,
  `pupil_id` int UNSIGNED NOT NULL,
  `subject_id` smallint UNSIGNED NOT NULL,
  `exam_id` int UNSIGNED NOT NULL,
  `score` decimal(5,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `wm_subjects`
--

CREATE TABLE `wm_subjects` (
  `id` smallint UNSIGNED NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `max_points` tinyint UNSIGNED NOT NULL DEFAULT '100'
) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_admins_login` (`login`),
  ADD KEY `idx_admins_role` (`role`),
  ADD KEY `idx_admins_active` (`is_active`),
  ADD KEY `idx_admins_role_level` (`role`,`level`);

--
-- Indexes for table `certificates`
--
ALTER TABLE `certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_certificates_name_serial` (`name`,`serial_number`),
  ADD KEY `idx_certificates_pupil` (`pupil_id`),
  ADD KEY `idx_certificates_subject` (`subject`),
  ADD KEY `idx_certificates_subject_fk` (`certificate_subject_id`),
  ADD KEY `idx_certificates_type_name` (`type`,`name`),
  ADD KEY `idx_certificates_expire_time` (`expire_time`);

--
-- Indexes for table `certificate_subjects`
--
ALTER TABLE `certificate_subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_certificate_subjects_code` (`code`),
  ADD UNIQUE KEY `uq_certificate_subjects_name` (`name`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_class_code` (`class_code`),
  ADD KEY `idx_grade` (`grade`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `exams`
--
ALTER TABLE `exams`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_exams_identity` (`academic_year`,`term`,`exam_name`),
  ADD KEY `idx_exams_year_term` (`academic_year`,`term`),
  ADD KEY `idx_exams_date` (`exam_date`);

--
-- Indexes for table `otm_exams`
--
ALTER TABLE `otm_exams`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_otm_exams_session` (`study_year_id`,`otm_kind`,`exam_title`,`exam_date`,`attempt_no`),
  ADD KEY `idx_otm_exams_active_date` (`is_active`,`exam_date`),
  ADD KEY `idx_otm_exams_year` (`study_year_id`);

--
-- Indexes for table `otm_major`
--
ALTER TABLE `otm_major`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_otm_major_pupil` (`pupil_id`),
  ADD KEY `idx_otm_major_major1` (`major1_subject_id`),
  ADD KEY `idx_otm_major_major2` (`major2_subject_id`),
  ADD KEY `idx_otm_major_active` (`is_active`);

--
-- Indexes for table `otm_results`
--
ALTER TABLE `otm_results`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_otm_result_attempt` (`pupil_id`,`otm_kind`,`exam_title`,`exam_date`,`attempt_no`),
  ADD UNIQUE KEY `uq_otm_result_pupil_exam` (`pupil_id`,`otm_exam_id`),
  ADD KEY `idx_otm_exam_lookup` (`exam_date`,`otm_kind`),
  ADD KEY `idx_otm_study_year` (`study_year_id`),
  ADD KEY `idx_otm_pupil` (`pupil_id`),
  ADD KEY `idx_otm_major1_subject` (`major1_subject_id`),
  ADD KEY `idx_otm_major2_subject` (`major2_subject_id`),
  ADD KEY `idx_otm_total_score` (`total_score`),
  ADD KEY `idx_otm_total_score_withcert` (`total_score_withcert`),
  ADD KEY `idx_otm_results_exam_id` (`otm_exam_id`);

--
-- Indexes for table `otm_subjects`
--
ALTER TABLE `otm_subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_otm_subjects_code` (`code`),
  ADD UNIQUE KEY `uq_otm_subjects_name` (`name`),
  ADD KEY `idx_otm_subjects_active_sort` (`is_active`,`sort_order`,`name`);

--
-- Indexes for table `pupils`
--
ALTER TABLE `pupils`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pupils_student_login` (`student_login`),
  ADD KEY `idx_pupils_class_code` (`class_code`),
  ADD KEY `idx_pupils_track` (`track`),
  ADD KEY `idx_pupils_surname_name` (`surname`,`name`),
  ADD KEY `idx_pupils_track_class` (`track`,`class_code`),
  ADD KEY `idx_pupils_class_id` (`class_id`);

--
-- Indexes for table `results`
--
ALTER TABLE `results`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_results_one` (`pupil_id`,`subject_id`,`exam_id`),
  ADD KEY `idx_results_exam_subject` (`exam_id`,`subject_id`),
  ADD KEY `idx_results_pupil_exam` (`pupil_id`,`exam_id`),
  ADD KEY `fk_results_subject` (`subject_id`),
  ADD KEY `idx_results_exam_pupil` (`exam_id`,`pupil_id`);

--
-- Indexes for table `study_year`
--
ALTER TABLE `study_year`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_study_year_code` (`year_code`),
  ADD KEY `idx_study_year_active` (`is_active`),
  ADD KEY `idx_study_year_dates` (`start_date`,`end_date`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_subjects_code` (`code`),
  ADD UNIQUE KEY `uq_subjects_name` (`name`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `login` (`login`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_teachers_class_id` (`class_id`),
  ADD KEY `idx_teachers_level` (`level`);

--
-- Indexes for table `wm_exams`
--
ALTER TABLE `wm_exams`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_wm_exams_identity` (`exam_date`,`exam_name`),
  ADD KEY `idx_wm_exams_year_date` (`exam_date`),
  ADD KEY `idx_wm_exams_study_year` (`study_year_id`);

--
-- Indexes for table `wm_results`
--
ALTER TABLE `wm_results`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_wm_results_one` (`pupil_id`,`subject_id`,`exam_id`),
  ADD KEY `idx_wm_results_exam_subject` (`exam_id`,`subject_id`),
  ADD KEY `idx_wm_results_pupil_exam` (`pupil_id`,`exam_id`),
  ADD KEY `idx_wm_results_exam_pupil` (`exam_id`,`pupil_id`),
  ADD KEY `idx_wm_results_subject` (`subject_id`);

--
-- Indexes for table `wm_subjects`
--
ALTER TABLE `wm_subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_wm_subjects_code` (`code`),
  ADD UNIQUE KEY `uq_wm_subjects_name` (`name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `certificates`
--
ALTER TABLE `certificates`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `certificate_subjects`
--
ALTER TABLE `certificate_subjects`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exams`
--
ALTER TABLE `exams`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otm_exams`
--
ALTER TABLE `otm_exams`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otm_major`
--
ALTER TABLE `otm_major`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otm_results`
--
ALTER TABLE `otm_results`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otm_subjects`
--
ALTER TABLE `otm_subjects`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pupils`
--
ALTER TABLE `pupils`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `results`
--
ALTER TABLE `results`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `study_year`
--
ALTER TABLE `study_year`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teachers`
--
ALTER TABLE `teachers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wm_exams`
--
ALTER TABLE `wm_exams`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wm_results`
--
ALTER TABLE `wm_results`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wm_subjects`
--
ALTER TABLE `wm_subjects`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `certificates`
--
ALTER TABLE `certificates`
  ADD CONSTRAINT `fk_certificates_pupil` FOREIGN KEY (`pupil_id`) REFERENCES `pupils` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_certificates_subject` FOREIGN KEY (`certificate_subject_id`) REFERENCES `certificate_subjects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `otm_exams`
--
ALTER TABLE `otm_exams`
  ADD CONSTRAINT `fk_otm_exams_study_year` FOREIGN KEY (`study_year_id`) REFERENCES `study_year` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `otm_major`
--
ALTER TABLE `otm_major`
  ADD CONSTRAINT `fk_otm_major_major1_subject` FOREIGN KEY (`major1_subject_id`) REFERENCES `otm_subjects` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_otm_major_major2_subject` FOREIGN KEY (`major2_subject_id`) REFERENCES `otm_subjects` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_otm_major_pupil` FOREIGN KEY (`pupil_id`) REFERENCES `pupils` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `otm_results`
--
ALTER TABLE `otm_results`
  ADD CONSTRAINT `fk_otm_results_exam` FOREIGN KEY (`otm_exam_id`) REFERENCES `otm_exams` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_otm_results_major1_subject` FOREIGN KEY (`major1_subject_id`) REFERENCES `otm_subjects` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_otm_results_major2_subject` FOREIGN KEY (`major2_subject_id`) REFERENCES `otm_subjects` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_otm_results_pupil` FOREIGN KEY (`pupil_id`) REFERENCES `pupils` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_otm_results_study_year` FOREIGN KEY (`study_year_id`) REFERENCES `study_year` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `pupils`
--
ALTER TABLE `pupils`
  ADD CONSTRAINT `fk_pupils_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `results`
--
ALTER TABLE `results`
  ADD CONSTRAINT `fk_results_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_results_pupil` FOREIGN KEY (`pupil_id`) REFERENCES `pupils` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_results_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `teachers`
--
ALTER TABLE `teachers`
  ADD CONSTRAINT `fk_teachers_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `wm_exams`
--
ALTER TABLE `wm_exams`
  ADD CONSTRAINT `fk_wm_exams_study_year` FOREIGN KEY (`study_year_id`) REFERENCES `study_year` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `wm_results`
--
ALTER TABLE `wm_results`
  ADD CONSTRAINT `fk_wm_results_exam` FOREIGN KEY (`exam_id`) REFERENCES `wm_exams` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_wm_results_pupil` FOREIGN KEY (`pupil_id`) REFERENCES `pupils` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_wm_results_subject` FOREIGN KEY (`subject_id`) REFERENCES `wm_subjects` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

SET FOREIGN_KEY_CHECKS = 1;
