<?php
session_start();
require_once '../db.php';
require_once '../functions.php';

// Check if user is logged in and is member
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'member') {
    header('Location: ../login.php');
    exit;
}

$user_member_id = $_SESSION['user']['member_id'];
$user = $_SESSION['user'];

// Get user statistics
try {
    $pdo = DB::pdo();
    
    // Total contributions
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total_contributions FROM contributions WHERE user_id = ? AND status = 'confirmed'");
    $stmt->execute([$user_member_id]);
    $total_contributions = $stmt->fetchColumn();
    
    // Active loans count (approved loans)
    $stmt = $pdo->prepare("SELECT COUNT(*) as active_loans FROM loans WHERE user_id = ? AND status = 'approved'");
    $stmt->execute([$user_member_id]);
    $active_loans = $stmt->fetchColumn();
    
    // Pending loans count
    $stmt = $pdo->prepare("SELECT COUNT(*) as pending_loans FROM loans WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$user_member_id]);
    $pending_loans = $stmt->fetchColumn();
    
    // Recent activity
    $stmt = $pdo->prepare("
        (SELECT 'contribution' as type, amount, created_at as date, 'Contribution' as description 
         FROM contributions WHERE user_id = ? ORDER BY created_at DESC LIMIT 3)
        UNION ALL
        (SELECT 'loan' as type, principal as amount, created_at as date, 
                CONCAT('Loan Application - ', status) as description 
         FROM loans WHERE user_id = ? ORDER BY created_at DESC LIMIT 3)
        ORDER BY date DESC LIMIT 5
    ");
    $stmt->execute([$user_member_id, $user_member_id]);
    $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $total_contributions = 0;
    $active_loans = 0;
    $pending_loans = 0;
    $recent_activity = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Dashboard - iBarako Loan System</title>
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
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    }
    .sidebar-brand small {
        font-size: 0.75rem;
        opacity: 0.9;
        font-weight: 500;
        color: #e6f3ff;
        text-shadow: 0 1px 1px rgba(0, 0, 0, 0.1);
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
    /* Main navbar */
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
    }
    .badge.bg-success {
        background: var(--success-gradient) !important;
        border: none;
        padding: 0.5rem 1rem;
        font-weight: 500;
        border-radius: 20px;
        font-size: 0.8rem;
    }
    /* Quick Actions Grid Container */
    .quick-actions-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
        margin-bottom: 2rem;
    }
    /* Quick Action Cards - Uniform Styling */
    .quick-action-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        min-height: 240px;
        padding: 24px;
        background: white;
        border-radius: 12px;
        box-shadow: var(--card-shadow);
        transition: all 0.3s ease;
        border: 1px solid #edf2f7;
    }
