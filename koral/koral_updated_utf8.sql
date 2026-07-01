-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: u621399201_koral
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `assignment_submissions`
--

DROP TABLE IF EXISTS `assignment_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assignment_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `upload_id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('submitted','graded','late','missing') DEFAULT 'submitted',
  `grade` decimal(5,2) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `graded_by` int(11) DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assignment_submissions`
--

LOCK TABLES `assignment_submissions` WRITE;
/*!40000 ALTER TABLE `assignment_submissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `assignment_submissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) DEFAULT NULL,
  `date` date NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `student_name` varchar(50) NOT NULL,
  `status` enum('Present','Absent') DEFAULT 'Present',
  `camera_status` enum('On','Off') DEFAULT 'Off',
  `remarks` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
INSERT INTO `attendance` VALUES (1,'STD001','2026-05-04','B001','test user','Present','On','Attentive'),(2,'STD001','2026-05-06','B001','test user','Present','On','Active participant'),(3,'STD001','2026-05-08','B001','test user','Present','Off',''),(4,'STD001','2026-05-11','B001','test user','Present','On',''),(5,'STD001','2026-05-13','B001','test user','Present','On',''),(6,'STD001','2026-05-15','B001','test user','Present','On',''),(7,'STD001','2026-05-18','B001','test user','Absent','Off','Informed beforehand'),(8,'STD001','2026-05-20','B001','test user','Present','On',''),(9,'STD001','2026-05-22','B001','test user','Present','On',''),(10,'STD001','2026-05-25','B001','test user','Present','Off',''),(11,'STD001','2026-05-27','B001','test user','Present','On','');
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `batch_terms_settings`
--

DROP TABLE IF EXISTS `batch_terms_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `batch_terms_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` varchar(10) NOT NULL,
  `require_terms_acceptance` tinyint(1) DEFAULT 1,
  `terms_content` text DEFAULT NULL,
  `custom_terms_enabled` tinyint(1) DEFAULT 0,
  `custom_terms_file` varchar(255) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_batch` (`batch_id`),
  KEY `fk_terms_updated_by` (`updated_by`),
  CONSTRAINT `fk_terms_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `batch_terms_settings`
--

