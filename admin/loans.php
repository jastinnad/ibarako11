<?php
session_start();
require_once '../db.php';
require_once '../notifications.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$user = $_SESSION['user'];
$action = $_GET['action'] ?? '';
$error = '';
$success = '';

// Handle loan actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get loan_id from POST
    $loan_id = intval($_POST['loan_id']);
    
    if (isset($_POST['approve_loan'])) {
        try {
            $pdo = DB::pdo();
            
            // First, verify the loan exists and is pending/active
            $stmt = $pdo->prepare("SELECT id, status FROM loans WHERE id = ?");
            $stmt->execute([$loan_id]);
            $loan = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$loan) {
                $_SESSION['error'] = "Loan application not found. It may have been deleted.";
                header('Location: loans.php');
                exit;
            }
            
            // Check if loan is pending or active
            if ($loan['status'] !== 'pending' && $loan['status'] !== 'active') {
                $_SESSION['error'] = "Loan application is already processed (current status: " . $loan['status'] . ").";
                header('Location: loans.php');
                exit;
            }
            
            // Update loan status to approved
            $stmt = $pdo->prepare("UPDATE loans SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $result = $stmt->execute([$user['member_id'], $loan_id]);
            
            if ($result) {
                // Get updated loan details to notify member
                $stmt = $pdo->prepare("SELECT l.*, u.firstname, u.lastname, u.email, u.member_id as user_member_id FROM loans l JOIN users u ON l.user_id = u.member_id WHERE l.id = ?");
                $stmt->execute([$loan_id]);
                $updated_loan = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($updated_loan) {
                    // Notify member about loan approval
                    Notifications::notifyUser(
                        $updated_loan['user_member_id'],
                        'loan_approval',
                        'Loan Application Approved',
                        'Your loan application for ₱' . number_format($updated_loan['principal'], 2) . ' has been approved.',
                        $loan_id,
                        $loan_id
                    );
                }
                
                $_SESSION['success'] = "Loan application approved successfully!";
            } else {
                $_SESSION['error'] = "Failed to update loan status. Please try again.";
            }
            
            header('Location: loans.php');
            exit;
            
        } catch (Exception $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
            header('Location: loans.php');
            exit;
        }
    } elseif (isset($_POST['reject_loan'])) {
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');
        try {
            $pdo = DB::pdo();
            
            // First, verify the loan exists and is pending/active
            $stmt = $pdo->prepare("SELECT id, status FROM loans WHERE id = ?");
            $stmt->execute([$loan_id]);
            $loan = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$loan) {
                $_SESSION['error'] = "Loan application not found. It may have been deleted.";
                header('Location: loans.php');
                exit;
            }
            
            // Check if loan is pending or active
            if ($loan['status'] !== 'pending' && $loan['status'] !== 'active') {
                $_SESSION['error'] = "Loan application is already processed (current status: " . $loan['status'] . ").";
                header('Location: loans.php');
                exit;
            }
            
            // Update loan status to rejected
            $stmt = $pdo->prepare("UPDATE loans SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?");
            $result = $stmt->execute([$user['member_id'], $rejection_reason, $loan_id]);
            
            if ($result) {
                // Get loan details to notify member
                $stmt = $pdo->prepare("SELECT l.*, u.firstname, u.lastname, u.email, u.member_id as user_member_id FROM loans l JOIN users u ON l.user_id = u.member_id WHERE l.id = ?");
                $stmt->execute([$loan_id]);
                $loan = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($loan) {
                    // Notify member about loan rejection
                    Notifications::notifyUser(
                        $loan['user_member_id'],
                        'loan_rejection',
                        'Loan Application Rejected',
                        'Your loan application for ₱' . number_format($loan['principal'], 2) . ' has been rejected.' . ($rejection_reason ? ' Reason: ' . $rejection_reason : ''),
                        $loan_id,
                        $loan_id
                    );
                }
                
                $_SESSION['success'] = "Loan application rejected. Member has been notified.";
            } else {
                $_SESSION['error'] = "Failed to reject loan. Please try again.";
            }
            
            header('Location: loans.php');
            exit;
            
        } catch (Exception $e) {
            $_SESSION['error'] = "Error rejecting loan: " . $e->getMessage();
            header('Location: loans.php');
            exit;
        }
    }
}

