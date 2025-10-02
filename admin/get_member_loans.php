<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

$member_id = $_GET['member_id'] ?? '';

try {
    $pdo = DB::pdo();
    
    // Get active loans for this member using correct column names from your schema
    $stmt = $pdo->prepare("
        SELECT 
            id,
            principal as loan_amount,
            COALESCE(remaining_balance, balance, principal) as remaining_balance,
            interest_rate,
            COALESCE(due_date, DATE_ADD(created_at, INTERVAL term_months MONTH)) as due_date,
            status,
            loan_number
        FROM loans 
        WHERE user_id = ? AND status IN ('active', 'approved')
        ORDER BY created_at DESC
    ");
    $stmt->execute([$member_id]);
    $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($loans);
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}
?>