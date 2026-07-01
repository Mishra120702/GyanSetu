<?php
include '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../logout_a.php");
    exit;
}

// Get current month and year
$currentMonth = date('m');
$currentYear = date('Y');

// Get selected month, year, and batch from GET parameters
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : $currentMonth;
$selectedYear = isset($_GET['year']) ? $_GET['year'] : $currentYear;
$selectedBatch = isset($_GET['batch']) ? $_GET['batch'] : null;

// Validate month and year
if (!is_numeric($selectedMonth)) $selectedMonth = $currentMonth;
if (!is_numeric($selectedYear)) $selectedYear = $currentYear;

// Get all batches for dropdown
$batchesQuery = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_id");
$batches = $batchesQuery->fetchAll(PDO::FETCH_ASSOC);

// Build query for classes dates
$classesQueryParams = [$selectedMonth, $selectedYear];
$classesQuerySql = "
    SELECT DISTINCT a.date, DAYNAME(a.date) as day_name, a.batch_id, b.batch_name
    FROM attendance a
    LEFT JOIN batches b ON a.batch_id = b.batch_id
    WHERE MONTH(a.date) = ? AND YEAR(a.date) = ?
";

if ($selectedBatch) {
    $classesQuerySql .= " AND a.batch_id = ?";
    $classesQueryParams[] = $selectedBatch;
}

$classesQuerySql .= " ORDER BY a.date DESC, a.batch_id";

$classesQuery = $db->prepare($classesQuerySql);
$classesQuery->execute($classesQueryParams);
$classesDates = $classesQuery->fetchAll(PDO::FETCH_ASSOC);

// Get attendance summary for each class date
foreach ($classesDates as &$classDate) {
    $summaryQueryParams = [$classDate['date']];
    $summaryQuerySql = "
        SELECT 
            COUNT(*) as total_students,
            SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_count
        FROM attendance 
        WHERE date = ?
    ";
    
    if ($selectedBatch) {
        $summaryQuerySql .= " AND batch_id = ?";
        $summaryQueryParams[] = $selectedBatch;
    } else {
        $summaryQuerySql .= " AND batch_id = ?";
        $summaryQueryParams[] = $classDate['batch_id'];
    }
    
    $summaryQuery = $db->prepare($summaryQuerySql);
    $summaryQuery->execute($summaryQueryParams);
    $summary = $summaryQuery->fetch(PDO::FETCH_ASSOC);
    
    $classDate['total_students'] = $summary['total_students'];
    $classDate['present_count'] = $summary['present_count'];
    $classDate['absent_count'] = $summary['absent_count'];
    $classDate['late_count'] = 0; // Your attendance table doesn't have a 'Late' status
}

// Get total unique students for the selected period
$totalStudentsQueryParams = [$selectedMonth, $selectedYear];
$totalStudentsQuerySql = "
    SELECT COUNT(DISTINCT student_id) as total_unique_students
    FROM attendance 
    WHERE MONTH(date) = ? AND YEAR(date) = ?
";

if ($selectedBatch) {
    $totalStudentsQuerySql .= " AND batch_id = ?";
    $totalStudentsQueryParams[] = $selectedBatch;
}

$totalStudentsQuery = $db->prepare($totalStudentsQuerySql);
$totalStudentsQuery->execute($totalStudentsQueryParams);
$totalStudentsResult = $totalStudentsQuery->fetch(PDO::FETCH_ASSOC);
$totalUniqueStudents = $totalStudentsResult['total_unique_students'];

// Get months for dropdown
$months = [
    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
    '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
];

// Get years for dropdown (last 5 years and next 2 years)
$currentYear = date('Y');
$years = [];
for ($i = $currentYear - 5; $i <= $currentYear + 2; $i++) {
    $years[$i] = $i;
}

// Prepare data for the chart based on selection
$chartLabels = [];
$chartData = [];
$chartType = 'bar';
$chartTitle = '';

