-- phpMyAdmin SQL Dump
-- version 5.2.3-1.el9
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3311
-- Generation Time: Jan 03, 2026 at 09:49 PM
-- Server version: 8.0.29
-- PHP Version: 8.3.29

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `zmonitoring`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int UNSIGNED NOT NULL,
  `login` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('superadmin','admin','viewer') NOT NULL DEFAULT 'admin',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varbinary(16) DEFAULT NULL,
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
-- Table structure for table `pupils`
--

CREATE TABLE `pupils` (
  `id` int UNSIGNED NOT NULL,
  `surname` varchar(40) NOT NULL,
  `name` varchar(40) NOT NULL,
  `middle_name` varchar(40) DEFAULT NULL,
  `class_code` varchar(30) NOT NULL,
  `track` enum('Aniq fanlar','Tabiiy fanlar') NOT NULL,
  `student_login` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `results`
--

CREATE TABLE `results` (
  `id` bigint UNSIGNED NOT NULL,
  `pupil_id` int UNSIGNED NOT NULL,
  `subject_id` smallint UNSIGNED NOT NULL,
  `exam_id` int UNSIGNED NOT NULL,
  `score` decimal(4,1) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
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

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `code`, `name`, `max_points`) VALUES
(1, 'MATHEMATICS', 'Matematika', 40),
(2, 'ENGLISH', 'Ingliz tili', 40),
(3, 'ALGEBRA', 'Algebra', 40),
(4, 'GEOMETRY', 'Geometriya', 40),
(5, 'PHYSICS', 'Fizika', 40),
(6, 'BIOLOGY', 'Biologiya', 40),
(7, 'CHEMISTRY', 'Kimyo', 40);

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
  ADD KEY `idx_admins_active` (`is_active`);

--
-- Indexes for table `exams`
--
ALTER TABLE `exams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_exams_year_term` (`academic_year`,`term`),
  ADD KEY `idx_exams_date` (`exam_date`);

--
-- Indexes for table `pupils`
--
ALTER TABLE `pupils`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pupils_student_login` (`student_login`),
  ADD KEY `idx_pupils_class_code` (`class_code`),
  ADD KEY `idx_pupils_track` (`track`),
  ADD KEY `idx_pupils_surname_name` (`surname`,`name`);

--
-- Indexes for table `results`
--
ALTER TABLE `results`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_results_one` (`pupil_id`,`subject_id`,`exam_id`),
  ADD KEY `idx_results_exam_subject` (`exam_id`,`subject_id`),
  ADD KEY `idx_results_pupil_exam` (`pupil_id`,`exam_id`),
  ADD KEY `fk_results_subject` (`subject_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_subjects_code` (`code`),
  ADD UNIQUE KEY `uq_subjects_name` (`name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exams`
--
ALTER TABLE `exams`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `results`
--
ALTER TABLE `results`
  ADD CONSTRAINT `fk_results_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_results_pupil` FOREIGN KEY (`pupil_id`) REFERENCES `pupils` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_results_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE RESTRICT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
