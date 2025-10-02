<?php
session_start();
require_once '../db.php';
require_once '../notifications.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$error = '';
$success = '';

// Function to calculate age from birthday
function calculateAge($birthday) {
    if (empty($birthday)) return '';
    
    $birthday_date = DateTime::createFromFormat('Y-m-d', $birthday);
    $today = new DateTime();
    return $today->diff($birthday_date)->y;
}

// Function to generate member ID in MBR-0001 format
function generateMemberId($pdo) {
    // Get the last created member (most recent)
    $stmt = $pdo->prepare("SELECT member_id FROM users WHERE role = 'member' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && !empty($result['member_id'])) {
        $last_member_id = $result['member_id'];
        
        // Check if it's in MBR- format
        if (strpos($last_member_id, 'MBR-') === 0) {
            // Extract the number part after "MBR-"
            $number_part = substr($last_member_id, 4);
            $last_number = intval($number_part);
            $next_number = $last_number + 1;
        } else {
            // If last member ID is not in MBR- format, count existing MBR- members
            $stmt = $pdo->prepare("SELECT COUNT(*) as mbr_count FROM users WHERE member_id LIKE 'MBR-%' AND role = 'member'");
            $stmt->execute();
            $count_result = $stmt->fetch(PDO::FETCH_ASSOC);
            $next_number = $count_result['mbr_count'] + 1;
        }
    } else {
        // No members exist yet, start from 1
        $next_number = 1;
    }
    
    return 'MBR-' . str_pad($next_number, 4, '0', STR_PAD_LEFT);
}

// Function to generate easy-to-remember password (firstname + numeric ID)
function generateEasyPassword($firstname, $member_id) {
    // Extract numeric part from member ID (e.g., "0001" from "MBR-0001")
    $numeric_part = substr($member_id, 4); // Remove "MBR-" prefix
    
    // Convert firstname to lowercase and remove spaces
    $clean_firstname = strtolower(trim($firstname));
    
    // Combine firstname and numeric ID
    $password = $clean_firstname . $numeric_part;
    
    return $password;
}

// Function to handle file upload
function handleFileUpload($file, $uploadDir, $allowedTypes, $maxSize, $memberId, $documentType) {
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("File upload error: " . $file['error']);
    }
    
    // Check file size
    if ($file['size'] > $maxSize) {
        throw new Exception("File size too large. Maximum size is " . ($maxSize / 1024 / 1024) . "MB");
    }
    
    // Check file type
    $fileType = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileType, $allowedTypes)) {
        throw new Exception("Invalid file type. Allowed types: " . implode(', ', $allowedTypes));
    }
    
    // Generate unique filename
    $filename = $memberId . '_' . $documentType . '_' . time() . '.' . $fileType;
    $filepath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception("Failed to save uploaded file");
    }
    
    return $filename;
}