.quick-action-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--card-hover-shadow);
    }
    /* Uniform Icon Styling */
    .quick-action-card i {
        width: 32px;
        height: 32px;
        font-size: 32px;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .quick-action-card .fa-plus-circle {
        color: #3182ce;
    }
    .quick-action-card .fa-file-invoice-dollar {
        color: #38a169;
    }
    .quick-action-card .fa-user {
        color: #0987a0;
    }
    /* Card Title Styling */
    .quick-action-card h6 {
        font-weight: 600;
        color: #2d3748;
        font-size: 1.1rem;
        margin-bottom: 16px;
        line-height: 1.3;
    }
    /* Uniform Button Styling */
    .quick-action-card .btn {
        height: 40px;
        min-width: 140px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        text-decoration: none;
    }
    .quick-action-card .btn-primary {
        background: var(--primary-gradient);
        color: white;
    }
    .quick-action-card .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(43, 108, 176, 0.3);
    }
    .quick-action-card .btn-success {
        background: var(--success-gradient);
        color: white;
    }
    .quick-action-card .btn-success:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(56, 161, 105, 0.3);
    }
    .quick-action-card .btn-info {
        background: var(--info-gradient);
        color: white;
    }
    .quick-action-card .btn-info:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(9, 135, 160, 0.3);
    }
    /* Main card styling */
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
    .card-header h4 {
        font-weight: 600;
        color: #2d3748;
        font-size: 1.25rem;
    }
    .card-body {
        padding: 1.5rem;
    }
    /* Quick stats cards */
    .card.bg-light {
        background: white !important;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
        transition: all 0.3s ease;
        border: 1px solid #f1f5f9;
    }
    .card.bg-light:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    }
    .card.bg-light .card-body {
        padding: 1.25rem;
    }
    .text-success {
        background: var(--success-gradient);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        font-weight: 700;
        font-size: 1.4rem !important;
    }
    .text-primary {
        background: var(--primary-gradient);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        font-weight: 700;
        font-size: 1.4rem !important;
    }
    .text-muted {
        color: #718096 !important;
        font-size: 0.85rem;
        font-weight: 500;
    }
    /* Recent activity */
    .list-group-item {
        border: none;
        border-radius: 10px !important;
        margin-bottom: 0.5rem;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
        transition: all 0.2s ease;
        border-left: 4px solid #e2e8f0;
        padding: 1rem 1.25rem;
        background: #fafbfc;
    }
    .list-group-item:hover {
        transform: translateX(2px);
        border-left-color: #3182ce;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        background: white;
    }
    .list-group-item h6 {
        font-weight: 600;
        color: #2d3748;
        font-size: 0.9rem;
        text-transform: capitalize;
    }
    .list-group-item small {
        color: #718096;
        font-weight: 500;
    }
    .alert {
        border: none;
        border-radius: 10px;
        font-weight: 500;
        border-left: 4px solid #4299e1;
		font-size: 0.875rem;
		padding: 0.75rem 1rem;
    }
    .alert-info {
        background: #ebf8ff;
        color: #2b6cb0;
    }
    /* Text and icon styling */
    .text-dark {
        color: #2d3748 !important;
    }
    .fa-tachometer-alt, .fa-user, .fa-chart-line, .fa-file-invoice-dollar, .fa-credit-card, .fa-sign-out-alt {
        opacity: 0.9;
    }
    /* Welcome section */
    .card-title .fa-home {
        color: #3182ce;
    }
    .text-muted .fa-user, .text-muted .fa-envelope {
        color: #a0aec0;
        font-size: 0.9rem;
    }
    /* Professional color refinements */
    .bg-white {
        background-color: white !important;
    }
    .border-bottom {
        border-color: #e2e8f0 !important;
    }
    /* Responsive design for quick actions */
    @media (max-width: 1023px) and (min-width: 768px) {
        .quick-actions-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
    }
    @media (max-width: 767px) {
        .quick-actions-grid {
            grid-template-columns: 1fr;
            gap: 16px;
        }
        .quick-action-card {
            min-height: 220px;
            padding: 20px;
        }
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
            <!-- Sidebar Navigation -->
            <div class="sidebar">
                <div class="sidebar-brand text-white">
                    <div class="text-center mb-3">
						<img src="../ibarako_logov2.PNG" alt="iBarako Logo" class="logo" style="max-height: 45px;">
					</div>
                    <small class="text-light">Member Portal</small>
                </div>
                <nav class="nav flex-column mt-3">
                    <a class="nav-link active" href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>Dashboard
                    </a>
                    <a class="nav-link" href="profile.php">
                        <i class="fas fa-user"></i>My Profile
                    </a>
                    <a class="nav-link" href="contributions.php">
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
                        <h4 class="mb-0 text-dark">Member Dashboard</h4>
                        <span class="badge bg-success">Member ID: <?= $user['member_id'] ?></span>
                    </div>
                </nav>

                <div class="container-fluid mt-4">
                    <!-- Flash Message -->
                    <?php if ($message = get_flash_message()): ?>
                        <div class="alert alert-info alert-dismissible fade show" role="alert">
                            <?= $message['message'] ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="card">
                        <div class="card-header bg-white">
                            <h4 class="card-title mb-0">
                                <i class="fas fa-home me-2"></i>Welcome back, <?= htmlspecialchars($user['firstname']) ?>!
                            </h4>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">
                                <i class="fas fa-user me-1"></i>Member ID: <?= $user['member_id'] ?> | 
                                <i class="fas fa-envelope me-1"></i><?= $user['email'] ?>
                            </p>
                            
                            <!-- Quick Stats -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h5 class="text-success">₱<?= number_format($total_contributions, 2) ?></h5>
                                            <small class="text-muted">Total Contributions</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h5 class="text-primary"><?= $active_loans ?></h5>
                                            <small class="text-muted">Active Loans</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h5 class="text-warning"><?= $pending_loans ?></h5>
                                            <small class="text-muted">Pending Loans</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Quick Actions -->
                            <div class="row mb-4">
                                <div class="col-md-4 mb-3">
                                    <div class="card quick-action-card h-100 text-center">
                                        <div class="card-body">
											<div class="d-flex justify-content-center mt-2">
												<i class="fas fa-plus-circle fa-3x text-primary mb-2"></i>
											</div>
                                            <h6>Make Contribution</h6>
                                            <a href="contributions.php" class="btn btn-primary btn-sm">Add Contribution</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="card quick-action-card h-100 text-center">
                                        <div class="card-body">
											<div class="d-flex justify-content-center mt-2">
												<i class="fas fa-file-invoice-dollar fa-3x text-success mb-2"></i>
											</div>
                                            <h6>Apply for Loan</h6>
                                            <a href="loans.php" class="btn btn-success btn-sm">Apply Now</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="card quick-action-card h-100 text-center">
                                        <div class="card-body">
                                           <div class="d-flex justify-content-center mt-2">
												<i class="fas fa-user fa-3x text-info mb-2"></i>
											</div>
                                            <h6>My Profile</h6>
                                            <a href="profile.php" class="btn btn-info btn-sm">View Profile</a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Recent Activity -->
                            <h5><i class="fas fa-history me-2"></i>Recent Activity</h5>
                            <?php if (!empty($recent_activity)): ?>
                                <div class="list-group">
                                    <?php foreach ($recent_activity as $activity): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1 text-capitalize"><?= $activity['type'] ?></h6>
                                                <small><?= format_date($activity['date']) ?></small>
                                            </div>
                                            <p class="mb-1"><?= $activity['description'] ?></p>
                                            <?php if (isset($activity['amount'])): ?>
                                                <small class="text-muted">Amount: <?= format_currency($activity['amount']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    No recent activity found. Start by making a contribution or applying for a loan.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>