// Handle loan agreement generation
if (isset($_GET['generate_agreement'])) {
    $loan_id = intval($_GET['generate_agreement']);
    header('Location: generate_loan_agreement.php?loan_id=' . $loan_id);
    exit;
}

// Check for success/error messages from redirect
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Search functionality
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Pagination setup
$loans_per_page = 7;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $loans_per_page;

// Build query for loans with search and filters
$pdo = DB::pdo();
try {
    $query = "
        SELECT l.*, u.member_id, u.firstname, u.lastname, u.email, u.mobile
        FROM loans l 
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
        $query .= " AND l.status = ?";
        $params[] = $status_filter;
    }
    
    $query .= " ORDER BY 
        CASE 
            WHEN l.status = 'pending' THEN 1
            WHEN l.status = 'active' THEN 1
            WHEN l.status = 'approved' THEN 2
            WHEN l.status = 'rejected' THEN 3
            ELSE 4
        END,
        l.created_at DESC";
    
    // Get total count for pagination
    $count_query = "SELECT COUNT(*) as total FROM loans l JOIN users u ON l.user_id = u.member_id WHERE 1=1";
    $count_params = [];
    
    if (!empty($search)) {
        $count_query .= " AND (u.member_id LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ?)";
        $search_term = "%$search%";
        $count_params = [$search_term, $search_term, $search_term, $search_term];
    }
    
    if (!empty($status_filter)) {
        $count_query .= " AND l.status = ?";
        $count_params[] = $status_filter;
    }
    
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($count_params);
    $total_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_loans = $total_result['total'];
    $total_pages = ceil($total_loans / $loans_per_page);
    
    // Get loans for current page
    $query .= " LIMIT ? OFFSET ?";
    $params[] = $loans_per_page;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Error loading loans: " . $e->getMessage();
    $loans = [];
    $total_loans = 0;
    $total_pages = 1;
}

// Get loan details for view action
$loan_details = null;
if ($action === 'view' && isset($_GET['id'])) {
    $loan_id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("
            SELECT l.*, u.member_id, u.firstname, u.lastname, u.email, u.mobile, u.nature_of_work, u.salary
            FROM loans l 
            JOIN users u ON l.user_id = u.member_id 
            WHERE l.id = ?
        ");
        $stmt->execute([$loan_id]);
        $loan_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($loan_details && $loan_details['approved_by']) {
            // Get approver details if loan is approved
            $stmt = $pdo->prepare("SELECT firstname, lastname FROM users WHERE member_id = ?");
            $stmt->execute([$loan_details['approved_by']]);
            $approver = $stmt->fetch(PDO::FETCH_ASSOC);
            $loan_details['approved_fname'] = $approver['firstname'] ?? '';
            $loan_details['approved_lname'] = $approver['lastname'] ?? '';
        }
        
        // Calculate total repayment amount if not already calculated
        if ($loan_details && empty($loan_details['total_amount'])) {
            $principal = $loan_details['principal'];
            $interest_rate = $loan_details['interest_rate'];
            $term_months = $loan_details['term_months'];
            $total_interest = $principal * ($interest_rate / 100) * $term_months;
            $loan_details['total_amount'] = $principal + $total_interest;
        }
        
    } catch (Exception $e) {
        $error = "Error loading loan details: " . $e->getMessage();
    }
}

// Calculate pending loans count
$pending_loans_count = 0;
foreach ($loans as $loan) {
    if ($loan['status'] === 'pending' || $loan['status'] === 'active') {
        $pending_loans_count++;
    }
}

