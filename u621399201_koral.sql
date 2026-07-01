-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 05, 2026 at 08:14 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `asd_academy`
--
CREATE DATABASE IF NOT EXISTS `u621399201_koral` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `u621399201_koral`;

-- --------------------------------------------------------

--
-- Table structure for table `admin_users`
--

CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_users`
--

INSERT INTO `admin_users` (`id`, `username`, `password`, `email`, `created_at`) VALUES
(1, 'admin', '$2y$10$jG0f/4DQHX1C1g5tDzo/8e71QJ3QpETSXx8D4T9FqRI8PSJZQaoti', 'admin@asd.com', '2026-05-12 16:58:54');

-- --------------------------------------------------------

--
-- Table structure for table `banners`
--

CREATE TABLE `banners` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subtitle` text DEFAULT NULL,
  `image_url` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `banners`
--

INSERT INTO `banners` (`id`, `title`, `subtitle`, `image_url`, `link`, `status`) VALUES
(1, 'Cyber Security Courses', 'Ab Har Ghar KOTA Classroom!', 'public/upload/banners/banner1.png', NULL, 'active'),
(2, 'Predict Your Rank', 'With ASD Academy Rank Predictor', 'public/upload/banners/banner2.png', NULL, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `careers`
--

CREATE TABLE `careers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `field` varchar(255) DEFAULT NULL,
  `resume_path` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `coupons`
--

CREATE TABLE `coupons` (
  `id` int(11) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `discount_percent` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `usage_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `duration` varchar(50) DEFAULT NULL,
  `price` varchar(50) DEFAULT NULL,
  `format` varchar(100) DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `category` varchar(100) DEFAULT 'Masterclass',
  `mode` varchar(50) DEFAULT 'Online',
  `discount` varchar(255) DEFAULT NULL,
  `plan_a` varchar(255) DEFAULT NULL,
  `plan_b` varchar(255) DEFAULT NULL,
  `plan_c` varchar(255) DEFAULT NULL,
  `link_one_time` text DEFAULT NULL,
  `link_plan_a` text DEFAULT NULL,
  `link_plan_b` text DEFAULT NULL,
  `link_plan_c` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`id`, `title`, `duration`, `price`, `format`, `status`, `created_at`, `category`, `mode`, `discount`, `plan_a`, `plan_b`, `plan_c`, `link_one_time`, `link_plan_a`, `link_plan_b`, `link_plan_c`) VALUES
