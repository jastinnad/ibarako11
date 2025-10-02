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
$error = '';
$success = '';

// Handle interest rate change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_interest_rate'])) {
    $new_rate = floatval($_POST['interest_rate']);
    $effective_date = $_POST['effective_date'];
    
    if ($new_rate > 0 && $new_rate <= 10) {
        try {
            $pdo = DB::pdo();
            
            // Get current rate
            $current_rate = LoanSystem::get_interest_rate();
            
            // Insert rate change with effective date
            $stmt = $pdo->prepare("
                INSERT INTO interest_rate_changes (old_rate, new_rate, effective_date, changed_by) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$current_rate, $new_rate, $effective_date, $user['id']]);
            
            // Update current rate in settings
            LoanSystem::update_interest_rate($new_rate);
            
            $success = "Interest rate updated successfully! New rate will be effective from " . date('F j, Y', strtotime($effective_date));
            
        } catch (Exception $e) {
            $error = "Error updating interest rate: " . $e->getMessage();
        }
    } else {
        $error = "Please enter a valid interest rate (0.1% to 10%)";
    }
}

// Handle payment gateway updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment_gateways'])) {
    try {
        $pdo = DB::pdo();
        
        // Update GCash numbers
        foreach ($_POST['gcash'] as $id => $data) {
            if (!empty($data['number']) && !empty($data['name'])) {
                if ($id == 'new') {
                    // Insert new GCash number
                    $stmt = $pdo->prepare("INSERT INTO payment_gateways (type, account_number, account_name, is_active) VALUES ('gcash', ?, ?, 1)");
                    $stmt->execute([$data['number'], $data['name']]);
                } else {
                    // Update existing GCash number
                    $stmt = $pdo->prepare("UPDATE payment_gateways SET account_number = ?, account_name = ? WHERE id = ?");
                    $stmt->execute([$data['number'], $data['name'], $id]);
                }
            }
        }
        
        // Update bank accounts
        foreach ($_POST['bank'] as $id => $data) {
            if (!empty($data['number']) && !empty($data['name']) && !empty($data['bank_name'])) {
                if ($id == 'new') {
                    // Insert new bank account
                    $stmt = $pdo->prepare("INSERT INTO payment_gateways (type, account_number, account_name, bank_name, is_active) VALUES ('bank', ?, ?, ?, 1)");
                    $stmt->execute([$data['number'], $data['name'], $data['bank_name']]);
                } else {
                    // Update existing bank account
                    $stmt = $pdo->prepare("UPDATE payment_gateways SET account_number = ?, account_name = ?, bank_name = ? WHERE id = ?");
                    $stmt->execute([$data['number'], $data['name'], $data['bank_name'], $id]);
                }
            }
        }
        
        // Handle deletions
        if (isset($_POST['delete_gateways'])) {
            foreach ($_POST['delete_gateways'] as $id) {
                $stmt = $pdo->prepare("DELETE FROM payment_gateways WHERE id = ?");
                $stmt->execute([$id]);
            }
        }
        
        $success = "Payment gateways updated successfully!";
        
    } catch (Exception $e) {
        $error = "Error updating payment gateways: " . $e->getMessage();
    }
}

$current_rate = LoanSystem::get_interest_rate();
$next_month = date('Y-m-d', strtotime('+1 month'));

