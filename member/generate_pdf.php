<?php
session_start();
require_once '../db.php';

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

if (isset($_GET['loan_id']) && isset($_GET['user_id'])) {
    $loan_id = intval($_GET['loan_id']);
    $user_id = intval($_GET['user_id']);
    
    // Verify user access
    $pdo = DB::pdo();
    
    if ($_SESSION['user']['role'] === 'member') {
        $stmt = $pdo->prepare("SELECT l.*, u.* FROM loans l JOIN users u ON l.user_id = u.member_id WHERE l.id = ? AND l.user_id = ?");
        $stmt->execute([$loan_id, $_SESSION['user']['member_id']]);
    } else {
        // Admin can access any loan
        $stmt = $pdo->prepare("SELECT l.*, u.* FROM loans l JOIN users u ON l.user_id = u.member_id WHERE l.id = ?");
        $stmt->execute([$loan_id]);
    }
    
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($data) {
        // Redirect to loan_agreement.php with appropriate parameters
        header('Location: ../member/loan_agreement.php?action=download&loan_id=' . $loan_id);
        exit;
    } else {
        die("Loan not found or access denied.");
    }
} else {
    die("Invalid parameters.");
}
?>