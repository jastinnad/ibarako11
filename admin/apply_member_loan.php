<?php
session_start();
require_once '../db.php';
require_once '../notifications.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$admin_user = $_SESSION['user'];
$error = '';
$success = '';

// Get member details if member_id is provided
$member = null;
if (isset($_GET['member_id'])) {
    try {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE member_id = ? AND role = 'member'");
        $stmt->execute([$_GET['member_id']]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$member) {
            $error = "Member not found.";
        }
    } catch (Exception $e) {
        $error = "Error loading member details: " . $e->getMessage();
    }
}

// Handle loan application form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_loan'])) {
    $member_id = $_POST['member_id'];
    $principal = floatval($_POST['principal']);
    $term_months = intval($_POST['term_months']);
    $purpose = trim($_POST['purpose']);
    $payment_method = $_POST['payment_method'];
    $account_details = trim($_POST['account_details']);
    $account_name = trim($_POST['account_name']);
    
    // Validate inputs
    if ($principal < 1000 || $principal > 50000) {
        $error = "Loan amount must be between ₱1,000 and ₱50,000";
    } elseif (!in_array($term_months, [3, 6, 9, 12])) {
        $error = "Please select a valid loan term";
    } elseif (empty($purpose)) {
        $error = "Please enter loan purpose";
    } elseif (empty($payment_method)) {
        $error = "Please select payment method";
    } elseif (empty($account_details) || empty($account_name)) {
        $error = "Please fill in all required account details";
    } else {
        try {
            $pdo = DB::pdo();
            
            // Verify member exists
            $stmt = $pdo->prepare("SELECT member_id FROM users WHERE member_id = ? AND role = 'member'");
            $stmt->execute([$member_id]);
            $member_exists = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$member_exists) {
                $error = "Member not found.";
            } else {
                // Calculate interest and monthly payment based on 2% monthly
                $interest_rate = 2.0; // 2% monthly interest
                $total_interest = $principal * ($interest_rate / 100) * $term_months;
                $total_amount = $principal + $total_interest;
                $monthly_payment = $total_amount / $term_months;
                
                // Generate loan number
                $loan_number = 'LN-' . date('YmdHis') . '-' . $member_id;
                
                // Insert loan application
                $stmt = $pdo->prepare("
                    INSERT INTO loans (user_id, loan_number, principal, term_months, interest_rate, 
                                     monthly_payment, total_amount, loan_type, purpose, 
                                     payment_method, account_details, account_name, status, applied_by_admin) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Personal Loan', ?, ?, ?, ?, 'approved', ?)
                ");

                $stmt->execute([
                    $member_id, $loan_number, $principal, $term_months, $interest_rate,
                    $monthly_payment, $total_amount, $purpose, $payment_method, 
                    $account_details, $account_name, $admin_user['member_id']
                ]);
                
                $loan_id = $pdo->lastInsertId();
                
                // Auto-approve since admin is applying
                $stmt = $pdo->prepare("UPDATE loans SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute([$admin_user['member_id'], $loan_id]);
                
                // Notify member about the loan application
                Notifications::notifyUser(
                    $member_id,
                    'loan_application',
                    'New Loan Application',
                    'A loan application for ₱' . number_format($principal, 2) . ' has been submitted on your behalf and has been approved.',
                    $loan_id,
                    $loan_id
                );
                
                $success = "Loan application submitted and approved successfully for member!";
                
                // Redirect to avoid form resubmission
                header("Location: apply_member_loan.php?member_id=$member_id&success=1&loan_id=$loan_id");
                exit;
            }
            
        } catch (Exception $e) {
            $error = "Error submitting loan application: " . $e->getMessage();
        }
    }
}

