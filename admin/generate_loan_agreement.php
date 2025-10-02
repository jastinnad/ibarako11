<?php
session_start();
require_once '../db.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Check if loan_id is provided and valid
if (!isset($_GET['loan_id']) || !is_numeric($_GET['loan_id'])) {
    die('Invalid loan ID');
}

$loan_id = intval($_GET['loan_id']);
$action = $_GET['action'] ?? 'view'; // view or print

// Verify that the loan exists and is approved/active/completed
$pdo = DB::pdo();
$stmt = $pdo->prepare("
    SELECT l.*, u.firstname, u.lastname, u.email, u.mobile, u.house_no, u.street_village, u.barangay, u.municipality, u.city, u.postal_code, u.member_id
    FROM loans l 
    INNER JOIN users u ON l.user_id = u.member_id 
    WHERE l.id = ? AND l.status IN ('approved', 'active', 'completed')
");
$stmt->execute([$loan_id]);
$loan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loan) {
    die('Loan not found, access denied, or loan is not yet approved');
}

// Set appropriate headers based on action
if ($action === 'print') {
    // For printing, we'll use HTML that's print-optimized
} else {
    // Default to view
    header('Content-Type: text/html');
}

// Calculate payment schedule
$payment_schedule = [];
$remaining_balance = $loan['total_amount'];
$payment_date = date('Y-m-d', strtotime($loan['approved_date'] ?? $loan['created_at']));

for ($i = 1; $i <= $loan['term_months']; $i++) {
    $payment_date = date('Y-m-d', strtotime($payment_date . ' +1 month'));
    $remaining_balance -= $loan['monthly_payment'];
    if ($remaining_balance < 0) $remaining_balance = 0;
    
    $payment_schedule[] = [
        'installment' => $i,
        'due_date' => $payment_date,
        'amount' => $loan['monthly_payment'],
        'remaining_balance' => $remaining_balance
    ];
}

// Function to format currency with Peso sign
function format_currency($amount) {
    return '₱' . number_format($amount, 2);
}

