<?php
session_start();
require_once 'db.php';
require_once 'notifications.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($action === 'login') {
        // LOGIN PROCESS
        try {
            $pdo = DB::pdo();
            
            // Check if user exists
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                // Check if user is approved
                if ($user['status'] !== 'active' && $user['role'] === 'member') {
                    $_SESSION['login_error'] = "Your account is pending admin approval. Please wait for approval.";
                    header('Location: index.php');
                    exit;
                } else {
                    // Login successful
                    $_SESSION['user'] = [
                        'member_id' => $user['member_id'],
                        'email' => $user['email'],
                        'role' => $user['role'],
                        'firstname' => $user['firstname'],
                        'lastname' => $user['lastname'],
                        'status' => $user['status'],
                        'requires_password_change' => $user['requires_password_change']
                    ];
                    
                    // Check if password change is required
                    if ($user['requires_password_change']) {
                        $_SESSION['requires_password_change'] = true;
                        header('Location: change_password.php'); // This should point to root change_password.php
                        exit;
                    }
                    
                    // Redirect based on role
                    if ($user['role'] === 'admin') {
                        header('Location: admin/dashboard.php');
                    } else {
                        header('Location: member/dashboard.php');
                    }
                    exit;
                }
            } else {
                $_SESSION['login_error'] = "Invalid email or password";
                header('Location: index.php');
                exit;
            }
            
        } catch (Exception $e) {
            $_SESSION['login_error'] = "Login error: " . $e->getMessage();
            header('Location: index.php');
            exit;
        }
    } 
}

// If someone accesses login.php directly without POST, redirect to index
header('Location: index.php');
exit;
?>