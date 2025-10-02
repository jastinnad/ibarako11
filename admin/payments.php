<?php
session_start();
require_once '../db.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$user = $_SESSION['user'];
$action = $_GET['action'] ?? '';
$error = '';
$success = '';

// Handle payment actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_id = intval($_POST['payment_id']);
    
    if (isset($_POST['verify_payment'])) {
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("UPDATE loan_payments SET status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ?");
            $stmt->execute([$user['member_id'], $payment_id]);
            $success = "Payment verified successfully";
        } catch (Exception $e) {
            $error = "Error verifying payment: " . $e->getMessage();
        }
    } elseif (isset($_POST['reject_payment'])) {
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("UPDATE loan_payments SET status = 'rejected', verified_by = ?, verified_at = NOW() WHERE id = ?");
            $stmt->execute([$user['member_id'], $payment_id]);
            $success = "Payment rejected";
        } catch (Exception $e) {
            $error = "Error rejecting payment: " . $e->getMessage();
        }
    }
}

// Search functionality
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Pagination setup
$payments_per_page = 7;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $payments_per_page;

// Build query for payments with search and filters
$pdo = DB::pdo();
try {
    $query = "
        SELECT p.*, l.principal, l.term_months, u.member_id, u.firstname, u.lastname
        FROM loan_payments p 
        JOIN loans l ON p.loan_id = l.id 
        JOIN users u ON l.user_id = u.member_id 
        WHERE 1=1
    ";
    $params = [];
    
    if (!empty($search)) {
        $query .= " AND (u.member_id LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ?)";
        $search_term = "%$search%";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    }
    
    if (!empty($status_filter)) {
        $query .= " AND p.status = ?";
        $params[] = $status_filter;
    }
    
    $query .= " ORDER BY p.created_at DESC";
    
    // Get total count for pagination
    $count_query = "
        SELECT COUNT(*) as total 
        FROM loan_payments p 
        JOIN loans l ON p.loan_id = l.id 
        JOIN users u ON l.user_id = u.member_id 
        WHERE 1=1
    ";
    $count_params = [];
    
    if (!empty($search)) {
        $count_query .= " AND (u.member_id LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ?)";
        $search_term = "%$search%";
        $count_params = [$search_term, $search_term, $search_term, $search_term];
    }
    
    if (!empty($status_filter)) {
        $count_query .= " AND p.status = ?";
        $count_params[] = $status_filter;
    }
    
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($count_params);
    $total_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_payments = $total_result['total'];
    $total_pages = ceil($total_payments / $payments_per_page);
    
    // Get payments for current page
    $query .= " LIMIT ? OFFSET ?";
    $params[] = $payments_per_page;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Error loading payments: " . $e->getMessage();
    $payments = [];
    $total_payments = 0;
    $total_pages = 1;
}

// Get payment details for view action
$payment_details = null;
if ($action === 'view' && isset($_GET['id'])) {
    $payment_id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("
            SELECT p.*, l.principal, l.term_months, u.*
            FROM loan_payments p 
            JOIN loans l ON p.loan_id = l.id 
            JOIN users u ON l.user_id = u.member_id 
            WHERE p.id = ?
        ");
        $stmt->execute([$payment_id]);
        $payment_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($payment_details) {
            // Get total payments count for this loan
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as paid_count 
                FROM loan_payments 
                WHERE loan_id = ? AND status = 'verified'
            ");
            $stmt->execute([$payment_details['loan_id']]);
            $paid_result = $stmt->fetch(PDO::FETCH_ASSOC);
            $payment_details['paid_count'] = $paid_result['paid_count'] ?? 0;
            
            // Calculate total payments needed (twice per month)
            $payment_details['total_payments_needed'] = $payment_details['term_months'] * 2;
            $payment_details['remaining_payments'] = $payment_details['total_payments_needed'] - $payment_details['paid_count'];
            
            if ($payment_details['verified_by']) {
                // Get verifier details if payment is verified
                $stmt = $pdo->prepare("SELECT firstname, lastname FROM users WHERE member_id = ?");
                $stmt->execute([$payment_details['verified_by']]);
                $verifier = $stmt->fetch(PDO::FETCH_ASSOC);
                $payment_details['verified_fname'] = $verifier['firstname'] ?? '';
                $payment_details['verified_lname'] = $verifier['lastname'] ?? '';
            }
        }
    } catch (Exception $e) {
        $error = "Error loading payment details: " . $e->getMessage();
    }
}

