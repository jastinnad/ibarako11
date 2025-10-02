<?php
// functions.php - Professional Loan System Functions
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

date_default_timezone_set($config['app']['timezone']);

// Start session with secure settings
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400, // 1 day
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? 'localhost',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

class Auth {
    public static function generate_member_id() {
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->query("SELECT member_id FROM users WHERE member_id LIKE 'MBR-%' ORDER BY id DESC LIMIT 1");
            $last = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$last) {
                return 'MBR-0001';
            }
            
            $last_number = intval(substr($last['member_id'], 4));
            $new_number = $last_number + 1;
            return 'MBR-' . str_pad($new_number, 4, '0', STR_PAD_LEFT);
            
        } catch (Exception $e) {
            error_log("Error generating member ID: " . $e->getMessage());
            // Fallback: generate based on timestamp
            return 'MBR-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        }
    }
    
    public static function generate_initial_password($lastname, $birthday) {
        $year = date('Y', strtotime($birthday));
        return $lastname . $year;
    }
    
    public static function hash_password($plain) {
        return password_hash($plain, PASSWORD_DEFAULT);
    }
    
    public static function verify_password($plain, $hash) {
        return password_verify($plain, $hash);
    }
    
    public static function current_user() {
        return $_SESSION['user'] ?? null;
    }
    
    public static function require_role($role) {
        $user = self::current_user();
        if (!$user || $user['role'] !== $role) {
            $_SESSION['error'] = "Access denied. Insufficient permissions.";
            header('Location: /ibarako/index.php');
            exit;
        }
    }
    
    public static function check_login_attempts($email) {
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE email = ?");
            $stmt->execute([$email]);
            $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($attempt && $attempt['attempts'] >= 5) {
                $lockout_time = strtotime($attempt['last_attempt']) + 900; // 15 minutes
                if (time() < $lockout_time) {
                    return false; // Account locked
                } else {
                    // Reset attempts after lockout period
                    $pdo->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$email]);
                    return true;
                }
            }
            return true;
        } catch (Exception $e) {
            error_log("Error checking login attempts: " . $e->getMessage());
            return true; // Allow login if there's an error
        }
    }
    
    public static function detect_user_role($email) {
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("SELECT role FROM users WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? $result['role'] : null;
        } catch (Exception $e) {
            error_log("Error detecting user role: " . $e->getMessage());
            return null;
        }
    }
    
    public static function get_redirect_url($role) {
        global $config;
        $base = $config['app']['base_url'];
        
        switch ($role) {
            case 'admin':
                return $base . '/admin/dashboard.php';
            case 'member':
                return $base . '/member/dashboard.php';
            default:
                return $base . '/index.php';
        }
    }
    
    public static function attempt_login($email, $password) {
        global $config;
        
        if (!self::check_login_attempts($email)) {
            return [
                'success' => false, 
                'error' => 'Too many login attempts. Please try again in 15 minutes.',
                'locked' => true
            ];
        }
        
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && self::verify_password($password, $user['password'])) {
                // Reset login attempts on successful login
                $pdo->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$email]);
                
                // Log successful login
                self::log_activity($user['id'], 'Login', 'Successful login');
                
                // Store user data in session
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'member_id' => $user['member_id'],
                    'role' => $user['role'],
                    'firstname' => $user['firstname'],
                    'lastname' => $user['lastname'],
                    'email' => $user['email'],
                    'mobile' => $user['mobile'],
                    'status' => $user['status']
                ];
                
                return [
                    'success' => true, 
                    'user' => $user,
                    'redirect' => self::get_redirect_url($user['role']),
                    'message' => 'Login successful! Redirecting...'
                ];
            } else {
                // Record failed attempt with proper error handling
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO login_attempts (email, ip_address, user_agent) 
                        VALUES (?, ?, ?) 
                        ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()
                    ");
                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                    $stmt->execute([$email, $_SERVER['REMOTE_ADDR'] ?? 'unknown', $user_agent]);
                } catch (Exception $e) {
                    error_log("Error recording login attempt: " . $e->getMessage());
                    // Continue without failing the login process
                }
                
                // Log failed attempt if user exists
                if ($user) {
                    self::log_activity($user['id'], 'Failed Login', 'Invalid password attempt');
                }
                
                return [
                    'success' => false, 
                    'error' => 'Invalid email or password.',
                    'locked' => false
                ];
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return [
                'success' => false, 
                'error' => 'System error. Please try again later.',
                'locked' => false
            ];
        }
    }
    
    public static function log_activity($user_id, $activity, $details = '') {
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("
                INSERT INTO user_logs (user_id, activity, details, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $stmt->execute([
                $user_id, 
                $activity, 
                $details,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $user_agent
            ]);
        } catch (Exception $e) {
            error_log("Error logging activity: " . $e->getMessage());
        }
    }
    
    public static function logout() {
        if (self::current_user()) {
            self::log_activity(self::current_user()['id'], 'Logout', 'User logged out');
        }
        
        // Clear session data
        $_SESSION = [];
        
        // Delete session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
    
    public static function validate_password_strength($password) {
        global $config;
        $min_length = $config['security']['min_password_length'];
        
        if (strlen($password) < $min_length) {
            return "Password must be at least $min_length characters long.";
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            return "Password must contain at least one uppercase letter.";
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            return "Password must contain at least one lowercase letter.";
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            return "Password must contain at least one number.";
        }
        
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return "Password must contain at least one special character.";
        }
        
        return true;
    }
    
    public static function validate_member_data($data) {
        $errors = [];
        
        if (empty($data['firstname']) || strlen(trim($data['firstname'])) < 2) {
            $errors[] = "First name is required and must be at least 2 characters";
        }
        
        if (empty($data['lastname']) || strlen(trim($data['lastname'])) < 2) {
            $errors[] = "Last name is required and must be at least 2 characters";
        }
        
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email address is required";
        }
        
        if (empty($data['mobile']) || strlen(trim($data['mobile'])) < 10) {
            $errors[] = "Valid mobile number is required";
        }
        
        if (empty($data['birthday']) || !self::is_valid_date($data['birthday'])) {
            $errors[] = "Valid birthday is required";
        }
        
        if (empty($data['nature_of_work']) || strlen(trim($data['nature_of_work'])) < 2) {
            $errors[] = "Nature of work is required";
        }
        
        if (empty($data['salary']) || !is_numeric($data['salary']) || $data['salary'] < 0) {
            $errors[] = "Valid salary amount is required";
        }
        
        return $errors;
    }
    
    public static function is_valid_date($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    public static function create_demo_admin() {
        try {
            $pdo = DB::pdo();
            
            // Check if demo admin exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = 'admin@ibarako.com'");
            $stmt->execute();
            
            if (!$stmt->fetch()) {
                $hashed_password = self::hash_password('admin001');
                $stmt = $pdo->prepare("
                    INSERT INTO users (member_id, role, firstname, lastname, email, password, status) 
                    VALUES (?, 'admin', ?, ?, ?, ?, 'active')
                ");
                $stmt->execute(['ADMIN-001', 'System', 'Administrator', 'admin@ibarako.com', $hashed_password]);
                
                error_log("Demo admin account created: admin@ibarako.com / admin001");
                return true;
            }
            return false;
        } catch (Exception $e) {
            error_log("Error creating demo admin: " . $e->getMessage());
            return false;
        }
    }
}

class LoanSystem {
    public static function generate_member_id() {
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->query("SELECT member_id FROM users WHERE member_id LIKE 'MBR-%' ORDER BY id DESC LIMIT 1");
            $last = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$last) {
                return 'MBR-0001';
            }
            
            $last_number = intval(substr($last['member_id'], 4));
            $new_number = $last_number + 1;
            return 'MBR-' . str_pad($new_number, 4, '0', STR_PAD_LEFT);
            
        } catch (Exception $e) {
            error_log("Error generating member ID: " . $e->getMessage());
            return 'MBR-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        }
    }
    
    public static function generate_initial_password($lastname, $birthday) {
        $year = date('Y', strtotime($birthday));
        // Remove spaces and make lowercase for consistency
        $clean_lastname = strtolower(str_replace(' ', '', $lastname));
        return $clean_lastname . $year;
    }
    
    public static function create_loan_schedule($loan_id, $principal, $interest_rate, $term_months) {
        try {
            $pdo = DB::pdo();
            $monthly_payment = self::calculate_monthly_payment($principal, $interest_rate, $term_months);
            $balance = $principal;
            $due_date = date('Y-m-d', strtotime('+1 month'));
            
            for ($i = 1; $i <= $term_months; $i++) {
                $interest = $balance * ($interest_rate / 100 / 12);
                $principal_payment = $monthly_payment - $interest;
                $balance -= $principal_payment;
                
                $stmt = $pdo->prepare("
                    INSERT INTO loan_schedules (loan_id, month_number, due_date, monthly_payment, principal_amount, interest_amount, remaining_balance) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$loan_id, $i, $due_date, $monthly_payment, $principal_payment, $interest, max(0, $balance)]);
                
                $due_date = date('Y-m-d', strtotime($due_date . ' +1 month'));
            }
            return true;
        } catch (Exception $e) {
            error_log("Error creating loan schedule: " . $e->getMessage());
            return false;
        }
    }
    
    public static function get_interest_rate() {
        try {
            $pdo = DB::pdo();
            
            // First try to get from settings table
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'interest_rate'");
            $stmt->execute();
            $rate = $stmt->fetchColumn();
            
            if ($rate) {
                return (float) $rate;
            }
            
            // Fallback to old method
            $stmt = $pdo->prepare("SELECT interest_rate FROM settings WHERE id = 1");
            $stmt->execute();
            $rate = $stmt->fetchColumn();
            
            return $rate ? (float) $rate : 2.00;
            
        } catch (Exception $e) {
            error_log("Error getting interest rate: " . $e->getMessage());
            return 2.00;
        }
    }
    
    public static function get_effective_interest_rate() {
        try {
            $pdo = DB::pdo();
            
            // Check if there's a future rate change
            $stmt = $pdo->prepare("
                SELECT new_rate FROM interest_rate_changes 
                WHERE effective_date > CURDATE() 
                ORDER BY effective_date ASC 
                LIMIT 1
            ");
            $stmt->execute();
            $future_rate = $stmt->fetchColumn();
            
            if ($future_rate) {
                return (float) $future_rate;
            }
            
            // Return current rate
            return self::get_interest_rate();
            
        } catch (Exception $e) {
            error_log("Error getting effective interest rate: " . $e->getMessage());
            return self::get_interest_rate();
        }
    }
    
    public static function update_interest_rate($rate, $effective_date = null) {
        try {
            $pdo = DB::pdo();
            
            if ($effective_date && strtotime($effective_date) > time()) {
                // Schedule future rate change
                $current_rate = self::get_interest_rate();
                $stmt = $pdo->prepare("
                    INSERT INTO interest_rate_changes (old_rate, new_rate, effective_date, changed_by) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$current_rate, $rate, $effective_date, $_SESSION['user']['id'] ?? null]);
            } else {
                // Update current rate immediately
                $stmt = $pdo->prepare("
                    INSERT INTO settings (name, value) VALUES ('interest_rate', ?) 
                    ON DUPLICATE KEY UPDATE value = ?
                ");
                $stmt->execute([$rate, $rate]);
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Error updating interest rate: " . $e->getMessage());
            return false;
        }
    }
    
    public static function calculate_monthly_payment($principal, $interest_rate, $term_months) {
        $monthly_rate = $interest_rate / 100 / 12;
        if ($monthly_rate == 0) {
            return $principal / $term_months;
        }
        $payment = ($principal * $monthly_rate) / (1 - pow(1 + $monthly_rate, -$term_months));
        return round($payment, 2);
    }
    
    public static function calculate_total_interest($principal, $interest_rate, $term_months) {
        $monthly_payment = self::calculate_monthly_payment($principal, $interest_rate, $term_months);
        $total_payment = $monthly_payment * $term_months;
        return round($total_payment - $principal, 2);
    }
    
    public static function validate_loan_application($principal, $term_months, $user_id = null) {
        $errors = [];
        global $config;
        
        $min_amount = $config['loan']['min_amount'] ?? 1000;
        $max_amount = $config['loan']['max_amount'] ?? 50000;
        $allowed_terms = $config['loan']['terms'] ?? [3, 6, 9, 12];
        
        if ($principal < $min_amount || $principal > $max_amount) {
            $errors[] = "Loan amount must be between " . format_currency($min_amount) . " and " . format_currency($max_amount);
        }
        
        if (!in_array($term_months, $allowed_terms)) {
            $errors[] = "Loan term must be one of: " . implode(', ', $allowed_terms) . " months";
        }
        
        // Check if user has active loans
        if ($user_id) {
            try {
                $pdo = DB::pdo();
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM loans 
                    WHERE user_id = ? AND status IN ('pending', 'approved')
                ");
                $stmt->execute([$user_id]);
                $active_loans = $stmt->fetchColumn();
                
                if ($active_loans > 0) {
                    $errors[] = "You already have an active or pending loan application";
                }
            } catch (Exception $e) {
                error_log("Error checking active loans: " . $e->getMessage());
            }
        }
        
        return $errors;
    }
}

class NotificationSystem {
    public static function create($user_id, $title, $message, $type = 'info') {
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
            return $stmt->execute([$user_id, $title, $message, $type]);
        } catch (Exception $e) {
            error_log("Error creating notification: " . $e->getMessage());
            return false;
        }
    }
    
    public static function notify_admin($type, $title, $message, $related_id = null) {
        try {
            $pdo = DB::pdo();
            
            // Get admin users
            $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
            $stmt->execute();
            $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($admins as $admin_id) {
                self::create($admin_id, $title, $message, $type);
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Error notifying admin: " . $e->getMessage());
            return false;
        }
    }
}

class Validation {
    public static function is_valid_email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    public static function is_valid_phone($phone) {
        // Remove all non-digit characters except + at the beginning
        $clean_phone = preg_replace('/[^\d+]/', '', $phone);
        return preg_match('/^(\+\d{1,3})?\d{10,15}$/', $clean_phone);
    }
    
    public static function is_valid_name($name) {
        return preg_match('/^[a-zA-Z\s\'\-]{2,100}$/', $name);
    }
    
    public static function is_valid_amount($amount) {
        return is_numeric($amount) && $amount > 0;
    }
    
    public static function is_valid_date($date, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}

// Utility functions
function generate_loan_agreement($user, $loan_details = null) {
    $interest_rate = $loan_details ? $loan_details['interest_rate'] : LoanSystem::get_interest_rate();
    $monthly_payment = $loan_details ? $loan_details['monthly_payment'] : 0;
    $principal = $loan_details ? $loan_details['principal'] : 0;
    $term = $loan_details ? $loan_details['term_months'] : 0;
    
    return "
        iBARAKO COOPERATIVE LOAN AGREEMENT
        
        This Agreement is made and entered into on " . date('F j, Y') . "
        
        BETWEEN:
        iBarako Cooperative (hereinafter referred to as the 'Lender')
        AND
        {$user['firstname']} {$user['lastname']} (Member ID: {$user['member_id']})
        (hereinafter referred to as the 'Borrower')
        
        LOAN TERMS:
        1. Loan Type: " . ($loan_details['loan_type'] ?? 'Personal') . "
        2. Principal Amount: " . format_currency($principal) . "
        3. Interest Rate: {$interest_rate}% per month
        4. Loan Term: {$term} months
        5. Monthly Payment: " . format_currency($monthly_payment) . "
        6. Total Interest: " . format_currency($loan_details['total_interest'] ?? 0) . "
        7. Total Repayment: " . format_currency(($principal + ($loan_details['total_interest'] ?? 0))) . "
        
        TERMS AND CONDITIONS:
        - Payments are due on the first day of each month
        - Late payments incur 5% penalty fee
        - Prepayment is allowed without penalty
        - Default occurs after 3 missed payments
        
        BORROWER'S ACKNOWLEDGEMENT:
        I have read and understood all terms and conditions of this agreement.
        
        Borrower's Signature: ___________________________
        Printed Name: {$user['firstname']} {$user['lastname']}
        Date: ___________________________
        
        LENDER'S APPROVAL:
        Approved by iBarako Cooperative
        
        Authorized Signature: ___________________________
        Date: ___________________________
    ";
}

function sanitize_input($data) {
    if ($data === null) return '';
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function format_currency($amount) {
    return '₱' . number_format($amount, 2);
}

function format_date($date, $format = 'M j, Y') {
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') return 'N/A';
    return date($format, strtotime($date));
}

function format_datetime($datetime, $format = 'M j, Y g:i A') {
    if (empty($datetime) || $datetime == '0000-00-00 00:00:00') return 'N/A';
    return date($format, strtotime($datetime));
}

function redirect($url, $message = null, $message_type = 'success') {
    if ($message) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_message_type'] = $message_type;
    }
    header("Location: $url");
    exit;
}

function get_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_message_type'] ?? 'info';
        unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
        
        return [
            'message' => $message,
            'type' => $type
        ];
    }
    return null;
}

