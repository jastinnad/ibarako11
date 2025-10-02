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

// Get payment gateways from admin settings
try {
    $pdo = DB::pdo();
    $stmt = $pdo->query("SELECT * FROM payment_gateways WHERE is_active = 1 ORDER BY type, id");
    $payment_gateways = $stmt->fetchAll();
    
    // Separate GCash and bank accounts
    $gcash_accounts = array_filter($payment_gateways, function($gateway) {
        return $gateway['type'] === 'gcash';
    });
    
    $bank_accounts = array_filter($payment_gateways, function($gateway) {
        return $gateway['type'] === 'bank';
    });
    
} catch (Exception $e) {
    $gcash_accounts = [];
    $bank_accounts = [];
}

// Handle contribution form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['make_contribution'])) {
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? '';
    
    // Validate inputs
    if ($amount < 100) {
        $error = "Minimum contribution amount is ₱100";
    } elseif (empty($payment_method)) {
        $error = "Please select a payment method";
    } else {
        try {
            $pdo = DB::pdo();
            
            // Check if user has an 'id' in session, otherwise use member_id
            $user_id = $user['id'] ?? $user['member_id'];
            
            // Insert into contributions table without reference_number
            $stmt = $pdo->prepare("
                INSERT INTO contributions (member_id, user_id, amount, payment_method, status, contrib_date, created_at) 
                VALUES (?, ?, ?, ?, 'pending', CURDATE(), NOW())
            ");
            
            $stmt->execute([
                $user['member_id'],
                $user_id,
                $amount,
                $payment_method
            ]);
            
            $contribution_id = $pdo->lastInsertId();

// NOTIFY ADMIN ABOUT THE CONTRIBUTION
Notifications::notifyAdmin(
    'contribution',
    'New Contribution',
    $user['firstname'] . ' ' . $user['lastname'] . ' ('.$user['member_id'].') has made a contribution of ₱' . number_format($amount, 2),
    $user['member_id'],
    $contribution_id
);

$success = "Contribution submitted successfully! Waiting for admin confirmation.";
            
        } catch (Exception $e) {
            $error = "Error submitting contribution: " . $e->getMessage();
            error_log("Contribution submission error: " . $e->getMessage());
        }
    }
}

// Pagination and search
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 7;
$offset = ($page - 1) * $limit;

// Get user's contributions with search and pagination
$pdo = DB::pdo();

// Build base query
$query = "SELECT * FROM contributions WHERE member_id = ?";
$countQuery = "SELECT COUNT(*) FROM contributions WHERE member_id = ?";
$params = [$user['member_id']];
$countParams = [$user['member_id']];

// Add search filter if provided
if (!empty($search)) {
    $searchTerm = "%$search%";
    $query .= " AND (id LIKE ? OR amount LIKE ?)";
    $countQuery .= " AND (id LIKE ? OR amount LIKE ?)";
    $params = array_merge($params, [$searchTerm, $searchTerm]);
    $countParams = array_merge($countParams, [$searchTerm, $searchTerm]);
}

// Add ordering and pagination
$query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

// Get contributions
$user_contributions = $pdo->prepare($query);
$user_contributions->execute($params);
$contributions = $user_contributions->fetchAll();

