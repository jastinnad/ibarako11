<?php
require_once 'db.php';

try {
    $pdo = DB::pdo();
    
    // Reset admin password
    $new_password = 'admin001';
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = 'admin@ibarako.com'");
    $stmt->execute([$hashed_password]);
    
    echo "<h3>✅ Admin Password Reset Successfully</h3>";
    echo "<p><strong>New Password:</strong> admin001</p>";
    echo "<p><strong>Email:</strong> admin@ibarako.com</p>";
    echo "<p><a href='login.php'>Go to Login</a></p>";
    
    // Verify the hash
    $stmt = $pdo->prepare("SELECT password FROM users WHERE email = 'admin@ibarako.com'");
    $stmt->execute();
    $hash = $stmt->fetchColumn();
    
    echo "<p><strong>Password verification test:</strong> " . 
         (password_verify('admin001', $hash) ? '✅ SUCCESS' : '❌ FAILED') . "</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>