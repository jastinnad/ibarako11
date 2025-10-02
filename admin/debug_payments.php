<?php
// debug_payments.php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$member_id = $_GET['member_id'] ?? 'MBR-0001';

echo "<h3>Payment Debug for Member: $member_id</h3>";

try {
    $pdo = DB::pdo();
    
    // Check users table structure
    echo "<h4>Users Table Structure:</h4>";
    $stmt = $pdo->prepare("DESCRIBE users");
    $stmt->execute();
    $user_structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($user_structure, true) . "</pre>";
    
    // Get user details using member_id directly
    echo "<h4>User Details:</h4>";
    $stmt = $pdo->prepare("SELECT * FROM users WHERE member_id = ?");
    $stmt->execute([$member_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "<p>Member not found</p>";
        exit;
    }
    
    echo "<pre>" . print_r($user, true) . "</pre>";
    
    // Check loans table structure
    echo "<h4>Loans Table Structure:</h4>";
    $stmt = $pdo->prepare("DESCRIBE loans");
    $stmt->execute();
    $loan_structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($loan_structure, true) . "</pre>";
    
    // Get loans for this member - try different possible column names
    echo "<h4>Loans for Member:</h4>";
    
    // Try member_id column
    $stmt = $pdo->prepare("SELECT * FROM loans WHERE member_id = ?");
    $stmt->execute([$member_id]);
    $loans_by_member = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>Loans by member_id: " . count($loans_by_member) . " found</p>";
    
    // Try user_id column with member_id (if user_id in loans table is actually the member_id)
    $stmt = $pdo->prepare("SELECT * FROM loans WHERE user_id = ?");
    $stmt->execute([$member_id]);
    $loans_by_user = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>Loans by user_id: " . count($loans_by_user) . " found</p>";
    
    // Show all loans if any found
    $loans = array_merge($loans_by_member, $loans_by_user);
    if (empty($loans)) {
        echo "<p>No loans found using member_id or user_id</p>";
        
        // Show all loans in system to see what exists
        echo "<h4>All Loans in System (first 10):</h4>";
        $stmt = $pdo->prepare("SELECT * FROM loans LIMIT 10");
        $stmt->execute();
        $all_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>" . print_r($all_loans, true) . "</pre>";
    } else {
        foreach ($loans as $loan) {
            echo "<pre>Loan: " . print_r($loan, true) . "</pre>";
            
            // Check loan_payments table structure
            echo "<h5>Loan Payments Table Structure:</h5>";
            try {
                $stmt = $pdo->prepare("DESCRIBE loan_payments");
                $stmt->execute();
                $payment_structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo "<pre>" . print_r($payment_structure, true) . "</pre>";
            } catch (Exception $e) {
                echo "<p>loan_payments table doesn't exist: " . $e->getMessage() . "</p>";
            }
            
            // Check payments for this loan
            echo "<h5>Payments for Loan ID " . $loan['id'] . ":</h5>";
            try {
                $stmt = $pdo->prepare("SELECT * FROM loan_payments WHERE loan_id = ?");
                $stmt->execute([$loan['id']]);
                $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($payments)) {
                    echo "<p>No payments found in loan_payments table</p>";
                } else {
                    echo "<p>Found " . count($payments) . " payments:</p>";
                    foreach ($payments as $payment) {
                        echo "<pre>" . print_r($payment, true) . "</pre>";
                    }
                }
            } catch (Exception $e) {
                echo "<p>Error querying loan_payments: " . $e->getMessage() . "</p>";
            }
        }
    }
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
}
?>