<?php
session_start();
require_once '../db.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$user = $_SESSION['user'];

// Handle update request actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id = intval($_POST['request_id']);
    $action = $_POST['action'];
    
    try {
        $pdo = DB::pdo();
        
        // Get the request details
        $stmt = $pdo->prepare("SELECT * FROM update_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();
        
        if ($request) {
            if ($action === 'approve') {
                // Update user information
                $update_fields = [];
                $update_values = [];
                
                if (!empty($request['new_email'])) {
                    $update_fields[] = 'email = ?';
                    $update_values[] = $request['new_email'];
                }
                
                if (!empty($request['new_mobile'])) {
                    $update_fields[] = 'mobile = ?';
                    $update_values[] = $request['new_mobile'];
                }
                
                if (!empty($update_fields)) {
                    $update_values[] = $request['user_id'];
                    $stmt = $pdo->prepare("
                        UPDATE users SET " . implode(', ', $update_fields) . " WHERE member_id = ?
                    ");
                    $stmt->execute($update_values);
                }
                
                // Mark request as approved
                $stmt = $pdo->prepare("
                    UPDATE update_requests SET status = 'approved', processed_by = ?, processed_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$user['member_id'], $request_id]);
                
            } elseif ($action === 'reject') {
                // Mark request as rejected
                $stmt = $pdo->prepare("
                    UPDATE update_requests SET status = 'rejected', processed_by = ?, processed_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$user['member_id'], $request_id]);
            }
        }
    } catch (Exception $e) {
        error_log("Update request error: " . $e->getMessage());
    }
}

// Get all pending update requests - FIXED: Use member_id instead of id
$pdo = DB::pdo();
$requests = $pdo->query("
    SELECT ur.*, u.member_id, u.firstname, u.lastname 
    FROM update_requests ur 
    JOIN users u ON ur.user_id = u.member_id 
    WHERE ur.status = 'pending' 
    ORDER BY ur.created_at DESC
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Requests - iBarako Loan System</title>
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
		.alert {
			font-size: 0.8rem; 
			padding: 0.5rem 1rem; 
		}
		.alert strong {
			font-size: 0.9rem;
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
                    <a class="nav-link" href="payments.php">
                        <i class="fas fa-money-check"></i>Payment Verification
                    </a>
					<a class="nav-link" href="contributions.php">
                        <i class="fas fa-chart-line"></i>Contributions
                    </a>
                    <a class="nav-link active" href="update_requests.php">
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
                        <h4 class="mt-2 mb-2 text-dark">Member Update Requests</h4>
                        <span class="badge bg-primary"><?= count($requests) ?> Pending</span>
                    </div>
                </nav>

                <div class="container-fluid mt-3">
                    <?php if (empty($requests)): ?>
                        <div class="alert alert-info py-2 px-3">
                            <i class="fas fa-info-circle me-2"></i>
                            No pending update requests.
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($requests as $request): ?>
                            <div class="col-md-6 mb-4">
                                <div class="card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h6 class="mb-0">Request from <?= $request['firstname'] ?> <?= $request['lastname'] ?></h6>
                                        <small class="text-muted"><?= date('M j, Y', strtotime($request['created_at'])) ?></small>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <small class="text-muted">Member ID:</small>
                                            <div><strong><?= $request['member_id'] ?></strong></div>
                                        </div>
                                        
                                        <?php if (!empty($request['new_email'])): ?>
                                        <div class="mb-3">
                                            <small class="text-muted">Email Change:</small>
                                            <div>
                                                <del><?= $request['current_email'] ?></del> 
                                                → <strong><?= $request['new_email'] ?></strong>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($request['new_mobile'])): ?>
                                        <div class="mb-3">
                                            <small class="text-muted">Mobile Change:</small>
                                            <div>
                                                <del><?= $request['current_mobile'] ?></del> 
                                                → <strong><?= $request['new_mobile'] ?></strong>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="mb-3">
                                            <small class="text-muted">Reason:</small>
                                            <div><?= nl2br(htmlspecialchars($request['reason'])) ?></div>
                                        </div>
                                        
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn btn-success btn-sm">
                                                <i class="fas fa-check me-1"></i>Approve
                                            </button>
                                        </form>
                                        
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                <i class="fas fa-times me-1"></i>Reject
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>