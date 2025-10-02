<?php
session_start();
require_once '../db.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Check if this is a preview request
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['preview_mode'])) {
    die('Invalid request');
}

// Get form data
$principal = floatval($_POST['principal']);
$term_months = intval($_POST['term_months']);
$purpose = $_POST['purpose'];
$payment_method = $_POST['payment_method'];
$account_details = $_POST['account_details'];
$account_name = $_POST['account_name'];
$member_id = $_POST['member_id'];
$member_name = $_POST['member_name'];
$interest_rate = floatval($_POST['interest_rate']);
$total_amount = floatval($_POST['total_amount']);
$monthly_payment = floatval($_POST['monthly_payment']);

// Generate temporary loan number for preview
$loan_number = 'PREVIEW-' . date('YmdHis');

// Calculate payment schedule
$payment_schedule = [];
$remaining_balance = $total_amount;
$payment_date = date('Y-m-d');

for ($i = 1; $i <= $term_months; $i++) {
    $payment_date = date('Y-m-d', strtotime($payment_date . ' +1 month'));
    $remaining_balance -= $monthly_payment;
    if ($remaining_balance < 0) $remaining_balance = 0;
    
    $payment_schedule[] = [
        'installment' => $i,
        'due_date' => $payment_date,
        'amount' => $monthly_payment,
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
    <title>Preview Loan Agreement - <?= $loan_number ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none !important; }
            .page-break { page-break-before: always; }
            .preview-watermark { display: none !important; }
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
            content: "PREVIEW";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 48px;
            color: rgba(255,0,0,0.1);
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
            font-family: Arial, sans-serif;
        }
        
        .btn:hover {
            background: #0056b3;
        }
        
        .btn-print {
            background: #28a745;
            border-color: #28a745;
        }
        
        .btn-print:hover {
            background: #218838;
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
        
        .row {
            display: flex;
            justify-content: space-between;
        }
        
        .preview-watermark {
            background-color: #fff3cd;
            padding: 15px;
            text-align: center;
            margin-bottom: 20px;
            border: 2px solid #ffc107;
            border-radius: 5px;
            font-family: Arial, sans-serif;
        }
        
        .preview-watermark h4 {
            margin: 0 0 10px 0;
            color: #856404;
        }
        
        .preview-watermark p {
            margin: 0;
            color: #856404;
            font-size: 14px;
        }
        
        .preview-note {
            background-color: #d1ecf1;
            padding: 10px;
            margin: 10px 0;
            border-left: 4px solid #0c5460;
            font-family: Arial, sans-serif;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Preview Watermark -->
        <div class="preview-watermark no-print">
            <h4><i class="fas fa-eye me-2"></i>LOAN AGREEMENT PREVIEW</h4>
            <p>This is a preview of the loan agreement. Data has not been saved to the database yet.</p>
            <p><strong>Close this window and return to the application form to submit the loan.</strong></p>
        </div>

        <!-- Action buttons -->
        <div class="actions no-print">
            <button class="btn btn-print" onclick="window.print()">
                <i class="fas fa-print me-1"></i>Print Preview
            </button>
            <button class="btn btn-secondary" onclick="window.close()">
                <i class="fas fa-times me-1"></i>Close Preview
            </button>
        </div>

        <!-- Loan Agreement Content -->
        <div class="header">
            <h1>LOAN AGREEMENT</h1>
            <h2>iBarako Lending Corporation</h2>
            <p>Official Loan Contract</p>
        </div>

        <div class="preview-note no-print">
            <strong><i class="fas fa-info-circle me-1"></i>Note:</strong> This is a preview document. The actual loan agreement will be generated after form submission.
        </div>

        <div class="section">
            <div class="section-title">PARTIES</div>
            <div class="clause">
                <p>This Loan Agreement ("Agreement") is made and entered into on <?= date('F j, Y') ?> between:</p>
                
                <p><strong>iBarako Lending Corporation</strong> ("Lender"), a duly registered lending corporation, with office address at [Company Address];</p>
                
                <p>AND</p>
                
                <p><strong><?= htmlspecialchars($member_name) ?></strong> ("Borrower"), with Member ID: <?= $member_id ?>, residing at [Member Address].</p>
            </div>
        </div>

        <div class="section">
            <div class="section-title">LOAN DETAILS</div>
            <table class="loan-details">
                <tr>
                    <td class="label">Loan Number</td>
                    <td><?= $loan_number ?> <span style="color: #dc3545; font-style: italic;">(Preview)</span></td>
                </tr>
                <tr>
                    <td class="label">Principal Loan Amount</td>
                    <td><?= format_currency($principal) ?> (<?= number_to_words($principal) ?> Pesos)</td>
                </tr>
                <tr>
                    <td class="label">Loan Term</td>
                    <td><?= $term_months ?> Months</td>
                </tr>
                <tr>
                    <td class="label">Monthly Interest Rate</td>
                    <td><?= $interest_rate ?>%</td>
                </tr>
                <tr>
                    <td class="label">Total Interest</td>
                    <td><?= format_currency($total_amount - $principal) ?></td>
                </tr>
                <tr>
                    <td class="label">Total Amount Payable</td>
                    <td><?= format_currency($total_amount) ?> (<?= number_to_words($total_amount) ?> Pesos)</td>
                </tr>
                <tr>
                    <td class="label">Monthly Payment</td>
                    <td><?= format_currency($monthly_payment) ?></td>
                </tr>
                <tr>
                    <td class="label">Loan Purpose</td>
                    <td><?= htmlspecialchars($purpose) ?></td>
                </tr>
                <tr>
                    <td class="label">Payment Method</td>
                    <td><?= ucfirst($payment_method) ?></td>
                </tr>
                <tr>
                    <td class="label">Account Details</td>
                    <td><?= htmlspecialchars($account_details) ?></td>
                </tr>
                <tr>
                    <td class="label">Account Name</td>
                    <td><?= htmlspecialchars($account_name) ?></td>
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
                <p>The Borrower agrees to repay the loan in <?= $term_months ?> equal monthly installments of <?= format_currency($monthly_payment) ?> each. Payments are due on the same day of each month as the loan disbursement date.</p>
            </div>
            
            <div class="clause">
                <div class="clause-title">2. INTEREST CALCULATION</div>
                <p>Interest is calculated at <?= $interest_rate ?>% per month on the outstanding principal balance. The total interest for the entire loan term amounts to <?= format_currency($total_amount - $principal) ?>.</p>
            </div>
            
            <div class="clause">
                <div class="clause-title">3. PAYMENT METHOD</div>
                <p>Payments shall be made through <?= ucfirst($payment_method) ?> to the following account:</p>
                <p>Account Name: <?= htmlspecialchars($account_name) ?><br>
                Account Details: <?= htmlspecialchars($account_details) ?></p>
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
            
            <div class="clause">
                <div class="clause-title">8. LOAN PURPOSE</div>
                <p>The Borrower certifies that the loan proceeds will be used for the following purpose: <?= htmlspecialchars($purpose) ?>. Any misrepresentation of the loan purpose may result in immediate termination of this agreement.</p>
            </div>
        </div>

        <div class="section">
            <div class="section-title">GOVERNING LAW</div>
            <div class="clause">
                <p>This Agreement shall be governed by and construed in accordance with the laws of the Republic of the Philippines. Any disputes arising from this Agreement shall be settled in the proper courts of [City/Municipality], Philippines.</p>
            </div>
        </div>

        <div class="section">
            <div class="section-title">ENTIRE AGREEMENT</div>
            <div class="clause">
                <p>This Agreement constitutes the entire understanding between the parties and supersedes all prior agreements, whether written or oral. No modification of this Agreement shall be effective unless in writing and signed by both parties.</p>
            </div>
        </div>

        <div class="signature-section">
            <div class="row">
                <div class="borrower-signature" style="width: 45%;">
                    <div class="signature-line"></div>
                    <div class="signature-label">
                        <strong><?= htmlspecialchars($member_name) ?></strong><br>
                        Borrower's Signature<br>
                        Member ID: <?= $member_id ?><br>
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
                <strong>PREVIEW AGREEMENT</strong><br>
                <em>For Review Purposes Only</em><br>
                iBarako Lending Corporation<br>
                Date: <?= date('F j, Y') ?>
            </div>
        </div>

        <div class="text-center mt-4">
            <p><em>This is a computer-generated preview document and does not require a physical signature.</em></p>
            <p><strong><em>This agreement will become official upon form submission and database entry.</em></strong></p>
        </div>

        <!-- Footer Actions -->
        <div class="actions no-print mt-4">
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Next Step:</strong> Close this window and return to the application form to submit the loan.
            </div>
            <button class="btn btn-primary" onclick="window.close()">
                <i class="fas fa-arrow-left me-1"></i>Return to Application Form
            </button>
        </div>
    </div>

    <script>
        // Auto-print if URL has print parameter
        if (window.location.search.includes('print=true')) {
            window.print();
        }

        // Add keyboard shortcut for printing (Ctrl+P)
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });

        // Show confirmation when trying to close the window
        window.addEventListener('beforeunload', function(e) {
            if (!window.location.search.includes('print=true')) {
                // This message might not show in all browsers due to security restrictions
                e.returnValue = 'Are you sure you want to leave? Your loan agreement preview will be lost.';
                return e.returnValue;
            }
        });
    </script>
</body>
</html>