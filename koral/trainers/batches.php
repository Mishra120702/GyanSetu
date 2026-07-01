<?php
require_once '../db_connection.php';
require_once 'functions.php';

// Check admin permissions
if (!hasPermission('admin')) {
    header('Location: /unauthorized.php');
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$trainerId = (int)$_GET['id'];

// Fetch trainer data
$stmt = $db->prepare("SELECT name FROM trainers WHERE id = ?");
$stmt->bind_param('i', $trainerId);
$stmt->execute();
$result = $stmt->get_result();
$trainer = $result->fetch_assoc();

if (!$trainer) {
    header('Location: index.php');
    exit;
}

// Get pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Get batches count
$stmt = $db->prepare("SELECT COUNT(*) as total 
                       FROM batches 
                       WHERE batch_mentor_id = ?");
$stmt->bind_param('i', $trainerId);
$stmt->execute();
$result = $stmt->get_result();
$totalBatches = $result->fetch_assoc()['total'];
$totalPages = ceil($totalBatches / $perPage);

// Get batches
$stmt = $db->prepare("SELECT b.*, c.name as batch_name 
                       FROM batches b
                       JOIN courses c ON b.course_id = c.id
                       WHERE b.batch_mentor_id = ?
                       ORDER BY b.start_date DESC
                       LIMIT ? OFFSET ?");
$stmt->bind_param('iii', $trainerId, $perPage, $offset);
$stmt->execute();
$batches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en" class="dark-theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($trainer['name']) ?>'s Batches | ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <?php include '../../includes/admin-nav.php'; ?>
    
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        
        <main class="admin-content">
            <div class="container-fluid py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="page-title"><?= htmlspecialchars($trainer['name']) ?>'s Batches</h1>
                    <div>
                        <a href="view.php?id=<?= $trainerId ?>" class="btn btn-outline-secondary me-2">
                            <i class="fas fa-arrow-left me-1"></i> Back to Profile
                        </a>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-users me-1"></i> All Trainers
                        </a>
                    </div>
                </div>
                
                <div class="card animate__animated animate__fadeIn">
                    <div class="card-body">
                        <?php if (empty($batches)): ?>
                            <div class="alert alert-info">No batches assigned to this trainer yet.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Batch Name</th>
                                            <th>Course</th>
                                            <th>Start Date</th>
                                            <th>End Date</th>
                                            <th>Status</th>
                                            <th>Students</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($batches as $batch): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($batch['batch_name']) ?></td>
                                                <td><?= htmlspecialchars($batch['batch_name']) ?></td>
                                                <td><?= date('M d, Y', strtotime($batch['start_date'])) ?></td>
                                                <td><?= $batch['end_date'] ? date('M d, Y', strtotime($batch['end_date'])) : '-' ?></td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $batch['status'] === 'upcoming' ? 'info' : 
                                                        ($batch['status'] === 'ongoing' ? 'success' : 'secondary') 
                                                    ?>">
                                                        <?= ucfirst($batch['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM batch_students WHERE batch_id = ?");
                                                    $stmt->bind_param('i', $batch['batch_id']);
                                                    $stmt->execute();
                                                    $result = $stmt->get_result();
                                                    $count = $result->fetch_assoc()['count'];
                                                    ?>
                                                    <span class="badge bg-primary rounded-pill"><?= $count ?></span>
                                                </td>
                                                <td>
                                                    <a href="/admin/batches/view.php?id=<?= $batch['batch_id'] ?>" 
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <nav aria-label="Page navigation" class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?id=<?= $trainerId ?>&page=<?= $page - 1 ?>" aria-label="Previous">
                                                <span aria-hidden="true">&laquo;</span>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                            <a class="page-link" href="?id=<?= $trainerId ?>&page=<?= $i ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?id=<?= $trainerId ?>&page=<?= $page + 1 ?>" aria-label="Next">
                                                <span aria-hidden="true">&raquo;</span>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/admin.js"></script>
</body>
</html>