function display_flash_message() {
    $flash = get_flash_message();
    if ($flash) {
        $alert_class = match($flash['type']) {
            'success' => 'alert-success',
            'error' => 'alert-danger',
            'warning' => 'alert-warning',
            default => 'alert-info'
        };
        
        return "<div class='alert {$alert_class} alert-dismissible fade show' role='alert'>
                <i class='fas fa-info-circle me-2'></i>{$flash['message']}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
    }
    return '';
}

function get_user_display_name($user) {
    if (is_array($user)) {
        return trim($user['firstname'] . ' ' . ($user['middlename'] ? $user['middlename'] . ' ' : '') . $user['lastname']);
    }
    return '';
}

function calculate_age($birthday) {
    if (empty($birthday)) return null;
    
    $birthday = new DateTime($birthday);
    $today = new DateTime();
    $age = $today->diff($birthday);
    return $age->y;
}

function is_valid_image($file) {
    if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
        return false;
    }
    
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $file_info = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($file_info, $file['tmp_name']);
    finfo_close($file_info);
    
    return in_array($mime_type, $allowed_types);
}

function upload_file($file, $target_dir, $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf']) {
    if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
        return ['success' => false, 'error' => 'No file uploaded'];
    }
    
    // Create target directory if it doesn't exist
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }
    
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_extension, $allowed_extensions)) {
        return ['success' => false, 'error' => 'Invalid file type'];
    }
    
    // Generate unique filename
    $filename = uniqid() . '.' . $file_extension;
    $target_path = $target_dir . '/' . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return ['success' => true, 'filename' => $filename, 'path' => $target_path];
    } else {
        return ['success' => false, 'error' => 'Failed to upload file'];
    }
}