// Function to get member loans with payment progress
function getMemberLoansWithProgress($memberId) {
    try {
        $pdo = DB::pdo();
        
        // Get active loans
        $stmt = $pdo->prepare("
            SELECT * FROM loans 
            WHERE user_id = ? AND status IN ('approved', 'active', 'completed')
            ORDER BY created_at DESC
        ");
        $stmt->execute([$memberId]);
        $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get payments for each loan
        $loanPayments = [];
        foreach ($loans as $loan) {
            $stmt = $pdo->prepare("
                SELECT * FROM loan_payments 
                WHERE loan_id = ? AND status = 'confirmed'
                ORDER BY payment_date ASC
            ");
            $stmt->execute([$loan['id']]);
            $loanPayments[$loan['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return [
            'loans' => $loans,
            'payments' => $loanPayments
        ];
        
    } catch (Exception $e) {
        error_log("Error fetching loans with progress: " . $e->getMessage());
        return ['loans' => [], 'payments' => []];
    }
}

// Handle new member creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_member'])) {
    $firstname = trim($_POST['firstname']);
    $middlename = trim($_POST['middlename']);
    $lastname = trim($_POST['lastname']);
    $birthday = $_POST['birthday'];
    $email = trim($_POST['email']);
    $mobile = trim($_POST['mobile']);
    $nature_of_work = trim($_POST['nature_of_work']);
    $salary = floatval($_POST['salary']);
    $house_no = trim($_POST['house_no']);
    $street_village = trim($_POST['street_village']);
    $barangay = trim($_POST['barangay']);
    $municipality = trim($_POST['municipality']);
    $city = trim($_POST['city']);
    $postal_code = trim($_POST['postal_code']);
    $company_name = trim($_POST['company_name']);
    $company_address = trim($_POST['company_address']);
    $date_employed = $_POST['date_employed'];
    $id_type = $_POST['id_type'];
    
    // File upload configuration
    $uploadDir = '../uploads/members/';
    $allowedImageTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    $maxFileSize = 5 * 1024 * 1024; // 5MB
    
    // Validate required fields
    $errors = [];
    
    if (empty($firstname)) $errors[] = "First name is required";
    if (empty($lastname)) $errors[] = "Last name is required";
    if (empty($birthday)) $errors[] = "Birthday is required";
    if (empty($email)) $errors[] = "Email is required";
    if (empty($mobile)) $errors[] = "Mobile number is required";
    if (empty($nature_of_work)) $errors[] = "Nature of work is required";
    if ($salary <= 0) $errors[] = "Salary must be greater than 0";
    if (empty($house_no)) $errors[] = "House number is required";
    if (empty($barangay)) $errors[] = "Barangay is required";
    if (empty($municipality)) $errors[] = "Municipality is required";
    if (empty($city)) $errors[] = "City is required";
    if (empty($postal_code)) $errors[] = "Postal code is required";
    if (empty($company_name)) $errors[] = "Company name is required";
    if (empty($company_address)) $errors[] = "Company address is required";
    if (empty($date_employed)) $errors[] = "Date employed is required";
    if (empty($id_type)) $errors[] = "ID type is required";
    
    // Validate email format
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address";
    }
    
    // Validate birthday (must be at least 18 years old)
    if (!empty($birthday)) {
        $birthday_date = DateTime::createFromFormat('Y-m-d', $birthday);
        $today = new DateTime();
        $age = $today->diff($birthday_date)->y;
        
        if ($age < 18) {
            $errors[] = "Member must be at least 18 years old";
        }
    }
    
    // Validate postal code (must be 4 digits)
    if (!empty($postal_code) && (!is_numeric($postal_code) || strlen($postal_code) !== 4)) {
        $errors[] = "Postal code must be a 4-digit number";
    }
    
    // Validate date employed (cannot be in the future)
    if (!empty($date_employed)) {
        $date_employed_obj = DateTime::createFromFormat('Y-m-d', $date_employed);
        $today = new DateTime();
        
        if ($date_employed_obj > $today) {
            $errors[] = "Date employed cannot be in the future";
        }
    }
    
    // Validate file uploads
    $proof_of_id_file = null;
    $company_id_file = null;
    $coe_file = null;
    
    if (empty($errors)) {
        try {
            // Validate Proof of Identity upload
            if (!isset($_FILES['proof_of_id']) || $_FILES['proof_of_id']['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = "Proof of Identity document is required";
            } else {
                try {
                    $proof_of_id_file = handleFileUpload(
                        $_FILES['proof_of_id'], 
                        $uploadDir, 
                        $allowedImageTypes, 
                        $maxFileSize,
                        'temp', // Will be replaced with actual member ID
                        'proof_of_id'
                    );
                } catch (Exception $e) {
                    $errors[] = "Proof of Identity upload failed: " . $e->getMessage();
                }
            }
            
            // Validate Company ID upload
            if (!isset($_FILES['company_id']) || $_FILES['company_id']['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = "Company ID document is required";
            } else {
                try {
                    $company_id_file = handleFileUpload(
                        $_FILES['company_id'], 
                        $uploadDir, 
                        $allowedImageTypes, 
                        $maxFileSize,
                        'temp', // Will be replaced with actual member ID
                        'company_id'
                    );
                } catch (Exception $e) {
                    $errors[] = "Company ID upload failed: " . $e->getMessage();
                }
            }
            
            // Validate Certificate of Employment upload
            if (!isset($_FILES['coe']) || $_FILES['coe']['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = "Certificate of Employment document is required";
            } else {
                try {
                    $coe_file = handleFileUpload(
                        $_FILES['coe'], 
                        $uploadDir, 
                        $allowedImageTypes, 
                        $maxFileSize,
                        'temp', // Will be replaced with actual member ID
                        'coe'
                    );
                } catch (Exception $e) {
                    $errors[] = "Certificate of Employment upload failed: " . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            $errors[] = "File upload error: " . $e->getMessage();
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo = DB::pdo();
            
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT member_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = "Email address already exists. Please use a different email.";
            }
            
            if (empty($errors)) {
                // Generate member ID (MBR-0001 format)
                $member_id = generateMemberId($pdo);
                
                // Generate easy-to-remember password (firstname + numeric ID)
                $easy_password = generateEasyPassword($firstname, $member_id);
                $hashed_password = password_hash($easy_password, PASSWORD_DEFAULT);
                
                // Rename uploaded files with actual member ID
                if ($proof_of_id_file) {
                    $new_proof_of_id_file = $member_id . '_proof_of_id_' . time() . '.' . pathinfo($proof_of_id_file, PATHINFO_EXTENSION);
                    rename($uploadDir . $proof_of_id_file, $uploadDir . $new_proof_of_id_file);
                    $proof_of_id_file = $new_proof_of_id_file;
                }
                
                if ($company_id_file) {
                    $new_company_id_file = $member_id . '_company_id_' . time() . '.' . pathinfo($company_id_file, PATHINFO_EXTENSION);
                    rename($uploadDir . $company_id_file, $uploadDir . $new_company_id_file);
                    $company_id_file = $new_company_id_file;
                }
                
                if ($coe_file) {
                    $new_coe_file = $member_id . '_coe_' . time() . '.' . pathinfo($coe_file, PATHINFO_EXTENSION);
                    rename($uploadDir . $coe_file, $uploadDir . $new_coe_file);
                    $coe_file = $new_coe_file;
                }
                
                // Insert new member
                $stmt = $pdo->prepare("
                    INSERT INTO users (member_id, firstname, middlename, lastname, birthday, email, mobile, 
                                     nature_of_work, salary, password, role, status, requires_password_change,
                                     house_no, street_village, barangay, municipality, city, postal_code,
                                     company_name, company_address, date_employed, id_type, proof_of_id_file, company_id_file, coe_file) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'member', 'active', 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $member_id, 
                    $firstname, 
                    $middlename, 
                    $lastname, 
                    $birthday, 
                    $email, 
                    $mobile, 
                    $nature_of_work, 
                    $salary, 
                    $hashed_password,
                    $house_no,
                    $street_village,
                    $barangay,
                    $municipality,
                    $city,
                    $postal_code,
                    $company_name,
                    $company_address,
                    $date_employed,
                    $id_type,
                    $proof_of_id_file,
                    $company_id_file,
                    $coe_file
                ]);
                
                // Notify member about account creation
                Notifications::notifyUser(
                    $member_id,
                    'account_created',
                    'Welcome to iBarako Loan System',
                    'Your member account has been created successfully. Your temporary password is: ' . $easy_password . '. Please login and change your password immediately.',
                    null,
                    $member_id
                );
                
                $success = "New member added successfully!<br>
                           <strong>Member ID:</strong> $member_id<br>
                           <strong>Temporary password:</strong> $easy_password<br>
                           <strong>Login format:</strong> First name + numeric ID (e.g., john0001)<br>
                           The member will be required to change their password on first login.";
                
                // Clear form fields
                $_POST = array();
                $_FILES = array();
            }
            
        } catch (Exception $e) {
            // Clean up uploaded files if database insertion fails
            if ($proof_of_id_file && file_exists($uploadDir . $proof_of_id_file)) {
                unlink($uploadDir . $proof_of_id_file);
            }
            if ($company_id_file && file_exists($uploadDir . $company_id_file)) {
                unlink($uploadDir . $company_id_file);
            }
            if ($coe_file && file_exists($uploadDir . $coe_file)) {
                unlink($uploadDir . $coe_file);
            }
            
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $error = "Email already exists. Please use a different email address.";
            } else {
                $error = "Error adding new member: " . $e->getMessage();
            }
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Handle member status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $member_id = $_POST['member_id'];
    $new_status = $_POST['status'];
    
    try {
        $pdo = DB::pdo();
        
        // FIXED: Use member_id instead of id in WHERE clause
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE member_id = ?");
        $stmt->execute([$new_status, $member_id]);
        
        if ($stmt->rowCount() > 0) {
            $success = "Member status updated successfully!";
        } else {
            $error = "No changes made or member not found.";
        }
        
    } catch (Exception $e) {
        $error = "Error updating member status: " . $e->getMessage();
    }
}

// Handle add contribution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_contribution'])) {
    $member_id = $_POST['member_id'];
    $amount = floatval($_POST['amount']);
    $contribution_type = $_POST['contribution_type'];
    $remarks = trim($_POST['remarks']);
    
    try {
        $pdo = DB::pdo();
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Get member details
        $stmt = $pdo->prepare("SELECT firstname, lastname, member_id FROM users WHERE member_id = ?");
        $stmt->execute([$member_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$member) {
            throw new Exception("Member not found.");
        }
        
        // Generate receipt number for contribution
        $receipt_number = 'CTB-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Insert contribution record - using user_id as that's the column name in your table
        $stmt = $pdo->prepare("
            INSERT INTO contributions (user_id, member_id, amount, contrib_date, note, created_at, status) 
            VALUES (?, ?, ?, CURDATE(), ?, NOW(), 'confirmed')
        ");
        $stmt->execute([
            $member_id, // user_id
            $member_id, // member_id (if you added the column)
            $amount, 
            $remarks
        ]);
        
        $contribution_id = $pdo->lastInsertId();
        
        // Update member's total contributions in users table if the column exists
        // First check if total_contributions column exists
        $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'total_contributions'");
        $stmt->execute();
        $has_total_contributions = $stmt->fetch();
        
        if ($has_total_contributions) {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET total_contributions = COALESCE(total_contributions, 0) + ? 
                WHERE member_id = ?
            ");
            $stmt->execute([$amount, $member_id]);
        }
        
        // Commit transaction
        $pdo->commit();
        
        // Store receipt data in session for printing
        $_SESSION['last_contribution_receipt'] = [
            'receipt_number' => $receipt_number,
            'contribution_id' => $contribution_id,
            'member_name' => $member['firstname'] . ' ' . $member['lastname'],
            'member_id' => $member['member_id'],
            'amount' => $amount,
            'contribution_type' => $contribution_type,
            'remarks' => $remarks,
            'date' => date('Y-m-d'),
            'processed_by' => $_SESSION['user']['firstname'] . ' ' . $_SESSION['user']['lastname']
        ];
        
        // Notify member
        if (class_exists('Notifications')) {
            Notifications::notifyUser(
                $member_id,
                'contribution_added',
                'Contribution Added',
                'A contribution of ₱' . number_format($amount, 2) . ' has been added to your account. Receipt No: ' . $receipt_number,
                null,
                $member_id
            );
        }
        
        $success = "Contribution added successfully! Receipt No: <strong>" . $receipt_number . "</strong>";
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Error adding contribution: " . $e->getMessage();
    }
}

// Handle process loan payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    $member_id = $_POST['member_id'];
    $loan_id = $_POST['loan_id'];
    $payment_amount = floatval($_POST['payment_amount']);
    $payment_date = $_POST['payment_date'];
    $payment_method = 'cash'; // Force cash only
    $payment_remarks = trim($_POST['payment_remarks']);
    
    try {
        $pdo = DB::pdo();
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Get loan details using correct column names
        $stmt = $pdo->prepare("
            SELECT *, 
                principal as loan_amount,
                COALESCE(remaining_balance, balance, principal) as remaining_balance
            FROM loans 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$loan_id, $member_id]);
        $loan = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$loan) {
            throw new Exception("Loan not found.");
        }
        
        // Get member details
        $stmt = $pdo->prepare("SELECT firstname, lastname, member_id FROM users WHERE member_id = ?");
        $stmt->execute([$member_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Validate payment amount doesn't exceed remaining balance
        $remaining_balance = $loan['remaining_balance'];
        if ($payment_amount > $remaining_balance) {
            throw new Exception("Payment amount cannot exceed remaining balance of ₱" . number_format($remaining_balance, 2));
        }
        
        // Generate receipt number
        $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Insert payment record with receipt number
        $stmt = $pdo->prepare("
            INSERT INTO loan_payments (loan_id, member_id, amount, payment_date, payment_method, remarks, processed_by, receipt_number) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $loan_id,
            $member_id,
            $payment_amount,
            $payment_date,
            $payment_method,
            $payment_remarks,
            $_SESSION['user']['member_id'],
            $receipt_number
        ]);
        
        $payment_id = $pdo->lastInsertId();
        
        // Update loan balance
        $new_balance = $remaining_balance - $payment_amount;
        
        // Update the remaining_balance column
        $stmt = $pdo->prepare("UPDATE loans SET remaining_balance = ? WHERE id = ?");
        $stmt->execute([$new_balance, $loan_id]);
        
        // Also update balance column if it exists
        $stmt = $pdo->prepare("UPDATE loans SET balance = ? WHERE id = ?");
        $stmt->execute([$new_balance, $loan_id]);
        
        // Check if loan is fully paid
        if ($new_balance <= 0) {
            $stmt = $pdo->prepare("UPDATE loans SET status = 'completed' WHERE id = ?");
            $stmt->execute([$loan_id]);
        }
        
        // Commit transaction
        $pdo->commit();
        
        // Store receipt data in session for printing
        $_SESSION['last_receipt'] = [
            'receipt_number' => $receipt_number,
            'payment_id' => $payment_id,
            'member_name' => $member['firstname'] . ' ' . $member['lastname'],
            'member_id' => $member['member_id'],
            'loan_id' => $loan_id,
            'payment_amount' => $payment_amount,
            'payment_date' => $payment_date,
            'previous_balance' => $remaining_balance,
            'new_balance' => $new_balance,
            'payment_method' => $payment_method,
            'processed_by' => $_SESSION['user']['firstname'] . ' ' . $_SESSION['user']['lastname']
        ];
        
        // Notify member
        if (class_exists('Notifications')) {
            Notifications::notifyUser(
                $member_id,
                'payment_processed',
                'Loan Payment Processed',
                'Your cash payment of ₱' . number_format($payment_amount, 2) . ' has been processed successfully. Receipt No: ' . $receipt_number,
                null,
                $member_id
            );
        }
        
        $success = "Loan payment processed successfully! Receipt No: <strong>" . $receipt_number . "</strong>";
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Error processing payment: " . $e->getMessage();
    }
}

// Search functionality
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Pagination setup
$members_per_page = 7;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $members_per_page;

// Build query for members with search and filters
try {
    $pdo = DB::pdo();
    $query = "SELECT * FROM users WHERE role = 'member'";
    $count_query = "SELECT COUNT(*) as total FROM users WHERE role = 'member'";
    $params = [];
    $count_params = [];
    
    if (!empty($search)) {
        $query .= " AND (member_id LIKE ? OR firstname LIKE ? OR lastname LIKE ? OR email LIKE ? OR mobile LIKE ?)";
        $count_query .= " AND (member_id LIKE ? OR firstname LIKE ? OR lastname LIKE ? OR email LIKE ? OR mobile LIKE ?)";
        $search_term = "%$search%";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
        $count_params = [$search_term, $search_term, $search_term, $search_term, $search_term];
    }
    
    if (!empty($status_filter)) {
        $query .= " AND status = ?";
        $count_query .= " AND status = ?";
        $params[] = $status_filter;
        $count_params[] = $status_filter;
    }
    
    $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $params[] = $members_per_page;
    $params[] = $offset;
    
    // Get total count
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($count_params);
    $total_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_members = $total_result['total'];
    $total_pages = ceil($total_members / $members_per_page);
    
    // Get members for current page
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $members = $stmt->fetchAll();
    
} catch (Exception $e) {
    $error = "Error fetching members: " . $e->getMessage();
    $members = [];
    $total_members = 0;
    $total_pages = 1;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Members - iBarako Loan System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
		/* Import modern fonts */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
		body {
			font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
		}
        .sidebar {
            background: linear-gradient(180deg, #e53e3e, #1a365d, #1a365d, #1a365d);
            min-height: 100vh;
            width: 250px;
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: all 0.3s;
			font-family: 'Inter', sans-serif;
        }
		.alert {
			font-size: 0.8rem; 
			padding: 0.5rem 1rem; 
		}
		.alert strong {
			font-size: 0.9rem;
		}
        .main-content {
            margin-left: 250px;
            width: calc(100% - 250px);
            transition: all 0.3s;
			padding-bottom: 2rem;
        }
        .sidebar-brand {
            padding: 1.5rem 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 0.75rem 1rem;
            border-left: 3px solid transparent;
            transition: all 0.3s;
            margin: 0.1rem 0;
        }
        .nav-link:hover, .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.1);
            border-left-color: #3b82f6;
        }
        .nav-link i {
            width: 20px;
            text-align: center;
            margin-right: 0.5rem;
        }
        .badge-notification {
            font-size: 0.7em;
            padding: 0.2em 0.4em;
        }
        .member-form {
            display: none;
            transition: all 0.3s ease-in-out;
        }
        .member-form.show {
            display: block;
        }
		.members-table {
			font-size: 14px;
		}
		.members-table th {
			font-weight: 600;
			background-color: #f8fafc;
		}
		.members-table td {
			vertical-align: middle;
		}
		.members-table .btn-success {
			padding: 0.3rem 0.25rem;
			font-size: 0.8rem;
		}
		.btn.btn-sm.btn-success.ms-1 {
			padding: 0.3rem 0.3rem;
			font-size: 0.7rem;
		}
		.btn-group .dropdown-menu {
			max-height: none !important;
			font-size: 0.8rem;
			min-width: 180px;
			box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
			border: 1px solid rgba(0, 0, 0, 0.1);
		}
		.btn-group .dropdown-item {
			padding: 0.35rem 0.8rem;
			font-size: 0.8rem;
			display: flex;
			align-items: center;
			transition: all 0.2s ease;
		}
		.btn-group .dropdown-item:hover {
			background-color: #f8f9fa;
		}
		.btn-group .dropdown-item i {
			font-size: 0.7rem;
			width: 16px;
			margin-right: 0.5rem;
		}
		.btn-group .dropdown-divider {
			margin: 0.25rem 0;
		}
		.btn-group .btn-sm {
			padding: 0.25rem 0.5rem;
			font-size: 0.75rem;
		}
		.btn-group .btn-sm i {
		font-size: 0.8rem;
		}
        .salary-input {
            position: relative;
        }
        .salary-input::before {
            content: "₱";
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-weight: bold;
        }
        .salary-input input {
            padding-left: 25px;
        }
        .pagination-container {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
        }
        .page-info {
            text-align: center;
            margin-bottom: 1rem;
            color: #6c757d;
            font-size: 0.8rem;
        }
        .form-required::after {
            content: " *";
            color: #dc3545;
        }
        .file-upload-box {
            border: 2px dashed #dee2e6;
            border-radius: 0.375rem;
            padding: 1.5rem;
            text-align: center;
            background-color: #f8f9fa;
            transition: all 0.3s ease;
        }
        .file-upload-box:hover {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }
        .file-upload-box.has-file {
            border-color: #198754;
            background-color: #d1e7dd;
        }
        .file-preview {
            max-width: 200px;
            max-height: 150px;
            margin-top: 10px;
        }
        .file-info {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 5px;
        }
        .loan-progress {
            min-width: 120px;
        }
        .progress {
            height: 8px;
        }
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            .main-content {
                margin-left: 0;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Sidebar -->
			<div class="sidebar">
				<div class="sidebar-brand text-white">
					<!-- Logo Section -->
					<div class="text-center mb-3">
						<img src="../ibarako_logov2.PNG" alt="iBarako Logo" class="logo" style="max-height: 45px;">
					</div>
					<!-- Text Brand -->
					<small class="text-center d-block">Admin Panel</small>
				</div>
                <nav class="nav flex-column mt-3">
                    <a class="nav-link" href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>Dashboard
                    </a>
                    <a class="nav-link active" href="members.php">
                        <i class="fas fa-users"></i>Manage Members
                    </a>
                    <a class="nav-link" href="loans.php">
                        <i class="fas fa-file-invoice-dollar"></i>Loan Applications
                    </a>
                    <a class="nav-link" href="payments.php">
                        <i class="fas fa-money-check"></i>Payment Verification
                    </a>
					<a class="nav-link" href="contributions.php">
                        <i class="fas fa-chart-line"></i>Contributions
                    </a>
                    <a class="nav-link" href="update_requests.php">
                        <i class="fas fa-edit"></i>Update Requests
                    </a>
                    <a class="nav-link" href="settings.php">
                        <i class="fas fa-cog"></i>Settings
                    </a>
                    <div class="mt-auto">
                        <a class="nav-link text-warning" href="../logout.php">
                            <i class="fas fa-sign-out-alt"></i>Logout
                        </a>
                    </div>
                </nav>
            </div>

            <!-- Main Content -->
            <div class="main-content">
                <nav class="navbar navbar-light bg-white border-bottom shadow-sm">
                    <div class="container-fluid">
                        <h4 class="mt-2 mb-2 text-dark">Manage Members</h4>
                        <span class="badge" style="background-color: #1e3a8a; color: white;">Admin</span>
                    </div>
                </nav>

                <div class="container-fluid mt-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= $success ?></div>
                    <?php endif; ?>

                    <!-- Add New Member Button -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="mb-0">Member Management</h5>
                        <button class="btn btn-primary" id="showMemberFormBtn">
                            <i class="fas fa-plus-circle me-2"></i>Add New Member
                        </button>
                    </div>

                    <!-- Search Form -->
                    <div class="card mb-4">
                        <div class="card-header" style="background-color: #1e3a8a; color: white;">
                            <h6 class="mb-0"><i class="fas fa-search me-2"></i>Search Members</h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <div class="col-md-8">
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="search" placeholder="Search by member ID, name, email, or mobile..." value="<?= htmlspecialchars($search) ?>">
                                        <button class="btn btn-outline-primary" type="submit">
                                            <i class="fas fa-search"></i>
                                        </button>
                                        <?php if (!empty($search)): ?>
                                            <a href="members.php" class="btn btn-outline-secondary">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select" name="status" onchange="this.form.submit()">
                                        <option value="">All Statuses</option>
                                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Add Member Form -->
                    <div class="card mb-4 member-form" id="memberFormContainer">
                        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Add New Member</h5>
                            <button type="button" class="btn btn-sm btn-light" id="hideMemberFormBtn">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="memberForm" enctype="multipart/form-data">
                                <input type="hidden" name="add_member" value="1">
                                
                                <h6 class="border-bottom pb-2 mb-3">Personal Information</h6>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label form-required">First Name</label>
                                            <input type="text" class="form-control" name="firstname" required 
                                                   value="<?= $_POST['firstname'] ?? '' ?>"
                                                   placeholder="Enter first name">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Middle Name</label>
                                            <input type="text" class="form-control" name="middlename"
                                                   value="<?= $_POST['middlename'] ?? '' ?>"
                                                   placeholder="Enter middle name">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Last Name</label>
                                            <input type="text" class="form-control" name="lastname" required 
                                                   value="<?= $_POST['lastname'] ?? '' ?>"
                                                   placeholder="Enter last name">
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Birthday</label>
                                            <input type="date" class="form-control" name="birthday" required 
                                                   value="<?= $_POST['birthday'] ?? '' ?>"
                                                   max="<?= date('Y-m-d', strtotime('-18 years')) ?>">
                                            <small class="text-muted">Must be at least 18 years old</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Mobile Number</label>
                                            <input type="text" class="form-control" name="mobile" required 
                                                   value="<?= $_POST['mobile'] ?? '' ?>"
                                                   placeholder="e.g., 09123456789">
                                        </div>
                                    </div>
                                </div>

                                <h6 class="border-bottom pb-2 mb-3 mt-4">Address Information</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label form-required">House No./Building</label>
                                            <input type="text" class="form-control" name="house_no" required 
                                                   value="<?= $_POST['house_no'] ?? '' ?>"
                                                   placeholder="e.g., 123, Unit 4B">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Street/Village/Subdivision</label>
                                            <input type="text" class="form-control" name="street_village"
                                                   value="<?= $_POST['street_village'] ?? '' ?>"
                                                   placeholder="e.g., Main Street, Greenhills Subd.">
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Barangay</label>
                                            <input type="text" class="form-control" name="barangay" required 
                                                   value="<?= $_POST['barangay'] ?? '' ?>"
                                                   placeholder="e.g., Barangay 1">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Municipality</label>
                                            <input type="text" class="form-control" name="municipality" required 
                                                   value="<?= $_POST['municipality'] ?? '' ?>"
                                                   placeholder="e.g., Poblacion">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label form-required">City</label>
                                            <input type="text" class="form-control" name="city" required 
                                                   value="<?= $_POST['city'] ?? '' ?>"
                                                   placeholder="e.g., Manila">
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Postal Code</label>
                                            <input type="text" class="form-control" name="postal_code" required 
                                                   value="<?= $_POST['postal_code'] ?? '' ?>"
                                                   placeholder="e.g., 1000" maxlength="4"
                                                   pattern="[0-9]{4}" title="Postal code must be 4 digits">
                                            <small class="text-muted">4-digit postal code</small>
                                        </div>
                                    </div>
                                </div>

                                <h6 class="border-bottom pb-2 mb-3 mt-4">Employment Information</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Nature of Work</label>
                                            <select class="form-control" name="nature_of_work" required>
                                                <option value="">Select Nature of Work</option>
                                                <option value="Government Employee" <?= ($_POST['nature_of_work'] ?? '') == 'Government Employee' ? 'selected' : '' ?>>Government Employee</option>
                                                <option value="Private Employee" <?= ($_POST['nature_of_work'] ?? '') == 'Private Employee' ? 'selected' : '' ?>>Private Employee</option>
                                                <option value="Self-Employed" <?= ($_POST['nature_of_work'] ?? '') == 'Self-Employed' ? 'selected' : '' ?>>Self-Employed</option>
                                                <option value="Business Owner" <?= ($_POST['nature_of_work'] ?? '') == 'Business Owner' ? 'selected' : '' ?>>Business Owner</option>
                                                <option value="Freelancer" <?= ($_POST['nature_of_work'] ?? '') == 'Freelancer' ? 'selected' : '' ?>>Freelancer</option>
                                                <option value="OFW" <?= ($_POST['nature_of_work'] ?? '') == 'OFW' ? 'selected' : '' ?>>OFW</option>
                                                <option value="Retired" <?= ($_POST['nature_of_work'] ?? '') == 'Retired' ? 'selected' : '' ?>>Retired</option>
                                                <option value="Student" <?= ($_POST['nature_of_work'] ?? '') == 'Student' ? 'selected' : '' ?>>Student</option>
                                                <option value="Unemployed" <?= ($_POST['nature_of_work'] ?? '') == 'Unemployed' ? 'selected' : '' ?>>Unemployed</option>
                                                <option value="Other" <?= ($_POST['nature_of_work'] ?? '') == 'Other' ? 'selected' : '' ?>>Other</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Monthly Salary (₱)</label>
                                            <div class="salary-input">
                                                <input type="number" class="form-control" name="salary" required 
                                                       value="<?= $_POST['salary'] ?? '' ?>"
                                                       min="1" step="0.01" placeholder="0.00">
                                            </div>
                                            <small class="text-muted">Enter monthly gross salary</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Company Name</label>
                                            <input type="text" class="form-control" name="company_name" required 
                                                   value="<?= $_POST['company_name'] ?? '' ?>"
                                                   placeholder="Enter company name">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Date Employed</label>
                                            <input type="date" class="form-control" name="date_employed" required 
                                                   value="<?= $_POST['date_employed'] ?? '' ?>"
                                                   max="<?= date('Y-m-d') ?>">
                                            <small class="text-muted">Cannot be a future date</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Company Address</label>
                                            <textarea class="form-control" name="company_address" required 
                                                      placeholder="Enter complete company address" 
                                                      rows="3"><?= $_POST['company_address'] ?? '' ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <h6 class="border-bottom pb-2 mb-3 mt-4">Document Uploads</h6>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label form-required">ID Type</label>
                                            <select class="form-control" name="id_type" required>
                                                <option value="">Select ID Type</option>
                                                <option value="Passport" <?= ($_POST['id_type'] ?? '') == 'Passport' ? 'selected' : '' ?>>Passport</option>
                                                <option value="National ID" <?= ($_POST['id_type'] ?? '') == 'National ID' ? 'selected' : '' ?>>National ID</option>
                                                <option value="UMID" <?= ($_POST['id_type'] ?? '') == 'UMID' ? 'selected' : '' ?>>UMID</option>
                                                <option value="Driver's License" <?= ($_POST['id_type'] ?? '') == 'Driver\'s License' ? 'selected' : '' ?>>Driver's License</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Proof of Identity</label>
                                            <div class="file-upload-box" id="proofOfIdBox">
                                                <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-3"></i>
                                                <p class="mb-2">Upload Proof of Identity</p>
                                                <p class="small text-muted mb-3">(Passport/National ID/UMID/Driver's License)</p>
                                                <input type="file" class="form-control d-none" name="proof_of_id" id="proofOfId" accept=".jpg,.jpeg,.png,.gif,.pdf" required>
                                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('proofOfId').click()">
                                                    Choose File
                                                </button>
                                                <div class="file-info" id="proofOfIdInfo"></div>
                                                <div id="proofOfIdPreview"></div>
                                            </div>
                                            <small class="text-muted">Accepted formats: JPG, JPEG, PNG, GIF, PDF (Max: 5MB)</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Company ID</label>
                                            <div class="file-upload-box" id="companyIdBox">
                                                <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-3"></i>
                                                <p class="mb-2">Upload Company ID</p>
                                                <p class="small text-muted mb-3">(Front and back if applicable)</p>
                                                <input type="file" class="form-control d-none" name="company_id" id="companyId" accept=".jpg,.jpeg,.png,.gif,.pdf" required>
                                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('companyId').click()">
                                                    Choose File
                                                </button>
                                                <div class="file-info" id="companyIdInfo"></div>
                                                <div id="companyIdPreview"></div>
                                            </div>
                                            <small class="text-muted">Accepted formats: JPG, JPEG, PNG, GIF, PDF (Max: 5MB)</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Certificate of Employment</label>
                                            <div class="file-upload-box" id="coeBox">
                                                <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-3"></i>
                                                <p class="mb-2">Upload COE</p>
                                                <p class="small text-muted mb-3">(Certificate of Employment)</p>
                                                <input type="file" class="form-control d-none" name="coe" id="coe" accept=".jpg,.jpeg,.png,.gif,.pdf" required>
                                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('coe').click()">
                                                    Choose File
                                                </button>
                                                <div class="file-info" id="coeInfo"></div>
                                                <div id="coePreview"></div>
                                            </div>
                                            <small class="text-muted">Accepted formats: JPG, JPEG, PNG, GIF, PDF (Max: 5MB)</small>
                                        </div>
                                    </div>
                                </div>

                                <h6 class="border-bottom pb-2 mb-3 mt-4">Account Information</h6>
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Email Address</label>
                                            <input type="email" class="form-control" name="email" required 
                                                   value="<?= $_POST['email'] ?? '' ?>"
                                                   placeholder="Enter email address">
                                        </div>
                                    </div>
                                </div>

                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Note:</strong> The system will automatically generate:<br>
                                    - <strong>Member ID:</strong> MBR-0001 format<br>
                                    - <strong>Temporary password:</strong> First name + numeric ID (e.g., john0001)<br>
                                    Member will be required to change password on first login and will be notified via email.
                                </div>

                                <button type="submit" class="btn btn-success btn">
                                    <i class="fas fa-save me-2"></i>Add Member
                                </button>
                                <button type="button" class="btn btn-secondary btn" id="cancelBtn">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Members List -->
                    <div class="card">
                        <div class="card-header" style="background-color: #3b82f6; color: white;">
                            <h5 class="mb-0"><i class="fas fa-users me-2"></i>Member List</h5>
                        </div>
                        <div class="card-body">
                            <!-- Page Info -->
                            <div class="page-info">
                                Showing <?= count($members) ?> of <?= $total_members ?> members
                                <?php if ($total_pages > 1): ?>
                                    - Page <?= $current_page ?> of <?= $total_pages ?>
                                <?php endif; ?>
                                <?php if (!empty($search)): ?>
                                    - Search: "<?= htmlspecialchars($search) ?>"
                                <?php endif; ?>
                            </div>

                            <?php if (empty($members)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No members found.</p>
                                    <?php if (!empty($search) || !empty($status_filter)): ?>
                                        <a href="members.php" class="btn btn-primary">Show All Members</a>
                                    <?php else: ?>
                                        <button class="btn btn-primary" id="showMemberFormBtn2">
                                            <i class="fas fa-plus-circle me-2"></i>Add Your First Member
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped members-table">
                                        <thead>
                                            <tr>
                                                <th>Member ID</th>
                                                <th>Name</th>
                                                <th>Age</th>
                                                <th>Email</th>
                                                <th>Mobile</th>
                                                <th>Work</th>
                                                <th>Salary</th>
                                                <th>Status</th>
                                                <th>Join Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($members as $member): ?>
                                            <tr>
                                                <td><strong><?= $member['member_id'] ?></strong></td>
                                                <td>
                                                    <strong><?= $member['firstname'] . ' ' . $member['lastname'] ?></strong>
                                                    <?php if (!empty($member['middlename'])): ?>
                                                        <br><small class="text-muted"><?= $member['middlename'] ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= calculateAge($member['birthday']) ?></td>
                                                <td><?= $member['email'] ?></td>
                                                <td><?= $member['mobile'] ?? 'N/A' ?></td>
                                                <td><?= $member['nature_of_work'] ?? 'N/A' ?></td>
                                                <td>₱<?= number_format($member['salary'] ?? 0, 2) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= 
                                                        $member['status'] === 'active' ? 'success' : 
                                                        ($member['status'] === 'pending' ? 'warning' : 'danger') 
                                                    ?>">
                                                        <?= ucfirst($member['status']) ?>
                                                    </span>
                                                    <?php if ($member['requires_password_change']): ?>
                                                        <span class="badge bg-warning" title="Requires password change">
                                                            <i class="fas fa-key"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= date('M j, Y', strtotime($member['created_at'])) ?></td>
                                                <td>
                                                    <div class="btn-group">
                                                        <!-- Status Update Form -->
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="member_id" value="<?= $member['member_id'] ?>">
                                                            <input type="hidden" name="update_status" value="1">
                                                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                                                <option value="pending" <?= $member['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                                <option value="active" <?= $member['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                                                <option value="inactive" <?= $member['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                                            </select>
                                                        </form>
                                                        
                                                        <!-- Action Buttons Dropdown -->
                                                        <div class="btn-group">
                                                            <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                                                <i class="fas fa-cog"></i>
                                                            </button>
                                                            <ul class="dropdown-menu">
                                                                <!-- View Profile -->
                                                                <li>
                                                                    <a class="dropdown-item" href="#" onclick="viewMemberProfile('<?= $member['member_id'] ?>')">
                                                                        <i class="fas fa-eye me-2"></i>View Profile
                                                                    </a>
                                                                </li>
                                                                <!-- Add Contribution -->
                                                                <li>
                                                                    <a class="dropdown-item" href="#" onclick="addContribution('<?= $member['member_id'] ?>', '<?= $member['firstname'] . ' ' . $member['lastname'] ?>')">
                                                                        <i class="fas fa-plus-circle me-2"></i>Add Contribution
                                                                    </a>
                                                                </li>
                                                                <!-- Process Loan Payment -->
                                                                <li>
                                                                    <a class="dropdown-item" href="#" onclick="processLoanPayment('<?= $member['member_id'] ?>', '<?= $member['firstname'] . ' ' . $member['lastname'] ?>')">
                                                                        <i class="fas fa-money-bill-wave me-2"></i>Process Payment
                                                                    </a>
                                                                </li>
                                                                <li><hr class="dropdown-divider"></li>
                                                                <!-- Apply Loan -->
                                                                <li>
                                                                    <a class="dropdown-item" href="apply_member_loan.php?member_id=<?= $member['member_id'] ?>">
                                                                        <i class="fas fa-file-invoice-dollar me-2"></i>Apply Loan
                                                                    </a>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                <div class="pagination-container">
                                    <nav aria-label="Member pagination">
                                        <ul class="pagination">
                                            <!-- Previous Page -->
                                            <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                                                <a class="page-link" href="?page=<?= $current_page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>" aria-label="Previous">
                                                    <span aria-hidden="true">&laquo; Previous</span>
                                                </a>
                                            </li>
                                            
                                            <!-- Page Numbers -->
                                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                                <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>"><?= $i ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <!-- Next Page -->
                                            <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                                                <a class="page-link" href="?page=<?= $current_page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>" aria-label="Next">
                                                    <span aria-hidden="true">Next &raquo;</span>
                                                </a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Profile Modal -->
    <div class="modal fade" id="viewProfileModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Member Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="profileModalContent">
                    <!-- Profile content will be loaded here via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Contribution Modal -->
    <div class="modal fade" id="addContributionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Add Contribution</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="contributionForm">
                    <div class="modal-body">
                        <input type="hidden" name="member_id" id="contribution_member_id">
                        <input type="hidden" name="add_contribution" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label">Member</label>
                            <input type="text" class="form-control" id="contribution_member_name" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label form-required">Amount (₱)</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" class="form-control" name="amount" required min="1" step="0.01" placeholder="0.00">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label form-required">Contribution Type</label>
                            <select class="form-select" name="contribution_type" required>
                                <option value="">Select Type</option>
                                <option value="monthly">Monthly Contribution</option>
                                <option value="voluntary">Voluntary Contribution</option>
                                <option value="special">Special Assessment</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="3" placeholder="Optional remarks..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Add Contribution</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Process Loan Payment Modal -->
    <div class="modal fade" id="processPaymentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title">Process Loan Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="paymentForm">
                    <div class="modal-body">
                        <input type="hidden" name="member_id" id="payment_member_id">
                        <input type="hidden" name="process_payment" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label">Member</label>
                            <input type="text" class="form-control" id="payment_member_name" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label form-required">Select Loan</label>
                            <select class="form-select" name="loan_id" id="loanSelect" required>
                                <option value="">Select Loan</option>
                                <!-- Loans will be populated via AJAX -->
                            </select>
                        </div>
                        
                        <div id="loanDetails" class="mb-3 p-3 border rounded" style="display: none;">
                            <!-- Loan details will be shown here -->
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label form-required">Payment Amount (₱)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" class="form-control" name="payment_amount" required min="1" step="0.01" placeholder="0.00" id="paymentAmount">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label form-required">Payment Date</label>
                                    <input type="date" class="form-control" name="payment_date" required value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <input type="text" class="form-control" value="Cash" readonly style="background-color: #e9ecef;">
                            <input type="hidden" name="payment_method" value="cash">
                            <small class="text-muted">Only cash payments are accepted at the moment</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="payment_remarks" rows="2" placeholder="Optional payment remarks..."></textarea>
                        </div>

                        <!-- Receipt Preview -->
                        <div id="receiptPreview" class="border p-3 mt-3" style="display: none; background-color: #f8f9fa;">
                            <h6 class="text-center mb-3">Payment Receipt Preview</h6>
                            <div id="receiptContent">
                                <!-- Receipt content will be generated here -->
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Process Payment</button>
                        <button type="button" class="btn btn-success" id="printReceiptBtn" style="display: none;">
                            <i class="fas fa-print me-2"></i>Print Receipt
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Receipt Modal for Printing -->
    <div class="modal fade" id="receiptModal" tabindex="-1">
        <div class="modal-dialog modal-md">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Payment Receipt</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="printableReceipt">
                    <!-- Printable receipt content -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" onclick="printReceipt()">
                        <i class="fas fa-print me-2"></i>Print Receipt
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Contribution Receipt Modal for Printing -->
    <div class="modal fade" id="contributionReceiptModal" tabindex="-1">
        <div class="modal-dialog modal-md">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Contribution Receipt</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="printableContributionReceipt">
                    <!-- Printable contribution receipt content -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" onclick="printContributionReceipt()">
                        <i class="fas fa-print me-2"></i>Print Receipt
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show/hide member form functionality
        document.getElementById('showMemberFormBtn').addEventListener('click', function() {
            document.getElementById('memberFormContainer').classList.add('show');
        });

        document.getElementById('showMemberFormBtn2').addEventListener('click', function() {
            document.getElementById('memberFormContainer').classList.add('show');
        });

        document.getElementById('hideMemberFormBtn').addEventListener('click', function() {
            document.getElementById('memberFormContainer').classList.remove('show');
            resetMemberForm();
        });

        document.getElementById('cancelBtn').addEventListener('click', function() {
            document.getElementById('memberFormContainer').classList.remove('show');
            resetMemberForm();
        });

        function resetMemberForm() {
            document.getElementById('memberForm').reset();
            resetFileUpload('proofOfId');
            resetFileUpload('companyId');
            resetFileUpload('coe');
        }

        // Set maximum date for birthday (18 years ago) and date employed (today)
        document.addEventListener('DOMContentLoaded', function() {
            const birthdayField = document.querySelector('input[name="birthday"]');
            const dateEmployedField = document.querySelector('input[name="date_employed"]');
            
            // Set max date for birthday (18 years ago)
            const maxBirthdayDate = new Date();
            maxBirthdayDate.setFullYear(maxBirthdayDate.getFullYear() - 18);
            const maxBirthdayString = maxBirthdayDate.toISOString().split('T')[0];
            birthdayField.max = maxBirthdayString;
            
            // Set max date for date employed (today)
            const today = new Date().toISOString().split('T')[0];
            dateEmployedField.max = today;
        });

        // Show member form if there are validation errors
        <?php if ($error && isset($_POST['add_member'])): ?>
            document.getElementById('memberFormContainer').classList.add('show');
        <?php endif; ?>

        // Postal code validation
        document.querySelector('input[name="postal_code"]').addEventListener('input', function(e) {
            // Remove non-numeric characters
            this.value = this.value.replace(/[^0-9]/g, '');
            
            // Limit to 4 digits
            if (this.value.length > 4) {
                this.value = this.value.slice(0, 4);
            }
        });

        // File upload functionality
        function setupFileUpload(inputId, boxId, infoId, previewId) {
            const input = document.getElementById(inputId);
            const box = document.getElementById(boxId);
            const info = document.getElementById(infoId);
            const preview = document.getElementById(previewId);

            input.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    // Update file info
                    const fileSize = (file.size / 1024 / 1024).toFixed(2);
                    info.innerHTML = `<strong>Selected:</strong> ${file.name}<br><strong>Size:</strong> ${fileSize} MB`;
                    
                    // Update box styling
                    box.classList.add('has-file');
                    
                    // Show preview for images
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            preview.innerHTML = `<img src="${e.target.result}" class="file-preview img-thumbnail" alt="Preview">`;
                        };
                        reader.readAsDataURL(file);
                    } else if (file.type === 'application/pdf') {
                        preview.innerHTML = `<div class="text-center"><i class="fas fa-file-pdf fa-3x text-danger"></i><br><small>PDF Document</small></div>`;
                    } else {
                        preview.innerHTML = `<div class="text-center"><i class="fas fa-file fa-3x text-muted"></i><br><small>${file.name}</small></div>`;
                    }
                }
            });
        }

        function resetFileUpload(inputId) {
            const input = document.getElementById(inputId);
            const box = document.getElementById(inputId + 'Box');
            const info = document.getElementById(inputId + 'Info');
            const preview = document.getElementById(inputId + 'Preview');
            
            input.value = '';
            info.innerHTML = '';
            preview.innerHTML = '';
            box.classList.remove('has-file');
        }

        // Initialize file uploads
        setupFileUpload('proofOfId', 'proofOfIdBox', 'proofOfIdInfo', 'proofOfIdPreview');
        setupFileUpload('companyId', 'companyIdBox', 'companyIdInfo', 'companyIdPreview');
        setupFileUpload('coe', 'coeBox', 'coeInfo', 'coePreview');

        // File size validation
        document.getElementById('memberForm').addEventListener('submit', function(e) {
            const proofOfId = document.getElementById('proofOfId').files[0];
            const companyId = document.getElementById('companyId').files[0];
            const coe = document.getElementById('coe').files[0];
            const maxSize = 5 * 1024 * 1024; // 5MB

            if (proofOfId && proofOfId.size > maxSize) {
                e.preventDefault();
                alert('Proof of Identity file is too large. Maximum size is 5MB.');
                return false;
            }

            if (companyId && companyId.size > maxSize) {
                e.preventDefault();
                alert('Company ID file is too large. Maximum size is 5MB.');
                return false;
            }

            if (coe && coe.size > maxSize) {
                e.preventDefault();
                alert('Certificate of Employment file is too large. Maximum size is 5MB.');
                return false;
            }
        });

        // View Member Profile
        function viewMemberProfile(memberId) {
            // Show loading state
            document.getElementById('profileModalContent').innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading profile...</p>
                </div>
            `;
            
            // Fetch member profile via AJAX
            fetch(`get_member_profile.php?member_id=${memberId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('profileModalContent').innerHTML = data;
                })
                .catch(error => {
                    document.getElementById('profileModalContent').innerHTML = `
                        <div class="alert alert-danger">Error loading profile: ${error}</div>
                    `;
                });
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('viewProfileModal'));
            modal.show();
        }

        // Add Contribution
        function addContribution(memberId, memberName) {
            document.getElementById('contribution_member_id').value = memberId;
            document.getElementById('contribution_member_name').value = memberName;
            
            const modal = new bootstrap.Modal(document.getElementById('addContributionModal'));
            modal.show();
        }

        // Process Loan Payment
        function processLoanPayment(memberId, memberName) {
            document.getElementById('payment_member_id').value = memberId;
            document.getElementById('payment_member_name').value = memberName;
            
            // Clear previous data
            document.getElementById('loanSelect').innerHTML = '<option value="">Select Loan</option>';
            document.getElementById('loanDetails').style.display = 'none';
            document.getElementById('receiptPreview').style.display = 'none';
            document.getElementById('printReceiptBtn').style.display = 'none';
            
            // Fetch active loans for this member
            fetch(`get_member_loans.php?member_id=${memberId}`)
                .then(response => response.json())
                .then(loans => {
                    const loanSelect = document.getElementById('loanSelect');
                    loans.forEach(loan => {
                        const option = document.createElement('option');
                        option.value = loan.id;
                        // Use correct column names for loan details
                        const loanAmount = loan.loan_amount || loan.amount || 0;
                        const remainingBalance = loan.remaining_balance || loan.balance || 0;
                        option.textContent = `Loan #${loan.id} - ₱${parseFloat(remainingBalance).toFixed(2)} (${loan.status})`;
                        option.setAttribute('data-loan', JSON.stringify(loan));
                        loanSelect.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Error fetching loans:', error);
                });
            
            const modal = new bootstrap.Modal(document.getElementById('processPaymentModal'));
            modal.show();
        }

        // Show loan details when loan is selected
        document.getElementById('loanSelect').addEventListener('change', function() {
            const loanDetails = document.getElementById('loanDetails');
            const selectedOption = this.options[this.selectedIndex];
            
            if (selectedOption.value) {
                const loan = JSON.parse(selectedOption.getAttribute('data-loan'));
                // Use correct column names for loan details
                const loanAmount = loan.loan_amount || loan.amount || 0;
                const remainingBalance = loan.remaining_balance || loan.balance || 0;
                const interestRate = loan.interest_rate || 0;
                const dueDate = loan.due_date || loan.end_date || 'N/A';
                
                loanDetails.innerHTML = `
                    <h6>Loan Details</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Loan Amount:</strong> ₱${parseFloat(loanAmount).toFixed(2)}<br>
                            <strong>Remaining Balance:</strong> ₱${parseFloat(remainingBalance).toFixed(2)}
                        </div>
                        <div class="col-md-6">
                            <strong>Interest Rate:</strong> ${interestRate}%<br>
                            <strong>Due Date:</strong> ${dueDate !== 'N/A' ? new Date(dueDate).toLocaleDateString() : 'N/A'}
                        </div>
                    </div>
                `;
                loanDetails.style.display = 'block';
                
                // Set payment amount to remaining balance by default
                document.getElementById('paymentAmount').value = parseFloat(remainingBalance).toFixed(2);
                
                // Update receipt preview
                updateReceiptPreview();
            } else {
                loanDetails.style.display = 'none';
                document.getElementById('receiptPreview').style.display = 'none';
            }
        });

        // Update receipt preview when payment amount changes
        document.getElementById('paymentAmount').addEventListener('input', updateReceiptPreview);

        function updateReceiptPreview() {
            const loanSelect = document.getElementById('loanSelect');
            const paymentAmount = document.getElementById('paymentAmount').value;
            const selectedOption = loanSelect.options[loanSelect.selectedIndex];
            
            if (selectedOption.value && paymentAmount) {
                const loan = JSON.parse(selectedOption.getAttribute('data-loan'));
                const remainingBalance = loan.remaining_balance || loan.balance || 0;
                const newBalance = parseFloat(remainingBalance) - parseFloat(paymentAmount);
                
                document.getElementById('receiptContent').innerHTML = `
                    <div class="row">
                        <div class="col-6"><small><strong>Loan ID:</strong> ${loan.id}</small></div>
                        <div class="col-6"><small><strong>Date:</strong> ${new Date().toLocaleDateString()}</small></div>
                    </div>
                    <div class="row mt-1">
                        <div class="col-12"><small><strong>Previous Balance:</strong> ₱${parseFloat(remainingBalance).toFixed(2)}</small></div>
                    </div>
                    <div class="row mt-1">
                        <div class="col-12"><small><strong>Payment Amount:</strong> ₱${parseFloat(paymentAmount).toFixed(2)}</small></div>
                    </div>
                    <div class="row mt-1">
                        <div class="col-12"><small><strong>New Balance:</strong> ₱${newBalance.toFixed(2)}</small></div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-12 text-center">
                            <small><strong>Payment Method:</strong> Cash</small>
                        </div>
                    </div>
                `;
                document.getElementById('receiptPreview').style.display = 'block';
            }
        }

        // Print receipt function
        function printReceipt() {
            // Open receipt in new window for printing
            const receiptWindow = window.open('', '_blank');
            receiptWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Payment Receipt</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .receipt-container { max-width: 400px; margin: 0 auto; border: 2px solid #000; padding: 20px; }
                        .header { text-align: center; margin-bottom: 20px; }
                        .header h2 { margin: 0; color: #2c5e2e; }
                        .header p { margin: 5px 0; color: #666; }
                        .receipt-info { margin-bottom: 15px; }
                        .receipt-info .label { font-weight: bold; width: 120px; display: inline-block; }
                        .divider { border-top: 1px dashed #000; margin: 15px 0; }
                        .amount { font-size: 18px; font-weight: bold; text-align: center; margin: 15px 0; }
                        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
                        .signature { margin-top: 40px; border-top: 1px solid #000; padding-top: 10px; }
                        @media print {
                            body { margin: 0; }
                            .no-print { display: none; }
                            .receipt-container { border: none; box-shadow: none; }
                        }
                    </style>
                </head>
                <body>
                    <div class="receipt-container">
                        <div class="header">
                            <h2>iBarako Loan System</h2>
                            <p>Official Payment Receipt</p>
                        </div>
                        
                        <div class="receipt-info">
                            <div><span class="label">Receipt No:</span> RCP-${Date.now()}</div>
                            <div><span class="label">Date:</span> ${new Date().toLocaleDateString()}</div>
                            <div><span class="label">Time:</span> ${new Date().toLocaleTimeString()}</div>
                        </div>
                        
                        <div class="divider"></div>
                        
                        <div class="receipt-info">
                            <div><span class="label">Member Name:</span> ${document.getElementById('payment_member_name').value}</div>
                            <div><span class="label">Loan ID:</span> ${document.getElementById('loanSelect').value}</div>
                        </div>
                        
                        <div class="divider"></div>
                        
                        <div class="receipt-info">
                            <div><span class="label">Payment Method:</span> Cash</div>
                            <div><span class="label">Amount Paid:</span> ₱${parseFloat(document.getElementById('paymentAmount').value).toFixed(2)}</div>
                        </div>
                        
                        <div class="amount">
                            TOTAL PAID: ₱${parseFloat(document.getElementById('paymentAmount').value).toFixed(2)}
                        </div>
                        
                        <div class="divider"></div>
                        
                        <div class="signature">
                            <div style="text-align: center;">_________________________</div>
                            <div style="text-align: center; margin-top: 5px;">Member's Signature</div>
                        </div>
                        
                        <div class="footer">
                            <p>Thank you for your payment!</p>
                            <p>This is an official receipt from iBarako Loan System</p>
                        </div>
                    </div>
                    
                    <div class="no-print" style="text-align: center; margin-top: 20px;">
                        <button onclick="window.print()" class="btn btn-success">Print Receipt</button>
                        <button onclick="window.close()" class="btn btn-secondary">Close</button>
                    </div>
                    
                    <script>
                        window.onload = function() {
                            window.print();
                        };
                    <\/script>
                </body>
                </html>
            `);
            receiptWindow.document.close();
        }

        // Print contribution receipt function
        function printContributionReceipt() {
            // Open receipt in new window for printing
            const receiptWindow = window.open('', '_blank');
            receiptWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Contribution Receipt</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .receipt-container { max-width: 400px; margin: 0 auto; border: 2px solid #000; padding: 20px; }
                        .header { text-align: center; margin-bottom: 20px; }
                        .header h2 { margin: 0; color: #2c5e2e; }
                        .header p { margin: 5px 0; color: #666; }
                        .receipt-info { margin-bottom: 15px; }
                        .receipt-info .label { font-weight: bold; width: 120px; display: inline-block; }
                        .divider { border-top: 1px dashed #000; margin: 15px 0; }
                        .amount { font-size: 18px; font-weight: bold; text-align: center; margin: 15px 0; }
                        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
                        .signature { margin-top: 40px; border-top: 1px solid #000; padding-top: 10px; }
                        @media print {
                            body { margin: 0; }
                            .no-print { display: none; }
                            .receipt-container { border: none; box-shadow: none; }
                        }
                    </style>
                </head>
                <body>
                    <div class="receipt-container">
                        <div class="header">
                            <h2>iBarako Loan System</h2>
                            <p>Official Contribution Receipt</p>
                        </div>
                        
                        <div class="receipt-info">
                            <div><span class="label">Receipt No:</span> CTB-${Date.now()}</div>
                            <div><span class="label">Date:</span> ${new Date().toLocaleDateString()}</div>
                            <div><span class="label">Time:</span> ${new Date().toLocaleTimeString()}</div>
                        </div>
                        
                        <div class="divider"></div>
                        
                        <div class="receipt-info">
                            <div><span class="label">Member Name:</span> ${document.getElementById('contribution_member_name').value}</div>
                        </div>
                        
                        <div class="divider"></div>
                        
                        <div class="receipt-info">
                            <div><span class="label">Contribution Type:</span> ${document.querySelector('select[name="contribution_type"]').options[document.querySelector('select[name="contribution_type"]').selectedIndex].text}</div>
                            <div><span class="label">Amount:</span> ₱${parseFloat(document.querySelector('input[name="amount"]').value).toFixed(2)}</div>
                        </div>
                        
                        <div class="amount">
                            TOTAL CONTRIBUTION: ₱${parseFloat(document.querySelector('input[name="amount"]').value).toFixed(2)}
                        </div>
                        
                        <div class="divider"></div>
                        
                        <div class="signature">
                            <div style="text-align: center;">_________________________</div>
                            <div style="text-align: center; margin-top: 5px;">Member's Signature</div>
                        </div>
                        
                        <div class="footer">
                            <p>Thank you for your contribution!</p>
                            <p>This is an official receipt from iBarako Loan System</p>
                        </div>
                    </div>
                    
                    <div class="no-print" style="text-align: center; margin-top: 20px;">
                        <button onclick="window.print()" class="btn btn-success">Print Receipt</button>
                        <button onclick="window.close()" class="btn btn-secondary">Close</button>
                    </div>
                    
                    <script>
                        window.onload = function() {
                            window.print();
                        };
                    <\/script>
                </body>
                </html>
            `);
            receiptWindow.document.close();
        }

        // Auto-show receipt after successful payment
        <?php if ($success && strpos($success, 'Receipt No:') !== false): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Show print receipt button
            document.getElementById('printReceiptBtn').style.display = 'inline-block';
            
            // Show receipt modal after delay
            setTimeout(() => {
                const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
                
                // Generate receipt content for modal
                document.getElementById('printableReceipt').innerHTML = `
                    <div class="text-center">
                        <h4 class="text-success">iBarako Loan System</h4>
                        <p class="mb-3">Payment Receipt</p>
                    </div>
                    <div class="border p-3">
                        <p><strong>Receipt No:</strong> <?= $_SESSION['last_receipt']['receipt_number'] ?? 'N/A' ?></p>
                        <p><strong>Date:</strong> <?= date('F j, Y') ?></p>
                        <p><strong>Time:</strong> <?= date('h:i A') ?></p>
                        <p><strong>Member:</strong> <?= $_SESSION['last_receipt']['member_name'] ?? 'N/A' ?> (<?= $_SESSION['last_receipt']['member_id'] ?? 'N/A' ?>)</p>
                        <p><strong>Loan ID:</strong> <?= $_SESSION['last_receipt']['loan_id'] ?? 'N/A' ?></p>
                        <p><strong>Amount Paid:</strong> ₱<?= number_format($_SESSION['last_receipt']['payment_amount'] ?? 0, 2) ?></p>
                        <p><strong>Payment Method:</strong> <?= $_SESSION['last_receipt']['payment_method'] ?? 'Cash' ?></p>
                        <p><strong>Processed By:</strong> <?= $_SESSION['last_receipt']['processed_by'] ?? 'Admin' ?></p>
                        <hr>
                        <p class="text-center"><strong>Thank you for your payment!</strong></p>
                    </div>
                `;
                
                receiptModal.show();
            }, 1000);
        });
        <?php unset($_SESSION['last_receipt']); endif; ?>

        // Auto-show contribution receipt after successful contribution
        <?php if ($success && strpos($success, 'Contribution added successfully') !== false): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Show contribution receipt modal after delay
            setTimeout(() => {
                const contributionReceiptModal = new bootstrap.Modal(document.getElementById('contributionReceiptModal'));
                
                // Generate contribution receipt content for modal
                document.getElementById('printableContributionReceipt').innerHTML = `
                    <div class="text-center">
                        <h4 class="text-success">iBarako Loan System</h4>
                        <p class="mb-3">Contribution Receipt</p>
                    </div>
                    <div class="border p-3">
                        <p><strong>Receipt No:</strong> <?= $_SESSION['last_contribution_receipt']['receipt_number'] ?? 'N/A' ?></p>
                        <p><strong>Date:</strong> <?= date('F j, Y') ?></p>
                        <p><strong>Time:</strong> <?= date('h:i A') ?></p>
                        <p><strong>Member:</strong> <?= $_SESSION['last_contribution_receipt']['member_name'] ?? 'N/A' ?> (<?= $_SESSION['last_contribution_receipt']['member_id'] ?? 'N/A' ?>)</p>
                        <p><strong>Contribution Type:</strong> <?= $_SESSION['last_contribution_receipt']['contribution_type'] ?? 'N/A' ?></p>
                        <p><strong>Amount:</strong> ₱<?= number_format($_SESSION['last_contribution_receipt']['amount'] ?? 0, 2) ?></p>
                        <p><strong>Remarks:</strong> <?= $_SESSION['last_contribution_receipt']['remarks'] ?? 'N/A' ?></p>
                        <p><strong>Processed By:</strong> <?= $_SESSION['last_contribution_receipt']['processed_by'] ?? 'Admin' ?></p>
                        <hr>
                        <p class="text-center"><strong>Thank you for your contribution!</strong></p>
                    </div>
                `;
                
                contributionReceiptModal.show();
            }, 1000);
        });
        <?php unset($_SESSION['last_contribution_receipt']); endif; ?>
    </script>
</body>
</html>