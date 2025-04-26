<?php
session_start();

// Enable full error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize variables
$token = isset($_GET['token']) ? $_GET['token'] : '';
$errors = [];
$success = false;
$validToken = false;
$userId = null;
$userEmail = null;

// Function to log debug information
function debug($message) {
    error_log("[RESET] " . $message);
}

// Include database connection
require_once 'conn.php';

debug("Processing token: $token");

// Validate token
if (!empty($token)) {
    try {
        // First, check if the token exists at all
        debug("Checking if token exists in database");
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ?");
        $stmt->execute([$token]);
        
        if ($stmt->rowCount() > 0) {
            $resetRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            debug("Token found in database: " . print_r($resetRecord, true));
            
            // Check if the token is expired
            $expiryDate = $resetRecord['expiry_date'];
            $currentDate = date('Y-m-d H:i:s');
            
            if ($currentDate > $expiryDate) {
                debug("Token expired. Expiry: $expiryDate, Current: $currentDate");
                $errors[] = "This password reset link has expired.";
            } else {
                // Get user details
                $userId = $resetRecord['user_id'];
                debug("Looking up user ID: $userId");
                
                $userStmt = $pdo->prepare("SELECT id, email, username FROM users WHERE id = ?");
                $userStmt->execute([$userId]);
                
                if ($userStmt->rowCount() > 0) {
                    $userRecord = $userStmt->fetch(PDO::FETCH_ASSOC);
                    debug("User found: " . print_r($userRecord, true));
                    
                    $validToken = true;
                    $userEmail = $userRecord['email'];
                    $username = $userRecord['username'];
                } else {
                    debug("User ID not found: $userId");
                    $errors[] = "User account not found.";
                }
            }
        } else {
            debug("Token not found in database");
            $errors[] = "Invalid reset token.";
        }
    } catch (PDOException $e) {
        debug("Database error: " . $e->getMessage());
        $errors[] = "System error. Please try again later.";
    }
} else {
    debug("No token provided");
    $errors[] = "Missing reset token.";
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && $validToken) {
    debug("Processing password reset form");
    
    // Validate password
    if (empty($_POST["password"])) {
        $errors[] = "Password is required";
    } else if (strlen($_POST["password"]) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    // Validate password confirmation
    if (empty($_POST["confirm_password"])) {
        $errors[] = "Please confirm your password";
    } else if ($_POST["password"] !== $_POST["confirm_password"]) {
        $errors[] = "Passwords do not match";
    }
    
    // Update password if no errors
    if (empty($errors)) {
        try {
            debug("Updating password for user ID: $userId");
            $pdo->beginTransaction();
            
            // Hash new password
            $hashedPassword = password_hash($_POST["password"], PASSWORD_DEFAULT);
            
            // Update user's password
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $result = $stmt->execute([$hashedPassword, $userId]);
            
            if (!$result) {
                debug("Failed to update password: " . print_r($stmt->errorInfo(), true));
                throw new PDOException("Failed to update password");
            }
            
            // Delete used reset token
            $stmt = $pdo->prepare("DELETE FROM password_resets WHERE token = ?");
            $result = $stmt->execute([$token]);
            
            if (!$result) {
                debug("Failed to delete token: " . print_r($stmt->errorInfo(), true));
                throw new PDOException("Failed to delete token");
            }
            
            $pdo->commit();
            debug("Password reset successful");
            $success = true;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            debug("Password update failed: " . $e->getMessage());
            $errors[] = "Password update failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - FixItNow</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            background-color: #eef1f6;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        [data-bs-theme="dark"] {
            --bs-body-bg: #121212;
            --bs-body-color: #e9ecef;
        }
        
        .reset-container {
            max-width: 450px;
            margin: 80px auto;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        [data-bs-theme="dark"] .reset-container {
            background-color: #1e1e1e;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.3);
        }
        
        .reset-header {
            text-align: center;
            padding: 1.5rem;
        }
        
        .reset-title {
            color: #7952b3;
            font-weight: 700;
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }
        
        [data-bs-theme="dark"] .reset-title {
            color: #9775fa;
        }
        
        .reset-subtitle {
            color: #6c757d;
            font-size: 1rem;
        }
        
        .reset-body {
            padding: 0 2rem 2rem;
        }
        
        .alert {
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid transparent;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            border-left-color: #dc3545;
            color: #721c24;
        }
        
        [data-bs-theme="dark"] .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            color: #f8d7da;
        }
        
        .alert-info {
            background-color: #d1ecf1;
            border-left-color: #17a2b8;
            color: #0c5460;
        }
        
        [data-bs-theme="dark"] .alert-info {
            background-color: rgba(23, 162, 184, 0.1);
            color: #d1ecf1;
        }
        
        .alert-success {
            background-color: #d4edda;
            border-left-color: #28a745;
            color: #155724;
        }
        
        [data-bs-theme="dark"] .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: #d4edda;
        }
        
        .form-control {
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            border: 1px solid #ced4da;
        }
        
        [data-bs-theme="dark"] .form-control {
            background-color: #2b2b2b;
            border-color: #444;
            color: #e9ecef;
        }
        
        .form-control:focus {
            border-color: #7952b3;
            box-shadow: 0 0 0 0.25rem rgba(121, 82, 179, 0.25);
        }
        
        [data-bs-theme="dark"] .form-control:focus {
            border-color: #9775fa;
            box-shadow: 0 0 0 0.25rem rgba(151, 117, 250, 0.25);
        }
        
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
        }
        
        .btn-primary {
            background-color: #7952b3;
            border-color: #7952b3;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        [data-bs-theme="dark"] .btn-primary {
            background-color: #9775fa;
            border-color: #9775fa;
        }
        
        .btn-primary:hover {
            background-color: #6941a0;
            border-color: #6941a0;
        }
        
        [data-bs-theme="dark"] .btn-primary:hover {
            background-color: #845ef7;
            border-color: #845ef7;
        }
        
        .reset-footer {
            text-align: center;
            padding: 1rem;
            border-top: 1px solid #dee2e6;
        }
        
        [data-bs-theme="dark"] .reset-footer {
            border-top-color: #343a40;
        }
        
        .reset-footer a {
            color: #7952b3;
            text-decoration: none;
            font-weight: 600;
        }
        
        [data-bs-theme="dark"] .reset-footer a {
            color: #9775fa;
        }
        
        .reset-footer a:hover {
            text-decoration: underline;
        }
        
        /* Error icon */
        .error-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background-color: #dc3545;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1rem;
        }
        
        /* Success icon */
        .success-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background-color: #28a745;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1rem;
        }
        
        /* Info icon */
        .info-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background-color: #17a2b8;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1rem;
        }
        
        .back-to-home {
            display: inline-block;
            margin-top: 1rem;
            color: #6c757d;
            text-decoration: none;
        }
        
        .back-to-home:hover {
            color: #7952b3;
            text-decoration: none;
        }
        
        [data-bs-theme="dark"] .back-to-home:hover {
            color: #9775fa;
        }
        
        /* Logo */
        .logo {
            text-decoration: none;
            color: #212529;
            font-weight: 900;
            font-size: 1.5rem;
            display: block;
            margin-bottom: 1rem;
        }
        
        [data-bs-theme="dark"] .logo {
            color: #e9ecef;
        }
        
        .logo .highlight {
            color: #28a745;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="reset-container">
            <!-- Success Message -->
            <?php if ($success): ?>
            <div class="reset-header">
                <div class="success-icon">
                    <i class="fas fa-check"></i>
                </div>
                <h1 class="reset-title">Success!</h1>
                <p class="reset-subtitle">Your password has been reset successfully.</p>
            </div>
            
            <div class="reset-body">
                <div class="d-grid">
                    <a href="login.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-sign-in-alt me-2"></i>Login with New Password
                    </a>
                </div>
            </div>
            
            <?php elseif ($validToken): ?>
            <div class="reset-header">
                <a href="index.php" class="logo text-center">
                    <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                </a>
                <h1 class="reset-title">Reset Your Password</h1>
                <p class="reset-subtitle">Please create a new password for your account</p>
            </div>
            
            <div class="reset-body">
                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <!-- Info Message -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Setting a new password for <strong><?php echo htmlspecialchars($userEmail); ?></strong>
                </div>
                
                <!-- Reset Password Form -->
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]) . '?token=' . htmlspecialchars($token); ?>">
                    <!-- New Password -->
                    <div class="mb-3 position-relative">
                        <label for="password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <span class="password-toggle" id="passwordToggle">
                            <i class="far fa-eye"></i>
                        </span>
                    </div>
                    
                    <!-- Confirm New Password -->
                    <div class="mb-4 position-relative">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        <span class="password-toggle" id="confirmPasswordToggle">
                            <i class="far fa-eye"></i>
                        </span>
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-key me-2"></i>Reset Password
                        </button>
                    </div>
                </form>
            </div>
            
            <?php else: ?>
            <div class="reset-header">
                <a href="index.php" class="logo text-center">
                    <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                </a>
                <div class="error-icon">
                    <i class="fas fa-exclamation"></i>
                </div>
                <h1 class="reset-title">Invalid Reset Link</h1>
                <p class="reset-subtitle">This password reset link is invalid or has expired.</p>
            </div>
            
            <div class="reset-body">
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <div class="d-grid">
                    <a href="forgot-password.php" class="btn btn-primary">
                        <i class="fas fa-redo me-2"></i>Request New Reset Link
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="reset-footer">
                <div>
                    <?php if (!$success): ?>
                    <span>Remember your password? <a href="login.php">Login</a></span>
                    <?php endif; ?>
                </div>
                <div class="mt-2">
                    <span>Don't have an account? <a href="signup.php">Sign Up</a></span>
                </div>
            </div>
        </div>
        
        <div class="text-center">
            <a href="index.php" class="back-to-home">
                <i class="fas fa-long-arrow-alt-left me-1"></i> Back to Home
            </a>
        </div>
        
        <?php if (isset($_GET['debug']) && $_GET['debug'] === 'true'): ?>
        <div class="card mt-4">
            <div class="card-header bg-dark text-white">
                Debug Information
            </div>
            <div class="card-body">
                <pre><code>
