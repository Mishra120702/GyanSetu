<?php
// feedback.php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Database connection
require_once '../db_connection.php';

// Get all batches for the dropdown
$batches = [];
try {
    $batch_query = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_id DESC");
    if ($batch_query) {
        $batches = $batch_query->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $error = "Failed to load batch data";
}

// Sanitize filter inputs
$batch_filter = isset($_GET['batch']) ? filter_var($_GET['batch'], FILTER_SANITIZE_STRING) : '';
$date_from = isset($_GET['date_from']) ? filter_var($_GET['date_from'], FILTER_SANITIZE_STRING) : '';
$date_to = isset($_GET['date_to']) ? filter_var($_GET['date_to'], FILTER_SANITIZE_STRING) : '';
$rating_min = isset($_GET['rating_min']) ? filter_var($_GET['rating_min'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]) : '';
$rating_max = isset($_GET['rating_max']) ? filter_var($_GET['rating_max'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]) : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback Management</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        
        .filter-controls {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .minimal-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #fff;
            font-size: 14px;
        }
        
        .btn-blue, .btn-gray {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-blue {
            background-color: #3498db;
            color: white;
        }
        
        .btn-blue:hover {
            background-color: #2980b9;
        }
        
        .btn-gray {
            background-color: #e0e0e0;
            color: #333;
        }
        
        .btn-gray:hover {
            background-color: #d0d0d0;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
        }
        
        @media (max-width: 768px) {
            .filter-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            form {
                flex-direction: column;
                gap: 10px;
            }
            
            .export-buttons {
                flex-direction: column;
                width: 100%;
            }
            
            .export-buttons a {
                text-align: center;
            }
        }
        
        .error-message {
            color: #d9534f;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ebccd1;
            background-color: #f2dede;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <h1>Feedback Management</h1>
    
    <?php if (isset($error)): ?>
        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- Filter controls section -->
    <div class="filter-controls">
        <form method="GET" action="" style="display: flex; align-items: center; gap: 10px;">
            <select name="batch" class="minimal-input" style="width: auto;">
                <option value="">All Batches</option>
                <?php foreach ($batches as $batch): ?>
                    <option value="<?= htmlspecialchars($batch['batch_id']) ?>" 
                        <?= $batch_filter === $batch['batch_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($batch['batch_id']) ?> - <?= htmlspecialchars($batch['batch_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <input type="date" name="date_from" class="minimal-input" placeholder="From Date" value="<?= htmlspecialchars($date_from) ?>">
            <input type="date" name="date_to" class="minimal-input" placeholder="To Date" value="<?= htmlspecialchars($date_to) ?>">
            
            <select name="rating_min" class="minimal-input" style="width: auto;">
                <option value="">Min Rating</option>
                <?php for ($i=1; $i<=5; $i++): ?>
                    <option value="<?= $i ?>" <?= $rating_min == $i ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
            
            <select name="rating_max" class="minimal-input" style="width: auto;">
                <option value="">Max Rating</option>
                <?php for ($i=1; $i<=5; $i++): ?>
                    <option value="<?= $i ?>" <?= $rating_max == $i ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
            
            <button type="submit" class="btn-blue">Filter</button>
            <a href="feedback.php" class="btn-gray">Reset</a>
        </form>
        
        <div class="export-buttons">
            <a href="export_feedback.php?export=excel&<?= http_build_query($_GET) ?>" class="btn-blue">Export Excel</a>
            <a href="export_feedback.php?export=csv&<?= http_build_query($_GET) ?>" class="btn-blue">Export CSV</a>
            <a href="export_feedback.php?export=pdf&<?= http_build_query($_GET) ?>" class="btn-blue">Export PDF</a>
        </div>
    </div>
    
    <!-- Feedback results table would go here -->
    <div class="feedback-results">
        <?php
        // Here you would display the filtered feedback results
        // based on the filters applied
        ?>
    </div>
</body>
</html>