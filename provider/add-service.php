<?php
// Start the session at the very beginning
session_start();

// Check if user is logged in and is a provider
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'provider') {
    // Redirect to login page
    header('Location: ../login.php');
    exit();
}

// Include database connection
require_once 'conn.php';

// Define a function for secure output
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Get user data with prepared statement
$userId = $_SESSION['user_id'];
$userQuery = "SELECT u.*, p.* FROM users u 
              LEFT JOIN providers p ON p.user_id = u.id 
              WHERE u.id = ? AND u.role = 'provider'";
$stmt = $conn->prepare($userQuery);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();

// Verify user data exists
if (!$userData) {
    // Handle invalid user session
    session_destroy();
    header('Location: ../login.php?error=invalid_session');
    exit();
}

// Get provider ID with null check
$providerId = $userData['provider_id'] ?? $userData['id'] ?? null;

if (!$providerId) {
    // Log the error for debugging
    error_log('Provider profile not found for user ID: ' . $userId);
    
    // Show more helpful error message with options
    echo '
    <div class="container mt-5">
        <div class="alert alert-danger p-5 text-center">
            <h2><i class="fas fa-exclamation-triangle mb-3"></i> Provider Profile Not Found</h2>
            <p class="mb-4">Your user account exists but we couldn\'t find your provider profile.</p>
            <div class="mb-4">This may happen if:</div>
            <ul class="list-unstyled mb-4 text-start">
                <li><i class="fas fa-check-circle me-2"></i> Your account registration is incomplete</li>
                <li><i class="fas fa-check-circle me-2"></i> Your provider profile hasn\'t been approved yet</li>
                <li><i class="fas fa-check-circle me-2"></i> There was a system error during your registration</li>
            </ul>
            <div class="d-flex justify-content-center gap-3">
                <a href="../support.php" class="btn btn-primary">Contact Support</a>
                <a href="../logout.php" class="btn btn-outline-secondary">Logout</a>
            </div>
        </div>
    </div>';
    exit();
}

// Initialize variables
$success_message = '';
$error_message = '';
$formData = [
    'name' => '',
    'category' => '',
    'description' => '',
    'price' => '',
    'duration' => '',
    'is_active' => 1
];

// Get all categories for the dropdown
$categoriesQuery = "SELECT DISTINCT category FROM services WHERE category != '' ORDER BY category";
$categoriesResult = $conn->query($categoriesQuery);
$categories = [];

