<?php
// setup_database.php - Complete Database Setup for iBarako Loan System
require_once 'config.php';

try {
    $config = require 'config.php';
    $dbConfig = $config['db'];
    
    // Create database connection without selecting database first
    $pdo = new PDO("mysql:host={$dbConfig['host']}", $dbConfig['user'], $dbConfig['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbConfig['dbname']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbConfig['dbname']}`");

    echo "<div class='alert alert-info'>✅ Database connection established</div>";

    // Enhanced users table with additional fields
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        member_id VARCHAR(20) PRIMARY KEY,
        role ENUM('admin', 'member') DEFAULT 'member',
        firstname VARCHAR(100) NOT NULL,
        lastname VARCHAR(100) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        mobile VARCHAR(20),
        password VARCHAR(255) NOT NULL,
        status ENUM('pending', 'active', 'suspended') DEFAULT 'pending',
        approved_by VARCHAR(20) NULL,
        approved_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (approved_by) REFERENCES users(member_id),
        INDEX idx_email (email),
        INDEX idx_role (role),
        INDEX idx_status (status)
    )
");
echo "<div class='alert alert-success'>✅ Users table created (member_id as primary key)</div>";

// Update loans table to use member_id as foreign key
$pdo->exec("
    CREATE TABLE IF NOT EXISTS loans (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id VARCHAR(20),
        loan_number VARCHAR(50) NULL,
        principal DECIMAL(10,2) NOT NULL,
        term_months INT NOT NULL,
        interest_rate DECIMAL(5,2) NOT NULL,
        monthly_payment DECIMAL(10,2) DEFAULT 0,
        total_amount DECIMAL(10,2) DEFAULT 0,
        loan_type VARCHAR(50) DEFAULT 'Personal',
        purpose TEXT NULL,
        payment_method VARCHAR(50),
        account_details VARCHAR(255),
        account_name VARCHAR(255),
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        approved_by VARCHAR(20),
        approved_at TIMESTAMP NULL,
        rejection_reason TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(member_id) ON DELETE CASCADE,
        FOREIGN KEY (approved_by) REFERENCES users(member_id),
        INDEX idx_user_id (user_id),
        INDEX idx_status (status),
        INDEX idx_loan_number (loan_number)
    )
");
echo "<div class='alert alert-success'>✅ Loans table created (using member_id as FK)</div>";

// Update other tables to use member_id as foreign key
$pdo->exec("
    CREATE TABLE IF NOT EXISTS user_logs (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id VARCHAR(20),
        activity VARCHAR(255),
        details TEXT,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(member_id) ON DELETE CASCADE,
        INDEX idx_user_id (user_id)
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS contributions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id VARCHAR(20),
        amount DECIMAL(10,2) NOT NULL,
        contrib_date DATE NOT NULL,
        note TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(member_id) ON DELETE CASCADE,
        INDEX idx_user_id (user_id),
        INDEX idx_contrib_date (contrib_date)
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS update_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id VARCHAR(20),
        current_email VARCHAR(255),
        new_email VARCHAR(255),
        current_mobile VARCHAR(20),
        new_mobile VARCHAR(20),
        reason TEXT,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        admin_notes TEXT,
        processed_by VARCHAR(20),
        processed_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(member_id) ON DELETE CASCADE,
        FOREIGN KEY (processed_by) REFERENCES users(member_id),
        INDEX idx_user_id (user_id),
        INDEX idx_status (status)
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS notifications (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id VARCHAR(20) NULL,
        target_user_id VARCHAR(20) NULL,
        type ENUM('loan_application', 'update_request', 'payment_verification', 'system') NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        related_id INT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        action_url VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(member_id) ON DELETE SET NULL,
        FOREIGN KEY (target_user_id) REFERENCES users(member_id) ON DELETE SET NULL,
        INDEX idx_type (type),
        INDEX idx_target_user (target_user_id),
        INDEX idx_created (created_at)
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id VARCHAR(20),
        notify_loan_applications BOOLEAN DEFAULT TRUE,
        notify_update_requests BOOLEAN DEFAULT TRUE,
        notify_payment_verifications BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(member_id) ON DELETE CASCADE,
        UNIQUE KEY unique_user (user_id)
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS interest_rate_changes (
        id INT PRIMARY KEY AUTO_INCREMENT,
        old_rate DECIMAL(5,2),
        new_rate DECIMAL(5,2),
        effective_date DATE,
        changed_by VARCHAR(20),
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (changed_by) REFERENCES users(member_id)
    )
");

// Update the admin account creation
$admin_password = 'admin001';
$hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO users (member_id, role, firstname, lastname, email, password, status) 
    VALUES (?, 'admin', ?, ?, ?, ?, 'active')
");
$stmt->execute(['ADMIN-001', 'System', 'Administrator', 'admin@ibarako.com', $hashed_password]);

// Update sample member creation
$member_password = 'member001';
$hashed_member_password = password_hash($member_password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO users (member_id, role, firstname, lastname, email, mobile, password, status) 
    VALUES (?, 'member', ?, ?, ?, ?, ?, 'active')
");
$stmt->execute(['MBR-1001', 'Juan', 'Dela Cruz', 'member@example.com', '09171234567', $hashed_member_password]);
    
    echo "<div class='alert alert-success'>✅ Sample member account created</div>";
    echo "<div class='alert alert-info'>🔐 Member credentials: member@example.com / member001</div>";

    // Create uploads directory structure
    $directories = [
        'assets/uploads/receipts',
        'assets/documents',
        'assets/css'
    ];
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            if (mkdir($dir, 0755, true)) {
                echo "<div class='alert alert-success'>✅ Created directory: $dir</div>";
            } else {
                echo "<div class='alert alert-warning'>⚠️ Could not create directory: $dir</div>";
            }
        } else {
            echo "<div class='alert alert-info'>✅ Directory exists: $dir</div>";
        }
    }

    echo "<div class='alert alert-success mt-3'>";
    echo "<h4>🎉 <strong>Database setup completed successfully!</strong></h4>";
    echo "</div>";
    
    echo "<div class='card mt-3'>";
    echo "<div class='card-header bg-primary text-white'>";
    echo "<h5 class='mb-0'>Accounts Created</h5>";
    echo "</div>";
    echo "<div class='card-body'>";
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<h6>Admin Account:</h6>";
    echo "<p><strong>Email:</strong> admin@ibarako.com<br><strong>Password:</strong> admin001</p>";
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<h6>Member Account:</h6>";
    echo "<p><strong>Email:</strong> member@example.com<br><strong>Password:</strong> member001</p>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";

    echo "<div class='mt-4'>";
    echo "<h5>Next Steps:</h5>";
    echo "<div class='list-group'>";
    echo "<a href='login.php' class='list-group-item list-group-item-action'>";
    echo "<i class='fas fa-sign-in-alt me-2'></i><strong>Go to Login Page</strong> - Test the system with demo accounts";
    echo "</a>";
    echo "<a href='member/loans.php' class='list-group-item list-group-item-action'>";
    echo "<i class='fas fa-file-invoice-dollar me-2'></i><strong>Test Loans</strong> - Try the loan application system";
    echo "</a>";
    echo "<a href='member/payments.php' class='list-group-item list-group-item-action'>";
    echo "<i class='fas fa-money-check me-2'></i><strong>Test Payments</strong> - Try the payment system";
    echo "</a>";
    echo "</div>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>❌ <strong>Error during setup:</strong></h4>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup - iBarako Loan System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            padding: 20px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container { 
            max-width: 1000px; 
        }
        .card {
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border: none;
            border-radius: 15px;
        }
        .alert {
            border: none;
            border-radius: 10px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="text-center mb-4">
            <h1 class="text-white"><i class="fas fa-hand-holding-usd me-3"></i>iBarako Loan System</h1>
            <p class="lead text-white">Complete Database Setup</p>
        </div>
        
        <div class="card shadow-lg">
            <div class="card-header bg-primary text-white text-center py-4">
                <h3 class="mb-0"><i class="fas fa-database me-2"></i>Database Setup Progress</h3>
                <p class="mb-0 mt-2">Setting up your complete loan management system...</p>
            </div>
            <div class="card-body p-4">
                <?php
                // The PHP output will be displayed here
                ?>
            </div>
            <div class="card-footer text-center bg-light">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    This setup creates all necessary tables, demo accounts, and directory structure for the iBarako Loan System.
                </small>
            </div>
        </div>
        
        <div class="text-center mt-4">
            <p class="text-white">
                Need help? Check the documentation or contact support.
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>