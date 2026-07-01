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
