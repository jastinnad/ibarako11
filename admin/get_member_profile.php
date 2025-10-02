<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$member_id = $_GET['member_id'] ?? '';

if (empty($member_id)) {
    echo '<div class="alert alert-danger">Member ID is required.</div>';
    exit;
}

try {
    $pdo = DB::pdo();
    
    // Get member details
    $stmt = $pdo->prepare("SELECT * FROM users WHERE member_id = ?");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$member) {
        echo '<div class="alert alert-danger">Member not found.</div>';
        exit;
    }
    
    // Get member's active loans with payment progress
    $loanData = getMemberLoansWithProgress($member_id);
    $activeLoans = $loanData['loans'];
    $loanPayments = $loanData['payments'];
    
    // Get total contributions
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total_contributions FROM contributions WHERE member_id = ? AND status = 'confirmed'");
    $stmt->execute([$member_id]);
    $contributions = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_contributions = $contributions['total_contributions'];
    
    ?>
    
    <div class="row">
        <div class="col-md-6">
            <h6>Personal Information</h6>
            <table class="table table-sm">
                <tr>
                    <td><strong>Name:</strong></td>
                    <td><?= $member['firstname'] . ' ' . $member['lastname'] ?></td>
                </tr>
                <tr>
                    <td><strong>Birthday:</strong></td>
                    <td><?= date('M j, Y', strtotime($member['birthday'])) ?> (Age: <?= calculateAge($member['birthday']) ?>)</td>
                </tr>
                <tr>
                    <td><strong>Email:</strong></td>
                    <td><?= $member['email'] ?></td>
                </tr>
                <tr>
                    <td><strong>Mobile:</strong></td>
                    <td><?= $member['mobile'] ?></td>
                </tr>
            </table>
        </div>
        
        <div class="col-md-6">
            <h6>Account Status</h6>
            <table class="table table-sm">
                <tr>
                    <td><strong>Status:</strong></td>
                    <td>
                        <span class="badge bg-<?= $member['status'] === 'active' ? 'success' : ($member['status'] === 'pending' ? 'warning' : 'danger') ?>">
                            <?= ucfirst($member['status']) ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td><strong>Member Since:</strong></td>
                    <td><?= date('M j, Y', strtotime($member['created_at'])) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Active Loans with Progress -->
    <div class="card mt-4">
        <div class="card-header bg-primary text-white">
            <h6 class="mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>Active Loans</h6>
        </div>
        <div class="card-body">
            <?php if (!empty($activeLoans)): ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Loan ID</th>
                                <th>Loan Amount</th>
                                <th>Remaining Balance</th>
                                <th>Interest Rate</th>
                                <th>Due Date</th>
                                <th>Payment Progress</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeLoans as $loan): ?>
                                <?php
                                // Calculate payment progress
                                $totalPayments = 6; // 3 months × 2 payments per month
                                $paidPayments = 0;
                                $paymentProgress = "0/{$totalPayments}";
                                
                                // Get payment count for this loan
                                if (isset($loanPayments[$loan['id']]) && !empty($loanPayments[$loan['id']])) {
                                    $paidPayments = count($loanPayments[$loan['id']]);
                                    $paymentProgress = "{$paidPayments}/{$totalPayments}";
                                }
                                
                                // Calculate progress percentage
                                $progressPercentage = ($paidPayments / $totalPayments) * 100;
                                ?>
                                <tr>
                                    <td><strong><?= $loan['loan_id'] ?? 'LN-' . $loan['id'] ?></strong></td>
                                    <td>₱<?= number_format($loan['principal'] ?? $loan['loan_amount'] ?? 0, 2) ?></td>
                                    <td>₱<?= number_format($loan['remaining_balance'] ?? $loan['balance'] ?? 0, 2) ?></td>
                                    <td><?= $loan['interest_rate'] ?? '0' ?>%</td>
                                    <td><?= date('M j, Y', strtotime($loan['due_date'] ?? $loan['end_date'] ?? 'N/A')) ?></td>
                                    <td>
                                        <div class="loan-progress">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <small class="text-muted"><?= $paymentProgress ?></small>
                                                <small class="text-muted"><?= number_format($progressPercentage, 1) ?>%</small>
                                            </div>
                                            <div class="progress" style="height: 8px;">
                                                <div class="progress-bar 
                                                    <?= $progressPercentage >= 100 ? 'bg-success' : 
                                                       ($progressPercentage >= 75 ? 'bg-primary' : 
                                                       ($progressPercentage >= 50 ? 'bg-warning' : 
                                                       ($progressPercentage >= 25 ? 'bg-info' : 'bg-secondary'))) ?>" 
                                                    role="progressbar" 
                                                    style="width: <?= min($progressPercentage, 100) ?>%"
                                                    aria-valuenow="<?= $progressPercentage ?>" 
                                                    aria-valuemin="0" 
                                                    aria-valuemax="100">
                                                </div>
                                            </div>
                                            <div class="mt-1">
                                                <small class="text-muted">
                                                    <?php if ($paidPayments > 0): ?>
                                                        <?= $paidPayments ?> of <?= $totalPayments ?> payments made
                                                        <?php if (isset($loanPayments[$loan['id']])): ?>
                                                            <br><small>Last payment: <?= date('M j, Y', strtotime(end($loanPayments[$loan['id']])['payment_date'])) ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        No payments yet
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            ($loan['status'] === 'approved' || $loan['status'] === 'active') ? 'success' : 
                                            ($loan['status'] === 'pending' ? 'warning' : 
                                            ($loan['status'] === 'rejected' ? 'danger' : 'secondary')) 
                                        ?>">
                                            <?= ucfirst($loan['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted text-center py-3">No active loans found.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Employment Information -->
    <div class="card mt-4">
        <div class="card-header bg-info text-white">
            <h6 class="mb-0"><i class="fas fa-briefcase me-2"></i>Employment</h6>
        </div>
        <div class="card-body">
            <table class="table table-sm">
                <tr>
                    <td><strong>Company:</strong></td>
                    <td><?= $member['company_name'] ?></td>
                </tr>
                <tr>
                    <td><strong>Position:</strong></td>
                    <td><?= $member['nature_of_work'] ?></td>
                </tr>
                <tr>
                    <td><strong>Salary:</strong></td>
                    <td>₱<?= number_format($member['salary'], 2) ?></td>
                </tr>
                <tr>
                    <td><strong>Date Employed:</strong></td>
                    <td><?= date('M j, Y', strtotime($member['date_employed'])) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Financial Summary -->
    <div class="card mt-4">
        <div class="card-header bg-success text-white">
            <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Financial Summary</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <strong>Total Contributions:</strong> ₱<?= number_format($total_contributions, 2) ?>
                </div>
                <div class="col-md-6">
                    <strong>Active Loans:</strong> <?= count($activeLoans) ?>
                </div>
            </div>
        </div>
    </div>

    <?php
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error loading profile: ' . $e->getMessage() . '</div>';
}

// Function to calculate age from birthday
function calculateAge($birthday) {
    if (empty($birthday)) return '';
    
    $birthday_date = DateTime::createFromFormat('Y-m-d', $birthday);
    $today = new DateTime();
    return $today->diff($birthday_date)->y;
}

// Function to get member loans with payment progress - CORRECTED VERSION
function getMemberLoansWithProgress($memberId) {
    try {
        $pdo = DB::pdo();
        
        // Get loans for this member - try different possible column names
        $loans = [];
        
        // Try member_id column in loans table
        try {
            $stmt = $pdo->prepare("SELECT * FROM loans WHERE member_id = ? AND status IN ('approved', 'active', 'completed')");
            $stmt->execute([$memberId]);
            $loans = array_merge($loans, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            // Column might not exist, continue to next try
        }
        
        // Try user_id column in loans table (where user_id is actually the member_id)
        try {
            $stmt = $pdo->prepare("SELECT * FROM loans WHERE user_id = ? AND status IN ('approved', 'active', 'completed')");
            $stmt->execute([$memberId]);
            $loans = array_merge($loans, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            // Column might not exist, continue
        }
        
        // If still no loans, try to find any loans for this member
        if (empty($loans)) {
            $stmt = $pdo->prepare("SELECT * FROM loans WHERE status IN ('approved', 'active', 'completed')");
            $stmt->execute();
            $all_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Filter loans that might be linked to this member through other means
            foreach ($all_loans as $loan) {
                if (isset($loan['member_id']) && $loan['member_id'] == $memberId) {
                    $loans[] = $loan;
                } elseif (isset($loan['user_id']) && $loan['user_id'] == $memberId) {
                    $loans[] = $loan;
                }
            }
        }
        
        // Get payments for each loan
        $loanPayments = [];
        foreach ($loans as $loan) {
            // Try to get payments from loan_payments table
            try {
                $stmt = $pdo->prepare("SELECT * FROM loan_payments WHERE loan_id = ? ORDER BY payment_date ASC");
                $stmt->execute([$loan['id']]);
                $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $loanPayments[$loan['id']] = $payments;
            } catch (Exception $e) {
                // Table might not exist or error
                $loanPayments[$loan['id']] = [];
            }
        }
        
        return [
            'loans' => $loans,
            'payments' => $loanPayments
        ];
        
    } catch (Exception $e) {
        error_log("Error fetching loans with progress: " . $e->getMessage());
        return ['loans' => [], 'payments' => []];
    }
}
?>