// Get payment gateways
try {
    $pdo = DB::pdo();
    $stmt = $pdo->query("SELECT * FROM payment_gateways ORDER BY type, id");
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - iBarako Loan System</title>
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
        .gateway-item {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            background: #f8f9fa;
        }
        .gateway-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .delete-checkbox {
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
                    <a class="nav-link" href="update_requests.php">
                        <i class="fas fa-edit"></i>Update Requests
                    </a>
                    <a class="nav-link active" href="settings.php">
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
                        <h4 class="mt-2 mb-2 text-dark">System Settings</h4>
                    </div>
                </nav>

                <div class="container-fluid mt-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= $success ?></div>
                    <?php endif; ?>

                    <div class="row">
                        <!-- Interest Rate Settings -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header" style="background-color: #3b82f6; color: white;">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-percentage me-2"></i>Interest Rate Settings
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <div class="mb-3">
                                            <label class="form-label">Current Interest Rate</label>
                                            <div class="form-control bg-light">
                                                <strong><?= $current_rate ?>% per month</strong>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">New Interest Rate (%)</label>
                                            <input type="number" class="form-control" name="interest_rate" 
                                                   value="<?= $current_rate ?>" step="0.01" min="0.1" max="10" required>
                                            <small class="text-muted">Enter new monthly interest rate (0.1% to 10%)</small>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Effective Date</label>
                                            <input type="date" class="form-control" name="effective_date" 
                                                   value="<?= $next_month ?>" min="<?= $next_month ?>" required>
                                            <small class="text-muted">Rate change will be effective from this date</small>
                                        </div>
                                        
                                        <button type="submit" name="update_interest_rate" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Update Interest Rate
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Rate Change History -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header" style="background-color: #1e3a8a; color: white;">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-history me-2"></i>Rate Change History
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php
                                    try {
                                        $pdo = DB::pdo();
                                        $stmt = $pdo->query("
                                            SELECT irc.*, u.firstname, u.lastname 
                                            FROM interest_rate_changes irc 
                                            LEFT JOIN users u ON irc.changed_by = u.id 
                                            ORDER BY irc.changed_at DESC 
                                            LIMIT 5
                                        ");
                                        $changes = $stmt->fetchAll();
                                        
                                        if (empty($changes)) {
                                            echo "<p class='text-muted'>No rate changes recorded.</p>";
                                        } else {
                                            foreach ($changes as $change) {
                                                echo "<div class='border-bottom pb-2 mb-2'>";
                                                echo "<div class='d-flex justify-content-between'>";
                                                echo "<strong>{$change['old_rate']}% → {$change['new_rate']}%</strong>";
                                                echo "<small class='text-muted'>" . date('M j, Y', strtotime($change['effective_date'])) . "</small>";
                                                echo "</div>";
                                                echo "<small>By: {$change['firstname']} {$change['lastname']}</small>";
                                                echo "</div>";
                                            }
                                        }
                                    } catch (Exception $e) {
                                        echo "<p class='text-muted'>Unable to load rate history.</p>";
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Gateway Settings -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header" style="background-color: #10b981; color: white;">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-credit-card me-2"></i>Payment Gateway Settings
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <div class="row">
                                            <!-- GCash Accounts -->
                                            <div class="col-md-6">
                                                <h6 class="text-success mb-3">
                                                    <i class="fas fa-mobile-alt me-2"></i>GCash Accounts
                                                </h6>
                                                
                                                <?php if (empty($gcash_accounts)): ?>
                                                    <div class="gateway-item">
                                                        <div class="gateway-header">
                                                            <strong>Primary GCash Account</strong>
                                                            <input type="checkbox" class="delete-checkbox" name="delete_gateways[]" value="new" disabled>
                                                        </div>
                                                        <div class="mb-2">
                                                            <label class="form-label small">Account Number</label>
                                                            <input type="text" class="form-control form-control-sm" name="gcash[new][number]" placeholder="09XXXXXXXXX" value="09171234567">
                                                        </div>
                                                        <div class="mb-2">
                                                            <label class="form-label small">Account Name</label>
                                                            <input type="text" class="form-control form-control-sm" name="gcash[new][name]" placeholder="Account Holder Name" value="iBarako Cooperative">
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <?php foreach ($gcash_accounts as $gcash): ?>
                                                        <div class="gateway-item">
                                                            <div class="gateway-header">
                                                                <strong>GCash Account</strong>
                                                                <div>
                                                                    <input type="checkbox" class="delete-checkbox" name="delete_gateways[]" value="<?= $gcash['id'] ?>">
                                                                    <small class="text-danger">Delete</small>
                                                                </div>
                                                            </div>
                                                            <div class="mb-2">
                                                                <label class="form-label small">Account Number</label>
                                                                <input type="text" class="form-control form-control-sm" name="gcash[<?= $gcash['id'] ?>][number]" value="<?= htmlspecialchars($gcash['account_number']) ?>" placeholder="09XXXXXXXXX">
                                                            </div>
                                                            <div class="mb-2">
                                                                <label class="form-label small">Account Name</label>
                                                                <input type="text" class="form-control form-control-sm" name="gcash[<?= $gcash['id'] ?>][name]" value="<?= htmlspecialchars($gcash['account_name']) ?>" placeholder="Account Holder Name">
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                
                                                <!-- Add new GCash account -->
                                                <div class="gateway-item" style="border-style: dashed;">
                                                    <div class="gateway-header">
                                                        <strong class="text-muted">Add New GCash Account</strong>
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label small">Account Number</label>
                                                        <input type="text" class="form-control form-control-sm" name="gcash[new][number]" placeholder="09XXXXXXXXX">
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label small">Account Name</label>
                                                        <input type="text" class="form-control form-control-sm" name="gcash[new][name]" placeholder="Account Holder Name">
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Bank Accounts -->
                                            <div class="col-md-6">
                                                <h6 class="text-primary mb-3">
                                                    <i class="fas fa-university me-2"></i>Bank Accounts
                                                </h6>
                                                
                                                <?php if (empty($bank_accounts)): ?>
                                                    <div class="gateway-item">
                                                        <div class="gateway-header">
                                                            <strong>Primary Bank Account</strong>
                                                            <input type="checkbox" class="delete-checkbox" name="delete_gateways[]" value="new" disabled>
                                                        </div>
                                                        <div class="mb-2">
                                                            <label class="form-label small">Bank Name</label>
                                                            <input type="text" class="form-control form-control-sm" name="bank[new][bank_name]" placeholder="Bank Name" value="BPI (Bank of the Philippine Islands)">
                                                        </div>
                                                        <div class="mb-2">
                                                            <label class="form-label small">Account Number</label>
                                                            <input type="text" class="form-control form-control-sm" name="bank[new][number]" placeholder="Account Number" value="1234-5678-90">
                                                        </div>
                                                        <div class="mb-2">
                                                            <label class="form-label small">Account Name</label>
                                                            <input type="text" class="form-control form-control-sm" name="bank[new][name]" placeholder="Account Holder Name" value="iBarako Cooperative">
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <?php foreach ($bank_accounts as $bank): ?>
                                                        <div class="gateway-item">
                                                            <div class="gateway-header">
                                                                <strong>Bank Account</strong>
                                                                <div>
                                                                    <input type="checkbox" class="delete-checkbox" name="delete_gateways[]" value="<?= $bank['id'] ?>">
                                                                    <small class="text-danger">Delete</small>
                                                                </div>
                                                            </div>
                                                            <div class="mb-2">
                                                                <label class="form-label small">Bank Name</label>
                                                                <input type="text" class="form-control form-control-sm" name="bank[<?= $bank['id'] ?>][bank_name]" value="<?= htmlspecialchars($bank['bank_name']) ?>" placeholder="Bank Name">
                                                            </div>
                                                            <div class="mb-2">
                                                                <label class="form-label small">Account Number</label>
                                                                <input type="text" class="form-control form-control-sm" name="bank[<?= $bank['id'] ?>][number]" value="<?= htmlspecialchars($bank['account_number']) ?>" placeholder="Account Number">
                                                            </div>
                                                            <div class="mb-2">
                                                                <label class="form-label small">Account Name</label>
                                                                <input type="text" class="form-control form-control-sm" name="bank[<?= $bank['id'] ?>][name]" value="<?= htmlspecialchars($bank['account_name']) ?>" placeholder="Account Holder Name">
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                
                                                <!-- Add new bank account -->
                                                <div class="gateway-item" style="border-style: dashed;">
                                                    <div class="gateway-header">
                                                        <strong class="text-muted">Add New Bank Account</strong>
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label small">Bank Name</label>
                                                        <input type="text" class="form-control form-control-sm" name="bank[new][bank_name]" placeholder="Bank Name">
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label small">Account Number</label>
                                                        <input type="text" class="form-control form-control-sm" name="bank[new][number]" placeholder="Account Number">
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label small">Account Name</label>
                                                        <input type="text" class="form-control form-control-sm" name="bank[new][name]" placeholder="Account Holder Name">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mt-4">
                                            <button type="submit" name="update_payment_gateways" class="btn btn-success">
                                                <i class="fas fa-save me-2"></i>Update Payment Gateways
                                            </button>
                                            <small class="text-muted ms-2">Members will see these accounts for sending payments</small>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>