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
$stmt = $db->prepare("SELECT t.*, u.email 
                       FROM trainers t 
                       JOIN users u ON t.user_id = u.id 
                       WHERE t.id = ?");
$stmt->bind_param('i', $trainerId);
$stmt->execute();
$result = $stmt->get_result();
$trainer = $result->fetch_assoc();

if (!$trainer) {
    header('Location: index.php');
    exit;
}

// Get performance stats
$batchCount = getTrainerBatchCount($trainerId);
$avgRating = getTrainerAverageRating($trainerId);

// Get rating distribution
$stmt = $db->prepare("SELECT rating, COUNT(*) as count 
                       FROM feedback 
                       WHERE batch_id IN (SELECT batch_id FROM batches WHERE batch_mentor_id = ?)
                       GROUP BY rating
                       ORDER BY rating DESC");
$stmt->bind_param('i', $trainerId);
$stmt->execute();
$ratingDistribution = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get performance over time
$stmt = $db->prepare("SELECT 
                           DATE_FORMAT(f.created_at, '%Y-%m') as month,
                           AVG(f.rating) as avg_rating,
                           COUNT(f.id) as feedback_count
                       FROM feedback f
                       JOIN batches b ON f.batch_id = b.batch_id
                       WHERE b.batch_mentor_id = ?
                       GROUP BY DATE_FORMAT(f.created_at, '%Y-%m')
                       ORDER BY month DESC
                       LIMIT 12");
$stmt->bind_param('i', $trainerId);
$stmt->execute();
$performanceOverTime = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$performanceOverTime = array_reverse($performanceOverTime); // For chronological order
?>

<!DOCTYPE html>
<html lang="en" class="dark-theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($trainer['name']) ?>'s Performance | ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include '../../includes/admin-nav.php'; ?>
    
    <div class="admin-container">
        <?php include '../../includes/admin-sidebar.php'; ?>
        
        <main class="admin-content">
            <div class="container-fluid py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="page-title"><?= htmlspecialchars($trainer['name']) ?>'s Performance</h1>
                    <div>
                        <a href="view.php?id=<?= $trainerId ?>" class="btn btn-outline-secondary me-2">
                            <i class="fas fa-arrow-left me-1"></i> Back to Profile
                        </a>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-users me-1"></i> All Trainers
                        </a>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="stat-value"><?= $batchCount ?></div>
                                <div class="stat-label">Total Batches</div>
                                <i class="stat-icon fas fa-users-class"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="stat-value"><?= $avgRating ? round($avgRating, 1) : 'N/A' ?></div>
                                <div class="stat-label">Average Rating</div>
                                <i class="stat-icon fas fa-star"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="stat-value"><?= $trainer['years_of_experience'] ?></div>
                                <div class="stat-label">Years Experience</div>
                                <i class="stat-icon fas fa-award"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Rating Distribution</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="ratingChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Performance Over Time</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="performanceChart" height="250"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Detailed Feedback Analysis</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($ratingDistribution)): ?>
                            <div class="alert alert-info">No feedback data available for analysis.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Rating</th>
                                            <th>Count</th>
                                            <th>Percentage</th>
                                            <th>Stars</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $totalFeedback = array_sum(array_column($ratingDistribution, 'count'));
                                        foreach ($ratingDistribution as $rating): 
                                            $percentage = ($rating['count'] / $totalFeedback) * 100;
                                        ?>
                                            <tr>
                                                <td><?= $rating['rating'] ?></td>
                                                <td><?= $rating['count'] ?></td>
                                                <td>
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar bg-<?= 
                                                            $rating['rating'] >= 4 ? 'success' : 
                                                            ($rating['rating'] >= 3 ? 'warning' : 'danger') 
                                                        ?>" 
                                                        role="progressbar" 
                                                        style="width: <?= $percentage ?>%" 
                                                        aria-valuenow="<?= $percentage ?>" 
                                                        aria-valuemin="0" 
                                                        aria-valuemax="100">
                                                            <?= round($percentage, 1) ?>%
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i class="fas fa-star <?= $i <= $rating['rating'] ? 'text-warning' : 'text-secondary' ?>"></i>
                                                    <?php endfor; ?>
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
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/admin.js"></script>
    <script>
        // Rating Distribution Chart
        const ratingCtx = document.getElementById('ratingChart');
        if (ratingCtx) {
            new Chart(ratingCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_column($ratingDistribution, 'rating')) ?>,
                    datasets: [{
                        label: 'Feedback Count',
                        data: <?= json_encode(array_column($ratingDistribution, 'count')) ?>,
                        backgroundColor: 'rgba(54, 162, 235, 0.7)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Ratings'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Star Rating'
                            }
                        }
                    }
                }
            });
        }
        
        // Performance Over Time Chart
        const performanceCtx = document.getElementById('performanceChart');
        if (performanceCtx) {
            new Chart(performanceCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode(array_column($performanceOverTime, 'month')) ?>,
                    datasets: [{
                        label: 'Average Rating',
                        data: <?= json_encode(array_column($performanceOverTime, 'avg_rating')) ?>,
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 2,
                        tension: 0.1,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: false,
                            min: 1,
                            max: 5,
                            title: {
                                display: true,
                                text: 'Average Rating'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Month'
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>