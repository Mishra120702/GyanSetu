<?php
include '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../logout_a.php");
    exit;
}
include '../header.php';
include '../sidebar.php';

// Get pagination and sorting parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Sorting logic
$validSortColumns = ['student_id', 'first_name', 'batch_name', 'course_name'];
$sortColumn = isset($_GET['sort']) && in_array($_GET['sort'], $validSortColumns) ? $_GET['sort'] : 'first_name';
$sortOrder = isset($_GET['order']) && in_array(strtoupper($_GET['order']), ['ASC', 'DESC']) ? strtoupper($_GET['order']) : 'ASC';

// Default ordering for name is by first name then last name
$orderByClause = ($sortColumn == 'first_name') ? "s.first_name {$sortOrder}, s.last_name {$sortOrder}" : "{$sortColumn} {$sortOrder}";

// Get total students for pagination
$total_students_stmt = $db->prepare("SELECT COUNT(*) FROM students");
$total_students_stmt->execute();
$total_students = $total_students_stmt->fetchColumn();

// Get enrolled students with pagination and sorting
$students_stmt = $db->prepare("
    SELECT 
        s.student_id, 
        s.first_name, 
        s.last_name, 
        s.email,
        s.batch_name, 
        s.current_status,
        c.name AS course_name
    FROM 
        students s
    LEFT JOIN 
        courses c ON s.course = c.id
    ORDER BY 
        {$orderByClause}
    LIMIT :perPage OFFSET :offset
");
$students_stmt->bindParam(':perPage', $perPage, PDO::PARAM_INT);
$students_stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$students_stmt->execute();
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

$nextOrder = ($sortOrder === 'ASC') ? 'DESC' : 'ASC';
?>

<div class="flex-1 ml-0 md:ml-64 min-h-screen">
    <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
        <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <i class="fas fa-users text-green-500"></i>
            <span>Enrolled Students</span>
        </h1>
        <div class="flex items-center space-x-4">
            <a href="../students/add_student.php" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-plus mr-2"></i> Add Student
            </a>
        </div>
    </header>

    <div class="p-4 md:p-6">
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer sortable" data-sort="student_id">
                                Student ID
                                <i class="fas fa-sort-<?= $sortColumn === 'student_id' ? strtolower($sortOrder) : 'none' ?> ml-1"></i>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer sortable" data-sort="first_name">
                                Name
                                <i class="fas fa-sort-<?= $sortColumn === 'first_name' ? strtolower($sortOrder) : 'none' ?> ml-1"></i>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer sortable" data-sort="batch_name">
                                Batch
                                <i class="fas fa-sort-<?= $sortColumn === 'batch_name' ? strtolower($sortOrder) : 'none' ?> ml-1"></i>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer sortable" data-sort="course_name">
                                Course
                                <i class="fas fa-sort-<?= $sortColumn === 'course_name' ? strtolower($sortOrder) : 'none' ?> ml-1"></i>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($students as $student): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?= htmlspecialchars($student['student_id']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                                </div>
                                <div class="text-sm text-gray-500">
                                    <?= htmlspecialchars($student['email']) ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= htmlspecialchars($student['batch_name'] ?? 'Not assigned') ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= htmlspecialchars($student['course_name'] ?? 'N/A') ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 text-xs rounded-full 
                                    <?= $student['current_status'] == 'active' ? 'bg-green-100 text-green-800' : 
                                       ($student['current_status'] == 'inactive' ? 'bg-red-100 text-red-800' : 
                                       'bg-gray-100 text-gray-800') ?>">
                                    <?= ucfirst(htmlspecialchars($student['current_status'])) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <a href="../student/student_view.php?id=<?= htmlspecialchars($student['student_id']) ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="../student/student_edit.php?id=<?= htmlspecialchars($student['student_id']) ?>" class="text-indigo-600 hover:text-indigo-900">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
                <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm text-gray-700">
                            Showing <span class="font-medium"><?= $offset + 1 ?></span> to <span class="font-medium"><?= min($offset + $perPage, $total_students) ?></span> of <span class="font-medium"><?= $total_students ?></span> students
                        </p>
                    </div>
                    <div>
                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                            <a href="?page=<?= max(1, $page - 1) ?>&sort=<?= $sortColumn ?>&order=<?= $sortOrder ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 <?= $page <= 1 ? 'opacity-50 cursor-not-allowed' : '' ?>">
                                <span class="sr-only">Previous</span>
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php for ($i = 1; $i <= ceil($total_students / $perPage); $i++): ?>
                                <a href="?page=<?= $i ?>&sort=<?= $sortColumn ?>&order=<?= $sortOrder ?>" class="relative inline-flex items-center px-4 py-2 border text-sm font-medium
                                    <?= $i == $page ? 'bg-blue-50 border-blue-500 text-blue-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            <a href="?page=<?= min(ceil($total_students / $perPage), $page + 1) ?>&sort=<?= $sortColumn ?>&order=<?= $sortOrder ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 <?= $page >= ceil($total_students / $perPage) ? 'opacity-50 cursor-not-allowed' : '' ?>">
                                <span class="sr-only">Next</span>
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sortableHeaders = document.querySelectorAll('.sortable');
        sortableHeaders.forEach(header => {
            header.addEventListener('click', function() {
                const sortColumn = this.getAttribute('data-sort');
                let sortOrder = '<?= $sortOrder ?>';
                let currentSortColumn = '<?= $sortColumn ?>';

                // If clicking the same column, toggle the order
                if (sortColumn === currentSortColumn) {
                    sortOrder = sortOrder === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    // If clicking a new column, reset order to ASC
                    sortOrder = 'ASC';
                }

                // Construct the new URL
                const url = new URL(window.location.href);
                url.searchParams.set('sort', sortColumn);
                url.searchParams.set('order', sortOrder);
                url.searchParams.set('page', 1); // Reset to first page on sort change

                // Redirect to the new URL
                window.location.href = url.toString();
            });
        });
    });
</script>

<?php include '../footer.php'; ?>