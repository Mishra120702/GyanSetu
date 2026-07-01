<?php
include '../db_connection.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../logout_a.php");
    exit;
}
// Get upcoming classes (next 30 days)
$upcoming_start = date('Y-m-d');
$upcoming_end = date('Y-m-d', strtotime('+30 days'));

$upcoming_classes = $db->query("
    SELECT s.*, b.batch_name, b.batch_id, t.name as trainer_name
    FROM schedule s
    JOIN batches b ON s.batch_id = b.batch_id
    LEFT JOIN trainers t ON b.batch_mentor_id = t.id
    WHERE s.schedule_date BETWEEN '$upcoming_start' AND '$upcoming_end'
    AND s.is_cancelled = 0
    ORDER BY s.schedule_date ASC, s.start_time ASC
")->fetchAll(PDO::FETCH_ASSOC);

include '../header.php';
include '../sidebar.php';
?>

<!-- Main Content -->
<div class="flex-1 ml-0 md:ml-64 min-h-screen">
    <!-- Header -->
    <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
        <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <i class="fas fa-calendar-alt text-blue-500"></i>
            <span>Upcoming Classes</span>
        </h1>
        <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="text-sm text-blue-600 hover:underline flex items-center space-x-1">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Dashboard</span>
            </a>
        </div>
    </header>

    <div class="p-4 md:p-6">
        <!-- Filter Section -->
        <div class="bg-white p-5 rounded-xl shadow mb-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Filter Classes</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="date" id="startDate" class="w-full p-2 border rounded-md" value="<?= date('Y-m-d') ?>">
                        <input type="date" id="endDate" class="w-full p-2 border rounded-md" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Batch</label>
                    <select id="batchFilter" class="w-full p-2 border rounded-md">
                        <option value="">All Batches</option>
                        <?php
                        $batches = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_id");
                        foreach ($batches as $batch) {
                            echo "<option value='{$batch['batch_id']}'>{$batch['batch_id']} - {$batch['batch_name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="flex items-end">
                    <button id="applyFilters" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition-colors">
                        <i class="fas fa-filter mr-2"></i>Apply Filters
                    </button>
                    <button id="resetFilters" class="ml-2 bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300 transition-colors">
                        <i class="fas fa-redo mr-2"></i>Reset
                    </button>
                </div>
            </div>
        </div>

        <!-- Classes Table -->
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="flex justify-between items-center p-4 border-b">
                <h2 class="text-lg font-semibold text-gray-800">Upcoming Classes</h2>
                <div class="flex space-x-2">
                    <button id="exportCSV" class="text-sm bg-green-100 text-green-700 px-3 py-1 rounded hover:bg-green-200">
                        <i class="fas fa-file-csv mr-1"></i>Export CSV
                    </button>
                    <button id="printTable" class="text-sm bg-blue-100 text-blue-700 px-3 py-1 rounded hover:bg-blue-200">
                        <i class="fas fa-print mr-1"></i>Print
                    </button>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Course</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Topic</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trainer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200" id="classesTableBody">
                        <?php foreach ($upcoming_classes as $class): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= date('D, M j', strtotime($class['schedule_date'])) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= date('g:i A', strtotime($class['start_time'])) ?> - <?= date('g:i A', strtotime($class['end_time'])) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?= htmlspecialchars($class['batch_id']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= htmlspecialchars($class['batch_name']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= htmlspecialchars($class['topic']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= htmlspecialchars($class['trainer_name'] ?? 'Not assigned') ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <a href="../schedule/schedule.php?batch_id=<?= htmlspecialchars($class['batch_id']) ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="../schedule/edit_schedule.php?id=<?= htmlspecialchars($class['id']) ?>" class="text-indigo-600 hover:text-indigo-900">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($upcoming_classes)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">
                                No upcoming classes found.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Apply filters button
    document.getElementById('applyFilters').addEventListener('click', function() {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        const batchId = document.getElementById('batchFilter').value;
        
        // Show loading state
        document.getElementById('classesTableBody').innerHTML = `
            <tr>
                <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">
                    <i class="fas fa-spinner fa-spin mr-2"></i>Loading data...
                </td>
            </tr>
        `;
        
        // Make AJAX request
        fetch('get_upcoming_classes.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                startDate: startDate,
                endDate: endDate,
                batchId: batchId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                let html = '';
                data.data.forEach(classItem => {
                    html += `
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            ${new Date(classItem.schedule_date).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            ${formatTime(classItem.start_time)} - ${formatTime(classItem.end_time)}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            ${escapeHtml(classItem.batch_id)}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            ${escapeHtml(classItem.batch_name)}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            ${escapeHtml(classItem.topic)}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            ${escapeHtml(classItem.trainer_name || 'Not assigned')}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <a href="../schedule/schedule.php?batch_id=${encodeURIComponent(classItem.batch_id)}" class="text-blue-600 hover:text-blue-900 mr-3">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <a href="../schedule/edit_schedule.php?id=${classItem.id}" class="text-indigo-600 hover:text-indigo-900">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                        </td>
                    </tr>
                    `;
                });
                document.getElementById('classesTableBody').innerHTML = html;
            } else {
                document.getElementById('classesTableBody').innerHTML = `
                    <tr>
                        <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">
                            No classes found matching your criteria.
                        </td>
                    </tr>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('classesTableBody').innerHTML = `
                <tr>
                    <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">
                        Error loading data. Please try again.
                    </td>
                </tr>
            `;
        });
    });
    
    // Reset filters button
    document.getElementById('resetFilters').addEventListener('click', function() {
        document.getElementById('startDate').value = '<?= date('Y-m-d') ?>';
        document.getElementById('endDate').value = '<?= date('Y-m-d', strtotime('+30 days')) ?>';
        document.getElementById('batchFilter').value = '';
        document.getElementById('applyFilters').click();
    });
    
    // Export CSV button
    document.getElementById('exportCSV').addEventListener('click', function() {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        const batchId = document.getElementById('batchFilter').value;
        
        // Create a temporary form to submit the export request
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'export_upcoming_classes.php';
        
        // Add parameters as hidden inputs
        const addInput = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        };
        
        addInput('startDate', startDate);
        addInput('endDate', endDate);
        addInput('batchId', batchId);
        
        // Append form to body and submit
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    });
    
    // Print button
    document.getElementById('printTable').addEventListener('click', function() {
        const printWindow = window.open('', '_blank');
        const tableContent = document.querySelector('table').outerHTML;
        const title = 'Upcoming Classes Report';
        
        printWindow.document.write(`
            <html>
                <head>
                    <title>${title}</title>
                    <style>
                        body { font-family: Arial, sans-serif; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; }
                        .no-print { display: none; }
                    </style>
                </head>
                <body>
                    <h1>${title}</h1>
                    <p>Generated on: ${new Date().toLocaleString()}</p>
                    ${tableContent}
                    <script>
                        window.onload = function() {
                            window.print();
                            window.close();
                        };
                    <\/script>
                </body>
            </html>
        `);
    });
    
    // Helper functions
    function formatTime(timeString) {
        const time = new Date(`2000-01-01T${timeString}`);
        return time.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }
    
    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
});
</script>

<?php include '../footer.php'; ?>