<?php
include '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../logout_a.php");
    exit;
}
include '../header.php';
include '../sidebar.php';

// Get running batches with student count
$running_batches = $db->query("
    SELECT b.*, t.name as mentor_name, 
           (SELECT COUNT(*) FROM students s WHERE s.batch_name = b.batch_id) as student_count
    FROM batches b
    LEFT JOIN trainers t ON b.batch_mentor_id = t.id
    WHERE b.status = 'ongoing'
    ORDER BY b.start_date ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="flex-1 ml-0 md:ml-64 min-h-screen">
    <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
        <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <i class="fas fa-play-circle text-blue-500"></i>
            <span>Running Batches</span>
        </h1>
    </header>

    <div class="p-4 md:p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($running_batches as $batch): ?>
            <div class="bg-white rounded-xl shadow overflow-hidden border-l-4 border-blue-500">
                <div class="p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <h3 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($batch['batch_name']) ?></h3>
                            <p class="text-sm text-gray-600"><?= htmlspecialchars($batch['batch_id']) ?></p>
                        </div>
                        <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">
                            Running
                        </span>
                    </div>
                    
                    <div class="mt-4 space-y-2">
                        <div class="flex items-center text-sm text-gray-600">
                            <i class="fas fa-calendar-day mr-2 text-blue-500"></i>
                            <?= date('M d, Y', strtotime($batch['start_date'])) ?> - <?= date('M d, Y', strtotime($batch['end_date'])) ?>
                        </div>
                        <div class="flex items-center text-sm text-gray-600">
                            <i class="fas fa-clock mr-2 text-blue-500"></i>
                            <?= htmlspecialchars($batch['time_slot'] ?? 'N/A') ?>
                        </div>
                        <div class="flex items-center text-sm text-gray-600">
                            <i class="fas fa-user-tie mr-2 text-blue-500"></i>
                            <?= htmlspecialchars($batch['mentor_name'] ?? 'Not assigned') ?>
                        </div>
                        <div class="flex items-center text-sm text-gray-600">
                            <i class="fas fa-users mr-2 text-blue-500"></i>
                            <?= htmlspecialchars($batch['student_count']) ?> students enrolled
                        </div>
                    </div>
                    
                    <div class="mt-6 flex space-x-2">
                        <a href="../batch/batch_view.php?batch_id=<?= htmlspecialchars($batch['batch_id']) ?>" class="flex-1 text-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            View Details
                        </a>
                        <a href="../attendance/attendance.php?batch_id=<?= htmlspecialchars($batch['batch_id']) ?>" class="flex-1 text-center bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                            Take Attendance
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($running_batches)): ?>
            <div class="col-span-full text-center py-8">
                <i class="fas fa-info-circle text-3xl text-gray-400 mb-2"></i>
                <p class="text-gray-500">No running batches found.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../footer.php'; ?>