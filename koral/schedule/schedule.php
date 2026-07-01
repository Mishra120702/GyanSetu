<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
$batch_id = isset($_GET['batch_id']) ? $_GET['batch_id'] : null;

if (!$batch_id) {
    header("Location: ../batch_list.php");
    exit();
}

// Get filter parameter (all, upcoming, past)
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'upcoming';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get batch details
    $stmt = $conn->prepare("SELECT * FROM batches WHERE batch_id = ?");
    $stmt->execute([$batch_id]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$batch) {
        header("Location: ../batch_list.php");
        exit();
    }
    
    $today = date('Y-m-d');
    
    // Get month and year from URL or use current
    $currentYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
    $currentMonth = isset($_GET['month']) ? intval($_GET['month']) : date('m');
    
    // Build date range for the selected month
    $monthStart = "$currentYear-$currentMonth-01";
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    
    // Build WHERE clauses based on filter AND selected month
    $classWhereClause = "batch_id = ? AND schedule_date BETWEEN ? AND ?";
    $examWhereClause = "batch_id = ? AND exam_date BETWEEN ? AND ?";
    $workshopWhereClause = "start_datetime BETWEEN ? AND ?";
    
    $classParams = [$batch_id, $monthStart, $monthEnd];
    $examParams = [$batch_id, $monthStart, $monthEnd];
    $workshopParams = [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59'];
    
    // Get classes for the selected month
    $stmt = $conn->prepare("SELECT * FROM schedule WHERE $classWhereClause ORDER BY schedule_date, start_time");
    $stmt->execute($classParams);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get exams for the selected month
    $stmt = $conn->prepare("SELECT * FROM exams WHERE $examWhereClause ORDER BY exam_date");
    $stmt->execute($examParams);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get workshops for the selected month
    $stmt = $conn->prepare("
        SELECT w.* FROM workshops w 
        WHERE $workshopWhereClause
        ORDER BY start_datetime
    ");
    $stmt->execute($workshopParams);
    $workshops = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // For counting all events (past and upcoming) for stats
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM schedule WHERE batch_id = ?");
    $stmt->execute([$batch_id]);
    $totalClasses = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM exams WHERE batch_id = ?");
    $stmt->execute([$batch_id]);
    $totalExams = $stmt->fetchColumn();
    
    // Count upcoming events for stats
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM schedule WHERE batch_id = ? AND schedule_date >= ?");
    $stmt->execute([$batch_id, $today]);
    $upcomingClasses = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM exams WHERE batch_id = ? AND exam_date >= ?");
    $stmt->execute([$batch_id, $today]);
    $upcomingExams = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM workshops WHERE status = 'upcoming' AND start_datetime >= ?");
    $stmt->execute([$today . ' 00:00:00']);
    $upcomingWorkshops = $stmt->fetchColumn();
    
    // Count past events for stats
    $pastClasses = $totalClasses - $upcomingClasses;
    $pastExams = $totalExams - $upcomingExams;
    
    // Handle Excel upload
    $uploadMessage = '';
    $uploadError = '';
    $uploadPreview = [];
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        // ... (keep the existing Excel upload code)
    }
    
    // Combine all events into a single array
    $allEvents = [];
    
    // Add classes
    foreach ($classes as $class) {
        $is_past = $class['schedule_date'] < $today;
        $allEvents[] = [
            'id' => $class['id'],
            'title' => $class['topic'],
            'date' => $class['schedule_date'],
            'start_time' => $class['start_time'],
            'end_time' => $class['end_time'],
            'description' => $class['description'],
            'type' => 'class',
            'is_cancelled' => $class['is_cancelled'],
            'cancellation_reason' => $class['cancellation_reason'],
            'is_past' => $is_past,
            'color' => $class['is_cancelled'] ? 'red' : ($is_past ? 'gray' : 'blue')
        ];
    }
    
    // Add exams
    foreach ($exams as $exam) {
        $is_past = $exam['exam_date'] < $today;
        $allEvents[] = [
            'id' => $exam['exam_id'],
            'title' => $exam['exam_name'] . ' (' . $exam['subject'] . ')',
            'date' => $exam['exam_date'],
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'description' => 'Exam Type: ' . $exam['exam_type'] . 
                           ($exam['description'] ? ' / Description: ' . $exam['description'] : ''),
            'type' => 'exam',
            'total_marks' => $exam['total_marks'],
            'exam_type' => $exam['exam_type'],
            'is_past' => $is_past,
            'color' => $is_past ? 'gray' : 'purple'
        ];
    }
    
    // Add workshops
    foreach ($workshops as $workshop) {
        $workshopDate = date('Y-m-d', strtotime($workshop['start_datetime']));
        $is_past = strtotime($workshop['start_datetime']) < strtotime($today . ' 00:00:00');
        $allEvents[] = [
            'id' => $workshop['workshop_id'],
            'title' => $workshop['title'],
            'date' => $workshopDate,
            'start_time' => date('H:i:s', strtotime($workshop['start_datetime'])),
            'end_time' => date('H:i:s', strtotime($workshop['end_datetime'])),
            'description' => $workshop['description'] . 
                           '<br>Level: ' . $workshop['difficulty_level'] . 
                           '<br>Fee: $' . $workshop['fee'],
            'type' => 'workshop',
            'location' => $workshop['location'],
            'difficulty_level' => $workshop['difficulty_level'],
            'is_past' => $is_past,
            'status' => $workshop['status'],
            'color' => $is_past ? 'gray' : 'green'
        ];
    }
    
    // Sort all events by date
    usort($allEvents, function($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    
    // Group events by date for calendar
    $eventsByDate = [];
    foreach ($allEvents as $event) {
        $date = $event['date'];
        if (!isset($eventsByDate[$date])) {
            $eventsByDate[$date] = [];
        }
        $eventsByDate[$date][] = $event;
    }
    
    // Handle delete request for classes only
    if (isset($_GET['delete_id'])) {
        $delete_id = $_GET['delete_id'];
        $stmt = $conn->prepare("DELETE FROM schedule WHERE id = ? AND batch_id = ?");
        $stmt->execute([$delete_id, $batch_id]);
        header("Location: schedule.php?batch_id=" . $batch_id . "&filter=" . $filter . "&month=" . $currentMonth . "&year=" . $currentYear);
        exit();
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Function to generate calendar days
function generateCalendarDays($year, $month, $eventsByDate) {
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $firstDayOfMonth = date('N', strtotime("$year-$month-01"));
    $calendarDays = [];
    $today = date('Y-m-d');
    
    // Add empty cells for days before the first day of the month
    for ($i = 1; $i < $firstDayOfMonth; $i++) {
        $calendarDays[] = [
            'day' => '', 
            'events' => [], 
            'has_events' => false,
            'has_upcoming' => false,
            'has_past' => false,
            'has_cancelled' => false,
            'has_class' => false,
            'has_exam' => false,
            'has_workshop' => false
        ];
    }
    
    // Add days of the month
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $dayEvents = isset($eventsByDate[$date]) ? $eventsByDate[$date] : [];
        
        $hasClass = false;
        $hasExam = false;
        $hasWorkshop = false;
        $hasUpcoming = false;
        $hasPast = false;
        $hasCancelled = false;
        
        foreach ($dayEvents as $event) {
            if ($event['type'] === 'class') {
                $hasClass = true;
                if ($event['is_cancelled']) $hasCancelled = true;
            }
            if ($event['type'] === 'exam') $hasExam = true;
            if ($event['type'] === 'workshop') $hasWorkshop = true;
            
            if (!$event['is_past'] && !$event['is_cancelled']) {
                $hasUpcoming = true;
            }
            if ($event['is_past']) {
                $hasPast = true;
            }
        }
        
        $isToday = $date === $today;
        $isPast = $date < $today;
        $isSunday = date('N', strtotime($date)) == 7;
        $isSaturday = date('N', strtotime($date)) == 6;
        
        $calendarDays[] = [
            'day' => $day,
            'date' => $date,
            'events' => $dayEvents,
            'isToday' => $isToday,
            'isPast' => $isPast,
            'isSunday' => $isSunday,
            'isSaturday' => $isSaturday,
            'has_events' => !empty($dayEvents),
            'has_upcoming' => $hasUpcoming,
            'has_past' => $hasPast,
            'has_cancelled' => $hasCancelled,
            'has_class' => $hasClass,
            'has_exam' => $hasExam,
            'has_workshop' => $hasWorkshop
        ];
    }
    
    return $calendarDays;
}

$calendarDays = generateCalendarDays($currentYear, $currentMonth, $eventsByDate);

// Navigation links for previous/next month
$prevMonth = $currentMonth - 1;
$prevYear = $currentYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $currentMonth + 1;
$nextYear = $currentYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// Count events by type
$classCount = count($classes);
$examCount = count($exams);
$workshopCount = count($workshops);
$totalEvents = $classCount + $examCount + $workshopCount;
$totalAllEvents = $totalClasses + $totalExams + $upcomingWorkshops;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule | <?= htmlspecialchars($batch['batch_id']) ?> | ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Poppins', sans-serif;
        }
        
        .calendar-day {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            min-height: 120px;
            cursor: pointer;
        }
        
        .calendar-day:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .calendar-day.today {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(59, 130, 246, 0.2) 100%);
            border: 2px solid rgba(59, 130, 246, 0.3);
        }
        
        .calendar-day.past-day {
            background-color: rgba(243, 244, 246, 0.5);
        }
        
        .calendar-day.sunday {
            background: linear-gradient(135deg, rgba(236, 72, 153, 0.05) 0%, rgba(236, 72, 153, 0.1) 100%);
        }
        
        .calendar-day.saturday {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.05) 0%, rgba(139, 92, 246, 0.1) 100%);
        }
        
        .calendar-day.has-events::after {
            content: '';
            position: absolute;
            top: 4px;
            right: 4px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #3b82f6;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }
        
        .calendar-day.has-class::after { background-color: #3b82f6; }
        .calendar-day.has-exam::after { background-color: #8b5cf6; }
        .calendar-day.has-workshop::after { background-color: #10b981; }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .slide-in {
            animation: slideIn 0.4s ease-out;
        }
        
        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .pulse-glow {
            animation: pulseGlow 2s infinite;
        }
        
        @keyframes pulseGlow {
            0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); }
            100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
        }
        
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .stat-card {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 100%);
            z-index: 1;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .calendar-nav-btn {
            transition: all 0.2s ease;
        }
        
        .calendar-nav-btn:hover {
            background-color: #f3f4f6;
            transform: scale(1.05);
        }
        
        .event-badge {
            transition: all 0.2s ease;
        }
        
        .event-badge:hover {
            transform: scale(1.05);
        }
        
        .event-item {
            transition: all 0.2s ease;
        }
        
        .event-item:hover {
            transform: scale(1.02);
        }
        
        .event-type-badge {
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .event-class { background-color: #dbeafe; color: #1e40af; }
        .event-exam { background-color: #f3e8ff; color: #6b21a8; }
        .event-workshop { background-color: #dcfce7; color: #166534; }
        .event-past { background-color: #f3f4f6; color: #6b7280; }
        
        .event-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        
        .dot-class { background-color: #3b82f6; }
        .dot-exam { background-color: #8b5cf6; }
        .dot-workshop { background-color: #10b981; }
        .dot-cancelled { background-color: #ef4444; }
        .dot-past { background-color: #9ca3af; }
        .dot-saturday { background-color: #8b5cf6; }
        .dot-sunday { background-color: #ec4899; }
        
        .filter-tab {
            transition: all 0.3s ease;
        }
        
        .filter-tab.active {
            background-color: #3b82f6;
            color: white;
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);
        }
        
        .past-event {
            opacity: 0.8;
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            background-color: white;
            border-radius: 1rem;
            max-width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease-out;
        }
        
        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .event-indicator {
            position: absolute;
            bottom: 4px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 2px;
        }
        
        .event-dot-small {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }
        
        .dot-class-small { background-color: #3b82f6; }
        .dot-exam-small { background-color: #8b5cf6; }
        .dot-workshop-small { background-color: #10b981; }
        .dot-past-small { background-color: #9ca3af; }
        
        .ripple {
            position: absolute;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.7);
            transform: scale(0);
            animation: ripple-animation 0.6s linear;
        }
        
        @keyframes ripple-animation {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
        
        a, button {
            position: relative;
            overflow: hidden;
        }
        
        .add-schedule-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: all 0.3s ease;
        }
        
        .add-schedule-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .date-picker-day {
            transition: all 0.2s ease;
        }
        
        .date-picker-day:hover {
            transform: scale(1.1);
        }
        
        .date-picker-day.selected {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php
    include '../header.php';
    include '../sidebar.php';
    ?>
    
    <div class="ml-64 p-6 fade-in">
        <div class="max-w-7xl mx-auto">
            <!-- Back button -->
            <a href="../batch/batch_view.php?batch_id=<?= $batch_id ?>" class="inline-flex items-center text-blue-600 hover:text-blue-800 mb-6 transition-all duration-300 slide-in">
                <i class="fas fa-arrow-left mr-2"></i> Back to Batch
            </a>
            
            <!-- Header -->
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">Schedule & Events</h1>
                    <p class="text-gray-600 mt-1">Batch: <?= htmlspecialchars($batch['batch_id']) ?> - <?= htmlspecialchars($batch['batch_name']) ?></p>
                    <p class="text-sm text-gray-500 mt-1">
                        <i class="far fa-calendar-alt mr-1"></i>
                        Showing schedule for <?= date('F Y', strtotime("$currentYear-$currentMonth-01")) ?>
                    </p>
                </div>
                <div class="flex space-x-3">
                    <a href="upload_schedule.php?batch_id=<?= $batch_id ?>" class="add-schedule-btn px-5 py-3 text-white font-medium rounded-lg transition-all duration-300 flex items-center shadow-md hover:shadow-lg pulse-glow">
                        <i class="fas fa-plus mr-2"></i> Upload Schedule
                    </a>
                    <a href="add_event.php?batch_id=<?= $batch_id ?>" class="px-5 py-3 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-all duration-300 flex items-center shadow-md hover:shadow-lg">
                        <i class="fas fa-calendar-plus mr-2"></i> Add New Event
                    </a>
                    <button onclick="openExcelModal()" class="px-5 py-3 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-all duration-300 flex items-center shadow-md hover:shadow-lg">
                        <i class="fas fa-file-excel mr-2"></i> Import Excel
                    </button>
                </div>
            </div>
            
            <!-- Quick Add Schedule Modal -->
            <div id="addScheduleModal" class="modal-overlay hidden">
                <div class="modal-content w-full max-w-md">
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-2xl font-bold text-gray-800">Add Schedule</h2>
                            <button onclick="closeAddScheduleModal()" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>
                        
                        <form action="add_event.php" method="GET" id="quickAddForm">
                            <input type="hidden" name="batch_id" value="<?= $batch_id ?>">
                            <input type="hidden" name="date" id="selectedDate" value="">
                            
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Selected Date</label>
                                <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                    <p class="text-blue-800 font-medium" id="selectedDateDisplay"><?= date('F j, Y') ?></p>
                                </div>
                            </div>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Event Type</label>
                                <div class="grid grid-cols-3 gap-3">
                                    <button type="button" onclick="selectEventType('class')" class="event-type-option p-3 border-2 border-blue-200 rounded-lg text-center hover:bg-blue-50 transition-all" data-type="class">
                                        <i class="fas fa-chalkboard-teacher text-blue-600 text-xl mb-1"></i>
                                        <p class="text-xs font-medium text-gray-700">Class</p>
                                    </button>
                                    <button type="button" onclick="selectEventType('exam')" class="event-type-option p-3 border-2 border-purple-200 rounded-lg text-center hover:bg-purple-50 transition-all" data-type="exam">
                                        <i class="fas fa-file-alt text-purple-600 text-xl mb-1"></i>
                                        <p class="text-xs font-medium text-gray-700">Exam</p>
                                    </button>
                                    <button type="button" onclick="selectEventType('workshop')" class="event-type-option p-3 border-2 border-green-200 rounded-lg text-center hover:bg-green-50 transition-all" data-type="workshop">
                                        <i class="fas fa-wrench text-green-600 text-xl mb-1"></i>
                                        <p class="text-xs font-medium text-gray-700">Workshop</p>
                                    </button>
                                </div>
                                <input type="hidden" name="type" id="selectedEventType" value="">
                            </div>
                            
                            <div class="flex justify-end space-x-3">
                                <button type="button" onclick="closeAddScheduleModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                                    Cancel
                                </button>
                                <button type="submit" id="proceedBtn" disabled class="px-4 py-2 bg-gray-400 text-white rounded-lg cursor-not-allowed" onclick="return proceedToAdd()">
                                    Proceed
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Excel Import Modal (keep existing) -->
            <div id="excelModal" class="modal-overlay hidden">
                <!-- ... keep existing Excel modal content ... -->
            </div>
            
            <!-- Filter Tabs -->
            <div class="bg-white rounded-xl shadow-md p-4 mb-6">
                <div class="flex space-x-2">
                    <a href="schedule.php?batch_id=<?= $batch_id ?>&filter=upcoming&month=<?= $currentMonth ?>&year=<?= $currentYear ?>" 
                       class="filter-tab px-4 py-2 rounded-lg text-sm font-medium <?= $filter === 'upcoming' ? 'active bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                        <i class="fas fa-calendar-day mr-2"></i>Upcoming Events
                        <span class="ml-1 px-2 py-0.5 text-xs bg-blue-100 text-blue-800 rounded-full"><?= $upcomingClasses + $upcomingExams + $upcomingWorkshops ?></span>
                    </a>
                    <a href="schedule.php?batch_id=<?= $batch_id ?>&filter=past&month=<?= $currentMonth ?>&year=<?= $currentYear ?>" 
                       class="filter-tab px-4 py-2 rounded-lg text-sm font-medium <?= $filter === 'past' ? 'active bg-gray-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                        <i class="fas fa-history mr-2"></i>Past Events
                        <span class="ml-1 px-2 py-0.5 text-xs bg-gray-100 text-gray-800 rounded-full"><?= $pastClasses + $pastExams ?></span>
                    </a>
                    <a href="schedule.php?batch_id=<?= $batch_id ?>&filter=all&month=<?= $currentMonth ?>&year=<?= $currentYear ?>" 
                       class="filter-tab px-4 py-2 rounded-lg text-sm font-medium <?= $filter === 'all' ? 'active bg-purple-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                        <i class="fas fa-calendar-alt mr-2"></i>All Events
                        <span class="ml-1 px-2 py-0.5 text-xs bg-purple-100 text-purple-800 rounded-full"><?= $totalAllEvents ?></span>
                    </a>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="stat-card bg-white rounded-xl shadow-md p-5 flex items-center">
                    <div class="p-3 rounded-full bg-blue-100 text-blue-600 mr-4">
                        <i class="fas fa-calendar-alt text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Total Events</h3>
                        <p class="text-2xl font-bold text-gray-900"><?= $totalEvents ?></p>
                        <p class="text-xs text-gray-500 mt-1">for <?= date('F Y', strtotime("$currentYear-$currentMonth-01")) ?></p>
                    </div>
                </div>
                
                <div class="stat-card bg-white rounded-xl shadow-md p-5 flex items-center">
                    <div class="p-3 rounded-full bg-blue-100 text-blue-600 mr-4">
                        <i class="fas fa-chalkboard-teacher text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Classes</h3>
                        <p class="text-2xl font-bold text-gray-900"><?= $classCount ?></p>
                    </div>
                </div>
                
                <div class="stat-card bg-white rounded-xl shadow-md p-5 flex items-center">
                    <div class="p-3 rounded-full bg-purple-100 text-purple-600 mr-4">
                        <i class="fas fa-file-alt text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Exams</h3>
                        <p class="text-2xl font-bold text-gray-900"><?= $examCount ?></p>
                    </div>
                </div>
                
                <div class="stat-card bg-white rounded-xl shadow-md p-5 flex items-center">
                    <div class="p-3 rounded-full bg-green-100 text-green-600 mr-4">
                        <i class="fas fa-wrench text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Workshops</h3>
                        <p class="text-2xl font-bold text-gray-900"><?= $workshopCount ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Calendar View -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-8 transition-all duration-300 hover:shadow-lg">
                <div class="flex justify-between items-center mb-6">
                    <div class="flex items-center">
                        <div class="p-2 rounded-lg bg-gradient-to-r from-purple-500 to-indigo-600 text-white mr-3">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <h2 class="text-xl font-semibold text-gray-800"><?= date('F Y', strtotime("$currentYear-$currentMonth-01")) ?></h2>
                    </div>
                    <div class="flex space-x-2">
                        <a href="schedule.php?batch_id=<?= $batch_id ?>&filter=<?= $filter ?>&month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="calendar-nav-btn px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-all duration-200">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                        <a href="schedule.php?batch_id=<?= $batch_id ?>&filter=<?= $filter ?>&month=<?= date('m') ?>&year=<?= date('Y') ?>" class="calendar-nav-btn px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-all duration-200">
                            <i class="fas fa-calendar-day mr-1"></i> Today
                        </a>
                        <a href="schedule.php?batch_id=<?= $batch_id ?>&filter=<?= $filter ?>&month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="calendar-nav-btn px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-all duration-200">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
                
                <div class="grid grid-cols-7 gap-2 mb-2">
                    <!-- Weekday headers -->
                    <?php 
                    $weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                    foreach ($weekdays as $index => $day): 
                    ?>
                        <div class="bg-gradient-to-r from-gray-100 to-gray-50 py-3 text-center text-sm font-semibold <?= $index >= 5 ? 'text-purple-700' : 'text-gray-700' ?> rounded-lg">
                            <?= $day ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="grid grid-cols-7 gap-2">
                    <!-- Calendar days -->
                    <?php foreach ($calendarDays as $day): ?>
                        <div class="calendar-day bg-white p-3 border border-gray-200 rounded-lg 
                            <?= $day['isToday'] ? 'today' : '' ?> 
                            <?= $day['isPast'] ? 'past-day' : '' ?> 
                            <?= $day['isSunday'] ? 'sunday' : '' ?> 
                            <?= $day['isSaturday'] ? 'saturday' : '' ?> 
                            <?= $day['has_events'] ? 'has-events' : '' ?>
                            <?= $day['has_class'] ? 'has-class' : '' ?>
                            <?= $day['has_exam'] ? 'has-exam' : '' ?>
                            <?= $day['has_workshop'] ? 'has-workshop' : '' ?>"
                            onclick="openAddScheduleModal('<?= $day['date'] ?>')">
                            <?php if ($day['day'] !== ''): ?>
                                <div class="flex justify-between items-start mb-2">
                                    <div class="text-lg font-medium 
                                        <?= $day['isToday'] ? 'text-blue-600' : 
                                           ($day['isSunday'] ? 'text-pink-600' : 
                                           ($day['isSaturday'] ? 'text-purple-600' : 
                                           ($day['isPast'] ? 'text-gray-400' : 'text-gray-700'))) ?>">
                                        <?= $day['day'] ?>
                                    </div>
                                    <?php if ($day['isToday']): ?>
                                        <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs font-medium rounded-full animate-pulse">Today</span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($day['events'])): ?>
                                    <div class="space-y-1 max-h-20 overflow-y-auto scrollbar-thin">
                                        <?php foreach (array_slice($day['events'], 0, 3) as $event): ?>
                                            <div class="text-xs p-2 bg-<?= $event['color'] ?>-100 text-<?= $event['color'] ?>-800 border-l-2 border-<?= $event['color'] ?>-500 rounded cursor-pointer transition-all duration-200 hover:shadow-sm <?= $event['is_past'] ? 'opacity-80' : '' ?>" 
                                                 onclick="eventClickHandler(event, '<?= $event['type'] ?>', '<?= $event['id'] ?>')"
                                                 title="<?= htmlspecialchars($event['title']) ?>">
                                                <div class="flex items-center">
                                                    <span class="event-dot dot-<?= $event['is_past'] ? 'past' : $event['color'] ?>"></span>
                                                    <div class="font-medium truncate flex-1">
                                                        <?= htmlspecialchars(substr($event['title'], 0, 15)) ?>...
                                                    </div>
                                                </div>
                                                <div class="text-xs mt-1 opacity-75">
                                                    <?= date('g:i A', strtotime($event['start_time'])) ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (count($day['events']) > 3): ?>
                                            <div class="text-xs text-center text-gray-500 mt-1">
                                                +<?= count($day['events']) - 3 ?> more
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="h-16 flex items-center justify-center opacity-0 hover:opacity-100 transition-opacity">
                                        <i class="fas fa-plus-circle text-gray-300 text-xl"></i>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Calendar Legend -->
                <div class="mt-6 flex flex-wrap gap-6 text-sm">
                    <div class="flex items-center">
                        <span class="event-dot dot-class mr-2"></span>
                        <span class="text-gray-600">Class</span>
                    </div>
                    <div class="flex items-center">
                        <span class="event-dot dot-exam mr-2"></span>
                        <span class="text-gray-600">Exam</span>
                    </div>
                    <div class="flex items-center">
                        <span class="event-dot dot-workshop mr-2"></span>
                        <span class="text-gray-600">Workshop</span>
                    </div>
                    <div class="flex items-center">
                        <span class="event-dot dot-past mr-2"></span>
                        <span class="text-gray-600">Past</span>
                    </div>
                    <div class="flex items-center">
                        <span class="event-dot dot-cancelled mr-2"></span>
                        <span class="text-gray-600">Cancelled</span>
                    </div>
                    <div class="flex items-center">
                        <span class="event-dot dot-saturday mr-2"></span>
                        <span class="text-gray-600">Saturday</span>
                    </div>
                    <div class="flex items-center">
                        <span class="event-dot dot-sunday mr-2"></span>
                        <span class="text-gray-600">Sunday</span>
                    </div>
                </div>
            </div>
            
            <!-- Events List -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-8 transition-all duration-300 hover:shadow-lg">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center">
                        <div class="p-2 rounded-lg bg-gradient-to-r from-blue-500 to-indigo-600 text-white mr-3">
                            <i class="fas fa-list-ul"></i>
                        </div>
                        <h2 class="text-xl font-semibold text-gray-800">
                            Events for <?= date('F Y', strtotime("$currentYear-$currentMonth-01")) ?>
                        </h2>
                    </div>
                    <div class="text-sm text-gray-500">
                        <?= count($allEvents) ?> events found
                    </div>
                </div>
                
                <?php if (count($allEvents) > 0): ?>
                    <div class="space-y-4">
                        <?php 
                        $currentDate = null;
                        foreach ($allEvents as $event): 
                            $eventDate = date('l, F j, Y', strtotime($event['date']));
                            if ($eventDate !== $currentDate):
                                $currentDate = $eventDate;
                        ?>
                            <div class="border-l-4 border-indigo-400 pl-4 mb-4 fade-in">
                                <h3 class="text-lg font-semibold text-gray-800">
                                    <?= $eventDate ?>
                                    <?php if ($event['date'] == date('Y-m-d')): ?>
                                        <span class="ml-2 px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full">Today</span>
                                    <?php endif; ?>
                                </h3>
                            </div>
                        <?php endif; ?>
                        
                        <div class="event-item border-l-4 <?= 'border-' . $event['color'] . '-500' ?> bg-<?= $event['color'] ?>-50 pl-5 py-4 rounded-r-lg transition-all duration-300 hover:shadow-md <?= $event['is_past'] ? 'past-event' : '' ?> ml-4 mb-3">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2 flex-wrap gap-2">
                                        <h4 class="font-semibold text-gray-900 text-base"><?= htmlspecialchars($event['title']) ?></h4>
                                        <span class="event-type-badge <?= $event['is_past'] ? 'event-past' : 'event-' . $event['type'] ?>">
                                            <?= ucfirst($event['type']) ?>
                                        </span>
                                        <?php if (isset($event['is_cancelled']) && $event['is_cancelled']): ?>
                                            <span class="px-2 py-1 bg-red-100 text-red-800 text-xs font-medium rounded-full">Cancelled</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex flex-wrap items-center text-sm text-gray-600 gap-4">
                                        <div class="flex items-center">
                                            <i class="far fa-clock mr-1 <?= 'text-' . $event['color'] . '-500' ?>"></i>
                                            <?= date('g:i A', strtotime($event['start_time'])) ?> - <?= date('g:i A', strtotime($event['end_time'])) ?>
                                        </div>
                                        <?php if ($event['type'] == 'exam' && isset($event['total_marks'])): ?>
                                            <div class="flex items-center">
                                                <i class="fas fa-chart-bar mr-1 text-purple-500"></i>
                                                Marks: <?= $event['total_marks'] ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($event['type'] == 'workshop' && isset($event['location'])): ?>
                                            <div class="flex items-center">
                                                <i class="fas fa-map-marker-alt mr-1 text-green-500"></i>
                                                <?= htmlspecialchars($event['location']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($event['description'])): ?>
                                        <div class="text-sm text-gray-500 mt-2">
                                            <?= strip_tags(substr($event['description'], 0, 100)) ?>...
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex space-x-2 ml-4">
                                    <?php if ($event['type'] == 'class'): ?>
                                        <a href="edit_event.php?id=<?= $event['id'] ?>" class="p-2 text-blue-600 hover:bg-blue-100 rounded-full transition-colors" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="schedule.php?batch_id=<?= $batch_id ?>&delete_id=<?= $event['id'] ?>&filter=<?= $filter ?>&month=<?= $currentMonth ?>&year=<?= $currentYear ?>" 
                                           class="p-2 text-red-600 hover:bg-red-100 rounded-full transition-colors" 
                                           title="Delete" 
                                           onclick="return confirm('Are you sure you want to delete this class?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    <?php elseif ($event['type'] == 'exam'): ?>
                                        <a href="../exam/exam_details.php?exam_id=<?= $event['id'] ?>" class="p-2 text-purple-600 hover:bg-purple-100 rounded-full transition-colors" title="View Exam">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    <?php elseif ($event['type'] == 'workshop'): ?>
                                        <a href="../workshop/workshop_view.php?id=<?= $event['id'] ?>" class="p-2 text-green-600 hover:bg-green-100 rounded-full transition-colors" title="View Workshop">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-10">
                        <div class="mb-4">
                            <i class="fas fa-calendar-times text-gray-300 text-5xl"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-500 mb-2">No events found for <?= date('F Y', strtotime("$currentYear-$currentMonth-01")) ?></h3>
                        <p class="text-gray-400 text-sm mb-4">Click on a date in the calendar to add a new event</p>
                        <button onclick="openAddScheduleModal()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-all duration-300">
                            <i class="fas fa-plus mr-2"></i> Add First Event
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Add animation to elements when they come into view
        document.addEventListener('DOMContentLoaded', function() {
            // Animate stat cards with staggered delay
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });
            
            // Add click event to calendar days
            const calendarDays = document.querySelectorAll('.calendar-day');
            calendarDays.forEach(day => {
                day.addEventListener('mouseenter', function() {
                    this.style.zIndex = '10';
                });
                
                day.addEventListener('mouseleave', function() {
                    this.style.zIndex = '1';
                });
            });
            
            // Initialize modal close on overlay click
            const modals = document.querySelectorAll('.modal-overlay');
            modals.forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.add('hidden');
                        document.body.style.overflow = 'auto';
                    }
                });
            });
            
            // Show success message if redirected with upload_success parameter
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('upload_success')) {
                showToast('Events imported successfully!', 'success');
                // Remove the parameter from URL without reloading
                const newUrl = window.location.pathname + '?batch_id=<?= $batch_id ?>&filter=<?= $filter ?>&month=<?= $currentMonth ?>&year=<?= $currentYear ?>';
                window.history.replaceState({}, document.title, newUrl);
            }
        });
        
        // Quick Add Schedule Modal Functions
        function openAddScheduleModal(date = null) {
            const modal = document.getElementById('addScheduleModal');
            const selectedDateInput = document.getElementById('selectedDate');
            const selectedDateDisplay = document.getElementById('selectedDateDisplay');
            
            if (date) {
                // Format the date for display
                const dateObj = new Date(date);
                const formattedDate = dateObj.toLocaleDateString('en-US', { 
                    weekday: 'long', 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric' 
                });
                selectedDateDisplay.textContent = formattedDate;
                selectedDateInput.value = date;
            } else {
                // Use today's date
                const today = new Date();
                const formattedToday = today.toLocaleDateString('en-US', { 
                    weekday: 'long', 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric' 
                });
                const year = today.getFullYear();
                const month = String(today.getMonth() + 1).padStart(2, '0');
                const day = String(today.getDate()).padStart(2, '0');
                selectedDateDisplay.textContent = formattedToday;
                selectedDateInput.value = `${year}-${month}-${day}`;
            }
            
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        
        function closeAddScheduleModal() {
            document.getElementById('addScheduleModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
            
            // Reset selections
            document.querySelectorAll('.event-type-option').forEach(opt => {
                opt.classList.remove('border-2', 'border-blue-600', 'bg-blue-50');
            });
            document.getElementById('selectedEventType').value = '';
            document.getElementById('proceedBtn').disabled = true;
            document.getElementById('proceedBtn').classList.remove('bg-blue-600', 'hover:bg-blue-700', 'cursor-pointer');
            document.getElementById('proceedBtn').classList.add('bg-gray-400', 'cursor-not-allowed');
        }
        
        function selectEventType(type) {
            // Remove selected class from all options
            document.querySelectorAll('.event-type-option').forEach(opt => {
                opt.classList.remove('border-2', 'border-blue-600', 'bg-blue-50');
            });
            
            // Add selected class to clicked option
            const selectedOption = document.querySelector(`[data-type="${type}"]`);
            selectedOption.classList.add('border-2', 'border-blue-600', 'bg-blue-50');
            
            // Set the selected type
            document.getElementById('selectedEventType').value = type;
            
            // Enable the proceed button
            const proceedBtn = document.getElementById('proceedBtn');
            proceedBtn.disabled = false;
            proceedBtn.classList.remove('bg-gray-400', 'cursor-not-allowed');
            proceedBtn.classList.add('bg-blue-600', 'hover:bg-blue-700', 'cursor-pointer');
        }
        
        function proceedToAdd() {
            const type = document.getElementById('selectedEventType').value;
            if (!type) {
                showToast('Please select an event type', 'error');
                return false;
            }
            
            const form = document.getElementById('quickAddForm');
            let actionUrl = 'add_event.php?batch_id=<?= $batch_id ?>&date=' + document.getElementById('selectedDate').value;
            
            if (type === 'class') {
                actionUrl += '&type=class';
            } else if (type === 'exam') {
                actionUrl += '&type=exam';
            } else if (type === 'workshop') {
                actionUrl += '&type=workshop';
            }
            
            form.action = actionUrl;
            return true;
        }
        
        // Excel Modal Functions
        function openExcelModal() {
            document.getElementById('excelModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        
        function closeExcelModal() {
            document.getElementById('excelModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
        
        // Event click handler with event propagation prevention
        function eventClickHandler(event, type, id) {
            event.stopPropagation();
            switch(type) {
                case 'class':
                    window.location.href = `edit_event.php?id=${id}`;
                    break;
                case 'exam':
                    window.location.href = `../exam/exam_details.php?exam_id=${id}`;
                    break;
                case 'workshop':
                    window.location.href = `../workshop/workshop_view.php?id=${id}`;
                    break;
            }
        }
        
        // Toast notification
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `fixed bottom-4 right-4 px-6 py-3 rounded-lg shadow-lg text-white fade-in ${
                type === 'success' ? 'bg-green-500' : 'bg-red-500'
            }`;
            toast.innerHTML = `
                <div class="flex items-center">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-3"></i>
                    <span>${message}</span>
                </div>
            `;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.remove();
            }, 3000);
        }
        
        // Add ripple effect to buttons
        const buttons = document.querySelectorAll('a, button');
        buttons.forEach(button => {
            button.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.classList.add('ripple');
                
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });
    </script>
</body>
</html>