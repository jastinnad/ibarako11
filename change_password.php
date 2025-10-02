<?php
session_start();
require_once 'db.php';

// Check if user is logged in and requires password change
if (!isset($_SESSION['user']) || !isset($_SESSION['requires_password_change'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$user = $_SESSION['user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate passwords
    if (empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all password fields.";
    } elseif (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        try {
            $pdo = DB::pdo();
            
            // Hash new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password and remove password change requirement
            $stmt = $pdo->prepare("UPDATE users SET password = ?, requires_password_change = 0 WHERE member_id = ?");
            $result = $stmt->execute([$hashed_password, $user['member_id']]);
            
            if ($result) {
                // Update session
                $_SESSION['user']['requires_password_change'] = 0;
                unset($_SESSION['requires_password_change']);
                
                // Redirect to dashboard with success message
                $_SESSION['success'] = "Password changed successfully!";
                
                // FIXED: Correct redirect paths
                if ($user['role'] === 'admin') {
                    header('Location: admin/dashboard.php');
                } else {
                    header('Location: member/dashboard.php');
                }
                exit;
            } else {
                $error = "Failed to update password. Please try again.";
            }
        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - iBarako Loan System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #2b9cff 0%, #1a3c89 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .password-change-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            width: 100%;
			max-width: 600px;
			 margin: 0 auto;
        }
        .alert-warning {
			border: none;
			border-radius: 10px;
            border-left: 4px solid #ffc107;
			font-size: 0.875rem;
			padding: 0.75rem 1rem;
        }
        .form-text {
            font-size: 0.875rem;
        }
		.text-muted {
			font-size: 0.75rem;
		}
    </style>
</head>
<body>
	<div class="d-flex align-items-center mb-1" style="padding-left: 7.9rem;">
		<img src="ibarako_logov2.PNG" alt="iBarako Logo" class="logo" style="max-height: 90px;">
	</div>
    <div class="container">
        <div class="password-change-card">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <i class="fas fa-shield-alt fa-3x text-primary mb-3"></i>
                    <h2 class="card-title">Change Your Password</h2>
                    <p class="text-muted mt-1" style="font-size: 0.9rem;">Welcome, <?= htmlspecialchars($user['firstname']) ?>! Please set your new password.</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>

                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Security Notice:</strong> You are required to change your temporary password for security reasons.
                </div>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">New Password *</label>
                        <input type="password" class="form-control" name="new_password" required 
                               placeholder="Enter your new password (min. 8 characters)"
                               minlength="8">
                        <div class="form-text mb-2" style="font-size: 0.75rem;">Password must be at least 8 characters long.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Confirm New Password *</label>
                        <input type="password" class="form-control" name="confirm_password" required 
                               placeholder="Confirm your new password"
                               minlength="8">
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2">
                        <i class="fas fa-key me-2"></i>Change Password
                    </button>
                </form>

                <div class="text-center mt-3">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        You'll be redirected to your dashboard after changing your password.
                    </small>
                </div>
            </div>
        </div>
    </div>

<!--
    <style>
        body {
            background: linear-gradient(135deg, #2b9cff 0%, #1a3c89 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .password-change-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            width: 100%;
			max-width: 600px;
			 margin: 0 auto;
        }
        .alert-warning {
			border: none;
			border-radius: 10px;
            border-left: 4px solid #ffc107;
			font-size: 0.875rem;
			padding: 0.75rem 1rem;
        }
        .form-text {
            font-size: 0.875rem;
        }
		.text-muted {
			font-size: 0.75rem;
		}
		.logo {
			max-height: 60px;
			margin-bottom: 1.5rem;
		}
    </style>
</head>
<body class= "d-flex flex-column align-items-center">
	
    <div class="text-center mb-1">
        <img src="ibarako_logov2.PNG" alt="iBarako Logo" class="logo" style="max-height: 70px;">
    </div>
    <div class="container">
        <div class="password-change-card">
    <div class="card-body p-4">
        <div class="row align-items-center">
            <div class="col-md-4 text-center">
                <i class="fas fa-shield-alt fa-3x text-primary mb-3"></i>
                <h2 class="card-title" style="font-size: 1.5rem;">Change Your Password</h2>
                <p class="text-muted mt-1" style="font-size: 0.9rem;">Welcome, <?= htmlspecialchars($user['firstname']) ?>! <br>Please set your new password.</br></p>
            </div>
            
            <div class="col-md-8">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>

                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Security Notice:</strong> You are required to change your temporary password.
                </div>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">New Password *</label>
                        <input type="password" class="form-control" name="new_password" required 
                               placeholder="Enter your new password (min. 8 characters)"
                               minlength="8">
                        <div class="form-text mb-2" style="font-size: 0.75rem;">Password must be at least 8 characters long.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Confirm New Password *</label>
                        <input type="password" class="form-control" name="confirm_password" required 
                               placeholder="Confirm your new password"
                               minlength="8">
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2">
                        <i class="fas fa-key me-2"></i>Change Password
                    </button>
                </form>

                <div class="text-center mt-3">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        You'll be redirected to your dashboard after changing your password.
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>
-->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Client-side validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const newPassword = document.querySelector('input[name="new_password"]').value;
            const confirmPassword = document.querySelector('input[name="confirm_password"]').value;
            
            if (newPassword.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long.');
                return false;
            }
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match.');
                return false;
            }
        });
    </script>
</body>
</html>