if ($categoriesResult && $categoriesResult->num_rows > 0) {
    while ($row = $categoriesResult->fetch_assoc()) {
        $categories[] = $row['category'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error_message = 'Security validation failed. Please try again.';
    } else {
        // Collect form data
        $formData = [
            'name' => $_POST['name'] ?? '',
            'category' => $_POST['category'] ?? '',
            'description' => $_POST['description'] ?? '',
            'price' => $_POST['price'] ?? '',
            'duration' => $_POST['duration'] ?? '',
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];

        // Validate form data
        $errors = [];

        if (empty($formData['name'])) {
            $errors[] = 'Service name is required';
        }

        if (empty($formData['category'])) {
            $errors[] = 'Category is required';
        }

        if (empty($formData['description'])) {
            $errors[] = 'Description is required';
        } elseif (strlen($formData['description']) > 500) {
            $errors[] = 'Description cannot exceed 500 characters';
        }

        if (empty($formData['price'])) {
            $errors[] = 'Price is required';
        } elseif (!is_numeric($formData['price']) || $formData['price'] <= 0) {
            $errors[] = 'Price must be a positive number';
        }

        if (empty($formData['duration'])) {
            $errors[] = 'Duration is required';
        } elseif (!is_numeric($formData['duration']) || $formData['duration'] <= 0) {
            $errors[] = 'Duration must be a positive number';
        }

        // If validation passes, insert new service
        if (empty($errors)) {
            try {
                // Prepare the SQL query
                $insertQuery = "INSERT INTO services (
                    provider_id, 
                    name, 
                    category, 
                    description, 
                    price, 
                    duration, 
                    is_active, 
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

                $stmt = $conn->prepare($insertQuery);
                $stmt->bind_param(
                    "isssdii",
                    $providerId,
                    $formData['name'],
                    $formData['category'],
                    $formData['description'],
                    $formData['price'],
                    $formData['duration'],
                    $formData['is_active']
                );

                $result = $stmt->execute();

                if ($result) {
                    $success_message = 'Service added successfully!';
                    
                    // Reset form data after successful submission
                    $formData = [
                        'name' => '',
                        'category' => '',
                        'description' => '',
                        'price' => '',
                        'duration' => '',
                        'is_active' => 1
                    ];

                    // Redirect to dashboard with success message after 2 seconds
                    header("refresh:2;url=dashboard.php?success=1&message=" . urlencode('Service added successfully!'));
                } else {
                    $error_message = 'Failed to add service. Please try again.';
                }
            } catch (Exception $e) {
                $error_message = 'An error occurred: ' . $e->getMessage();
                error_log('Service addition error: ' . $e->getMessage());
            }
        } else {
            // Combine all validation errors
            $error_message = implode('<br>', $errors);
        }
    }
}

// Get top 5 services for reference
$topServicesQuery = "SELECT * FROM services WHERE provider_id = ? ORDER BY created_at DESC LIMIT 5";
$stmt = $conn->prepare($topServicesQuery);
$stmt->bind_param("i", $providerId);
$stmt->execute();
$topServicesResult = $stmt->get_result();

// Set safe profile image URL
$profileImage = '../default.png'; // Default image
if (!empty($userData['profile_image'])) {
    // Check if the profile image starts with http:// or https://
    if (preg_match('/^https?:\/\//', $userData['profile_image'])) {
        $profileImage = $userData['profile_image'];
    } else {
        // Use full path for uploaded image with basename for security
        $profileImage = '/uploads/profile_images/' . basename($userData['profile_image']);
    }
}

// Close the database connection
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline'; font-src 'self' https://cdnjs.cloudflare.com; img-src 'self' data: https:">
    <title>Add Service - <?php echo e($userData['first_name'] ?? 'Provider'); ?></title>
    
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    
    <style>
        :root {
            --primary: #3a6ad4;
            --primary-dark: #2a4dae;
            --primary-light: #4e7ed1;
            --primary-lighter: #e8f0ff;
            --success: #28a745;
            --success-soft: #d4edda;
            --warning: #ffc107;
            --warning-soft: #fff3cd;
            --danger: #dc3545;
            --info: #17a2b8;
            --info-soft: #d1ecf1;
            --gray-700: #495057;
        }
        
        body {
            background-color: #f5f7fb;
        }
        
        .dashboard-wrapper {
            display: flex;
            min-height: calc(100vh - 56px);
        }
        
        .dashboard-sidebar {
            width: 280px;
            background-color: #ffffff;
            box-shadow: 0 0 15px rgba(0,0,0,0.05);
            position: fixed;
            height: calc(100vh - 56px);
            overflow-y: auto;
            z-index: 100;
            left: 0; 
        }
        
        .dashboard-main {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
        }
        
        .sidebar-profile {
            padding: 1.5rem;
            text-align: center;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .sidebar-menu {
            padding: 1rem 0;
        }
        
        .menu-item {
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            color: #495057;
            text-decoration: none;
            border-left: 4px solid transparent;
            transition: all 0.2s;
        }
        
        .menu-item:hover {
            background-color: var(--primary-lighter);
            color: var(--primary);
            border-left-color: var(--primary-light);
        }
        
        .menu-item.active {
            background-color: var(--primary-lighter);
            color: var(--primary);
            border-left-color: var(--primary);
            font-weight: 600;
        }
        
        .menu-item i {
            width: 24px;
            margin-right: 10px;
            text-align: center;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .page-header::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            background-image: url("data:image/svg+xml,%3Csvg width='100' height='100' viewBox='0 0 120 120' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M9 0h2v20H9V0zm25.134.84l1.732 1-10 17.32-1.732-1 10-17.32zm-20 20l1.732 1-10 17.32-1.732-1 10-17.32zM58.16 4.134l1 1.732-17.32 10-1-1.732 17.32-10zm-40 40l1 1.732-17.32 10-1-1.732 17.32-10zM80 9v2H60V9h20zM20 69v2H0v-2h20zm79.32-55l-1 1.732-17.32-10L82 4l17.32 10zm-80 80l-1 1.732-17.32-10L2 84l17.32 10zm96.546-75.84l-1.732 1-10-17.32 1.732-1 10 17.32zm-100 100l-1.732 1-10-17.32 1.732-1 10 17.32zM38.16 24.134l1 1.732-17.32 10-1-1.732 17.32-10zM60 29v2H40v-2h20zm19.32 5l-1 1.732-17.32-10L62 24l17.32 10zm16.546 4.16l-1.732 1-10-17.32 1.732-1 10 17.32zM111 40h-2V20h2v20zm3.134.84l1.732 1-10 17.32-1.732-1 10-17.32zM40 49v2H20v-2h20zm19.32 5l-1 1.732-17.32-10L42 44l17.32 10zm16.546 4.16l-1.732 1-10-17.32 1.732-1 10 17.32zM91 60h-2V40h2v20zm3.134.84l1.732 1-10 17.32-1.732-1 10-17.32zm24.026 3.294l1 1.732-17.32 10-1-1.732 17.32-10zM39.32 74l-1 1.732-17.32-10L22 64l17.32 10zm16.546 4.16l-1.732 1-10-17.32 1.732-1 10 17.32zM71 80h-2V60h2v20zm3.134.84l1.732 1-10 17.32-1.732-1 10-17.32zm24.026 3.294l1 1.732-17.32 10-1-1.732 17.32-10zM120 89v2h-20v-2h20zm-84.134 9.16l-1.732 1-10-17.32 1.732-1 10 17.32zM51 100h-2V80h2v20zm3.134.84l1.732 1-10 17.32-1.732-1 10-17.32zm24.026 3.294l1 1.732-17.32 10-1-1.732 17.32-10zM100 109v2H80v-2h20zm19.32 5l-1 1.732-17.32-10 1-1.732 17.32 10zM31 120h-2v-20h2v20z' fill='%23ffffff' fill-opacity='0.05' fill-rule='evenodd'/%3E%3C/svg%3E");
            opacity: 0.3;
        }
        
        .dashboard-card {
            border-radius: 1rem;
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
            overflow: hidden;
            margin-bottom: 1.5rem;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
        }
        
        .dashboard-card .card-header {
            background-color: #fff;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 1.25rem 1.5rem;
            font-weight: 600;
        }
        
        .service-card {
            border: none;
            border-radius: 0.75rem;
            overflow: hidden;
            transition: all 0.3s;
            margin-bottom: 1rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
        }
        
        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
        }
        
        .service-card .card-body {
            padding: 1.25rem;
        }
        
        .avatar-lg {
            width: 5rem;
            height: 5rem;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.1);
        }
        
        .form-floating > label {
            color: var(--gray-700);
        }
        
        .form-floating:not(.form-control:disabled)::before {
            background-color: transparent;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.25rem rgba(58, 106, 212, 0.25);
        }
        
        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .category-item {
            background-color: var(--primary-lighter);
            color: var(--primary);
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            display: inline-block;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .category-item:hover {
            background-color: var(--primary-light);
            color: white;
        }
        
        .btn-float-end {
            position: absolute;
            right: 2rem;
            top: 50%;
            transform: translateY(-50%);
        }
        
        @media (max-width: 991.98px) {
            .dashboard-sidebar {
                display: none;
            }
            
            .dashboard-main {
                margin-left: 0;
            }
            
            .mobile-menu-toggle {
                display: block;
            }
        }
    </style>
</head>
<body>
    <!-- Top Navbar -->


    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="dashboard-sidebar">
            <div class="sidebar-profile">
                <img src="<?php echo e($profileImage); ?>" alt="Profile" class="avatar-lg mb-3" 
                     onerror="this.src='../images/provider.png'">
                <h5 class="mb-1"><?php echo e($userData['first_name'] . ' ' . $userData['last_name']); ?></h5>
                <p class="text-muted small mb-3">Service Provider</p>
                <div class="d-grid">
                    <a href="profile.php" class="btn btn-sm btn-outline-primary">View Profile</a>
                </div>
            </div>
            
            <div class="sidebar-menu">
                <a href="dashboard.php" class="menu-item">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="bookings.php" class="menu-item">
                    <i class="fas fa-calendar-check"></i> My Bookings
                </a>
                <a href="services.php" class="menu-item active">
                    <i class="fas fa-tools"></i> My Services
                </a>
                <a href="reviews.php" class="menu-item">
                    <i class="fas fa-star"></i> Reviews
                </a>
                <a href="messages.php" class="menu-item">
                    <i class="fas fa-comments"></i> Messages
                </a>
                <a href="earnings.php" class="menu-item">
                    <i class="fas fa-dollar-sign"></i> Earnings
                </a>
                
                <hr class="my-3">
                
                <a href="profile.php" class="menu-item">
                    <i class="fas fa-user-cog"></i> Account Settings
                </a>
                <a href="help.php" class="menu-item">
                    <i class="fas fa-question-circle"></i> Help & Support
                </a>
                <a href="../logout.php" class="menu-item text-danger">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </aside>

        <!-- Mobile Sidebar Toggle -->
        <div class="d-lg-none fixed-top m-3">
            <button class="btn btn-primary rounded-circle shadow sidebar-toggle" type="button">
                <i class="fas fa-bars"></i>
            </button>
        </div>

        <!-- Main Content -->
        <main class="dashboard-main">
            <!-- Page Header -->
            <div class="page-header position-relative">
                <div class="row">
                    <div class="col-lg-8">
                        <h1 class="mb-2"><i class="fas fa-plus-circle me-2"></i>Add New Service</h1>
                        <p class="lead mb-0">Create a new repair service to offer to your customers</p>
                    </div>
                </div>
                <a href="services.php" class="btn btn-light btn-lg btn-float-end d-none d-lg-block">
                    <i class="fas fa-arrow-left me-2"></i>Back to Services
                </a>
            </div>
            
            <!-- Mobile back button -->
            <div class="d-lg-none mb-3">
                <a href="services.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Services
                </a>
            </div>
            
            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <div class="row">
                <!-- Form Section -->
                <div class="col-lg-8">
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-tools me-2 text-primary"></i>Service Information</h5>
                        </div>
                        <div class="card-body">
                            <form action="" method="POST" id="addServiceForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <div class="mb-4">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="serviceName" name="name" 
                                               placeholder="Enter service name" value="<?php echo e($formData['name']); ?>" required>
                                        <label for="serviceName">Service Name*</label>
                                    </div>
                                    <small class="text-muted">Choose a clear, specific name for your service (e.g., "iPhone Screen Repair")</small>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="form-label">Category*</label>
                                    
                                    <div class="input-group mb-2">
                                        <input type="text" class="form-control" id="serviceCategory" name="category" list="categoryOptions"
                                               placeholder="Select or enter a category" value="<?php echo e($formData['category']); ?>" required>
                                        <datalist id="categoryOptions">
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?php echo e($category); ?>">
                                            <?php endforeach; ?>
                                        </datalist>
                                    </div>
                                    
                                    <?php if (!empty($categories)): ?>
                                        <div class="mb-2">
                                            <small class="text-muted d-block mb-2">Popular categories:</small>
                                            <?php foreach (array_slice($categories, 0, 6) as $category): ?>
                                                <span class="category-item" onclick="selectCategory('<?php echo e($category); ?>')">
                                                    <?php echo e($category); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <small class="text-muted">Choose an existing category or create a new one</small>
                                </div>
                                
                                <div class="mb-4">
                                    <div class="form-floating">
                                        <textarea class="form-control" id="serviceDescription" name="description" 
                                                style="height: 120px" placeholder="Enter service description" required><?php echo e($formData['description']); ?></textarea>
                                        <label for="serviceDescription">Description*</label>
                                    </div>
                                    <small class="text-muted">Provide a detailed description of what this service includes (max 500 characters)</small>
                                </div>
                                
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <input type="number" class="form-control" id="servicePrice" name="price" min="0.01" step="0.01" 
                                                   placeholder="Enter price" value="<?php echo e($formData['price']); ?>" required>
                                            <label for="servicePrice">Price ($)*</label>
                                        </div>
                                        <small class="text-muted">Set the base price for this service</small>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <input type="number" class="form-control" id="serviceDuration" name="duration" min="15" step="15" 
                                                   placeholder="Enter duration" value="<?php echo e($formData['duration']); ?>" required>
                                            <label for="serviceDuration">Duration (minutes)*</label>
                                        </div>
                                        <small class="text-muted">Estimate how long this service typically takes</small>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="serviceActive" name="is_active" 
                                               <?php echo $formData['is_active'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="serviceActive">Make service active immediately</label>
                                    </div>
                                    <small class="text-muted">If checked, customers will be able to book this service right away</small>
                                </div>
                                
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="services.php" class="btn btn-outline-secondary">Cancel</a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-plus-circle me-2"></i>Add Service
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Sidebar Section -->
                <div class="col-lg-4">
                    <!-- Tips Card -->
                    <div class="dashboard-card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-lightbulb me-2 text-warning"></i>Tips for Success</h5>
                        </div>
                        <div class="card-body">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item px-0">
                                    <div class="d-flex">
                                        <div class="me-3 text-primary">
                                            <i class="fas fa-check-circle fa-lg"></i>
                                        </div>
                                        <div>
                                            <h6 class="mb-1">Be Specific</h6>
                                            <p class="small text-muted mb-0">Clear service names and descriptions help customers find exactly what they need.</p>
                                        </div>
                                    </div>
                                </li>
                                <li class="list-group-item px-0">
                                    <div class="d-flex">
                                        <div class="me-3 text-primary">
                                            <i class="fas fa-check-circle fa-lg"></i>
                                        </div>
                                        <div>
                                            <h6 class="mb-1">Accurate Pricing</h6>
                                            <p class="small text-muted mb-0">Set competitive prices that reflect your expertise and the quality of your work.</p>
                                        </div>
                                    </div>
                                </li>
                                <li class="list-group-item px-0">
                                    <div class="d-flex">
                                        <div class="me-3 text-primary">
                                            <i class="fas fa-check-circle fa-lg"></i>
                                        </div>
                                        <div>
                                            <h6 class="mb-1">Realistic Duration</h6>
                                            <p class="small text-muted mb-0">Estimate duration accurately to manage customer expectations and your schedule.</p>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Recent Services Card -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-history me-2 text-primary"></i>Your Recent Services</h5>
                        </div>
                        <div class="card-body">
                            <?php if (isset($topServicesResult) && $topServicesResult->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($service = $topServicesResult->fetch_assoc()): ?>
                                        <div class="list-group-item px-0">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?php echo e($service['name']); ?></h6>
                                                    <p class="mb-0 small">
                                                        <span class="text-primary me-2">$<?php echo number_format($service['price'], 2); ?></span>
                                                        <span class="text-muted"><i class="far fa-clock me-1"></i><?php echo e($service['duration']); ?> mins</span>
                                                    </p>
                                                </div>
                                                <span class="badge <?php echo $service['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo $service['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                                <div class="mt-3">
                                    <a href="services.php" class="btn btn-sm btn-outline-primary d-block">View All Services</a>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <img src="../images/illustrations/no-data.svg" alt="No services" class="img-fluid mb-3" style="max-width: 150px;">
                                    <h6>No services yet</h6>
                                    <p class="text-muted small">This is your first service! Fill out the form to get started.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

    <!-- Custom JavaScript -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle mobile sidebar
        const sidebarToggle = document.querySelector('.sidebar-toggle');
        const dashboardSidebar = document.querySelector('.dashboard-sidebar');
        
        if (sidebarToggle && dashboardSidebar) {
            sidebarToggle.addEventListener('click', function() {
                dashboardSidebar.classList.toggle('show');
                document.body.classList.toggle('sidebar-open');
            });
            
            // Close sidebar when clicking outside
            document.addEventListener('click', function(e) {
                if (dashboardSidebar.classList.contains('show') && 
                    !dashboardSidebar.contains(e.target) && 
                    !sidebarToggle.contains(e.target)) {
                    dashboardSidebar.classList.remove('show');
                    document.body.classList.remove('sidebar-open');
                }
            });
        }
        
        // Auto-dismiss alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                const closeButton = alert.querySelector('.btn-close');
                if (closeButton) {
                    closeButton.click();
                }
            }, 5000);
        });
        
        // Character counter for description
        const descriptionField = document.getElementById('serviceDescription');
        if (descriptionField) {
            descriptionField.addEventListener('input', function() {
                const maxLength = 500;
                const currentLength = this.value.length;
                
                if (currentLength > maxLength) {
                    this.value = this.value.substring(0, maxLength);
                }
            });
        }
        
        // Form validation
        const form = document.getElementById('addServiceForm');
        if (form) {
            form.addEventListener('submit', function(event) {
                let isValid = true;
                
                // Validate name
                const nameField = document.getElementById('serviceName');
                if (!nameField.value.trim()) {
                    nameField.classList.add('is-invalid');
                    isValid = false;
                } else {
                    nameField.classList.remove('is-invalid');
                }
                
                // Validate category
                const categoryField = document.getElementById('serviceCategory');
                if (!categoryField.value.trim()) {
                    categoryField.classList.add('is-invalid');
                    isValid = false;
                } else {
                    categoryField.classList.remove('is-invalid');
                }
                
                // Validate description
                if (!descriptionField.value.trim()) {
                    descriptionField.classList.add('is-invalid');
                    isValid = false;
                } else if (descriptionField.value.length > 500) {
                    descriptionField.classList.add('is-invalid');
                    isValid = false;
                } else {
                    descriptionField.classList.remove('is-invalid');
                }
                
                // Validate price
                const priceField = document.getElementById('servicePrice');
                if (!priceField.value || parseFloat(priceField.value) <= 0) {
                    priceField.classList.add('is-invalid');
                    isValid = false;
                } else {
                    priceField.classList.remove('is-invalid');
                }
                
                // Validate duration
                const durationField = document.getElementById('serviceDuration');
                if (!durationField.value || parseInt(durationField.value) <= 0) {
                    durationField.classList.add('is-invalid');
                    isValid = false;
                } else {
                    durationField.classList.remove('is-invalid');
                }
                
                if (!isValid) {
                    event.preventDefault();
                }
            });
        }
    });
    
    // Function to select a category from the suggestions
    function selectCategory(category) {
        document.getElementById('serviceCategory').value = category;
    }
    </script>
</body>
</html>