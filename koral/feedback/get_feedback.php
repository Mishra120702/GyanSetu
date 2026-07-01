<?php
require_once '../db_connection.php';

if (isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM feedback WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $feedback = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($feedback) {
        echo '
        <style>
            .feedback-container {
                max-width: 800px;
                margin: 0 auto;
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            }
            
            .feedback-card {
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                border-radius: 12px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                padding: 25px;
                margin-bottom: 20px;
                border-left: 5px solid #4a6ee0;
                transition: all 0.3s ease;
                animation: fadeIn 0.5s ease-out;
            }
            
            .feedback-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
            }
            
            .feedback-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            }
            
            .feedback-title {
                font-size: 1.5rem;
                font-weight: 600;
                color: #2c3e50;
                margin: 0;
            }
            
            .feedback-date {
                background-color: #4a6ee0;
                color: white;
                padding: 5px 12px;
                border-radius: 20px;
                font-size: 0.85rem;
                font-weight: 500;
            }
            
            .feedback-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin-bottom: 20px;
            }
            
            .feedback-item {
                background-color: white;
                padding: 15px;
                border-radius: 8px;
                box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
                transition: all 0.2s ease;
            }
            
            .feedback-item:hover {
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            }
            
            .feedback-label {
                font-weight: 600;
                color: #4a5568;
                margin-bottom: 5px;
                font-size: 0.9rem;
            }
            
            .feedback-value {
                color: #2d3748;
                font-size: 1rem;
            }
            
            .ratings-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin: 20px 0;
            }
            
            .rating-item {
                text-align: center;
                padding: 15px;
                background-color: white;
                border-radius: 8px;
                box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
                transition: all 0.2s ease;
            }
            
            .rating-item:hover {
                transform: scale(1.03);
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            }
            
            .rating-stars {
                font-size: 1.5rem;
                margin-bottom: 8px;
                color: #ffc107;
            }
            
            .rating-text {
                font-size: 0.9rem;
                color: #4a5568;
            }
            
            .satisfaction-badge {
                display: inline-block;
                padding: 8px 16px;
                border-radius: 20px;
                font-weight: 600;
                margin: 10px 0;
                animation: pulse 2s infinite;
            }
            
            .satisfied-yes {
                background-color: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            
            .satisfied-no {
                background-color: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            
            .feedback-section {
                margin-top: 25px;
                padding-top: 20px;
                border-top: 1px solid rgba(0, 0, 0, 0.1);
            }
            
            .section-title {
                font-size: 1.2rem;
                font-weight: 600;
                color: #2c3e50;
                margin-bottom: 15px;
                display: flex;
                align-items: center;
            }
            
            .section-title i {
                margin-right: 10px;
                color: #4a6ee0;
            }
            
            .feedback-text {
                background-color: white;
                padding: 15px;
                border-radius: 8px;
                box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
                line-height: 1.6;
                color: #4a5568;
                animation: slideIn 0.5s ease-out;
            }
            
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            @keyframes slideIn {
                from { opacity: 0; transform: translateX(-20px); }
                to { opacity: 1; transform: translateX(0); }
            }
            
            @keyframes pulse {
                0% { box-shadow: 0 0 0 0 rgba(74, 110, 224, 0.4); }
                70% { box-shadow: 0 0 0 10px rgba(74, 110, 224, 0); }
                100% { box-shadow: 0 0 0 0 rgba(74, 110, 224, 0); }
            }
            
            @media (max-width: 768px) {
                .feedback-grid {
                    grid-template-columns: 1fr;
                }
                
                .ratings-grid {
                    grid-template-columns: 1fr;
                }
                
                .feedback-header {
                    flex-direction: column;
                    align-items: flex-start;
                }
                
                .feedback-date {
                    margin-top: 10px;
                }
            }
        </style>
        
        <div class="feedback-container">
            <div class="feedback-card">
                <div class="feedback-header">
                    <h2 class="feedback-title">Feedback Details</h2>
                    <div class="feedback-date">' . htmlspecialchars($feedback['date']) . '</div>
                </div>
                
                <div class="feedback-grid">
                    <div class="feedback-item">
                        <div class="feedback-label">Student Name</div>
                        <div class="feedback-value">' . htmlspecialchars($feedback['student_name']) . '</div>
                    </div>
                    
                    <div class="feedback-item">
                        <div class="feedback-label">Email</div>
                        <div class="feedback-value">' . htmlspecialchars($feedback['email']) . '</div>
                    </div>
                    
                    <div class="feedback-item">
                        <div class="feedback-label">Regular Attendance</div>
                        <div class="feedback-value">' . htmlspecialchars($feedback['is_regular']) . '</div>
                    </div>
                    
                    <div class="feedback-item">
                        <div class="feedback-label">Batch ID</div>
                        <div class="feedback-value">' . htmlspecialchars($feedback['batch_id']) . '</div>
                    </div>
                    
                    <div class="feedback-item">
                        <div class="feedback-label">Course Name</div>
                        <div class="feedback-value">' . htmlspecialchars($feedback['course_name']) . '</div>
                    </div>
                </div>
                
                <div class="ratings-grid">
                    <div class="rating-item">
                        <div class="rating-stars">' . str_repeat('★', $feedback['class_rating']) . str_repeat('☆', 5 - $feedback['class_rating']) . '</div>
                        <div class="rating-text">Class Rating (' . $feedback['class_rating'] . '/5)</div>
                    </div>
                    
                    <div class="rating-item">
                        <div class="rating-stars">' . str_repeat('★', $feedback['assignment_understanding']) . str_repeat('☆', 5 - $feedback['assignment_understanding']) . '</div>
                        <div class="rating-text">Assignment Understanding (' . $feedback['assignment_understanding'] . '/5)</div>
                    </div>
                    
                    <div class="rating-item">
                        <div class="rating-stars">' . str_repeat('★', $feedback['practical_understanding']) . str_repeat('☆', 5 - $feedback['practical_understanding']) . '</div>
                        <div class="rating-text">Practical Understanding (' . $feedback['practical_understanding'] . '/5)</div>
                    </div>
                </div>
                
                <div class="satisfaction-badge ' . (($feedback['satisfied'] == 1 || $feedback['satisfied'] === 'Yes') ? 'satisfied-yes' : 'satisfied-no') . '">
                    Overall Satisfaction: ' . (($feedback['satisfied'] == 1 || $feedback['satisfied'] === 'Yes') ? 'Yes' : 'No') . '
                </div>';
        
        if (!empty($feedback['suggestions'])) {
            echo '
                <div class="feedback-section">
                    <div class="section-title">
                        <i>💡</i> Suggestions
                    </div>
                    <div class="feedback-text">' . nl2br(htmlspecialchars($feedback['suggestions'])) . '</div>
                </div>';
        }
        
        if (!empty($feedback['feedback_text'])) {
            echo '
                <div class="feedback-section">
                    <div class="section-title">
                        <i>📝</i> Additional Feedback
                    </div>
                    <div class="feedback-text">' . nl2br(htmlspecialchars($feedback['feedback_text'])) . '</div>
                </div>';
        }
        
        echo '
            </div>
        </div>';
    } else {
        echo '
        <style>
            .error-container {
                text-align: center;
                padding: 40px 20px;
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            }
            
            .error-icon {
                font-size: 4rem;
                margin-bottom: 20px;
                color: #e74c3c;
                animation: bounce 2s infinite;
            }
            
            .error-message {
                font-size: 1.2rem;
                color: #7f8c8d;
            }
            
            @keyframes bounce {
                0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
                40% { transform: translateY(-10px); }
                60% { transform: translateY(-5px); }
            }
        </style>
        
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <div class="error-message">Feedback not found.</div>
        </div>';
    }
}
?>