// Function to generate clean Loan ID (LN-0001, LN-0002, etc.)
function generateCleanLoanId($loan_id, $existing_loan_number = null) {
    // Always use sequential format: LN-0001, LN-0002, etc.
    return 'LN-' . str_pad($loan_id, 4, '0', STR_PAD_LEFT);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Applications - iBarako Loan System</title>
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
		.alert {
			font-size: 0.8rem; 
			padding: 0.5rem 1rem; 
		}
		.alert strong {
			font-size: 0.9rem;
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
                    <a class="nav-link active" href="loans.php">
                        <i class="fas fa-file-invoice-dollar"></i>Loan Applications
                        <?php if ($pending_loans_count > 0): ?>
                            <span class="badge badge-notification bg-danger ms-1"><?= $pending_loans_count ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="nav-link" href="payments.php">
                        <i class="fas fa-money-check"></i>Payment Verification
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
                        <h4 class="mt-2 mb-2 text-dark">Loan Applications Management</h4>
                    </div>
                </nav>

                <div class="container-fluid mt-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= $success ?></div>
                    <?php endif; ?>

                    <?php if ($action === 'view' && $loan_details): ?>
                        <!-- Loan Details View -->
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Loan Application Details</h5>
                                <div>
                                    <span class="badge bg-<?= 
                                        $loan_details['status'] === 'approved' ? 'success' : 
                                        ($loan_details['status'] === 'rejected' ? 'danger' : 'warning') 
                                    ?>">
                                        <?= ucfirst($loan_details['status']) ?>
                                    </span>
                                    <?php if ($loan_details['status'] === 'approved'): ?>
                                        <a href="?generate_agreement=<?= $loan_details['id'] ?>" class="btn btn-success btn-sm ms-2">
                                            <i class="fas fa-file-pdf me-1"></i>Generate Agreement
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="border-bottom pb-2">Member Information</h6>
                                        <table class="table table-bordered">
                                            <tr><th>Member ID</th><td><?= $loan_details['member_id'] ?></td></tr>
                                            <tr><th>Name</th><td><?= $loan_details['firstname'] ?> <?= $loan_details['lastname'] ?></td></tr>
                                            <tr><th>Email</th><td><?= $loan_details['email'] ?></td></tr>
                                            <tr><th>Mobile</th><td><?= $loan_details['mobile'] ?></td></tr>
                                            <tr><th>Nature of Work</th><td><?= $loan_details['nature_of_work'] ?></td></tr>
                                            <tr><th>Monthly Salary</th><td>₱<?= number_format($loan_details['salary'], 2) ?></td></tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="border-bottom pb-2">Loan Details</h6>
                                        <table class="table table-bordered">
                                            <tr><th>Loan ID</th><td><?= generateCleanLoanId($loan_details['id']) ?></td></tr>
                                            <tr><th>Loan Amount</th><td>₱<?= number_format($loan_details['principal'], 2) ?></td></tr>
                                            <tr><th>Term</th><td><?= $loan_details['term_months'] ?> months</td></tr>
                                            <tr><th>Interest Rate</th><td><?= $loan_details['interest_rate'] ?>% monthly</td></tr>
                                            <tr><th>Monthly Payment</th><td>₱<?= number_format($loan_details['monthly_payment'], 2) ?></td></tr>
                                            <tr><th>Total Repayment</th>
                                                <td>₱<?= number_format($loan_details['total_amount'] ?? ($loan_details['principal'] + ($loan_details['principal'] * ($loan_details['interest_rate'] / 100) * $loan_details['term_months'])), 2) ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <h6 class="border-bottom pb-2">Additional Information</h6>
                                        <table class="table table-bordered">
                                            <tr><th>Loan Purpose</th><td><?= nl2br(htmlspecialchars($loan_details['purpose'] ?? 'Not specified')) ?></td></tr>
                                            <tr><th>Payment Method</th><td><?= ucfirst($loan_details['payment_method'] ?? 'Not specified') ?></td></tr>
                                            <tr><th>Account Details</th><td><?= $loan_details['account_details'] ?? 'Not specified' ?></td></tr>
                                            <tr><th>Account Name</th><td><?= $loan_details['account_name'] ?? 'Not specified' ?></td></tr>
                                            <tr><th>Application Date</th><td><?= date('F j, Y g:i A', strtotime($loan_details['created_at'])) ?></td></tr>
                                            <?php if ($loan_details['approved_at']): ?>
                                            <tr><th>Processed Date</th><td><?= date('F j, Y g:i A', strtotime($loan_details['approved_at'])) ?></td></tr>
                                            <tr><th>Processed By</th><td><?= $loan_details['approved_fname'] ?? '' ?> <?= $loan_details['approved_lname'] ?? '' ?></td></tr>
                                            <?php if ($loan_details['status'] === 'rejected' && !empty($loan_details['rejection_reason'])): ?>
                                            <tr><th>Rejection Reason</th><td><?= nl2br(htmlspecialchars($loan_details['rejection_reason'])) ?></td></tr>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </table>
                                        
                                        <!-- APPROVE/REJECT BUTTONS - ONLY SHOW FOR PENDING/ACTIVE LOANS -->
                                        <?php if ($loan_details['status'] === 'pending' || $loan_details['status'] === 'active'): ?>
                                            <div class="text-center mt-4 p-3 border-top">
                                                <h6 class="mb-3">Loan Action</h6>
                                                <div class="d-flex justify-content-center gap-3 flex-wrap">
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="loan_id" value="<?= $loan_details['id'] ?>">
                                                        <button type="submit" name="approve_loan" class="btn btn-success btn-lg">
                                                            <i class="fas fa-check-circle me-2"></i>Approve Loan
                                                        </button>
                                                    </form>
                                                    
                                                    <button type="button" class="btn btn-danger btn-lg" data-bs-toggle="modal" data-bs-target="#rejectModal">
                                                        <i class="fas fa-times-circle me-2"></i>Reject Loan
                                                    </button>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <!-- HIDE BUTTONS FOR APPROVED/REJECTED LOANS -->
                                            <div class="alert alert-<?= $loan_details['status'] === 'approved' ? 'success' : 'danger' ?> text-center mt-4 mb-0">
                                                <i class="fas fa-info-circle me-2"></i>
                                                This loan application has been <strong><?= ucfirst($loan_details['status']) ?></strong>
                                                <?php if ($loan_details['approved_at']): ?>
                                                    on <?= date('F j, Y', strtotime($loan_details['approved_at'])) ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-3 mb-3">
                            <a href="loans.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Loan List
                            </a>
                        </div>

                        <!-- Reject Loan Modal - ONLY SHOW FOR PENDING/ACTIVE LOANS -->
                        <?php if ($loan_details['status'] === 'pending' || $loan_details['status'] === 'active'): ?>
                        <div class="modal fade" id="rejectModal" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header bg-danger text-white">
                                        <h5 class="modal-title">Reject Loan Application</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST">
                                        <div class="modal-body">
                                            <input type="hidden" name="loan_id" value="<?= $loan_details['id'] ?>">
                                            <div class="alert alert-warning">
                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                <strong>Warning:</strong> Rejecting this loan application cannot be undone.
                                            </div>
                                            <div class="mb-3">
                                                <label for="rejection_reason" class="form-label">Reason for Rejection *</label>
                                                <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="4" 
                                                          placeholder="Please provide a reason for rejecting this loan application. This will be visible to the member." required></textarea>
                                                <small class="text-muted">This reason will be communicated to the member.</small>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="reject_loan" class="btn btn-danger">Confirm Rejection</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    
                    <?php else: ?>
                        <!-- Loan List -->
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center" style="background-color: #3b82f6; color: white;">
                                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Loan Applications</h5>
                                <div>
                                    <span class="badge bg-primary"><?= $total_loans ?> Total</span>
                                    <?php if ($pending_loans_count > 0): ?>
                                        <span class="badge bg-warning"><?= $pending_loans_count ?> Pending</span>
                                    <?php endif; ?>
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
                                                <a href="loans.php" class="btn btn-outline-secondary">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <select class="form-select" name="status" onchange="this.form.submit()">
                                            <option value="">All Statuses</option>
                                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                                            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                            <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <a href="loans.php" class="btn btn-secondary w-90">Reset</a>
                                    </div>
                                </form>

                                <!-- Page Info -->
                                <div class="page-info">
                                    Showing <?= count($loans) ?> of <?= $total_loans ?> loans
                                    <?php if ($total_pages > 1): ?>
                                        - Page <?= $current_page ?> of <?= $total_pages ?>
                                    <?php endif; ?>
                                    <?php if (!empty($search)): ?>
                                        - Search: "<?= htmlspecialchars($search) ?>"
                                    <?php endif; ?>
                                </div>

                                <?php if (empty($loans)): ?>
                                    <div class="alert alert-info text-center py-4">
                                        <i class="fas fa-file-invoice-dollar fa-3x mb-3"></i>
                                        <h5>No loan applications found</h5>
                                        <p class="text-muted">When members apply for loans, they will appear here for review.</p>
                                        <?php if (!empty($search) || !empty($status_filter)): ?>
                                            <a href="loans.php" class="btn btn-primary">Show All Loans</a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <!-- Pending Loans Section -->
                                    <?php 
                                    $pending_loans = array_filter($loans, function($loan) {
                                        return $loan['status'] === 'pending' || $loan['status'] === 'active';
                                    });
                                    ?>
                                    <?php if (count($pending_loans) > 0): ?>
                                    <div class="mb-4">
                                        <h6 class="text-warning mb-3"><i class="fas fa-clock me-2"></i>Pending Applications (<?= count($pending_loans) ?>)</h6>
                                        <div class="table-responsive">
                                            <table class="table table-striped table-warning">
                                                <thead>
                                                    <tr>
                                                        <th>Loan ID</th>
                                                        <th>Member</th>
                                                        <th>Amount</th>
                                                        <th>Term</th>
                                                        <th>Monthly Payment</th>
                                                        <th>Status</th>
                                                        <th>Applied Date</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($pending_loans as $loan): ?>
                                                        <tr>
                                                            <td><strong><?= generateCleanLoanId($loan['id']) ?></strong></td>
                                                            <td>
                                                                <div><strong><?= $loan['member_id'] ?></strong></div>
                                                                <small><?= $loan['firstname'] ?> <?= $loan['lastname'] ?></small>
                                                            </td>
                                                            <td>₱<?= number_format($loan['principal'], 2) ?></td>
                                                            <td><?= $loan['term_months'] ?> months</td>
                                                            <td>₱<?= number_format($loan['monthly_payment'], 2) ?></td>
                                                            <td>
                                                                <span class="badge bg-warning">Pending</span>
                                                            </td>
                                                            <td><?= date('M j, Y', strtotime($loan['created_at'])) ?></td>
                                                            <td>
                                                                <a href="loans.php?action=view&id=<?= $loan['id'] ?>" class="btn btn-primary btn-sm">
                                                                    <i class="fas fa-eye me-1"></i> Review
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Processed Loans Section -->
                                    <?php 
                                    $processed_loans = array_filter($loans, function($loan) {
                                        return $loan['status'] !== 'pending' && $loan['status'] !== 'active';
                                    });
                                    ?>
                                    <?php if (count($processed_loans) > 0): ?>
                                    <div>
                                        <h6 class="text-secondary mb-3"><i class="fas fa-history me-2"></i>Processed Applications (<?= count($processed_loans) ?>)</h6>
                                        <div class="table-responsive">
                                            <table class="table table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Loan ID</th>
                                                        <th>Member</th>
                                                        <th>Amount</th>
                                                        <th>Term</th>
                                                        <th>Monthly Payment</th>
                                                        <th>Status</th>
                                                        <th>Applied Date</th>
                                                        <th>Processed Date</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($processed_loans as $loan): ?>
                                                        <tr>
                                                            <td><strong><?= generateCleanLoanId($loan['id']) ?></strong></td>
                                                            <td>
                                                                <div><strong><?= $loan['member_id'] ?></strong></div>
                                                                <small><?= $loan['firstname'] ?> <?= $loan['lastname'] ?></small>
                                                            </td>
                                                            <td>₱<?= number_format($loan['principal'], 2) ?></td>
                                                            <td><?= $loan['term_months'] ?> months</td>
                                                            <td>₱<?= number_format($loan['monthly_payment'], 2) ?></td>
                                                            <td>
                                                                <span class="badge bg-<?= $loan['status'] === 'approved' ? 'success' : 'danger' ?>">
                                                                    <?= ucfirst($loan['status']) ?>
                                                                </span>
                                                            </td>
                                                            <td><?= date('M j, Y', strtotime($loan['created_at'])) ?></td>
                                                            <td>
                                                                <?php if ($loan['approved_at']): ?>
                                                                    <?= date('M j, Y', strtotime($loan['approved_at'])) ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">Not processed</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <a href="loans.php?action=view&id=<?= $loan['id'] ?>" class="btn btn-outline-primary btn-sm">
                                                                    <i class="fas fa-eye me-1"></i> View
                                                                </a>
                                                                <?php if ($loan['status'] === 'approved'): ?>
                                                                    <a href="?generate_agreement=<?= $loan['id'] ?>" class="btn btn-success btn-sm ms-1" title="Generate Loan Agreement">
                                                                        <i class="fas fa-file-pdf"></i>
                                                                    </a>
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
                                        <nav aria-label="Loan pagination">
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
    <script>
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>