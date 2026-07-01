<?php
// payment_functions.php - Payment dashboard helper functions
include '../db_connection.php';

class PaymentDashboard {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    // Get payment statistics
    public function getPaymentStatistics($dateFrom = null, $dateTo = null) {
        try {
            $query = "SELECT 
                COUNT(*) as total_payments,
                SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) as verified_payments,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_payments,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_payments,
                SUM(amount) as total_amount,
                SUM(CASE WHEN status = 'verified' THEN amount ELSE 0 END) as verified_amount,
                SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending_amount,
                SUM(CASE WHEN status = 'rejected' THEN amount ELSE 0 END) as rejected_amount,
                AVG(amount) as average_amount,
                MAX(amount) as max_amount,
                MIN(amount) as min_amount
            FROM transactions";
            
            $params = [];
            
            if ($dateFrom && $dateTo) {
                $query .= " WHERE transaction_date BETWEEN ? AND ?";
                $params[] = $dateFrom;
                $params[] = $dateTo;
            } elseif ($dateFrom) {
                $query .= " WHERE transaction_date >= ?";
                $params[] = $dateFrom;
            } elseif ($dateTo) {
                $query .= " WHERE transaction_date <= ?";
                $params[] = $dateTo;
            }
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting payment statistics: " . $e->getMessage());
            return null;
        }
    }
    
    // Get payments by batch
    public function getPaymentsByBatch($limit = 10) {
        try {
            $query = "SELECT 
                b.batch_name,
                b.batch_id,
                COUNT(t.id) as payment_count,
                SUM(t.amount) as total_amount,
                SUM(CASE WHEN t.status = 'verified' THEN t.amount ELSE 0 END) as verified_amount,
                SUM(CASE WHEN t.status = 'pending' THEN t.amount ELSE 0 END) as pending_amount,
                AVG(t.amount) as average_payment
            FROM batches b
            LEFT JOIN transactions t ON b.batch_id = t.batch_id
            WHERE b.status = 'ongoing'
            GROUP BY b.batch_id, b.batch_name
            ORDER BY total_amount DESC
            LIMIT ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting payments by batch: " . $e->getMessage());
            return [];
        }
    }
    
    // Get recent payments
    public function getRecentPayments($limit = 10) {
        try {
            $query = "SELECT 
                t.*,
                b.batch_name,
                s.first_name,
                s.last_name,
                s.fees_status,
                s.total_fees_paid,
                s.enrollment_fees,
                u.name as verified_by_name
            FROM transactions t
            LEFT JOIN batches b ON t.batch_id = b.batch_id
            LEFT JOIN students s ON t.student_id = s.student_id
            LEFT JOIN users u ON t.verified_by = u.id
            ORDER BY t.uploaded_at DESC
            LIMIT ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting recent payments: " . $e->getMessage());
            return [];
        }
    }
    
    // Get payment trends (daily/weekly/monthly)
    public function getPaymentTrends($period = 'monthly', $limit = 12) {
        try {
            $format = '';
            $interval = '';
            
            switch ($period) {
                case 'daily':
                    $format = '%Y-%m-%d';
                    $interval = 'DAY';
                    break;
                case 'weekly':
                    $format = '%Y-%u';
                    $interval = 'WEEK';
                    break;
                case 'monthly':
                    $format = '%Y-%m';
                    $interval = 'MONTH';
                    break;
                default:
                    $format = '%Y-%m';
                    $interval = 'MONTH';
            }
            
            $query = "SELECT 
                DATE_FORMAT(transaction_date, ?) as period,
                COUNT(*) as payment_count,
                SUM(amount) as total_amount,
                SUM(CASE WHEN status = 'verified' THEN amount ELSE 0 END) as verified_amount,
                SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending_amount,
                AVG(amount) as average_amount
            FROM transactions
            WHERE transaction_date >= DATE_SUB(NOW(), INTERVAL ? $interval)
            GROUP BY period
            ORDER BY period DESC
            LIMIT ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$format, $limit, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting payment trends: " . $e->getMessage());
            return [];
        }
    }
    
    // Get payment modes summary
    public function getPaymentModeSummary() {
        try {
            $query = "SELECT 
                payment_mode,
                COUNT(*) as count,
                SUM(amount) as total_amount,
                AVG(amount) as average_amount,
                SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) as verified_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
            FROM transactions
            GROUP BY payment_mode
            ORDER BY total_amount DESC";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting payment mode summary: " . $e->getMessage());
            return [];
        }
    }
    
    // Get pending fee installments
    public function getPendingInstallments() {
        try {
            $query = "SELECT 
                fi.*,
                s.first_name,
                s.last_name,
                s.email,
                s.phone_number,
                s.batch_name,
                s.fees_status,
                s.total_fees_paid,
                s.enrollment_fees,
                b.batch_name as batch_full_name,
                DATEDIFF(fi.due_date, CURDATE()) as days_remaining,
                CASE 
                    WHEN fi.due_date < CURDATE() THEN 'overdue'
                    WHEN DATEDIFF(fi.due_date, CURDATE()) <= 7 THEN 'due_soon'
                    ELSE 'upcoming'
                END as due_status
            FROM fee_installments fi
            LEFT JOIN students s ON fi.student_id = s.student_id
            LEFT JOIN batches b ON s.batch_name = b.batch_id
            WHERE fi.payment_status IN ('pending', 'overdue')
            AND s.current_status = 'active'
            ORDER BY fi.due_date ASC, fi.installment_number ASC";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting pending installments: " . $e->getMessage());
            return [];
        }
    }
    
    // Get student fee overview
    public function getStudentFeeOverview($student_id = null) {
        try {
            $query = "SELECT 
                s.student_id,
                CONCAT(s.first_name, ' ', s.last_name) as student_name,
                s.email,
                s.phone_number,
                s.batch_name,
                b.batch_name as batch_full_name,
                s.enrollment_fees,
                s.total_fees_paid,
                s.fees_status,
                s.last_payment_date,
                s.next_payment_due_date,
                s.current_status,
                COUNT(fi.id) as total_installments,
                SUM(CASE WHEN fi.payment_status = 'paid' THEN 1 ELSE 0 END) as paid_installments,
                SUM(CASE WHEN fi.payment_status IN ('pending', 'overdue') THEN 1 ELSE 0 END) as pending_installments,
                SUM(fi.installment_amount) as total_installment_amount,
                SUM(fi.paid_amount) as total_paid_installments,
                COUNT(t.id) as total_transactions,
                SUM(CASE WHEN t.status = 'verified' THEN t.amount ELSE 0 END) as verified_transaction_amount
            FROM students s
            LEFT JOIN batches b ON s.batch_name = b.batch_id
            LEFT JOIN fee_installments fi ON s.student_id = fi.student_id
            LEFT JOIN transactions t ON s.student_id = t.student_id
            WHERE s.current_status = 'active'";
            
            $params = [];
            
            if ($student_id) {
                $query .= " AND s.student_id = ?";
                $params[] = $student_id;
            }
            
            $query .= " GROUP BY s.student_id
                ORDER BY s.total_fees_paid DESC, s.last_payment_date DESC";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting student fee overview: " . $e->getMessage());
            return [];
        }
    }
    
    // Get top paying students
    public function getTopPayingStudents($limit = 10) {
        try {
            $query = "SELECT 
                s.student_id,
                CONCAT(s.first_name, ' ', s.last_name) as student_name,
                s.batch_name,
                b.batch_name as batch_full_name,
                s.enrollment_fees,
                s.total_fees_paid,
                s.fees_status,
                (s.total_fees_paid / s.enrollment_fees * 100) as payment_percentage,
                COUNT(t.id) as transaction_count,
                MAX(t.transaction_date) as last_payment_date
            FROM students s
            LEFT JOIN batches b ON s.batch_name = b.batch_id
            LEFT JOIN transactions t ON s.student_id = t.student_id AND t.status = 'verified'
            WHERE s.current_status = 'active'
            AND s.enrollment_fees > 0
            GROUP BY s.student_id
            ORDER BY s.total_fees_paid DESC
            LIMIT ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting top paying students: " . $e->getMessage());
            return [];
        }
    }
    
    // Get fee collection summary by date range
    public function getFeeCollectionSummary($startDate, $endDate) {
        try {
            $query = "SELECT 
                DATE(t.transaction_date) as collection_date,
                COUNT(*) as transaction_count,
                SUM(t.amount) as total_collected,
                SUM(CASE WHEN t.payment_mode = 'bank_transfer' THEN t.amount ELSE 0 END) as bank_transfer_total,
                SUM(CASE WHEN t.payment_mode = 'cash' THEN t.amount ELSE 0 END) as cash_total,
                SUM(CASE WHEN t.payment_mode = 'cheque' THEN t.amount ELSE 0 END) as cheque_total,
                SUM(CASE WHEN t.payment_mode = 'other' THEN t.amount ELSE 0 END) as other_total,
                COUNT(DISTINCT t.student_id) as unique_students,
                COUNT(DISTINCT t.batch_id) as unique_batches
            FROM transactions t
            WHERE t.status = 'verified'
            AND t.transaction_date BETWEEN ? AND ?
            GROUP BY DATE(t.transaction_date)
            ORDER BY collection_date DESC";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$startDate, $endDate]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting fee collection summary: " . $e->getMessage());
            return [];
        }
    }
}
?>