// Function to determine if payment needs action (is pending/active/unverified)
function isPaymentPending($payment) {
    $pendingStatuses = ['pending', 'active', 'unverified', 'submitted'];
    return in_array(strtolower($payment['status']), $pendingStatuses) && empty($payment['verified_by']);
}

// Function to get status badge class
function getStatusBadgeClass($status) {
    $status = strtolower($status);
    switch ($status) {
        case 'verified': return 'success';
        case 'rejected': return 'danger';
        case 'active': return 'warning';
        case 'pending': return 'warning';
        default: return 'secondary';
    }
}

// Function to get payment progress for a loan
function getPaymentProgress($loan_id, $term_months) {
    $pdo = DB::pdo();
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as paid_count 
            FROM loan_payments 
            WHERE loan_id = ? AND status = 'verified'
        ");
        $stmt->execute([$loan_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $paid_count = $result['paid_count'] ?? 0;
        $total_needed = $term_months * 2; // Twice per month
        $remaining = $total_needed - $paid_count;
        
        return [
            'paid' => $paid_count,
            'total' => $total_needed,
            'remaining' => $remaining,
            'progress' => $paid_count . '/' . $total_needed
        ];
    } catch (Exception $e) {
        return ['paid' => 0, 'total' => 0, 'remaining' => 0, 'progress' => '0/0'];
    }
}

// Calculate pending payments count
$pending_payments_count = 0;
foreach ($payments as $payment) {
    if ($payment['status'] === 'pending') {
        $pending_payments_count++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Verification - iBarako Loan System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
		/* Import modern fonts */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
		body {
			font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
		}
        .sidebar {
            background: linear-gradient(180deg, #e53e3e, #1a365d, #1a365d, #1a365d);
            min-height: 100vh;
            width: 250px;
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: all 0.3s;
			font-family: 'Inter', sans-serif;
        }
        .main-content {
            margin-left: 250px;
            width: calc(100% - 250px);
            transition: all 0.3s;
			padding-bottom: 2rem;
        }
        .sidebar-brand {
            padding: 1.5rem 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 0.75rem 1rem;
            border-left: 3px solid transparent;
            transition: all 0.3s;
            margin: 0.1rem 0;
        }
        .nav-link:hover, .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.1);
            border-left-color: #3b82f6;
        }
        .nav-link i {
            width: 20px;
            text-align: center;
            margin-right: 0.5rem;
        }
        .badge-notification {
            font-size: 0.7em;
            padding: 0.2em 0.4em;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .progress-badge {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
        }
        .pagination-container {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
        }
        .page-info {
            text-align: center;
            margin-bottom: 1rem;
            color: #6c757d;
            font-size: 0.9rem;
        }
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
        }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Sidebar -->
			<div class="sidebar">
				<div class="sidebar-brand text-white">
					<!-- Logo Section -->
					<div class="text-center mb-3">
						<img src="../ibarako_logov2.PNG" alt="iBarako Logo" class="logo" style="max-height: 45px;">
					</div>
					<!-- Text Brand -->
					<small class="text-center d-block">Admin Panel</small>
				</div>
                <nav class="nav flex-column mt-3">
                    <a class="nav-link" href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>Dashboard
                    </a>
                    <a class="nav-link" href="members.php">
                        <i class="fas fa-users"></i>Manage Members
                    </a>
                    <a class="nav-link" href="loans.php">
                        <i class="fas fa-file-invoice-dollar"></i>Loan Applications
                    </a>
                    <a class="nav-link active" href="payments.php">
                        <i class="fas fa-money-check"></i>Payment Verification
                        <?php if ($pending_payments_count > 0): ?>
                            <span class="badge badge-notification bg-danger ms-1"><?= $pending_payments_count ?></span>
                        <?php endif; ?>
                    </a>
					<a class="nav-link" href="contributions.php">
                        <i class="fas fa-chart-line"></i>Contributions
                    </a>
                    <a class="nav-link" href="update_requests.php">
                        <i class="fas fa-edit"></i>Update Requests
                    </a>
                    <a class="nav-link" href="settings.php">
                        <i class="fas fa-cog"></i>Settings
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
                        <h4 class="mt-2 mb-2 text-dark">Payment Verification</h4>
                        <div>
                            <span class="badge bg-primary"><?= $total_payments ?> Total</span>
                            <?php if ($pending_payments_count > 0): ?>
                                <span class="badge bg-warning"><?= $pending_payments_count ?> Pending</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </nav>

                <div class="container-fluid mt-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= $success ?></div>
                    <?php endif; ?>

                    <?php if ($action === 'view' && $payment_details): ?>
                        <!-- Payment Details View -->
                        <div class="card">
                            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Payment Verification Details</h5>
                                <?php if (isPaymentPending($payment_details)): ?>
                                <div class="action-buttons">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="payment_id" value="<?= $payment_details['id'] ?>">
                                        <button type="submit" name="verify_payment" class="btn btn-success btn-sm" 
                                                onclick="return confirm('Are you sure you want to approve this payment?')">
                                            <i class="fas fa-check me-1"></i>Approve Payment
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="payment_id" value="<?= $payment_details['id'] ?>">
                                        <button type="submit" name="reject_payment" class="btn btn-danger btn-sm"
                                                onclick="return confirm('Are you sure you want to reject this payment?')">
                                            <i class="fas fa-times me-1"></i>Reject Payment
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Member Information</h6>
                                        <table class="table table-bordered">
                                            <tr><th>Member ID</th><td><?= $payment_details['member_id'] ?></td></tr>
                                            <tr><th>Name</th><td><?= $payment_details['firstname'] ?> <?= $payment_details['lastname'] ?></td></tr>
                                            <tr><th>Email</th><td><?= $payment_details['email'] ?></td></tr>
                                            <tr><th>Mobile</th><td><?= $payment_details['mobile'] ?></td></tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Loan Information</h6>
                                        <table class="table table-bordered">
                                            <tr><th>Loan Amount</th><td>₱<?= number_format($payment_details['principal'], 2) ?></td></tr>
                                            <tr><th>Term</th><td><?= $payment_details['term_months'] ?> months</td></tr>
                                            <tr><th>Payment Schedule</th><td>Twice per month</td></tr>
                                            <tr><th>Total Payments Needed</th><td><?= $payment_details['total_payments_needed'] ?> payments</td></tr>
                                        </table>
                                    </div>
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <h6>Payment Details</h6>
                                        <table class="table table-bordered">
                                            <tr><th>Payment Amount</th><td>₱<?= number_format($payment_details['amount'], 2) ?></td></tr>
                                            <tr><th>Payment Date</th><td><?= date('F j, Y', strtotime($payment_details['payment_date'])) ?></td></tr>
                                            <tr><th>Payment Method</th><td><?= ucfirst($payment_details['payment_method']) ?></td></tr>
                                            <tr><th>Receipt</th>
                                                <td>
                                                    <?php if (!empty($payment_details['receipt_filename'])): ?>
                                                        <a href="../uploads/receipts/<?= $payment_details['receipt_filename'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-image me-1"></i> View Receipt
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">No receipt uploaded</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <tr><th>Status</th>
                                                <td>
                                                    <span class="badge bg-<?= getStatusBadgeClass($payment_details['status']) ?>">
                                                        <?= ucfirst($payment_details['status']) ?>
                                                    </span>
                                                    <?php if (isPaymentPending($payment_details)): ?>
                                                    <span class="badge bg-warning ms-1">Action Required</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <tr><th>Progress</th>
                                                <td>
                                                    <span class="badge progress-badge">
                                                        <?= $payment_details['paid_count'] ?>/<?= $payment_details['total_payments_needed'] ?> 
                                                        payments made
                                                    </span>
                                                    <small class="text-muted ms-2">
                                                        (<?= $payment_details['remaining_payments'] ?> remaining)
                                                    </small>
                                                    <div class="progress mt-1" style="height: 8px;">
                                                        <div class="progress-bar bg-success" 
                                                             style="width: <?= ($payment_details['paid_count'] / $payment_details['total_payments_needed']) * 100 ?>%">
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php if (!isPaymentPending($payment_details) && $payment_details['verified_by']): ?>
                                            <tr><th>Verified By</th><td><?= $payment_details['verified_fname'] ?> <?= $payment_details['verified_lname'] ?></td></tr>
                                            <tr><th>Verified At</th><td><?= date('F j, Y g:i A', strtotime($payment_details['verified_at'])) ?></td></tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-3 mb-3">
                            <a href="payments.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Payment List
                            </a>
                        </div>
                    
                    <?php else: ?>
                        <!-- Payment List -->
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center" style="background-color: #3b82f6; color: white;">
                                <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Payments</h5>
                                <div>
                                    <span class="badge bg-warning">
                                        <?= $pending_payments_count ?> Need Action
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Search and Filter Form -->
                                <form method="GET" class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="search" placeholder="Search by member ID, name, or email..." value="<?= htmlspecialchars($search) ?>">
                                            <button class="btn btn-outline-primary" type="submit">
                                                <i class="fas fa-search"></i>
                                            </button>
                                            <?php if (!empty($search)): ?>
                                                <a href="payments.php" class="btn btn-outline-secondary">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <select class="form-select" name="status" onchange="this.form.submit()">
                                            <option value="">All Statuses</option>
                                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                            <option value="verified" <?= $status_filter === 'verified' ? 'selected' : '' ?>>Verified</option>
                                            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <a href="payments.php" class="btn btn-secondary w-90">Reset</a>
                                    </div>
                                </form>

                                <!-- Page Info -->
                                <div class="page-info">
                                    Showing <?= count($payments) ?> of <?= $total_payments ?> payments
                                    <?php if ($total_pages > 1): ?>
                                        - Page <?= $current_page ?> of <?= $total_pages ?>
                                    <?php endif; ?>
                                    <?php if (!empty($search)): ?>
                                        - Search: "<?= htmlspecialchars($search) ?>"
                                    <?php endif; ?>
                                </div>

                                <?php if (empty($payments)): ?>
                                    <div class="alert alert-info text-center py-4">
                                        <i class="fas fa-money-check fa-3x mb-3"></i>
                                        <h5>No payment submissions found</h5>
                                        <p class="text-muted">When members submit payments, they will appear here for verification.</p>
                                        <?php if (!empty($search) || !empty($status_filter)): ?>
                                            <a href="payments.php" class="btn btn-primary">Show All Payments</a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <!-- Pending Payments Section -->
                                    <?php 
                                    $pending_payments = array_filter($payments, function($payment) {
                                        return $payment['status'] === 'pending';
                                    });
                                    ?>
                                    <?php if (count($pending_payments) > 0): ?>
                                    <div class="mb-4">
                                        <h6 class="text-warning mb-3"><i class="fas fa-clock me-2"></i>Pending Verification (<?= count($pending_payments) ?>)</h6>
                                        <div class="table-responsive">
                                            <table class="table table-striped table-warning">
                                                <thead>
                                                    <tr>
                                                        <th>Payment ID</th>
                                                        <th>Member</th>
                                                        <th>Loan Amount</th>
                                                        <th>Payment Amount</th>
                                                        <th>Payment Date</th>
                                                        <th>Receipt</th>
                                                        <th>Status</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($pending_payments as $payment): ?>
                                                        <tr>
                                                            <td><strong>PMT-<?= str_pad($payment['id'], 4, '0', STR_PAD_LEFT) ?></strong></td>
                                                            <td>
                                                                <div><strong><?= $payment['member_id'] ?></strong></div>
                                                                <small><?= $payment['firstname'] ?> <?= $payment['lastname'] ?></small>
                                                            </td>
                                                            <td>₱<?= number_format($payment['principal'], 2) ?></td>
                                                            <td>₱<?= number_format($payment['amount'], 2) ?></td>
                                                            <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                                                            <td>
                                                                <?php if (!empty($payment['receipt_filename'])): ?>
                                                                    <a href="../uploads/receipts/<?= $payment['receipt_filename'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                                        <i class="fas fa-image me-1"></i> View Receipt
                                                                    </a>
                                                                <?php else: ?>
                                                                    <span class="text-muted">No receipt uploaded</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-warning">Pending</span>
                                                            </td>
                                                            <td>
                                                                <form method="POST" class="d-inline">
                                                                    <input type="hidden" name="payment_id" value="<?= $payment['id'] ?>">
                                                                    <button type="submit" name="verify_payment" class="btn btn-success btn-sm">
                                                                        <i class="fas fa-check me-1"></i> Verify
                                                                    </button>
                                                                </form>
                                                                <form method="POST" class="d-inline">
                                                                    <input type="hidden" name="payment_id" value="<?= $payment['id'] ?>">
                                                                    <button type="submit" name="reject_payment" class="btn btn-danger btn-sm">
                                                                        <i class="fas fa-times me-1"></i> Reject
                                                                    </button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Processed Payments Section -->
                                    <?php 
                                    $processed_payments = array_filter($payments, function($payment) {
                                        return $payment['status'] !== 'pending';
                                    });
                                    ?>
                                    <?php if (count($processed_payments) > 0): ?>
                                    <div>
                                        <h6 class="text-secondary mb-3"><i class="fas fa-history me-2"></i>Processed Payments (<?= count($processed_payments) ?>)</h6>
                                        <div class="table-responsive">
                                            <table class="table table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Payment ID</th>
                                                        <th>Member</th>
                                                        <th>Loan Amount</th>
                                                        <th>Payment Amount</th>
                                                        <th>Payment Date</th>
                                                        <th>Receipt</th>
                                                        <th>Status</th>
                                                        <th>Processed Date</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($processed_payments as $payment): ?>
                                                        <tr>
                                                            <td><strong>PMT-<?= str_pad($payment['id'], 4, '0', STR_PAD_LEFT) ?></strong></td>
                                                            <td>
                                                                <div><strong><?= $payment['member_id'] ?></strong></div>
                                                                <small><?= $payment['firstname'] ?> <?= $payment['lastname'] ?></small>
                                                            </td>
                                                            <td>₱<?= number_format($payment['principal'], 2) ?></td>
                                                            <td>₱<?= number_format($payment['amount'], 2) ?></td>
                                                            <td><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                                                            <td>
                                                                <?php if (!empty($payment['receipt_filename'])): ?>
                                                                    <a href="../uploads/receipts/<?= $payment['receipt_filename'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                                        <i class="fas fa-image me-1"></i> View Receipt
                                                                    </a>
                                                                <?php else: ?>
                                                                    <span class="text-muted">No receipt uploaded</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-<?= $payment['status'] === 'verified' ? 'success' : 'danger' ?>">
                                                                    <?= ucfirst($payment['status']) ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <?php if ($payment['verified_at']): ?>
                                                                    <?= date('M j, Y', strtotime($payment['verified_at'])) ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">Not processed</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Pagination -->
                                    <?php if ($total_pages > 1): ?>
                                    <div class="pagination-container">
                                        <nav aria-label="Payment pagination">
                                            <ul class="pagination">
                                                <!-- Previous Page -->
                                                <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                                                    <a class="page-link" href="?page=<?= $current_page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>" aria-label="Previous">
                                                        <span aria-hidden="true">&laquo; Previous</span>
                                                    </a>
                                                </li>
                                                
                                                <!-- Page Numbers -->
                                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                                    <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>"><?= $i ?></a>
                                                    </li>
                                                <?php endfor; ?>
                                                
                                                <!-- Next Page -->
                                                <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                                                    <a class="page-link" href="?page=<?= $current_page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>" aria-label="Next">
                                                        <span aria-hidden="true">Next &raquo;</span>
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
</body>
</html>