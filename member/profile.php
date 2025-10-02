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
$error = '';
$success = '';

// Get complete user profile
try {
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("
        SELECT * FROM users 
        WHERE member_id = ? AND role = 'member'
    ");
    $stmt->execute([$user_member_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        header('Location: ../login.php');
        exit;
    }
} catch (Exception $e) {
    $error = "Error loading profile: " . $e->getMessage();
}

// Handle profile update request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_update'])) {
    $new_email = sanitize_input($_POST['email'] ?? '');
    $new_mobile = sanitize_input($_POST['mobile'] ?? '');
    $reason = sanitize_input($_POST['reason'] ?? '');
    
    $errors = [];
    
    // Validation
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address";
    }
    
    if (empty($new_mobile)) {
        $errors[] = "Please enter your mobile number";
    }
    
    if (empty($reason)) {
        $errors[] = "Please provide a reason for the update";
    }
    
    // Check if email already exists (excluding current user)
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT member_id FROM users WHERE email = ? AND member_id != ?");
            $stmt->execute([$new_email, $user_member_id]);
            if ($stmt->fetch()) {
                $errors[] = "This email address is already registered by another member";
            }
        } catch (Exception $e) {
            error_log("Email check error: " . $e->getMessage());
        }
    }
    
    if (empty($errors)) {
        try {
            // Insert update request
            $stmt = $pdo->prepare("
                INSERT INTO update_requests (user_id, current_email, new_email, current_mobile, new_mobile, reason, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $user_member_id,
                $user['email'],
                $new_email,
                $user['mobile'],
                $new_mobile,
                $reason
            ]);
            
            $success = "Update request submitted successfully! Waiting for admin approval.";
            
        } catch (Exception $e) {
            $error = "Error submitting update request: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Get pending update requests
$pending_requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT ur.* 
        FROM update_requests ur 
        WHERE ur.user_id = ? AND ur.status = 'pending' 
        ORDER BY ur.created_at DESC
    ");
    $stmt->execute([$user_member_id]);
    $pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching update requests: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - iBarako Loan System</title>
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
    
    /* Main navbar - Matching Dashboard */
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
    
    .card-header h4, .card-header h5 {
        font-weight: 600;
        color: #2d3748;
        font-size: 1.25rem;
    }
    
    .card-body {
        padding: 1.5rem;
    }
    
    /* Profile Card Specific Styling */
    .profile-card {
        border-left: 4px solid #3182ce;
    }
    
    .update-request-card {
        border-left: 4px solid #ed8936;
    }
    
    /* Table Styling */
    .table {
        margin-bottom: 0;
    }
    
    .table th {
        font-weight: 600;
        color: #2d3748;
        background-color: #f8fafc;
        border-color: #e2e8f0;
    }
    
    .table td {
        border-color: #e2e8f0;
        color: #4a5568;
    }
    
    .table-striped tbody tr:nth-of-type(odd) {
        background-color: #fafbfc;
    }
    
    /* Badge Styling */
    .badge {
        font-weight: 500;
        font-size: 0.75rem;
        padding: 0.35em 0.65em;
    }
    
    .bg-success {
        background: var(--success-gradient) !important;
    }
    
    .bg-warning {
        background: var(--warning-gradient) !important;
    }
    
    .bg-info {
        background: var(--info-gradient) !important;
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

	.alert-info {
		background: #ebf8ff;
		color: #2b6cb0;
		border-left-color: #4299e1;
	}

	.alert-warning {
		background: #fffaf0;
		color: #dd6b20;
		border-left-color: #ed8936;
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
    
    /* Form Styling */
    .form-label {
        font-weight: 600;
        color: #2d3748;
        margin-bottom: 0.5rem;
    }
    
    .form-control {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.75rem;
        transition: all 0.3s ease;
    }
    
    .form-control:focus {
        border-color: #3182ce;
        box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
    }
    
    /* Button Styling */
    .btn {
        border: none;
        border-radius: 8px;
        font-weight: 600;
        padding: 0.75rem 1.5rem;
        transition: all 0.3s ease;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    
    .btn-warning {
        background: var(--warning-gradient);
        color: white;
    }
    
    .btn-warning:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(237, 137, 54, 0.3);
    }
    
    /* Icon Styling */
    .fa-user-circle {
        color: #718096;
    }
    
    .fa-user-circle.fa-5x {
        font-size: 5rem;
    }
    
    /* Text and spacing */
    .text-muted {
        color: #718096 !important;
        font-size: 0.85rem;
        font-weight: 500;
    }
    
    .text-dark {
        color: #2d3748 !important;
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
    }
    
    @media (max-width: 576px) {
        .card-header {
            padding: 1rem;
        }
        
        .card-header h5 {
            font-size: 1.1rem;
        }
        
        .btn {
            padding: 0.625rem 1.25rem;
            font-size: 0.875rem;
        }
        
        .alert {
            font-size: 0.875rem;
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
                    <a class="nav-link active" href="profile.php">
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
                        <h4 class="mb-0 text-dark">My Profile</h4>
                        <span class="badge bg-success">Member ID: <?= $user['member_id'] ?></span>
                    </div>
                </nav>

                <div class="container-fluid mt-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= $success ?></div>
                    <?php endif; ?>

                    <!-- Profile Information -->
                    <div class="card profile-card mb-4">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-user-circle me-2"></i>Personal Information
                            </h5>
                            <span class="badge bg-<?= $user['status'] === 'active' ? 'success' : 'warning' ?>">
                                <?= ucfirst($user['status']) ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <table class="table table-bordered">
                                        <tr>
                                            <th width="30%" class="bg-light">Member ID</th>
                                            <td><?= $user['member_id'] ?></td>
                                        </tr>
                                        <tr>
                                            <th class="bg-light">First Name</th>
                                            <td><?= htmlspecialchars($user['firstname']) ?></td>
                                        </tr>
                                        <tr>
                                            <th class="bg-light">Last Name</th>
                                            <td><?= htmlspecialchars($user['lastname']) ?></td>
                                        </tr>
                                        <tr>
                                            <th class="bg-light">Email Address</th>
                                            <td>
                                                <?= $user['email'] ?>
                                                <?php if (!empty($user['email'])): ?>
                                                    <span class="badge bg-success ms-2">Verified</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning ms-2">Not Provided</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="bg-light">Mobile Number</th>
                                            <td>
                                                <?= $user['mobile'] ?: 'Not provided' ?>
                                                <?php if (!empty($user['mobile'])): ?>
                                                    <span class="badge bg-success ms-2">Verified</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning ms-2">Not Provided</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="bg-light">Member Since</th>
                                            <td><?= format_date($user['created_at'], 'F j, Y') ?></td>
                                        </tr>
                                        <tr>
                                            <th class="bg-light">Account Status</th>
                                            <td>
                                                <span class="badge bg-<?= $user['status'] === 'active' ? 'success' : 'warning' ?>">
                                                    <?= ucfirst($user['status']) ?>
                                                </span>
                                                <?php if ($user['status'] === 'active'): ?>
                                                    <small class="text-muted ms-2">Good standing</small>
                                                <?php else: ?>
                                                    <small class="text-muted ms-2">Pending approval</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-4 text-center">
                                    <div class="mb-3">
                                        <i class="fas fa-user-circle fa-5x text-secondary"></i>
                                    </div>
                                    <div class="alert alert-info">
                                        <small>
                                            <i class="fas fa-info-circle me-1"></i>
                                            Your profile information is used for communication and account verification.
                                        </small>
                                    </div>
                                    <?php if ($user['status'] !== 'active'): ?>
                                    <div class="alert alert-warning">
                                        <small>
                                            <i class="fas fa-clock me-1"></i>
                                            Your account is pending admin approval.
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                    <div class="alert alert-warning">
                                        <small>
                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                            To update your information, please submit a request below.
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Update Request Form -->
                    <div class="card update-request-card">
                        <div class="card-header bg-white">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-edit me-2"></i>Request Information Update
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Note:</strong> To update your email or mobile number, please submit a request below. 
                                All changes require admin approval for security purposes.
                            </div>

                            <form method="post">
                                <input type="hidden" name="request_update" value="1">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">New Email Address *</label>
                                            <input type="email" class="form-control" name="email" 
                                                   value="<?= $user['email'] ?>" required
                                                   placeholder="Enter your new email address">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">New Mobile Number *</label>
                                            <input type="text" class="form-control" name="mobile" 
                                                   value="<?= $user['mobile'] ?>" required
                                                   placeholder="Enter your new mobile number">
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Reason for Update *</label>
                                    <textarea class="form-control" name="reason" rows="3" 
                                              placeholder="Please explain why you need to update your information..." required></textarea>
                                    <small class="form-text text-muted">
                                        Provide a clear reason for the update request (e.g., changed phone number, new email address)
                                    </small>
                                </div>

                                <div class="alert alert-info">
                                    <i class="fas fa-clock me-2"></i>
                                    Update requests are typically processed within 1-2 business days. You will receive a notification once your request is approved or rejected.
                                </div>

                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-paper-plane me-2"></i>Submit Update Request
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Pending Update Requests -->
                    <?php if (!empty($pending_requests)): ?>
                    <div class="card mt-4">
                        <div class="card-header bg-white">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-clock me-2"></i>Pending Update Requests
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Request Date</th>
                                            <th>New Email</th>
                                            <th>New Mobile</th>
                                            <th>Reason</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_requests as $request): ?>
                                            <tr>
                                                <td><?= format_date($request['created_at']) ?></td>
                                                <td><?= $request['new_email'] ?></td>
                                                <td><?= $request['new_mobile'] ?></td>
                                                <td><?= $request['reason'] ?></td>
                                                <td>
                                                    <span class="badge bg-warning">Pending Review</span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
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