LOCK TABLES `batch_terms_settings` WRITE;
/*!40000 ALTER TABLE `batch_terms_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `batch_terms_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `batch_uploads`
--

DROP TABLE IF EXISTS `batch_uploads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `batch_uploads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `upload_id` int(11) NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `batch_uploads`
--

LOCK TABLES `batch_uploads` WRITE;
/*!40000 ALTER TABLE `batch_uploads` DISABLE KEYS */;
INSERT INTO `batch_uploads` VALUES (1,201,'B001'),(2,202,'B001');
/*!40000 ALTER TABLE `batch_uploads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `batches`
--

DROP TABLE IF EXISTS `batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `batches` (
  `batch_id` varchar(10) NOT NULL,
  `batch_name` varchar(59) DEFAULT NULL,
  `course_description` text DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `time_slot` varchar(50) DEFAULT NULL,
  `platform` varchar(100) DEFAULT NULL,
  `meeting_link` varchar(2083) DEFAULT NULL,
  `thumbnail_path` varchar(255) DEFAULT NULL,
  `max_students` int(11) DEFAULT NULL,
  `current_enrollment` int(11) DEFAULT 0,
  `academic_year` varchar(20) DEFAULT NULL,
  `batch_mentor_id` int(11) DEFAULT NULL,
  `mode` enum('online','offline') DEFAULT 'online',
  `status` enum('upcoming','ongoing','completed','cancelled') DEFAULT 'upcoming',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `batches`
--

LOCK TABLES `batches` WRITE;
/*!40000 ALTER TABLE `batches` DISABLE KEYS */;
INSERT INTO `batches` VALUES ('B001','Linux Admin Batch 1','Learn Linux administration from scratch including bash scripting and server management.','2026-05-01','2026-08-01','18:00 - 19:30','Zoom','https://zoom.us/j/1234567890',NULL,30,1,'2026',1,'online','ongoing','2026-05-29 12:04:46',1),('B002','Ethical Hacking','','2026-06-02','2026-09-02','','Google Meet','https://meet.google.com/ewj-ymmg-puz',NULL,10,0,'',0,'online','ongoing','2026-06-02 09:07:44',1);
/*!40000 ALTER TABLE `batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `certificate_templates`
--

DROP TABLE IF EXISTS `certificate_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `certificate_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workshop_id` varchar(10) NOT NULL,
  `template_path` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `certificate_templates`
--

LOCK TABLES `certificate_templates` WRITE;
/*!40000 ALTER TABLE `certificate_templates` DISABLE KEYS */;
/*!40000 ALTER TABLE `certificate_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clear_chat_history`
--

DROP TABLE IF EXISTS `clear_chat_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clear_chat_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `cleared_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_clear` (`conversation_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `clear_chat_history_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clear_chat_history_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clear_chat_history`
--

LOCK TABLES `clear_chat_history` WRITE;
/*!40000 ALTER TABLE `clear_chat_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `clear_chat_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `content_categories`
--

DROP TABLE IF EXISTS `content_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `content_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `color` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `content_categories`
--

LOCK TABLES `content_categories` WRITE;
/*!40000 ALTER TABLE `content_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `content_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `conversation_members`
--

DROP TABLE IF EXISTS `conversation_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `conversation_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_member` (`conversation_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `conversation_members_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conversation_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `conversation_members`
--

LOCK TABLES `conversation_members` WRITE;
/*!40000 ALTER TABLE `conversation_members` DISABLE KEYS */;
/*!40000 ALTER TABLE `conversation_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `conversations`
--

DROP TABLE IF EXISTS `conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('one_to_one','group') NOT NULL DEFAULT 'one_to_one',
  `name` varchar(255) DEFAULT NULL,
  `batch_id` varchar(50) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_cleared` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `conversations_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `conversations`
--

LOCK TABLES `conversations` WRITE;
/*!40000 ALTER TABLE `conversations` DISABLE KEYS */;
/*!40000 ALTER TABLE `conversations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `courses`
--

DROP TABLE IF EXISTS `courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `courses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `courses`
--

LOCK TABLES `courses` WRITE;
/*!40000 ALTER TABLE `courses` DISABLE KEYS */;
INSERT INTO `courses` VALUES (5,'Linux Fundamental');
/*!40000 ALTER TABLE `courses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `doubt_responses`
--

DROP TABLE IF EXISTS `doubt_responses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doubt_responses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `doubt_id` int(11) NOT NULL,
  `responded_by` int(11) NOT NULL,
  `response` text NOT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `is_trainer_response` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `doubt_id` (`doubt_id`),
  KEY `responded_by` (`responded_by`),
  CONSTRAINT `doubt_responses_ibfk_1` FOREIGN KEY (`doubt_id`) REFERENCES `doubts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `doubt_responses_ibfk_2` FOREIGN KEY (`responded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `doubt_responses`
--

LOCK TABLES `doubt_responses` WRITE;
/*!40000 ALTER TABLE `doubt_responses` DISABLE KEYS */;
/*!40000 ALTER TABLE `doubt_responses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `doubts`
--

DROP TABLE IF EXISTS `doubts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doubts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `batch_id` varchar(10) DEFAULT NULL,
  `subject` varchar(255) NOT NULL DEFAULT 'General',
  `question` text NOT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `status` enum('pending','answered','in_progress','resolved') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `batch_id` (`batch_id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `doubts`
--

LOCK TABLES `doubts` WRITE;
/*!40000 ALTER TABLE `doubts` DISABLE KEYS */;
/*!40000 ALTER TABLE `doubts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_enrollments`
--

DROP TABLE IF EXISTS `exam_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exam_enrollments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` varchar(12) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `enrolled_by` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_exam_student_enrollment` (`exam_id`,`student_id`),
  KEY `student_id` (`student_id`),
  KEY `enrolled_by` (`enrolled_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_enrollments`
--

LOCK TABLES `exam_enrollments` WRITE;
/*!40000 ALTER TABLE `exam_enrollments` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_enrollments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_results`
--

DROP TABLE IF EXISTS `exam_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exam_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` varchar(12) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `obtained_marks` decimal(5,2) DEFAULT NULL,
  `grade` varchar(2) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `mcq_marks` decimal(5,2) DEFAULT NULL,
  `project_marks` decimal(5,2) DEFAULT NULL,
  `viva_marks` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_exam_student` (`exam_id`,`student_id`),
  KEY `fk_result_student` (`student_id`),
  KEY `fk_result_uploader` (`uploaded_by`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_results`
--

LOCK TABLES `exam_results` WRITE;
/*!40000 ALTER TABLE `exam_results` DISABLE KEYS */;
INSERT INTO `exam_results` VALUES (1,'EXM001','STD001',45.00,'A','Excellent conceptual knowledge shown.',1,'2026-05-29 12:04:46',NULL,NULL,NULL),(2,'EXM002','STD001',88.00,'A','Very efficient terminal commands execution.',1,'2026-05-29 12:04:46',NULL,NULL,NULL);
/*!40000 ALTER TABLE `exam_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_students`
--

DROP TABLE IF EXISTS `exam_students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exam_students` (
  `exam_id` varchar(10) NOT NULL,
  `student_name` varchar(50) NOT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `is_malpractice` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_students`
--

LOCK TABLES `exam_students` WRITE;
/*!40000 ALTER TABLE `exam_students` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exams`
--

DROP TABLE IF EXISTS `exams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exams` (
  `exam_id` varchar(12) NOT NULL,
  `exam_name` varchar(100) NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `subject` varchar(50) NOT NULL,
  `exam_date` date NOT NULL,
  `total_marks` decimal(5,2) NOT NULL,
  `passing_marks` decimal(5,2) NOT NULL,
  `exam_type` enum('quarterly','half-yearly','final','unit_test','practice') NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `enrollment_status` enum('all_students','selected_students') DEFAULT 'all_students',
  `exam_components` set('mcq','project','viva') DEFAULT 'mcq',
  `mcq_marks` decimal(5,2) DEFAULT 0.00,
  `project_marks` decimal(5,2) DEFAULT 0.00,
  `viva_marks` decimal(5,2) DEFAULT 0.00,
  `is_back_schedule` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`exam_id`),
  KEY `fk_exam_batch` (`batch_id`),
  KEY `fk_exam_creator` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exams`
--

LOCK TABLES `exams` WRITE;
/*!40000 ALTER TABLE `exams` DISABLE KEYS */;
INSERT INTO `exams` VALUES ('EXM001','Linux Basics Quiz','B001','Linux','2026-05-15',50.00,20.00,'unit_test','Covers basic terminal commands and Linux structure.',1,'2026-05-29 12:04:46','all_students','mcq',0.00,0.00,0.00,0),('EXM002','Command Line Practical','B001','Linux','2026-05-22',100.00,40.00,'practice','Real-world terminal tasks evaluation.',1,'2026-05-29 12:04:46','all_students','mcq',0.00,0.00,0.00,0);
/*!40000 ALTER TABLE `exams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fee_installments`
--

DROP TABLE IF EXISTS `fee_installments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fee_installments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `installment_number` int(11) NOT NULL,
  `installment_amount` decimal(10,2) NOT NULL,
  `due_date` date NOT NULL,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `payment_date` date DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_status` enum('pending','paid','overdue','cancelled') DEFAULT 'pending',
  `transaction_id` varchar(100) DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `due_date` (`due_date`),
  KEY `payment_status` (`payment_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_installments`
--

LOCK TABLES `fee_installments` WRITE;
/*!40000 ALTER TABLE `fee_installments` DISABLE KEYS */;
/*!40000 ALTER TABLE `fee_installments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fee_reminder_logs`
--

DROP TABLE IF EXISTS `fee_reminder_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fee_reminder_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `sent_by` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `sent_at` datetime NOT NULL,
  `email_sent` tinyint(1) DEFAULT 0,
  `is_bulk` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_reminder_logs`
--

LOCK TABLES `fee_reminder_logs` WRITE;
/*!40000 ALTER TABLE `fee_reminder_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `fee_reminder_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `feed_comments`
--

DROP TABLE IF EXISTS `feed_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feed_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `feed_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_role` enum('admin','student','mentor') NOT NULL,
  `comment` text NOT NULL,
  `parent_comment_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `feed_id` (`feed_id`),
  KEY `user_id` (`user_id`),
  KEY `parent_comment_id` (`parent_comment_id`),
  CONSTRAINT `feed_comments_ibfk_1` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feed_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feed_comments_ibfk_3` FOREIGN KEY (`parent_comment_id`) REFERENCES `feed_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feed_comments`
--

LOCK TABLES `feed_comments` WRITE;
/*!40000 ALTER TABLE `feed_comments` DISABLE KEYS */;
/*!40000 ALTER TABLE `feed_comments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `feed_notifications`
--

DROP TABLE IF EXISTS `feed_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feed_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `feed_id` int(11) NOT NULL,
  `type` enum('reaction','comment','reply') NOT NULL,
  `actor_id` int(11) NOT NULL,
  `actor_role` enum('admin','student','mentor') NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `feed_id` (`feed_id`),
  CONSTRAINT `feed_notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feed_notifications_ibfk_2` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feed_notifications`
--

LOCK TABLES `feed_notifications` WRITE;
/*!40000 ALTER TABLE `feed_notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `feed_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `feed_reactions`
--

DROP TABLE IF EXISTS `feed_reactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feed_reactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `feed_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_role` enum('admin','student','mentor') NOT NULL,
  `reaction_type` enum('like','love','care','haha','wow','sad','angry') DEFAULT 'like',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_feed_reaction` (`feed_id`,`user_id`),
  KEY `feed_id` (`feed_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `feed_reactions_ibfk_1` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feed_reactions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feed_reactions`
--

LOCK TABLES `feed_reactions` WRITE;
/*!40000 ALTER TABLE `feed_reactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `feed_reactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `feedback`
--

DROP TABLE IF EXISTS `feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `student_name` varchar(50) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `batch_id` varchar(10) NOT NULL,
  `is_regular` enum('Yes','No') DEFAULT NULL,
  `class_rating` tinyint(1) DEFAULT NULL,
  `assignment_understanding` tinyint(1) DEFAULT NULL,
  `practical_understanding` tinyint(1) DEFAULT NULL,
  `satisfied` tinyint(1) DEFAULT NULL,
  `suggestions` text DEFAULT NULL,
  `course_name` varchar(50) NOT NULL,
  `rating` tinyint(1) DEFAULT NULL CHECK (`rating` between 1 and 5),
  `feedback_text` text DEFAULT NULL,
  `action_taken` text DEFAULT NULL,
  `action_taken_time` datetime DEFAULT NULL,
  `action_time` datetime(6) DEFAULT NULL,
  `show_to_trainer` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_show_to_trainer` (`show_to_trainer`),
  KEY `idx_action_taken_time` (`action_taken_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feedback`
--

LOCK TABLES `feedback` WRITE;
/*!40000 ALTER TABLE `feedback` DISABLE KEYS */;
/*!40000 ALTER TABLE `feedback` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `feeds`
--

DROP TABLE IF EXISTS `feeds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feeds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `media_url` varchar(500) DEFAULT NULL,
  `media_type` enum('image','video','link','none') DEFAULT 'none',
  `link_url` varchar(500) DEFAULT NULL,
  `link_title` varchar(255) DEFAULT NULL,
  `link_description` text DEFAULT NULL,
  `link_image` varchar(500) DEFAULT NULL,
  `status` enum('published','draft','archived') DEFAULT 'published',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `feeds_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feeds`
--

LOCK TABLES `feeds` WRITE;
/*!40000 ALTER TABLE `feeds` DISABLE KEYS */;
/*!40000 ALTER TABLE `feeds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leads`
--

DROP TABLE IF EXISTS `leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `contact` varchar(20) NOT NULL,
  `qualification` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `lead_source` varchar(255) DEFAULT NULL,
  `enquiry_date` date NOT NULL,
  `status` enum('new','contacted','follow_up','converted','lost','not_interested') DEFAULT 'new',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leads`
--

LOCK TABLES `leads` WRITE;
/*!40000 ALTER TABLE `leads` DISABLE KEYS */;
/*!40000 ALTER TABLE `leads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_application_history`
--

DROP TABLE IF EXISTS `leave_application_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_application_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `action` enum('submitted','updated','approved','rejected','cancelled') NOT NULL,
  `action_by` int(11) NOT NULL,
  `action_at` datetime NOT NULL DEFAULT current_timestamp(),
  `remarks` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `application_id` (`application_id`),
  KEY `action_by` (`action_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_application_history`
--

LOCK TABLES `leave_application_history` WRITE;
/*!40000 ALTER TABLE `leave_application_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_application_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_applications`
--

DROP TABLE IF EXISTS `leave_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_applications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_no` varchar(20) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `student_name` varchar(100) NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `email` varchar(150) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_days` int(11) NOT NULL,
  `reason_category` enum('Health Issue','Family Emergency','Personal Work','College Work & Exam','Other') NOT NULL,
  `reason_detail` text NOT NULL,
  `absence_type` enum('Planned','Sudden') NOT NULL,
  `informed_academy` enum('Yes','No') NOT NULL,
  `medical_prescription` varchar(255) DEFAULT NULL,
  `course_importance` enum('Yes, very important','Somewhat important','Not sure') NOT NULL,
  `content_value` enum('Very valuable','Good','Average','Not useful') NOT NULL,
  `topic_understanding` enum('Yes, clearly','Sometimes','No, I struggle') NOT NULL,
  `practical_ability` enum('Yes','With some difficulty','No') NOT NULL,
  `unique_learning` enum('Yes','Maybe','No') NOT NULL,
  `loss_reflection` text NOT NULL,
  `acceptable_situation` text NOT NULL,
  `support_needed` text NOT NULL,
  `future_commitment` enum('Yes','I will try','Not sure') NOT NULL,
  `counselling_request` enum('Yes','No') NOT NULL,
  `responsibility_acceptance` tinyint(1) DEFAULT 0,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `admin_remarks` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `application_no` (`application_no`),
  KEY `student_id` (`student_id`),
  KEY `batch_id` (`batch_id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`),
  KEY `fk_leave_approved_by` (`approved_by`),
  KEY `fk_leave_rejected_by` (`rejected_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_applications`
--

LOCK TABLES `leave_applications` WRITE;
/*!40000 ALTER TABLE `leave_applications` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `attempt_time` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `main_topics`
--

DROP TABLE IF EXISTS `main_topics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `main_topics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_name` varchar(50) NOT NULL,
  `chapter` int(11) NOT NULL,
  `topic_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `covered_by_trainer` tinyint(1) DEFAULT 0,
  `covered_date` datetime DEFAULT NULL,
  `topic_type` enum('theory','practical','both') DEFAULT 'both',
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `batch_name` (`batch_name`)
) ENGINE=InnoDB AUTO_INCREMENT=104 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `main_topics`
--

LOCK TABLES `main_topics` WRITE;
/*!40000 ALTER TABLE `main_topics` DISABLE KEYS */;
INSERT INTO `main_topics` VALUES (101,'B001',1,'Introduction to Linux OS','2026-05-29 12:04:46',1,'2026-05-02 18:00:00','both',1),(102,'B001',2,'File System Navigation & Operations','2026-05-29 12:04:46',1,'2026-05-10 18:00:00','both',1),(103,'B001',3,'User & Group Configurations','2026-05-29 12:04:46',0,NULL,'both',1);
/*!40000 ALTER TABLE `main_topics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_type` enum('image','video','audio','document','other') DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_size` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `conversation_id` (`conversation_id`),
  KEY `sender_id` (`sender_id`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `messages`
--

LOCK TABLES `messages` WRITE;
/*!40000 ALTER TABLE `messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('feedback','message','leave','ticket') NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `reference_id` int(11) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (3,7,'ticket','Support Ticket Resolved','Your support ticket regarding \'Batch/Schedule Related\' has been resolved. Click to view the reply.',1,0,'2026-06-02 08:58:51'),(4,11,'ticket','Support Ticket Resolved','Your support ticket regarding \'Exam/Test Related\' has been resolved. Click to view the reply.',2,0,'2026-06-02 09:20:13');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_modes`
--

DROP TABLE IF EXISTS `payment_modes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_modes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mode_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_modes`
--

LOCK TABLES `payment_modes` WRITE;
/*!40000 ALTER TABLE `payment_modes` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_modes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_verification_settings`
--

DROP TABLE IF EXISTS `payment_verification_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_verification_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` varchar(10) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_verification_settings`
--

LOCK TABLES `payment_verification_settings` WRITE;
/*!40000 ALTER TABLE `payment_verification_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_verification_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `proctored_exams`
--

DROP TABLE IF EXISTS `proctored_exams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `proctored_exams` (
  `exam_id` varchar(10) NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `exam_date` date NOT NULL,
  `mode` enum('Online','Offline') NOT NULL,
  `duration` int(11) DEFAULT NULL COMMENT 'Minutes',
  `proctor_name` varchar(50) DEFAULT NULL,
  `malpractice_cases` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `proctored_exams`
--

LOCK TABLES `proctored_exams` WRITE;
/*!40000 ALTER TABLE `proctored_exams` DISABLE KEYS */;
/*!40000 ALTER TABLE `proctored_exams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `remember_tokens`
--

DROP TABLE IF EXISTS `remember_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_token` (`token`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `remember_tokens`
--

LOCK TABLES `remember_tokens` WRITE;
/*!40000 ALTER TABLE `remember_tokens` DISABLE KEYS */;
INSERT INTO `remember_tokens` VALUES (1,10,'f5a374652aafcab994eb89bc718d5cb6cc4e92aa7fa6ff76e083a8eeb4e5156f','2026-06-05 17:31:05','2026-05-29 12:01:05'),(2,11,'9f56f5cbd978bd5decf1878a604ba5c097ae13e4d7c0ce09afd5f3c1a7e10678','2026-06-09 14:42:22','2026-06-02 09:12:22'),(3,11,'640d445f34b6f37d7b30ece6b85bd2b7a4831c3d8ec7c1f3b5c8800e7efc0c12','2026-06-09 14:48:37','2026-06-02 09:18:37');
/*!40000 ALTER TABLE `remember_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reports`
--

DROP TABLE IF EXISTS `reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reports` (
  `report_id` varchar(12) NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `month` date NOT NULL,
  `generated_on` datetime DEFAULT current_timestamp(),
  `report_type` enum('Monthly','Exam','Feedback') NOT NULL,
  `file_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reports`
--

LOCK TABLES `reports` WRITE;
/*!40000 ALTER TABLE `reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `results_sync`
--

DROP TABLE IF EXISTS `results_sync`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `results_sync` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `test_id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `asd_exam_id` varchar(12) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'pending',
  `sync_message` text DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `test_id` (`test_id`),
  CONSTRAINT `results_sync_ibfk_1` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `results_sync`
--

LOCK TABLES `results_sync` WRITE;
/*!40000 ALTER TABLE `results_sync` DISABLE KEYS */;
/*!40000 ALTER TABLE `results_sync` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schedule`
--

DROP TABLE IF EXISTS `schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` varchar(10) NOT NULL,
  `schedule_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `topic` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_cancelled` tinyint(1) DEFAULT 0,
  `cancellation_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `is_back_schedule` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schedule`
--

LOCK TABLES `schedule` WRITE;
/*!40000 ALTER TABLE `schedule` DISABLE KEYS */;
INSERT INTO `schedule` VALUES (1,'B001','2026-05-29','18:00:00','19:30:00','Shell Scripting Basics','Introduction to variables, arguments, and scripting files.',0,NULL,'2026-05-29 12:04:46',1,0),(2,'B001','2026-05-30','18:00:00','19:30:00','Bash Control Flow','Using if/else, loops (for/while) in scripting.',0,NULL,'2026-05-29 12:04:46',1,0),(3,'B001','2026-06-01','18:00:00','19:30:00','Linux Process Control','Managing background jobs, ps, top, kill commands.',0,NULL,'2026-05-29 12:04:46',1,0);
/*!40000 ALTER TABLE `schedule` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `semester_batches`
--

DROP TABLE IF EXISTS `semester_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `semester_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `semester_id` int(11) NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `semester_number` tinyint(1) NOT NULL COMMENT 'Semester number (1-6)',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assigned_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `semester_batches`
--

LOCK TABLES `semester_batches` WRITE;
/*!40000 ALTER TABLE `semester_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `semester_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `semesters`
--

DROP TABLE IF EXISTS `semesters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `semesters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('planning','ongoing','completed','cancelled') DEFAULT 'planning',
  `max_semesters` tinyint(1) DEFAULT 6,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `semesters`
--

LOCK TABLES `semesters` WRITE;
/*!40000 ALTER TABLE `semesters` DISABLE KEYS */;
/*!40000 ALTER TABLE `semesters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_batch_history`
--

DROP TABLE IF EXISTS `student_batch_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_batch_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `from_batch_id` varchar(10) NOT NULL,
  `to_batch_id` varchar(10) NOT NULL,
  `transfer_reason` varchar(200) DEFAULT NULL,
  `transfer_date` datetime NOT NULL DEFAULT current_timestamp(),
  `transferred_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_batch_history`
--

LOCK TABLES `student_batch_history` WRITE;
/*!40000 ALTER TABLE `student_batch_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_batch_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_documents`
--

DROP TABLE IF EXISTS `student_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_documents` (
  `document_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `document_type` enum('aadhaar','pancard','tenth_marksheet','twelfth_marksheet','other') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_documents`
--

LOCK TABLES `student_documents` WRITE;
/*!40000 ALTER TABLE `student_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_status_log`
--

DROP TABLE IF EXISTS `student_status_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_status_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `action` enum('dropped','reactivated','transferred','on_hold','resumed') NOT NULL,
  `reason` text DEFAULT NULL,
  `processed_by` int(11) NOT NULL,
  `processed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `enrollment_fees` decimal(10,2) DEFAULT NULL,
  `fees_status` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_status_log`
--

LOCK TABLES `student_status_log` WRITE;
/*!40000 ALTER TABLE `student_status_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_status_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `student_id` varchar(50) NOT NULL,
  `course` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `enrollment_date` date NOT NULL,
  `current_status` enum('active','dropped','on hold','transferred','completed') NOT NULL DEFAULT 'active',
  `on_hold_reason` text DEFAULT NULL,
  `on_hold_date` date DEFAULT NULL,
  `batch_name` varchar(100) DEFAULT NULL,
  `batch_name_2` varchar(10) DEFAULT NULL,
  `batch_name_3` varchar(10) DEFAULT NULL,
  `batch_name_4` varchar(100) DEFAULT NULL,
  `dropout_date` date DEFAULT NULL,
  `dropout_reason` text DEFAULT NULL,
  `dropout_processed_by` int(11) DEFAULT NULL,
  `dropout_processed_at` datetime DEFAULT NULL,
  `father_name` varchar(200) DEFAULT NULL,
  `father_phone_number` varchar(20) DEFAULT NULL,
  `father_email` varchar(150) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `last_login` datetime DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `sales_person_id` int(11) DEFAULT NULL,
  `referral_source` varchar(50) DEFAULT NULL,
  `sales_notes` text DEFAULT NULL,
  `enrollment_fees` decimal(10,2) DEFAULT NULL,
  `fees_status` enum('unpaid','partially_paid','fully_paid','overdue','cancelled') DEFAULT NULL,
  `fees_agreement_date` date DEFAULT NULL,
  `fees_payment_mode` enum('full','installment','partial','scholarship','sponsorship') DEFAULT NULL,
  `total_fees_paid` decimal(10,2) DEFAULT 0.00,
  `last_payment_date` date DEFAULT NULL,
  `next_payment_due_date` date DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `pincode` varchar(20) DEFAULT NULL,
  `terms_accepted` tinyint(1) DEFAULT 0,
  `terms_accepted_date` datetime DEFAULT NULL,
  `terms_accepted_ip` varchar(45) DEFAULT NULL,
  `registration_id` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`student_id`),
  KEY `idx_students_status` (`current_status`),
  KEY `idx_students_enrollment_date` (`enrollment_date`),
  KEY `idx_students_sales_person` (`sales_person_id`),
  KEY `idx_batch2` (`batch_name_2`),
  KEY `idx_batch3` (`batch_name_3`),
  CONSTRAINT `fk_students_sales_person` FOREIGN KEY (`sales_person_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES ('STD001',5,7,'test ','user','aryangupta.gca@gmail.com','6378811299',NULL,'2026-05-29','active',NULL,NULL,'B001','B001',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'bundi','$2y$10$sRhLCIs3YGVSLzk19f19PeNmacJY0c6pDGVFuV3tkCHI6BcAkvwjy',NULL,NULL,NULL,NULL,NULL,5000.00,'fully_paid',NULL,NULL,5000.00,NULL,NULL,NULL,NULL,NULL,1,'2026-05-29 17:34:46','127.0.0.1','ASD-2026-6891'),('STD002',0,11,'Dixant ','Choudhary','aryangtp@gmail.com','9530334990','2016-06-02','2026-06-02','active',NULL,NULL,'B002',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'','','','Rajasthan','$2y$10$bjzF6PNbQg1boGHHitpqeupHMKeloJ8I99N7YuG3ASGwlDCqjkkfC',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL);
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sub_topics`
--

DROP TABLE IF EXISTS `sub_topics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sub_topics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `main_topic_id` int(11) NOT NULL,
  `sub_topic_name` varchar(255) NOT NULL,
  `theory_completed` tinyint(1) DEFAULT 0,
  `practical_completed` tinyint(1) DEFAULT 0,
  `completed_by` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `main_topic_id` (`main_topic_id`),
  CONSTRAINT `fk_sub_topics_main` FOREIGN KEY (`main_topic_id`) REFERENCES `main_topics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sub_topics`
--

LOCK TABLES `sub_topics` WRITE;
/*!40000 ALTER TABLE `sub_topics` DISABLE KEYS */;
INSERT INTO `sub_topics` VALUES (1,101,'Linux Directory Layout',1,1,1,'2026-05-02 19:00:00','2026-05-29 12:04:46'),(2,101,'GNU/Linux Kernel & Shell concepts',1,1,1,'2026-05-02 19:30:00','2026-05-29 12:04:46'),(3,102,'Using cd, ls, pwd, mkdir, rm commands',1,1,1,'2026-05-10 19:00:00','2026-05-29 12:04:46'),(4,102,'Absolute vs Relative Paths',1,1,1,'2026-05-10 19:15:00','2026-05-29 12:04:46'),(5,102,'Permissions chmod & chown',1,1,1,'2026-05-10 19:30:00','2026-05-29 12:04:46'),(6,103,'Adding users (useradd, passwd)',0,0,NULL,NULL,'2026-05-29 12:04:46'),(7,103,'Managing groups (groupadd, usermod)',0,0,NULL,NULL,'2026-05-29 12:04:46');
/*!40000 ALTER TABLE `sub_topics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `test_answers`
--

DROP TABLE IF EXISTS `test_answers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `test_answers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attempt_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `selected_answer` enum('a','b','c','d','') DEFAULT '',
  `is_correct` tinyint(1) DEFAULT 0,
  `marks_obtained` decimal(5,2) DEFAULT 0.00,
  `answered_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attempt_question` (`attempt_id`,`question_id`),
  KEY `fk_answer_attempt` (`attempt_id`),
  KEY `fk_answer_question` (`question_id`),
  CONSTRAINT `fk_answer_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `test_attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_answer_question` FOREIGN KEY (`question_id`) REFERENCES `test_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `test_answers`
--

LOCK TABLES `test_answers` WRITE;
/*!40000 ALTER TABLE `test_answers` DISABLE KEYS */;
/*!40000 ALTER TABLE `test_answers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `test_attempts`
--

DROP TABLE IF EXISTS `test_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `test_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `test_id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `attempt_number` int(11) DEFAULT 1,
  `started_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `time_taken_seconds` int(11) DEFAULT 0,
  `total_questions` int(11) DEFAULT 0,
  `questions_attempted` int(11) DEFAULT 0,
  `correct_answers` int(11) DEFAULT 0,
  `wrong_answers` int(11) DEFAULT 0,
  `total_marks` decimal(10,2) DEFAULT 0.00,
  `obtained_marks` decimal(10,2) DEFAULT 0.00,
  `percentage` decimal(5,2) DEFAULT 0.00,
  `status` enum('in_progress','submitted','timeout') DEFAULT 'in_progress',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_test_attempt` (`test_id`,`student_id`,`attempt_number`),
  KEY `fk_attempt_test` (`test_id`),
  KEY `fk_attempt_student` (`student_id`),
  CONSTRAINT `fk_attempt_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attempt_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `test_attempts`
--

LOCK TABLES `test_attempts` WRITE;
/*!40000 ALTER TABLE `test_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `test_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `test_questions`
--

DROP TABLE IF EXISTS `test_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `test_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `test_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `option_a` text NOT NULL,
  `option_b` text NOT NULL,
  `option_c` text NOT NULL,
  `option_d` text NOT NULL,
  `correct_answer` enum('a','b','c','d') NOT NULL,
  `marks` int(11) DEFAULT 1,
  `explanation` text DEFAULT NULL,
  `question_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_question_test` (`test_id`),
  CONSTRAINT `fk_question_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `test_questions`
--

LOCK TABLES `test_questions` WRITE;
/*!40000 ALTER TABLE `test_questions` DISABLE KEYS */;
/*!40000 ALTER TABLE `test_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tests`
--

DROP TABLE IF EXISTS `tests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `batch_id` varchar(10) DEFAULT NULL,
  `subject` varchar(100) DEFAULT NULL,
  `total_marks` int(11) DEFAULT 0,
  `passing_marks` int(11) DEFAULT 0,
  `duration_minutes` int(11) DEFAULT 60,
  `max_attempts` int(11) DEFAULT 1,
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_test_batch` (`batch_id`),
  KEY `fk_test_creator` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tests`
--

LOCK TABLES `tests` WRITE;
/*!40000 ALTER TABLE `tests` DISABLE KEYS */;
/*!40000 ALTER TABLE `tests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tickets`
--

DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `status` enum('open','resolved') NOT NULL DEFAULT 'open',
  `admin_response` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `resolved_by` (`resolved_by`),
  CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_ibfk_2` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tickets`
--

LOCK TABLES `tickets` WRITE;
/*!40000 ALTER TABLE `tickets` DISABLE KEYS */;
INSERT INTO `tickets` VALUES (1,'STD001','Batch/Schedule Related','incorrect batch enrolled','uploads/tickets/ticket_STD001_1780390687_5142.pdf','resolved','okay it is been fixed now','2026-06-02 08:58:07','2026-06-02 08:58:51','2026-06-02 14:28:51',1),(2,'STD002','Exam/Test Related','exam results are invalid','uploads/tickets/ticket_STD002_1780391963_2110.pdf','resolved','okay it is fixed now','2026-06-02 09:19:23','2026-06-02 09:20:13','2026-06-02 14:50:13',1),(3,'STD002','Fee Related','payment keeps failing',NULL,'open',NULL,'2026-06-02 09:21:08','2026-06-02 09:21:08',NULL,NULL);
/*!40000 ALTER TABLE `tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `trainer_documents`
--

DROP TABLE IF EXISTS `trainer_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `trainer_documents` (
  `document_id` int(11) NOT NULL AUTO_INCREMENT,
  `trainer_id` int(11) NOT NULL,
  `document_type` enum('resume','certification','degree','id_proof','other') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trainer_documents`
--

LOCK TABLES `trainer_documents` WRITE;
/*!40000 ALTER TABLE `trainer_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `trainer_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `trainers`
--

DROP TABLE IF EXISTS `trainers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `trainers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `qualifications` text DEFAULT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `joining_date` date DEFAULT NULL,
  `years_of_experience` int(11) DEFAULT 0,
  `bio` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `trainers`
--

LOCK TABLES `trainers` WRITE;
/*!40000 ALTER TABLE `trainers` DISABLE KEYS */;
/*!40000 ALTER TABLE `trainers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` varchar(50) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `student_name` varchar(200) NOT NULL,
  `father_name` varchar(200) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `total_fees` decimal(10,2) DEFAULT 0.00,
  `previous_payments` decimal(10,2) DEFAULT 0.00,
  `outstanding_fees` decimal(10,2) DEFAULT 0.00,
  `batch_id` varchar(10) NOT NULL,
  `course_name` varchar(255) DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_mode` enum('bank_transfer','cash','cheque','other') NOT NULL DEFAULT 'cash',
  `payment_recipient` enum('ASDN Cybernatics','BugDetox Technologies LLP','Other') NOT NULL DEFAULT 'ASDN Cybernatics',
  `screenshot_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','verified','rejected','cancelled') DEFAULT 'pending',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `verification_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `receipt_no` varchar(50) DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `is_manual` tinyint(1) DEFAULT 0,
  `receipt_sent` tinyint(1) DEFAULT 0,
  `receipt_sent_at` datetime DEFAULT NULL,
  `receipt_generated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_id` (`transaction_id`),
  KEY `fk_transaction_student` (`student_id`),
  KEY `fk_transaction_batch` (`batch_id`),
  KEY `fk_transaction_verifier` (`verified_by`),
  KEY `idx_is_manual` (`is_manual`),
  KEY `idx_receipt_no` (`receipt_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `uploads`
--

DROP TABLE IF EXISTS `uploads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `uploads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` enum('Assignment','Notes','Test','Lab Manual','Other') NOT NULL,
  `due_date` date DEFAULT NULL,
  `due_time` time DEFAULT NULL,
  `max_marks` decimal(5,2) DEFAULT 100.00,
  `category_id` int(11) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `content_source` enum('file','drive') DEFAULT 'file',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=203 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `uploads`
--

LOCK TABLES `uploads` WRITE;
/*!40000 ALTER TABLE `uploads` DISABLE KEYS */;
INSERT INTO `uploads` VALUES (201,'Linux Commands Cheat Sheet','Complete list of essential commands for terminal manipulation.','uploads/linux_cheat_sheet.pdf','Notes',NULL,NULL,0.00,NULL,1,'2026-05-29 12:04:46','file'),(202,'Assignment 1: Shell Script Backup','Create a bash script that takes a directory path and generates a compressed tar.gz backup.','uploads/assignment_1.pdf','Assignment','2026-06-10',NULL,100.00,NULL,1,'2026-05-29 12:04:46','file');
/*!40000 ALTER TABLE `uploads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_lock_logs`
--

DROP TABLE IF EXISTS `user_lock_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_lock_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` enum('locked','unlocked') NOT NULL,
  `reason` text DEFAULT NULL,
  `performed_by` int(11) NOT NULL,
  `performed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `duration_days` int(11) DEFAULT NULL COMMENT 'For temporary locks',
  `expiry_date` date DEFAULT NULL COMMENT 'For temporary locks',
  PRIMARY KEY (`id`),
  KEY `fk_lock_log_user` (`user_id`),
  KEY `fk_lock_log_performer` (`performed_by`),
  CONSTRAINT `fk_lock_log_performer` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lock_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_lock_logs`
--

LOCK TABLES `user_lock_logs` WRITE;
/*!40000 ALTER TABLE `user_lock_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_lock_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','mentor','student','sales','accounts') NOT NULL DEFAULT 'student',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `failed_login_attempts` int(11) NOT NULL DEFAULT 0,
  `account_locked` tinyint(1) NOT NULL DEFAULT 0,
  `locked_reason` text DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` int(11) DEFAULT NULL,
  `last_failed_login` datetime DEFAULT NULL,
  `login_attempt_limit` int(11) NOT NULL DEFAULT 5,
  PRIMARY KEY (`id`),
  KEY `idx_locked_by` (`locked_by`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Admin','admin@asdacademy.com','$2y$10$m1/e1Ur4iEhEFBFWzsNmFOt5JZI.9iy3B53VgwKLlxZde5ttoX8vC','admin','2026-05-29 11:54:02','2026-06-02 14:43:13','active',0,0,NULL,NULL,NULL,NULL,3),(7,'test  user','aryangupta.gca@gmail.com','$2y$10$sRhLCIs3YGVSLzk19f19PeNmacJY0c6pDGVFuV3tkCHI6BcAkvwjy','student','2026-05-28 15:16:58','2026-06-02 14:39:29','active',0,0,NULL,NULL,NULL,NULL,5),(8,'test  user','aryangupta.gca@gmail.com','$2y$10$sRhLCIs3YGVSLzk19f19PeNmacJY0c6pDGVFuV3tkCHI6BcAkvwjy','student','2026-05-28 15:22:45',NULL,'active',0,0,NULL,NULL,NULL,NULL,5),(9,'test  user','aryangupta.gca@gmail.com','$2y$10$sRhLCIs3YGVSLzk19f19PeNmacJY0c6pDGVFuV3tkCHI6BcAkvwjy','student','2026-05-29 11:27:55',NULL,'active',0,0,NULL,NULL,NULL,NULL,5),(10,'test  user','aryangupta.gca@gmail.com','$2y$10$sRhLCIs3YGVSLzk19f19PeNmacJY0c6pDGVFuV3tkCHI6BcAkvwjy','student','2026-05-29 12:00:18','2026-05-29 17:31:05','active',0,0,NULL,NULL,NULL,NULL,5),(11,'Dixant  Choudhary','aryangtp@gmail.com','$2y$10$bjzF6PNbQg1boGHHitpqeupHMKeloJ8I99N7YuG3ASGwlDCqjkkfC','student','2026-06-02 09:11:30','2026-06-02 14:48:37','active',0,0,NULL,NULL,NULL,NULL,5);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `weekly_feedback`
--

DROP TABLE IF EXISTS `weekly_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `weekly_feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` varchar(10) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `trainer_id` int(11) NOT NULL,
  `week_start_date` date NOT NULL,
  `week_end_date` date NOT NULL,
  `rating` tinyint(1) NOT NULL CHECK (`rating` between 1 and 5),
  `remarks` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_weekly_feedback` (`batch_id`,`student_id`,`trainer_id`,`week_start_date`),
  KEY `fk_feedback_batch` (`batch_id`),
  KEY `fk_feedback_student` (`student_id`),
  KEY `fk_feedback_trainer` (`trainer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `weekly_feedback`
--

LOCK TABLES `weekly_feedback` WRITE;
/*!40000 ALTER TABLE `weekly_feedback` DISABLE KEYS */;
/*!40000 ALTER TABLE `weekly_feedback` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `weekly_report_cards`
--

DROP TABLE IF EXISTS `weekly_report_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `weekly_report_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `week_start_date` date NOT NULL,
  `week_end_date` date NOT NULL,
  `attendance_score` decimal(3,2) DEFAULT 0.00,
  `attendance_days_present` int(3) DEFAULT 0,
  `attendance_days_total` int(3) DEFAULT 0,
  `feedback_score` decimal(3,2) DEFAULT 0.00,
  `assignment_score` decimal(3,2) DEFAULT 0.00,
  `overall_score` decimal(3,2) DEFAULT 0.00,
  `trainer_comments` text DEFAULT NULL,
  `areas_of_improvement` text DEFAULT NULL,
  `next_week_focus` text DEFAULT NULL,
  `generated_by` int(11) DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `download_count` int(11) DEFAULT 0,
  `last_downloaded` datetime DEFAULT NULL,
  `status` enum('draft','published','archived') DEFAULT 'draft',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_weekly_report` (`student_id`,`batch_id`,`week_start_date`),
  KEY `fk_report_student` (`student_id`),
  KEY `fk_report_batch` (`batch_id`),
  KEY `fk_report_generator` (`generated_by`),
  KEY `idx_week_date` (`week_start_date`,`week_end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `weekly_report_cards`
--

LOCK TABLES `weekly_report_cards` WRITE;
/*!40000 ALTER TABLE `weekly_report_cards` DISABLE KEYS */;
/*!40000 ALTER TABLE `weekly_report_cards` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `workshop_attendance`
--

DROP TABLE IF EXISTS `workshop_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workshop_attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workshop_id` varchar(10) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `session_id` int(11) DEFAULT NULL,
  `attendance_status` enum('present','absent','late') NOT NULL DEFAULT 'absent',
  `attended_at` datetime DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `workshop_attendance`
--

LOCK TABLES `workshop_attendance` WRITE;
/*!40000 ALTER TABLE `workshop_attendance` DISABLE KEYS */;
/*!40000 ALTER TABLE `workshop_attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `workshop_feedback`
--

DROP TABLE IF EXISTS `workshop_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workshop_feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workshop_id` varchar(10) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `rating` tinyint(1) NOT NULL CHECK (`rating` between 1 and 5),
  `feedback_text` text DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `trainer_rating` tinyint(1) DEFAULT NULL CHECK (`trainer_rating` between 1 and 5),
  `content_rating` tinyint(1) DEFAULT NULL CHECK (`content_rating` between 1 and 5),
  `organization_rating` tinyint(1) DEFAULT NULL CHECK (`organization_rating` between 1 and 5),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `workshop_feedback`
--

LOCK TABLES `workshop_feedback` WRITE;
/*!40000 ALTER TABLE `workshop_feedback` DISABLE KEYS */;
/*!40000 ALTER TABLE `workshop_feedback` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `workshop_materials`
--

DROP TABLE IF EXISTS `workshop_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workshop_materials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workshop_id` varchar(10) NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` enum('slides','handout','exercise','recording','other') NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_public` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `workshop_materials`
--

LOCK TABLES `workshop_materials` WRITE;
/*!40000 ALTER TABLE `workshop_materials` DISABLE KEYS */;
/*!40000 ALTER TABLE `workshop_materials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `workshop_registrations`
--

DROP TABLE IF EXISTS `workshop_registrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workshop_registrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workshop_id` varchar(10) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `registration_date` datetime NOT NULL DEFAULT current_timestamp(),
  `payment_status` enum('pending','paid','failed','refunded') DEFAULT 'pending',
  `payment_amount` decimal(10,2) DEFAULT NULL,
  `payment_date` datetime DEFAULT NULL,
  `attendance_status` enum('registered','attended','absent','cancelled') DEFAULT 'registered',
  `certificate_issued` tinyint(1) DEFAULT 0,
  `certificate_issued_date` datetime DEFAULT NULL,
  `certificate_path` varchar(255) DEFAULT NULL,
  `feedback_submitted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `workshop_registrations`
--

LOCK TABLES `workshop_registrations` WRITE;
/*!40000 ALTER TABLE `workshop_registrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `workshop_registrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `workshop_schedule`
--

DROP TABLE IF EXISTS `workshop_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workshop_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workshop_id` varchar(10) NOT NULL,
  `session_title` varchar(100) NOT NULL,
  `session_description` text DEFAULT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `trainer_id` int(11) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `is_break` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `workshop_schedule`
--

LOCK TABLES `workshop_schedule` WRITE;
/*!40000 ALTER TABLE `workshop_schedule` DISABLE KEYS */;
/*!40000 ALTER TABLE `workshop_schedule` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `workshops`
--

DROP TABLE IF EXISTS `workshops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workshops` (
  `workshop_id` varchar(10) NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `location` varchar(100) DEFAULT NULL,
  `max_participants` int(11) DEFAULT NULL,
  `current_registrations` int(11) DEFAULT 0,
  `trainer_id` int(11) DEFAULT NULL,
  `fee` decimal(10,2) DEFAULT 0.00,
  `status` enum('upcoming','ongoing','completed','cancelled') DEFAULT 'upcoming',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `cover_image` varchar(255) DEFAULT NULL,
  `requirements` text DEFAULT NULL,
  `certificate_available` tinyint(1) DEFAULT 0,
  `difficulty_level` enum('beginner','intermediate','advanced') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `workshops`
--

LOCK TABLES `workshops` WRITE;
/*!40000 ALTER TABLE `workshops` DISABLE KEYS */;
/*!40000 ALTER TABLE `workshops` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-02 14:58:37