// Check for success message from redirect
if (isset($_GET['success'])) {
    $loan_id = $_GET['loan_id'] ?? '';
    $success = "Loan application submitted and approved successfully for member!";
    
    // Add download link if loan_id is available
    if ($loan_id) {
        $success = '<div class="d-flex align-items-center">' . 
                   $success . 
                   '<a href="../member/loan_agreement.php?loan_id=' . $loan_id . '&action=view" target="_blank" class="btn btn-sm btn-outline-primary ms-3">' .
                   '<i class="fas fa-file-pdf me-1"></i>View Loan Agreement</a>' .
                   '</div>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply Member Loan - iBarako Loan System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
		/* Import modern fonts */
		@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
		body {
			font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
		}
        .sidebar {
            background: linear-gradient(180deg, #1e3a8a, #2563eb);
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
        .loan-summary {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 0.5rem;
            padding: 1.5rem;
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
        .modal-backdrop {
            background-color: rgba(0,0,0,0.5);
        }
        .account-details-card {
            border-left: 4px solid #0d6efd;
        }
        .form-required::after {
            content: " *";
            color: #dc3545;
        }
        .btn:disabled {
            cursor: not-allowed;
        }
        .preview-agreement-btn {
            display: none;
        }
        .bank-account-input {
            max-width: 200px;
        }
        .gcash-input-group {
            position: relative;
        }
        .gcash-prefix {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            z-index: 3;
            font-weight: 500;
        }
        .gcash-number-input {
            padding-left: 45px;
        }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Sidebar Navigation -->
            <div class="sidebar">
                <div class="sidebar-brand text-white">
                    <h5 class="mb-1"><i class="fas fa-hand-holding-usd"></i> iBarako</h5>
                    <small style="color: #BDBDC7;">Admin Panel</small>
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
                        <h4 class="mt-2 mb-2 text-dark">Apply Loan for Member</h4>
                        <span class="badge" style="background-color: #1e3a8a; color: white;">Admin</span>
                    </div>
                </nav>

                <div class="container-fluid mt-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= $success ?></div>
                    <?php endif; ?>

                    <?php if ($member): ?>
                        <!-- Member Information -->
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-user me-2"></i>Member Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-bordered">
                                            <tr><th>Member ID</th><td><?= $member['member_id'] ?></td></tr>
                                            <tr><th>Name</th><td><?= $member['firstname'] . ' ' . $member['lastname'] ?></td></tr>
                                            <tr><th>Email</th><td><?= $member['email'] ?></td></tr>
                                            <tr><th>Mobile</th><td><?= $member['mobile'] ?? 'N/A' ?></td></tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong>Note:</strong> Loans applied by admins are automatically approved.<br>
                                            The member will be notified about this loan application.
                                        </div>
                                        
                                        <!-- Loan Agreement Download Button (shown after successful application) -->
                                        <?php if (isset($_GET['loan_id']) && is_numeric($_GET['loan_id'])): ?>
                                        <div class="mt-3">
                                            <a href="../member/loan_agreement.php?loan_id=<?= $_GET['loan_id'] ?>&action=view" target="_blank" class="btn btn-primary btn-sm">
                                                <i class="fas fa-file-contract me-2"></i>View Loan Agreement
                                            </a>
                                            <a href="../member/loan_agreement.php?loan_id=<?= $_GET['loan_id'] ?>&action=print" target="_blank" class="btn btn-success btn-sm">
                                                <i class="fas fa-print me-2"></i>Print Agreement
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Loan Application Form -->
                        <div class="card loan-details-card">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>Loan Application</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="loanForm">
                                    <input type="hidden" name="apply_loan" value="1">
                                    <input type="hidden" name="member_id" value="<?= $member['member_id'] ?>">
                                    <input type="hidden" name="account_details" id="accountDetailsInput">
                                    <input type="hidden" name="account_name" id="accountNameInput">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label form-required">Loan Amount (₱)</label>
                                                <input type="number" class="form-control" name="principal" id="principal" min="1000" max="50000" required 
                                                       onchange="calculateLoanDetails(); checkFormCompletion(); updatePreviewAgreement();">
                                                <small class="text-muted">Minimum: ₱1,000 | Maximum: ₱50,000</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label form-required">Loan Term</label>
                                                <select class="form-control" name="term_months" id="term_months" required onchange="calculateLoanDetails(); checkFormCompletion(); updatePreviewAgreement();">
                                                    <option value="">Select Term</option>
                                                    <option value="3">3 Months</option>
                                                    <option value="6">6 Months</option>
                                                    <option value="9">9 Months</option>
                                                    <option value="12">12 Months</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label form-required">Loan Purpose</label>
                                        <textarea class="form-control" name="purpose" id="purpose" placeholder="Explain the purpose of this loan..." required oninput="checkFormCompletion(); updatePreviewAgreement();"></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label form-required">Preferred Payment Method</label>
                                        <select class="form-control" name="payment_method" id="paymentMethod" required onchange="showAccountDetailsModal(); checkFormCompletion(); updatePreviewAgreement();">
                                            <option value="">Select Method</option>
                                            <option value="bank">Bank Transfer</option>
                                            <option value="gcash">GCash</option>
                                            <option value="cash">Cash</option>
                                        </select>
                                    </div>

                                    <!-- Account Details Preview -->
                                    <div class="card mb-3 account-details-card" id="accountDetailsPreview" style="display: none;">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">Payment Account Details</h6>
                                        </div>
                                        <div class="card-body">
                                            <div id="accountDetailsContent">
                                                <!-- Dynamic content will be inserted here -->
                                            </div>
                                            <button type="button" class="btn btn-outline-primary btn-sm mt-2" onclick="showAccountDetailsModal()">
                                                <i class="fas fa-edit me-1"></i>Edit Details
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Loan Details Preview -->
                                    <div class="card mb-3" id="loanDetails" style="display: none;">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">Loan Details Preview</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-3">
                                                    <strong>Principal:</strong> <span id="previewPrincipal">₱0.00</span>
                                                </div>
                                                <div class="col-md-3">
                                                    <strong>Term:</strong> <span id="previewTerm">0 months</span>
                                                </div>
                                                <div class="col-md-3">
                                                    <strong>Interest Rate:</strong> <span id="previewRate">2%</span>
                                                </div>
                                                <div class="col-md-3">
                                                    <strong>Monthly Payment:</strong> <span id="previewMonthly">₱0.00</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Preview Agreement Button -->
                                    <div class="card mb-3 preview-agreement-btn" id="previewAgreementCard">
                                        <div class="card-header bg-info text-white">
                                            <h6 class="mb-0"><i class="fas fa-file-contract me-2"></i>Loan Agreement Preview</h6>
                                        </div>
                                        <div class="card-body">
                                            <p class="mb-3">Review the loan agreement before submitting the application:</p>
                                            <button type="button" class="btn btn-info" onclick="previewLoanAgreement()" id="previewAgreementBtn">
                                                <i class="fas fa-eye me-2"></i>Preview Loan Agreement
                                            </button>
                                            <small class="text-muted d-block mt-2">This will open the loan agreement in a new tab for review.</small>
                                        </div>
                                    </div>

                                    <!-- Form Completion Status -->
                                    <div class="card mb-3" id="completionStatus" style="display: none;">
                                        <div class="card-header bg-warning text-dark">
                                            <h6 class="mb-0"><i class="fas fa-tasks me-2"></i>Required Information</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="checkLoanAmount" disabled>
                                                        <label class="form-check-label" for="checkLoanAmount">
                                                            Loan Amount
                                                        </label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="checkLoanTerm" disabled>
                                                        <label class="form-check-label" for="checkLoanTerm">
                                                            Loan Term
                                                        </label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="checkLoanPurpose" disabled>
                                                        <label class="form-check-label" for="checkLoanPurpose">
                                                            Loan Purpose
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="checkPaymentMethod" disabled>
                                                        <label class="form-check-label" for="checkPaymentMethod">
                                                            Payment Method
                                                        </label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="checkAccountDetails" disabled>
                                                        <label class="form-check-label" for="checkAccountDetails">
                                                            Account Details
                                                        </label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="checkAgreementPreviewed" disabled>
                                                        <label class="form-check-label" for="checkAgreementPreviewed">
                                                            Agreement Previewed
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <strong>Important:</strong> This loan will be automatically approved since it's being applied by an admin.
                                        The member will receive a notification about this loan.
                                    </div>

                                    <button type="submit" class="btn btn-success btn" id="submitBtn" disabled>
                                        <i class="fas fa-check-circle me-2"></i>Apply & Approve Loan
                                    </button>
                                    <a href="members.php" class="btn btn-secondary btn">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </a>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Member not found or invalid member ID.
                        </div>
                    <?php endif; ?>
					
					<!-- Back to Members -->
                    <div class="mt-3">
                        <a href="members.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Members
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Account Details Modal -->
    <div class="modal fade" id="accountDetailsModal" tabindex="-1" aria-labelledby="accountDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="accountDetailsModalLabel">Payment Account Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="bankSelection" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label form-required">Select Bank</label>
                            <select class="form-control" id="bankSelect" onchange="showBankFields()">
                                <option value="">Select Bank</option>
                                <option value="bdo">BDO</option>
                                <option value="bpi">BPI</option>
                                <option value="landbank">LandBank</option>
                                <option value="others">Other Bank</option>
                            </select>
                        </div>
                    </div>

                    <div id="bankFields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label form-required" id="accountNumberLabel">Account Number</label>
                            <input type="text" class="form-control bank-account-input" id="bankAccountNumber" placeholder="" maxlength="12" oninput="validateBankAccountNumber()">
                            <small class="text-muted" id="accountNumberHelp"></small>
                            <div class="invalid-feedback" id="accountNumberError">Please enter a valid account number (10-12 digits)</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label form-required">Account Name</label>
                            <input type="text" class="form-control" id="bankAccountName" placeholder="Name as it appears on bank account">
                        </div>
                    </div>

                    <div id="otherBankFields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label form-required">Bank Name</label>
                            <input type="text" class="form-control" id="otherBankName" placeholder="Enter bank name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label form-required">Account Number</label>
                            <input type="text" class="form-control bank-account-input" id="otherBankAccountNumber" placeholder="" maxlength="12" oninput="validateOtherBankAccountNumber()">
                            <small class="text-muted">Enter 10-12 digit account number</small>
                            <div class="invalid-feedback" id="otherAccountNumberError">Please enter a valid account number (10-12 digits)</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label form-required">Account Name</label>
                            <input type="text" class="form-control" id="otherBankAccountName" placeholder="Name as it appears on bank account">
                        </div>
                    </div>

                    <div id="gcashFields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label form-required">GCash Account Number</label>
                            <div class="gcash-input-group">
                                <span class="gcash-prefix">+63</span>
                                <input type="text" class="form-control gcash-number-input" id="gcashAccountNumber" placeholder="9123456789" maxlength="10" oninput="validateGcashNumber()">
                            </div>
                            <small class="text-muted">Enter 10-digit mobile number after +63 (e.g., 9123456789)</small>
                            <div class="invalid-feedback" id="gcashNumberError">Please enter a valid 10-digit mobile number after +63</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label form-required">Account Name</label>
                            <input type="text" class="form-control" id="gcashAccountName" placeholder="Name as it appears on GCash account">
                        </div>
                    </div>

                    <div id="cashFields" style="display: none;">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            For cash payments, the member will need to visit the office to receive their loan proceeds.
                            No additional account details are required.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveAccountDetails()">Save Details</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentPaymentMethod = '';
        let accountDetailsModal = null;
        let agreementPreviewed = false;

        function calculateLoanDetails() {
            const principal = parseFloat(document.getElementById('principal').value) || 0;
            const termMonths = parseInt(document.getElementById('term_months').value) || 0;
            
            if (principal > 0 && termMonths > 0) {
                const interestRate = 2.0; // 2% monthly
                const totalInterest = principal * (interestRate / 100) * termMonths;
                const monthlyPayment = (principal + totalInterest) / termMonths;
                
                document.getElementById('previewPrincipal').textContent = '₱' + principal.toFixed(2);
                document.getElementById('previewTerm').textContent = termMonths + ' months';
                document.getElementById('previewRate').textContent = interestRate + '% monthly';
                document.getElementById('previewMonthly').textContent = '₱' + monthlyPayment.toFixed(2);
                document.getElementById('loanDetails').style.display = 'block';
            } else {
                document.getElementById('loanDetails').style.display = 'none';
            }
        }

        function checkFormCompletion() {
            const principal = document.getElementById('principal').value;
            const termMonths = document.getElementById('term_months').value;
            const purpose = document.getElementById('purpose').value.trim();
            const paymentMethod = document.getElementById('paymentMethod').value;
            const accountDetails = document.getElementById('accountDetailsInput').value;
            
            // Update checkboxes
            document.getElementById('checkLoanAmount').checked = principal && principal >= 1000 && principal <= 50000;
            document.getElementById('checkLoanTerm').checked = termMonths && ['3','6','9','12'].includes(termMonths);
            document.getElementById('checkLoanPurpose').checked = purpose.length > 0;
            document.getElementById('checkPaymentMethod').checked = paymentMethod.length > 0;
            document.getElementById('checkAccountDetails').checked = accountDetails.length > 0;
            document.getElementById('checkAgreementPreviewed').checked = agreementPreviewed;
            
            // Show/hide completion status
            const completionStatus = document.getElementById('completionStatus');
            const allCompleted = document.getElementById('checkLoanAmount').checked && 
                               document.getElementById('checkLoanTerm').checked && 
                               document.getElementById('checkLoanPurpose').checked && 
                               document.getElementById('checkPaymentMethod').checked && 
                               document.getElementById('checkAccountDetails').checked &&
                               agreementPreviewed;
            
            if (principal || termMonths || purpose || paymentMethod || accountDetails) {
                completionStatus.style.display = 'block';
            } else {
                completionStatus.style.display = 'none';
            }
            
            // Enable/disable submit button
            document.getElementById('submitBtn').disabled = !allCompleted;
        }

        function updatePreviewAgreement() {
            const principal = document.getElementById('principal').value;
            const termMonths = document.getElementById('term_months').value;
            const purpose = document.getElementById('purpose').value.trim();
            const paymentMethod = document.getElementById('paymentMethod').value;
            const accountDetails = document.getElementById('accountDetailsInput').value;
            
            // Show preview agreement button when form is mostly complete
            const previewCard = document.getElementById('previewAgreementCard');
            if (principal && termMonths && purpose && paymentMethod && accountDetails) {
                previewCard.style.display = 'block';
            } else {
                previewCard.style.display = 'none';
                agreementPreviewed = false;
                checkFormCompletion();
            }
        }

        function previewLoanAgreement() {
            // Get form data
            const principal = document.getElementById('principal').value;
            const termMonths = document.getElementById('term_months').value;
            const purpose = document.getElementById('purpose').value;
            const paymentMethod = document.getElementById('paymentMethod').value;
            const accountDetails = document.getElementById('accountDetailsInput').value;
            const accountName = document.getElementById('accountNameInput').value;
            const memberId = '<?= $member['member_id'] ?>';
            const memberName = '<?= $member['firstname'] . ' ' . $member['lastname'] ?>';
            
            // Calculate loan details
            const interestRate = 2.0;
            const totalInterest = principal * (interestRate / 100) * termMonths;
            const totalAmount = parseFloat(principal) + totalInterest;
            const monthlyPayment = totalAmount / termMonths;
            
            // Create a temporary form to pass data to preview
            const tempForm = document.createElement('form');
            tempForm.method = 'POST';
            tempForm.action = 'preview_loan_agreement.php';
            tempForm.target = '_blank';
            
            // Add all necessary data as hidden inputs
            const addHiddenInput = (name, value) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                tempForm.appendChild(input);
            };
            
            addHiddenInput('principal', principal);
            addHiddenInput('term_months', termMonths);
            addHiddenInput('purpose', purpose);
            addHiddenInput('payment_method', paymentMethod);
            addHiddenInput('account_details', accountDetails);
            addHiddenInput('account_name', accountName);
            addHiddenInput('member_id', memberId);
            addHiddenInput('member_name', memberName);
            addHiddenInput('interest_rate', interestRate);
            addHiddenInput('total_amount', totalAmount.toFixed(2));
            addHiddenInput('monthly_payment', monthlyPayment.toFixed(2));
            addHiddenInput('preview_mode', 'true');
            
            // Append form to body and submit
            document.body.appendChild(tempForm);
            tempForm.submit();
            document.body.removeChild(tempForm);
            
            // Mark agreement as previewed
            agreementPreviewed = true;
            checkFormCompletion();
            
            // Show success message
            alert('Loan agreement preview opened in new tab. Please review the agreement before submitting the loan application.');
        }

        function showAccountDetailsModal() {
            currentPaymentMethod = document.getElementById('paymentMethod').value;
            
            if (!currentPaymentMethod) {
                alert('Please select a payment method first.');
                return;
            }

            // Reset all fields
            document.getElementById('bankSelection').style.display = 'none';
            document.getElementById('bankFields').style.display = 'none';
            document.getElementById('otherBankFields').style.display = 'none';
            document.getElementById('gcashFields').style.display = 'none';
            document.getElementById('cashFields').style.display = 'none';
            
            // Clear all inputs
            document.getElementById('bankSelect').value = '';
            document.getElementById('bankAccountNumber').value = '';
            document.getElementById('bankAccountName').value = '';
            document.getElementById('otherBankName').value = '';
            document.getElementById('otherBankAccountNumber').value = '';
            document.getElementById('otherBankAccountName').value = '';
            document.getElementById('gcashAccountNumber').value = '';
            document.getElementById('gcashAccountName').value = '';

            // Remove validation classes
            document.getElementById('bankAccountNumber').classList.remove('is-invalid');
            document.getElementById('otherBankAccountNumber').classList.remove('is-invalid');
            document.getElementById('gcashAccountNumber').classList.remove('is-invalid');

            // Show relevant fields based on payment method
            if (currentPaymentMethod === 'bank') {
                document.getElementById('bankSelection').style.display = 'block';
            } else if (currentPaymentMethod === 'gcash') {
                document.getElementById('gcashFields').style.display = 'block';
            } else if (currentPaymentMethod === 'cash') {
                document.getElementById('cashFields').style.display = 'block';
            }

            if (!accountDetailsModal) {
                accountDetailsModal = new bootstrap.Modal(document.getElementById('accountDetailsModal'));
            }
            accountDetailsModal.show();
        }

        function showBankFields() {
            const bank = document.getElementById('bankSelect').value;
            const bankFields = document.getElementById('bankFields');
            const otherBankFields = document.getElementById('otherBankFields');
            
            // Hide both sections first
            bankFields.style.display = 'none';
            otherBankFields.style.display = 'none';
            
            if (bank === 'others') {
                otherBankFields.style.display = 'block';
            } else if (bank) {
                bankFields.style.display = 'block';
                
                // Set appropriate labels and placeholders based on bank
                switch(bank) {
                    case 'bdo':
                        document.getElementById('accountNumberLabel').textContent = 'BDO Account Number';
                        document.getElementById('accountNumberHelp').textContent = '10-12 digit account number';
                        document.getElementById('bankAccountNumber').placeholder = '000000000000';
                        break;
                    case 'bpi':
                        document.getElementById('accountNumberLabel').textContent = 'BPI Account Number';
                        document.getElementById('accountNumberHelp').textContent = '10 digit account number';
                        document.getElementById('bankAccountNumber').placeholder = '0000000000';
                        break;
                    case 'landbank':
                        document.getElementById('accountNumberLabel').textContent = 'LandBank Account Number';
                        document.getElementById('accountNumberHelp').textContent = '10 digit account number';
                        document.getElementById('bankAccountNumber').placeholder = '0000000000';
                        break;
                }
            }
        }

        function validateBankAccountNumber() {
            const accountNumber = document.getElementById('bankAccountNumber').value;
            const bank = document.getElementById('bankSelect').value;
            const input = document.getElementById('bankAccountNumber');
            const error = document.getElementById('accountNumberError');
            
            // Remove spaces and check if it's all digits
            const cleanNumber = accountNumber.replace(/\s/g, '');
            const isValidLength = cleanNumber.length >= 10 && cleanNumber.length <= 12;
            const isAllDigits = /^\d+$/.test(cleanNumber);
            
            if (!isValidLength || !isAllDigits) {
                input.classList.add('is-invalid');
                error.textContent = 'Please enter a valid account number (10-12 digits)';
                return false;
            } else {
                input.classList.remove('is-invalid');
                return true;
            }
        }

        function validateOtherBankAccountNumber() {
            const accountNumber = document.getElementById('otherBankAccountNumber').value;
            const input = document.getElementById('otherBankAccountNumber');
            const error = document.getElementById('otherAccountNumberError');
            
            // Remove spaces and check if it's all digits
            const cleanNumber = accountNumber.replace(/\s/g, '');
            const isValidLength = cleanNumber.length >= 10 && cleanNumber.length <= 12;
            const isAllDigits = /^\d+$/.test(cleanNumber);
            
            if (!isValidLength || !isAllDigits) {
                input.classList.add('is-invalid');
                error.textContent = 'Please enter a valid account number (10-12 digits)';
                return false;
            } else {
                input.classList.remove('is-invalid');
                return true;
            }
        }

        function validateGcashNumber() {
            const gcashNumber = document.getElementById('gcashAccountNumber').value;
            const input = document.getElementById('gcashAccountNumber');
            const error = document.getElementById('gcashNumberError');
            
            // Remove spaces and validate GCash format (10 digits after +63)
            const cleanNumber = gcashNumber.replace(/\s/g, '');
            const isValid = cleanNumber.length === 10 && /^9\d{9}$/.test(cleanNumber);
            
            if (!isValid) {
                input.classList.add('is-invalid');
                error.textContent = 'Please enter a valid 10-digit mobile number after +63 (should start with 9)';
                return false;
            } else {
                input.classList.remove('is-invalid');
                return true;
            }
        }

        function saveAccountDetails() {
            let accountDetails = '';
            let accountName = '';
            let isValid = true;

            if (currentPaymentMethod === 'bank') {
                const bank = document.getElementById('bankSelect').value;
                
                if (!bank) {
                    alert('Please select a bank.');
                    isValid = false;
                } else if (bank === 'others') {
                    // Handle other bank
                    const otherBankName = document.getElementById('otherBankName').value.trim();
                    const otherAccountNumber = document.getElementById('otherBankAccountNumber').value.trim();
                    accountName = document.getElementById('otherBankAccountName').value.trim();

                    if (!otherBankName) {
                        alert('Please enter bank name.');
                        isValid = false;
                    } else if (!validateOtherBankAccountNumber()) {
                        isValid = false;
                    } else if (!accountName) {
                        alert('Please enter account name.');
                        isValid = false;
                    } else {
                        accountDetails = `${otherBankName} - ${otherAccountNumber}`;
                    }
                } else {
                    // Handle specific banks
                    const accountNumber = document.getElementById('bankAccountNumber').value.trim();
                    accountName = document.getElementById('bankAccountName').value.trim();

                    if (!validateBankAccountNumber()) {
                        isValid = false;
                    } else if (!accountName) {
                        alert('Please enter account name.');
                        isValid = false;
                    } else {
                        accountDetails = `${bank.toUpperCase()} - ${accountNumber}`;
                    }
                }
            } else if (currentPaymentMethod === 'gcash') {
                const gcashNumber = document.getElementById('gcashAccountNumber').value.trim();
                accountName = document.getElementById('gcashAccountName').value.trim();

                if (!validateGcashNumber()) {
                    isValid = false;
                } else if (!accountName) {
                    alert('Please enter account name.');
                    isValid = false;
                } else {
                    // Format as +63 followed by the 10-digit number
                    accountDetails = `GCash - +63${gcashNumber}`;
                }
            } else if (currentPaymentMethod === 'cash') {
                accountDetails = 'Cash Payment';
                accountName = 'N/A';
            }

            if (isValid) {
                // Save to hidden inputs
                document.getElementById('accountDetailsInput').value = accountDetails;
                document.getElementById('accountNameInput').value = accountName;

                // Update preview
                const previewDiv = document.getElementById('accountDetailsPreview');
                const contentDiv = document.getElementById('accountDetailsContent');
                
                contentDiv.innerHTML = `
                    <strong>Payment Method:</strong> ${document.getElementById('paymentMethod').options[document.getElementById('paymentMethod').selectedIndex].text}<br>
                    <strong>Account Details:</strong> ${accountDetails}<br>
                    <strong>Account Name:</strong> ${accountName}
                `;
                
                previewDiv.style.display = 'block';
                
                // Check form completion and update agreement preview
                checkFormCompletion();
                updatePreviewAgreement();
                
                // Close modal
                accountDetailsModal.hide();
            }
        }

        // Initialize modal and check form on load
        document.addEventListener('DOMContentLoaded', function() {
            accountDetailsModal = new bootstrap.Modal(document.getElementById('accountDetailsModal'));
            checkFormCompletion();
            
            // Add input event listeners for real-time validation
            document.getElementById('principal').addEventListener('input', checkFormCompletion);
            document.getElementById('term_months').addEventListener('change', checkFormCompletion);
            document.getElementById('purpose').addEventListener('input', checkFormCompletion);
        });
    </script>
</body>
</html>