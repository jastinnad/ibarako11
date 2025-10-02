<?php
session_start();
require_once '../db.php';
require_once '../notifications.php';

// Check if user is logged in and is approved member
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'member' || $_SESSION['user']['status'] !== 'active') {
    header('Location: ../login.php');
    exit;
}

$user = $_SESSION['user'];
$error = '';
$success = '';

// Check if user has been active for at least 3 months
$is_eligible_for_loan = false;
$membership_duration = '';

try {
    $pdo = DB::pdo();
    
    // Fetch user's creation date from database
    $user_stmt = $pdo->prepare("SELECT created_at FROM users WHERE member_id = ?");
    $user_stmt->execute([$user['member_id']]);
    $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user_data && !empty($user_data['created_at'])) {
        // Calculate membership duration
        $created_at = new DateTime($user_data['created_at']);
        $current_date = new DateTime();
        $interval = $current_date->diff($created_at);
        $months_active = ($interval->y * 12) + $interval->m;
        
        $is_eligible_for_loan = $months_active >= 3;
        $membership_duration = $months_active . ' month' . ($months_active != 1 ? 's' : '');
    } else {
        $error = "Unable to verify membership duration. Please contact administrator.";
        $membership_duration = 'Unknown';
    }
    
} catch (Exception $e) {
    $error = "Error checking membership eligibility: " . $e->getMessage();
    $membership_duration = 'Error';
}

