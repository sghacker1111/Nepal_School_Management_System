-- Nepal Mathematics Learning & School Result Management System
-- Database Schema

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+05:45"; -- Nepal Time

-- --------------------------------------------------------
-- 1. Core Settings & Users
-- --------------------------------------------------------

CREATE TABLE `schools` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name_en` varchar(255) NOT NULL,
  `name_np` varchar(255) DEFAULT NULL,
  `emis_code` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `principal_signature` varchar(255) DEFAULT NULL,
  `exam_coordinator_signature` varchar(255) DEFAULT NULL,
  `academic_year_current` varchar(20) DEFAULT '2081/2025',
  `principal_name` varchar(100) DEFAULT NULL,
  `principal_mobile` varchar(20) DEFAULT NULL,
  `technical_person` varchar(100) DEFAULT NULL,
  `class_teacher_signature` varchar(255) DEFAULT NULL,
  `routine_periods_per_day` int(11) DEFAULT 8,
  `routine_include_sunday` tinyint(1) DEFAULT 0,
  `subscription_type` varchar(50) DEFAULT 'full',
  `question_permission_status` varchar(20) DEFAULT 'none',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `schools` (`name_en`, `name_np`, `emis_code`) VALUES 
('Model Secondary School', 'नमूना माध्यमिक विद्यालय', '12345678');

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('super_admin','school_admin','principal','teacher','accountant','exam_officer','student','parent') NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `signature` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default Admin: username: admin, password: admin123
INSERT INTO `users` (`username`, `password`, `role`, `full_name`) VALUES 
('admin', '$2y$12$z/7Y8e4ptJUVtKH5VvjFwOld0Q.fESfS/1HSkBLs8pCZhg7sfco.m', 'super_admin', 'System Administrator');

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 2. Academic Structure
-- --------------------------------------------------------

CREATE TABLE `academic_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year_bs` varchar(10) NOT NULL,
  `year_ad` varchar(10) NOT NULL,
  `is_active` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `academic_years` (`year_bs`, `year_ad`, `is_active`) VALUES ('2081', '2024', 1);

CREATE TABLE `classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL, -- e.g., Class 10
  `name_np` varchar(50) DEFAULT NULL,
  `level` enum('ECD','Basic','Secondary') DEFAULT 'Basic',
  `grading_type` enum('percentage','gpa') DEFAULT 'gpa',
  `class_teacher_name` varchar(100) DEFAULT NULL,
  `class_teacher_signature` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `is_grading_teaching` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `classes` (`name`, `name_np`, `level`, `grading_type`) VALUES 
('Nursery', 'नर्सरी', 'ECD', 'percentage'),
('LKG', 'एल.के.जी.', 'ECD', 'percentage'),
('UKG', 'यू.के.जी.', 'ECD', 'percentage'),
('Class 1', 'कक्षा १', 'Basic', 'gpa'),
('Class 2', 'कक्षा २', 'Basic', 'gpa'),
('Class 3', 'कक्षा ३', 'Basic', 'gpa'),
('Class 4', 'कक्षा ४', 'Basic', 'gpa'),
('Class 5', 'कक्षा ५', 'Basic', 'gpa'),
('Class 6', 'कक्षा ६', 'Basic', 'gpa'),
('Class 7', 'कक्षा ७', 'Basic', 'gpa'),
('Class 8', 'कक्षा ८', 'Basic', 'gpa'),
('Class 9', 'कक्षा ९', 'Secondary', 'gpa'),
('Class 10', 'कक्षा १०', 'Secondary', 'gpa');

CREATE TABLE `sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `name` varchar(10) NOT NULL, -- A, B, C
  `class_teacher_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) DEFAULT NULL,
  `name_en` varchar(100) NOT NULL,
  `name_np` varchar(100) DEFAULT NULL,
  `type` enum('compulsory','optional','local') DEFAULT 'compulsory',
  `full_marks` int(11) DEFAULT 100,
  `pass_marks` int(11) DEFAULT 40,
  `credit_hours` int(11) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `subjects` (`name_en`, `name_np`, `full_marks`, `pass_marks`, `credit_hours`) VALUES 
('Mathematics', 'गणित', 100, 40, 1),
('English', 'अंग्रेजी', 100, 40, 1),
('Nepali', 'नेपाली', 100, 40, 1);

-- --------------------------------------------------------
-- 3. Student Management (IEMIS Style)
-- --------------------------------------------------------

CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `emis_id` varchar(50) DEFAULT NULL,
  `student_id` varchar(50) NOT NULL, -- Internal ID
  `academic_year_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `roll_number` int(11) DEFAULT NULL,
  `exam_roll_number` varchar(20) DEFAULT NULL, -- Specific for Admit Card
  
  -- Personal Info
  `first_name_en` varchar(100) NOT NULL,
  `last_name_en` varchar(100) DEFAULT NULL,
  `first_name_np` varchar(100) DEFAULT NULL,
  `last_name_np` varchar(100) DEFAULT NULL,
  `gender` enum('Male','Female','Other') NOT NULL,
  `dob_bs` varchar(20) DEFAULT NULL,
  `dob_ad` date DEFAULT NULL,
  `blood_group` varchar(5) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  
  -- Demographics
  `caste_ethnicity` varchar(100) DEFAULT NULL,
  `religion` varchar(50) DEFAULT NULL,
  `disability_status` tinyint(1) DEFAULT 0,
  `scholarship_type` varchar(50) DEFAULT NULL,
  
  -- Address (Permanent)
  `perm_province` varchar(50) DEFAULT NULL,
  `perm_district` varchar(50) DEFAULT NULL,
  `perm_municipality` varchar(100) DEFAULT NULL,
  `perm_ward_no` varchar(10) DEFAULT NULL,
  `perm_tole` varchar(100) DEFAULT NULL,
  
  -- Guardian Info
  `father_name` varchar(100) DEFAULT NULL,
  `father_phone` varchar(20) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `mother_phone` varchar(20) DEFAULT NULL,
  `guardian_name` varchar(100) DEFAULT NULL,
  `guardian_phone` varchar(20) DEFAULT NULL,
  
  `status` enum('active','transferred','dropout','passed') DEFAULT 'active',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `optional_subject_i` int(11) DEFAULT NULL,
  `optional_subject_ii` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`),
  FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`),
  FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- 4. Exam & Result System
-- --------------------------------------------------------

CREATE TABLE `exams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL, -- First Terminal, SEE
  `type` enum('terminal','final','unit','practical') DEFAULT 'terminal',
  `academic_year_id` int(11) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 0,
  `is_locked` tinyint(1) DEFAULT 0,
  `result_system` enum('gpa','percentage') DEFAULT 'gpa',
  `has_internal` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `exam_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `theory_full` int(11) DEFAULT 75,
  `theory_pass` int(11) DEFAULT 30,
  `practical_full` int(11) DEFAULT 25,
  `practical_pass` int(11) DEFAULT 10,
  `credit_hours` int(11) DEFAULT NULL,
  `internal_credit_hours` decimal(4,2) DEFAULT NULL,
  `external_credit_hours` decimal(4,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`exam_id`) REFERENCES `exams`(`id`),
  FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `marks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `theory_obtained` decimal(5,2) DEFAULT 0,
  `practical_obtained` decimal(5,2) DEFAULT 0,
  `grade` varchar(5) DEFAULT NULL,
  `grade_point` decimal(3,2) DEFAULT 0,
  `remarks` varchar(20) DEFAULT NULL, -- Pass, Fail, NG
  `entered_by` int(11) DEFAULT NULL,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_mark` (`exam_id`, `student_id`, `subject_id`),
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `grading_scales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `min_percentage` decimal(5,2) NOT NULL,
  `max_percentage` decimal(5,2) NOT NULL,
  `grade` varchar(5) NOT NULL,
  `grade_point` decimal(3,2) NOT NULL,
  `remark` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default Nepal CDC Grading Scale (Secondary Level Example)
INSERT INTO `grading_scales` (`min_percentage`, `max_percentage`, `grade`, `grade_point`, `remark`) VALUES
(80, 100, 'A+', 4.0, 'Excellent'),
(70, 79.99, 'A', 3.6, 'Very Good'),
(60, 69.99, 'B+', 3.2, 'Good'),
(50, 59.99, 'B', 2.8, 'Above Average'),
(40, 49.99, 'C', 2.4, 'Average'),
(30, 39.99, 'D', 2.0, 'Pass'),
(0, 29.99, 'F', 0.0, 'Fail');

CREATE TABLE `attendances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `date_ad` date NOT NULL,
  `date_bs` varchar(20) DEFAULT NULL,
  `status` enum('P','A','L','H') DEFAULT 'P', -- Present, Absent, Late, Holiday
  `remarks` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `daily_attendance` (`student_id`, `date_ad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `exam_student_attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `present_days` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`exam_id`) REFERENCES `exams`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `class_routines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `day_of_week` int(11) NOT NULL,
  `period_number` int(11) NOT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `school_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`teacher_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `class_routines_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version_id` int(11) DEFAULT NULL,
  `class_id` int(11) NOT NULL,
  `day_of_week` int(11) NOT NULL,
  `period_number` int(11) NOT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `school_id` int(11) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `changed_by_user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;