if (!empty($classesDates)) {
    if (!$selectedBatch) {
        // ALL BATCHES: Show date-wise attendance of batches (grouped bar chart)
        $chartTitle = 'Batch-wise Attendance by Date';
        
        // Get all unique dates
        $uniqueDates = [];
        foreach ($classesDates as $class) {
            $dateLabel = date('M j', strtotime($class['date']));
            if (!in_array($dateLabel, $uniqueDates)) {
                $uniqueDates[] = $dateLabel;
            }
        }
        $chartLabels = $uniqueDates;
        
        // Get all unique batches in the selected period
        $uniqueBatches = [];
        foreach ($classesDates as $class) {
            $batchKey = $class['batch_id'];
            if (!isset($uniqueBatches[$batchKey])) {
                $uniqueBatches[$batchKey] = [
                    'batch_name' => $class['batch_name'],
                    'data' => array_fill(0, count($uniqueDates), 0)
                ];
            }
        }
        
        // Fill data for each batch
        foreach ($classesDates as $class) {
            $dateLabel = date('M j', strtotime($class['date']));
            $dateIndex = array_search($dateLabel, $uniqueDates);
            $batchKey = $class['batch_id'];
            
            if ($dateIndex !== false && isset($uniqueBatches[$batchKey])) {
                $uniqueBatches[$batchKey]['data'][$dateIndex] = $class['present_count'];
            }
        }
        
        // Prepare datasets for Chart.js
        $chartDatasets = [];
        $colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'];
        $colorIndex = 0;
        
        foreach ($uniqueBatches as $batchId => $batchData) {
            $chartDatasets[] = [
                'label' => 'Batch ' . $batchId . ' (' . $batchData['batch_name'] . ')',
                'data' => $batchData['data'],
                'backgroundColor' => $colors[$colorIndex % count($colors)],
                'borderColor' => $colors[$colorIndex % count($colors)],
                'borderWidth' => 1
            ];
            $colorIndex++;
        }
        
        $chartData = $chartDatasets;
        
    } else {
        // SINGLE BATCH: Show date-wise present/absent comparison
        $chartTitle = 'Daily Attendance for Batch ' . $selectedBatch;
        
        // Group data by date
        $dateData = [];
        foreach ($classesDates as $class) {
            $dateLabel = date('M j', strtotime($class['date']));
            if (!isset($dateData[$dateLabel])) {
                $dateData[$dateLabel] = [
                    'present' => 0,
                    'absent' => 0
                ];
            }
            $dateData[$dateLabel]['present'] += $class['present_count'];
            $dateData[$dateLabel]['absent'] += $class['absent_count'];
        }
        
        // Prepare data for chart
        $chartLabels = array_keys($dateData);
        $presentData = array_column($dateData, 'present');
        $absentData = array_column($dateData, 'absent');
        
        $chartData = [
            [
                'label' => 'Present',
                'data' => $presentData,
                'backgroundColor' => '#10B981',
                'borderColor' => '#10B981',
                'borderWidth' => 1
            ],
            [
                'label' => 'Absent',
                'data' => $absentData,
                'backgroundColor' => '#EF4444',
                'borderColor' => '#EF4444',
                'borderWidth' => 1
            ]
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classes Occurred - ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
        }
        .sidebar-link:hover {
            background-color: #f0f7ff;
        }
        .sidebar-link.active {
            background-color: #e1f0ff;
            border-left: 4px solid #3b82f6;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <i class="fas fa-chalkboard-teacher text-blue-500"></i>
                <span>Classes Occurred</span>
            </h1>
            <div class="flex items-center space-x-4">
                <a href="../logout.php" class="text-sm text-red-600 hover:underline flex items-center space-x-1">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>    
            </div>
        </header>

        <div class="p-4 md:p-6">
            <!-- Filter Section -->
            <div class="bg-white p-5 rounded-xl shadow mb-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Filter Classes</h2>
                <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Month</label>
                        <select name="month" class="w-full border rounded px-3 py-2 bg-gray-50">
                            <?php foreach ($months as $num => $name): ?>
                                <option value="<?= $num ?>" <?= $num == $selectedMonth ? 'selected' : '' ?>>
                                    <?= $name ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Year</label>
                        <select name="year" class="w-full border rounded px-3 py-2 bg-gray-50">
                            <?php foreach ($years as $year): ?>
                                <option value="<?= $year ?>" <?= $year == $selectedYear ? 'selected' : '' ?>>
                                    <?= $year ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Batch</label>
                        <select name="batch" class="w-full border rounded px-3 py-2 bg-gray-50">
                            <option value="">All Batches</option>
                            <?php foreach ($batches as $batch): ?>
                                <option value="<?= $batch['batch_id'] ?>" <?= $batch['batch_id'] == $selectedBatch ? 'selected' : '' ?>>
                                    <?= $batch['batch_id'] ?> - <?= $batch['batch_name'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 transition-colors">
                            <i class="fas fa-filter mr-2"></i>Filter
                        </button>
                    </div>
                 </form>
            </div>

            <!-- Summary Card -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white p-5 rounded-xl shadow border-l-4 border-blue-500">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Total Classes</p>
                            <h3 class="text-2xl font-bold text-gray-800 mt-1"><?= count($classesDates) ?></h3>
                        </div>
                        <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                            <i class="fas fa-calendar-day text-lg"></i>
                        </div>
                    </div>
                </div>
                
                <?php
                // Calculate total attendance stats
                $totalPresent = 0;
                $totalAbsent = 0;
                $totalLate = 0;
                
                foreach ($classesDates as $class) {
                    $totalPresent += $class['present_count'];
                    $totalAbsent += $class['absent_count'];
                    $totalLate += $class['late_count'];
                }
                
                // Calculate total attendance records (not unique students)
                $totalAttendanceRecords = $totalPresent + $totalAbsent + $totalLate;
                $attendanceRate = $totalAttendanceRecords > 0 ? round(($totalPresent / $totalAttendanceRecords) * 100, 1) : 0;
                ?>
                
                <div class="bg-white p-5 rounded-xl shadow border-l-4 border-green-500">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Attendance Rate</p>
                            <h3 class="text-2xl font-bold text-gray-800 mt-1"><?= $attendanceRate ?>%</h3>
                        </div>
                        <div class="p-3 rounded-full bg-green-100 text-green-600">
                            <i class="fas fa-user-check text-lg"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-5 rounded-xl shadow border-l-4 border-purple-500">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Total Students</p>
                            <h3 class="text-2xl font-bold text-gray-800 mt-1"><?= $totalUniqueStudents ?></h3>
                        </div>
                        <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                            <i class="fas fa-users text-lg"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Chart -->
            <?php if (!empty($classesDates)): ?>
                <div class="bg-white p-5 rounded-xl shadow mb-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-semibold text-gray-800"><?= $chartTitle ?></h2>
                        <div class="text-sm text-gray-500">
                            <?php if ($selectedBatch): ?>
                                <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full">
                                    Batch: <?= $selectedBatch ?>
                                </span>
                            <?php else: ?>
                                <span class="bg-gray-100 text-gray-800 px-3 py-1 rounded-full">
                                    All Batches
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="h-80">
                        <canvas id="attendanceChart"></canvas>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Classes List -->
            <div class="bg-white p-5 rounded-xl shadow">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800">Class Dates</h2>
                    <?php if ($selectedBatch): ?>
                        <span class="text-sm bg-blue-100 text-blue-800 px-3 py-1 rounded-full">
                            Batch: <?= $selectedBatch ?>
                        </span>
                    <?php else: ?>
                        <span class="text-sm bg-gray-100 text-gray-800 px-3 py-1 rounded-full">
                            <?= count($classesDates) ?> class(es) found
                        </span>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($classesDates)): ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-calendar-times text-4xl mb-3"></i>
                        <p>No classes found for the selected period.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Day</th>
                                    <?php if (!$selectedBatch): ?>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch</th>
                                    <?php endif; ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Present</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Absent</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Attendance %</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($classesDates as $class): 
                                    $classAttendanceRate = $class['total_students'] > 0 ? round(($class['present_count'] / $class['total_students']) * 100, 1) : 0;
                                ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?= date('M j, Y', strtotime($class['date'])) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?= $class['day_name'] ?>
                                        </td>
                                        <?php if (!$selectedBatch): ?>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    <?= $class['batch_id'] ?>
                                                </span>
                                                <?php if (!empty($class['batch_name'])): ?>
                                                    <div class="text-xs text-gray-500 mt-1"><?= $class['batch_name'] ?></div>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                        <td class="px-6 py-4 whitespace-nowrap text-green-600">
                                            <?= $class['present_count'] ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-red-600">
                                            <?= $class['absent_count'] ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?= $class['total_students'] ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?= $classAttendanceRate >= 80 ? 'bg-green-100 text-green-800' : ($classAttendanceRate >= 60 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') ?>">
                                                <?= $classAttendanceRate ?>%
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <a href="../attendance/attendance.php?batch_id=<?= $class['batch_id'] ?>&date=<?= $class['date'] ?>" class="text-blue-500 hover:text-blue-700 mr-3" title="View Attendance Details">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        <?php if (!empty($classesDates)): ?>
            // Prepare data for attendance chart
            const chartLabels = <?= json_encode($chartLabels) ?>;
            const chartData = <?= json_encode($chartData) ?>;
            const isAllBatches = <?= json_encode(!$selectedBatch) ?>;
            
            // Attendance Chart
            const attendanceCtx = document.getElementById('attendanceChart').getContext('2d');
            const attendanceChart = new Chart(attendanceCtx, {
                type: 'bar',
                data: {
                    labels: chartLabels,
                    datasets: chartData
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: isAllBatches ? 'Class Dates' : 'Dates'
                            },
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            title: {
                                display: true,
                                text: 'Number of Students'
                            },
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                boxWidth: 12,
                                padding: 15
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    label += context.parsed.y;
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        <?php endif; ?>
    </script>

    <?php include '../footer.php'; ?>
</body>
</html>