<?php
session_start();
require_once 'db.php';

echo "<h3>Login Debug Information</h3>";

try {
    $pdo = DB::pdo();
    
    // Check if admin user exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = 'admin@ibarako.com'");
    $stmt->execute();
    $admin = $stmt->fetch();
    
    if ($admin) {
        echo "<p style='color: green;'>✓ Admin user found in database</p>";
        echo "<pre>Admin data: " . print_r($admin, true) . "</pre>";
        
        // Test password
        $test_password = 'Admin123!';
        if (password_verify($test_password, $admin['password'])) {
            echo "<p style='color: green;'>✓ Password verification successful</p>";
        } else {
            echo "<p style='color: red;'>✗ Password verification failed</p>";
            echo "<p>Stored hash: " . $admin['password'] . "</p>";
            echo "<p>You may need to reset the admin password.</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Admin user not found in database</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='login.php'>Back to Login</a></p>";
?>