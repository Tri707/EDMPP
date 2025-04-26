<?php
session_start();
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Redirect if not admin
if (!$loggedIn || $userRole !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Include database connection
include 'conn.php';

// Check if any accounts exist in the database
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($count['count'] == 0) {
        // No accounts exist, redirect to login page
        header('Location: ../login.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Database error checking user accounts: " . $e->getMessage());
}

// Get admin profile information
$adminProfileImage = '../default.png';

try {
    // Query to get admin user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$userId]);
    $adminData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set profile image path
    if (!empty($adminData['profile_image'])) {
        if (preg_match('/^https?:\/\//', $adminData['profile_image'])) {
            $adminProfileImage = $adminData['profile_image'];
        } else {
            $imagePath = '../profile_images/' . basename($adminData['profile_image']);
            if (file_exists($imagePath)) {
                $adminProfileImage = $imagePath;
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching admin data: " . $e->getMessage());
}

// Initialize variables
$errors = [];
$success = false;
$formData = [
    'username' => '',
    'email' => '',
    'password' => '',
    'first_name' => '',
    'last_name' => '',
    'phone' => '',
    'specialties' => [],
    'experience' => '',
    'bio' => '',
    'location' => '',
    'education' => ''
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $formData['username'] = isset($_POST['username']) ? trim($_POST['username']) : '';
    $formData['email'] = isset($_POST['email']) ? trim($_POST['email']) : '';
    $formData['password'] = isset($_POST['password']) ? $_POST['password'] : '';
    $formData['first_name'] = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
    $formData['last_name'] = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
    $formData['phone'] = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $formData['specialties'] = isset($_POST['specialties']) ? $_POST['specialties'] : [];
    $formData['experience'] = isset($_POST['experience']) ? $_POST['experience'] : '';
    $formData['bio'] = isset($_POST['bio']) ? trim($_POST['bio']) : '';
    $formData['location'] = isset($_POST['location']) ? trim($_POST['location']) : '';
    $formData['education'] = isset($_POST['education']) ? trim($_POST['education']) : '';
    
    // Validate form data
    if (empty($formData['username'])) {
        $errors[] = 'Username is required';
    } else {
        // Check if username already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$formData['username']]);
        if ($stmt->rowCount() > 0) {
            $errors[] = 'Username already exists';
        }
    }
    
    if (empty($formData['email'])) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email is invalid';
    } else {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$formData['email']]);
        if ($stmt->rowCount() > 0) {
            $errors[] = 'Email already exists';
        }
    }
    
    if (empty($formData['password'])) {
        $errors[] = 'Password is required';
    } elseif (strlen($formData['password']) < 6) {
        $errors[] = 'Password must be at least 6 characters';
    }
    
    if (empty($formData['first_name'])) {
        $errors[] = 'First name is required';
    }
    
    if (empty($formData['last_name'])) {
        $errors[] = 'Last name is required';
    }
    
    if (empty($formData['phone'])) {
        $errors[] = 'Phone number is required';
    }
    
    if (empty($formData['specialties'])) {
        $errors[] = 'At least one specialty is required';
    }
    
    if (empty($formData['experience'])) {
        $errors[] = 'Experience level is required';
    }
    
    // Handle profile image upload
    $profileImage = '';
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $fileType = $_FILES['profile_image']['type'];
        
        if (!in_array($fileType, $allowedTypes)) {
            $errors[] = 'Profile image must be JPEG, PNG, or GIF';
        } else {
            $fileName = uniqid() . '.' . pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $uploadPath = '../profile_images/' . $fileName;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadPath)) {
                $profileImage = $fileName;
            } else {
                $errors[] = 'Failed to upload profile image';
            }
        }
    }
    
    // If no errors, insert new technician
    if (empty($errors)) {
        try {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Hash password
            $hashedPassword = password_hash($formData['password'], PASSWORD_DEFAULT);
            
            // Insert user record
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    username, email, password, role, first_name, last_name, phone, profile_image, status
                ) VALUES (
                    ?, ?, ?, 'provider', ?, ?, ?, ?, 'active'
                )
            ");
            
            $stmt->execute([
                $formData['username'],
                $formData['email'],
                $hashedPassword,
                $formData['first_name'],
                $formData['last_name'],
                $formData['phone'],
                $profileImage
            ]);
            
            // Get the new user ID
            $userId = $pdo->lastInsertId();
            
            // Convert specialties array to comma-separated string
            $specialties = implode(',', $formData['specialties']);
            
            // Insert provider record (without hourly_rate and response_time)
            $stmt = $pdo->prepare("
                INSERT INTO providers (
                    user_id, specialties, experience, bio, location, education, is_verified
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, 1
                )
            ");
            
            $stmt->execute([
                $userId,
                $specialties,
                $formData['experience'],
                $formData['bio'],
                $formData['location'],
                $formData['education']
            ]);
            
            // Commit transaction
            $pdo->commit();
            
            // Set success message
            $success = true;
            
            // Reset form data
            $formData = [
                'username' => '',
                'email' => '',
                'password' => '',
                'first_name' => '',
                'last_name' => '',
                'phone' => '',
                'specialties' => [],
                'experience' => '',
                'bio' => '',
                'location' => '',
                'education' => ''
            ];
            
        } catch (PDOException $e) {
            // Rollback transaction on error
            $pdo->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Technician - FixItNow Admin</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Theme CSS Variables */
        :root {
            /* Light Theme Variables (default) */
            --primary-color: #7952b3;      /* Purple */
            --primary-hover: #6941a0;      /* Darker Purple */
            --primary-light: #e4dafc;      /* Light Purple */
            --accent-color: #37b24d;       /* Green */
            --accent-light: #d3f9d8;       /* Light Green */
            --text-color: #212529;         /* Dark text for light mode */
            --text-muted: #6c757d;         /* Muted text for light mode */
            --bg-color: #f2f0f7;           /* Light purple background */
            --card-bg: #ffffff;            /* Card background */
            --header-bg: #212529;          /* Header background */
            --header-text: #ffffff;        /* Header text */
            --footer-bg: #212529;          /* Footer background */
            --footer-text: #e9ecef;        /* Footer text */
            --border-color: #dee2e6;       /* Border color */
            --input-bg: #ffffff;           /* Input background */
            --input-border: #ced4da;       /* Input border */
            --modal-bg: #ffffff;           /* Modal background */
            --shadow-color: rgba(0, 0, 0, 0.1); /* Shadow color */
            --sidebar-bg: #303238;         /* Sidebar background */
            --sidebar-hover: #3e4148;      /* Sidebar hover */
        }
        
        /* Dark Theme Variables - Enhanced for better vibrancy */
        [data-bs-theme="dark"] {
            --primary-color: #a687ff;      /* More vibrant purple */
            --primary-hover: #9775fa;      /* Brighter purple hover */
            --primary-light: #473a6b;      /* Less dark purple for better contrast */
            --accent-color: #4cd963;       /* More vibrant green */
            --accent-light: #2a7d3f;       /* Brighter green light */
            --text-color: #ffffff;         /* Brighter white text */
            --text-muted: #c5cfd8;         /* Less muted text */
            --bg-color: #18181b;           /* Slightly less dark background */
            --card-bg: #242429;            /* Less dark card background */
            --header-bg: #151518;          /* Slightly adjusted header */
            --header-text: #ffffff;        /* Pure white header text */
            --footer-bg: #151518;          /* Matching footer background */
            --footer-text: #c5cfd8;        /* Brighter footer text */
            --border-color: #3d4349;       /* More visible border */
            --input-bg: #323237;           /* Slightly lighter input background */
            --input-border: #5a5a66;       /* More visible input border */
            --modal-bg: #242429;           /* Matching modal background */
            --shadow-color: rgba(0, 0, 0, 0.35); /* Slightly stronger shadow */
            --sidebar-bg: #1c1c20;         /* Darker sidebar background */
            --sidebar-hover: #27272c;      /* Darker sidebar hover */
        }
        
        /* General Styles */
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.3s ease;
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }
        
        .main-container {
            display: flex;
            flex: 1;
        }
        
        /* Header Styles */
        .site-header {
            background-color: var(--header-bg);
            padding: 1rem 0;
            color: var(--header-text);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
            transition: background-color 0.3s ease;
        }
        
        .logo-text {
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            color: var(--header-text);
        }
        
        .logo-text .highlight {
            color: var(--accent-color);
        }
        
        /* Theme Toggle Button */
        .theme-toggle-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: transparent;
            border: 2px solid rgba(255, 255, 255, 0.2);
            color: var(--header-text);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .theme-toggle-btn:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background-color: var(--sidebar-bg);
            flex-shrink: 0;
            box-shadow: 0.25rem 0 1rem var(--shadow-color);
            transition: all 0.3s ease;
            z-index: 999;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 76px;
            }
            
            .sidebar .nav-text {
                display: none;
            }
            
            .sidebar .nav-link {
                justify-content: center;
            }
            
            .content-area {
                margin-left: 76px;
            }
        }
        
        .sidebar-header {
            padding: 1.5rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .sidebar-nav .nav-link {
            color: var(--footer-text);
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .sidebar-nav .nav-link:hover {
            color: var(--header-text);
            background-color: var(--sidebar-hover);
        }
        
        .sidebar-nav .nav-link.active {
            color: var(--header-text);
            background: linear-gradient(90deg, var(--primary-color) 0%, transparent 100%);
        }
        
        .sidebar-nav .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background-color: var(--accent-color);
        }
        
        .sidebar-nav .nav-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
        }
        
        /* Content Area */
        .content-area {
            flex: 1;
            padding: 2rem;
            transition: all 0.3s ease;
        }
        
        .page-title {
            font-weight: 700;
            position: relative;
            display: inline-block;
            margin-bottom: 1.5rem;
        }
        
        .page-title::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -8px;
            width: 60px;
            height: 3px;
            background-color: var(--primary-color);
        }
        
        /* Card Styles */
        .card {
            border-radius: 0.75rem;
            border: none;
            background-color: var(--card-bg);
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .card-header {
            background-color: rgba(0, 0, 0, 0.05);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            font-weight: 600;
        }
        
        .card-footer {
            background-color: rgba(0, 0, 0, 0.05);
            border-top: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
        }
        
        /* Form controls */
        .form-control, .form-select {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--input-border);
            border-radius: 0.5rem;
            padding: 0.6rem 1rem;
        }
        
        .form-control:focus, .form-select:focus {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(167, 135, 255, 0.25);
        }
        
        /* Form label */
        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        /* Form groups */
        .mb-3 {
            margin-bottom: 1.5rem !important;
        }
        
        /* Checkboxes */
        .form-check-input {
            background-color: var(--input-bg);
            border-color: var(--input-border);
        }
        
        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        /* Buttons */
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        
        /* Image preview */
        .profile-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            overflow: hidden;
            margin-bottom: 1rem;
            background-color: var(--input-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px dashed var(--input-border);
        }
        
        .profile-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-preview-icon {
            font-size: 3rem;
            color: var(--input-border);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="site-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="dashboard.php" class="text-decoration-none d-flex align-items-center">
                    <div class="logo-text">
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                    <span class="ms-3 text-white badge bg-danger">Admin Panel</span>
                </a>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-sun" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Action -->
                    <?php if($loggedIn && isset($adminData['username'])): ?>
                    <div class="dropdown">
                        <button class="btn btn-dark dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($adminProfileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($adminData['first_name']); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <div class="main-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-nav">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="users.php">
                            <span class="nav-icon"><i class="fas fa-users"></i></span>
                            <span class="nav-text">Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="technicians.php">
                            <span class="nav-icon"><i class="fas fa-user-cog"></i></span>
                            <span class="nav-text">Technicians</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="quotes.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="nav-text">Quote Requests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-calendar-check"></i></span>
                            <span class="nav-text">Bookings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="services.php">
                            <span class="nav-icon"><i class="fas fa-cogs"></i></span>
                            <span class="nav-text">Services</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reviews.php">
                            <span class="nav-icon"><i class="fas fa-star"></i></span>
                            <span class="nav-text">Reviews</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                            <span class="nav-text">Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">
                            <span class="nav-icon"><i class="fas fa-cog"></i></span>
                            <span class="nav-text">Settings</span>
                        </a>
                    </li>
                    <li class="nav-item mt-4">
                        <a class="nav-link text-danger" href="../logout.php">
                            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                            <span class="nav-text">Logout</span>
                        </a>
                    </li>
                </ul>
            </div>
        </aside>

        <!-- Main Content Area -->
        <div class="content-area">
            <!-- Back button and page title -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <a href="technicians.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Technicians
                </a>
            </div>
            
            <h1 class="page-title">Add New Technician</h1>
            
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>Technician has been added successfully.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>Please fix the following errors:
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <!-- Add Technician Form -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Technician Details</h5>
                </div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data">
                        <div class="row">
                            <!-- Account Information -->
                            <div class="col-lg-6">
                                <h6 class="mb-3 text-primary">Account Information</h6>
                                
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($formData['username']); ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email Address</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($formData['email']); ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="password" class="form-label">Password</label>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                    <div class="form-text">Password must be at least 6 characters long</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>
                            </div>
                            
                            <!-- Personal Information -->
                            <div class="col-lg-6">
                                <h6 class="mb-3 text-primary">Personal Information</h6>
                                
                                <div class="mb-3">
                                    <label for="first_name" class="form-label">First Name</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($formData['first_name']); ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="last_name" class="form-label">Last Name</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($formData['last_name']); ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($formData['phone']); ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="profile_image" class="form-label">Profile Image</label>
                                    <div class="d-flex align-items-center">
                                        <div class="profile-preview me-3" id="profilePreview">
                                            <i class="fas fa-user profile-preview-icon"></i>
                                        </div>
                                        <div>
                                            <input type="file" class="form-control" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/gif">
                                            <div class="form-text">JPEG, PNG, or GIF format (max 2MB)</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Professional Details -->
                            <div class="col-12 mt-4">
                                <h6 class="mb-3 text-primary">Professional Details</h6>
                                
                                <div class="row">
                                    <div class="col-lg-6">
                                        <div class="mb-3">
                                            <label class="form-label">Specialties</label>
                                            <div class="row g-3">
                                                <div class="col-6">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="smartphone" name="specialties[]" value="smartphone" <?php echo in_array('smartphone', $formData['specialties']) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="smartphone">Smartphone Repair</label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="laptop" name="specialties[]" value="laptop" <?php echo in_array('laptop', $formData['specialties']) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="laptop">Laptop Repair</label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="tablet" name="specialties[]" value="tablet" <?php echo in_array('tablet', $formData['specialties']) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="tablet">Tablet Repair</label>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="desktop" name="specialties[]" value="desktop" <?php echo in_array('desktop', $formData['specialties']) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="desktop">Desktop Repair</label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="gaming" name="specialties[]" value="gaming" <?php echo in_array('gaming', $formData['specialties']) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="gaming">Gaming Devices</label>
                                                    </div>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="tv" name="specialties[]" value="tv" <?php echo in_array('tv', $formData['specialties']) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="tv">TV Repair</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="experience" class="form-label">Experience Level</label>
                                            <select class="form-select" id="experience" name="experience">
                                                <option value="">Select Experience</option>
                                                <option value="0-1" <?php echo $formData['experience'] === '0-1' ? 'selected' : ''; ?>>Less than 1 year</option>
                                                <option value="1-3" <?php echo $formData['experience'] === '1-3' ? 'selected' : ''; ?>>1-3 years</option>
                                                <option value="3-5" <?php echo $formData['experience'] === '3-5' ? 'selected' : ''; ?>>3-5 years</option>
                                                <option value="5-10" <?php echo $formData['experience'] === '5-10' ? 'selected' : ''; ?>>5-10 years</option>
                                                <option value="10+" <?php echo $formData['experience'] === '10+' ? 'selected' : ''; ?>>10+ years</option>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="location" class="form-label">Location</label>
                                            <select class="form-select" id="location" name="location">
                                                <option value="">Select Location</option>
                                                <?php
                                                $locations = [
                                                    'Abha', 'Abu Arish', 'Ad Darb', 'Ad Dilam', 'Afif', 'Al Bahah', 'Al Battaliyah', 
                                                    'Al Bukayriyah', 'Al Hawtah', 'Al Hofuf', 'Al Jawf', 'Al Jubail', 'Al Kharj', 
                                                    'Al Khobar', 'Al Lith', 'Al Majma\'ah', 'Al Mithnab', 'Al Mubarraz', 'Al Muzahmiyah', 
                                                    'Al Namas', 'Al Omran', 'Al Qaṭif', 'Al Qurayyat', 'Al Qunfudhah', 'Al Rass', 
                                                    'Al Ula', 'Al Wajh', 'Al Zulfi', 'Arar', 'Ar Ranyah', 'As Sulayyil', 'Ash Shafa',
                                                    'At Tuwal', 'Baljurashi', 'Badr', 'Bisha', 'Buraidah', 'Dammam', 'Dawadmi', 'Dhahran', 
                                                    'Diriyah', 'Dumat Al Jandal', 'Farrasan', 'Hafar Al-Batin', 'Hail', 'Hotat Bani Tamim', 
                                                    'Jazan', 'Jeddah', 'Jubail', 'Khafji', 'Khamis Mushait', 'Khaybar', 'Khobar', 
                                                    'Mecca', 'Medina', 'Najran', 'Qatif', 'Rabigh', 'Ras Tanura', 'Riyadh', 'Sakaka', 
                                                    'Samtah', 'Sayhat', 'Sharurah', 'Shaqra', 'Tabouk', 'Taif', 'Tarut', 'Thadiq', 
                                                    'Thuwal', 'Tumayr', 'Turabah', 'Umluj', 'Unaizah', 'Yanbu'
                                                ];
                                                
                                                foreach ($locations as $city) {
                                                    $selected = ($formData['location'] === $city) ? 'selected' : '';
                                                    echo "<option value=\"$city\" $selected>$city</option>";
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="col-lg-6">

                                        
                                        <div class="mb-3">
                                            <label for="education" class="form-label">Education / Certifications</label>
                                            <textarea class="form-control" id="education" name="education" rows="3"><?php echo htmlspecialchars($formData['education']); ?></textarea>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="bio" class="form-label">Bio / About</label>
                                            <textarea class="form-control" id="bio" name="bio" rows="5"><?php echo htmlspecialchars($formData['bio']); ?></textarea>
                                            <div class="form-text">Brief description of technician's background and expertise</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <a href="technicians.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-user-plus me-2"></i>Add Technician
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Theme toggle functionality
            const themeToggleBtn = document.getElementById('themeToggle');
            const htmlElement = document.documentElement;
            const themeIcon = document.getElementById('themeIcon');
            
            // Function to set theme
            function setTheme(isDark) {
                if (isDark) {
                    htmlElement.setAttribute('data-bs-theme', 'dark');
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                    localStorage.setItem('theme', 'dark');
                } else {
                    htmlElement.setAttribute('data-bs-theme', 'light');
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                    localStorage.setItem('theme', 'light');
                }
            }
            
            // Check for saved theme preference or prefer-color-scheme
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) {
                setTheme(savedTheme === 'dark');
            } else {
                // Default to dark theme
                setTheme(true);
            }
            
            // Toggle theme when button is clicked
            themeToggleBtn.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                setTheme(currentTheme !== 'dark');
            });
            
            // Profile image preview
            const profileImageInput = document.getElementById('profile_image');
            const profilePreview = document.getElementById('profilePreview');
            
            profileImageInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    const reader = new FileReader();
                    
                    reader.onload = function(e) {
                        // Create image element
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        
                        // Clear preview and add image
                        profilePreview.innerHTML = '';
                        profilePreview.appendChild(img);
                    };
                    
                    reader.readAsDataURL(this.files[0]);
                }
            });
            
            // Password confirmation validation
            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            
            confirmPasswordInput.addEventListener('input', function() {
                if (this.value !== passwordInput.value) {
                    this.setCustomValidity('Passwords do not match');
                } else {
                    this.setCustomValidity('');
                }
            });
            
            passwordInput.addEventListener('input', function() {
                if (confirmPasswordInput.value !== '') {
                    if (confirmPasswordInput.value !== this.value) {
                        confirmPasswordInput.setCustomValidity('Passwords do not match');
                    } else {
                        confirmPasswordInput.setCustomValidity('');
                    }
                }
            });
        });
    </script>
</body>
</html>