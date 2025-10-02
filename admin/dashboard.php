<?php
session_start();
require_once '../db.php';
require_once '../functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$user = $_SESSION['user'];

// Get pending contributions count for notifications
try {
    $pdo = DB::pdo();
    
    // Get pending contributions
    $stmt = $pdo->prepare("SELECT COUNT(*) as pending_count FROM contributions WHERE status = 'pending'");
    $stmt->execute();
    $pending_contributions = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get pending loan applications
    $stmt = $pdo->prepare("SELECT COUNT(*) as pending_loans FROM loans WHERE status = 'pending'");
    $stmt->execute();
    $pending_loans = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get pending payment verifications
    $stmt = $pdo->prepare("SELECT COUNT(*) as pending_payments FROM loan_payments WHERE status = 'pending'");
    $stmt->execute();
    $pending_payments = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent pending contributions for notifications
    $stmt = $pdo->prepare("
        SELECT c.*, u.firstname, u.lastname, u.member_id 
        FROM contributions c 
        JOIN users u ON c.member_id = u.member_id 
        WHERE c.status = 'pending' 
        ORDER BY c.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_contributions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get system statistics
    $total_members = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'member' AND status = 'active'")->fetchColumn();
    $total_loans = $pdo->query("SELECT COUNT(*) FROM loans WHERE status IN ('approved', 'active')")->fetchColumn();
    $total_contributions = $pdo->query("SELECT SUM(amount) FROM contributions WHERE status = 'confirmed'")->fetchColumn();
    $total_contributions = $total_contributions ?: 0;
    
} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $pending_contributions = ['pending_count' => 0];
    $pending_loans = ['pending_loans' => 0];
    $pending_payments = ['pending_payments' => 0];
    $recent_contributions = [];
    $total_members = 0;
    $total_loans = 0;
    $total_contributions = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - iBarako Loan System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
    /* Import modern fonts */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    
    :root {
        --sidebar-bg: linear-gradient(180deg, #e53e3e, #1a365d, #1a365d, #1a365d);
        --sidebar-hover: rgba(255, 255, 255, 0.1);
        --sidebar-active: rgba(255, 255, 255, 0.15);
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
        
    }
    
    .sidebar {
        background: var(--sidebar-bg);
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
        background: var(--sidebar-hover);
        border-left-color: #3b82f6;
    }
    
    .nav-link i {
        width: 20px;
        text-align: center;
        margin-right: 0.5rem;
    }
    
    .notification-badge {
        position: absolute;
        top: 8px;
        right: 8px;
        background: #ef4444;
        color: white;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        font-size: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
    }
    
    .stats-card:hover {
        box-shadow: var(--card-hover-shadow);
        transform: translateY(-2px);
    }

	.stats-card {
		border: none;
		border-radius: 12px;
		box-shadow: var(--card-shadow);
		transition: all 0.3s ease;
		background: white;
		overflow: hidden;
		border: 1px solid #edf2f7;
		padding: 1.25rem;
	}

	.stats-card h5.card-title {
		font-size: 1rem; 
		margin-bottom: 0.5rem;
		font-weight: 600;
	}

	.stats-card h2 {
		font-size: 1.5rem; 
		margin-bottom: 0.25rem;
		font-weight: 700;
	}

	.stats-card .text-muted {
		font-size: 0.8rem; 
		margin-bottom: 0;
	}
    
    .card-icon {
        width: 45px;
        height: 45px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
    }
    
    .bg-primary-light { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
    .bg-success-light { background: rgba(34, 197, 94, 0.1); color: #16a34a; }
    .bg-warning-light { background: rgba(249, 115, 22, 0.1); color: #ea580c; }
    .bg-info-light { background: rgba(6, 182, 212, 0.1); color: #0891b2; }
    
    .notification-item {
        border-left: 4px solid #3b82f6;
        background: #f8fafc;
        margin-bottom: 0.5rem;
        padding: 0.75rem;
        border-radius: 8px;
        transition: all 0.3s ease;
    }
    
    .notification-item:hover {
        background: #e7f1ff;
        transform: translateX(2px);
    }
    
    .notification-item.warning {
        border-left-color: #f59e0b;
        background: #fffbeb;
    }
    
    .notification-item.warning:hover {
        background: #fef3c7;
    }
    
    .notification-item.success {
        border-left-color: #10b981;
        background: #ecfdf5;
    }
    
    .notification-item.success:hover {
        background: #d1fae5;
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
                    <a class="nav-link active" href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>Dashboard
                    </a>
                    <a class="nav-link" href="members.php">
                        <i class="fas fa-users"></i>Manage Members
                    </a>
                    <a class="nav-link" href="loans.php">
                        <i class="fas fa-file-invoice-dollar"></i>Loan Applications
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
                        <h4 class="mt-2 mb-2 text-dark">Admin Dashboard</h4>
                        <div class="d-flex align-items-center">
                            <span class="badge bg-primary me-3">Welcome, <?= $user['firstname'] ?>!</span>
                            <div class="dropdown">
                                <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-bell"></i>
                                    <?php if ($pending_contributions['pending_count'] > 0 || $pending_loans['pending_loans'] > 0 || $pending_payments['pending_payments'] > 0): ?>
                                        <span class="notification-badge"><?= $pending_contributions['pending_count'] + $pending_loans['pending_loans'] + $pending_payments['pending_payments'] ?></span>
                                    <?php endif; ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <?php if ($pending_contributions['pending_count'] > 0): ?>
                                        <li><a class="dropdown-item" href="contributions.php">
                                            <i class="fas fa-chart-line text-primary me-2"></i>
                                            <?= $pending_contributions['pending_count'] ?> Pending Contribution(s)
                                        </a></li>
                                    <?php endif; ?>
                                    <?php if ($pending_loans['pending_loans'] > 0): ?>
                                        <li><a class="dropdown-item" href="loans.php">
                                            <i class="fas fa-file-invoice-dollar text-warning me-2"></i>
                                            <?= $pending_loans['pending_loans'] ?> Pending Loan(s)
                                        </a></li>
                                    <?php endif; ?>
                                    <?php if ($pending_payments['pending_payments'] > 0): ?>
                                        <li><a class="dropdown-item" href="payments.php">
                                            <i class="fas fa-money-check text-success me-2"></i>
                                            <?= $pending_payments['pending_payments'] ?> Payment(s) to Verify
                                        </a></li>
                                    <?php endif; ?>
                                    <?php if ($pending_contributions['pending_count'] == 0 && $pending_loans['pending_loans'] == 0 && $pending_payments['pending_payments'] == 0): ?>
                                        <li><a class="dropdown-item text-muted" href="#" style="font-size: 0.8rem;">
                                            <i class="fas fa-check-circle me-2"></i>
                                            No pending actions
                                        </a></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </nav>

                <div class="container-fluid mt-4">
                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stats-card">
                                <div class="card-body">
                                    <div class="card-icon bg-primary-light">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <h5 class="card-title">Total Members</h5>
                                    <h2 class="text-primary"><?= $total_members ?></h2>
                                    <p class="text-muted mb-0">Active members</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stats-card">
                                <div class="card-body">
                                    <div class="card-icon bg-success-light">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                    </div>
                                    <h5 class="card-title">Active Loans</h5>
                                    <h2 class="text-success"><?= $total_loans ?></h2>
                                    <p class="text-muted mb-0">Approved loans</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stats-card">
                                <div class="card-body">
                                    <div class="card-icon bg-info-light">
                                        <i class="fas fa-chart-line"></i>
                                    </div>
                                    <h5 class="card-title">Total Contributions</h5>
                                    <h2 class="text-info">₱<?= number_format($total_contributions, 2) ?></h2>
                                    <p class="text-muted mb-0">Confirmed contributions</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="stats-card">
                                <div class="card-body">
                                    <div class="card-icon bg-warning-light">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <h5 class="card-title">Pending Actions</h5>
                                    <h2 class="text-warning"><?= $pending_contributions['pending_count'] + $pending_loans['pending_loans'] + $pending_payments['pending_payments'] ?></h2>
                                    <p class="text-muted mb-0">Require attention</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Recent Pending Contributions -->
                        <div class="col-lg-6 mb-4">
                            <div class="card">
                                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="fas fa-bell me-2"></i>Pending Contributions
                                        <?php if ($pending_contributions['pending_count'] > 0): ?>
                                            <span class="badge bg-warning ms-2"><?= $pending_contributions['pending_count'] ?> New</span>
                                        <?php endif; ?>
                                    </h5>
                                    <a href="contributions.php" class="btn btn-sm btn-light">View All</a>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($recent_contributions)): ?>
                                        <div class="text-center py-4">
                                            <i class="fas fa-check-circle fa-2x text-muted mb-3"></i>
                                            <p class="text-muted">No pending contributions</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($recent_contributions as $contribution): ?>
                                            <div class="notification-item warning">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="mb-1"><?= htmlspecialchars($contribution['firstname']) ?> <?= htmlspecialchars($contribution['lastname']) ?></h6>
                                                        <p class="mb-1 text-muted">Member ID: <?= $contribution['member_id'] ?></p>
                                                        <p class="mb-1">
                                                            <strong>Amount:</strong> ₱<?= number_format($contribution['amount'], 2) ?>
                                                            <span class="mx-2">•</span>
                                                            <strong>Method:</strong> <?= ucfirst($contribution['payment_method']) ?>
                                                        </p>
                                                        <small class="text-muted">
                                                            <i class="fas fa-clock me-1"></i>
                                                            <?= date('M j, Y g:i A', strtotime($contribution['created_at'])) ?>
                                                        </small>
                                                    </div>
                                                    <a href="contributions.php?action=verify&id=<?= $contribution['id'] ?>" class="btn btn-sm btn-primary">
                                                        Verify
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="col-lg-6 mb-4">
                            <div class="card">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <a href="members.php" class="btn btn-outline-primary w-100 h-100 d-flex flex-column align-items-center justify-content-center p-3">
                                                <i class="fas fa-users fa-2x mb-2"></i>
                                                <span>Manage Members</span>
                                            </a>
                                        </div>
                                        <div class="col-md-6">
                                            <a href="loans.php" class="btn btn-outline-warning w-100 h-100 d-flex flex-column align-items-center justify-content-center p-3">
                                                <i class="fas fa-file-invoice-dollar fa-2x mb-2"></i>
                                                <span>Loan Applications</span>
                                            </a>
                                        </div>
                                        <div class="col-md-6">
                                            <a href="payments.php" class="btn btn-outline-success w-100 h-100 d-flex flex-column align-items-center justify-content-center p-3">
                                                <i class="fas fa-money-check fa-2x mb-2"></i>
                                                <span>Verify Payments</span>
                                            </a>
                                        </div>
                                        <div class="col-md-6">
                                            <a href="contributions.php" class="btn btn-outline-info w-100 h-100 d-flex flex-column align-items-center justify-content-center p-3">
                                                <i class="fas fa-chart-line fa-2x mb-2"></i>
                                                <span>Contributions</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- System Status -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>System Status</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-md-3 mb-3">
                                            <div class="p-3 rounded bg-primary text-white">
                                                <i class="fas fa-user-check fa-2x mb-2"></i>
                                                <h5>Member System</h5>
                                                <span class="badge bg-success">Active</span>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="p-3 rounded bg-success text-white">
                                                <i class="fas fa-hand-holding-usd fa-2x mb-2"></i>
                                                <h5>Loan System</h5>
                                                <span class="badge bg-success">Active</span>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="p-3 rounded bg-info text-white">
                                                <i class="fas fa-chart-line fa-2x mb-2"></i>
                                                <h5>Contribution System</h5>
                                                <span class="badge bg-success">Active</span>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="p-3 rounded bg-warning text-white">
                                                <i class="fas fa-cog fa-2x mb-2"></i>
                                                <h5>Payment System</h5>
                                                <span class="badge bg-success">Active</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh notifications every 30 seconds
        setInterval(() => {
            // You can implement AJAX refresh here if needed
            console.log('Refreshing notifications...');
        }, 30000);
    </script>
</body>
</html>