<?php
function getTrainerPhoto($trainer) {
    if (!empty($trainer['profile_picture'])) {
        return $trainer['profile_picture'];
    }
    return '../assets/images/default-trainer.jpg';
}

function getTrainerSpecializations() {
    global $db;
    $specializations = [];
    $query = "SELECT DISTINCT specialization FROM trainers WHERE specialization IS NOT NULL AND specialization != ''";
    $result = $db->query($query);
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $specializations[] = $row['specialization'];
    }
    return $specializations;
}

function getFilteredTrainers($filters, $limit = 20, $offset = 0) {
    global $db;
    
    $query = "SELECT t.*, u.email, COUNT(b.batch_id) as batch_count 
              FROM trainers t
              LEFT JOIN users u ON t.user_id = u.id
              LEFT JOIN batches b ON t.id = b.batch_mentor_id";
    
    $where = [];
    $params = [];
    
    if (!empty($filters['search'])) {
        $where[] = "(t.name LIKE ? OR u.email LIKE ? OR t.specialization LIKE ?)";
        $searchTerm = "%{$filters['search']}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if (!empty($filters['status'])) {
        if ($filters['status'] === 'active') {
            $where[] = "t.is_active = 1";
        } elseif ($filters['status'] === 'inactive') {
            $where[] = "t.is_active = 0";
        }
    }
    
    if (!empty($filters['specialization'])) {
        $where[] = "t.specialization = ?";
        $params[] = $filters['specialization'];
    }
    
    if (!empty($where)) {
        $query .= " WHERE " . implode(" AND ", $where);
    }
    
    $query .= " GROUP BY t.id";
    
    // Sorting
    if (!empty($filters['sort'])) {
        switch ($filters['sort']) {
            case 'name_desc':
                $query .= " ORDER BY t.name DESC";
                break;
            case 'newest':
                $query .= " ORDER BY t.created_at DESC";
                break;
            case 'oldest':
                $query .= " ORDER BY t.created_at ASC";
                break;
            case 'name_asc':
            default:
                $query .= " ORDER BY t.name ASC";
                break;
        }
    } else {
        $query .= " ORDER BY t.name ASC";
    }
    
    // Pagination
    if ($limit) {
        $query .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
    }
    
    $stmt = $db->prepare($query);
    
    if (!empty($params)) {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key + 1, $value);
        }
    }
    
    $stmt->execute();
    $trainers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $trainers;
}

function getTotalFilteredTrainers($filters) {
    global $db;
    
    $query = "SELECT COUNT(DISTINCT t.id) as total 
              FROM trainers t
              LEFT JOIN users u ON t.user_id = u.id";
    
    $where = [];
    $params = [];
    
    if (!empty($filters['search'])) {
        $where[] = "(t.name LIKE ? OR u.email LIKE ? OR t.specialization LIKE ?)";
        $searchTerm = "%{$filters['search']}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if (!empty($filters['status'])) {
        if ($filters['status'] === 'active') {
            $where[] = "t.is_active = 1";
        } elseif ($filters['status'] === 'inactive') {
            $where[] = "t.is_active = 0";
        }
    }
    
    if (!empty($filters['specialization'])) {
        $where[] = "t.specialization = ?";
        $params[] = $filters['specialization'];
    }
    
    if (!empty($where)) {
        $query .= " WHERE " . implode(" AND ", $where);
    }
    
    $stmt = $db->prepare($query);
    
    if (!empty($params)) {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key + 1, $value);
        }
    }
    
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $row['total'];
}

function getTrainerBatchCount($trainerId) {
    global $db;
    $query = "SELECT COUNT(*) as count FROM batches WHERE batch_mentor_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bindValue(1, $trainerId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['count'];
}

function getTrainerAverageRating($trainerId) {
    global $db;
    $query = "SELECT AVG(rating) as avg_rating FROM feedback 
              WHERE batch_id IN (SELECT batch_id FROM batches WHERE batch_mentor_id = ?)";
    $stmt = $db->prepare($query);
    $stmt->bindValue(1, $trainerId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['avg_rating'];
}

function getTrainersPerformanceStats() {
    global $db;
    
    $stats = [
        'active_count' => 0,
        'inactive_count' => 0,
        'avg_rating' => 0,
        'total_batches' => 0,
        'top_trainers' => []
    ];
    
    // Count active/inactive trainers
    $query = "SELECT is_active, COUNT(*) as count FROM trainers GROUP BY is_active";
    $result = $db->query($query);
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        if ($row['is_active']) {
            $stats['active_count'] = $row['count'];
        } else {
            $stats['inactive_count'] = $row['count'];
        }
    }
    
    // Average rating across all trainers
    $query = "SELECT AVG(f.rating) as avg_rating 
              FROM feedback f
              JOIN batches b ON f.batch_id = b.batch_id
              JOIN trainers t ON b.batch_mentor_id = t.id
              WHERE t.is_active = 1";
    $result = $db->query($query);
    $row = $result->fetch(PDO::FETCH_ASSOC);
    $stats['avg_rating'] = $row['avg_rating'];
    
    // Total active batches
    $query = "SELECT COUNT(*) as count FROM batches WHERE status = 'ongoing'";
    $result = $db->query($query);
    $row = $result->fetch(PDO::FETCH_ASSOC);
    $stats['total_batches'] = $row['count'];
    
    // Top 5 trainers by rating
    $query = "SELECT t.id, t.name, AVG(f.rating) as avg_rating, COUNT(b.batch_id) as batch_count
              FROM trainers t
              LEFT JOIN batches b ON t.id = b.batch_mentor_id
              LEFT JOIN feedback f ON b.batch_id = f.batch_id
              WHERE t.is_active = 1
              GROUP BY t.id
              ORDER BY avg_rating DESC
              LIMIT 5";
    $result = $db->query($query);
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $stats['top_trainers'][] = $row;
    }
    
    return $stats;
}