// Function to number to words
function number_to_words($number) {
    $ones = array("", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine");
    $teens = array("Ten", "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen");
    $tens = array("", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety");
    
    if ($number == 0) return "Zero";
    
    $words = "";
    
    // Handle millions
    if ($number >= 1000000) {
        $millions = floor($number / 1000000);
        $words .= number_to_words($millions) . " Million ";
        $number %= 1000000;
    }
    
    // Handle thousands
    if ($number >= 1000) {
        $thousands = floor($number / 1000);
        $words .= number_to_words($thousands) . " Thousand ";
        $number %= 1000;
    }
    
    // Handle hundreds
    if ($number >= 100) {
        $hundreds = floor($number / 100);
        $words .= $ones[$hundreds] . " Hundred ";
        $number %= 100;
    }
    
    // Handle tens and ones
    if ($number >= 20) {
        $tens_digit = floor($number / 10);
        $words .= $tens[$tens_digit] . " ";
        $number %= 10;
    } elseif ($number >= 10) {
        $words .= $teens[$number - 10] . " ";
        $number = 0;
    }
    
    if ($number > 0) {
        $words .= $ones[$number] . " ";
    }
    
    return trim($words);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Agreement - <?= $loan['loan_number'] ?></title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none !important; }
            .page-break { page-break-before: always; }
        }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            line-height: 1.6;
            color: #000;
            margin: 0;
            padding: 20px;
            background: #fff;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        
        .header h1 {
            margin: 0;
            font-size: 24px;
            text-transform: uppercase;
        }
        
        .header h2 {
            margin: 5px 0;
            font-size: 18px;
            color: #333;
        }
        
        .section {
            margin-bottom: 25px;
        }
        
        .section-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 10px;
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
        }
        
        .clause {
            margin-bottom: 15px;
            text-align: justify;
        }
        
        .clause-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .signature-section {
            margin-top: 50px;
        }
        
        .signature-line {
            border-top: 1px solid #000;
            width: 300px;
            margin: 40px 0 5px 0;
        }
        
        .signature-label {
            font-size: 14px;
            text-align: center;
        }
        
        .loan-details {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        .loan-details td {
            padding: 8px;
            border: 1px solid #000;
        }
        
        .loan-details .label {
            font-weight: bold;
            width: 40%;
            background: #f5f5f5;
        }
        
        .payment-schedule {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 12px;
        }
        
        .payment-schedule th,
        .payment-schedule td {
            padding: 6px;
            border: 1px solid #000;
            text-align: center;
        }
        
        .payment-schedule th {
            background: #f5f5f5;
            font-weight: bold;
        }
        
        .stamp {
            position: relative;
            padding: 20px;
            margin: 30px 0;
            border: 2px solid #000;
            text-align: center;
        }
        
        .stamp:before {
            content: "APPROVED";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 48px;
            color: rgba(0,0,0,0.1);
            font-weight: bold;
            z-index: 1;
        }
        
        .stamp-content {
            position: relative;
            z-index: 2;
        }
        
        .actions {
            margin: 20px 0;
            text-align: center;
        }
        
        .btn {
            padding: 10px 20px;
            margin: 0 5px;
            border: 1px solid #007bff;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .btn:hover {
            background: #0056b3;
        }
        
        .text-center {
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        .mb-4 {
            margin-bottom: 30px;
        }
        
        .mt-4 {
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Action buttons (hidden when printing) -->
        <div class="actions no-print">
            <a href="?action=print&loan_id=<?= $loan_id ?>" class="btn" onclick="window.print(); return false;">Print</a>
            <a href="loans.php" class="btn">Back to Loan Management</a>
        </div>

        <!-- Loan Agreement Content -->
        <div class="header">
            <h1>LOAN AGREEMENT</h1>
            <h2>iBarako Lending Corporation</h2>
            <p>Official Loan Contract</p>
        </div>

        <div class="section">
            <div class="section-title">PARTIES</div>
            <div class="clause">
                <p>This Loan Agreement ("Agreement") is made and entered into on <?= date('F j, Y', strtotime($loan['approved_date'] ?? $loan['created_at'])) ?> between:</p>
                
                <p><strong>iBarako Lending Corporation</strong> ("Lender"), a duly registered lending corporation, with office address at [Company Address];</p>
                
                <p>AND</p>
                
                <p><strong><?= $loan['firstname'] . ' ' . $loan['lastname'] ?></strong> ("Borrower"), with Member ID: <?= $loan['member_id'] ?>, residing at <?= $loan['address'] ?>.</p>
            </div>
        </div>

        <div class="section">
            <div class="section-title">LOAN DETAILS</div>
            <table class="loan-details">
                <tr>
                    <td class="label">Loan Number</td>
                    <td><?= $loan['loan_number'] ?></td>
                </tr>
                <tr>
                    <td class="label">Principal Loan Amount</td>
                    <td><?= format_currency($loan['principal']) ?> (<?= number_to_words($loan['principal']) ?> Pesos)</td>
                </tr>
                <tr>
                    <td class="label">Loan Term</td>
                    <td><?= $loan['term_months'] ?> Months</td>
                </tr>
                <tr>
                    <td class="label">Monthly Interest Rate</td>
                    <td><?= $loan['interest_rate'] ?>%</td>
                </tr>
                <tr>
                    <td class="label">Total Interest</td>
                    <td><?= format_currency($loan['total_amount'] - $loan['principal']) ?></td>
                </tr>
                <tr>
                    <td class="label">Total Amount Payable</td>
                    <td><?= format_currency($loan['total_amount']) ?> (<?= number_to_words($loan['total_amount']) ?> Pesos)</td>
                </tr>
                <tr>
                    <td class="label">Monthly Payment</td>
                    <td><?= format_currency($loan['monthly_payment']) ?></td>
                </tr>
                <tr>
                    <td class="label">Loan Purpose</td>
                    <td><?= htmlspecialchars($loan['purpose']) ?></td>
                </tr>
            </table>
        </div>

        <div class="section">
            <div class="section-title">PAYMENT SCHEDULE</div>
            <table class="payment-schedule">
                <thead>
                    <tr>
                        <th>Installment #</th>
                        <th>Due Date</th>
                        <th>Amount Due</th>
                        <th>Remaining Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payment_schedule as $payment): ?>
                    <tr>
                        <td><?= $payment['installment'] ?></td>
                        <td><?= date('M j, Y', strtotime($payment['due_date'])) ?></td>
                        <td><?= format_currency($payment['amount']) ?></td>
                        <td><?= format_currency($payment['remaining_balance']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <div class="section-title">TERMS AND CONDITIONS</div>
            
            <div class="clause">
                <div class="clause-title">1. REPAYMENT TERMS</div>
                <p>The Borrower agrees to repay the loan in <?= $loan['term_months'] ?> equal monthly installments of <?= format_currency($loan['monthly_payment']) ?> each. Payments are due on the same day of each month as the loan disbursement date.</p>
            </div>
            
            <div class="clause">
                <div class="clause-title">2. INTEREST CALCULATION</div>
                <p>Interest is calculated at <?= $loan['interest_rate'] ?>% per month on the outstanding principal balance. The total interest for the entire loan term amounts to <?= format_currency($loan['total_amount'] - $loan['principal']) ?>.</p>
            </div>
            
            <div class="clause">
                <div class="clause-title">3. PAYMENT METHOD</div>
                <p>Payments shall be made through <?= ucfirst($loan['payment_method']) ?> to the following account:</p>
                <p>Account Name: <?= htmlspecialchars($loan['account_name']) ?><br>
                Account Details: <?= htmlspecialchars($loan['account_details']) ?></p>
            </div>
            
            <div class="clause">
                <div class="clause-title">4. LATE PAYMENT PENALTIES</div>
                <p>A late payment penalty of 5% of the overdue amount will be charged for payments received after the due date. Continuous late payments may affect the Borrower's credit standing with the Lender.</p>
            </div>
            
            <div class="clause">
                <div class="clause-title">5. PREPAYMENT</div>
                <p>The Borrower may prepay the loan in full or in part at any time without penalty. Prepayments will be applied first to accrued interest, then to the principal balance.</p>
            </div>
            
            <div class="clause">
                <div class="clause-title">6. DEFAULT</div>
                <p>Failure to make three consecutive payments will constitute default. Upon default, the entire outstanding balance shall become immediately due and payable, and the Lender may pursue all available legal remedies.</p>
            </div>
            
            <div class="clause">
                <div class="clause-title">7. COLLECTION COSTS</div>
                <p>In the event of default, the Borrower shall be responsible for all reasonable collection costs, including attorney's fees and court costs incurred by the Lender.</p>
            </div>
        </div>

        <div class="section">
            <div class="section-title">GOVERNING LAW</div>
            <div class="clause">
                <p>This Agreement shall be governed by and construed in accordance with the laws of the Republic of the Philippines. Any disputes arising from this Agreement shall be settled in the proper courts of [City/Municipality], Philippines.</p>
            </div>
        </div>

        <div class="signature-section">
            <div class="row" style="display: flex; justify-content: space-between;">
                <div class="borrower-signature" style="width: 45%;">
                    <div class="signature-line"></div>
                    <div class="signature-label">
                        <strong><?= $loan['firstname'] . ' ' . $loan['lastname'] ?></strong><br>
                        Borrower's Signature<br>
                        Member ID: <?= $loan['member_id'] ?><br>
                        Date: ____________________
                    </div>
                </div>
                
                <div class="lender-signature" style="width: 45%;">
                    <div class="signature-line"></div>
                    <div class="signature-label">
                        <strong>Authorized Signatory</strong><br>
                        iBarako Lending Corporation<br>
                        Date: ____________________
                    </div>
                </div>
            </div>
        </div>

        <div class="stamp mt-4">
            <div class="stamp-content">
                <strong>APPROVED AND DISBURSED</strong><br>
                iBarako Lending Corporation<br>
                Date: <?= date('F j, Y', strtotime($loan['approved_date'] ?? $loan['created_at'])) ?>
            </div>
        </div>

        <div class="text-center mt-4">
            <p><em>This is a computer-generated document and does not require a physical signature.</em></p>
        </div>
    </div>

    <script>
        // Auto-print if action is print
        <?php if ($action === 'print'): ?>
        window.onload = function() {
            window.print();
        }
        <?php endif; ?>
    </script>
</body>
</html>