<?php
include '../db_connection.php';
session_start();

// Verify admin login
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../logout_a.php");
    exit;
}

$admin_id = $_SESSION['user_id'];

// Handle Respond & Resolve submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_ticket'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $admin_response = trim($_POST['admin_response'] ?? '');
    
    if (empty($admin_response)) {
        $_SESSION['ticket_message'] = [
            'type' => 'error',
            'text' => 'Response cannot be empty.'
        ];
    } else {
        try {
            $db->beginTransaction();
            
            // Get ticket details to retrieve student info
            $ticket_stmt = $db->prepare("SELECT * FROM tickets WHERE id = ?");
            $ticket_stmt->execute([$ticket_id]);
            $ticket = $ticket_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($ticket) {
                // Update ticket status
                $update_stmt = $db->prepare("
                    UPDATE tickets 
                    SET status = 'resolved', 
                        admin_response = :admin_response, 
                        resolved_at = NOW(), 
                        resolved_by = :resolved_by 
                    WHERE id = :ticket_id
                ");
                $update_stmt->execute([
                    ':admin_response' => $admin_response,
                    ':resolved_by' => $admin_id,
                    ':ticket_id' => $ticket_id
                ]);
                
                // Get student's user ID from students table
                $student_stmt = $db->prepare("SELECT user_id FROM students WHERE student_id = ?");
                $student_stmt->execute([$ticket['student_id']]);
                $student_user = $student_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($student_user) {
                    $student_user_id = $student_user['user_id'];
                    $notif_title = "Support Ticket Resolved";
                    $notif_message = "Your support ticket regarding '" . $ticket['reason'] . "' has been resolved. Click to view the reply.";
                    
                    // Insert notification
                    $notif_stmt = $db->prepare("
                        INSERT INTO notifications (user_id, type, title, message, reference_id, is_read) 
                        VALUES (:user_id, 'ticket', :title, :message, :reference_id, 0)
                    ");
                    $notif_stmt->execute([
                        ':user_id' => $student_user_id,
                        ':title' => $notif_title,
                        ':message' => $notif_message,
                        ':reference_id' => $ticket_id
                    ]);
                }
                
                $db->commit();
                $_SESSION['ticket_message'] = [
                    'type' => 'success',
                    'text' => "Ticket #$ticket_id resolved successfully and student notified!"
                ];
            } else {
                $db->rollBack();
                $_SESSION['ticket_message'] = [
                    'type' => 'error',
                    'text' => 'Ticket not found.'
                ];
            }
        } catch (PDOException $e) {
            $db->rollBack();
            $_SESSION['ticket_message'] = [
                'type' => 'error',
                'text' => 'Database error: ' . $e->getMessage()
            ];
        }
    }
    
    header("Location: tickets.php");
    exit();
}

// Fetch search and filter parameters
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? 'all');
$category_filter = trim($_GET['category'] ?? 'all');

// Build SQL query
$sql = "
    SELECT t.*, s.first_name, s.last_name, s.email as student_email, u.name as admin_name
    FROM tickets t
    JOIN students s ON t.student_id = s.student_id
    LEFT JOIN users u ON t.resolved_by = u.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (t.student_id LIKE :search1 
                   OR s.first_name LIKE :search2 
                   OR s.last_name LIKE :search3 
                   OR t.reason LIKE :search4)";
    $search_param = '%' . $search . '%';
    $params[':search1'] = $search_param;
    $params[':search2'] = $search_param;
    $params[':search3'] = $search_param;
    $params[':search4'] = $search_param;
}

if ($status_filter !== 'all') {
    $sql .= " AND t.status = :status";
    $params[':status'] = $status_filter;
}

if ($category_filter !== 'all') {
    $sql .= " AND t.reason = :category";
    $params[':category'] = $category_filter;
}

$sql .= " ORDER BY t.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check messages
$ticket_message = $_SESSION['ticket_message'] ?? null;
unset($_SESSION['ticket_message']);

include '../header.php';
include '../sidebar.php';
?>

