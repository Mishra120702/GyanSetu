<?php
// preview_export.php
require_once '../db_connection.php';

// Get filter parameters
$batch_id = $_GET['batch_id'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$student_id = $_GET['student_id'] ?? '';
$report_type = $_GET['report_type'] ?? 'all';

// Get sample data (first 10 records) for preview
function getPreviewData($db, $batch_id, $start_date, $end_date, $student_id, $report_type) {
    switch($report_type) {
        case 'attendance':
            $query = "SELECT a.date, a.student_id, a.student_name, a.status, a.remarks 
                      FROM attendance a 
                      WHERE a.date BETWEEN ? AND ? 
                      " . (!empty($batch_id) ? " AND a.batch_id = ?" : "") . "
                      " . (!empty($student_id) ? " AND a.student_id = ?" : "") . "
                      LIMIT 10";
            $params = [$start_date, $end_date];
            if (!empty($batch_id)) $params[] = $batch_id;
            if (!empty($student_id)) $params[] = $student_id;
            break;
            
        case 'exams':
            $query = "SELECT e.exam_date as date, er.student_id, e.exam_name, 
                             er.obtained_marks, e.total_marks, er.grade 
                      FROM exam_results er 
                      JOIN exams e ON er.exam_id = e.exam_id 
                      WHERE e.exam_date BETWEEN ? AND ? 
                      " . (!empty($batch_id) ? " AND e.batch_id = ?" : "") . "
                      " . (!empty($student_id) ? " AND er.student_id = ?" : "") . "
                      LIMIT 10";
            $params = [$start_date, $end_date];
            if (!empty($batch_id)) $params[] = $batch_id;
            if (!empty($student_id)) $params[] = $student_id;
            break;
            
        case 'feedback':
            $query = "SELECT date, student_name, class_rating, assignment_understanding, 
                             practical_understanding, suggestions 
                      FROM feedback 
                      WHERE date BETWEEN ? AND ? 
                      " . (!empty($batch_id) ? " AND batch_id = ?" : "") . "
                      " . (!empty($student_id) ? " AND student_name LIKE ?" : "") . "
                      LIMIT 10";
            $params = [$start_date, $end_date];
            if (!empty($batch_id)) $params[] = $batch_id;
            if (!empty($student_id)) {
                $student_name = $db->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM students WHERE student_id = ?");
                $student_name->execute([$student_id]);
                $name = $student_name->fetch(PDO::FETCH_ASSOC);
                $params[] = $name['name'] ?? '';
            }
            break;
            
        default:
            return [];
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$data = getPreviewData($db, $batch_id, $start_date, $end_date, $student_id, $report_type);

if (empty($data)): ?>
    <div class="text-center py-8">
        <i class="fas fa-database text-gray-300 text-4xl mb-3"></i>
        <h4 class="text-lg font-medium text-gray-700 mb-1">No Data Found</h4>
        <p class="text-gray-500">No records match your current filter criteria</p>
    </div>
<?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <?php foreach(array_keys($data[0]) as $header): ?>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?= str_replace('_', ' ', ucfirst($header)) ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach($data as $row): ?>
                    <tr class="hover:bg-gray-50">
                        <?php foreach($row as $value): ?>
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <?= htmlspecialchars($value ?? '') ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="mt-4 p-3 bg-blue-50 rounded-lg">
        <div class="flex items-center">
            <i class="fas fa-info-circle text-blue-500 mr-2"></i>
            <p class="text-sm text-blue-700">
                Preview shows first 10 records. Full export will include all matching records.
            </p>
        </div>
    </div>
<?php endif; ?>