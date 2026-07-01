<?php
session_start();
require_once '../db_connection.php';

$attemptId = $_GET['attempt_id'] ?? 0;

try {
    // Fetch attempt details
    $stmt = $db->prepare("
        SELECT ta.*, t.title, t.subject, t.total_marks, t.passing_marks,
               s.first_name, s.last_name, s.student_id, s.email, s.batch_name_2,
               tq.question_text, tq.option_a, tq.option_b, tq.option_c, tq.option_d,
               tq.correct_answer, tq.marks, tq.explanation,
               tqa.selected_answer, tqa.is_correct
        FROM test_attempts ta
        JOIN tests t ON ta.test_id = t.id
        JOIN students s ON ta.student_id = s.student_id
        JOIN test_answers tqa ON ta.id = tqa.attempt_id
        JOIN test_questions tq ON tqa.question_id = tq.id
        WHERE ta.id = ?
        ORDER BY tq.question_order ASC
    ");
    $stmt->execute([$attemptId]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($details)) {
        echo json_encode(['success' => false, 'message' => 'Attempt not found']);
        exit;
    }
    
    $html = '
        <div class="space-y-6">
            <div class="bg-gradient-to-r from-blue-50 to-blue-100 p-4 rounded-xl">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <div class="text-sm font-semibold text-blue-700">Student</div>
                        <div class="text-lg font-bold text-gray-800">' . htmlspecialchars($details[0]['first_name'] . ' ' . $details[0]['last_name']) . '</div>
                        <div class="text-sm text-gray-600">' . $details[0]['student_id'] . '</div>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-blue-700">Test</div>
                        <div class="text-lg font-bold text-gray-800">' . htmlspecialchars($details[0]['title']) . '</div>
                        <div class="text-sm text-gray-600">' . $details[0]['subject'] . '</div>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-blue-700">Score</div>
                        <div class="text-2xl font-bold ' . ($details[0]['percentage'] >= ($details[0]['passing_marks'] / $details[0]['total_marks'] * 100) ? 'text-green-600' : 'text-red-600') . '">' . $details[0]['percentage'] . '%</div>
                        <div class="text-sm text-gray-600">' . $details[0]['obtained_marks'] . '/' . $details[0]['total_marks'] . ' marks</div>
                    </div>
                </div>
            </div>
            
            <div>
                <h4 class="font-bold text-gray-800 mb-3">Question-wise Analysis</h4>
                <div class="space-y-4">
    ';
    
    foreach ($details as $index => $question) {
        $html .= '
            <div class="border border-gray-200 rounded-xl p-4 ' . ($question['is_correct'] ? 'bg-green-50' : 'bg-red-50') . '">
                <div class="flex items-start justify-between mb-3">
                    <div class="flex items-center">
                        <div class="w-8 h-8 rounded-full ' . ($question['is_correct'] ? 'bg-green-500' : 'bg-red-500') . ' text-white flex items-center justify-center font-bold mr-3">
                            ' . ($index + 1) . '
                        </div>
                        <div class="font-semibold text-gray-800">' . htmlspecialchars(substr($question['question_text'], 0, 150)) . '</div>
                    </div>
                    <div class="text-sm ' . ($question['is_correct'] ? 'text-green-700' : 'text-red-700') . '">
                        ' . ($question['is_correct'] ? '✓ Correct' : '✗ Wrong') . '
                        <div class="font-bold">' . $question['marks'] . ' marks</div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 ml-11">
                    <div class="' . ($question['correct_answer'] === 'a' ? 'bg-green-100 border-green-300' : 'bg-gray-100') . ' border p-3 rounded-lg">
                        <div class="text-sm font-semibold">A: ' . htmlspecialchars($question['option_a']) . '</div>
                        ' . ($question['selected_answer'] === 'a' ? '<div class="text-xs text-blue-600 font-semibold">✓ Selected</div>' : '') . '
                    </div>
                    <div class="' . ($question['correct_answer'] === 'b' ? 'bg-green-100 border-green-300' : 'bg-gray-100') . ' border p-3 rounded-lg">
                        <div class="text-sm font-semibold">B: ' . htmlspecialchars($question['option_b']) . '</div>
                        ' . ($question['selected_answer'] === 'b' ? '<div class="text-xs text-blue-600 font-semibold">✓ Selected</div>' : '') . '
                    </div>
                    <div class="' . ($question['correct_answer'] === 'c' ? 'bg-green-100 border-green-300' : 'bg-gray-100') . ' border p-3 rounded-lg">
                        <div class="text-sm font-semibold">C: ' . htmlspecialchars($question['option_c']) . '</div>
                        ' . ($question['selected_answer'] === 'c' ? '<div class="text-xs text-blue-600 font-semibold">✓ Selected</div>' : '') . '
                    </div>
                    <div class="' . ($question['correct_answer'] === 'd' ? 'bg-green-100 border-green-300' : 'bg-gray-100') . ' border p-3 rounded-lg">
                        <div class="text-sm font-semibold">D: ' . htmlspecialchars($question['option_d']) . '</div>
                        ' . ($question['selected_answer'] === 'd' ? '<div class="text-xs text-blue-600 font-semibold">✓ Selected</div>' : '') . '
                    </div>
                </div>
                
                ' . ($question['explanation'] ? '
                <div class="mt-3 ml-11 bg-blue-50 border border-blue-200 p-3 rounded-lg">
                    <div class="text-sm font-semibold text-blue-700 mb-1">Explanation:</div>
                    <div class="text-sm text-gray-700">' . htmlspecialchars($question['explanation']) . '</div>
                </div>' : '') . '
            </div>
        ';
    }
    
    $html .= '
                </div>
            </div>
        </div>
    ';
    
    echo json_encode(['success' => true, 'html' => $html]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>