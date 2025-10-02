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
$error = '';
$success = '';

// Handle contribution verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_contribution'])) {
    $contribution_id = intval($_POST['contribution_id']);
    $action = $_POST['action']; // 'confirm' or 'reject'
    
    try {
        $pdo = DB::pdo();
        
        if ($action === 'confirm') {
            $stmt = $pdo->prepare("UPDATE contributions SET status = 'confirmed' WHERE id = ?");
            $stmt->execute([$contribution_id]);
            $success = "Contribution confirmed successfully!";
        } elseif ($action === 'reject') {
            $stmt = $pdo->prepare("UPDATE contributions SET status = 'rejected' WHERE id = ?");
            $stmt->execute([$contribution_id]);
            $success = "Contribution rejected successfully!";
        }
        
    } catch (Exception $e) {
        $error = "Error updating contribution: " . $e->getMessage();
    }
}

// Get contributions with pagination and filters
$status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

try {
    $pdo = DB::pdo();
    
    // Build base query
    $query = "
        SELECT c.*, u.firstname, u.lastname, u.member_id, u.email 
        FROM contributions c 
        JOIN users u ON c.member_id = u.member_id 
        WHERE 1=1
    ";
    $countQuery = "
        SELECT COUNT(*) 
        FROM contributions c 
        JOIN users u ON c.member_id = u.member_id 
        WHERE 1=1
    ";
    $params = [];
    $countParams = [];

    // Add status filter
    if ($status !== 'all') {
        $query .= " AND c.status = ?";
        $countQuery .= " AND c.status = ?";
        $params[] = $status;
        $countParams[] = $status;
    }

    // Add search filter
    if (!empty($search)) {
        $searchTerm = "%$search%";
        $query .= " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.member_id LIKE ? OR c.amount LIKE ?)";
        $countQuery .= " AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.member_id LIKE ? OR c.amount LIKE ?)";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        $countParams = array_merge($countParams, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }

    // Add ordering and pagination
    $query .= " ORDER BY c.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    // Get contributions
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $contributions = $stmt->fetchAll();

    // Get total count for pagination
    $count_stmt = $pdo->prepare($countQuery);
    $count_stmt->execute($countParams);
    $total_contributions = $count_stmt->fetchColumn();
    $total_pages = ceil($total_contributions / $limit);

    // Get counts for status badges
    $all_count = $pdo->query("SELECT COUNT(*) FROM contributions")->fetchColumn();
    $pending_count = $pdo->query("SELECT COUNT(*) FROM contributions WHERE status = 'pending'")->fetchColumn();
    $confirmed_count = $pdo->query("SELECT COUNT(*) FROM contributions WHERE status = 'confirmed'")->fetchColumn();
    $rejected_count = $pdo->query("SELECT COUNT(*) FROM contributions WHERE status = 'rejected'")->fetchColumn();

} catch (Exception $e) {
    $error = "Error loading contributions: " . $e->getMessage();
    $contributions = [];
    $total_pages = 0;
    $all_count = $pending_count = $confirmed_count = $rejected_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Contributions - iBarako Loan System</title>
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
    .status-badge {
        cursor: pointer;
        transition: all 0.3s;
    }
    .status-badge:hover {
        transform: translateY(-1px);
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
                    <a class="nav-link active" href="contributions.php">
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
                        <h4 class="mb-0 text-dark">Manage Contributions</h4>
                    </div>
                </nav>

                <div class="container-fluid mt-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= $success ?></div>
                    <?php endif; ?>

                    <!-- Status Filter -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <div class="d-flex flex-wrap gap-2">
                                        <a href="?status=all" class="btn btn-outline-primary status-badge <?= $status === 'all' ? 'active' : '' ?>">
                                            All <span class="badge bg-primary ms-1"><?= $all_count ?></span>
                                        </a>
                                        <a href="?status=pending" class="btn btn-outline-warning status-badge <?= $status === 'pending' ? 'active' : '' ?>">
                                            Pending <span class="badge bg-warning ms-1"><?= $pending_count ?></span>
                                        </a>
                                        <a href="?status=confirmed" class="btn btn-outline-success status-badge <?= $status === 'confirmed' ? 'active' : '' ?>">
                                            Confirmed <span class="badge bg-success ms-1"><?= $confirmed_count ?></span>
                                        </a>
                                        <a href="?status=rejected" class="btn btn-outline-danger status-badge <?= $status === 'rejected' ? 'active' : '' ?>">
                                            Rejected <span class="badge bg-danger ms-1"><?= $rejected_count ?></span>
                                        </a>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <form method="GET" class="d-flex">
                                        <input type="hidden" name="status" value="<?= $status ?>">
                                        <input type="text" class="form-control" name="search" placeholder="Search contributions..." value="<?= htmlspecialchars($search) ?>">
                                        <button type="submit" class="btn btn-primary ms-2">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contributions Table -->
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2"></i>Contributions
                                <?php if ($status === 'pending'): ?>
                                    <span class="badge bg-warning"><?= $pending_count ?> Pending Verification</span>
                                <?php endif; ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($contributions)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No contributions found.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Member</th>
                                                <th>Amount</th>
                                                <th>Payment Method</th>
                                                <th>Date</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($contributions as $contribution): ?>
                                            <tr>
                                                <td>CN-<?= str_pad($contribution['id'], 4, '0', STR_PAD_LEFT) ?></td>
                                                <td>
                                                    <strong><?= htmlspecialchars($contribution['firstname']) ?> <?= htmlspecialchars($contribution['lastname']) ?></strong><br>
                                                    <small class="text-muted">ID: <?= $contribution['member_id'] ?></small>
                                                </td>
                                                <td>₱<?= number_format($contribution['amount'], 2) ?></td>
                                                <td><?= ucfirst($contribution['payment_method']) ?></td>
                                                <td><?= date('M j, Y g:i A', strtotime($contribution['created_at'])) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $contribution['status'] === 'confirmed' ? 'success' : 
                                                        ($contribution['status'] === 'pending' ? 'warning' : 'danger') 
                                                    ?>">
                                                        <?= ucfirst($contribution['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($contribution['status'] === 'pending'): ?>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="contribution_id" value="<?= $contribution['id'] ?>">
                                                            <input type="hidden" name="action" value="confirm">
                                                            <button type="submit" name="verify_contribution" class="btn btn-sm btn-success" onclick="return confirm('Confirm this contribution?')">
                                                                <i class="fas fa-check"></i> Confirm
                                                            </button>
                                                        </form>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="contribution_id" value="<?= $contribution['id'] ?>">
                                                            <input type="hidden" name="action" value="reject">
                                                            <button type="submit" name="verify_contribution" class="btn btn-sm btn-danger" onclick="return confirm('Reject this contribution?')">
                                                                <i class="fas fa-times"></i> Reject
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-muted">Verified</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                <div class="d-flex justify-content-center mt-4">
                                    <nav aria-label="Contributions pagination">
                                        <ul class="pagination">
                                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a class="page-link" href="?page=<?= $page - 1 ?>&status=<?= $status ?>&search=<?= urlencode($search) ?>">Previous</a>
                                            </li>
                                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                                    <a class="page-link" href="?page=<?= $i ?>&status=<?= $status ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                                <a class="page-link" href="?page=<?= $page + 1 ?>&status=<?= $status ?>&search=<?= urlencode($search) ?>">Next</a>
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
</body>
</html>