Token: <?php echo htmlspecialchars($token); ?>
Valid Token: <?php echo $validToken ? 'Yes' : 'No'; ?>
User ID: <?php echo $userId; ?>
User Email: <?php echo $userEmail; ?>
Errors: <?php echo !empty($errors) ? implode(', ', $errors) : 'None'; ?>
                </code></pre>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Theme detection and management
            const prefersDarkScheme = window.matchMedia("(prefers-color-scheme: dark)");
            const currentTheme = localStorage.getItem("theme");
            
            if (currentTheme === "dark" || (!currentTheme && prefersDarkScheme.matches)) {
                document.documentElement.setAttribute("data-bs-theme", "dark");
            } else {
                document.documentElement.setAttribute("data-bs-theme", "light");
            }
            
            // Password visibility toggle
            const passwordToggle = document.getElementById('passwordToggle');
            const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');
            
            if (passwordToggle) {
                const passwordInput = document.getElementById('password');
                passwordToggle.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    this.querySelector('i').classList.toggle('fa-eye');
                    this.querySelector('i').classList.toggle('fa-eye-slash');
                });
            }
            
            if (confirmPasswordToggle) {
                const confirmPasswordInput = document.getElementById('confirm_password');
                confirmPasswordToggle.addEventListener('click', function() {
                    const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    confirmPasswordInput.setAttribute('type', type);
                    this.querySelector('i').classList.toggle('fa-eye');
                    this.querySelector('i').classList.toggle('fa-eye-slash');
                });
            }
        });
    </script>
</body>
</html>