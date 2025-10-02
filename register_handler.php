<?php
session_start();
require_once 'db.php';
require_once 'notifications.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {
    // REGISTRATION PROCESS
    $firstname = trim($_POST['firstname'] ?? '');
    $middlename = trim($_POST['middlename'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $birthday = $_POST['birthday'] ?? '';
    
    // ADDRESS FIELDS
    $house_no = trim($_POST['house_no'] ?? '');
    $street_village = trim($_POST['street_village'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $municipality = trim($_POST['municipality'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $postal_code = trim($_POST['postal_code'] ?? '');
    
    // EMPLOYMENT FIELDS
    $company_name = trim($_POST['company_name'] ?? '');
    $company_address = trim($_POST['company_address'] ?? '');
    $nature_of_work = trim($_POST['nature_of_work'] ?? '');
    $salary = !empty($_POST['salary']) ? floatval($_POST['salary']) : 0;
    
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    
    // Validation (same as before)
    if (empty($firstname) || strlen($firstname) < 2) {
        $errors[] = "First name must be at least 2 characters";
    }
    
    if (empty($middlename) || strlen($middlename) < 2) {
        $errors[] = "Middle name must be at least 2 characters";
    }
    
    if (empty($lastname) || strlen($lastname) < 2) {
        $errors[] = "Last name must be at least 2 characters";
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address";
    }
    
    if (empty($mobile)) {
        $errors[] = "Please enter a valid mobile number";
    }
    
    if (empty($birthday)) {
        $errors[] = "Please enter your birthday";
    }
    
    if (empty($house_no)) {
        $errors[] = "Please enter your house/building number";
    }
    
    if (empty($street_village)) {
        $errors[] = "Please enter your street/village";
    }
    
    if (empty($barangay)) {
        $errors[] = "Please enter your barangay";
    }
    
    if (empty($municipality)) {
        $errors[] = "Please enter your municipality";
    }
    
    if (empty($city)) {
        $errors[] = "Please enter your city";
    }
    
    if (empty($postal_code)) {
        $errors[] = "Please enter your postal code";
    }
    
    if (empty($company_name)) {
        $errors[] = "Please enter your company name";
    }
    
    if (empty($company_address)) {
        $errors[] = "Please enter your company address";
    }
    
    if (empty($nature_of_work)) {
        $errors[] = "Please enter your nature of work";
    }
    
    if (empty($salary) || $salary < 0) {
        $errors[] = "Please enter a valid salary amount";
    }
    
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    // Check if email already exists
    if (empty($errors)) {
        try {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("SELECT member_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = "Email address is already registered";
            }
        } catch (Exception $e) {
            error_log("Email check error: " . $e->getMessage());
            $errors[] = "Error checking email availability";
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo = DB::pdo();
            
            // Get next member ID
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'member'");
            $count_result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = ($count_result['count'] ?? 0) + 1;
            $member_id = 'MBR-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Use current date for date_employed
            $date_employed = date('Y-m-d');
            
            // COUNT CAREFULLY: Let's list ALL columns from your table that are NOT NULL and don't have defaults
            // Based on your table structure, these are the required columns:
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    member_id,          -- 1
                    role,               -- 2
                    firstname,          -- 3
                    middlename,         -- 4
                    lastname,           -- 5
                    email,              -- 6
                    mobile,             -- 7
                    password,           -- 8
                    status,             -- 9
                    birthday,           -- 10
                    house_no,           -- 11
                    street_village,     -- 12
                    barangay,           -- 13
                    municipality,       -- 14
                    city,               -- 15
                    postal_code,        -- 16
                    nature_of_work,     -- 17
                    salary,             -- 18
                    company_name,       -- 19
                    company_address,    -- 20
                    date_employed,      -- 21
                    id_type,            -- 22
                    proof_of_id_file,   -- 23
                    company_id_file,    -- 24
                    coe_file            -- 25
                ) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $member_id,         // 1. member_id
                'member',           // 2. role
                $firstname,         // 3. firstname
                $middlename,        // 4. middlename
                $lastname,          // 5. lastname
                $email,             // 6. email
                $mobile,            // 7. mobile
                $hashed_password,   // 8. password
                'pending',          // 9. status
                $birthday,          // 10. birthday
                $house_no,          // 11. house_no
                $street_village,    // 12. street_village
                $barangay,          // 13. barangay
                $municipality,      // 14. municipality
                $city,              // 15. city
                $postal_code,       // 16. postal_code
                $nature_of_work,    // 17. nature_of_work
                $salary,            // 18. salary
                $company_name,      // 19. company_name
                $company_address,   // 20. company_address
                $date_employed,     // 21. date_employed
                'National ID',      // 22. id_type
                'pending_upload',   // 23. proof_of_id_file
                'pending_upload',   // 24. company_id_file
                'pending_upload'    // 25. coe_file
            ]);
            
            if ($result) {
                // NOTIFY ADMIN ABOUT NEW REGISTRATION
                Notifications::notifyAdmin(
                    'member_registration',
                    'New Member Registration',
                    $firstname . ' ' . $lastname . ' ('.$member_id.') has registered and is waiting for approval.',
                    $member_id,
                    $member_id
                );
                
                $_SESSION['registration_success'] = true;
                header('Location: index.php');
                exit;
            } else {
                $errorInfo = $stmt->errorInfo();
                error_log("Registration failed: " . print_r($errorInfo, true));
                $_SESSION['registration_errors'] = ["Registration failed. Please check your information and try again."];
                $_SESSION['form_data'] = $_POST;
                $_SESSION['had_registration_errors'] = true;
                header('Location: index.php#auth');
                exit;
            }
            
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            $_SESSION['registration_errors'] = ["Registration failed: " . $e->getMessage()];
            $_SESSION['form_data'] = $_POST;
            $_SESSION['had_registration_errors'] = true;
            header('Location: index.php#auth');
            exit;
        }
    } else {
        $_SESSION['registration_errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
        $_SESSION['had_registration_errors'] = true;
        header('Location: index.php#auth');
        exit;
    }
} else {
    header('Location: index.php');
    exit;
}
?>