// Get total count for pagination
$count_stmt = $pdo->prepare($countQuery);
$count_stmt->execute($countParams);
$total_contributions = $count_stmt->fetchColumn();
$total_pages = ceil($total_contributions / $limit);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Contributions - iBarako Loan System</title>
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
    
    /* Main navbar - EXACT SAME as Dashboard and My Profile */
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
    
    /* Contribution Form Styling */
    .contribution-form {
        display: none;
        transition: all 0.3s ease-in-out;
        opacity: 0;
        transform: translateY(-10px);
    }
    
    .contribution-form.show {
        display: block;
        opacity: 1;
        transform: translateY(0);
    }
    
    .card-header.bg-primary {
        background: var(--primary-gradient) !important;
        border: none;
    }
    
    /* Button Styling - Make all buttons the same normal size */
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
    
    .btn-light {
        background: white;
        color: #4a5568;
        border: 1px solid #e2e8f0;
    }
    
    .btn-light:hover {
        background: #f7fafc;
        transform: translateY(-1px);
    }
    
    /* Make form buttons smaller (Submit Contribution and Cancel) */
    .btn-lg {
        padding: 0.75rem 1.5rem;
        font-size: 0.9rem;
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
    
    /* Account Details Styling */
    .account-details {
        background-color: #f8fafc;
        border-radius: 8px;
        padding: 1.5rem;
        border-left: 4px solid #3182ce;
        border: 1px solid #e2e8f0;
        margin-top: 1rem;
    }
    
    .account-option {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 0.5rem;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .account-option:hover {
        border-color: #3182ce;
        background-color: #ebf8ff;
    }
    
    .account-option.selected {
        border-color: #3182ce;
        background-color: #e7f1ff;
        box-shadow: 0 0 0 2px rgba(49, 130, 206, 0.2);
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
    
    .bg-danger {
        background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%) !important;
    }
    
    /* Alert Styling */
    .alert {
        border: none;
        border-radius: 10px;
        font-weight: 500;
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
    
    /* Empty State Styling */
    .text-center.py-4 {
        padding: 3rem 1rem;
    }
    
    .fa-chart-line.fa-3x {
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
                    <a class="nav-link active" href="contributions.php">
                        <i class="fas fa-chart-line"></i>Contributions
                    </a>
                    <a class="nav-link" href="loans.php">
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
                        <h4 class="mb-0 text-dark">My Contributions</h4>
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

                    <!-- Make Contribution Button -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="mb-0">My Contribution History</h5>
                        <div class="d-flex gap-2">
                            <!-- Search Bar -->
                            <div class="search-container">
                                <form method="GET" class="search-input-group">
                                    <i class="fas fa-search search-icon"></i>
                                    <input type="text" class="form-control" name="search" placeholder="Search contributions..." value="<?= htmlspecialchars($search) ?>">
                                </form>
                            </div>
                            <button class="btn btn-primary" id="showContributionFormBtn">
                                <i class="fas fa-plus-circle me-2"></i>Make Contribution
                            </button>
                        </div>
                    </div>

                    <!-- Contribution Form (Hidden by default) -->
                    <div class="card mb-4 contribution-form" id="contributionFormContainer">
                        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Make Contribution</h5>
                            <button type="button" class="btn btn-sm btn-light" id="hideContributionFormBtn">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="contributionForm">
                                <input type="hidden" name="make_contribution" value="1">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Contribution Amount (₱)</label>
                                            <input type="number" class="form-control" name="amount" min="100" required>
                                            <small class="text-muted">Minimum: ₱100</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Payment Method</label>
                                            <select class="form-control" name="payment_method" id="paymentMethod" required>
                                                <option value="">Select Method</option>
                                                <option value="gcash">GCash</option>
                                                <option value="bank">Bank Transfer</option>
                                                <option value="cash">Cash</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- GCash Account Selection -->
                                <div id="gcashAccounts" class="account-details" style="display: none;">
                                    <h6><i class="fas fa-mobile-alt me-2"></i>Send to iBarako GCash Account</h6>
                                    <p class="text-muted mb-3">Please send your contribution to one of the following GCash accounts:</p>
                                    
                                    <?php if (empty($gcash_accounts)): ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            No GCash accounts configured. Please contact administrator.
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($gcash_accounts as $index => $gcash): ?>
                                            <div class="account-option" data-account-type="gcash" data-account-id="<?= $gcash['id'] ?>">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="selected_gcash_account" id="gcash_<?= $gcash['id'] ?>" value="<?= $gcash['id'] ?>" <?= $index === 0 ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="gcash_<?= $gcash['id'] ?>">
                                                        <strong><?= htmlspecialchars($gcash['account_name']) ?></strong><br>
                                                        <span class="text-muted"><?= htmlspecialchars($gcash['account_number']) ?></span>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <!-- Bank Account Selection -->
                                <div id="bankAccounts" class="account-details" style="display: none;">
                                    <h6><i class="fas fa-university me-2"></i>Send to iBarako Bank Account</h6>
                                    <p class="text-muted mb-3">Please send your contribution to one of the following bank accounts:</p>
                                    
                                    <?php if (empty($bank_accounts)): ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            No bank accounts configured. Please contact administrator.
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($bank_accounts as $index => $bank): ?>
                                            <div class="account-option" data-account-type="bank" data-account-id="<?= $bank['id'] ?>">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="selected_bank_account" id="bank_<?= $bank['id'] ?>" value="<?= $bank['id'] ?>" <?= $index === 0 ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="bank_<?= $bank['id'] ?>">
                                                        <strong><?= htmlspecialchars($bank['bank_name']) ?></strong><br>
                                                        <span class="text-muted">Account: <?= htmlspecialchars($bank['account_number']) ?> - <?= htmlspecialchars($bank['account_name']) ?></span>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-paper-plane me-2"></i>Submit Contribution
                                </button>
                                <button type="button" class="btn btn-secondary btn-lg" id="cancelBtn">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Existing Contributions -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Contribution History</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($contributions)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No contributions found.</p>
                                    <button class="btn btn-primary" id="showContributionFormBtn2">
                                        <i class="fas fa-plus-circle me-2"></i>Make Your First Contribution
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Contribution ID</th>
                                                <th>Amount</th>
                                                <th>Payment Method</th>
                                                <th>Status</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($contributions as $contribution): ?>
                                            <tr>
                                                <td>CN-<?= str_pad($contribution['id'], 4, '0', STR_PAD_LEFT) ?></td>
                                                <td>₱<?= number_format($contribution['amount'], 2) ?></td>
                                                <td><?= ucfirst($contribution['payment_method'] ?? 'N/A') ?></td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $contribution['status'] === 'confirmed' ? 'success' : 
                                                        ($contribution['status'] === 'pending' ? 'warning' : 'danger') 
                                                    ?>">
                                                        <?= ucfirst($contribution['status']) ?>
                                                    </span>
                                                </td>
                                                <td><?= date('M j, Y', strtotime($contribution['created_at'])) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                <div class="pagination-container">
                                    <nav aria-label="Contributions pagination">
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
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show/hide contribution form functionality
        document.getElementById('showContributionFormBtn').addEventListener('click', function() {
            document.getElementById('contributionFormContainer').classList.add('show');
        });

        document.getElementById('showContributionFormBtn2').addEventListener('click', function() {
            document.getElementById('contributionFormContainer').classList.add('show');
        });

        document.getElementById('hideContributionFormBtn').addEventListener('click', function() {
            document.getElementById('contributionFormContainer').classList.remove('show');
            resetContributionForm();
        });

        document.getElementById('cancelBtn').addEventListener('click', function() {
            document.getElementById('contributionFormContainer').classList.remove('show');
            resetContributionForm();
        });

        function resetContributionForm() {
            document.getElementById('contributionForm').reset();
            document.getElementById('gcashAccounts').style.display = 'none';
            document.getElementById('bankAccounts').style.display = 'none';
        }

        // Payment method change handler
        document.getElementById('paymentMethod').addEventListener('change', function() {
            const method = this.value;
            const gcashAccounts = document.getElementById('gcashAccounts');
            const bankAccounts = document.getElementById('bankAccounts');
            
            // Hide all account sections first
            gcashAccounts.style.display = 'none';
            bankAccounts.style.display = 'none';
            
            // Show relevant account section
            if (method === 'gcash') {
                gcashAccounts.style.display = 'block';
            } else if (method === 'bank') {
                bankAccounts.style.display = 'block';
            }
        });

        // Account option selection styling
        document.querySelectorAll('.account-option').forEach(option => {
            option.addEventListener('click', function() {
                const radio = this.querySelector('input[type="radio"]');
                radio.checked = true;
                
                // Remove selected class from all options in this group
                const accountType = this.dataset.accountType;
                document.querySelectorAll(`[data-account-type="${accountType}"]`).forEach(opt => {
                    opt.classList.remove('selected');
                });
                
                // Add selected class to clicked option
                this.classList.add('selected');
            });
        });

        // Form validation
        document.getElementById('contributionForm').addEventListener('submit', function(e) {
            const paymentMethod = document.getElementById('paymentMethod').value;
            const amount = document.querySelector('input[name="amount"]').value;
            
            if (amount < 100) {
                e.preventDefault();
                alert('Minimum contribution amount is ₱100');
                return;
            }
            
            if (paymentMethod === 'gcash') {
                const gcashSelected = document.querySelector('input[name="selected_gcash_account"]:checked');
                if (!gcashSelected) {
                    e.preventDefault();
                    alert('Please select a GCash account to send your contribution to.');
                    return;
                }
            } else if (paymentMethod === 'bank') {
                const bankSelected = document.querySelector('input[name="selected_bank_account"]:checked');
                if (!bankSelected) {
                    e.preventDefault();
                    alert('Please select a bank account to send your contribution to.');
                    return;
                }
            }
        });

        // Auto-submit search form when typing (with slight delay)
        let searchTimeout;
        document.querySelector('input[name="search"]').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 500);
        });
    </script>
</body>
</html>