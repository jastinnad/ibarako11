<?php
session_start();
    if (isset($_SESSION['registration_success']) && $_SESSION['registration_success']) {
        echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                Registration submitted successfully! Please wait for admin approval.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
              </div>';
                unset($_SESSION['registration_success']);
            }
                        
// Display registration errors if any
     if (isset($_SESSION['registration_errors']) && !empty($_SESSION['registration_errors'])) {
        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
				<i class="fas fa-exclamation-triangle me-2"></i>
				' . implode('<br>', $_SESSION['registration_errors']) . '
					<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
              </div>';
                   unset($_SESSION['registration_errors']);
                }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iBarako - Your Community. Your Growth. Your iBarako.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #1a365d;
            --secondary: #2d3748;
            --accent: #e53e3e;
            --light: #f7fafc;
            --dark: #2d3748;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light);
            color: var(--dark);
            line-height: 1.6;
        }
        
        .navbar {
            background-color: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1rem 0;
        }
        
        .logo {
            max-height: 50px;
        }
        
        .hero-section {
            background: linear-gradient(135deg, #1a365d);
            color: white;
            padding: 4rem 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        
        .hero-content {
            padding-right: 2rem;
        }
        
        .hero-title {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            line-height: 1.2;
        }
        
        .hero-subtitle {
            font-size: 1.25rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }
        
        .auth-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            padding: 2rem;
            height: fit-content;
        }
        
        .form-tabs {
            display: flex;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 1.5rem;
        }
        
        .form-tab {
            flex: 1;
            text-align: center;
            padding: 1rem;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            font-weight: 600;
            color: #718096;
        }
        
        .form-tab.active {
            border-bottom-color: var(--accent);
            color: var(--accent);
        }
        
        .form-content {
            display: none;
        }
        
        .form-content.active {
            display: block;
            animation: fadeIn 0.5s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .btn-primary {
            background-color: var(--accent);
            border-color: var(--accent);
            padding: 0.75rem 2rem;
            font-weight: 600;
            border-radius: 8px;
        }
        
        .btn-primary:hover {
            background-color: #c53030;
            border-color: #c53030;
        }
		
		.btn-white {
			background-color: white;
			border-color: white;
			color: #1a365d;
			font-weight: 600;
			transition: all 0.3s ease;
			padding: 0.75rem 1.5rem;
		}

		.btn-white:hover {
			background-color: var(--accent);
			border-color: var(--accent);
			color: white;
			transform: translateY(-1px);
			box-shadow: 0 4px 8px rgba(229, 62, 62, 0.3);
		}
		
		.btn-outline-accent {
			color: var(--accent);
			border-color: var(--accent);
		}

		.btn-outline-accent:hover {
			background-color: var(--accent);
			border-color: var(--accent);
			color: white;
		}
        
        .form-control {
            border-radius: 8px;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 0.2rem rgba(229, 62, 62, 0.25);
        }
        
        /* Updated form label color for better visibility */
        .form-label {
            color: #1a365d !important;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .feature-icon {
            width: 60px;
            height: 60px;
            background: rgba(229, 62, 62, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }
        
        .feature-icon i {
            font-size: 1.5rem;
            color: var(--accent);
        }
        
        .features-section {
            padding: 5rem 0;
        }
        
        .section-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 3rem;
            text-align: center;
            color: #1a365d;
        }
        
        .footer {
            background-color: var(--primary);
            color: white;
            padding: 2rem 0;
            margin-top: 0.5rem;
        }
        
        .toggle-password {
            cursor: pointer;
        }
        
        .file-upload-container {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            background-color: #f8f9fa;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .file-upload-container:hover {
            border-color: var(--accent);
            background-color: #fff;
        }
        
        .file-upload-container.dragover {
            border-color: var(--accent);
            background-color: rgba(229, 62, 62, 0.05);
        }
        
        .file-input {
            display: none;
        }
        
        .file-preview {
            margin-top: 0.5rem;
            font-size: 0.875rem;
        }
        
        .file-preview img {
            max-width: 100px;
            max-height: 100px;
            margin: 0.25rem;
            border-radius: 4px;
        }
        
        .required-field::after {
            content: " *";
            color: var(--accent);
        }
        
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }
            
            .hero-content {
                padding-right: 0;
                margin-bottom: 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container">
            <a class="navbar-brand" href="#">
                <img src="ibarako_logo.JPG" alt="iBarako Logo" class="logo">
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="#features">Features</a>
                <a class="nav-link" href="#about">About</a>
                <a class="nav-link btn btn-outline-accent ms-2" href="#auth">Get Started</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section with Auth Forms -->
    <section class="hero-section" id="auth">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-7">
                    <div class="hero-content">
                        <h1 class="hero-title">
                            Your Community.<br>
                            Your Growth.<br>
                            Your iBarako.
                        </h1>
                        <p class="hero-subtitle">
                            iBarako is a faculty-led web and mobile financial platform designed for local communities. 
                            From training to livelihood funding, iBarako serves as a trusted partner to save, earn, grow, and uplift others.
                        </p>
                        <div class="d-flex flex-wrap gap-3 mb-4">
                            <span class="badge bg-light text-dark p-2"><i class="fas fa-globe me-1"></i> Web & Mobile App</span>
                            <span class="badge bg-light text-dark p-2"><i class="fas fa-map-marker-alt me-1"></i> Localized</span>
                            <span class="badge bg-light text-dark p-2"><i class="fas fa-user-graduate me-1"></i> Faculty-led</span>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="auth-container">
                        <div class="form-tabs">
                            <div class="form-tab active" onclick="showTab('login')">Sign In</div>
                            <div class="form-tab" onclick="showTab('register')">Sign Up</div>
                        </div>

                        <!-- Login Form -->
                        <div id="login-form" class="form-content active">
                            <form method="POST" action="login.php">
                                <input type="hidden" name="action" value="login">
                                <div class="mb-3">
                                    <label class="form-label">Email Address</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                        <input type="email" class="form-control" name="email" placeholder="Enter your email" required>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                        <input type="password" class="form-control" name="password" placeholder="Enter your password" required>
                                        <button type="button" class="btn btn-outline-secondary toggle-password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="d-grid mb-3">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-sign-in-alt me-2"></i>Sign In
                                    </button>
                                </div>
                                
                                <div class="text-center">
                                    <a href="#" class="text-muted small">Forgot your password?</a>
                                </div>
                            </form>
                        </div>

                        <!-- Registration Form -->
                        <div id="register-form" class="form-content">
                            <form method="POST" action="register_handler.php" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="register">
                                
                                <!-- Personal Information Section -->
                                <h6 class="mb-3 text-primary"><i class="fas fa-user me-2"></i>Personal Information</h6>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label required-field">First Name</label>
                                            <input type="text" class="form-control" name="firstname" value="<?php echo isset($_SESSION['form_data']['firstname']) ? htmlspecialchars($_SESSION['form_data']['firstname']) : ''; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label required-field">Middle Name</label>
                                            <input type="text" class="form-control" name="middlename" value="<?php echo isset($_SESSION['form_data']['middlename']) ? htmlspecialchars($_SESSION['form_data']['middlename']) : ''; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label required-field">Last Name</label>
                                            <input type="text" class="form-control" name="lastname" value="<?php echo isset($_SESSION['form_data']['lastname']) ? htmlspecialchars($_SESSION['form_data']['lastname']) : ''; ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label required-field">Email Address</label>
                                    <input type="email" class="form-control" name="email" value="<?php echo isset($_SESSION['form_data']['email']) ? htmlspecialchars($_SESSION['form_data']['email']) : ''; ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label required-field">Mobile Number</label>
                                    <input type="tel" class="form-control" name="mobile" value="<?php echo isset($_SESSION['form_data']['mobile']) ? htmlspecialchars($_SESSION['form_data']['mobile']) : ''; ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label required-field">Birthday</label>
                                    <input type="date" class="form-control" name="birthday" value="<?php echo isset($_SESSION['form_data']['birthday']) ? htmlspecialchars($_SESSION['form_data']['birthday']) : ''; ?>" required>
                                </div>

                                <!-- Address Information Section -->
                                <h6 class="mb-3 mt-4 text-primary"><i class="fas fa-home me-2"></i>Address Information</h6>
                                
                                <!-- UPDATED: Separate House No and Street/Village fields -->
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label required-field">House/Building No.</label>
                                            <input type="text" class="form-control" name="house_no" value="<?php echo isset($_SESSION['form_data']['house_no']) ? htmlspecialchars($_SESSION['form_data']['house_no']) : ''; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label required-field">Street/Village</label>
                                            <input type="text" class="form-control" name="street_village" value="<?php echo isset($_SESSION['form_data']['street_village']) ? htmlspecialchars($_SESSION['form_data']['street_village']) : ''; ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label required-field">Barangay</label>
                                    <input type="text" class="form-control" name="barangay" value="<?php echo isset($_SESSION['form_data']['barangay']) ? htmlspecialchars($_SESSION['form_data']['barangay']) : ''; ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label required-field">Municipality</label>
                                    <input type="text" class="form-control" name="municipality" value="<?php echo isset($_SESSION['form_data']['municipality']) ? htmlspecialchars($_SESSION['form_data']['municipality']) : ''; ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label required-field">City</label>
                                    <input type="text" class="form-control" name="city" value="<?php echo isset($_SESSION['form_data']['city']) ? htmlspecialchars($_SESSION['form_data']['city']) : ''; ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label required-field">Postal Code</label>
                                    <input type="text" class="form-control" name="postal_code" value="<?php echo isset($_SESSION['form_data']['postal_code']) ? htmlspecialchars($_SESSION['form_data']['postal_code']) : ''; ?>" required>
                                </div>

                                <!-- Employment Information Section -->
                                <h6 class="mb-3 mt-4 text-primary"><i class="fas fa-briefcase me-2"></i>Employment Information</h6>
                                
                                <!-- Company Name Field -->
                                <div class="mb-3">
                                    <label class="form-label required-field">Company Name</label>
                                    <input type="text" class="form-control" name="company_name" value="<?php echo isset($_SESSION['form_data']['company_name']) ? htmlspecialchars($_SESSION['form_data']['company_name']) : ''; ?>" required>
                                </div>

                                <!-- Company Address Field -->
                                <div class="mb-3">
                                    <label class="form-label required-field">Company Address</label>
                                    <textarea class="form-control" name="company_address" rows="3" placeholder="Enter complete company address" required><?php echo isset($_SESSION['form_data']['company_address']) ? htmlspecialchars($_SESSION['form_data']['company_address']) : ''; ?></textarea>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label required-field">Nature of Work</label>
                                    <input type="text" class="form-control" name="nature_of_work" value="<?php echo isset($_SESSION['form_data']['nature_of_work']) ? htmlspecialchars($_SESSION['form_data']['nature_of_work']) : ''; ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label required-field">Monthly Salary (₱)</label>
                                    <input type="number" class="form-control" name="salary" min="0" step="0.01" value="<?php echo isset($_SESSION['form_data']['salary']) ? htmlspecialchars($_SESSION['form_data']['salary']) : ''; ?>" required>
                                </div>

                                <!-- Document Uploads Section -->
                                <h6 class="mb-3 mt-4 text-primary"><i class="fas fa-file-upload me-2"></i>Required Documents</h6>
                                
                                <!-- Proof of Identity Upload -->
                                <div class="mb-3">
                                    <label class="form-label required-field">Proof of Identity</label>
                                    <div class="file-upload-container" onclick="document.getElementById('identity_proof').click()">
                                        <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                        <p class="mb-1">Upload Proof of Identity</p>
                                        <small class="text-muted">Passport, National ID, UMID, or Driver's License</small>
                                        <input type="file" class="file-input" id="identity_proof" name="identity_proof" accept="image/*,.pdf" required>
                                    </div>
                                    <div class="file-preview" id="identity_preview"></div>
                                </div>

                                <!-- Company ID Upload -->
                                <div class="mb-3">
                                    <label class="form-label required-field">Company ID</label>
                                    <div class="file-upload-container" onclick="document.getElementById('company_id').click()">
                                        <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                        <p class="mb-1">Upload Company ID</p>
                                        <small class="text-muted">Clear image of your valid Company ID</small>
                                        <input type="file" class="file-input" id="company_id" name="company_id" accept="image/*,.pdf" required>
                                    </div>
                                    <div class="file-preview" id="company_id_preview"></div>
                                </div>

                                <!-- Certificate of Employment Upload -->
                                <div class="mb-3">
                                    <label class="form-label required-field">Certificate of Employment</label>
                                    <div class="file-upload-container" onclick="document.getElementById('certificate_employment').click()">
                                        <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                        <p class="mb-1">Upload Certificate of Employment</p>
                                        <small class="text-muted">Recent COE issued by your employer</small>
                                        <input type="file" class="file-input" id="certificate_employment" name="certificate_employment" accept="image/*,.pdf" required>
                                    </div>
                                    <div class="file-preview" id="certificate_employment_preview"></div>
                                </div>

                                <!-- Account Security Section -->
                                <h6 class="mb-3 mt-4 text-primary"><i class="fas fa-lock me-2"></i>Account Security</h6>
                                <div class="mb-3">
                                    <label class="form-label required-field">Password</label>
                                    <input type="password" class="form-control" name="password" required>
                                    <small class="text-muted">Minimum 6 characters</small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label required-field">Confirm Password</label>
                                    <input type="password" class="form-control" name="confirm_password" required>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-user-plus me-2"></i>Create Account
                                    </button>
                                </div>
                            </form>

                            <div class="alert alert-info mt-3">
                                <small>
                                    <i class="fas fa-info-circle me-1"></i>
                                    Your account requires admin approval. You'll be notified once approved.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section" id="features">
        <div class="container">
            <h2 class="section-title">Why Choose iBarako?</h2>
            <div class="row">
                <div class="col-md-4 mb-4">
                    <div class="text-center">
                        <div class="feature-icon mx-auto">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h4>Secure Platform</h4>
                        <p>Your financial data is protected with bank-level security measures and encryption.</p>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="text-center">
                        <div class="feature-icon mx-auto">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <h4>Fast Processing</h4>
                        <p>Quick loan approval and disbursement to meet your urgent financial needs.</p>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="text-center">
                        <div class="feature-icon mx-auto">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <h4>Competitive Rates</h4>
                        <p>Enjoy lower interest rates compared to traditional lending institutions.</p>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="text-center">
                        <div class="feature-icon mx-auto">
                            <i class="fas fa-mobile-alt"></i>
                        </div>
                        <h4>Mobile Access</h4>
                        <p>Manage your account and loans anytime, anywhere with our mobile app.</p>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="text-center">
                        <div class="feature-icon mx-auto">
                            <i class="fas fa-users"></i>
                        </div>
                        <h4>Community Focus</h4>
                        <p>Designed specifically to uplift and support local community members.</p>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="text-center">
                        <div class="feature-icon mx-auto">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <h4>Faculty-Led</h4>
                        <p>Developed and supervised by academic professionals for reliability.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
	<footer class="footer" id="about">
		<div class="container">
			<div class="row">
				<div class="col-lg-4 mb-3">
					<h4>iBarako</h4>
					<p>A faculty-led web and mobile financial platform designed for local communities.</p>
					<div class="d-flex gap-3">
						<a href="#" class="text-light"><i class="fab fa-facebook-f"></i></a>
						<a href="#" class="text-light"><i class="fab fa-twitter"></i></a>
						<a href="#" class="text-light"><i class="fab fa-instagram"></i></a>
					</div>
				</div>
				<div class="col-lg-2 mb-3"> 
					<h5>Quick Links</h5>
					<ul class="list-unstyled">
						<li><a href="#auth" class="text-light">Get Started</a></li>
						<li><a href="#features" class="text-light">Features</a></li>
						<li><a href="#" class="text-light">About Us</a></li>
						<li><a href="#" class="text-light">Contact</a></li>
					</ul>
				</div>
				<div class="col-lg-3 mb-3"> 
					<h5>Contact Info</h5>
					<ul class="list-unstyled">
						<li><i class="fas fa-map-marker-alt me-2"></i> Local Community Center</li>
						<li><i class="fas fa-phone me-2"></i> (123) 456-7890</li>
						<li><i class="fas fa-envelope me-2"></i> info@ibarako.com</li>
					</ul>
				</div>
				<div class="col-lg-3 mb-3"> 
					<h5>Newsletter</h5>
					<p>Subscribe to get updates on new features</p>
					<div class="input-group">
						<input type="email" class="form-control" placeholder="Your email">
						<button class="btn btn-white" type="button">Subscribe</button>
					</div>
				</div>
			</div>
			<hr class="my-4">
			<div class="text-center">
				<p class="mb-0">&copy; 2025 iBarako. All rights reserved.</p> 
			</div>
		</div>
	</footer>

    <!-- Login Error Modal -->
    <div class="modal fade" id="loginErrorModal" tabindex="-1" aria-labelledby="loginErrorModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="loginErrorModalLabel">
                        <i class="fas fa-exclamation-triangle me-2"></i>Login Failed
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="loginErrorMessage">Invalid credentials. Please try again.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showTab(tabName) {
            // Hide all tabs and contents
            document.querySelectorAll('.form-tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.form-content').forEach(content => content.classList.remove('active'));
            
            // Show selected tab and content
            document.querySelector(`.form-tab:nth-child(${tabName === 'login' ? 1 : 2})`).classList.add('active');
            document.getElementById(tabName + '-form').classList.add('active');
        }

        // Password visibility toggle
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', function() {
                const input = this.closest('.input-group').querySelector('input');
                const icon = this.querySelector('i');
                
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.replace('fa-eye', 'fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.replace('fa-eye-slash', 'fa-eye');
                }
            });
        });

        // File upload handling
        function setupFileUpload(inputId, previewId) {
            const input = document.getElementById(inputId);
            const preview = document.getElementById(previewId);
            const container = input.closest('.file-upload-container');

            input.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    preview.innerHTML = '';
                    
                    if (file.type.startsWith('image/')) {
                        const img = document.createElement('img');
                        img.src = URL.createObjectURL(file);
                        preview.appendChild(img);
                    }
                    
                    const fileName = document.createElement('div');
                    fileName.textContent = `Selected: ${file.name}`;
                    fileName.className = 'text-success';
                    preview.appendChild(fileName);
                    
                    container.style.borderColor = '#28a745';
                    container.style.backgroundColor = '#f8fff9';
                }
            });

            // Drag and drop functionality
            container.addEventListener('dragover', function(e) {
                e.preventDefault();
                container.classList.add('dragover');
            });

            container.addEventListener('dragleave', function() {
                container.classList.remove('dragover');
            });

            container.addEventListener('drop', function(e) {
                e.preventDefault();
                container.classList.remove('dragover');
                input.files = e.dataTransfer.files;
                const event = new Event('change');
                input.dispatchEvent(event);
            });
        }

        // Set maximum date for birthday (18 years ago)
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date();
            const maxDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate());
            const birthdayInput = document.querySelector('input[name="birthday"]');
            if (birthdayInput) {
                birthdayInput.max = maxDate.toISOString().split('T')[0];
            }

            // Initialize file uploads
            setupFileUpload('identity_proof', 'identity_preview');
            setupFileUpload('company_id', 'company_id_preview');
            setupFileUpload('certificate_employment', 'certificate_employment_preview');

            // Check for login error and show popup
            <?php if (isset($_SESSION['login_error'])): ?>
                const errorModal = new bootstrap.Modal(document.getElementById('loginErrorModal'));
                document.getElementById('loginErrorMessage').textContent = '<?php echo addslashes($_SESSION['login_error']); ?>';
                errorModal.show();
                <?php unset($_SESSION['login_error']); ?>
            <?php endif; ?>

            // Clear form data from session after displaying
            <?php unset($_SESSION['form_data']); ?>

            // Auto-switch to register tab if there were registration errors
            <?php if (isset($_SESSION['had_registration_errors']) && $_SESSION['had_registration_errors']): ?>
                showTab('register');
                <?php unset($_SESSION['had_registration_errors']); ?>
            <?php endif; ?>
        });
    </script>
</body>
</html>