function send_email($to, $subject, $message, $headers = '') {
    // Basic email sending function - would need to be implemented with a proper email service
    // For now, just log the email
    error_log("Email to: $to, Subject: $subject, Message: $message");
    return true;
}

function get_pagination($total_items, $current_page, $items_per_page = 10) {
    $total_pages = ceil($total_items / $items_per_page);
    $current_page = max(1, min($current_page, $total_pages));
    $offset = ($current_page - 1) * $items_per_page;
    
    return [
        'total_items' => $total_items,
        'total_pages' => $total_pages,
        'current_page' => $current_page,
        'items_per_page' => $items_per_page,
        'offset' => $offset,
        'has_previous' => $current_page > 1,
        'has_next' => $current_page < $total_pages
    ];
}

function generate_random_string($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $characters_length = strlen($characters);
    $random_string = '';
    
    for ($i = 0; $i < $length; $i++) {
        $random_string .= $characters[rand(0, $characters_length - 1)];
    }
    
    return $random_string;
}

function encrypt_data($data, $key) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

function decrypt_data($data, $key) {
    list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
    return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
}

// Error handling
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Error: $errstr in $errfile on line $errline");
    if (defined('DEBUG') && DEBUG) {
        echo "<div class='alert alert-danger'>Error: $errstr in $errfile on line $errline</div>";
    }
});

// Auto-create demo admin on include
if (php_sapi_name() !== 'cli') {
    Auth::create_demo_admin();
}