<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm px-6 py-5 flex justify-between items-center sticky top-0 z-30">
        <button class="md:hidden text-xl text-gray-600 focus:outline-none" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-3">
            <div class="bg-blue-100 text-blue-600 p-2.5 rounded-xl">
                <i class="fas fa-ticket-alt"></i>
            </div>
            <span>Support Tickets</span>
        </h1>
        <div class="flex items-center space-x-3">
            <div class="hidden sm:block text-right">
                <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></p>
                <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">System Admin</p>
            </div>
            <div class="w-10 h-10 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold shadow-md">
                <i class="fas fa-user-shield"></i>
            </div>
        </div>
    </header>

    <!-- Main Content Grid -->
    <div class="p-4 md:p-8">
        <?php if ($ticket_message): ?>
            <div class="mb-6 p-4 rounded-xl shadow-sm flex items-center animate-fade-in
                <?= $ticket_message['type'] === 'success' ? 'bg-green-100 text-green-800 border-l-4 border-green-500' : 'bg-red-100 text-red-800 border-l-4 border-red-500' ?>">
                <i class="fas <?= $ticket_message['type'] === 'success' ? 'fa-check-circle text-green-500' : 'fa-exclamation-circle text-red-500' ?> mr-3 text-lg"></i>
                <span class="font-medium"><?= htmlspecialchars($ticket_message['text']) ?></span>
            </div>
        <?php endif; ?>

        <!-- Search & Filter Controls -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 mb-6">
            <form method="GET" action="tickets.php" class="flex flex-col md:flex-row md:items-center gap-4">
                <div class="flex-1 relative">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Search by student ID, name or category..." 
                           class="w-full pl-11 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-sm">
                </div>
                
                <div class="w-full md:w-48">
                    <select name="status" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-sm">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="open" <?= $status_filter === 'open' ? 'selected' : '' ?>>Open</option>
                        <option value="resolved" <?= $status_filter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                    </select>
                </div>

                <div class="w-full md:w-48">
                    <select name="category" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-sm">
                        <option value="all" <?= $category_filter === 'all' ? 'selected' : '' ?>>All Categories</option>
                        <option value="Fee Related" <?= $category_filter === 'Fee Related' ? 'selected' : '' ?>>Fee Related</option>
                        <option value="Batch/Schedule Related" <?= $category_filter === 'Batch/Schedule Related' ? 'selected' : '' ?>>Batch/Schedule Related</option>
                        <option value="Exam/Test Related" <?= $category_filter === 'Exam/Test Related' ? 'selected' : '' ?>>Exam/Test Related</option>
                        <option value="App/Technical Issue" <?= $category_filter === 'App/Technical Issue' ? 'selected' : '' ?>>App/Technical Issue</option>
                        <option value="Other" <?= $category_filter === 'Other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                
                <button type="submit" class="w-full md:w-auto px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl transition-all font-semibold shadow-sm text-sm">
                    Apply Filters
                </button>
                
                <?php if (!empty($search) || $status_filter !== 'all' || $category_filter !== 'all'): ?>
                    <a href="tickets.php" class="w-full md:w-auto px-6 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl transition-all text-center font-semibold text-sm">
                        Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Tickets Table Card -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Ticket</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Date Raised</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Student Details</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Category</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($tickets)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-16 text-center text-gray-500">
                                    <div class="bg-gray-50 inline-block p-5 rounded-full mb-3">
                                        <i class="fas fa-ticket-alt text-gray-300 text-3xl"></i>
                                    </div>
                                    <p class="font-semibold text-gray-700">No support tickets found</p>
                                    <p class="text-xs text-gray-400 mt-1">Try modifying search keywords or filters.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tickets as $ticket): ?>
                                <!-- Main Row -->
                                <tr class="hover:bg-gray-50/50 cursor-pointer ticket-row transition-colors" data-id="<?= $ticket['id'] ?>">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-700">
                                        #<?= $ticket['id'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('d M Y, h:i A', strtotime($ticket['created_at'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-semibold text-gray-800">
                                            <?= htmlspecialchars($ticket['first_name'] . ' ' . $ticket['last_name']) ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?= htmlspecialchars($ticket['student_id']) ?> | <?= htmlspecialchars($ticket['student_email']) ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-700">
                                        <?= htmlspecialchars($ticket['reason']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold
                                            <?= $ticket['status'] === 'resolved' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800' ?>">
                                            <?= ucfirst($ticket['status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium" onclick="event.stopPropagation()">
                                        <div class="flex items-center justify-end space-x-2">
                                            <button onclick="toggleDetails(<?= $ticket['id'] ?>)" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs transition-colors flex items-center space-x-1">
                                                <i class="fas fa-eye"></i>
                                                <span>Details</span>
                                            </button>
                                            <?php if ($ticket['status'] === 'open'): ?>
                                                <button onclick="openResolveModal(<?= $ticket['id'] ?>, '<?= htmlspecialchars(addslashes($ticket['first_name'] . ' ' . $ticket['last_name'])) ?>', '<?= htmlspecialchars(addslashes($ticket['reason'])) ?>')" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs transition-colors flex items-center space-x-1">
                                                    <i class="fas fa-reply"></i>
                                                    <span>Resolve</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Expandable Details Row -->
                                <tr id="details-<?= $ticket['id'] ?>" class="hidden bg-gray-50/50">
                                    <td colspan="6" class="px-8 py-5 border-t border-b border-gray-100">
                                        <div class="max-w-3xl">
                                            <div class="mb-4">
                                                <strong class="text-xs font-bold text-gray-500 uppercase tracking-wider block mb-1">Student Problem Description:</strong>
                                                <p class="text-sm text-gray-700 bg-white border border-gray-200 p-4 rounded-xl leading-relaxed whitespace-pre-wrap">
                                                    <?= !empty($ticket['description']) ? htmlspecialchars($ticket['description']) : '<em>No detailed description provided.</em>' ?>
                                                </p>
                                            </div>

                                            <?php if (!empty($ticket['attachment_path'])): ?>
                                                <div class="mb-4">
                                                    <strong class="text-xs font-bold text-gray-500 uppercase tracking-wider block mb-1.5">Attachment File:</strong>
                                                    <a href="../<?= htmlspecialchars($ticket['attachment_path']) ?>" target="_blank" class="inline-flex items-center space-x-2 text-xs font-semibold text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 px-3.5 py-2 rounded-lg transition-colors border border-blue-100">
                                                        <i class="fas fa-paperclip"></i>
                                                        <span>View / Download Attachment</span>
                                                    </a>
                                                </div>
                                            <?php endif; ?>

                                            <!-- Response section -->
                                            <?php if ($ticket['status'] === 'resolved'): ?>
                                                <div class="border-t border-gray-200/80 pt-4 mt-4">
                                                    <div class="bg-green-50/60 border border-green-100 p-4 rounded-xl">
                                                        <div class="flex items-center space-x-2 mb-2">
                                                            <i class="fas fa-reply text-green-600"></i>
                                                            <span class="text-xs font-bold text-green-800 uppercase tracking-wider">Resolution Details:</span>
                                                        </div>
                                                        <p class="text-sm text-green-900 leading-relaxed whitespace-pre-wrap">
                                                            <?= htmlspecialchars($ticket['admin_response'] ?? '') ?>
                                                        </p>
                                                        <div class="text-[10px] text-gray-500 mt-2.5 flex items-center space-x-2">
                                                            <span>Resolved by: <strong><?= htmlspecialchars($ticket['admin_name'] ?? 'System') ?></strong></span>
                                                            <span>•</span>
                                                            <span>Date: <strong><?= date('d M Y, h:i A', strtotime($ticket['resolved_at'])) ?></strong></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="border-t border-gray-200/80 pt-4 mt-4">
                                                    <button onclick="openResolveModal(<?= $ticket['id'] ?>, '<?= htmlspecialchars(addslashes($ticket['first_name'] . ' ' . $ticket['last_name'])) ?>', '<?= htmlspecialchars(addslashes($ticket['reason'])) ?>')" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-semibold shadow-sm flex items-center space-x-1.5">
                                                        <i class="fas fa-reply"></i>
                                                        <span>Write Response & Resolve</span>
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Resolve Ticket Modal -->
<div id="resolveModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden transition-all duration-300">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden transform transition-all duration-300 scale-95 opacity-0" id="resolveModalContainer">
        <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white p-5">
            <div class="flex justify-between items-center">
                <h2 class="text-lg font-bold flex items-center">
                    <i class="fas fa-reply mr-2.5"></i>
                    Resolve Support Ticket
                </h2>
                <button onclick="closeResolveModal()" class="text-white hover:text-gray-200 text-2xl focus:outline-none">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        
        <form method="POST" action="tickets.php" class="p-6 space-y-4">
            <input type="hidden" name="ticket_id" id="modal_ticket_id">
            
            <div class="text-sm text-gray-600">
                <p>Resolving ticket for student: <strong id="modal_student_name" class="text-gray-800"></strong></p>
                <p class="mt-1">Category: <strong id="modal_ticket_category" class="text-gray-800"></strong></p>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Admin Response *</label>
                <textarea name="admin_response" required rows="5" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all text-sm" placeholder="Provide resolution or instructions for the student..."></textarea>
            </div>

            <div class="flex space-x-3 pt-2">
                <button type="button" onclick="closeResolveModal()" class="w-1/2 px-4 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-semibold transition-all text-sm">
                    Cancel
                </button>
                <button type="submit" name="resolve_ticket" class="w-1/2 px-4 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl font-semibold shadow-md transition-all text-sm">
                    Resolve & Send Notification
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Expand details toggler
    function toggleDetails(id) {
        const row = document.getElementById('details-' + id);
        if (row.classList.contains('hidden')) {
            row.classList.remove('hidden');
        } else {
            row.classList.add('hidden');
        }
    }

    // Modal triggers
    function openResolveModal(id, studentName, category) {
        document.getElementById('modal_ticket_id').value = id;
        document.getElementById('modal_student_name').textContent = studentName;
        document.getElementById('modal_ticket_category').textContent = category;
        
        const modal = document.getElementById('resolveModal');
        const container = document.getElementById('resolveModalContainer');
        modal.classList.remove('hidden');
        setTimeout(() => {
            container.classList.remove('scale-95', 'opacity-0');
            container.classList.add('scale-100', 'opacity-100');
        }, 10);
    }

    function closeResolveModal() {
        const modal = document.getElementById('resolveModal');
        const container = document.getElementById('resolveModalContainer');
        container.classList.remove('scale-100', 'opacity-100');
        container.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.add('hidden');
        }, 300);
    }

    // Row selection fallback to toggle detail rows
    document.querySelectorAll('.ticket-row').forEach(row => {
        row.addEventListener('click', function(e) {
            if (e.target.closest('button') || e.target.closest('a')) {
                return;
            }
            const id = this.dataset.id;
            toggleDetails(id);
        });
    });

    // Sidebar toggler fallback
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar && overlay) {
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }
    }
</script>

<?php include '../footer.php'; ?>