(3, 'SUPER 30', 'N/A', '₹80,000', 'Online/Offline', 'Active', '2026-05-20 17:36:59', 'Flagship Program', 'Offline', 'No Discount', '11-Month Plan | Admission ₹11,800 | Monthly ₹6,200 × 11', '10-Month Plan | Admission ₹17,700 | Monthly ₹6,230 × 10', '09-Month Plan | Admission ₹23,600 | Monthly ₹6,267 × 9', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10000,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10001,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10002,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10003,INR'),
(4, 'SUPER 30', 'N/A', '₹59,000', 'Online/Offline', 'Active', '2026-05-20 17:36:59', 'Flagship Program', 'Online', 'No Discount', '08-Month Plan | Admission ₹11,800 | Monthly ₹5,900 × 8', '07-Month Plan | Admission ₹17,700 | Monthly ₹5,900 × 7', '06-Month Plan | Admission ₹23,600 | Monthly ₹5,900 × 6', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,66027,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10005,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10006,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10007,INR'),
(5, 'LET\'S WIN', 'N/A', '₹59,000', 'Online/Offline', 'Active', '2026-05-20 17:36:59', 'Flagship Program', 'Offline', 'No Discount', '08-Month Plan | Admission ₹11,800 | Monthly ₹5,900 × 8', '07-Month Plan | Admission ₹17,700 | Monthly ₹5,900 × 7', '06-Month Plan | Admission ₹23,600 | Monthly ₹5,900 × 6', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10008,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10009,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10010,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10011,INR'),
(6, 'LET\'S WIN', 'N/A', '₹35,400', 'Online/Offline', 'Active', '2026-05-20 17:36:59', 'Flagship Program', 'Online', 'No Discount', '04-Month Plan | Admission ₹11,800 | Monthly ₹5,900 × 4', '03-Month Plan | Admission ₹17,700 | Monthly ₹5,900 × 3', '02-Month Plan | Admission ₹23,600 | Monthly ₹5,900 × 2', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10012,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10013,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10014,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10015,INR'),
(7, 'SPECIALIZATION', 'N/A', '₹35,400', 'Online/Offline', 'Active', '2026-05-20 17:36:59', 'Flagship Program', 'Online', '₹5,400 Discount on One Time Payment', 'Installment Plan A | Admission ₹11,800 | 2 Installments', 'Installment Plan B | Admission ₹17,700 | 2 Installments', 'Installment Plan C | Admission ₹23,600 | 1 Installment', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10016,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10017,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10018,INR', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10019,INR'),
(8, 'Networking', 'N/A', '₹5,500', 'Online/Offline', 'Active', '2026-05-20 17:36:59', 'Masterclass', 'Offline', 'No Discount', 'No Installment', 'No Installment', 'No Installment', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10020,INR', NULL, NULL, NULL),
(9, 'Python', 'N/A', '₹2,100', 'Online/Offline', 'Active', '2026-05-20 17:36:59', 'Masterclass', 'Online', 'No Discount', 'No Installment', 'No Installment', 'No Installment', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10021,INR', NULL, NULL, NULL),
(10, 'Windows Server', 'N/A', '₹2,100', 'Online/Offline', 'Active', '2026-05-20 17:36:59', 'Masterclass', 'Online', 'No Discount', 'No Installment', 'No Installment', 'No Installment', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10022,INR', NULL, NULL, NULL),
(11, 'SOC (SPLUNK)', 'N/A', '₹10,500', 'Online/Offline', 'Active', '2026-05-20 17:37:00', 'Masterclass', 'Offline', 'No Discount', 'No Installment', 'No Installment', 'No Installment', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10023,INR', NULL, NULL, NULL),
(12, 'Linux Fundamental', 'N/A', '₹699', 'Online/Offline', 'Active', '2026-05-20 17:37:00', 'Masterclass', 'Online', 'No Discount', 'No Installment', 'No Installment', 'No Installment', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10024,INR', NULL, NULL, NULL),
(13, 'Ethical Hacking v13', 'N/A', '₹10,500', 'Online/Offline', 'Active', '2026-05-20 17:37:00', 'Masterclass', 'Offline', 'No Discount', 'No Installment', 'No Installment', 'No Installment', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10025,INR', NULL, NULL, NULL),
(14, 'WAPT', 'N/A', '₹9,000', 'Online/Offline', 'Active', '2026-05-20 17:37:00', 'Masterclass', 'Offline', 'No Discount', 'No Installment', 'No Installment', 'No Installment', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10026,INR', NULL, NULL, NULL),
(15, 'Mobile App Testing', 'N/A', '₹6,200', 'Online/Offline', 'Active', '2026-05-20 17:37:00', 'Masterclass', 'Offline', 'No Discount', 'No Installment', 'No Installment', 'No Installment', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10027,INR', NULL, NULL, NULL),
(16, 'API Testing', 'N/A', '₹3,500', 'Online/Offline', 'Active', '2026-05-20 17:37:00', 'Masterclass', 'Online', 'No Discount', 'No Installment', 'No Installment', 'No Installment', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10028,INR', NULL, NULL, NULL),
(17, 'Source Code Review', 'N/A', '₹4,200', 'Online/Offline', 'Active', '2026-05-20 17:37:00', 'Masterclass', 'Online', 'No Discount', 'No Installment', 'No Installment', 'No Installment', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10029,INR', NULL, NULL, NULL),
(18, 'SC-900', 'N/A', '₹5,500', 'Online/Offline', 'Active', '2026-05-20 17:37:00', 'Masterclass', 'Online', 'No Discount', 'No Installment', 'No Installment', 'No Installment', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10030,INR', NULL, NULL, NULL),
(19, 'ISO -27001', 'N/A', '₹6,200', 'Online/Offline', 'Active', '2026-05-20 17:37:00', 'Masterclass', 'Online', 'No Discount', 'No Installment', 'No Installment', 'No Installment', 'https://secure.ccavenue.com/txn/shopcart/AVAC66LB60CH58CAHC,10031,INR', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `course_contents`
--

CREATE TABLE `course_contents` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `short_description` varchar(500) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `duration` varchar(255) DEFAULT NULL,
  `tech` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tech`)),
  `seats` varchar(100) DEFAULT NULL,
  `placementInfo` varchar(255) DEFAULT NULL,
  `color` varchar(50) DEFAULT 'blue',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `course_contents`
--

INSERT INTO `course_contents` (`id`, `title`, `short_description`, `description`, `duration`, `tech`, `seats`, `placementInfo`, `color`, `created_at`) VALUES
(10, 'Super 30', 'Flagship 1-year premium training with guaranteed placement.', 'Our flagship 1-year premium training program designed for elite cybersecurity aspirants.', '1 Year Training + 3 Month Internship', '[\"CCNA\", \"Python\", \"SOC\", \"VAPT\", \"ISO 27001\"]', '15 Students per Batch', 'Guaranteed Placement Assistance', 'purple', '2026-05-15 09:28:42'),
(11, 'Let\'s Win', 'Intensive career acceleration with industry expert mentorship.', 'Intensive training with live industry expert sessions, mock interviews, and personalized career guidance.', '1 Year Training', '[\"Networking\", \"Linux\", \"Ethical Hacking\", \"Cloud\"]', '30 Students per Batch', 'Expert Industry Sessions', 'blue', '2026-05-15 09:28:42'),
(12, 'Specialization in Cyber Security', 'Fast-track VAPT and Ethical Hacking specialization.', 'Fast-track your career with focused modules in Ethical Hacking, VAPT, and core Cyber Security.', '3 Month Training + 1.5 Month Internship', '[\"Cyber Security\", \"Ethical Hacking\", \"VAPT\"]', 'Recorded + Live Sessions', 'Mock Interviews & Career Prep', 'green', '2026-05-15 09:28:42');

-- --------------------------------------------------------

--
-- Table structure for table `flagship_programs`
--

CREATE TABLE `flagship_programs` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `features` text DEFAULT NULL,
  `total_fee` varchar(100) DEFAULT NULL,
  `duration` varchar(100) DEFAULT '',
  `admission_fee` varchar(100) DEFAULT NULL,
  `online_fee` varchar(100) DEFAULT NULL,
  `offline_fee` varchar(100) DEFAULT NULL,
  `one_time_discount` varchar(100) DEFAULT NULL,
  `installment_plans` text DEFAULT NULL,
  `installments_pdf` varchar(255) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `full_description` text DEFAULT NULL,
  `syllabus` text DEFAULT NULL,
  `tech` text DEFAULT NULL,
  `seats` varchar(255) DEFAULT NULL,
  `short_description` text DEFAULT NULL,
  `placementInfo` varchar(255) DEFAULT NULL,
  `color` varchar(50) DEFAULT 'blue',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `flagship_programs`
--

INSERT INTO `flagship_programs` (`id`, `title`, `subtitle`, `description`, `features`, `total_fee`, `duration`, `admission_fee`, `online_fee`, `offline_fee`, `one_time_discount`, `installment_plans`, `installments_pdf`, `image_url`, `full_description`, `syllabus`, `tech`, `seats`, `short_description`, `placementInfo`, `color`, `status`, `created_at`) VALUES
(1, 'SUPER 30', 'Diploma in Cyber Security', 'Dive deep with our Special Cyber Security Courses. Tailored expertise for advanced cyber security skills.', '[\"1 Year (Training) + 3 Month (Internship)\",\"100% Placement Assistance\",\"15 Students Per Batch\",\"Sessions By Industry Experts\"]', '59,000 RS.', '', '11,800 RS.', '59,000', '80,000', '', '{\"online\":[{\"name\":\"8 months installment plan\",\"admission_amount\":\"11,800\"},{\"name\":\"7 months installment plan\",\"admission_amount\":\"17,700\"},{\"name\":\"6 months installment plan\",\"admission_amount\":\"23,600\"}],\"offline\":[{\"name\":\"11 Months Installment Plan\",\"admission_amount\":\"11,800\"},{\"name\":\"10 Months Installment Plan\",\"admission_amount\":\"17,700\"},{\"name\":\"9 Months installment plan\",\"admission_amount\":\"23,600\"}]}', 'asd-backend/uploads/courses/1778745789_6a0581bdda16f.pdf', 'assets/courses/super30.png', '', '[]', '[]', '', '', '', 'blue', 'active', '2026-05-13 07:13:55'),
(2, 'LET\'S WIN', 'Diploma in Cyber Security', 'Dive deep with our Special Cyber Security Courses. Tailored expertise for advanced cyber security skills.', '[\"1 Year (Training)\",\"30 Students Per Batch\",\"Learn From Expertise\",\"Access Free Content\",\"Resume Building Sessions\"]', '35,400 RS.', '', '11,800 RS.', '35,400', '59,000', '', '{\"online\":[{\"name\":\"4 months installment plan\",\"admission_amount\":\"11,800\"},{\"name\":\"3 months installment plan\",\"admission_amount\":\"17,700\"},{\"name\":\"2 months installment plan\",\"admission_amount\":\"23,600\"}],\"offline\":[{\"name\":\"8 months installment plan\",\"admission_amount\":\"11,800\"},{\"name\":\"7 months installment plan\",\"admission_amount\":\"17,700\"},{\"name\":\"6 months installment plan\",\"admission_amount\":\"23,600\"}]}', 'asd-backend/uploads/courses/1778745861_6a0582055711f.pdf', 'assets/courses/letswin.png', 'Detailed description for Let\'s Win...', '[]', NULL, NULL, NULL, NULL, 'blue', 'active', '2026-05-13 07:13:55'),
(3, 'SPECIALIZATION', 'Diploma in Cyber Security', 'Dive deep with our Special Cyber Security Courses. Tailored expertise for advanced cyber security skills.', '[\"3 Month (Training) + 1.5 Month (Internship)\",\"Live Doubt Classes\",\"Recorded Video Lectures\",\"Diploma Certificate\"]', '35,400 RS.', '', '20,000 RS.', '35,400', '', '5,400', '{\"online\":[{\"name\":\"installment plan a\",\"admission_amount\":\"11,800\"},{\"name\":\"installment plan b\",\"admission_amount\":\"17,700\"},{\"name\":\"installment plan c\",\"admission_amount\":\"23,600\"}],\"offline\":[]}', 'asd-backend/uploads/courses/1778756153_6a05aa393c418.pdf', 'assets/courses/specialization.png', 'Detailed description for Specialization...', '[]', NULL, NULL, NULL, NULL, 'blue', 'active', '2026-05-13 07:13:55');

-- --------------------------------------------------------

--
-- Table structure for table `grow_with_study_content`
--

CREATE TABLE `grow_with_study_content` (
  `id` int(11) NOT NULL,
  `section` varchar(50) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `grow_with_study_content`
--

INSERT INTO `grow_with_study_content` (`id`, `section`, `title`, `subtitle`, `description`, `image`, `icon`, `color`, `link`, `sort_order`, `created_at`) VALUES
(1, 'hero', 'Grow with Study', 'Unlock Your Potential: Marketing Jobs for Future Innovators', 'Join the only program with 100% practical exposure.', '', '', '', '', 0, '2026-05-17 04:03:03'),
(2, 'income', 'INCENTIVE INCOME', '', '', '', 'ClipboardList', 'bg-[#E1251B]', '', 1, '2026-05-17 04:03:03'),
(3, 'income', 'BONUS INCOME', '', '', '', 'TrendingUp', 'bg-white', '', 2, '2026-05-17 04:03:03'),
(4, 'income', 'REWARDS INCOME', '', '', '', 'Award', 'bg-[#E1251B]', '', 3, '2026-05-17 04:03:03'),
(5, 'income', 'TARGET INCOME', '', '', '', 'Goal', 'bg-white', '', 4, '2026-05-17 04:03:03'),
(6, 'income', 'COMMISSION INCOME', '', '', '', 'Landmark', 'bg-[#E1251B]', '', 5, '2026-05-17 04:03:03'),
(7, 'organizer', 'Digital Marketing', '', 'Master SEO, SEM, and social media growth.', '', 'Target', '', '', 1, '2026-05-17 04:03:03'),
(8, 'organizer', 'Cybersecurity', '', 'Protect digital assets with ethical hacking.', '', 'Shield', '', '', 2, '2026-05-17 04:03:03'),
(9, 'organizer', 'WordPress', '', 'Build stunning websites with CMS expertise.', '', 'Globe', '', '', 3, '2026-05-17 04:03:03'),
(10, 'organizer', 'Android Application', '', 'Create powerful mobile experiences.', '', 'Smartphone', '', '', 5, '2026-05-17 04:03:03');

-- --------------------------------------------------------

--
-- Table structure for table `home_content`
--

CREATE TABLE `home_content` (
  `id` int(11) NOT NULL,
  `section_type` varchar(50) NOT NULL,
  `content_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`content_json`)),
  `order_index` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `home_content`
--

INSERT INTO `home_content` (`id`, `section_type`, `content_json`, `order_index`, `created_at`) VALUES
(1, 'reviews', '{\"name\":\"Alex Thompson\",\"role\":\"Penetration Tester\",\"content\":\"The level of detail in the offensive security course is insane. I learned more in 3 months here than I did in my 4-year degree.\",\"rating\":5,\"platform\":\"Trustpilot\"}', 0, '2026-05-15 17:43:48'),
(2, 'reviews', '{\"name\":\"Sarah Jenkins\",\"role\":\"Security Consultant\",\"content\":\"Best decision of my career. The labs are tough, but the mentors are always there to push you in the right direction without spoon-feeding.\",\"rating\":5,\"platform\":\"Google\"}', 1, '2026-05-15 17:43:48'),
(3, 'reviews', '{\"name\":\"Marcus Williams\",\"role\":\"SOC Analyst\",\"content\":\"The placement assistance is real. I had 3 interviews lined up before I even finished the final module. Secured an offer a week after graduation.\",\"rating\":5,\"platform\":\"Trustpilot\"}', 2, '2026-05-15 17:43:48'),
(4, 'certTracks', '{\"title\":\"Offensive Pentesting Mastery\",\"tag\":\"Most Popular\",\"tagVariant\":\"accent\",\"duration\":\"6 Months\",\"level\":\"Advanced\",\"features\":[\"Advanced Web Exploitation\",\"Network Pivoting\",\"Active Directory Attacks\",\"Buffer Overflows\"]}', 0, '2026-05-15 17:43:48'),
(5, 'certTracks', '{\"title\":\"Bug Bounty Hunter\",\"tag\":\"Beginner Friendly\",\"tagVariant\":\"success\",\"duration\":\"3 Months\",\"level\":\"Intermediate\",\"features\":[\"Reconnaissance Pro\",\"OWASP Top 10 Deep Dive\",\"Automated Scanners\",\"Report Writing\"]}', 1, '2026-05-15 17:43:48'),
(6, 'certTracks', '{\"title\":\"Red Team Operations\",\"tag\":\"Elite\",\"tagVariant\":\"default\",\"duration\":\"4 Months\",\"level\":\"Expert\",\"features\":[\"C2 Infrastructure Setup\",\"Evasion Techniques\",\"Physical Security\",\"Social Engineering\"]}', 2, '2026-05-15 17:43:48'),
(7, 'stats', '{\"label\":\"Highest Package\",\"value\":\"45 LPA\"}', 0, '2026-05-15 17:43:48'),
(8, 'stats', '{\"label\":\"Placement Rate\",\"value\":\"100%\"}', 1, '2026-05-15 17:43:48'),
(9, 'stats', '{\"label\":\"Global Certifications\",\"value\":\"1200+\"}', 2, '2026-05-15 17:43:48'),
(10, 'placements', '{\"name\":\"Liam O\'Connor\",\"role\":\"SOC Analyst\",\"company\":\"CyberDefend\",\"pkg\":\"12 LPA\"}', 0, '2026-05-15 17:43:48'),
(11, 'placements', '{\"name\":\"Aisha Patel\",\"role\":\"Vulnerability Researcher\",\"company\":\"SecureNet\",\"pkg\":\"15 LPA\"}', 1, '2026-05-15 17:43:48'),
(12, 'placements', '{\"name\":\"James Wilson\",\"role\":\"Junior Pentester\",\"company\":\"RedTeam Ops\",\"pkg\":\"10 LPA\"}', 2, '2026-05-15 17:43:48'),
(13, 'placements', '{\"name\":\"Sophia Lee\",\"role\":\"Incident Responder\",\"company\":\"TechGuard\",\"pkg\":\"14 LPA\"}', 3, '2026-05-15 17:43:48'),
(14, 'gallery', '{\"type\":\"video\",\"title\":\"Defcon 2025 Highlights\",\"image\":\"https:\\/\\/images.unsplash.com\\/photo-1517245386807-bb43f82c33c4?q=80&w=2070&auto=format&fit=crop\",\"installment_plans\":\"[object Object]\"}', 0, '2026-05-15 17:43:48'),
(15, 'gallery', '{\"type\":\"image\",\"title\":\"Hackathon Winners\",\"image\":\"https:\\/\\/images.unsplash.com\\/photo-1542831371-29b0f74f9713?q=80&w=2070&auto=format&fit=crop\"}', 1, '2026-05-15 17:43:48'),
(16, 'gallery', '{\"type\":\"image\",\"title\":\"Guest Lecture: NSA\",\"image\":\"https:\\/\\/images.unsplash.com\\/photo-1558494949-ef010cbdcc31?q=80&w=2034&auto=format&fit=crop\"}', 2, '2026-05-15 17:43:48'),
(17, 'gallery', '{\"type\":\"image\",\"title\":\"Hardware Hacking Lab\",\"image\":\"https:\\/\\/images.unsplash.com\\/photo-1518770660439-4636190af475?q=80&w=2070&auto=format&fit=crop\"}', 3, '2026-05-15 17:43:48'),
(18, 'gallery', '{\"type\":\"video\",\"title\":\"Student Alumni Meetup\",\"image\":\"https:\\/\\/images.unsplash.com\\/photo-1511512578047-dfb367046420?q=80&w=2071&auto=format&fit=crop\"}', 4, '2026-05-15 17:43:48'),
(19, 'prepPrograms', '{\"title\":\"Cyber Security\",\"description\":\"Foundational and advanced prep for entering the core cybersecurity domain.\",\"icon\":\"target\",\"color\":\"accent\"}', 0, '2026-05-15 17:43:48'),
(20, 'prepPrograms', '{\"title\":\"CTF Hunter\",\"description\":\"Intense training to crack global Capture The Flag competitions.\",\"icon\":\"flag\",\"color\":\"blue\"}', 1, '2026-05-15 17:43:48'),
(21, 'prepPrograms', '{\"title\":\"Placement Preparation\",\"description\":\"Mock interviews, resume building, and technical assessments.\",\"icon\":\"briefcase\",\"color\":\"emerald\"}', 2, '2026-05-15 17:43:48'),
(22, 'prepPrograms', '{\"title\":\"Bug Hunter\",\"description\":\"Master the art of finding vulnerabilities in live production systems.\",\"icon\":\"bug\",\"color\":\"yellow\"}', 3, '2026-05-15 17:43:48'),
(23, 'prepPrograms', '{\"title\":\"Hacker Exam\",\"description\":\"Rigorous prep for OSCP, CEH, and other elite certifications.\",\"icon\":\"graduation\",\"color\":\"purple\"}', 4, '2026-05-15 17:43:48'),
(24, 'teamSupport', '{\"name\":\"Elena Rodriguez\",\"role\":\"Career Counselor\",\"image\":\"https:\\/\\/images.unsplash.com\\/photo-1573497019940-1c28c88b4f3e?auto=format&fit=crop&q=80&w=400&h=500\",\"badges\":[\"Admissions\",\"Guidance\"]}', 0, '2026-05-15 17:43:48'),
(25, 'teamSupport', '{\"name\":\"Michael Chang\",\"role\":\"Lead Mentor Support\",\"image\":\"https:\\/\\/images.unsplash.com\\/photo-1560250097-0b93528c311a?auto=format&fit=crop&q=80&w=400&h=500\",\"badges\":[\"Technical\",\"Labs\"]}', 1, '2026-05-15 17:43:48'),
(26, 'teamSupport', '{\"name\":\"Sarah Jenkins\",\"role\":\"Student Success Manager\",\"image\":\"https:\\/\\/images.unsplash.com\\/photo-1580489944761-15a19d654956?auto=format&fit=crop&q=80&w=400&h=500\",\"badges\":[\"Placements\",\"Alumni\"]}', 2, '2026-05-15 17:43:48'),
(27, 'aiCourses', '{\"title\":\"AI-Powered Penetration Testing\",\"description\":\"Learn to use machine learning models to automate vulnerability discovery and exploitation.\",\"icon\":\"cpu\",\"features\":[\"Automated Scanning\",\"ML Threat Modeling\",\"Smart Exploitation\"],\"duration\":\"12 Weeks\"}', 0, '2026-05-15 17:43:48'),
(28, 'aiCourses', '{\"title\":\"Next-Gen SOC Analytics\",\"description\":\"Master AI-driven log analysis and automated incident response for modern security operations.\",\"icon\":\"shield-alert\",\"features\":[\"Predictive Analytics\",\"Anomaly Detection\",\"Automated IR\"],\"duration\":\"8 Weeks\"}', 1, '2026-05-15 17:43:48'),
(29, 'aiCourses', '{\"title\":\"Secure Code & LLM Sec\",\"description\":\"Secure AI applications and use LLMs to identify vulnerabilities in complex codebases.\",\"icon\":\"code\",\"features\":[\"LLM Vulnerabilities\",\"Prompt Injection\",\"AI Code Review\"],\"duration\":\"10 Weeks\"}', 2, '2026-05-15 17:43:48'),
(30, 'aiCourses', '{\"title\":\"Autonomous Red Teaming\",\"description\":\"Deploy AI agents to continuously simulate advanced persistent threats (APTs) in enterprise networks.\",\"icon\":\"network\",\"features\":[\"AI Agents\",\"Continuous Testing\",\"Adversarial ML\"],\"duration\":\"14 Weeks\"}', 3, '2026-05-15 17:43:48'),
(31, 'batches', '{\"track\":\"Offensive Pentesting\",\"date\":\"May 15, 2024\",\"time\":\"07:00 PM IST\",\"mode\":\"Online\",\"status\":\"Filling Fast\"}', 0, '2026-05-15 17:43:48'),
(32, 'batches', '{\"track\":\"Bug Bounty Mastery\",\"date\":\"May 20, 2024\",\"time\":\"08:30 PM IST\",\"mode\":\"Online\",\"status\":\"Open\"}', 1, '2026-05-15 17:43:48'),
(33, 'batches', '{\"track\":\"Red Team Ops\",\"date\":\"June 01, 2024\",\"time\":\"10:00 AM IST\",\"mode\":\"Offline\",\"status\":\"Limited Seats\"}', 2, '2026-05-15 17:43:48'),
(34, 'reviews', '{\"name\":\"Priya Yogi\",\"role\":\"Android Developer Student\",\"content\":\"I attended Riddhi man workshop in my collage for android app development. I am glad that I am her student and the workshop was so much interesting. Thank you..!!\",\"rating\":5,\"platform\":\"Google\"}', 0, '2026-05-15 17:43:48'),
(35, 'reviews', '{\"name\":\"Mohammed Aman Behalim\",\"role\":\"Founder, Web Dev Company\",\"content\":\"Very nice trainer\'s they have i attended their workshop arranged in my college i Found the workshop so knowledgeable and specially tapan sir makes the knowledge more enjoyable than i joined there two year diploma and current i am running my own website development company\",\"rating\":5,\"platform\":\"Google\"}', 0, '2026-05-15 17:43:48'),
(36, 'reviews', '{\"name\":\"SHUBHAM JAIN\",\"role\":\"Digital Marketing Student\",\"content\":\"I attended Tapan Sir Workshop In My Collage for Digital Marketing I am glad that i am his student and the workshop was so much interesting and knowledgeable i advise all that once in life you should learn from them:-)....\",\"rating\":5,\"platform\":\"Google\"}', 0, '2026-05-15 17:43:48'),
(37, 'reviews', '{\"name\":\"Puujaa Rathorr\",\"role\":\"Student\",\"content\":\"I highly recommend you guys fir this academy, tapan sir is very excellent in teaching, i learned a lot from his classes and Still learning.\",\"rating\":5,\"platform\":\"Google\"}', 0, '2026-05-15 17:43:48'),
(38, 'reviews', '{\"name\":\"Mansur Ali\",\"role\":\"Alumni (Placed)\",\"content\":\"I was student of this academy and I am proud of myself that I choose them I am now placed in a well reputed company all thanks to my trainer of ASD ACADEMY\",\"rating\":5,\"platform\":\"Google\"}', 0, '2026-05-15 17:43:48');

-- --------------------------------------------------------

--
-- Table structure for table `masterclasses`
--

CREATE TABLE `masterclasses` (
  `id` int(11) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `online_fee` varchar(100) DEFAULT NULL,
  `offline_fee` varchar(100) DEFAULT NULL,
  `fee` varchar(100) DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `class_hours` varchar(100) DEFAULT NULL,
  `mode` varchar(100) DEFAULT NULL,
  `assessment` varchar(100) DEFAULT NULL,
  `internship` varchar(100) DEFAULT NULL,
  `total_duration` varchar(100) DEFAULT NULL,
  `one_time_discount` varchar(100) DEFAULT NULL,
  `installment_plans` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `what_is` text DEFAULT NULL,
  `what_we_teach` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`what_we_teach`)),
  `careers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`careers`)),
  `image_url` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `masterclasses`
--

INSERT INTO `masterclasses` (`id`, `slug`, `title`, `subtitle`, `online_fee`, `offline_fee`, `fee`, `duration`, `class_hours`, `mode`, `assessment`, `internship`, `total_duration`, `one_time_discount`, `installment_plans`, `description`, `what_is`, `what_we_teach`, `careers`, `image_url`, `status`, `created_at`) VALUES
(1, 'networking', 'Networking', 'Fundamentals of Computer Networks', NULL, '5,500', '5,500', '30 days', '1.5 Hours', 'Offline', '15 days', '1 Month Internship', '75 Days', '', '[object Object]', 'Understand the basics of computer networks and how devices communicate using wired and wireless technologies.', 'Networking course helps students to understand the basics of computer networks and how these devices communicate with each other using wired and wireless networks. This course is important from the industry point of view as without a network nothing is possible.', '[\"Introduction\",\"scope of CCNA\",\"growth\",\"and job\",\"Introduction of Network\",\"Basic introduction of Network devices\",\"basic tools\",\"Introduction of Router\",\"switch\",\"firewall\",\"and other network device\",\"Basic configuration of Router and switch\",\"OSI MODEL & their function\",\"Tcp and udp\",\"IP Address & Subnetting\",\"Routing and Their Protocols\",\"VLAN\",\"VTP\",\"Wireless Media & SSID Creation\",\"Firewall and Their Types\"]', '[\"System administrator\",\"Network Engineer\",\"Technical Support\",\"Network administrator\",\"IT administrator\"]', 'assets/skills/networking.png', 'active', '2026-05-15 18:05:50'),
(2, 'python', 'Python', 'AI & Automation Scripting', '2,100', NULL, '2,100', '3 days', '2 Hours', 'Online', '5 days', 'Tools (Deve)', '38 Days', '', '[object Object]', 'Master one of the most popular programming languages used in web development, AI, and security automation.', 'Python is a high-level programming language known for its simplicity and versatility. It is widely used in web development, data analysis, AI, and automation, making it an excellent choice for beginners and experts alike.', '[\"Basic Syntax & Data Types\",\"Control Flow ÔÇô Conditional statements\",\"loops\",\"and iteration\",\"Functions\",\"File Handling\",\"Lists\",\"Tuples\",\"and Sets\",\"Working with APIs in Python\",\"Python for Automation\",\"Cryptography in Python\",\"Testing and Debugging in Python\",\"Building GUI Applications in Python\",\"Cyber Security tools creation with using Python\"]', '[\"Python Developer\",\"Data Scientist\",\"Software Engineer\",\"Automation Tester / Scripting Expert\",\"Web Developer (Backend)\",\"Machine Learning Engineer\"]', 'assets/skills/python.png', 'active', '2026-05-15 18:05:50'),
(3, 'windows-server', 'Windows Server', 'Enterprise Infrastructure Management', '2,100', NULL, '2,100', '3 days', '2 Hours', 'Online', '10 days', 'no', '13 Days', '', '[object Object]', 'Manage and support enterprise-level IT infrastructure using Microsoft\'s powerful server operating system.', 'Windows Server is a powerful server operating system designed to manage enterprise-level IT infrastructure. It enables organizations to build and control networks where users and devices can access shared resources like files, applications, and databases.', '[\"Introduction to Window Server\",\"Print Server\",\"HTTP Server\",\"FTP Server\",\"DHCP Server\",\"Mail Server\",\"Data Management & Optimization\",\"SMD Server\",\"Active Directory Management\",\"DNS and DHCP Services\",\"Hyper-V Virtualization\",\"Security Features & Group Policies\"]', '[\"System Administrator\",\"Network Administrator\",\"Server Support Engineer\",\"IT Support Specialist\",\"Cloud Support Engineer\",\"Security Analyst (SOC)\"]', 'assets/skills/windows-server.png', 'active', '2026-05-15 18:05:50'),
(4, 'soc-splunk', 'SOC (SPLUNK)', 'Security Operations & SIEM', NULL, '10,500', '10,500', '20 days', '1.5 Hours', 'Offline', '15 days', '1 Month Internship', '65 Days', '', '[object Object]', 'Master the Security Operations Center workflows using Splunk for real-time threat detection and response.', 'A SOC is a centralized unit that monitors and manages an organizationÔÇÖs security posture. Splunk is a powerful tool used in SOCs for Security Information and Event Management (SIEM) to analyze machine-generated data.', '[\"Introduction to SOC & Cybersecurity\",\"Installing Splunk (Lab Setup)\",\"Forwarders & Data Onboarding\",\"Dashboards & Visualization\",\"Alert Monitoring & Triage (L1)\",\"IOC Lookups & Threat Intel\",\"MITRE ATT&CK for L1\",\"Ticketing & Reporting (L1)\"]', '[\"SOC Analyst (Level 1/2/3)\",\"SIEM Engineer\",\"Security Analyst\",\"Splunk Administrator\",\"Incident Responder\",\"Threat Hunter\"]', 'assets/skills/soc.png', 'active', '2026-05-15 18:05:50'),
(5, 'linux-fundamental', 'Linux Fundamental', 'The Foundation of Servers', '699', NULL, '699', '2 days', '2 Hours', 'Online', '5 days', 'no', '7 Days', '', '[object Object]', 'Learn the core concepts and essential command-line skills required to work with the Linux operating system.', 'Linux is an open-source, Unix-like system widely used for servers and desktops. Linux Fundamentals covers the basic concepts required to understand its architecture and command-line operations.', '[\"Getting Started with Linux\",\"User and Group Management\",\"File Permissions and Ownership\",\"Process and Job Management\",\"Linux Networking Basics\",\"Security Basics in Linux\",\"System Administration Basics\",\"Shell Scripting Introduction\"]', '[\"Linux System Administrator\",\"Technical Support Engineer\",\"Network Administrator (Linux)\",\"DevOps Engineer (Entry-Level)\",\"System Analyst\"]', 'assets/skills/linux.png', 'active', '2026-05-15 18:05:50'),
(6, 'ethical-hacking-v13', 'Ethical Hacking v13', 'AI-Powered Security', NULL, '10,500', '10,500', '30 days', '1.5 Hours', 'Offline', '30 days', '1 Month Internship', '90 Days', '', '[object Object]', 'Join the AI Revolution in cybersecurity with the latest CEH v13 techniques and AI-powered tools.', 'CEH v13 equips you with AI-powered tools to identify, exploit, and secure vulnerabilities. YouÔÇÖll leverage AI for automating threat detection and responding swiftly to cyber incidents.', '[\"Introduction to Ethical Hacking\",\"Footprinting and Reconnaissance\",\"Scanning Networks & Enumeration\",\"Vulnerability Analysis\",\"System Hacking & Malware Threats\",\"Sniffing & Social Engineering\",\"Denial-of-Service & Session Hijacking\",\"Evading IDS\",\"Firewalls\",\"and Honeypots\",\"Hacking Web Servers & Applications\",\"SQL Injection\",\"Hacking Wireless & Mobile Platforms\",\"IoT Hacking & Cloud Computing\",\"Cryptography\"]', '[\"Ethical Hacker / Penetration Tester\",\"Information Security Analyst\",\"Security Consultant\",\"Vulnerability Assessor\",\"Network Security Engineer\",\"SOC Analyst\"]', 'assets/skills/soc.png', 'active', '2026-05-15 18:05:50'),
(7, 'wapt', 'WAPT', 'Web App Penetration Testing', NULL, '9,000', '9,000', '30 days', '1.5 Hours', 'Offline', '15 days', '1 Month Internship', '75 Days', '', '[object Object]', 'Master the methodologies for identifying and exploiting vulnerabilities in web applications.', 'The WAPT Course provides a comprehensive understanding of web application security. It covers methodologies for identifying vulnerabilities using various tools and techniques.', '[\"Understanding Web Application Concepts\",\"Understanding Web Application Threats\",\"Web Application Hacking Methodology\",\"Web Application Hacking Tools\",\"Web Application Countermeasures\",\"Security Auditing Phases\",\"Risk Analysis and Management\"]', '[\"Penetration Tester\",\"Cybersecurity Consultant\",\"Security Analyst\",\"Security Auditor\",\"Vulnerability Researcher\"]', 'assets/skills/linux.png', 'active', '2026-05-15 18:05:50'),
(8, 'mobile-app-testing', 'Mobile App Testing', 'iOS & Android Security', NULL, '6,200', '6,200', '30 days', '1.5 Hours', 'Offline', '15 days', '1 Month Internship', '75 Days', '', '[object Object]', 'Learn to identify and fix security vulnerabilities in mobile apps using OWASP Top 10 and AI tools.', 'This course focuses on identifying and fixing security vulnerabilities in mobile applications using OWASP Mobile Top 10 and AI-powered automation tools.', '[\"Introduction to OWASP Mobile Top 10\",\"Improper Credential Usage\",\"Insecure Authentication/Authorization\",\"Insufficient Input/Output Validation\",\"Insecure Communication\",\"Inadequate Privacy Controls\",\"Insufficient Binary Protections\",\"Security Misconfiguration\",\"Insecure Data Storage & Cryptography\"]', '[\"Mobile App QA Engineer\",\"Mobile Application Security Tester\",\"Performance and Load Tester\",\"Automated Testing Expert\"]', 'assets/skills/windows-server.png', 'active', '2026-05-15 18:05:50'),
(9, 'api-testing', 'API Testing', 'Securing the Connectors', '3,500', NULL, '3,500', '10 days', '1.5 Hours', 'Online', '5 days', 'No', '15 Days', '', '[object Object]', 'Deep dive into securing RESTful APIs using AI-powered testing and monitoring tools.', 'Focus on designing, developing, and securing APIs while integrating AI technologies for smarter testing, monitoring, and optimization.', '[\"Introduction to API Testing\",\"OWASP API Security Top 10 ÔÇô 2023\",\"Detailed Risk Analysis\",\"API Security Testing and Assessment\",\"Best Practices and Mitigation Strategies\",\"RESTful APIs\",\"OAuth\",\"and JWT Security\",\"Automated API Testing with AI\"]', '[\"API Tester / QA Engineer\",\"Security Validation Expert\",\"Automation Engineer\",\"Performance Tester\"]', 'assets/skills/networking.png', 'active', '2026-05-15 18:05:50'),
(10, 'source-code-review', 'Source Code Review', 'Secure Coding & Auditing', '4,200', NULL, '4,200', '10 days', '1.5 Hours', 'Online', '10 days', 'no', '20 Days', '', '[object Object]', 'Line-by-line analysis of application code to identify security flaws and ensure secure coding practices.', 'Source Code Review is a detailed analysis of source code to identify security vulnerabilities and ensure compliance with secure coding practices like SQLi and XSS prevention.', '[\"Security Requirements Gathering\",\"Secure Application Design & Architecture\",\"Secure Coding for Input Validation\",\"Authentication and Authorization Security\",\"Session Management & Error Handling\",\"Static and Dynamic Analysis (SAST/DAST)\",\"Secure Deployment and Maintenance\"]', '[\"Source Code Reviewer\",\"Application Security Analyst\",\"Secure Code Auditor\",\"DevSecOps Engineer\",\"VAPT Specialist\"]', 'assets/skills/python.png', 'active', '2026-05-15 18:05:50'),
(11, 'sc-900', 'SC-900', 'Microsoft Security Fundamentals', '5,500', NULL, '5,500', '16 days', '1.5 Hours', 'Online', 'Not Required', 'No', '16 Days', '', '[object Object]', 'Get certified in the basics of Microsoft\'s security, compliance, and identity solutions.', 'The SC-900 course covers the basics of Microsoft\'s security solutions, including Entra ID, Defender, Purview, and Sentinel. It\'s ideal for beginners in the Microsoft ecosystem.', '[\"Introduction to SC-900\",\"Core Security & Compliance Concepts\",\"Microsoft Entra ID (Identity Management)\",\"Microsoft Defender (Threat Protection)\",\"Microsoft Purview (Information Protection)\",\"Zero Trust Security Principles\"]', '[\"Security Administrator\",\"Identity Management Specialist\",\"Compliance Officer\",\"IT Support Specialist\"]', 'assets/skills/windows-server.png', 'active', '2026-05-15 18:05:50'),
(12, 'iso-27001', 'ISO -27001', 'Global Compliance Standard', '6,200', NULL, '6,200', '11 days', '1.5 Hours', 'Online', '5 days', '1 month Internship', '46 Days', '', '[object Object]', 'Learn to implement and manage an Information Security Management System (ISMS) based on international standards.', 'ISO 27001 provides training on managing sensitive company data through a framework of security policies and risk assessments. It\'s ideal for managers and compliance officers.', '[\"Governance & Risk Management Controls\",\"Access Control & Identity Management\",\"Incident Response & Monitoring\",\"Legal & Compliance Controls\",\"Network & Application Security Controls\",\"ISMS Implementation Framework\"]', '[\"ISO 27001 Lead Auditor\",\"Information Security Manager\",\"Compliance Officer\",\"Risk Manager\",\"DPO (Data Protection Officer)\"]', 'assets/skills/soc.png', 'active', '2026-05-15 18:05:50');

-- --------------------------------------------------------

--
-- Table structure for table `mentors`
--

CREATE TABLE `mentors` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `role` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `mentors`
--

INSERT INTO `mentors` (`id`, `name`, `role`, `description`, `image_url`, `status`, `created_at`) VALUES
(1, 'TAPAN KUMAR JHA', 'CYBER SECURITY + VAPT TRAINER', '15+ Years Experienced Senior Penetration Tester | Cybersecurity Expert', 'asd-backend/uploads/mentors/1779427515_6a0fe8bbd0fef.jpg', 'active', '2026-05-15 17:43:48'),
(2, 'RIDDHI SORAL', 'SOURCE CODE REVIEW + CEH TRAINER', '15+ Years Experienced Senior Penetration Tester | Security Auditor', 'asd-backend/uploads/mentors/1779427535_6a0fe8cff3398.jpg', 'active', '2026-05-15 17:43:48'),
(3, 'PRANAV PARANJPE', 'SOC TRAINER', '21+ Years Experience in SOC (Security Operations Center) Operations', 'asd-backend/uploads/mentors/1779427559_6a0fe8e7dae89.jpg', 'active', '2026-05-15 17:43:48'),
(4, 'ASIF FAROOQ', 'PYTHON TRAINER', '5+ Years Experience in Python Programming & Security Scripting', 'asd-backend/uploads/mentors/1779427570_6a0fe8f246293.jpg', 'active', '2026-05-15 17:43:48'),
(5, 'ACHANTA MANIKRISHNA', 'WINDOWS SERVER TRAINER', '5+ Years Experience in Enterprise Windows Server Management', 'asd-backend/uploads/mentors/1779427579_6a0fe8fb1f253.jpg', 'active', '2026-05-15 17:43:48'),
(6, 'DEEPAK MISHRA', 'CCNA NETWORKING TRAINER', '5+ Years Experience in Enterprise Networking & Infrastructure', 'asd-backend/uploads/mentors/1779427607_6a0fe917ab0ca.jpg', 'active', '2026-05-15 17:43:48');

-- --------------------------------------------------------

--
-- Table structure for table `registrations`
--

CREATE TABLE `registrations` (
  `id` int(11) NOT NULL,
  `reg_id` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `city` varchar(100) NOT NULL,
  `course_name` varchar(100) DEFAULT NULL,
  `course_mode` varchar(50) DEFAULT 'online',
  `message` text DEFAULT NULL,
  `status` enum('new','contacted','enrolled') DEFAULT 'new',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `total_fee` varchar(255) DEFAULT '0',
  `paid_amount` varchar(255) DEFAULT '0',
  `remaining_amount` varchar(255) DEFAULT '0',
  `selected_plan` text DEFAULT NULL,
  `payment_status` enum('unpaid','partially_paid','fully_paid') DEFAULT 'unpaid'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `registrations`
--

INSERT INTO `registrations` (`id`, `reg_id`, `first_name`, `last_name`, `email`, `phone`, `city`, `course_name`, `course_mode`, `message`, `status`, `submitted_at`, `total_fee`, `paid_amount`, `remaining_amount`, `selected_plan`, `payment_status`) VALUES
(19, 'ASD-2026-6891', 'test ', 'user', 'aryangupta.gca@gmail.com', '6378811299', 'bundi', 'Linux Fundamental', 'online', 'testing', 'new', '2026-05-20 12:42:50', '5000', '5000', '699', NULL, 'fully_paid'),
(20, 'ASD-2026-6891', 'dixant', 'choudhary', '2024bcamafsdixant18107@poornima.edu.in', '6378811299', 'dae', 'SUPER 30', 'online', 'wda', 'new', '2026-05-20 12:45:42', '59000', '0', '59000', NULL, 'unpaid');

-- --------------------------------------------------------

--
-- Table structure for table `results_banner`
--

CREATE TABLE `results_banner` (
  `id` int(11) NOT NULL,
  `image_url` varchar(255) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `results_banner`
--

INSERT INTO `results_banner` (`id`, `image_url`, `status`, `created_at`) VALUES
(3, 'asd-backend/uploads/results/6a0810b1a28d3.png', 'active', '2026-05-15 10:37:48'),
(4, 'asd-backend/uploads/results/6a0810723a067.png', 'active', '2026-05-15 10:37:48');

-- --------------------------------------------------------

--
-- Table structure for table `results_content`
--

CREATE TABLE `results_content` (
  `id` int(11) NOT NULL,
  `section` varchar(50) NOT NULL,
  `title` varchar(255) DEFAULT '',
  `subtitle` varchar(255) DEFAULT '',
  `value_text` varchar(100) DEFAULT '',
  `image` varchar(500) DEFAULT NULL,
  `description` text DEFAULT '',
  `image_url` varchar(500) DEFAULT '',
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `results_content`
--

INSERT INTO `results_content` (`id`, `section`, `title`, `subtitle`, `value_text`, `image`, `description`, `image_url`, `sort_order`, `created_at`) VALUES
(1, 'elite_achievers', 'Our Elite Achievers', NULL, NULL, '/upload/placement/placement_banner.png', '', '', 1, '2026-05-17 05:09:50'),
(2, 'placements', 'Result Page 1', NULL, NULL, '/upload/placement/Result%20page1.png', '', '', 1, '2026-05-17 05:09:50'),
(3, 'placements', 'Result Page 2', NULL, NULL, '/upload/placement/Result%20page%202.png', '', '', 2, '2026-05-17 05:09:50'),
(4, 'placements', 'Result Page 3', NULL, NULL, '/upload/placement/Result%20page%203.png', '', '', 3, '2026-05-17 05:09:50'),
(5, 'internships', 'Internship Showcase', NULL, NULL, '/upload/internship/internship_banner.png', '', '', 1, '2026-05-17 05:09:50'),
(6, 'ctf_hunters', 'CTF Hunters Banner', NULL, NULL, '/upload/ctf%20hunter/CTF%20HUNTER.png', '', '', 1, '2026-05-17 05:09:50'),
(7, 'bug_bounty', 'Bug Bounty Top Hunters', NULL, NULL, '/upload/bug%20hunters/bug%20hunter.png', '', '', 1, '2026-05-17 05:09:50'),
(15, 'achievements', 'Hackathon Winners 2024', NULL, NULL, 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?auto=format&fit=crop&q=80&w=600&h=400', '', '', 1, '2026-05-17 05:09:50'),
(16, 'achievements', 'DEFCON Qualifiers', NULL, NULL, 'https://images.unsplash.com/photo-1515187029135-18ee286d815b?auto=format&fit=crop&q=80&w=600&h=400', '', '', 2, '2026-05-17 05:09:50'),
(17, 'achievements', 'Annual Security Summit', NULL, NULL, 'https://images.unsplash.com/photo-1523240795612-9a054b0db644?auto=format&fit=crop&q=80&w=600&h=400', '', '', 3, '2026-05-17 05:09:50'),
(23, 'scholarships', 'scholorship toppers', '', '', 'asd-backend/uploads/results/6a0d67659161d.png', '', '', 0, '2026-05-20 07:48:53'),
(24, 'hacker_exams', '', '', '', '', '', '', 0, '2026-05-21 11:09:31'),
(25, 'hacker_exams', '', '', '', 'asd-backend/uploads/results/6a0ee7f0e8342.png', '', '', 0, '2026-05-21 11:09:36');

-- --------------------------------------------------------

--
-- Table structure for table `scholarship_content`
--

CREATE TABLE `scholarship_content` (
  `id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `name` varchar(200) DEFAULT '',
  `image` varchar(500) DEFAULT '',
  `title` varchar(255) DEFAULT '',
  `content` text DEFAULT '',
  `question` varchar(500) DEFAULT '',
  `answer` text DEFAULT '',
  `order_index` int(11) DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `scholarship_content`
--

INSERT INTO `scholarship_content` (`id`, `type`, `name`, `image`, `title`, `content`, `question`, `answer`, `order_index`, `status`, `created_at`) VALUES
(16, 'main_story', 'Michael Chang', 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=400', '100% Scholarship Success', 'Michael now works at a top firm.', '', '', 0, 'active', '2026-05-15 10:37:48'),
(17, 'other_stories', 'Sarah Johnson', 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=400', 'Career Transformation', 'Focus entirely on studies.', '', '', 0, 'active', '2026-05-15 10:37:48'),
(21, 'faq', '', '', '', '', 'What is AICET?', 'AICET (ASD India Combined Entrance Test) is a nationwide scholarship test conducted by ASD Academy to identify and reward talented students interested in Cyber Security and Ethical Hacking. It offers up to 100% scholarship on our premium training programs.', 0, 'active', '2026-05-15 10:38:36'),
(22, 'faq', '', '', '', '', 'Who is eligible to appear for AICET 2026?', 'Any student from Class 6th to 12th, college students, and working professionals who want to build a career in Cyber Security are eligible to appear for the AICET exam.', 0, 'active', '2026-05-15 10:38:36'),
(23, 'faq', '', '', '', '', 'What is the syllabus for the AICET Test?', 'The syllabus includes Logical Reasoning, Basic Networking, Fundamental Computing, and General Aptitude. It is designed to test your logical thinking and technical inclination rather than deep coding knowledge.', 0, 'active', '2026-05-15 10:38:36'),
(24, 'faq', '', '', '', '', 'Is the AICET test available in both Hindi and English medium?', 'Yes, the AICET test is available in both English and Hindi mediums to ensure accessibility for students from various backgrounds.', 0, 'active', '2026-05-15 10:38:36'),
(25, 'faq', '', '', '', '', 'How will students receive important information and updates related to AICET?', 'All updates, including admit cards, test links, and results, will be shared via your registered email ID and mobile number (SMS/WhatsApp).', 0, 'active', '2026-05-15 10:38:36'),
(26, 'faq', '', '', '', '', 'What courses can a student get scholarships for through AICET?', 'Scholarships are applicable for our flagship courses including Ethical Hacking Mastery, Cyber Security Professional, Bug Bounty, and VAPT programs.', 0, 'active', '2026-05-15 10:38:36'),
(27, 'faq', '', '', '', '', 'Can I appear for AICET using a mobile phone or tablet?', 'Yes, AICET is a fully online test. You can appear for it using a laptop, desktop, tablet, or even a smartphone with a stable internet connection.', 0, 'active', '2026-05-15 10:38:36');

-- --------------------------------------------------------

--
-- Table structure for table `scholarship_hero`
--

CREATE TABLE `scholarship_hero` (
  `id` int(11) NOT NULL,
  `image_url` varchar(500) DEFAULT '',
  `title` varchar(255) DEFAULT '',
  `subtitle` text DEFAULT '',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scholarship_registrations`
--

CREATE TABLE `scholarship_registrations` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) DEFAULT '',
  `last_name` varchar(100) DEFAULT '',
  `email` varchar(200) DEFAULT '',
  `phone` varchar(20) DEFAULT '',
  `city` varchar(100) DEFAULT '',
  `message` text DEFAULT '',
  `status` varchar(50) DEFAULT 'new',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `skill_courses`
--

CREATE TABLE `skill_courses` (
  `id` int(11) NOT NULL,
  `course_id` varchar(50) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `what_is` text DEFAULT NULL,
  `what_we_teach` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`what_we_teach`)),
  `careers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`careers`)),
  `image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `category` varchar(100) DEFAULT 'General'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `skill_courses`
--

INSERT INTO `skill_courses` (`id`, `course_id`, `title`, `subtitle`, `description`, `what_is`, `what_we_teach`, `careers`, `image`, `created_at`, `category`) VALUES
(13, 'networking', 'Networking Fundamental', 'The Backbone of Cyber Security', 'Master OSI model, TCP/IP, subnetting, and network protocols essential for any cyber security professional.', 'Networking is the foundation of all digital communication.', '[\"OSI Model\",\"TCP\\/IP Stack\",\"Subnetting & VLANs\",\"Routing & Switching\"]', '[\"Network Security Engineer\",\"Systems Administrator\"]', '/assets/skills/networking.png', '2026-05-15 10:37:48', 'Infrastructure'),
(14, 'python', 'Python for Security', 'Automation & Tool Development', 'Learn to build your own security tools, scanners, and automation scripts using Python.', 'Python is the Swiss Army knife for security researchers.', '[\"Socket Programming\",\"Scapy for Packets\",\"Automated Scanning\",\"Exploit Development\"]', '[\"Security Tool Developer\",\"Automation Engineer\"]', '/assets/skills/python.png', '2026-05-15 10:37:48', 'Programming'),
(15, 'ethical-hacking-v13', 'Ethical Hacking v13', 'Certified Ethical Hacker Prep', 'Latest techniques in network scanning, exploitation, and post-exploitation based on CEH v13.', 'Ethical hacking is the legal practice of breaking into systems.', '[\"Vulnerability Analysis\",\"Network Exploitation\",\"Post-Exploitation\",\"Hacking Methodologies\"]', '[\"Penetration Tester\",\"Ethical Hacker\"]', '/assets/skills/ethical-hacking.png', '2026-05-15 10:37:48', 'Security'),
(16, 'windows-server', 'Windows Server Security', 'Enterprise Infrastructure Defense', 'Secure Active Directory, Group Policies, and enterprise Windows environments against modern attacks.', 'Windows Server is the heart of most corporate networks.', '[\"Active Directory Security\",\"Group Policy Objects\",\"PowerShell Security\",\"Server Hardening\"]', '[\"Windows Administrator\",\"Security Analyst\"]', '/assets/skills/windows-server.png', '2026-05-15 11:14:03', 'Infrastructure'),
(17, 'soc-splunk', 'SOC (Splunk/SIEM)', 'Blue Team Operations', 'Master incident response, log analysis, and threat hunting using Splunk and SIEM tools.', 'SOC is the front line of enterprise defense.', '[\"Log Analysis\",\"Splunk Dashboarding\",\"Incident Response\",\"Threat Hunting\"]', '[\"SOC Analyst\",\"Incident Responder\"]', '/assets/skills/soc.png', '2026-05-15 11:14:03', 'Operations'),
(18, 'linux-fundamental', 'Linux Fundamental', 'The Hacker\'s Operating System', 'Command line mastery, file permissions, and system administration for security professionals.', 'Most security tools and servers run on Linux.', '[\"Bash Scripting\",\"System Permissions\",\"SSH & Remote Access\",\"Package Management\"]', '[\"Linux Administrator\",\"Security Researcher\"]', '/assets/skills/linux.png', '2026-05-15 11:14:03', 'Infrastructure'),
(19, 'wapt', 'WAPT (Web App Pentesting)', 'OWASP Top 10 & Beyond', 'Find and exploit vulnerabilities in web applications like SQLi, XSS, and IDOR.', 'Web applications are the most common attack surface.', '[\"SQL Injection\",\"Cross-Site Scripting (XSS)\",\"Broken Authentication\",\"API Security\"]', '[\"Web Pentester\",\"AppSec Engineer\"]', '/assets/skills/wapt.png', '2026-05-15 11:14:03', 'Application'),
(20, 'mobile-app-testing', 'Mobile App Security', 'iOS & Android Pentesting', 'Static and dynamic analysis of mobile applications to find security flaws.', 'Mobile apps hold sensitive personal data.', '[\"Android Debug Bridge (ADB)\",\"iOS Static Analysis\",\"Frida Hooking\",\"Data Storage Security\"]', '[\"Mobile Security Analyst\",\"App Pentester\"]', '/assets/skills/mobile-testing.png', '2026-05-15 11:14:03', 'Application'),
(21, 'api-testing', 'API Security Testing', 'Securing the Microservices', 'Test REST/GraphQL APIs for authentication bypass, rate limiting, and business logic flaws.', 'APIs are the backbone of modern web and mobile apps.', '[\"REST API Security\",\"GraphQL Injection\",\"JWT Vulnerabilities\",\"BOLA\\/IDOR\"]', '[\"API Security Specialist\",\"DevSecOps\"]', '/assets/skills/api-testing.png', '2026-05-15 11:14:03', 'Application'),
(22, 'source-code-review', 'Source Code Review', 'Secure Coding & SAST', 'Manually find vulnerabilities in application source code before they are deployed.', 'Fixing bugs at the source is the most efficient security.', '[\"Static Analysis\",\"Secure Coding Patterns\",\"Code Auditing\",\"Vulnerability Remediation\"]', '[\"Code Auditor\",\"AppSec Researcher\"]', '/assets/skills/ethical-hacking.png', '2026-05-15 11:14:03', 'Application'),
(23, 'sc-900', 'Microsoft SC-900', 'Security, Compliance & Identity', 'Preparation for Microsoft Certified: Security, Compliance, and Identity Fundamentals.', 'Cloud security starts with identity and compliance.', '[\"Azure Security\",\"M365 Compliance\",\"Identity Management\",\"Sentinel Basics\"]', '[\"Cloud Security Associate\",\"Azure Administrator\"]', '/assets/skills/sc-900.png', '2026-05-15 11:14:03', 'Cloud'),
(24, 'iso-27001', 'ISO 27001 LI/LA', 'Information Security Management', 'Learn to implement and audit Information Security Management Systems (ISMS).', 'Compliance is mandatory for enterprise security.', '[\"Risk Management\",\"ISMS Framework\",\"Audit Methodologies\",\"Compliance Controls\"]', '[\"ISMS Lead Auditor\",\"Compliance Manager\"]', '/assets/skills/iso-27001.png', '2026-05-15 11:14:03', 'Management');

-- --------------------------------------------------------

--
-- Table structure for table `testimonials`
--

CREATE TABLE `testimonials` (
  `id` int(11) NOT NULL,
  `image_url` varchar(255) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `testimonials`
--

INSERT INTO `testimonials` (`id`, `image_url`, `status`, `created_at`) VALUES
(2, '/uploads/testimonials/6a044204dcbad.jpg', 'active', '2026-05-15 17:44:20'),
(4, '/uploads/testimonials/6a0442711d956.jpg', 'active', '2026-05-15 17:44:20'),
(5, '/uploads/testimonials/6a04427a775e7.jpg', 'active', '2026-05-15 17:44:20'),
(6, '/uploads/testimonials/6a044284e4a7f.jpg', 'active', '2026-05-15 17:44:20'),
(7, '/uploads/testimonials/6a04428c050e1.jpg', 'active', '2026-05-15 17:44:20');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `order_id` varchar(50) NOT NULL,
  `tracking_id` varchar(100) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Pending',
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `course_name` varchar(255) DEFAULT NULL,
  `payment_plan` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `event_type` varchar(100) DEFAULT 'Order Status',
  `risk_status` varchar(50) DEFAULT NULL,
  `risk_reason` text DEFAULT NULL,
  `payment_option` varchar(100) DEFAULT NULL,
  `current_gateway_status` varchar(50) DEFAULT NULL,
  `card_name` varchar(100) DEFAULT NULL,
  `token_ref_number` varchar(100) DEFAULT NULL,
  `masked_card_number` varchar(50) DEFAULT NULL,
  `si_status` varchar(50) DEFAULT NULL,
  `si_sub_ref_no` varchar(100) DEFAULT NULL,
  `raw_response` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `order_id`, `tracking_id`, `amount`, `status`, `customer_name`, `customer_email`, `customer_phone`, `course_name`, `payment_plan`, `created_at`, `event_type`, `risk_status`, `risk_reason`, `payment_option`, `current_gateway_status`, `card_name`, `token_ref_number`, `masked_card_number`, `si_status`, `si_sub_ref_no`, `raw_response`) VALUES
(1, 'ORD17792997371510', NULL, 59000.00, 'Pending', NULL, 'dixantchoudhary05@gmail.com', NULL, NULL, NULL, '2026-05-20 23:25:37', 'Order Status', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `verification_codes`
--

CREATE TABLE `verification_codes` (
  `id` int(11) NOT NULL,
  `identifier` varchar(100) NOT NULL,
  `code` varchar(10) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `verification_codes`
--

INSERT INTO `verification_codes` (`id`, `identifier`, `code`, `created_at`) VALUES
(5, 'vermasaksham.06@gmail.cm', '762547', '2026-05-14 17:12:31'),
(8, '8427750981', '836581', '2026-05-14 17:14:49'),
(9, 'vermasaksham.06@gmail.co', '423996', '2026-05-14 17:37:44'),
(11, 'vermasaksham.06@gmai.com', '439185', '2026-05-14 17:41:33'),
(12, 'vermasaksham.06@gmil.com', '648949', '2026-05-14 17:46:18'),
(13, 'vermasaksham.06@gml.com', '873097', '2026-05-14 17:48:24'),
(14, 'vermasaksham.06@gail.com', '631139', '2026-05-14 17:49:01'),
(15, 'vermasaksham.06@gil.com', '629414', '2026-05-14 17:53:16'),
(36, 'vermasaksham.06@mail.com', '818886', '2026-05-15 04:43:40');

-- --------------------------------------------------------

--
-- Table structure for table `youtube_testimonials`
--

CREATE TABLE `youtube_testimonials` (
  `id` int(11) NOT NULL,
  `video_url` varchar(255) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `youtube_testimonials`
--

INSERT INTO `youtube_testimonials` (`id`, `video_url`, `status`, `created_at`) VALUES
(1, 'https://www.youtube.com/shorts/h7bosqHNLC4', 'active', '2026-05-16 07:07:06'),
(2, 'https://www.youtube.com/shorts/t1EmnDzJ8_o', 'active', '2026-05-16 07:07:06'),
(3, 'https://www.youtube.com/shorts/LhA5jp-5MGo', 'active', '2026-05-16 07:07:06'),
(4, 'https://www.youtube.com/shorts/jrWZG3Wx1Xw', 'active', '2026-05-16 07:07:06'),
(5, 'https://www.youtube.com/shorts/yXigB9QmHpk', 'active', '2026-05-16 07:07:06');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `banners`
--
ALTER TABLE `banners`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `careers`
--
ALTER TABLE `careers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `coupons`
--
ALTER TABLE `coupons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `course_contents`
--
ALTER TABLE `course_contents`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `flagship_programs`
--
ALTER TABLE `flagship_programs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `grow_with_study_content`
--
ALTER TABLE `grow_with_study_content`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `home_content`
--
ALTER TABLE `home_content`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `masterclasses`
--
ALTER TABLE `masterclasses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mentors`
--
ALTER TABLE `mentors`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `registrations`
--
ALTER TABLE `registrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `results_banner`
--
ALTER TABLE `results_banner`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `results_content`
--
ALTER TABLE `results_content`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `scholarship_content`
--
ALTER TABLE `scholarship_content`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `scholarship_hero`
--
ALTER TABLE `scholarship_hero`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `scholarship_registrations`
--
ALTER TABLE `scholarship_registrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `skill_courses`
--
ALTER TABLE `skill_courses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `course_id` (`course_id`);

--
-- Indexes for table `testimonials`
--
ALTER TABLE `testimonials`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_id` (`order_id`);

--
-- Indexes for table `verification_codes`
--
ALTER TABLE `verification_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `identifier` (`identifier`);

--
-- Indexes for table `youtube_testimonials`
--
ALTER TABLE `youtube_testimonials`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `banners`
--
ALTER TABLE `banners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `careers`
--
ALTER TABLE `careers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `coupons`
--
ALTER TABLE `coupons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `course_contents`
--
ALTER TABLE `course_contents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `flagship_programs`
--
ALTER TABLE `flagship_programs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `grow_with_study_content`
--
ALTER TABLE `grow_with_study_content`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `home_content`
--
ALTER TABLE `home_content`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `masterclasses`
--
ALTER TABLE `masterclasses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `mentors`
--
ALTER TABLE `mentors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `registrations`
--
ALTER TABLE `registrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `results_banner`
--
ALTER TABLE `results_banner`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `results_content`
--
ALTER TABLE `results_content`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `scholarship_content`
--
ALTER TABLE `scholarship_content`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `scholarship_hero`
--
ALTER TABLE `scholarship_hero`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `scholarship_registrations`
--
ALTER TABLE `scholarship_registrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `skill_courses`
--
ALTER TABLE `skill_courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `testimonials`
--
ALTER TABLE `testimonials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `youtube_testimonials`
--
ALTER TABLE `youtube_testimonials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;
--
-- Database: `form_1`
--
CREATE DATABASE IF NOT EXISTS `form_1` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `form_1`;

-- --------------------------------------------------------

--
-- Table structure for table `form_table_1`
--

CREATE TABLE `form_table_1` (
  `UID` int(5) NOT NULL,
  `Name` text NOT NULL,
  `Email` varchar(25) NOT NULL,
  `Password` varchar(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `form_table_1`
--

INSERT INTO `form_table_1` (`UID`, `Name`, `Email`, `Password`) VALUES
(1, 'Aryan', 'aryangtp@gmail.com', 'eggchan45');
--
-- Database: `lms`
--
CREATE DATABASE IF NOT EXISTS `lms` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `lms`;
--
-- Database: `lms_db`
--
CREATE DATABASE IF NOT EXISTS `lms_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `lms_db`;

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `due_date` datetime DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignments`
--

INSERT INTO `assignments` (`id`, `course_id`, `title`, `description`, `due_date`, `file_path`, `created_at`) VALUES
(1, 1, 'complete these questions', 'don\'t cheat', '2026-05-09 10:33:00', NULL, '2026-05-08 05:03:11'),
(2, 2, 'comeplete this', '..', '2026-05-15 17:22:00', NULL, '2026-05-08 08:52:07');

-- --------------------------------------------------------

--
-- Table structure for table `assignment_submissions`
--

CREATE TABLE `assignment_submissions` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `submission_file` varchar(255) NOT NULL,
  `marks` int(11) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `status` enum('pending','graded') DEFAULT 'pending',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignment_submissions`
--

INSERT INTO `assignment_submissions` (`id`, `assignment_id`, `user_id`, `submission_file`, `marks`, `feedback`, `status`, `submitted_at`) VALUES
(1, 1, 2, 'sub_1778216618_2.png', 90, 'good', 'graded', '2026-05-08 05:03:38'),
(2, 2, 8, 'sub_1778230346_8.pdf', 233, '3322', 'graded', '2026-05-08 08:52:26');

-- --------------------------------------------------------

--
-- Table structure for table `batches`
--

CREATE TABLE `batches` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `trainer_id` int(11) DEFAULT NULL,
  `schedule` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `batches`
--

INSERT INTO `batches` (`id`, `name`, `description`, `start_date`, `end_date`, `created_at`, `trainer_id`, `schedule`) VALUES
(3, 'Weekend Warrior Group', NULL, '2024-07-01', '2024-10-01', '2026-05-08 04:47:02', NULL, 'Mon, Wed, Fri: 10AM - 12PM'),
(4, 'Fast-Track Boot Camp', NULL, '2024-07-01', '2024-10-01', '2026-05-08 04:47:02', NULL, 'Mon, Wed, Fri: 10AM - 12PM'),
(5, 'Corporate Training Batch B1', NULL, '2024-07-01', '2024-10-01', '2026-05-08 04:47:02', NULL, 'Mon, Wed, Fri: 10AM - 12PM'),
(6, 'Morning Elite Series', NULL, '2024-07-01', '2024-10-01', '2026-05-08 04:47:02', NULL, 'Mon, Wed, Fri: 10AM - 12PM'),
(7, 'International Students Cohort', NULL, '2024-07-01', '2024-10-01', '2026-05-08 04:47:02', NULL, 'Mon, Wed, Fri: 10AM - 12PM'),
(8, 'Late Night Coding Group', NULL, '2024-07-01', '2024-10-01', '2026-05-08 04:47:02', NULL, 'Mon, Wed, Fri: 10AM - 12PM'),
(9, 'Advanced Skills Batch', NULL, '2024-07-01', '2024-10-01', '2026-05-08 04:47:02', NULL, 'Mon, Wed, Fri: 10AM - 12PM'),
(10, 'Newbie Friendly Intake', NULL, '2024-07-01', '2024-10-01', '2026-05-08 04:47:02', NULL, 'Mon, Wed, Fri: 10AM - 12PM');

-- --------------------------------------------------------

--
-- Table structure for table `batch_students`
--

CREATE TABLE `batch_students` (
  `id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `instructor_name` varchar(100) DEFAULT 'Admin',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `duration` varchar(50) DEFAULT NULL,
  `level` enum('beginner','intermediate','advanced') DEFAULT 'beginner',
  `price` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`id`, `title`, `description`, `thumbnail`, `instructor_name`, `created_at`, `duration`, `level`, `price`) VALUES
(1, 'Full Stack Web Development', 'Deep dive into Full Stack Web Development. Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 140.00),
(2, 'Mobile App Development with Flutter', 'Deep dive into Mobile App Development with Flutter. Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 127.99),
(3, 'Data Science & Machine Learning', 'Deep dive into Data Science & Machine Learning. Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 31.99),
(4, 'Digital Marketing Masterclass', 'Deep dive into Digital Marketing Masterclass. Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 134.99),
(5, 'Cyber Security Essentials', 'Deep dive into Cyber Security Essentials. Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 115.99),
(6, 'Cloud Computing with AWS', 'Deep dive into Cloud Computing with AWS. Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 165.99),
(7, 'Artificial Intelligence Foundations', 'Deep dive into Artificial Intelligence Foundations. Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 184.99),
(8, 'Graphic Design with Adobe Suite', 'Deep dive into Graphic Design with Adobe Suite. Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 68.99),
(9, 'Project Management (PMP)', 'Deep dive into Project Management (PMP). Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 51.99),
(10, 'Python for Automation', 'Deep dive into Python for Automation. Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 123.99),
(11, 'Java Enterprise Edition', 'Deep dive into Java Enterprise Edition. Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 62.99),
(12, 'Blockchain Technology', 'Deep dive into Blockchain Technology. Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 143.99),
(13, 'Game Development with Unity', 'Deep dive into Game Development with Unity. Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 35.99),
(14, 'Ethical Hacking 101', 'Deep dive into Ethical Hacking 101. Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 38.99),
(15, 'DevOps Engineering', 'Deep dive into DevOps Engineering. Master the skills required for industry success in 2024.', NULL, 'Expert Instructor', '2026-05-08 04:47:02', NULL, 'beginner', 112.99);

-- --------------------------------------------------------

--
-- Table structure for table `course_materials`
--

CREATE TABLE `course_materials` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` enum('pdf','video','notes') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `course_progress`
--

CREATE TABLE `course_progress` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `completed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enrollments`
--

CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `progress` int(11) DEFAULT 0,
  `status` enum('active','completed') DEFAULT 'active',
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enrollments`
--

INSERT INTO `enrollments` (`id`, `user_id`, `course_id`, `progress`, `status`, `enrolled_at`) VALUES
(2, 2, 1, 0, 'active', '2026-05-08 04:45:02'),
(4, 8, 7, 0, 'active', '2026-05-08 07:02:56'),
(5, 8, 2, 0, 'active', '2026-05-08 08:47:41'),
(7, 8, 4, 0, 'active', '2026-05-08 08:50:52'),
(8, 8, 1, 0, 'active', '2026-05-08 10:27:31');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `batch_id`, `title`, `message`, `created_at`) VALUES
(1, NULL, 'Welcome to the New Semester!', 'We are excited to have you back. Please check your course schedules.', '2026-05-08 04:15:09'),
(3, NULL, 'Multiple new courses added', 'check them out and enroll', '2026-05-08 05:08:27'),
(5, NULL, 'new course introduced', 'xyz', '2026-05-08 10:29:33');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `stripe_session_id` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('pending','completed','failed') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `user_id`, `course_id`, `stripe_session_id`, `amount`, `status`, `created_at`) VALUES
(1, 2, 1, 'cs_test_a1J4TXwMFO65Mm5FZJuswf9ZUChuVOmGSRRWxIiWqzU1Jra7jm4tYeH83v', 0.00, 'completed', '2026-05-08 04:44:49'),
(2, 8, 7, 'cs_test_a1a1KsJGSDvraakPfWgK5sTdtawHqWyLbUjHj1EirOthxxPLIOUXbSSHFd', 184.99, 'completed', '2026-05-08 07:01:00'),
(3, 8, 2, 'cs_test_a1nVfprt2FCnlyuFnIdQoGBl6ovIholCxNVdCEEMORxwtx6T5tePL7lRkM', 127.99, 'completed', '2026-05-08 08:47:16'),
(4, 8, 4, 'cs_test_a1GRICrsaj2kVjWLIwKLaD2FOI3VmupFwmfZfZXL4gLns6LV9EdUglRVWB', 134.99, 'completed', '2026-05-08 08:50:38'),
(5, 8, 1, 'cs_test_a1DJ7IHvZHUyUG6KcMgIsz2zxfyQbB9Eq1I66DWDTQ4l71nf4dWLVEKLDt', 137.99, 'pending', '2026-05-08 09:40:47'),
(6, 8, 1, 'cs_test_a1rrqqNQE0h0rNSKYLsYycaZUg3hdssZCVqDkPOBoVioqtOMFWiFP5tHyc', 140.00, 'completed', '2026-05-08 10:27:03');

-- --------------------------------------------------------

--
-- Table structure for table `quizzes`
--

CREATE TABLE `quizzes` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `time_limit` int(11) DEFAULT NULL COMMENT 'in minutes',
  `total_marks` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `batch_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quizzes`
--

INSERT INTO `quizzes` (`id`, `course_id`, `title`, `description`, `time_limit`, `total_marks`, `created_at`, `batch_id`) VALUES
(1, 1, 'React Basics Quiz', 'Test your knowledge of components and props.', 10, 3, '2026-05-08 04:15:09', NULL),
(4, 2, 'flutter quiz', '', 30, 1, '2026-05-08 09:48:16', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `quiz_questions`
--

CREATE TABLE `quiz_questions` (
  `id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `question` text NOT NULL,
  `option_a` varchar(255) NOT NULL,
  `option_b` varchar(255) NOT NULL,
  `option_c` varchar(255) NOT NULL,
  `option_d` varchar(255) NOT NULL,
  `correct_option` char(1) NOT NULL,
  `marks` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quiz_questions`
--

INSERT INTO `quiz_questions` (`id`, `quiz_id`, `question`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `marks`) VALUES
(1, 1, 'What is the hook for managing state?', 'useState', 'useEffect', 'useContext', 'useReducer', 'A', 1),
(2, 1, 'JSX stands for?', 'JavaScript XML', 'Java syntax', 'JSON X', 'None', 'A', 1),
(3, 1, 'aasas', 'ssa', 'ss', 'sss', 'ss', 'A', 1),
(4, 4, 'saas', 's', 's', 's', 's', 'A', 1);

-- --------------------------------------------------------

--
-- Table structure for table `quiz_results`
--

CREATE TABLE `quiz_results` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `score` int(11) NOT NULL,
  `total_marks` int(11) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quiz_results`
--

INSERT INTO `quiz_results` (`id`, `user_id`, `quiz_id`, `score`, `total_marks`, `attempted_at`) VALUES
(1, 2, 1, 2, 2, '2026-05-08 04:53:34'),
(2, 8, 4, 0, 1, '2026-05-08 09:51:54'),
(3, 8, 4, 0, 1, '2026-05-08 09:52:03');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','student','trainer') NOT NULL DEFAULT 'student',
  `profile_pic` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `profile_pic`, `created_at`) VALUES
(1, 'Admin User', 'admin@lms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', NULL, '2026-05-07 18:18:48'),
(2, 'John Student', 'student@lms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', NULL, '2026-05-07 18:18:48'),
(6, 'Dr. Sarah Connor', 'sarah@lms.com', '$2y$10$L4MPojWvr8WQVDWy7sww3uAy9BaocSuFhlPZAIYISvfctlZnVmqAW', 'trainer', NULL, '2026-05-08 04:15:09'),
(7, 'Prof. James Smith', 'james@lms.com', '$2y$10$9tLt0kXWvVvsqQLVJKLGvOq7z0O6pY6.bVvkoA5IOOAHZGKTEc5ke', 'trainer', NULL, '2026-05-08 04:15:09'),
(8, 'Aryan Gupta', '2024bcamafsaryan17186@poornima.edu.in', '$2y$10$IpeUkG8Y35PDhZqO6AFccODqxQku4SE5nMDto/biQV/64WoFJN1Yu', 'student', NULL, '2026-05-08 06:57:57'),
(9, 'bhvya choudhary', 'bhvya@gmail.com', '$2y$10$0VCHFCNjKoECOOCmt3yoAe98YQOYFiLO6qnvdHPpamvzmBYM/0YZ6', 'student', NULL, '2026-05-08 10:01:29');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `batches`
--
ALTER TABLE `batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_trainer` (`trainer_id`);

--
-- Indexes for table `batch_students`
--
ALTER TABLE `batch_students`
  ADD PRIMARY KEY (`id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `course_materials`
--
ALTER TABLE `course_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `course_progress`
--
ALTER TABLE `course_progress`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_progress` (`user_id`,`material_id`),
  ADD KEY `material_id` (`material_id`);

--
-- Indexes for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_course` (`user_id`,`course_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `batch_id` (`batch_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `fk_quiz_batch` (`batch_id`);

--
-- Indexes for table `quiz_questions`
--
ALTER TABLE `quiz_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `quiz_id` (`quiz_id`);

--
-- Indexes for table `quiz_results`
--
ALTER TABLE `quiz_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `quiz_id` (`quiz_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `batches`
--
ALTER TABLE `batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `batch_students`
--
ALTER TABLE `batch_students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `course_materials`
--
ALTER TABLE `course_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `course_progress`
--
ALTER TABLE `course_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `enrollments`
--
ALTER TABLE `enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `quizzes`
--
ALTER TABLE `quizzes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `quiz_questions`
--
ALTER TABLE `quiz_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `quiz_results`
--
ALTER TABLE `quiz_results`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  ADD CONSTRAINT `assignment_submissions_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assignment_submissions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `batches`
--
ALTER TABLE `batches`
  ADD CONSTRAINT `fk_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `batch_students`
--
ALTER TABLE `batch_students`
  ADD CONSTRAINT `batch_students_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `batch_students_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `course_materials`
--
ALTER TABLE `course_materials`
  ADD CONSTRAINT `course_materials_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `course_progress`
--
ALTER TABLE `course_progress`
  ADD CONSTRAINT `course_progress_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `course_progress_ibfk_2` FOREIGN KEY (`material_id`) REFERENCES `course_materials` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `enrollments_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD CONSTRAINT `fk_quiz_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `quizzes_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quiz_questions`
--
ALTER TABLE `quiz_questions`
  ADD CONSTRAINT `quiz_questions_ibfk_1` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quiz_results`
--
ALTER TABLE `quiz_results`
  ADD CONSTRAINT `quiz_results_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `quiz_results_ibfk_2` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE;
--
-- Database: `phpmyadmin`
--
CREATE DATABASE IF NOT EXISTS `phpmyadmin` DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;
USE `phpmyadmin`;

-- --------------------------------------------------------

--
-- Table structure for table `pma__bookmark`
--

CREATE TABLE `pma__bookmark` (
  `id` int(10) UNSIGNED NOT NULL,
  `dbase` varchar(255) NOT NULL DEFAULT '',
  `user` varchar(255) NOT NULL DEFAULT '',
  `label` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `query` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Bookmarks';

-- --------------------------------------------------------

--
-- Table structure for table `pma__central_columns`
--

CREATE TABLE `pma__central_columns` (
  `db_name` varchar(64) NOT NULL,
  `col_name` varchar(64) NOT NULL,
  `col_type` varchar(64) NOT NULL,
  `col_length` text DEFAULT NULL,
  `col_collation` varchar(64) NOT NULL,
  `col_isNull` tinyint(1) NOT NULL,
  `col_extra` varchar(255) DEFAULT '',
  `col_default` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Central list of columns';

-- --------------------------------------------------------

--
-- Table structure for table `pma__column_info`
--

CREATE TABLE `pma__column_info` (
  `id` int(5) UNSIGNED NOT NULL,
  `db_name` varchar(64) NOT NULL DEFAULT '',
  `table_name` varchar(64) NOT NULL DEFAULT '',
  `column_name` varchar(64) NOT NULL DEFAULT '',
  `comment` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `mimetype` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `transformation` varchar(255) NOT NULL DEFAULT '',
  `transformation_options` varchar(255) NOT NULL DEFAULT '',
  `input_transformation` varchar(255) NOT NULL DEFAULT '',
  `input_transformation_options` varchar(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Column information for phpMyAdmin';

-- --------------------------------------------------------

--
-- Table structure for table `pma__designer_settings`
--

CREATE TABLE `pma__designer_settings` (
  `username` varchar(64) NOT NULL,
  `settings_data` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Settings related to Designer';

-- --------------------------------------------------------

--
-- Table structure for table `pma__export_templates`
--

CREATE TABLE `pma__export_templates` (
  `id` int(5) UNSIGNED NOT NULL,
  `username` varchar(64) NOT NULL,
  `export_type` varchar(10) NOT NULL,
  `template_name` varchar(64) NOT NULL,
  `template_data` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Saved export templates';

-- --------------------------------------------------------

--
-- Table structure for table `pma__favorite`
--

CREATE TABLE `pma__favorite` (
  `username` varchar(64) NOT NULL,
  `tables` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Favorite tables';

-- --------------------------------------------------------

--
-- Table structure for table `pma__history`
--

CREATE TABLE `pma__history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(64) NOT NULL DEFAULT '',
  `db` varchar(64) NOT NULL DEFAULT '',
  `table` varchar(64) NOT NULL DEFAULT '',
  `timevalue` timestamp NOT NULL DEFAULT current_timestamp(),
  `sqlquery` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='SQL history for phpMyAdmin';

-- --------------------------------------------------------

--
-- Table structure for table `pma__navigationhiding`
--

CREATE TABLE `pma__navigationhiding` (
  `username` varchar(64) NOT NULL,
  `item_name` varchar(64) NOT NULL,
  `item_type` varchar(64) NOT NULL,
  `db_name` varchar(64) NOT NULL,
  `table_name` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Hidden items of navigation tree';

--
-- Dumping data for table `pma__navigationhiding`
--

INSERT INTO `pma__navigationhiding` (`username`, `item_name`, `item_type`, `db_name`, `table_name`) VALUES
('root', 'home_content', 'table', 'asd_academy', '');

-- --------------------------------------------------------

--
-- Table structure for table `pma__pdf_pages`
--

CREATE TABLE `pma__pdf_pages` (
  `db_name` varchar(64) NOT NULL DEFAULT '',
  `page_nr` int(10) UNSIGNED NOT NULL,
  `page_descr` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='PDF relation pages for phpMyAdmin';

-- --------------------------------------------------------

--
-- Table structure for table `pma__recent`
--

CREATE TABLE `pma__recent` (
  `username` varchar(64) NOT NULL,
  `tables` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Recently accessed tables';

--
-- Dumping data for table `pma__recent`
--

INSERT INTO `pma__recent` (`username`, `tables`) VALUES
('root', '[{\"db\":\"u621399201_koral\",\"table\":\"students\"},{\"db\":\"u621399201_koral\",\"table\":\"doubts\"},{\"db\":\"u621399201_koral\",\"table\":\"doubt_responses\"},{\"db\":\"u621399201_koral\",\"table\":\"courses\"},{\"db\":\"performance_schema\",\"table\":\"events_stages_summary_by_thread_by_event_name\"},{\"db\":\"asd_academy\",\"table\":\"registrations\"},{\"db\":\"asd_academy\",\"table\":\"verification_codes\"},{\"db\":\"asd_academy\",\"table\":\"transactions\"},{\"db\":\"asd_academy\",\"table\":\"testimonials\"},{\"db\":\"asd_academy\",\"table\":\"scholarship_registrations\"}]');

-- --------------------------------------------------------

--
-- Table structure for table `pma__relation`
--

CREATE TABLE `pma__relation` (
  `master_db` varchar(64) NOT NULL DEFAULT '',
  `master_table` varchar(64) NOT NULL DEFAULT '',
  `master_field` varchar(64) NOT NULL DEFAULT '',
  `foreign_db` varchar(64) NOT NULL DEFAULT '',
  `foreign_table` varchar(64) NOT NULL DEFAULT '',
  `foreign_field` varchar(64) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Relation table';

-- --------------------------------------------------------

--
-- Table structure for table `pma__savedsearches`
--

CREATE TABLE `pma__savedsearches` (
  `id` int(5) UNSIGNED NOT NULL,
  `username` varchar(64) NOT NULL DEFAULT '',
  `db_name` varchar(64) NOT NULL DEFAULT '',
  `search_name` varchar(64) NOT NULL DEFAULT '',
  `search_data` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Saved searches';

-- --------------------------------------------------------

--
-- Table structure for table `pma__table_coords`
--

CREATE TABLE `pma__table_coords` (
  `db_name` varchar(64) NOT NULL DEFAULT '',
  `table_name` varchar(64) NOT NULL DEFAULT '',
  `pdf_page_number` int(11) NOT NULL DEFAULT 0,
  `x` float UNSIGNED NOT NULL DEFAULT 0,
  `y` float UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Table coordinates for phpMyAdmin PDF output';

-- --------------------------------------------------------

--
-- Table structure for table `pma__table_info`
--

CREATE TABLE `pma__table_info` (
  `db_name` varchar(64) NOT NULL DEFAULT '',
  `table_name` varchar(64) NOT NULL DEFAULT '',
  `display_field` varchar(64) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Table information for phpMyAdmin';

-- --------------------------------------------------------

--
-- Table structure for table `pma__table_uiprefs`
--

CREATE TABLE `pma__table_uiprefs` (
  `username` varchar(64) NOT NULL,
  `db_name` varchar(64) NOT NULL,
  `table_name` varchar(64) NOT NULL,
  `prefs` text NOT NULL,
  `last_update` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Tables'' UI preferences';

-- --------------------------------------------------------

--
-- Table structure for table `pma__tracking`
--

CREATE TABLE `pma__tracking` (
  `db_name` varchar(64) NOT NULL,
  `table_name` varchar(64) NOT NULL,
  `version` int(10) UNSIGNED NOT NULL,
  `date_created` datetime NOT NULL,
  `date_updated` datetime NOT NULL,
  `schema_snapshot` text NOT NULL,
  `schema_sql` text DEFAULT NULL,
  `data_sql` longtext DEFAULT NULL,
  `tracking` set('UPDATE','REPLACE','INSERT','DELETE','TRUNCATE','CREATE DATABASE','ALTER DATABASE','DROP DATABASE','CREATE TABLE','ALTER TABLE','RENAME TABLE','DROP TABLE','CREATE INDEX','DROP INDEX','CREATE VIEW','ALTER VIEW','DROP VIEW') DEFAULT NULL,
  `tracking_active` int(1) UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Database changes tracking for phpMyAdmin';

-- --------------------------------------------------------

--
-- Table structure for table `pma__userconfig`
--

CREATE TABLE `pma__userconfig` (
  `username` varchar(64) NOT NULL,
  `timevalue` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `config_data` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='User preferences storage for phpMyAdmin';

--
-- Dumping data for table `pma__userconfig`
--

INSERT INTO `pma__userconfig` (`username`, `timevalue`, `config_data`) VALUES
('root', '2026-05-31 08:10:46', '{\"Console\\/Mode\":\"show\",\"NavigationWidth\":382}');

-- --------------------------------------------------------

--
-- Table structure for table `pma__usergroups`
--

CREATE TABLE `pma__usergroups` (
  `usergroup` varchar(64) NOT NULL,
  `tab` varchar(64) NOT NULL,
  `allowed` enum('Y','N') NOT NULL DEFAULT 'N'
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='User groups with configured menu items';

-- --------------------------------------------------------

--
-- Table structure for table `pma__users`
--

CREATE TABLE `pma__users` (
  `username` varchar(64) NOT NULL,
  `usergroup` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Users and their assignments to user groups';

--
-- Indexes for dumped tables
--

--
-- Indexes for table `pma__bookmark`
--
ALTER TABLE `pma__bookmark`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pma__central_columns`
--
ALTER TABLE `pma__central_columns`
  ADD PRIMARY KEY (`db_name`,`col_name`);

--
-- Indexes for table `pma__column_info`
--
ALTER TABLE `pma__column_info`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `db_name` (`db_name`,`table_name`,`column_name`);

--
-- Indexes for table `pma__designer_settings`
--
ALTER TABLE `pma__designer_settings`
  ADD PRIMARY KEY (`username`);

--
-- Indexes for table `pma__export_templates`
--
ALTER TABLE `pma__export_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `u_user_type_template` (`username`,`export_type`,`template_name`);

--
-- Indexes for table `pma__favorite`
--
ALTER TABLE `pma__favorite`
  ADD PRIMARY KEY (`username`);

--
-- Indexes for table `pma__history`
--
ALTER TABLE `pma__history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `username` (`username`,`db`,`table`,`timevalue`);

--
-- Indexes for table `pma__navigationhiding`
--
ALTER TABLE `pma__navigationhiding`
  ADD PRIMARY KEY (`username`,`item_name`,`item_type`,`db_name`,`table_name`);

--
-- Indexes for table `pma__pdf_pages`
--
ALTER TABLE `pma__pdf_pages`
  ADD PRIMARY KEY (`page_nr`),
  ADD KEY `db_name` (`db_name`);

--
-- Indexes for table `pma__recent`
--
ALTER TABLE `pma__recent`
  ADD PRIMARY KEY (`username`);

--
-- Indexes for table `pma__relation`
--
ALTER TABLE `pma__relation`
  ADD PRIMARY KEY (`master_db`,`master_table`,`master_field`),
  ADD KEY `foreign_field` (`foreign_db`,`foreign_table`);

--
-- Indexes for table `pma__savedsearches`
--
ALTER TABLE `pma__savedsearches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `u_savedsearches_username_dbname` (`username`,`db_name`,`search_name`);

--
-- Indexes for table `pma__table_coords`
--
ALTER TABLE `pma__table_coords`
  ADD PRIMARY KEY (`db_name`,`table_name`,`pdf_page_number`);

--
-- Indexes for table `pma__table_info`
--
ALTER TABLE `pma__table_info`
  ADD PRIMARY KEY (`db_name`,`table_name`);

--
-- Indexes for table `pma__table_uiprefs`
--
ALTER TABLE `pma__table_uiprefs`
  ADD PRIMARY KEY (`username`,`db_name`,`table_name`);

--
-- Indexes for table `pma__tracking`
--
ALTER TABLE `pma__tracking`
  ADD PRIMARY KEY (`db_name`,`table_name`,`version`);

--
-- Indexes for table `pma__userconfig`
--
ALTER TABLE `pma__userconfig`
  ADD PRIMARY KEY (`username`);

--
-- Indexes for table `pma__usergroups`
--
ALTER TABLE `pma__usergroups`
  ADD PRIMARY KEY (`usergroup`,`tab`,`allowed`);

--
-- Indexes for table `pma__users`
--
ALTER TABLE `pma__users`
  ADD PRIMARY KEY (`username`,`usergroup`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `pma__bookmark`
--
ALTER TABLE `pma__bookmark`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pma__column_info`
--
ALTER TABLE `pma__column_info`
  MODIFY `id` int(5) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pma__export_templates`
--
ALTER TABLE `pma__export_templates`
  MODIFY `id` int(5) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pma__history`
--
ALTER TABLE `pma__history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pma__pdf_pages`
--
ALTER TABLE `pma__pdf_pages`
  MODIFY `page_nr` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pma__savedsearches`
--
ALTER TABLE `pma__savedsearches`
  MODIFY `id` int(5) UNSIGNED NOT NULL AUTO_INCREMENT;
--
-- Database: `test`
--
CREATE DATABASE IF NOT EXISTS `test` DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci;
USE `test`;
--
-- Database: `u621399201_koral`
--
CREATE DATABASE IF NOT EXISTS `u621399201_koral` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `u621399201_koral`;

-- --------------------------------------------------------

--
-- Table structure for table `assignment_submissions`
--

CREATE TABLE `assignment_submissions` (
  `id` int(11) NOT NULL,
  `upload_id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('submitted','graded','late','missing') DEFAULT 'submitted',
  `grade` decimal(5,2) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `graded_by` int(11) DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignment_submissions`
--

INSERT INTO `assignment_submissions` (`id`, `upload_id`, `student_id`, `file_path`, `submitted_at`, `updated_at`, `status`, `grade`, `feedback`, `graded_by`, `graded_at`) VALUES
(1, 209, 'STD002', '../uploads/assignments/submissions/submission_STD002_209_1780574350.pdf', '2026-06-04 11:59:10', '2026-06-04 12:00:19', 'graded', 100.00, '', 1, '2026-06-04 17:30:19'),
(2, 210, 'STD002', '../uploads/assignments/submissions/submission_STD002_210_1780574592.pdf', '2026-06-04 12:03:12', '2026-06-04 12:03:46', 'graded', 0.00, '', 1, '2026-06-04 17:33:46');

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `student_id` varchar(50) DEFAULT NULL,
  `date` date NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `student_name` varchar(50) NOT NULL,
  `status` enum('Present','Absent') DEFAULT 'Present',
  `camera_status` enum('On','Off') DEFAULT 'Off',
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `student_id`, `date`, `batch_id`, `student_name`, `status`, `camera_status`, `remarks`) VALUES
(1, 'STD001', '2026-05-04', 'B001', 'test user', 'Present', 'On', 'Attentive'),
(2, 'STD001', '2026-05-06', 'B001', 'test user', 'Present', 'On', 'Active participant'),
(3, 'STD001', '2026-05-08', 'B001', 'test user', 'Present', 'Off', ''),
(4, 'STD001', '2026-05-11', 'B001', 'test user', 'Present', 'On', ''),
(5, 'STD001', '2026-05-13', 'B001', 'test user', 'Present', 'On', ''),
(6, 'STD001', '2026-05-15', 'B001', 'test user', 'Present', 'On', ''),
(7, 'STD001', '2026-05-18', 'B001', 'test user', 'Absent', 'Off', 'Informed beforehand'),
(8, 'STD001', '2026-05-20', 'B001', 'test user', 'Present', 'On', ''),
(9, 'STD001', '2026-05-22', 'B001', 'test user', 'Present', 'On', ''),
(10, 'STD001', '2026-05-25', 'B001', 'test user', 'Present', 'Off', ''),
(11, 'STD001', '2026-05-27', 'B001', 'test user', 'Present', 'On', '');

-- --------------------------------------------------------

--
-- Table structure for table `batches`
--

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

--
-- Dumping data for table `batches`
--

INSERT INTO `batches` (`batch_id`, `batch_name`, `course_description`, `start_date`, `end_date`, `time_slot`, `platform`, `meeting_link`, `thumbnail_path`, `max_students`, `current_enrollment`, `academic_year`, `batch_mentor_id`, `mode`, `status`, `created_at`, `created_by`) VALUES
('B001', 'Linux Admin Batch 1', 'Learn Linux administration from scratch including bash scripting and server management.', '2026-05-01', '2026-08-01', '18:00 - 19:30', 'Zoom', 'https://zoom.us/j/1234567890', NULL, 30, 1, '2026', 0, 'online', 'ongoing', '2026-05-29 12:04:46', 1),
('B002', 'Eklavya', '', '2026-06-02', '2026-09-02', '', 'Google Meet', 'https://meet.google.com/ewj-ymmg-puz', NULL, 10, 0, '', 0, 'online', 'ongoing', '2026-06-02 09:07:44', 1),
('B003', 'Backend Batch', '', '2026-06-03', '2026-09-03', '', 'Zoom', 'https://zoom.us/j/1234567890', NULL, 24, 2, '', 0, 'online', 'upcoming', '2026-06-03 10:17:41', 1);

-- --------------------------------------------------------

--
-- Table structure for table `batch_courses`
--

CREATE TABLE `batch_courses` (
  `id` int(11) NOT NULL,
  `batch_id` varchar(50) NOT NULL,
  `course_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `batch_courses`
--

INSERT INTO `batch_courses` (`id`, `batch_id`, `course_id`, `created_at`) VALUES
(19, 'B001', 7, '2026-06-03 10:08:27'),
(21, 'B002', 5, '2026-06-03 10:10:03'),
(22, 'B002', 7, '2026-06-03 10:10:03'),
(40, 'B003', 7, '2026-06-05 06:02:32'),
(41, 'B003', 6, '2026-06-05 06:02:32');

-- --------------------------------------------------------

--
-- Table structure for table `batch_terms_settings`
--

CREATE TABLE `batch_terms_settings` (
  `id` int(11) NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `require_terms_acceptance` tinyint(1) DEFAULT 1,
  `terms_content` text DEFAULT NULL,
  `custom_terms_enabled` tinyint(1) DEFAULT 0,
  `custom_terms_file` varchar(255) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `batch_uploads`
--

CREATE TABLE `batch_uploads` (
  `id` int(11) NOT NULL,
  `upload_id` int(11) NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `course_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `batch_uploads`
--

INSERT INTO `batch_uploads` (`id`, `upload_id`, `batch_id`, `course_id`) VALUES
(12, 207, 'B003', 6),
(14, 208, 'B003', 7),
(16, 210, 'B003', 7);

-- --------------------------------------------------------

--
-- Table structure for table `certificate_templates`
--

CREATE TABLE `certificate_templates` (
  `id` int(11) NOT NULL,
  `workshop_id` varchar(10) NOT NULL,
  `template_path` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clear_chat_history`
--

CREATE TABLE `clear_chat_history` (
  `id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `cleared_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `content_categories`
--

CREATE TABLE `content_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `color` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `conversations`
--

CREATE TABLE `conversations` (
  `id` int(11) NOT NULL,
  `type` enum('one_to_one','group') NOT NULL DEFAULT 'one_to_one',
  `name` varchar(255) DEFAULT NULL,
  `batch_id` varchar(50) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_cleared` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `conversation_members`
--

CREATE TABLE `conversation_members` (
  `id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`id`, `name`) VALUES
(5, 'Linux Fundamental'),
(6, 'Full Stack Development'),
(7, 'App Development');

-- --------------------------------------------------------

--
-- Table structure for table `course_content_visibility`
--

CREATE TABLE `course_content_visibility` (
  `course_id` int(11) NOT NULL,
  `batch_id` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `course_content_visibility`
--

INSERT INTO `course_content_visibility` (`course_id`, `batch_id`) VALUES
(6, 'B003'),
(7, 'B003');

-- --------------------------------------------------------

--
-- Table structure for table `course_main_topics`
--

CREATE TABLE `course_main_topics` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `chapter` int(11) NOT NULL,
  `topic_name` varchar(255) NOT NULL,
  `topic_type` enum('theory','practical','both') DEFAULT 'both',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `course_main_topics`
--

INSERT INTO `course_main_topics` (`id`, `course_id`, `chapter`, `topic_name`, `topic_type`, `created_at`) VALUES
(3, 6, 1, 'Complete HTML Tutorial', 'both', '2026-06-03 08:03:27'),
(4, 6, 2, 'Complete CSS Tutorial', 'both', '2026-06-03 08:03:35'),
(5, 7, 1, 'Intoduction to Java', 'both', '2026-06-03 08:23:03'),
(6, 7, 2, 'OOPS with Java', 'both', '2026-06-03 08:27:25');

-- --------------------------------------------------------

--
-- Table structure for table `course_sub_topics`
--

CREATE TABLE `course_sub_topics` (
  `id` int(11) NOT NULL,
  `course_main_topic_id` int(11) NOT NULL,
  `sub_topic_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `course_sub_topics`
--

INSERT INTO `course_sub_topics` (`id`, `course_main_topic_id`, `sub_topic_name`, `created_at`) VALUES
(2, 3, 'Semantic Tags, Box Model', '2026-06-03 08:03:31'),
(3, 5, 'Variables', '2026-06-03 08:23:15'),
(4, 6, 'Classes, Objects, etc.', '2026-06-03 08:27:38'),
(5, 5, 'Functions, Operators', '2026-06-03 08:41:40');

-- --------------------------------------------------------

--
-- Table structure for table `doubts`
--

CREATE TABLE `doubts` (
  `id` int(11) NOT NULL,
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
  `resolved_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `doubt_responses`
--

CREATE TABLE `doubt_responses` (
  `id` int(11) NOT NULL,
  `doubt_id` int(11) NOT NULL,
  `responded_by` int(11) NOT NULL,
  `response` text NOT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `is_trainer_response` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exams`
--

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
  `is_back_schedule` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `exams`
--

INSERT INTO `exams` (`exam_id`, `exam_name`, `batch_id`, `subject`, `exam_date`, `total_marks`, `passing_marks`, `exam_type`, `description`, `created_by`, `created_at`, `enrollment_status`, `exam_components`, `mcq_marks`, `project_marks`, `viva_marks`, `is_back_schedule`) VALUES
('EXM001', 'Linux Basics Quiz', 'B001', 'Linux', '2026-05-15', 50.00, 20.00, 'unit_test', 'Covers basic terminal commands and Linux structure.', 1, '2026-05-29 12:04:46', 'all_students', 'mcq', 0.00, 0.00, 0.00, 0),
('EXM002', 'Command Line Practical', 'B001', 'Linux', '2026-05-22', 100.00, 40.00, 'practice', 'Real-world terminal tasks evaluation.', 1, '2026-05-29 12:04:46', 'all_students', 'mcq', 0.00, 0.00, 0.00, 0);

-- --------------------------------------------------------

--
-- Table structure for table `exam_enrollments`
--

CREATE TABLE `exam_enrollments` (
  `id` int(11) NOT NULL,
  `exam_id` varchar(12) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `enrolled_by` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_results`
--

CREATE TABLE `exam_results` (
  `id` int(11) NOT NULL,
  `exam_id` varchar(12) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `obtained_marks` decimal(5,2) DEFAULT NULL,
  `grade` varchar(2) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `mcq_marks` decimal(5,2) DEFAULT NULL,
  `project_marks` decimal(5,2) DEFAULT NULL,
  `viva_marks` decimal(5,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `exam_results`
--

INSERT INTO `exam_results` (`id`, `exam_id`, `student_id`, `obtained_marks`, `grade`, `remarks`, `uploaded_by`, `uploaded_at`, `mcq_marks`, `project_marks`, `viva_marks`) VALUES
(1, 'EXM001', 'STD001', 45.00, 'A', 'Excellent conceptual knowledge shown.', 1, '2026-05-29 12:04:46', NULL, NULL, NULL),
(2, 'EXM002', 'STD001', 88.00, 'A', 'Very efficient terminal commands execution.', 1, '2026-05-29 12:04:46', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `exam_students`
--

CREATE TABLE `exam_students` (
  `exam_id` varchar(10) NOT NULL,
  `student_name` varchar(50) NOT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `is_malpractice` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int(11) NOT NULL,
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
  `show_to_trainer` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feeds`
--

CREATE TABLE `feeds` (
  `id` int(11) NOT NULL,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feed_comments`
--

CREATE TABLE `feed_comments` (
  `id` int(11) NOT NULL,
  `feed_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_role` enum('admin','student','mentor') NOT NULL,
  `comment` text NOT NULL,
  `parent_comment_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feed_notifications`
--

CREATE TABLE `feed_notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `feed_id` int(11) NOT NULL,
  `type` enum('reaction','comment','reply') NOT NULL,
  `actor_id` int(11) NOT NULL,
  `actor_role` enum('admin','student','mentor') NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feed_reactions`
--

CREATE TABLE `feed_reactions` (
  `id` int(11) NOT NULL,
  `feed_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_role` enum('admin','student','mentor') NOT NULL,
  `reaction_type` enum('like','love','care','haha','wow','sad','angry') DEFAULT 'like',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fee_installments`
--

CREATE TABLE `fee_installments` (
  `id` int(11) NOT NULL,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fee_reminder_logs`
--

CREATE TABLE `fee_reminder_logs` (
  `id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `sent_by` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `sent_at` datetime NOT NULL,
  `email_sent` tinyint(1) DEFAULT 0,
  `is_bulk` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leads`
--

CREATE TABLE `leads` (
  `id` int(11) NOT NULL,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_applications`
--

CREATE TABLE `leave_applications` (
  `id` int(11) NOT NULL,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_application_history`
--

CREATE TABLE `leave_application_history` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `action` enum('submitted','updated','approved','rejected','cancelled') NOT NULL,
  `action_by` int(11) NOT NULL,
  `action_at` datetime NOT NULL DEFAULT current_timestamp(),
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempt_time` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `main_topics`
--

CREATE TABLE `main_topics` (
  `id` int(11) NOT NULL,
  `batch_name` varchar(50) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `chapter` int(11) NOT NULL,
  `topic_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `covered_by_trainer` tinyint(1) DEFAULT 0,
  `covered_date` datetime DEFAULT NULL,
  `topic_type` enum('theory','practical','both') DEFAULT 'both',
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `main_topics`
--

INSERT INTO `main_topics` (`id`, `batch_name`, `course_id`, `chapter`, `topic_name`, `created_at`, `covered_by_trainer`, `covered_date`, `topic_type`, `is_active`) VALUES
(101, 'B001', NULL, 1, 'Introduction to Linux OS', '2026-05-29 12:04:46', 1, '2026-05-02 18:00:00', 'both', 1),
(102, 'B001', NULL, 2, 'File System Navigation & Operations', '2026-05-29 12:04:46', 1, '2026-05-10 18:00:00', 'both', 1),
(103, 'B001', NULL, 3, 'User & Group Configurations', '2026-05-29 12:04:46', 0, NULL, 'both', 1),
(104, 'B002', 6, 1, 'Complete HTML Tutorial', '2026-06-03 08:05:24', 0, NULL, 'both', 1),
(105, 'B002', 6, 2, 'Complete CSS Tutorial', '2026-06-03 08:05:24', 0, NULL, 'both', 1),
(106, 'B002', 7, 1, 'Intoduction to Java', '2026-06-03 08:23:48', 0, NULL, 'both', 1),
(107, 'B002', 7, 2, 'OOPS with Java', '2026-06-03 08:27:25', 0, NULL, 'both', 1),
(108, 'B001', 7, 1, 'Intoduction to Java', '2026-06-03 10:08:27', 0, NULL, 'both', 1),
(109, 'B001', 7, 2, 'OOPS with Java', '2026-06-03 10:08:27', 0, NULL, 'both', 1),
(110, 'B001', 6, 1, 'Complete HTML Tutorial', '2026-06-03 10:08:27', 0, NULL, 'both', 1),
(111, 'B001', 6, 2, 'Complete CSS Tutorial', '2026-06-03 10:08:27', 0, NULL, 'both', 1),
(112, 'B003', 6, 1, 'Complete HTML Tutorial', '2026-06-03 10:17:13', 0, NULL, 'both', 1),
(113, 'B003', 6, 2, 'Complete CSS Tutorial', '2026-06-03 10:17:13', 0, NULL, 'both', 1),
(114, 'B003', 7, 1, 'Intoduction to Java', '2026-06-03 10:17:13', 0, NULL, 'both', 1),
(115, 'B003', 7, 2, 'OOPS with Java', '2026-06-03 10:17:13', 0, NULL, 'both', 1);

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_type` enum('image','video','audio','document','other') DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_size` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('feedback','message','leave','ticket') NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `reference_id` int(11) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `reference_id`, `is_read`, `created_at`) VALUES
(3, 7, 'ticket', 'Support Ticket Resolved', 'Your support ticket regarding \'Batch/Schedule Related\' has been resolved. Click to view the reply.', 1, 0, '2026-06-02 08:58:51'),
(4, 11, 'ticket', 'Support Ticket Resolved', 'Your support ticket regarding \'Exam/Test Related\' has been resolved. Click to view the reply.', 2, 0, '2026-06-02 09:20:13'),
(5, 11, 'ticket', 'Support Ticket Resolved', 'Your support ticket regarding \'Fee Related\' has been resolved. Click to view the reply.', 3, 0, '2026-06-03 06:24:02'),
(6, 7, 'ticket', 'Support Ticket Closed', 'Your support ticket regarding \'App/Technical Issue\' has been closed by the admin.', 4, 0, '2026-06-03 06:38:42'),
(7, 7, 'ticket', 'New Message on Support Ticket', 'Admin responded to your support ticket regarding \'Exam/Test Related\'. Click to reply.', 5, 0, '2026-06-03 06:46:44'),
(8, 7, 'ticket', 'Support Ticket Closed', 'Your support ticket regarding \'Exam/Test Related\' has been closed by the admin.', 5, 0, '2026-06-03 06:50:02');

-- --------------------------------------------------------

--
-- Table structure for table `payment_modes`
--

CREATE TABLE `payment_modes` (
  `id` int(11) NOT NULL,
  `mode_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_verification_settings`
--

CREATE TABLE `payment_verification_settings` (
  `id` int(11) NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `proctored_exams`
--

CREATE TABLE `proctored_exams` (
  `exam_id` varchar(10) NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `exam_date` date NOT NULL,
  `mode` enum('Online','Offline') NOT NULL,
  `duration` int(11) DEFAULT NULL COMMENT 'Minutes',
  `proctor_name` varchar(50) DEFAULT NULL,
  `malpractice_cases` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `remember_tokens`
--

CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `remember_tokens`
--

INSERT INTO `remember_tokens` (`id`, `user_id`, `token`, `expires_at`, `created_at`) VALUES
(1, 10, 'f5a374652aafcab994eb89bc718d5cb6cc4e92aa7fa6ff76e083a8eeb4e5156f', '2026-06-05 17:31:05', '2026-05-29 12:01:05'),
(2, 11, '9f56f5cbd978bd5decf1878a604ba5c097ae13e4d7c0ce09afd5f3c1a7e10678', '2026-06-09 14:42:22', '2026-06-02 09:12:22'),
(3, 11, '640d445f34b6f37d7b30ece6b85bd2b7a4831c3d8ec7c1f3b5c8800e7efc0c12', '2026-06-09 14:48:37', '2026-06-02 09:18:37'),
(4, 11, '0044c6e08f2ccf3055ded8000676d1c604c4481044613fc57cc083de7bc960a2', '2026-06-09 15:39:49', '2026-06-02 10:09:49'),
(5, 11, '686be2b78b9b40306098313430bcca3b66840eb83b31434513ada1d5f2566d3c', '2026-06-09 16:31:16', '2026-06-02 11:01:16'),
(6, 12, '6094ab277f70296ee8c90539c0203aa80cd2cd620fe766a5bbcab97338b9d872', '2026-06-10 15:58:35', '2026-06-03 10:28:35');

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `report_id` varchar(12) NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `month` date NOT NULL,
  `generated_on` datetime DEFAULT current_timestamp(),
  `report_type` enum('Monthly','Exam','Feedback') NOT NULL,
  `file_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `results_sync`
--

CREATE TABLE `results_sync` (
  `id` int(11) NOT NULL,
  `test_id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `asd_exam_id` varchar(12) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'pending',
  `sync_message` text DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schedule`
--

CREATE TABLE `schedule` (
  `id` int(11) NOT NULL,
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
  `is_back_schedule` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedule`
--

INSERT INTO `schedule` (`id`, `batch_id`, `schedule_date`, `start_time`, `end_time`, `topic`, `description`, `is_cancelled`, `cancellation_reason`, `created_at`, `created_by`, `is_back_schedule`) VALUES
(1, 'B001', '2026-05-29', '18:00:00', '19:30:00', 'Shell Scripting Basics', 'Introduction to variables, arguments, and scripting files.', 0, NULL, '2026-05-29 12:04:46', 1, 0),
(2, 'B001', '2026-05-30', '18:00:00', '19:30:00', 'Bash Control Flow', 'Using if/else, loops (for/while) in scripting.', 0, NULL, '2026-05-29 12:04:46', 1, 0),
(3, 'B001', '2026-06-01', '18:00:00', '19:30:00', 'Linux Process Control', 'Managing background jobs, ps, top, kill commands.', 0, NULL, '2026-05-29 12:04:46', 1, 0),
(109, 'B003', '2026-02-21', '10:00:00', '12:00:00', 'Introduction to PHP', 'Basic PHP concepts and syntax', 0, NULL, '2026-06-05 06:08:24', 1, 1),
(110, 'B003', '2026-02-22', '14:00:00', '16:00:00', 'Database Design', 'MySQL fundamentals', 0, NULL, '2026-06-05 06:08:24', 1, 1),
(111, 'B003', '2026-02-23', '09:00:00', '11:00:00', 'JavaScript Basics', 'Variables and functions', 0, NULL, '2026-06-05 06:08:24', 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `semesters`
--

CREATE TABLE `semesters` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('planning','ongoing','completed','cancelled') DEFAULT 'planning',
  `max_semesters` tinyint(1) DEFAULT 6,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `semester_batches`
--

CREATE TABLE `semester_batches` (
  `id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `semester_number` tinyint(1) NOT NULL COMMENT 'Semester number (1-6)',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assigned_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

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
  `registration_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`student_id`, `course`, `user_id`, `first_name`, `last_name`, `email`, `phone_number`, `date_of_birth`, `enrollment_date`, `current_status`, `on_hold_reason`, `on_hold_date`, `batch_name`, `batch_name_2`, `batch_name_3`, `batch_name_4`, `dropout_date`, `dropout_reason`, `dropout_processed_by`, `dropout_processed_at`, `father_name`, `father_phone_number`, `father_email`, `state`, `password_hash`, `last_login`, `profile_picture`, `sales_person_id`, `referral_source`, `sales_notes`, `enrollment_fees`, `fees_status`, `fees_agreement_date`, `fees_payment_mode`, `total_fees_paid`, `last_payment_date`, `next_payment_due_date`, `city`, `address`, `pincode`, `terms_accepted`, `terms_accepted_date`, `terms_accepted_ip`, `registration_id`) VALUES
('STD001', 5, 7, 'test ', 'user', 'aryangupta.gca@gmail.com', '6378811299', NULL, '2026-05-29', 'active', NULL, NULL, 'B001', 'B001', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'bundi', '$2y$10$sRhLCIs3YGVSLzk19f19PeNmacJY0c6pDGVFuV3tkCHI6BcAkvwjy', NULL, NULL, NULL, NULL, NULL, 5000.00, 'fully_paid', NULL, NULL, 5000.00, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-29 17:34:46', '127.0.0.1', 'ASD-2026-6891'),
('STD002', 5, 11, 'Dixant ', 'Choudhary', 'aryangtp@gmail.com', '9530334990', '2016-06-02', '2026-06-02', 'active', NULL, NULL, 'B002', 'B003', '', '', NULL, NULL, NULL, NULL, '', '', '', 'Rajasthan', '$2y$10$bjzF6PNbQg1boGHHitpqeupHMKeloJ8I99N7YuG3ASGwlDCqjkkfC', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL),
('STD003', 6, 12, 'Saksham', 'Verma', 'vermasaksham.06@gmail.com', '1234567890', '2006-11-01', '2026-06-03', 'active', NULL, NULL, 'B003', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '', '', '', 'Punjab', '$2y$10$1NI2bLa4vaPyvGP8BafE.Oud3LKjl8Ho3AFHR0umCAtgIm3CNUiJ.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `student_batch_history`
--

CREATE TABLE `student_batch_history` (
  `id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `from_batch_id` varchar(10) NOT NULL,
  `to_batch_id` varchar(10) NOT NULL,
  `transfer_reason` varchar(200) DEFAULT NULL,
  `transfer_date` datetime NOT NULL DEFAULT current_timestamp(),
  `transferred_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_documents`
--

CREATE TABLE `student_documents` (
  `document_id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `document_type` enum('aadhaar','pancard','tenth_marksheet','twelfth_marksheet','other') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_status_log`
--

CREATE TABLE `student_status_log` (
  `id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `action` enum('dropped','reactivated','transferred','on_hold','resumed') NOT NULL,
  `reason` text DEFAULT NULL,
  `processed_by` int(11) NOT NULL,
  `processed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `enrollment_fees` decimal(10,2) DEFAULT NULL,
  `fees_status` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sub_topics`
--

CREATE TABLE `sub_topics` (
  `id` int(11) NOT NULL,
  `main_topic_id` int(11) NOT NULL,
  `sub_topic_name` varchar(255) NOT NULL,
  `theory_completed` tinyint(1) DEFAULT 0,
  `practical_completed` tinyint(1) DEFAULT 0,
  `completed_by` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sub_topics`
--

INSERT INTO `sub_topics` (`id`, `main_topic_id`, `sub_topic_name`, `theory_completed`, `practical_completed`, `completed_by`, `completed_at`, `created_at`) VALUES
(1, 101, 'Linux Directory Layout', 0, 0, 1, '2026-06-03 15:38:50', '2026-05-29 12:04:46'),
(2, 101, 'GNU/Linux Kernel & Shell concepts', 1, 1, 1, '2026-05-02 19:30:00', '2026-05-29 12:04:46'),
(3, 102, 'Using cd, ls, pwd, mkdir, rm commands', 1, 1, 1, '2026-05-10 19:00:00', '2026-05-29 12:04:46'),
(4, 102, 'Absolute vs Relative Paths', 1, 1, 1, '2026-05-10 19:15:00', '2026-05-29 12:04:46'),
(5, 102, 'Permissions chmod & chown', 1, 1, 1, '2026-05-10 19:30:00', '2026-05-29 12:04:46'),
(6, 103, 'Adding users (useradd, passwd)', 0, 0, NULL, NULL, '2026-05-29 12:04:46'),
(7, 103, 'Managing groups (groupadd, usermod)', 0, 0, NULL, NULL, '2026-05-29 12:04:46'),
(8, 104, 'Semantic Tags, Box Model', 1, 1, 1, '2026-06-03 15:36:08', '2026-06-03 08:05:24'),
(9, 106, 'Variables', 0, 0, 1, '2026-06-03 14:14:39', '2026-06-03 08:23:48'),
(10, 107, 'Classes, Objects, etc.', 0, 0, NULL, NULL, '2026-06-03 08:27:38'),
(11, 106, 'Functions, Operators', 0, 0, NULL, NULL, '2026-06-03 08:41:40'),
(12, 108, 'Variables', 0, 0, NULL, NULL, '2026-06-03 10:08:27'),
(13, 108, 'Functions, Operators', 0, 0, NULL, NULL, '2026-06-03 10:08:27'),
(14, 109, 'Classes, Objects, etc.', 0, 0, NULL, NULL, '2026-06-03 10:08:27'),
(15, 110, 'Semantic Tags, Box Model', 1, 0, 1, '2026-06-03 15:38:46', '2026-06-03 10:08:27'),
(16, 112, 'Semantic Tags, Box Model', 1, 1, 1, '2026-06-03 15:50:28', '2026-06-03 10:17:13'),
(17, 114, 'Variables', 1, 0, 1, '2026-06-03 15:50:36', '2026-06-03 10:17:13'),
(18, 114, 'Functions, Operators', 1, 0, 1, '2026-06-03 15:50:38', '2026-06-03 10:17:13'),
(19, 115, 'Classes, Objects, etc.', 0, 0, NULL, NULL, '2026-06-03 10:17:13');

-- --------------------------------------------------------

--
-- Table structure for table `tests`
--

CREATE TABLE `tests` (
  `id` int(11) NOT NULL,
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
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tests`
--

INSERT INTO `tests` (`id`, `title`, `description`, `batch_id`, `subject`, `total_marks`, `passing_marks`, `duration_minutes`, `max_attempts`, `start_date`, `end_date`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(2, 'lele', '', '', 'lele', 19, 40, 60, 1, NULL, NULL, 1, 1, '2026-06-04 06:58:11', '2026-06-04 06:58:11');

-- --------------------------------------------------------

--
-- Table structure for table `test_answers`
--

CREATE TABLE `test_answers` (
  `id` int(11) NOT NULL,
  `attempt_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `selected_answer` enum('a','b','c','d','') DEFAULT '',
  `is_correct` tinyint(1) DEFAULT 0,
  `marks_obtained` decimal(5,2) DEFAULT 0.00,
  `answered_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `test_answers`
--

INSERT INTO `test_answers` (`id`, `attempt_id`, `question_id`, `selected_answer`, `is_correct`, `marks_obtained`, `answered_at`) VALUES
(1, 1, 11, 'b', 0, 0.00, '2026-06-04 12:28:31'),
(2, 1, 12, 'c', 1, 1.00, '2026-06-04 12:28:31'),
(3, 1, 13, 'd', 0, 0.00, '2026-06-04 12:28:31'),
(4, 1, 14, 'd', 0, 0.00, '2026-06-04 12:28:31'),
(5, 1, 15, 'd', 0, 0.00, '2026-06-04 12:28:31'),
(6, 1, 16, 'd', 0, 0.00, '2026-06-04 12:28:31'),
(7, 1, 17, 'd', 0, 0.00, '2026-06-04 12:28:31'),
(8, 1, 18, 'd', 1, 2.00, '2026-06-04 12:28:31'),
(9, 1, 19, 'd', 0, 0.00, '2026-06-04 12:28:31'),
(10, 1, 20, 'd', 0, 0.00, '2026-06-04 12:28:31');

-- --------------------------------------------------------

--
-- Table structure for table `test_attempts`
--

CREATE TABLE `test_attempts` (
  `id` int(11) NOT NULL,
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
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `test_attempts`
--

INSERT INTO `test_attempts` (`id`, `test_id`, `student_id`, `attempt_number`, `started_at`, `submitted_at`, `time_taken_seconds`, `total_questions`, `questions_attempted`, `correct_answers`, `wrong_answers`, `total_marks`, `obtained_marks`, `percentage`, `status`, `created_at`) VALUES
(1, 2, 'STD002', 1, '2026-06-04 12:28:18', '2026-06-04 12:28:31', 14, 10, 10, 2, 8, 19.00, 3.00, 15.79, 'submitted', '2026-06-04 06:58:18');

-- --------------------------------------------------------

--
-- Table structure for table `test_questions`
--

CREATE TABLE `test_questions` (
  `id` int(11) NOT NULL,
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
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `test_questions`
--

INSERT INTO `test_questions` (`id`, `test_id`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_answer`, `marks`, `explanation`, `question_order`, `created_at`) VALUES
(11, 2, 'What does HTML stand for?', 'Hyper Text Markup Language', 'High Tech Modern Language', 'Hyper Transfer Markup Language', 'Home Tool Markup Language', 'a', 1, 'HTML stands for Hyper Text Markup Language', 1, '2026-06-04 06:58:11'),
(12, 2, 'Which CSS property is used to change text color?', 'font-size', 'background-color', 'color', 'text-style', 'c', 1, 'The color property changes the text color', 2, '2026-06-04 06:58:11'),
(13, 2, 'Which JavaScript keyword is used to declare a variable?', 'define', 'var', 'create', 'make', 'b', 1, 'var is one of the keywords used to declare variables', 3, '2026-06-04 06:58:11'),
(14, 2, 'What is the output of 5 + \'5\' in JavaScript?', '10', '55', 'Error', 'undefined', 'b', 2, 'JavaScript performs string concatenation when one operand is a string', 4, '2026-06-04 06:58:11'),
(15, 2, 'Which company developed Java?', 'Microsoft', 'Google', 'Sun Microsystems', 'Apple', 'c', 2, 'Java was originally developed by Sun Microsystems', 5, '2026-06-04 06:58:11'),
(16, 2, 'What does SQL stand for?', 'Structured Query Language', 'Simple Question Language', 'System Query Logic', 'Structured Queue Language', 'a', 2, 'SQL stands for Structured Query Language', 6, '2026-06-04 06:58:11'),
(17, 2, 'Which React hook is used for state management in functional components?', 'useEffect', 'useState', 'useRef', 'useMemo', 'b', 3, 'useState allows functional components to manage state', 7, '2026-06-04 06:58:11'),
(18, 2, 'Which HTTP method is commonly used to retrieve data from a server?', 'POST', 'PUT', 'DELETE', 'GET', 'd', 2, 'GET is used to request data from a server', 8, '2026-06-04 06:58:11'),
(19, 2, 'What is the time complexity of binary search on a sorted array?', 'O(n)', 'O(log n)', 'O(n²)', 'O(1)', 'b', 3, 'Binary search halves the search space on each step', 9, '2026-06-04 06:58:11'),
(20, 2, 'Which database is commonly used in the MERN stack?', 'MySQL', 'Oracle', 'MongoDB', 'SQLite', 'c', 2, 'MongoDB is the database component of the MERN stack', 10, '2026-06-04 06:58:11');

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

CREATE TABLE `tickets` (
  `id` int(11) NOT NULL,
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
  `batch_id` varchar(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tickets`
--

INSERT INTO `tickets` (`id`, `student_id`, `reason`, `description`, `attachment_path`, `status`, `admin_response`, `created_at`, `updated_at`, `resolved_at`, `resolved_by`, `batch_id`) VALUES
(1, 'STD001', 'Batch/Schedule Related', 'incorrect batch enrolled', 'uploads/tickets/ticket_STD001_1780390687_5142.pdf', 'resolved', 'Closed by student', '2026-06-02 08:58:07', '2026-06-03 06:46:02', '2026-06-03 12:16:02', 7, NULL),
(2, 'STD002', 'Exam/Test Related', 'exam results are invalid', 'uploads/tickets/ticket_STD002_1780391963_2110.pdf', 'resolved', 'okay it is fixed now', '2026-06-02 09:19:23', '2026-06-02 09:20:13', '2026-06-02 14:50:13', 1, NULL),
(3, 'STD002', 'Fee Related', 'payment keeps failing', NULL, 'resolved', '.]', '2026-06-02 09:21:08', '2026-06-03 06:24:02', '2026-06-03 11:54:02', 1, NULL),
(4, 'STD001', 'App/Technical Issue', 'app is crashing', NULL, 'resolved', 'Closed by Admin', '2026-06-03 06:38:33', '2026-06-03 06:38:42', '2026-06-03 12:08:42', 1, 'B001'),
(5, 'STD001', 'Exam/Test Related', 'invalid exam results', NULL, 'resolved', 'Closed by Admin', '2026-06-03 06:46:20', '2026-06-03 06:50:02', '2026-06-03 12:20:02', 1, 'B001');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_messages`
--

CREATE TABLE `ticket_messages` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ticket_messages`
--

INSERT INTO `ticket_messages` (`id`, `ticket_id`, `sender_id`, `message`, `attachment_path`, `created_at`) VALUES
(1, 1, 7, 'Hello, is anyone there? I am facing issues with my login credentials.', NULL, '2026-06-03 06:44:35'),
(2, 1, 1, 'Yes, we are here. Could you please specify which username you are using?', NULL, '2026-06-03 06:44:35'),
(3, 5, 7, 'pls respond', NULL, '2026-06-03 06:46:30'),
(4, 5, 1, 'alright it will be fixed', NULL, '2026-06-03 06:46:44'),
(5, 5, 7, 'okay', NULL, '2026-06-03 06:46:53');

-- --------------------------------------------------------

--
-- Table structure for table `trainers`
--

CREATE TABLE `trainers` (
  `id` int(11) NOT NULL,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trainer_documents`
--

CREATE TABLE `trainer_documents` (
  `document_id` int(11) NOT NULL,
  `trainer_id` int(11) NOT NULL,
  `document_type` enum('resume','certification','degree','id_proof','other') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
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
  `receipt_generated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `uploads`
--

CREATE TABLE `uploads` (
  `id` int(11) NOT NULL,
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
  `course_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `uploads`
--

INSERT INTO `uploads` (`id`, `title`, `description`, `file_path`, `file_type`, `due_date`, `due_time`, `max_marks`, `category_id`, `uploaded_by`, `uploaded_at`, `content_source`, `course_id`) VALUES
(207, 'Node.js', 'dqqdqwdqwd', 'uploads/content/6a211931a53d7_Unit1.docx', 'Notes', NULL, NULL, 100.00, NULL, 1, '2026-06-04 06:20:33', 'file', 6),
(208, 'Intro to Android', 'ddqdqdqwdqwd', 'uploads/content/6a21196b390d2_UNIT2.pdf', 'Notes', NULL, NULL, 100.00, NULL, 1, '2026-06-04 06:21:31', 'file', 7),
(210, 'test assignment', 'krle', '../uploads/assignments/assignment_B003_1780574361_CCAvenue_Gap_Check_Report.pdf', 'Assignment', NULL, '23:59:00', 100.00, NULL, 1, '2026-06-04 11:59:21', 'file', 7);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
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
  `login_attempt_limit` int(11) NOT NULL DEFAULT 5
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `created_at`, `last_login`, `status`, `failed_login_attempts`, `account_locked`, `locked_reason`, `locked_at`, `locked_by`, `last_failed_login`, `login_attempt_limit`) VALUES
(1, 'Admin', 'admin@asdacademy.com', '$2y$10$m1/e1Ur4iEhEFBFWzsNmFOt5JZI.9iy3B53VgwKLlxZde5ttoX8vC', 'admin', '2026-05-29 11:54:02', '2026-06-03 13:14:12', 'active', 0, 0, NULL, NULL, NULL, NULL, 3),
(7, 'test  user', 'aryangupta.gca@gmail.com', '$2y$10$sRhLCIs3YGVSLzk19f19PeNmacJY0c6pDGVFuV3tkCHI6BcAkvwjy', 'student', '2026-05-28 15:16:58', '2026-06-03 12:07:55', 'active', 0, 0, NULL, NULL, NULL, NULL, 5),
(8, 'test  user', 'aryangupta.gca@gmail.com', '$2y$10$sRhLCIs3YGVSLzk19f19PeNmacJY0c6pDGVFuV3tkCHI6BcAkvwjy', 'student', '2026-05-28 15:22:45', NULL, 'active', 0, 0, NULL, NULL, NULL, NULL, 5),
(9, 'test  user', 'aryangupta.gca@gmail.com', '$2y$10$sRhLCIs3YGVSLzk19f19PeNmacJY0c6pDGVFuV3tkCHI6BcAkvwjy', 'student', '2026-05-29 11:27:55', NULL, 'active', 0, 0, NULL, NULL, NULL, NULL, 5),
(10, 'test  user', 'aryangupta.gca@gmail.com', '$2y$10$sRhLCIs3YGVSLzk19f19PeNmacJY0c6pDGVFuV3tkCHI6BcAkvwjy', 'student', '2026-05-29 12:00:18', '2026-05-29 17:31:05', 'active', 0, 0, NULL, NULL, NULL, NULL, 5),
(11, 'Dixant  Choudhary', 'aryangtp@gmail.com', '$2y$10$bjzF6PNbQg1boGHHitpqeupHMKeloJ8I99N7YuG3ASGwlDCqjkkfC', 'student', '2026-06-02 09:11:30', '2026-06-03 13:15:22', 'active', 0, 0, NULL, NULL, NULL, NULL, 5),
(12, 'Saksham Verma', 'vermasaksham.06@gmail.com', '$2y$10$1NI2bLa4vaPyvGP8BafE.Oud3LKjl8Ho3AFHR0umCAtgIm3CNUiJ.', 'student', '2026-06-03 10:19:01', '2026-06-03 15:58:35', 'active', 0, 0, NULL, NULL, NULL, NULL, 5);

-- --------------------------------------------------------

--
-- Table structure for table `user_lock_logs`
--

CREATE TABLE `user_lock_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` enum('locked','unlocked') NOT NULL,
  `reason` text DEFAULT NULL,
  `performed_by` int(11) NOT NULL,
  `performed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `duration_days` int(11) DEFAULT NULL COMMENT 'For temporary locks',
  `expiry_date` date DEFAULT NULL COMMENT 'For temporary locks'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `weekly_feedback`
--

CREATE TABLE `weekly_feedback` (
  `id` int(11) NOT NULL,
  `batch_id` varchar(10) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `trainer_id` int(11) NOT NULL,
  `week_start_date` date NOT NULL,
  `week_end_date` date NOT NULL,
  `rating` tinyint(1) NOT NULL CHECK (`rating` between 1 and 5),
  `remarks` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `weekly_report_cards`
--

CREATE TABLE `weekly_report_cards` (
  `id` int(11) NOT NULL,
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
  `status` enum('draft','published','archived') DEFAULT 'draft'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workshops`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `workshop_attendance`
--

CREATE TABLE `workshop_attendance` (
  `id` int(11) NOT NULL,
  `workshop_id` varchar(10) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `session_id` int(11) DEFAULT NULL,
  `attendance_status` enum('present','absent','late') NOT NULL DEFAULT 'absent',
  `attended_at` datetime DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workshop_feedback`
--

CREATE TABLE `workshop_feedback` (
  `id` int(11) NOT NULL,
  `workshop_id` varchar(10) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `rating` tinyint(1) NOT NULL CHECK (`rating` between 1 and 5),
  `feedback_text` text DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `trainer_rating` tinyint(1) DEFAULT NULL CHECK (`trainer_rating` between 1 and 5),
  `content_rating` tinyint(1) DEFAULT NULL CHECK (`content_rating` between 1 and 5),
  `organization_rating` tinyint(1) DEFAULT NULL CHECK (`organization_rating` between 1 and 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workshop_materials`
--

CREATE TABLE `workshop_materials` (
  `id` int(11) NOT NULL,
  `workshop_id` varchar(10) NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` enum('slides','handout','exercise','recording','other') NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_public` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workshop_registrations`
--

CREATE TABLE `workshop_registrations` (
  `id` int(11) NOT NULL,
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
  `feedback_submitted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workshop_schedule`
--

CREATE TABLE `workshop_schedule` (
  `id` int(11) NOT NULL,
  `workshop_id` varchar(10) NOT NULL,
  `session_title` varchar(100) NOT NULL,
  `session_description` text DEFAULT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `trainer_id` int(11) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `is_break` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `batches`
--
ALTER TABLE `batches`
  ADD PRIMARY KEY (`batch_id`);

--
-- Indexes for table `batch_courses`
--
ALTER TABLE `batch_courses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `batch_terms_settings`
--
ALTER TABLE `batch_terms_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_batch` (`batch_id`),
  ADD KEY `fk_terms_updated_by` (`updated_by`);

--
-- Indexes for table `batch_uploads`
--
ALTER TABLE `batch_uploads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `certificate_templates`
--
ALTER TABLE `certificate_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `clear_chat_history`
--
ALTER TABLE `clear_chat_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_clear` (`conversation_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `conversations`
--
ALTER TABLE `conversations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `conversation_members`
--
ALTER TABLE `conversation_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_member` (`conversation_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `course_content_visibility`
--
ALTER TABLE `course_content_visibility`
  ADD PRIMARY KEY (`course_id`,`batch_id`);

--
-- Indexes for table `course_main_topics`
--
ALTER TABLE `course_main_topics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `course_sub_topics`
--
ALTER TABLE `course_sub_topics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_main_topic_id` (`course_main_topic_id`);

--
-- Indexes for table `doubts`
--
ALTER TABLE `doubts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `status` (`status`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `doubt_responses`
--
ALTER TABLE `doubt_responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `doubt_id` (`doubt_id`),
  ADD KEY `responded_by` (`responded_by`);

--
-- Indexes for table `exams`
--
ALTER TABLE `exams`
  ADD PRIMARY KEY (`exam_id`),
  ADD KEY `fk_exam_batch` (`batch_id`),
  ADD KEY `fk_exam_creator` (`created_by`);

--
-- Indexes for table `exam_enrollments`
--
ALTER TABLE `exam_enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_exam_student_enrollment` (`exam_id`,`student_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `enrolled_by` (`enrolled_by`);

--
-- Indexes for table `exam_results`
--
ALTER TABLE `exam_results`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_exam_student` (`exam_id`,`student_id`),
  ADD KEY `fk_result_student` (`student_id`),
  ADD KEY `fk_result_uploader` (`uploaded_by`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_show_to_trainer` (`show_to_trainer`),
  ADD KEY `idx_action_taken_time` (`action_taken_time`);

--
-- Indexes for table `feeds`
--
ALTER TABLE `feeds`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `feed_comments`
--
ALTER TABLE `feed_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `feed_id` (`feed_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `parent_comment_id` (`parent_comment_id`);

--
-- Indexes for table `feed_notifications`
--
ALTER TABLE `feed_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `feed_id` (`feed_id`);

--
-- Indexes for table `feed_reactions`
--
ALTER TABLE `feed_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_feed_reaction` (`feed_id`,`user_id`),
  ADD KEY `feed_id` (`feed_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `fee_installments`
--
ALTER TABLE `fee_installments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `due_date` (`due_date`),
  ADD KEY `payment_status` (`payment_status`);

--
-- Indexes for table `fee_reminder_logs`
--
ALTER TABLE `fee_reminder_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `leads`
--
ALTER TABLE `leads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `leave_applications`
--
ALTER TABLE `leave_applications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `application_no` (`application_no`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `status` (`status`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `fk_leave_approved_by` (`approved_by`),
  ADD KEY `fk_leave_rejected_by` (`rejected_by`);

--
-- Indexes for table `leave_application_history`
--
ALTER TABLE `leave_application_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `action_by` (`action_by`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `main_topics`
--
ALTER TABLE `main_topics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `batch_name` (`batch_name`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `conversation_id` (`conversation_id`),
  ADD KEY `sender_id` (`sender_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payment_modes`
--
ALTER TABLE `payment_modes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payment_verification_settings`
--
ALTER TABLE `payment_verification_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_batch` (`batch_id`);

--
-- Indexes for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `results_sync`
--
ALTER TABLE `results_sync`
  ADD PRIMARY KEY (`id`),
  ADD KEY `test_id` (`test_id`);

--
-- Indexes for table `schedule`
--
ALTER TABLE `schedule`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `semesters`
--
ALTER TABLE `semesters`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `semester_batches`
--
ALTER TABLE `semester_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`student_id`),
  ADD KEY `idx_students_status` (`current_status`),
  ADD KEY `idx_students_enrollment_date` (`enrollment_date`),
  ADD KEY `idx_students_sales_person` (`sales_person_id`),
  ADD KEY `idx_batch2` (`batch_name_2`),
  ADD KEY `idx_batch3` (`batch_name_3`);

--
-- Indexes for table `student_batch_history`
--
ALTER TABLE `student_batch_history`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_documents`
--
ALTER TABLE `student_documents`
  ADD PRIMARY KEY (`document_id`);

--
-- Indexes for table `student_status_log`
--
ALTER TABLE `student_status_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sub_topics`
--
ALTER TABLE `sub_topics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `main_topic_id` (`main_topic_id`);

--
-- Indexes for table `tests`
--
ALTER TABLE `tests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_test_batch` (`batch_id`),
  ADD KEY `fk_test_creator` (`created_by`);

--
-- Indexes for table `test_answers`
--
ALTER TABLE `test_answers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attempt_question` (`attempt_id`,`question_id`),
  ADD KEY `fk_answer_attempt` (`attempt_id`),
  ADD KEY `fk_answer_question` (`question_id`);

--
-- Indexes for table `test_attempts`
--
ALTER TABLE `test_attempts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_test_attempt` (`test_id`,`student_id`,`attempt_number`),
  ADD KEY `fk_attempt_test` (`test_id`),
  ADD KEY `fk_attempt_student` (`student_id`);

--
-- Indexes for table `test_questions`
--
ALTER TABLE `test_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_question_test` (`test_id`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `resolved_by` (`resolved_by`),
  ADD KEY `fk_tickets_batch` (`batch_id`);

--
-- Indexes for table `ticket_messages`
--
ALTER TABLE `ticket_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `sender_id` (`sender_id`);

--
-- Indexes for table `trainers`
--
ALTER TABLE `trainers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `trainer_documents`
--
ALTER TABLE `trainer_documents`
  ADD PRIMARY KEY (`document_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_id` (`transaction_id`),
  ADD KEY `fk_transaction_student` (`student_id`),
  ADD KEY `fk_transaction_batch` (`batch_id`),
  ADD KEY `fk_transaction_verifier` (`verified_by`),
  ADD KEY `idx_is_manual` (`is_manual`),
  ADD KEY `idx_receipt_no` (`receipt_no`);

--
-- Indexes for table `uploads`
--
ALTER TABLE `uploads`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_locked_by` (`locked_by`);

--
-- Indexes for table `user_lock_logs`
--
ALTER TABLE `user_lock_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_lock_log_user` (`user_id`),
  ADD KEY `fk_lock_log_performer` (`performed_by`);

--
-- Indexes for table `weekly_feedback`
--
ALTER TABLE `weekly_feedback`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_weekly_feedback` (`batch_id`,`student_id`,`trainer_id`,`week_start_date`),
  ADD KEY `fk_feedback_batch` (`batch_id`),
  ADD KEY `fk_feedback_student` (`student_id`),
  ADD KEY `fk_feedback_trainer` (`trainer_id`);

--
-- Indexes for table `weekly_report_cards`
--
ALTER TABLE `weekly_report_cards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_weekly_report` (`student_id`,`batch_id`,`week_start_date`),
  ADD KEY `fk_report_student` (`student_id`),
  ADD KEY `fk_report_batch` (`batch_id`),
  ADD KEY `fk_report_generator` (`generated_by`),
  ADD KEY `idx_week_date` (`week_start_date`,`week_end_date`);

--
-- Indexes for table `workshop_attendance`
--
ALTER TABLE `workshop_attendance`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `workshop_feedback`
--
ALTER TABLE `workshop_feedback`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `workshop_materials`
--
ALTER TABLE `workshop_materials`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `workshop_registrations`
--
ALTER TABLE `workshop_registrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `workshop_schedule`
--
ALTER TABLE `workshop_schedule`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `batch_courses`
--
ALTER TABLE `batch_courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `batch_terms_settings`
--
ALTER TABLE `batch_terms_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `batch_uploads`
--
ALTER TABLE `batch_uploads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `certificate_templates`
--
ALTER TABLE `certificate_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `clear_chat_history`
--
ALTER TABLE `clear_chat_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `conversations`
--
ALTER TABLE `conversations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `conversation_members`
--
ALTER TABLE `conversation_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `course_main_topics`
--
ALTER TABLE `course_main_topics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `course_sub_topics`
--
ALTER TABLE `course_sub_topics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `doubts`
--
ALTER TABLE `doubts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `doubt_responses`
--
ALTER TABLE `doubt_responses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_enrollments`
--
ALTER TABLE `exam_enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_results`
--
ALTER TABLE `exam_results`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feeds`
--
ALTER TABLE `feeds`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feed_comments`
--
ALTER TABLE `feed_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feed_notifications`
--
ALTER TABLE `feed_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feed_reactions`
--
ALTER TABLE `feed_reactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fee_installments`
--
ALTER TABLE `fee_installments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fee_reminder_logs`
--
ALTER TABLE `fee_reminder_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leads`
--
ALTER TABLE `leads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_applications`
--
ALTER TABLE `leave_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_application_history`
--
ALTER TABLE `leave_application_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `main_topics`
--
ALTER TABLE `main_topics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=116;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `payment_modes`
--
ALTER TABLE `payment_modes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_verification_settings`
--
ALTER TABLE `payment_verification_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `results_sync`
--
ALTER TABLE `results_sync`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schedule`
--
ALTER TABLE `schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=112;

--
-- AUTO_INCREMENT for table `semesters`
--
ALTER TABLE `semesters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `semester_batches`
--
ALTER TABLE `semester_batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_batch_history`
--
ALTER TABLE `student_batch_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_documents`
--
ALTER TABLE `student_documents`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_status_log`
--
ALTER TABLE `student_status_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sub_topics`
--
ALTER TABLE `sub_topics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `tests`
--
ALTER TABLE `tests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `test_answers`
--
ALTER TABLE `test_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `test_attempts`
--
ALTER TABLE `test_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `test_questions`
--
ALTER TABLE `test_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `ticket_messages`
--
ALTER TABLE `ticket_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `trainers`
--
ALTER TABLE `trainers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trainer_documents`
--
ALTER TABLE `trainer_documents`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `uploads`
--
ALTER TABLE `uploads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=211;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `user_lock_logs`
--
ALTER TABLE `user_lock_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `weekly_feedback`
--
ALTER TABLE `weekly_feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `weekly_report_cards`
--
ALTER TABLE `weekly_report_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `workshop_attendance`
--
ALTER TABLE `workshop_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `workshop_feedback`
--
ALTER TABLE `workshop_feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `workshop_materials`
--
ALTER TABLE `workshop_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `workshop_registrations`
--
ALTER TABLE `workshop_registrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `workshop_schedule`
--
ALTER TABLE `workshop_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `batch_terms_settings`
--
ALTER TABLE `batch_terms_settings`
  ADD CONSTRAINT `fk_terms_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `batch_uploads`
--
ALTER TABLE `batch_uploads`
  ADD CONSTRAINT `batch_uploads_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `clear_chat_history`
--
ALTER TABLE `clear_chat_history`
  ADD CONSTRAINT `clear_chat_history_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `clear_chat_history_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `conversations`
--
ALTER TABLE `conversations`
  ADD CONSTRAINT `conversations_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `conversation_members`
--
ALTER TABLE `conversation_members`
  ADD CONSTRAINT `conversation_members_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `conversation_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `course_main_topics`
--
ALTER TABLE `course_main_topics`
  ADD CONSTRAINT `fk_cmt_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `course_sub_topics`
--
ALTER TABLE `course_sub_topics`
  ADD CONSTRAINT `fk_cst_main_topic` FOREIGN KEY (`course_main_topic_id`) REFERENCES `course_main_topics` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `doubt_responses`
--
ALTER TABLE `doubt_responses`
  ADD CONSTRAINT `doubt_responses_ibfk_1` FOREIGN KEY (`doubt_id`) REFERENCES `doubts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `doubt_responses_ibfk_2` FOREIGN KEY (`responded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `feeds`
--
ALTER TABLE `feeds`
  ADD CONSTRAINT `feeds_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `feed_comments`
--
ALTER TABLE `feed_comments`
  ADD CONSTRAINT `feed_comments_ibfk_1` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `feed_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `feed_comments_ibfk_3` FOREIGN KEY (`parent_comment_id`) REFERENCES `feed_comments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `feed_notifications`
--
ALTER TABLE `feed_notifications`
  ADD CONSTRAINT `feed_notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `feed_notifications_ibfk_2` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `feed_reactions`
--
ALTER TABLE `feed_reactions`
  ADD CONSTRAINT `feed_reactions_ibfk_1` FOREIGN KEY (`feed_id`) REFERENCES `feeds` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `feed_reactions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD CONSTRAINT `remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `results_sync`
--
ALTER TABLE `results_sync`
  ADD CONSTRAINT `results_sync_ibfk_1` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `fk_students_sales_person` FOREIGN KEY (`sales_person_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sub_topics`
--
ALTER TABLE `sub_topics`
  ADD CONSTRAINT `fk_sub_topics_main` FOREIGN KEY (`main_topic_id`) REFERENCES `main_topics` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `test_answers`
--
ALTER TABLE `test_answers`
  ADD CONSTRAINT `fk_answer_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `test_attempts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_answer_question` FOREIGN KEY (`question_id`) REFERENCES `test_questions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `test_attempts`
--
ALTER TABLE `test_attempts`
  ADD CONSTRAINT `fk_attempt_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_attempt_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `test_questions`
--
ALTER TABLE `test_questions`
  ADD CONSTRAINT `fk_question_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tickets`
--
ALTER TABLE `tickets`
  ADD CONSTRAINT `fk_tickets_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`batch_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tickets_ibfk_2` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ticket_messages`
--
ALTER TABLE `ticket_messages`
  ADD CONSTRAINT `ticket_messages_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_lock_logs`
--
ALTER TABLE `user_lock_logs`
  ADD CONSTRAINT `fk_lock_log_performer` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lock_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
