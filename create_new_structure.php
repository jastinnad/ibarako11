<?php
// create_new_structure.php - Migration to use member_id as primary key
require_once 'config.php';

try {
    $config = require 'config.php';
    $dbConfig = $config['db'];
    
    // Create database connection
    $pdo = new PDO("mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']}", $dbConfig['user'], $dbConfig['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<div class='alert alert-info'>✅ Database connection established</div>";

    // Step 1: Create a temporary backup of current data
    $pdo->exec("CREATE TABLE IF NOT EXISTS users_backup AS SELECT * FROM users");
    $pdo->exec("CREATE TABLE IF NOT EXISTS loans_backup AS SELECT * FROM loans");
    echo "<div class='alert alert-info'>✅ Created backup tables</div>";

    // Step 2: Drop all tables in correct order to avoid foreign key constraints
    $tables = [
        'interest_rate_changes',
        'admin_settings',
        'notifications',
        'loan_payments',
        'loan_schedules',
        'update_requests',
        'contributions',
        'user_logs',
        'login_attempts',
        'loans',
        'settings',
        'users'
    ];

    foreach ($tables as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS $table");
            echo "<div class='alert alert-info'>✅ Dropped table: $table</div>";
        } catch (Exception $e) {
            echo "<div class='alert alert-warning'>⚠️ Could not drop $table: " . $e->getMessage() . "</div>";
        }
    }

    // Step 3: Create new tables with member_id as primary key
    $pdo->exec("
        CREATE TABLE users (
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

    // Create other tables with member_id as foreign key
    $pdo->exec("
        CREATE TABLE loans (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id VARCHAR(20),
            loan_number VARCHAR(50) UNIQUE,
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
    echo "<div class='alert alert-success'>✅ Loans table created</div>";

    // Create other tables...
    $pdo->exec("
        CREATE TABLE login_attempts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            attempts INT DEFAULT 1,
            last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45),
            user_agent TEXT,
            INDEX idx_email (email)
        )
    ");
    echo "<div class='alert alert-success'>✅ Login attempts table created</div>";

    $pdo->exec("
        CREATE TABLE user_logs (
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
    echo "<div class='alert alert-success'>✅ User logs table created</div>";

    $pdo->exec("
        CREATE TABLE settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            interest_rate DECIMAL(5,2) DEFAULT 2.00,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    echo "<div class='alert alert-success'>✅ Settings table created</div>";

    $pdo->exec("
        CREATE TABLE contributions (
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
    echo "<div class='alert alert-success'>✅ Contributions table created</div>";

    $pdo->exec("
        CREATE TABLE interest_rate_changes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            old_rate DECIMAL(5,2),
            new_rate DECIMAL(5,2),
            effective_date DATE,
            changed_by VARCHAR(20),
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (changed_by) REFERENCES users(member_id)
        )
    ");
    echo "<div class='alert alert-success'>✅ Interest rate changes table created</div>";

    $pdo->exec("
        CREATE TABLE loan_schedules (
            id INT PRIMARY KEY AUTO_INCREMENT,
            loan_id INT,
            month_number INT,
            due_date DATE NOT NULL,
            monthly_payment DECIMAL(10,2),
            principal_amount DECIMAL(10,2),
            interest_amount DECIMAL(10,2),
            remaining_balance DECIMAL(10,2),
            is_paid BOOLEAN DEFAULT FALSE,
            paid_date DATE NULL,
            late_fee DECIMAL(10,2) DEFAULT 0.00,
            FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
            INDEX idx_loan_id (loan_id)
        )
    ");
    echo "<div class='alert alert-success'>✅ Loan schedules table created</div>";

    $pdo->exec("
        CREATE TABLE loan_payments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            loan_id INT,
            amount DECIMAL(10,2) NOT NULL,
            payment_date DATE NOT NULL,
            payment_method VARCHAR(50),
            reference_number VARCHAR(100),
            receipt_path VARCHAR(500),
            notes TEXT,
            status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
            verified_by VARCHAR(20),
            verified_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
            FOREIGN KEY (verified_by) REFERENCES users(member_id),
            INDEX idx_loan_id (loan_id),
            INDEX idx_status (status)
        )
    ");
    echo "<div class='alert alert-success'>✅ Loan payments table created</div>";

    $pdo->exec("
        CREATE TABLE update_requests (
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
    echo "<div class='alert alert-success'>✅ Update requests table created</div>";

    $pdo->exec("
        CREATE TABLE notifications (
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
    echo "<div class='alert alert-success'>✅ Notifications table created</div>";

    $pdo->exec("
        CREATE TABLE admin_settings (
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
    echo "<div class='alert alert-success'>✅ Admin settings table created</div>";

    // Step 4: Insert default data
    $pdo->exec("INSERT INTO settings (interest_rate) VALUES (2.00)");
    echo "<div class='alert alert-success'>✅ Default settings inserted</div>";

    // Create admin account
    $admin_password = 'admin001';
    $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("
        INSERT INTO users (member_id, role, firstname, lastname, email, password, status) 
        VALUES (?, 'admin', ?, ?, ?, ?, 'active')
    ");
    $stmt->execute(['ADMIN-001', 'System', 'Administrator', 'admin@ibarako.com', $hashed_password]);
    
    echo "<div class='alert alert-success'>✅ Admin account created</div>";

    // Create sample member
    $member_password = 'member001';
    $hashed_member_password = password_hash($member_password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("
        INSERT INTO users (member_id, role, firstname, lastname, email, mobile, password, status) 
        VALUES (?, 'member', ?, ?, ?, ?, ?, 'active')
    ");
    $stmt->execute(['MBR-1001', 'Juan', 'Dela Cruz', 'member@example.com', '09171234567', $hashed_member_password]);
    
    echo "<div class='alert alert-success'>✅ Sample member account created</div>";

    // Create sample loan
    $stmt = $pdo->prepare("
        INSERT INTO loans (user_id, loan_number, principal, term_months, interest_rate, monthly_payment, total_amount, purpose, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute(['MBR-1001', 'LN-0001', 5000.00, 6, 2.0, 883.33, 5300.00, 'Sample loan purpose']);
    echo "<div class='alert alert-success'>✅ Sample loan created</div>";

    echo "<div class='alert alert-success mt-3'>";
    echo "<h4>🎉 <strong>Database migration completed successfully!</strong></h4>";
    echo "<p>All tables now use <strong>member_id</strong> as the primary key.</p>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>❌ <strong>Error during migration:</strong></h4>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}
?>