// Handle loan application form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_loan'])) {
    // Check if user is eligible for loans
    if (!$is_eligible_for_loan) {
        $error = "You must be an active member for at least 3 months to apply for a loan. Your current membership duration: " . $membership_duration;
    }
    // Check if agreement is accepted
    elseif (!isset($_POST['agree_terms'])) {
        $error = "You must accept the loan agreement terms";
    } else {
        $principal = floatval($_POST['principal']);
        $term_months = intval($_POST['term_months']);
        $purpose = trim($_POST['purpose']);
        $payment_method = $_POST['payment_method'];
        $account_details = trim($_POST['account_details']);
        $account_name = trim($_POST['account_name']);

        // Validate inputs
        if ($principal < 1000 || $principal > 50000) {
            $error = "Loan amount must be between ₱1,000 and ₱50,000";
        } elseif (!in_array($term_months, [3, 6, 9, 12])) {
            $error = "Please select a valid loan term";
        } elseif (empty($purpose)) {
            $error = "Please enter loan purpose";
        } elseif (empty($payment_method)) {
            $error = "Please select payment method";
        } elseif (empty($account_details) || empty($account_name)) {
            $error = "Please fill in all required account details";
        } else {
            try {
                $pdo = DB::pdo();
                
                // Calculate interest and monthly payment based on 2% monthly
                $interest_rate = 2.0; // 2% monthly interest
                $total_interest = $principal * ($interest_rate / 100) * $term_months;
                $total_amount = $principal + $total_interest;
                $monthly_payment = $total_amount / $term_months;
                
                // Generate loan number
                $loan_number = 'LN-' . date('YmdHis') . '-' . $user['member_id'];
                
                // Insert loan application using cleaned table structure
                $stmt = $pdo->prepare("
                    INSERT INTO loans (user_id, loan_number, principal, term_months, interest_rate, 
                                     monthly_payment, total_amount, loan_type, purpose, 
                                     payment_method, account_details, account_name, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Personal Loan', ?, ?, ?, ?, 'pending')
                ");

                $stmt->execute([
                    $user['member_id'], $loan_number, $principal, $term_months, $interest_rate,
                    $monthly_payment, $total_amount, $purpose, $payment_method, 
                    $account_details, $account_name
                ]);
                
                $loan_id = $pdo->lastInsertId();
                
                // NOTIFY ADMIN ABOUT THE LOAN APPLICATION
                Notifications::notifyAdmin(
                    'loan_application',
                    'New Loan Application',
                    $user['firstname'] . ' ' . $user['lastname'] . ' ('.$user['member_id'].') has submitted a loan application for ₱' . number_format($principal, 2),
                    $user['member_id'], // Use member_id instead of id
                    $loan_id
                );
                
                $success = "Loan application submitted successfully! The admin will review your application.";
                
            } catch (Exception $e) {
                $error = "Error submitting loan application: " . $e->getMessage();
            }
        }
    }
}

// Pagination and search
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 7;
$offset = ($page - 1) * $limit;

// Get user's loans with search and pagination
$pdo = DB::pdo();

// Build base query
$query = "SELECT * FROM loans WHERE user_id = ?";
$countQuery = "SELECT COUNT(*) FROM loans WHERE user_id = ?";
$params = [$user['member_id']];
$countParams = [$user['member_id']];

// Add search filter if provided
if (!empty($search)) {
    $searchTerm = "%$search%";
    $query .= " AND (loan_number LIKE ? OR principal LIKE ? OR purpose LIKE ? OR status LIKE ?)";
    $countQuery .= " AND (loan_number LIKE ? OR principal LIKE ? OR purpose LIKE ? OR status LIKE ?)";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $countParams = array_merge($countParams, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

// Add ordering and pagination
$query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

// Get loans
$user_loans = $pdo->prepare($query);
$user_loans->execute($params);
$loans = $user_loans->fetchAll();

// Get total count for pagination
$count_stmt = $pdo->prepare($countQuery);
$count_stmt->execute($countParams);
$total_loans = $count_stmt->fetchColumn();
$total_pages = ceil($total_loans / $limit);

// Function to calculate loan progress
function calculateLoanProgress($loan_id, $pdo) {
    // Get total number of payments (term_months * 2 for semi-monthly)
    $loan_stmt = $pdo->prepare("SELECT term_months FROM loans WHERE id = ?");
    $loan_stmt->execute([$loan_id]);
    $loan = $loan_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$loan) return '0/0';
    
    $total_payments = $loan['term_months'] * 2;
    
    // Count verified payments for this loan
    $payment_stmt = $pdo->prepare("SELECT COUNT(*) as paid_count FROM loan_payments WHERE loan_id = ? AND status = 'verified'");
    $payment_stmt->execute([$loan_id]);
    $payment_count = $payment_stmt->fetch(PDO::FETCH_ASSOC);
    
    $paid_payments = $payment_count['paid_count'];
    $remaining_payments = $total_payments - $paid_payments;
    
    return $remaining_payments . '/' . $total_payments;
}

// Handle loan details view
$selected_loan = null;
if (isset($_GET['view_loan']) && is_numeric($_GET['view_loan'])) {
    $loan_id = intval($_GET['view_loan']);
    
    // Verify that the loan belongs to the current user
    $loan_stmt = $pdo->prepare("SELECT * FROM loans WHERE id = ? AND user_id = ?");
    $loan_stmt->execute([$loan_id, $user['member_id']]);
    $selected_loan = $loan_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$selected_loan) {
        $error = "Loan not found or access denied";
    }
}

// Get payment history for the selected loan
$payment_history = [];
if ($selected_loan) {
    $payments_stmt = $pdo->prepare("
        SELECT * FROM loan_payments 
        WHERE loan_id = ? 
        ORDER BY payment_date DESC
    ");
    $payments_stmt->execute([$selected_loan['id']]);
    $payment_history = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Loans - iBarako Loan System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
    /* Import modern fonts */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    
    :root {
        --sidebar-bg: linear-gradient(180deg, #F4E9D7 0%, #1a365d 80%);
        --sidebar-hover: rgba(255, 255, 255, 0.08);
        --sidebar-active: rgba(255, 255, 255, 0.12);
        --primary-gradient: linear-gradient(135deg, #2b6cb0 0%, #3182ce 100%);
        --success-gradient: linear-gradient(135deg, #38a169 0%, #48bb78 100%);
        --warning-gradient: linear-gradient(135deg, #dd6b20 0%, #ed8936 100%);
        --info-gradient: linear-gradient(135deg, #0987a0 0%, #00a3c4 100%);
        --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        --card-hover-shadow: 0 8px 30px rgba(0, 0, 0, 0.09);
    }
    
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background-color: #f7fafc;
        color: #4a5568;
        line-height: 1.6;
    }
    
    .sidebar {
        background: var(--sidebar-bg);
        min-height: 100vh;
        width: 250px;
        position: fixed;
        left: 0;
        top: 0;
        z-index: 1000;
        transition: all 0.3s ease;
        box-shadow: 2px 0 15px rgba(0, 0, 0, 0.08);
    }
    
    .main-content {
        margin-left: 250px;
        width: calc(100% - 250px);
        transition: all 0.3s ease;
        background-color: #f7fafc;
		padding-bottom: 2rem;
    }
    
    .sidebar-brand {
        padding: 1.5rem 1rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        background: linear-gradient(135deg, #2c5282 0%, #ffffff 100%);
    }
    
    .sidebar-brand h6 {
        font-weight: 700;
        font-size: 1.25rem;
        margin-bottom: 0.25rem;
        color: white;
    }
    
    .sidebar-brand small {
        font-size: 0.75rem;
        opacity: 0.8;
        font-weight: 400;
        color: #e2e8f0;
    }
    
    .nav-link {
        color: rgba(255, 255, 255, 0.8);
        padding: 0.85rem 1rem;
        border-left: 4px solid transparent;
        transition: all 0.3s ease;
        margin: 0.1rem 0.5rem;
        border-radius: 8px;
        font-weight: 500;
        font-size: 0.9rem;
    }
    
    .nav-link:hover {
        color: white;
        background: var(--sidebar-hover);
        border-left-color: rgba(255, 255, 255, 0.3);
        transform: translateX(2px);
    }
    
    .nav-link.active {
        color: white;
        background: var(--sidebar-active);
        border-left-color: #3182ce;
        box-shadow: 0 2px 8px rgba(49, 130, 206, 0.2);
    }
    
    .nav-link i {
        width: 20px;
        text-align: center;
        margin-right: 0.75rem;
        font-size: 1rem;
        opacity: 0.9;
    }
    
    /* Main navbar - EXACT SAME as Dashboard, My Profile and Contributions */
    .navbar {
        background: white !important;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .navbar h4 {
        font-weight: 600;
        color: #2d3748;
        font-size: 1.3rem;
        margin-bottom: 0;
    }
    
    /* FIX: Make sure ALL badge classes use the same styling */
    .badge.bg-success, .badge.bg-primary {
        background: var(--success-gradient) !important;
        border: none;
        padding: 0.5rem 1rem;
        font-weight: 500;
        border-radius: 20px;
        font-size: 0.8rem;
        color: white;
    }
    
    /* Card Styling - Matching Dashboard */
    .card {
        border: none;
        border-radius: 12px;
        box-shadow: var(--card-shadow);
        transition: all 0.3s ease;
        background: white;
        overflow: hidden;
        border: 1px solid #edf2f7;
    }
    
    .card:hover {
        box-shadow: var(--card-hover-shadow);
        transform: translateY(-1px);
    }
    
    .card-header {
        background: white !important;
        border-bottom: 1px solid #edf2f7;
        padding: 1.25rem 1.5rem;
    }
    
    .card-header h5 {
        font-weight: 600;
        color: #2d3748;
        font-size: 1.1rem;
        margin-bottom: 0;
    }
    
    .card-body {
        padding: 1.5rem;
    }
    
    /* Loan Form Styling */
    .loan-form {
        display: none;
        transition: all 0.3s ease-in-out;
        opacity: 0;
        transform: translateY(-10px);
    }
    
    .loan-form.show {
        display: block;
        opacity: 1;
        transform: translateY(0);
    }
    
    .card-header.bg-primary {
        background: var(--primary-gradient) !important;
        border: none;
    }
    
    /* Button Styling - Normal size for all buttons */
    .btn {
        border: none;
        border-radius: 8px;
        font-weight: 600;
        padding: 0.625rem 1.25rem;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    
    .btn-primary {
        background: var(--primary-gradient);
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(43, 108, 176, 0.3);
    }
    
    .btn-secondary {
        background: #718096;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #4a5568;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(113, 128, 150, 0.3);
    }
    
    .btn-outline-secondary {
        background: transparent;
        color: #718096;
        border: 1px solid #e2e8f0;
    }
    
    .btn-outline-secondary:hover {
        background: #f7fafc;
        color: #4a5568;
        transform: translateY(-1px);
    }
    
    .btn-outline-primary {
        background: transparent;
        color: #3182ce;
        border: 1px solid #3182ce;
    }
    
    .btn-outline-primary:hover {
        background: #3182ce;
        color: white;
        transform: translateY(-1px);
    }
    
    .btn-light {
        background: white;
        color: #4a5568;
        border: 1px solid #e2e8f0;
    }
    
    .btn-light:hover {
        background: #f7fafc;
        transform: translateY(-1px);
    }
    
    /* Make form buttons slightly larger */
    .btn-lg {
        padding: 0.75rem 1.5rem;
        font-size: 0.9rem;
    }
    
    .btn-sm {
        padding: 0.375rem 0.75rem;
        font-size: 0.8rem;
    }
    
    /* Form Styling */
    .form-label {
        font-weight: 600;
        color: #2d3748;
        margin-bottom: 0.5rem;
    }
    
    .form-control, .form-select {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.75rem;
        transition: all 0.3s ease;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: #3182ce;
        box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
    }
    
    .text-muted {
        color: #718096 !important;
        font-size: 0.85rem;
        font-weight: 500;
    }
    
    /* Table Styling */
    .table {
        margin-bottom: 0;
        border-color: #e2e8f0;
    }
    
    .table th {
        font-weight: 600;
        color: #2d3748;
        background-color: #f8fafc;
        border-color: #e2e8f0;
        padding: 1rem 0.75rem;
        font-size: 0.9rem;
    }
    
    .table td {
        border-color: #e2e8f0;
        color: #4a5568;
        padding: 1rem 0.75rem;
        vertical-align: middle;
    }
    
    .table-striped tbody tr:nth-of-type(odd) {
        background-color: #fafbfc;
    }
    
    /* Badge Styling */
    .badge {
        font-weight: 500;
        font-size: 0.75rem;
        padding: 0.35em 0.65em;
        border-radius: 6px;
    }
    
    .bg-success {
        background: var(--success-gradient) !important;
    }
    
    .bg-warning {
        background: var(--warning-gradient) !important;
    }
    
    .bg-primary {
        background: var(--primary-gradient) !important;
    }
    
    .bg-info {
        background: var(--info-gradient) !important;
    }
    
    .bg-danger {
        background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%) !important;
    }
    
    /* Alert Styling */
    .alert {
        border: none;
        border-radius: 10px;
        font-weight: 500;
        border-left: 4px solid;
        font-size: 0.875rem;
		padding: 0.75rem 1rem;
    }
    
    .alert-danger {
        background: #fed7d7;
        color: #c53030;
        border-left-color: #fc8181;
    }
    
    .alert-success {
        background: #f0fff4;
        color: #38a169;
        border-left-color: #48bb78;
    }
    
    .alert-info {
        background: #ebf8ff;
        color: #2b6cb0;
        border-left-color: #4299e1;
    }
    
    /* Empty State Styling */
    .text-center.py-4 {
        padding: 3rem 1rem;
    }
    
    .fa-file-invoice-dollar.fa-3x {
        font-size: 3rem;
        color: #cbd5e0;
        margin-bottom: 1rem;
    }
    
    /* Page Header Styling */
    .d-flex.justify-content-between.align-items-center.mb-4 h5 {
        font-weight: 600;
        color: #2d3748;
        font-size: 1.25rem;
        margin-bottom: 0;
    }
    
    /* Loan Details Specific Styling */
    .loan-details-card {
        border-left: 4px solid #3182ce;
    }
    
    .loan-summary {
        background: #f8fafc;
        border-radius: 8px;
        padding: 1.5rem;
        border: 1px solid #e2e8f0;
    }
    
    .progress-badge {
        background: var(--primary-gradient);
        color: white;
        padding: 0.25rem 0.5rem;
        border-radius: 6px;
        font-weight: 600;
        font-size: 0.8rem;
    }
    
    .progress {
        height: 5px;
        background-color: #e2e8f0;
    }
    
    .progress-bar {
        background: var(--success-gradient);
    }
    
    /* Payment Status Colors */
    .payment-status-verified {
        color: #38a169;
    }
    
    .payment-status-pending {
        color: #ed8936;
    }
    
    .payment-status-rejected {
        color: #e53e3e;
    }
    
    /* Modal Styling */
    .modal-header.bg-primary {
        background: var(--primary-gradient) !important;
        border: none;
    }
    
    /* Search Bar Styling */
    .search-container {
        max-width: 300px;
    }
    
    .search-input-group {
        position: relative;
    }
    
    .search-input-group .form-control {
        padding-left: 2.5rem;
    }
    
    .search-input-group .search-icon {
        position: absolute;
        left: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: #718096;
        z-index: 5;
    }
    
    /* Pagination Styling */
    .pagination-container {
        display: flex;
        justify-content: center;
        margin-top: 1.5rem;
        padding-top: 1rem;
        border-top: 1px solid #e2e8f0;
    }
    
    .pagination {
        margin-bottom: 0;
    }
    
    .page-item.active .page-link {
        background: var(--primary-gradient);
        border-color: #3182ce;
    }
    
    .page-link {
        color: #4a5568;
        border: 1px solid #e2e8f0;
        padding: 0.5rem 0.75rem;
        margin: 0 0.15rem;
        border-radius: 6px;
        font-weight: 500;
    }
    
    .page-link:hover {
        color: #2d3748;
        background-color: #f7fafc;
        border-color: #cbd5e0;
    }
    
    .page-item.disabled .page-link {
        color: #a0aec0;
        background-color: #f7fafc;
        border-color: #e2e8f0;
    }
    
    /* Account Details Card Styling */
    .account-details-card {
        border-left: 4px solid #0d6efd;
    }
    
    .form-required::after {
        content: " *";
        color: #dc3545;
    }
    
    /* Responsive Design */
    @media (max-width: 768px) {
        .sidebar {
            width: 100%;
            height: auto;
            position: relative;
        }
        
        .main-content {
            margin-left: 0;
            width: 100%;
        }
        
        .table-responsive {
            font-size: 0.875rem;
        }
        
        .card-body {
            padding: 1rem;
        }
        
        .navbar {
            padding: 0.75rem 1rem;
        }
        
        .navbar h4 {
            font-size: 1.1rem;
        }
        
        .d-flex.justify-content-between.align-items-center.mb-4 {
            flex-direction: column;
            gap: 1rem;
            align-items: flex-start !important;
        }
        
        .search-container {
            max-width: 100%;
            width: 100%;
        }
        
        .loan-summary {
            padding: 1rem;
        }
    }
    
    @media (max-width: 576px) {
        .card-header {
            padding: 1rem;
        }
        
        .card-header h5 {
            font-size: 1rem;
        }
        
        .row .col-md-6 {
            margin-bottom: 1rem;
        }
        
        .btn-lg {
            padding: 0.75rem 1.5rem;
            font-size: 0.9rem;
        }
        
        .pagination {
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .page-item {
            margin-bottom: 0.25rem;
        }
    }
</style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Sidebar Navigation -->
            <div class="sidebar">
                <div class="sidebar-brand text-white">
                    <div class="text-center mb-3">
						<img src="../ibarako_logov2.PNG" alt="iBarako Logo" class="logo" style="max-height: 45px;">
					</div>
                    <small class="text-light">Member Portal</small>
                </div>
                <nav class="nav flex-column mt-3">
                    <a class="nav-link" href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>Dashboard
                    </a>
                    <a class="nav-link" href="profile.php">
                        <i class="fas fa-user"></i>My Profile
                    </a>
                    <a class="nav-link" href="contributions.php">
                        <i class="fas fa-chart-line"></i>Contributions
                    </a>
                    <a class="nav-link active" href="loans.php">
                        <i class="fas fa-file-invoice-dollar"></i>My Loans
                    </a>
                    <a class="nav-link" href="payments.php">
                        <i class="fas fa-credit-card"></i>Loan Payments
                    </a>
                    <div class="mt-auto">
                        <a class="nav-link text-warning" href="../logout.php">
                            <i class="fas fa-sign-out-alt"></i>Logout
                        </a>
                    </div>
                </nav>
            </div>

            <!-- Main Content -->
            <div class="main-content">
                <nav class="navbar navbar-light bg-white border-bottom shadow-sm">
                    <div class="container-fluid">
                        <h4 class="mb-0 text-dark">My Loans</h4>
                        <span class="badge bg-primary">Member ID: <?= $user['member_id'] ?></span>
                    </div>
                </nav>

                <div class="container-fluid mt-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= $success ?></div>
                    <?php endif; ?>

                    <!-- Loan Eligibility Notification -->
                    <?php if (!$is_eligible_for_loan): ?>
                        <div class="alert alert-info">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-info-circle fa-lg me-3"></i>
                                <div>
                                    <h6 class="mb-1">Loan Application Eligibility</h6>
                                    <p class="mb-0">
                                        You need to be an active member for at least 3 months to apply for loans. 
                                        Your current membership duration: <strong><?= $membership_duration ?></strong>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Back to Loans List (only shown when viewing loan details) -->
                    <?php if ($selected_loan): ?>
                        <div class="mb-3">
                            <a href="loans.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Loans List
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- Loan Details View -->
                    <?php if ($selected_loan): 
                        // Calculate progress for the selected loan
                        $progress = calculateLoanProgress($selected_loan['id'], $pdo);
                        list($remaining, $total) = explode('/', $progress);
                        $percentage = $total > 0 ? (($total - $remaining) / $total) * 100 : 0;
                    ?>
                        <div class="card mb-4 loan-details-card">
                            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-file-invoice-dollar me-2"></i>
                                    Loan Details - <?= $selected_loan['loan_number'] ?>
                                </h5>
                                <span class="badge bg-<?= 
                                    $selected_loan['status'] === 'approved' ? 'success' : 
                                    ($selected_loan['status'] === 'pending' ? 'warning' : 
                                    ($selected_loan['status'] === 'active' ? 'primary' : 
                                    ($selected_loan['status'] === 'completed' ? 'info' : 'danger'))) 
                                ?>">
                                    <?= ucfirst($selected_loan['status']) ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="loan-summary mb-4">
                                            <h6 class="border-bottom pb-2">Loan Summary</h6>
                                            <div class="row">
                                                <div class="col-6">
                                                    <strong>Principal Amount:</strong>
                                                </div>
                                                <div class="col-6">
                                                    ₱<?= number_format($selected_loan['principal'], 2) ?>
                                                </div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-6">
                                                    <strong>Loan Term:</strong>
                                                </div>
                                                <div class="col-6">
                                                    <?= $selected_loan['term_months'] ?> months
                                                </div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-6">
                                                    <strong>Interest Rate:</strong>
                                                </div>
                                                <div class="col-6">
                                                    <?= $selected_loan['interest_rate'] ?>% monthly
                                                </div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-6">
                                                    <strong>Monthly Payment:</strong>
                                                </div>
                                                <div class="col-6">
                                                    ₱<?= number_format($selected_loan['monthly_payment'], 2) ?>
                                                </div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-6">
                                                    <strong>Total Amount:</strong>
                                                </div>
                                                <div class="col-6">
                                                    ₱<?= number_format($selected_loan['total_amount'], 2) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="loan-info mb-4">
                                            <h6 class="border-bottom pb-2">Loan Information</h6>
                                            <div class="row">
                                                <div class="col-6">
                                                    <strong>Application Date:</strong>
                                                </div>
                                                <div class="col-6">
                                                    <?= date('M j, Y', strtotime($selected_loan['created_at'])) ?>
                                                </div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-6">
                                                    <strong>Purpose:</strong>
                                                </div>
                                                <div class="col-6">
                                                    <?= htmlspecialchars($selected_loan['purpose']) ?>
                                                </div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-6">
                                                    <strong>Payment Method:</strong>
                                                </div>
                                                <div class="col-6">
                                                    <?= ucfirst($selected_loan['payment_method']) ?>
                                                </div>
                                            </div>
                                            <!-- NEW: Progress Section -->
                                            <div class="row mt-2">
                                                <div class="col-6">
                                                    <strong>Payment Progress:</strong>
                                                </div>
                                                <div class="col-6">
                                                    <span class="progress-badge"><?= $progress ?></span>
                                                    <div class="progress mt-1" style="height: 5px;">
                                                        <div class="progress-bar bg-success" role="progressbar" 
                                                             style="width: <?= $percentage ?>%" 
                                                             aria-valuenow="<?= $percentage ?>" 
                                                             aria-valuemin="0" 
                                                             aria-valuemax="100">
                                                        </div>
                                                    </div>
                                                    <small class="text-muted">Remaining/Total Payments</small>
                                                </div>
                                            </div>
                                            <?php if (!empty($selected_loan['account_details'])): ?>
                                            <div class="row mt-2">
                                                <div class="col-6">
                                                    <strong>Account Details:</strong>
                                                </div>
                                                <div class="col-6">
                                                    <?= htmlspecialchars($selected_loan['account_details']) ?>
                                                </div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-6">
                                                    <strong>Account Name:</strong>
                                                </div>
                                                <div class="col-6">
                                                    <?= htmlspecialchars($selected_loan['account_name']) ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Payment History -->
                                <h6 class="border-bottom pb-2 mt-4">Payment History</h6>
                                <?php if (empty($payment_history)): ?>
                                    <div class="alert alert-info text-center py-3">
                                        <i class="fas fa-receipt fa-2x mb-2"></i>
                                        <p class="mb-0">No payment history found for this loan.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Payment Date</th>
                                                    <th>Amount</th>
                                                    <th>Status</th>
                                                    <th>Payment Method</th>
                                                    <th>Reference No.</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($payment_history as $payment): ?>
                                                    <tr>
                                                        <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                                                        <td>₱<?= number_format($payment['amount'], 2) ?></td>
                                                        <td>
                                                            <span class="payment-status-<?= $payment['status'] ?>">
                                                                <i class="fas fa-<?= 
                                                                    $payment['status'] === 'verified' ? 'check-circle' : 
                                                                    ($payment['status'] === 'pending' ? 'clock' : 'times-circle')
                                                                ?> me-1"></i>
                                                                <?= ucfirst($payment['status']) ?>
                                                            </span>
                                                        </td>
                                                        <td><?= ucfirst($payment['payment_method']) ?></td>
                                                        <td><?= $payment['reference_number'] ?: 'N/A' ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    <!-- Main Loans List View -->
                    <?php else: ?>
                        <!-- Header Section -->
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="mb-0">My Loan Applications</h5>
                            <div class="d-flex gap-2">
                                <!-- Search Bar -->
                                <div class="search-container">
                                    <form method="GET" class="search-input-group">
                                        <i class="fas fa-search search-icon"></i>
                                        <input type="text" class="form-control" name="search" placeholder="Search loans..." value="<?= htmlspecialchars($search) ?>">
                                    </form>
                                </div>
                                
                                <?php if ($is_eligible_for_loan): ?>
                                    <button class="btn btn-primary" id="showLoanFormBtn">
                                        <i class="fas fa-plus-circle me-2"></i>Apply for New Loan
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-secondary" disabled data-bs-toggle="tooltip" 
                                            title="You need 3 months active membership to apply for loans. Current: <?= $membership_duration ?>">
                                        <i class="fas fa-clock me-2"></i>Apply for New Loan
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Loan Application Form (Hidden by default) -->
                        <div class="card mb-4 loan-form" id="loanFormContainer">
                            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Apply for New Loan</h5>
                                <button type="button" class="btn btn-sm btn-light" id="hideLoanFormBtn">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="loanForm">
                                    <input type="hidden" name="apply_loan" value="1">
                                    <input type="hidden" name="account_details" id="accountDetailsInput">
                                    <input type="hidden" name="account_name" id="accountNameInput">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label form-required">Loan Amount (₱)</label>
                                                <input type="number" class="form-control" name="principal" id="principal" min="1000" max="50000" required 
                                                       onchange="calculateLoanDetails(); checkFormCompletion();">
                                                <small class="text-muted">Minimum: ₱1,000 | Maximum: ₱50,000</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label form-required">Loan Term</label>
                                                <select class="form-control" name="term_months" id="term_months" required onchange="calculateLoanDetails(); checkFormCompletion();">
                                                    <option value="">Select Term</option>
                                                    <option value="3">3 Months</option>
                                                    <option value="6">6 Months</option>
                                                    <option value="9">9 Months</option>
                                                    <option value="12">12 Months</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label form-required">Loan Purpose</label>
                                        <textarea class="form-control" name="purpose" id="purpose" placeholder="Explain why you need this loan..." required oninput="checkFormCompletion();"></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label form-required">Preferred Payment Method</label>
                                        <select class="form-control" name="payment_method" id="paymentMethod" required onchange="showAccountDetailsModal(); checkFormCompletion();">
                                            <option value="">Select Method</option>
                                            <option value="bank">Bank Transfer</option>
                                            <option value="gcash">GCash</option>
                                            <option value="cash">Cash</option>
                                        </select>
                                    </div>

                                    <!-- Account Details Preview -->
                                    <div class="card mb-3 account-details-card" id="accountDetailsPreview" style="display: none;">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">Payment Account Details</h6>
                                        </div>
                                        <div class="card-body">
                                            <div id="accountDetailsContent">
                                                <!-- Dynamic content will be inserted here -->
                                            </div>
                                            <button type="button" class="btn btn-outline-primary btn-sm mt-2" onclick="showAccountDetailsModal()">
                                                <i class="fas fa-edit me-1"></i>Edit Details
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Loan Details Preview -->
                                    <div class="card mb-3" id="loanDetails" style="display: none;">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">Loan Details Preview</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-3">
                                                    <strong>Principal:</strong> <span id="previewPrincipal">₱0.00</span>
                                                </div>
                                                <div class="col-md-3">
                                                    <strong>Term:</strong> <span id="previewTerm">0 months</span>
                                                </div>
                                                <div class="col-md-3">
                                                    <strong>Interest Rate:</strong> <span id="previewRate">2%</span>
                                                </div>
                                                <div class="col-md-3">
                                                    <strong>Monthly Payment:</strong> <span id="previewMonthly">₱0.00</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Form Completion Status -->
                                    <div class="card mb-3" id="completionStatus" style="display: none;">
                                        <div class="card-header bg-warning text-dark">
                                            <h6 class="mb-0"><i class="fas fa-tasks me-2"></i>Required Information</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="checkLoanAmount" disabled>
                                                        <label class="form-check-label" for="checkLoanAmount">
                                                            Loan Amount
                                                        </label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="checkLoanTerm" disabled>
                                                        <label class="form-check-label" for="checkLoanTerm">
                                                            Loan Term
                                                        </label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="checkLoanPurpose" disabled>
                                                        <label class="form-check-label" for="checkLoanPurpose">
                                                            Loan Purpose
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="checkPaymentMethod" disabled>
                                                        <label class="form-check-label" for="checkPaymentMethod">
                                                            Payment Method
                                                        </label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="checkAccountDetails" disabled>
                                                        <label class="form-check-label" for="checkAccountDetails">
                                                            Account Details
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Loan Agreement -->
                                    <div class="mb-3">
                                        <div class="card">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0">Loan Agreement</h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="form-check mb-3">
                                                    <input class="form-check-input" type="checkbox" name="agree_terms" id="agree_terms" required onchange="checkFormCompletion();">
                                                    <label class="form-check-label" for="agree_terms">
                                                        I have reviewed the <a href="#" data-bs-toggle="modal" data-bs-target="#loanAgreementModal">Loan Agreement</a> and agree to all terms and conditions
                                                    </label>
                                                </div>
                                                
                                                <div class="d-flex gap-2 flex-wrap">
                                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="previewLoanAgreement()">
                                                        <i class="fas fa-eye me-1"></i>Preview Agreement
                                                    </button>
                                                    <button type="button" class="btn btn-outline-success btn-sm" onclick="downloadLoanAgreement()">
                                                        <i class="fas fa-download me-1"></i>Download Agreement
                                                    </button>
                                                    <button type="button" class="btn btn-outline-info btn-sm" onclick="printLoanAgreement()">
                                                        <i class="fas fa-print me-1"></i>Print Agreement
                                                    </button>
                                                </div>
                                                
                                                <div class="mt-2">
                                                    <small class="text-muted">
                                                        <i class="fas fa-info-circle me-1"></i>
                                                        Please review the loan agreement carefully before submitting your application.
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-primary btn-lg" id="submitBtn" disabled>
                                        <i class="fas fa-paper-plane me-2"></i>Submit Application
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-lg" id="cancelBtn">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Account Details Modal -->
                        <div class="modal fade" id="accountDetailsModal" tabindex="-1" aria-labelledby="accountDetailsModalLabel" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="accountDetailsModalLabel">Payment Account Details</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div id="bankSelection" style="display: none;">
                                            <div class="mb-3">
                                                <label class="form-label form-required">Select Bank</label>
                                                <select class="form-control" id="bankSelect" onchange="showBankFields()">
                                                    <option value="">Select Bank</option>
                                                    <option value="bdo">BDO</option>
                                                    <option value="bpi">BPI</option>
                                                    <option value="landbank">LandBank</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div id="bankFields" style="display: none;">
                                            <div class="mb-3">
                                                <label class="form-label form-required" id="accountNumberLabel">Account Number</label>
                                                <input type="text" class="form-control" id="bankAccountNumber" placeholder="">
                                                <small class="text-muted" id="accountNumberHelp"></small>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label form-required">Account Name</label>
                                                <input type="text" class="form-control" id="bankAccountName" placeholder="Name as it appears on bank account">
                                            </div>
                                        </div>

                                        <div id="gcashFields" style="display: none;">
                                            <div class="mb-3">
                                                <label class="form-label form-required">GCash Account Number</label>
                                                <input type="text" class="form-control" id="gcashAccountNumber" placeholder="09XXXXXXXXX">
                                                <small class="text-muted">Enter your 11-digit mobile number</small>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label form-required">Account Name</label>
                                                <input type="text" class="form-control" id="gcashAccountName" placeholder="Name as it appears on GCash account">
                                            </div>
                                        </div>

                                        <div id="cashFields" style="display: none;">
                                            <div class="alert alert-info">
                                                <i class="fas fa-info-circle me-2"></i>
                                                For cash payments, you will need to visit the office to receive your loan proceeds.
                                                No additional account details are required.
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="button" class="btn btn-primary" onclick="saveAccountDetails()">Save Details</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Loan Agreement Modal -->
                        <div class="modal fade" id="loanAgreementModal" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header bg-primary text-white">
                                        <h5 class="modal-title">Loan Agreement Preview</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body text-center">
                                        <i class="fas fa-file-pdf fa-4x text-danger mb-3"></i>
                                        <h6>Loan Agreement Document</h6>
                                        <p class="text-muted">
                                            The loan agreement contains all terms and conditions for your loan application.
                                            Please use the buttons below to view, download, or print the agreement.
                                        </p>
                                        
                                        <div class="d-flex gap-2 justify-content-center flex-wrap mt-4">
                                            <button type="button" class="btn btn-outline-primary" onclick="previewLoanAgreement()">
                                                <i class="fas fa-eye me-1"></i>Preview
                                            </button>
                                            <button type="button" class="btn btn-outline-success" onclick="downloadLoanAgreement()">
                                                <i class="fas fa-download me-1"></i>Download
                                            </button>
                                            <button type="button" class="btn btn-outline-info" onclick="printLoanAgreement()">
                                                <i class="fas fa-print me-1"></i>Print
                                            </button>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal" onclick="document.getElementById('agree_terms').checked = true; checkFormCompletion();">
                                            I Have Reviewed the Agreement
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Existing Loans -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Loan History</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($loans)): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-file-invoice-dollar fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No loan applications found.</p>
                                        <?php if ($is_eligible_for_loan): ?>
                                            <button class="btn btn-primary" id="showLoanFormBtn2">
                                                <i class="fas fa-plus-circle me-2"></i>Apply for Your First Loan
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-secondary" disabled data-bs-toggle="tooltip" 
                                                    title="You need 3 months active membership to apply for loans. Current: <?= $membership_duration ?>">
                                                <i class="fas fa-clock me-2"></i>Apply for Your First Loan
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Loan ID</th>
                                                    <th>Amount</th>
                                                    <th>Term</th>
                                                    <th>Monthly Payment</th>
                                                    <th>Status</th>
                                                    <th>Applied Date</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($loans as $loan): ?>
                                                <tr>
                                                    <td><?= $loan['loan_number'] ?? 'LN-' . str_pad($loan['id'], 4, '0', STR_PAD_LEFT) ?></td>
                                                    <td>₱<?= number_format($loan['principal'], 2) ?></td>
                                                    <td><?= $loan['term_months'] ?> months</td>
                                                    <td>₱<?= number_format($loan['monthly_payment'], 2) ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= 
                                                            $loan['status'] === 'approved' ? 'success' : 
                                                            ($loan['status'] === 'pending' ? 'warning' : 
                                                            ($loan['status'] === 'active' ? 'primary' : 
                                                            ($loan['status'] === 'completed' ? 'info' : 'danger'))) 
                                                        ?>">
                                                            <?= ucfirst($loan['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= date('M j, Y', strtotime($loan['created_at'])) ?></td>
                                                    <td>
                                                        <a href="loans.php?view_loan=<?= $loan['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-eye me-1"></i>View Details
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Pagination -->
                                    <?php if ($total_pages > 1): ?>
                                    <div class="pagination-container">
                                        <nav aria-label="Loans pagination">
                                            <ul class="pagination">
                                                <!-- Previous Page -->
                                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                    <a class="page-link" href="?page=<?= $page - 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" aria-label="Previous">
                                                        <span aria-hidden="true">&laquo;</span>
                                                    </a>
                                                </li>
                                                
                                                <!-- Page Numbers -->
                                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $i ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"><?= $i ?></a>
                                                    </li>
                                                <?php endfor; ?>
                                                
                                                <!-- Next Page -->
                                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                                    <a class="page-link" href="?page=<?= $page + 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" aria-label="Next">
                                                        <span aria-hidden="true">&raquo;</span>
                                                    </a>
                                                </li>
                                            </ul>
                                        </nav>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentPaymentMethod = '';
        let accountDetailsModal = null;

        // Show/hide loan form functionality
        document.getElementById('showLoanFormBtn').addEventListener('click', function() {
            document.getElementById('loanFormContainer').classList.add('show');
        });

        document.getElementById('showLoanFormBtn2').addEventListener('click', function() {
            document.getElementById('loanFormContainer').classList.add('show');
        });

        document.getElementById('hideLoanFormBtn').addEventListener('click', function() {
            document.getElementById('loanFormContainer').classList.remove('show');
            resetLoanForm();
        });

        document.getElementById('cancelBtn').addEventListener('click', function() {
            document.getElementById('loanFormContainer').classList.remove('show');
            resetLoanForm();
        });

        function resetLoanForm() {
            document.getElementById('loanForm').reset();
            document.getElementById('loanDetails').style.display = 'none';
            document.getElementById('accountDetailsPreview').style.display = 'none';
            document.getElementById('completionStatus').style.display = 'none';
            document.getElementById('submitBtn').disabled = true;
        }

        function calculateLoanDetails() {
            const principal = parseFloat(document.getElementById('principal').value) || 0;
            const termMonths = parseInt(document.getElementById('term_months').value) || 0;
            
            if (principal > 0 && termMonths > 0) {
                const interestRate = 2.0; // 2% monthly
                const totalInterest = principal * (interestRate / 100) * termMonths;
                const monthlyPayment = (principal + totalInterest) / termMonths;
                
                document.getElementById('previewPrincipal').textContent = '₱' + principal.toFixed(2);
                document.getElementById('previewMonthly').textContent = '₱' + monthlyPayment.toFixed(2);
                document.getElementById('previewTerm').textContent = termMonths + ' months';
                document.getElementById('previewRate').textContent = interestRate + '% monthly';
                document.getElementById('loanDetails').style.display = 'block';
            } else {
                document.getElementById('loanDetails').style.display = 'none';
            }
        }

        function checkFormCompletion() {
            const principal = document.getElementById('principal').value;
            const termMonths = document.getElementById('term_months').value;
            const purpose = document.getElementById('purpose').value.trim();
            const paymentMethod = document.getElementById('paymentMethod').value;
            const accountDetails = document.getElementById('accountDetailsInput').value;
            const agreeTerms = document.getElementById('agree_terms').checked;
            
            // Update checkboxes
            document.getElementById('checkLoanAmount').checked = principal && principal >= 1000 && principal <= 50000;
            document.getElementById('checkLoanTerm').checked = termMonths && ['3','6','9','12'].includes(termMonths);
            document.getElementById('checkLoanPurpose').checked = purpose.length > 0;
            document.getElementById('checkPaymentMethod').checked = paymentMethod.length > 0;
            document.getElementById('checkAccountDetails').checked = accountDetails.length > 0;
            
            // Show/hide completion status
            const completionStatus = document.getElementById('completionStatus');
            const allCompleted = document.getElementById('checkLoanAmount').checked && 
                               document.getElementById('checkLoanTerm').checked && 
                               document.getElementById('checkLoanPurpose').checked && 
                               document.getElementById('checkPaymentMethod').checked && 
                               document.getElementById('checkAccountDetails').checked &&
                               agreeTerms;
            
            if (principal || termMonths || purpose || paymentMethod || accountDetails) {
                completionStatus.style.display = 'block';
            } else {
                completionStatus.style.display = 'none';
            }
            
            // Enable/disable submit button
            document.getElementById('submitBtn').disabled = !allCompleted;
        }

        function showAccountDetailsModal() {
            currentPaymentMethod = document.getElementById('paymentMethod').value;
            
            if (!currentPaymentMethod) {
                alert('Please select a payment method first.');
                return;
            }

            // Reset all fields
            document.getElementById('bankSelection').style.display = 'none';
            document.getElementById('bankFields').style.display = 'none';
            document.getElementById('gcashFields').style.display = 'none';
            document.getElementById('cashFields').style.display = 'none';
            
            document.getElementById('bankSelect').value = '';
            document.getElementById('bankAccountNumber').value = '';
            document.getElementById('bankAccountName').value = '';
            document.getElementById('gcashAccountNumber').value = '';
            document.getElementById('gcashAccountName').value = '';

            // Show relevant fields based on payment method
            if (currentPaymentMethod === 'bank') {
                document.getElementById('bankSelection').style.display = 'block';
            } else if (currentPaymentMethod === 'gcash') {
                document.getElementById('gcashFields').style.display = 'block';
            } else if (currentPaymentMethod === 'cash') {
                document.getElementById('cashFields').style.display = 'block';
            }

            if (!accountDetailsModal) {
                accountDetailsModal = new bootstrap.Modal(document.getElementById('accountDetailsModal'));
            }
            accountDetailsModal.show();
        }

        function showBankFields() {
            const bank = document.getElementById('bankSelect').value;
            const bankFields = document.getElementById('bankFields');
            
            if (bank) {
                bankFields.style.display = 'block';
                
                // Set appropriate labels and placeholders based on bank
                switch(bank) {
                    case 'bdo':
                        document.getElementById('accountNumberLabel').textContent = 'BDO Account Number';
                        document.getElementById('accountNumberHelp').textContent = '12-digit account number';
                        document.getElementById('bankAccountNumber').placeholder = '000000000000';
                        break;
                    case 'bpi':
                        document.getElementById('accountNumberLabel').textContent = 'BPI Account Number';
                        document.getElementById('accountNumberHelp').textContent = '10-digit account number';
                        document.getElementById('bankAccountNumber').placeholder = '0000000000';
                        break;
                    case 'landbank':
                        document.getElementById('accountNumberLabel').textContent = 'LandBank Account Number';
                        document.getElementById('accountNumberHelp').textContent = '10-digit account number';
                        document.getElementById('bankAccountNumber').placeholder = '0000000000';
                        break;
                }
            } else {
                bankFields.style.display = 'none';
            }
        }

        function saveAccountDetails() {
            let accountDetails = '';
            let accountName = '';
            let isValid = true;

            if (currentPaymentMethod === 'bank') {
                const bank = document.getElementById('bankSelect').value;
                const accountNumber = document.getElementById('bankAccountNumber').value.trim();
                accountName = document.getElementById('bankAccountName').value.trim();

                if (!bank) {
                    alert('Please select a bank.');
                    isValid = false;
                } else if (!accountNumber) {
                    alert('Please enter account number.');
                    isValid = false;
                } else if (!accountName) {
                    alert('Please enter account name.');
                    isValid = false;
                } else {
                    // Validate account number length based on bank
                    switch(bank) {
                        case 'bdo':
                            if (accountNumber.length !== 12 || !/^\d+$/.test(accountNumber)) {
                                alert('BDO account number must be exactly 12 digits.');
                                isValid = false;
                            }
                            break;
                        case 'bpi':
                        case 'landbank':
                            if (accountNumber.length !== 10 || !/^\d+$/.test(accountNumber)) {
                                alert('Account number must be exactly 10 digits.');
                                isValid = false;
                            }
                            break;
                    }

                    if (isValid) {
                        accountDetails = `${bank.toUpperCase()} - ${accountNumber}`;
                    }
                }
            } else if (currentPaymentMethod === 'gcash') {
                const gcashNumber = document.getElementById('gcashAccountNumber').value.trim();
                accountName = document.getElementById('gcashAccountName').value.trim();

                if (!gcashNumber) {
                    alert('Please enter GCash account number.');
                    isValid = false;
                } else if (!accountName) {
                    alert('Please enter account name.');
                    isValid = false;
                } else if (gcashNumber.length !== 11 || !/^09\d{9}$/.test(gcashNumber)) {
                    alert('GCash account number must be a valid 11-digit mobile number starting with 09.');
                    isValid = false;
                } else {
                    accountDetails = `GCash - ${gcashNumber}`;
                }
            } else if (currentPaymentMethod === 'cash') {
                accountDetails = 'Cash Payment';
                accountName = 'N/A';
            }

            if (isValid) {
                // Save to hidden inputs
                document.getElementById('accountDetailsInput').value = accountDetails;
                document.getElementById('accountNameInput').value = accountName;

                // Update preview
                const previewDiv = document.getElementById('accountDetailsPreview');
                const contentDiv = document.getElementById('accountDetailsContent');
                
                contentDiv.innerHTML = `
                    <strong>Payment Method:</strong> ${document.getElementById('paymentMethod').options[document.getElementById('paymentMethod').selectedIndex].text}<br>
                    <strong>Account Details:</strong> ${accountDetails}<br>
                    <strong>Account Name:</strong> ${accountName}
                `;
                
                previewDiv.style.display = 'block';
                
                // Check form completion
                checkFormCompletion();
                
                // Close modal
                accountDetailsModal.hide();
            }
        }

        // PDF Agreement Functions
        function previewLoanAgreement() {
            const principal = document.getElementById('principal').value;
            const termMonths = document.getElementById('term_months').value;
            const purpose = document.getElementById('purpose').value;
            
            if (!principal || !termMonths) {
                alert('Please enter loan amount and term to preview agreement');
                return;
            }
            
            // Open agreement in new tab for preview
            const url = `loan_agreement.php?action=preview&preview=1&principal=${principal}&term_months=${termMonths}&purpose=${encodeURIComponent(purpose)}`;
            window.open(url, '_blank');
        }

        function downloadLoanAgreement() {
            const principal = document.getElementById('principal').value;
            const termMonths = document.getElementById('term_months').value;
            const purpose = document.getElementById('purpose').value;
            
            if (!principal || !termMonths) {
                alert('Please enter loan amount and term to download agreement');
                return;
            }
            
            // Download agreement
            const url = `loan_agreement.php?action=download&preview=1&principal=${principal}&term_months=${termMonths}&purpose=${encodeURIComponent(purpose)}`;
            window.open(url, '_blank');
        }

        function printLoanAgreement() {
            const principal = document.getElementById('principal').value;
            const termMonths = document.getElementById('term_months').value;
            const purpose = document.getElementById('purpose').value;
            
            if (!principal || !termMonths) {
                alert('Please enter loan amount and term to print agreement');
                return;
            }
            
            // Open agreement for printing
            const url = `loan_agreement.php?action=print&preview=1&principal=${principal}&term_months=${termMonths}&purpose=${encodeURIComponent(purpose)}`;
            window.open(url, '_blank');
        }

        // Initialize modal and check form on load
        document.addEventListener('DOMContentLoaded', function() {
            accountDetailsModal = new bootstrap.Modal(document.getElementById('accountDetailsModal'));
            checkFormCompletion();
            
            // Add input event listeners for real-time validation
            document.getElementById('principal').addEventListener('input', checkFormCompletion);
            document.getElementById('term_months').addEventListener('change', checkFormCompletion);
            document.getElementById('purpose').addEventListener('input', checkFormCompletion);
            document.getElementById('paymentMethod').addEventListener('change', checkFormCompletion);
        });

        // Auto-submit search form when typing (with slight delay)
        let searchTimeout;
        document.querySelector('input[name="search"]').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 500);
        });

        // Initialize Bootstrap tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>