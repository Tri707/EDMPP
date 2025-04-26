<?php
// Start session securely
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// Check if user is already logged in
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    // Redirect based on role
    if ($_SESSION['role'] === 'customer') {
        header('Location: customer/dashboard.php');
        exit;
    } elseif ($_SESSION['role'] === 'provider') {
        header('Location: provider/dashboard.php');
        exit;
    } elseif ($_SESSION['role'] === 'admin') {
        header('Location: admin/dashboard.php');
        exit;
    }
}

// List of Saudi cities
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

// Initialize variables
$errorMessage = '';
$successMessage = '';
$formData = [
    'username' => '',
    'email' => '',
    'first_name' => '',
    'last_name' => '',
    'phone' => '',
    'location' => '',
    'role' => 'customer',
    'specialties' => [],
    'experience' => '',
    'bio' => ''
];

// Process registration form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Database connection
    include 'conn.php';
    
    // Get form data
    $formData = [
        'username' => trim($_POST['username']),
        'email' => trim($_POST['email']),
        'password' => $_POST['password'],
        'confirm_password' => $_POST['confirm_password'],
        'role' => $_POST['role'],
        'first_name' => trim($_POST['first_name']),
        'last_name' => trim($_POST['last_name']),
        'phone' => trim($_POST['phone']),
        'location' => trim($_POST['location']),
        'specialties' => isset($_POST['specialties']) ? $_POST['specialties'] : [],
        'experience' => isset($_POST['experience']) ? trim($_POST['experience']) : '',
        'bio' => isset($_POST['bio']) ? trim($_POST['bio']) : ''
    ];
    
    // Validate input
    if (empty($formData['username']) || empty($formData['email']) || empty($formData['password']) || 
        empty($formData['confirm_password']) || empty($formData['first_name']) || 
        empty($formData['last_name']) || empty($formData['phone']) || empty($formData['location'])) {
        $errorMessage = "All fields marked with * are required";
    } elseif ($formData['password'] !== $formData['confirm_password']) {
        $errorMessage = "Passwords do not match";
    } elseif (strlen($formData['password']) < 8) {
        $errorMessage = "Password must be at least 8 characters long";
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errorMessage = "Invalid email format";
    } elseif ($formData['role'] === 'provider' && empty($formData['specialties'])) {
        $errorMessage = "Please select at least one specialty";
    } else {
        // Check if username or email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $formData['username'], $formData['email']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $errorMessage = "Username or email already exists";
        } else {
            // Hash password
            $hashedPassword = password_hash($formData['password'], PASSWORD_DEFAULT);
            
            // Insert new user
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, first_name, last_name, phone, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->bind_param("sssssss", 
                $formData['username'], 
                $formData['email'], 
                $hashedPassword, 
                $formData['role'], 
                $formData['first_name'], 
                $formData['last_name'], 
                $formData['phone']
            );
            
            if ($stmt->execute()) {
                $userId = $stmt->insert_id;
                
                // If registering as provider, also insert into providers table
                if ($formData['role'] === 'provider') {
                    $specialties = implode(',', $formData['specialties']);
                    $providerStmt = $conn->prepare("INSERT INTO providers (user_id, specialties, experience, bio, location, is_verified) VALUES (?, ?, ?, ?, ?, 0)");
                    $providerStmt->bind_param("issss", 
                        $userId, 
                        $specialties, 
                        $formData['experience'], 
                        $formData['bio'], 
                        $formData['location']
                    );
                    $providerStmt->execute();
                    $providerStmt->close();
                }
                
                $successMessage = "Registration successful! You can now <a href='login.php'>log in</a>.";
                // Clear form data
                $formData = [
                    'username' => '',
                    'email' => '',
                    'first_name' => '',
                    'last_name' => '',
                    'phone' => '',
                    'location' => '',
                    'role' => 'customer',
                    'specialties' => [],
                    'experience' => '',
                    'bio' => ''
                ];
            } else {
                $errorMessage = "Registration failed. Please try again.";
            }
        }
        
        $stmt->close();
        $conn->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="Register for FixItNow - Service Booking Platform for Repairs and Maintenance">
    <title>Register - FixItNow</title>
    
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    
    <style>
        /* Theme CSS Variables */
        :root {
            --primary-color: #7952b3;      /* Purple */
            --primary-hover: #6941a0;      /* Darker Purple */
            --primary-light: #e4dafc;      /* Light Purple */
            --accent-color: #37b24d;       /* Green */
            --accent-light: #d3f9d8;       /* Light Green */
            --text-color: #212529;         /* Dark text for light mode */
            --text-muted: #6c757d;         /* Muted text for light mode */
            --bg-color: #f8f9fa;           /* Light background */
            --card-bg: #ffffff;            /* Card background */
            --header-bg: #212529;          /* Header background */
            --header-text: #ffffff;        /* Header text */
            --footer-bg: #212529;          /* Footer background */
            --footer-text: #e9ecef;        /* Footer text */
            --border-color: #dee2e6;       /* Border color */
            --input-bg: #ffffff;           /* Input background */
            --input-border: #ced4da;       /* Input border */
            --shadow-color: rgba(0, 0, 0, 0.1); /* Shadow color */
        }
            
        /* Dark Theme Variables */
        [data-bs-theme="dark"] {
            --primary-color: #a687ff;      /* More vibrant purple */
            --primary-hover: #9775fa;      /* Brighter purple hover */
            --primary-light: #473a6b;      /* Less dark purple for better contrast */
            --accent-color: #4cd963;       /* More vibrant green */
            --accent-light: #2a7d3f;       /* Brighter green light */
            --text-color: #f8f9fa;         /* Brighter white text */
            --text-muted: #c5cfd8;         /* Less muted text */
            --bg-color: #212529;           /* Slightly less dark background */
            --card-bg: #2c3034;            /* Less dark card background */
            --header-bg: #151518;          /* Slightly adjusted header */
            --header-text: #ffffff;        /* Pure white header text */
            --border-color: #3d4349;       /* More visible border */
            --input-bg: #323237;           /* Slightly lighter input background */
            --input-border: #5a5a66;       /* More visible input border */
            --shadow-color: rgba(0, 0, 0, 0.35); /* Slightly stronger shadow */
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        /* Header Styles */
        .site-header {
            background-color: var(--header-bg);
            padding: 0.75rem 0;
            color: var(--header-text);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 15px var(--shadow-color);
        }
        
        .logo-text {
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            color: var(--header-text);
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: transform 0.2s;
        }
        
        .logo-text:hover {
            transform: scale(1.02);
            color: var(--header-text);
        }
        
        .logo-text .highlight {
            color: var(--accent-color);
            font-weight: 900;
        }
        
        /* Navigation Styles */
        .nav-button {
            display: flex;
            align-items: center;
            padding: 0.6rem 1.1rem;
            border-radius: 0.375rem;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: all 0.2s ease;
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .nav-button:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--header-text);
            transform: translateY(-1px);
        }
        
        .nav-button.active {
            background-color: var(--primary-color);
            color: white;
            box-shadow: 0 2px 8px rgba(121, 82, 179, 0.4);
        }
        
        .nav-button i {
            margin-right: 0.5rem;
            font-size: 0.9rem;
        }
        
        /* Theme Toggle Button */
        .theme-toggle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            border: none;
            color: rgba(255, 255, 255, 0.85);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 10px;
        }
        
        .theme-toggle:hover {
            background-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
            color: #fff;
        }
        
        /* Registration Form Styles */
        .auth-section {
            padding: 3rem 0;
            min-height: calc(100vh - 180px);
        }
        
        .auth-card {
            border-radius: 1rem;
            overflow: hidden;
            background-color: var(--card-bg);
            box-shadow: 0 5px 25px var(--shadow-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .auth-card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-hover) 100%);
            color: white;
            padding: 2rem 1.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .auth-card-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI1MCIgaGVpZ2h0PSI1MCI+CjxyZWN0IHdpZHRoPSI1MCIgaGVpZ2h0PSI1MCIgZmlsbD0ibm9uZSIvPgo8cGF0aCBkPSJNMjUsMzAgTDUwLDAgTDUwLDUwIEwwLDUwIEwwLDI1IFoiIGZpbGw9InJnYmEoMjU1LDI1NSwyNTUsMC4wNSkiLz4KPC9zdmc+') repeat;
            opacity: 0.3;
        }
        
        .auth-card-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
        }
        
        .auth-card-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            position: relative;
        }
        
        .auth-card-body {
            padding: 2rem;
        }
        
        .form-control, .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 0.25rem rgba(121, 82, 179, 0.25);
            border-color: var(--primary-color);
        }
        
        .input-group-text {
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-color: var(--input-border);
            border-radius: 0.5rem 0 0 0.5rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-hover) 100%);
            border: none;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(121, 82, 179, 0.3);
        }
        
        /* User Type Selector */
        .user-type-selector {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .user-type-card {
            flex: 1;
            border: 2px solid var(--border-color);
            border-radius: 0.75rem;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 3px 10px var(--shadow-color);
        }
        
        .user-type-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background-color: transparent;
            transition: background-color 0.3s ease;
        }
        
        .user-type-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px var(--shadow-color);
        }
        
        .user-type-card.active {
            border-color: var(--primary-color);
        }
        
        .user-type-card.active::before {
            background-color: var(--primary-color);
        }
        
        .user-type-card.active::after {
            content: "✓";
            position: absolute;
            top: 10px;
            right: 10px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }
        
        .user-type-icon {
            position: relative;
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1rem;
            transition: all 0.3s ease;
        }
        
        .user-type-card.active .user-type-icon {
            background-color: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }
        
        /* Specialty Badges */
        .specialty-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .specialty-badge {
            display: inline-block;
            border: 1px solid var(--border-color);
            border-radius: 50px;
            padding: 0.5rem 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            background-color: var(--bg-color);
        }
        
        .specialty-badge:hover {
            border-color: var(--primary-color);
            background-color: var(--primary-light);
            transform: translateY(-2px);
        }
        
        .specialty-badge.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .specialty-badge .specialty-icon {
            margin-right: 0.5rem;
        }
        
        /* Experience Selector */
        .experience-selector {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        
        .experience-option {
            flex: 1;
            text-align: center;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 0.75rem 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .experience-option:hover {
            border-color: var(--primary-color);
            background-color: var(--primary-light);
            transform: translateY(-2px);
        }
        
        .experience-option.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .experience-option .experience-years {
            font-weight: bold;
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }
        
        .experience-option .experience-level {
            font-size: 0.85rem;
            opacity: 0.8;
        }
        
        /* Technician Card */
        .technician-card {
            border-radius: 0.75rem;
            border: 1px solid var(--border-color);
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: 0 3px 10px var(--shadow-color);
            transition: all 0.3s ease;
        }
        
        .technician-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px var(--shadow-color);
        }
        
        .technician-card-header {
            background-color: var(--primary-light);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
        }
        
        .technician-icon {
            font-size: 1.5rem;
            color: var(--primary-color);
            margin-right: 0.75rem;
        }
        
        .technician-card-body {
            padding: 1.5rem;
        }
        
        /* Password Strength */
        .password-strength {
            height: 5px;
            margin-top: 0.5rem;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .password-strength-meter {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background-color 0.3s ease;
        }
        
        .password-strength-text {
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }
        
        /* Divider */
        .divider {
            text-align: center;
            margin: 1.5rem 0;
            display: flex;
            align-items: center;
            color: var(--text-muted);
        }
        
        .divider:before,
        .divider:after {
            content: "";
            flex: 1;
            border-bottom: 1px solid var(--border-color);
        }
        
        .divider:before {
            margin-right: 1rem;
        }
        
        .divider:after {
            margin-left: 1rem;
        }
        
        /* Form Validation Styles */
        .was-validated .form-control:invalid,
        .was-validated .form-select:invalid {
            border-color: #dc3545;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }
        
        .was-validated .form-control:valid,
        .was-validated .form-select:valid {
            border-color: #198754;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }

        /* Responsive adjustments */
        @media (max-width: 767.98px) {
            .user-type-selector {
                flex-direction: column;
            }
            
            .experience-selector {
                flex-wrap: wrap;
            }
            
            .experience-option {
                min-width: calc(50% - 0.375rem);
            }
            
            .auth-card-header {
                padding: 1.5rem 1rem;
            }
            
            .auth-card-body {
                padding: 1.5rem;
            }
        }
        /* Technician Information Styles */
.technician-card {
    border-radius: 1rem;
    border: none;
    overflow: hidden;
    margin-bottom: 2rem;
    box-shadow: 0 5px 20px var(--shadow-color);
    background-color: var(--card-bg);
    transition: all 0.3s ease;
}

.technician-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 30px var(--shadow-color);
}

.technician-card-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    position: relative;
    overflow: hidden;
}

.technician-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background-color: rgba(255, 255, 255, 0.2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-right: 1rem;
    flex-shrink: 0;
}

/* Specialty Grid */
.specialty-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
}

.specialty-card {
    display: flex;
    align-items: center;
    border: 2px solid var(--border-color);
    border-radius: 1rem;
    padding: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    background-color: var(--bg-color);
    height: 100%;
    position: relative;
    overflow: hidden;
}

.specialty-card:hover {
    transform: translateY(-5px);
    border-color: var(--primary-color);
    box-shadow: 0 5px 15px var(--shadow-color);
}

.specialty-card.active {
    border-color: var(--primary-color);
    background-color: var(--primary-light);
}

.specialty-card.active::after {
    content: "✓";
    position: absolute;
    top: 5px;
    right: 10px;
    color: var(--primary-color);
    font-size: 1rem;
    font-weight: bold;
}

.specialty-icon-container {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background-color: var(--primary-light);
    color: var(--primary-color);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    margin-right: 1rem;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

.specialty-card.active .specialty-icon-container {
    background-color: var(--primary-color);
    color: white;
    transform: scale(1.1);
}

.specialty-info {
    flex: 1;
}

.specialty-name {
    font-weight: 600;
    margin-bottom: 0.25rem;
}

.specialty-desc {
    font-size: 0.8rem;
    color: var(--text-muted);
}

/* Experience Level Cards */
.experience-levels {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
}

.experience-level-card {
    position: relative;
    border: 2px solid var(--border-color);
    border-radius: 1rem;
    padding: 1.5rem 1rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background-color: var(--bg-color);
    overflow: hidden;
}

.experience-level-card:hover {
    transform: translateY(-5px);
    border-color: var(--primary-color);
    box-shadow: 0 5px 15px var(--shadow-color);
}

.experience-level-card.active {
    border-color: var(--primary-color);
    background-color: var(--primary-light);
}

.experience-level-progress {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 5px;
    background-color: var(--border-color);
    overflow: hidden;
}

.experience-level-progress .progress-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
    transition: width 0.5s ease;
}

.experience-icon {
    font-size: 2rem;
    color: var(--primary-color);
    margin-bottom: 0.75rem;
    transition: all 0.3s ease;
}

.experience-level-card.active .experience-icon {
    transform: scale(1.2);
}

.experience-name {
    font-weight: 700;
    font-size: 1.1rem;
    margin-bottom: 0.25rem;
}

.experience-years {
    font-weight: 600;
    color: var(--primary-color);
    margin-bottom: 0.5rem;
}

.experience-desc {
    font-size: 0.8rem;
    color: var(--text-muted);
}

/* Bio Section */
.bio-container {
    position: relative;
}

.bio-counter {
    position: absolute;
    bottom: 10px;
    right: 10px;
    font-size: 0.8rem;
    color: var(--text-muted);
    background-color: var(--bg-color);
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
}

.bio-tips {
    background-color: var(--bg-color);
    border-radius: 0.5rem;
    padding: 0.75rem;
    font-size: 0.85rem;
}

.bio-tip {
    margin-bottom: 0.5rem;
}

.bio-tip:last-child {
    margin-bottom: 0;
}

/* For smaller screens */
@media (max-width: 767.98px) {
    .experience-levels {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 575.98px) {
    .experience-levels {
        grid-template-columns: 1fr;
    }
}
    </style>
</head>
<body>
    <!-- Header -->
    <header class="site-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="index.php" class="logo-text">
                    <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                </a>
                
                <!-- Main Navigation Menu -->
                <div class="d-none d-lg-flex">
                    <a href="index.php" class="nav-button">
                        <i class="fas fa-home"></i> Home
                    </a>
                    <a href="find-technician.php" class="nav-button">
                        <i class="fas fa-search"></i> Find Technician
                    </a>
                    <a href="services.php" class="nav-button">
                        <i class="fas fa-cogs"></i> Services
                    </a>
                    <a href="how-it-works.php" class="nav-button">
                        <i class="fas fa-info-circle"></i> How It Works
                    </a>
                </div>
                
                <!-- Authentication Buttons -->
                <div class="d-flex align-items-center">
                    <!-- Mobile Menu Toggle -->
                    <button type="button" class="btn btn-outline-light d-lg-none me-2" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <!-- Login Button -->
                    <a href="login.php" class="btn btn-primary me-2">
                        <i class="fas fa-sign-in-alt me-1"></i> Log In
                    </a>
                    
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle" id="themeToggle" aria-label="Toggle dark/light theme">
                        <i class="fas fa-moon" id="themeIcon"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Mobile Menu (Hidden by default) -->
        <div class="container-fluid d-lg-none mt-3 d-none" id="mobileMenu">
            <div class="list-group">
                <a href="index.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-home me-2"></i> Home
                </a>
                <a href="find-technician.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-search me-2"></i> Find Technician
                </a>
                <a href="services.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-cogs me-2"></i> Services
                </a>
                <a href="how-it-works.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-info-circle me-2"></i> How It Works
                </a>
            </div>
        </div>
    </header>
    
    <!-- Registration Section -->
    <section class="auth-section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-10 col-lg-8">
                    <div class="auth-card">
                        <div class="auth-card-header">
                            <h2 class="auth-card-title">Create Your Account</h2>
                            <p class="auth-card-subtitle">Join FixItNow and get your devices fixed</p>
                        </div>
                        <div class="auth-card-body">
                            <?php if (!empty($errorMessage)): ?>
                                <div class="alert alert-danger alert-dismissible fade show">
                                    <i class="fas fa-exclamation-circle me-2"></i> <?php echo $errorMessage; ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($successMessage)): ?>
                                <div class="alert alert-success alert-dismissible fade show">
                                    <i class="fas fa-check-circle me-2"></i> <?php echo $successMessage; ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            <?php endif; ?>
                            
                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="needs-validation" novalidate>
                                <!-- Step 1: Account Type -->
                                <div class="mb-4">
                                    <p class="text-center text-muted mb-3">Choose account type:</p>
                                    <div class="user-type-selector">
                                        <div class="user-type-card <?php echo $formData['role'] === 'customer' ? 'active' : ''; ?>" id="customerRole">
                                            <div class="user-type-icon">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <h5 class="mb-2">Customer</h5>
                                            <p class="mb-0 small text-muted">I need repair services</p>
                                        </div>
                                        
                                        <div class="user-type-card <?php echo $formData['role'] === 'provider' ? 'active' : ''; ?>" id="providerRole">
                                            <div class="user-type-icon">
                                                <i class="fas fa-tools"></i>
                                            </div>
                                            <h5 class="mb-2">Technician</h5>
                                            <p class="mb-0 small text-muted">I provide repair services</p>
                                        </div>
                                    </div>
                                    <input type="hidden" name="role" id="role" value="<?php echo htmlspecialchars($formData['role']); ?>">
                                </div>
                                
                                <!-- Step 2: Personal Information -->
                                <h5 class="mb-3"><i class="fas fa-user-circle text-primary me-2"></i> Personal Information</h5>
                                
                                <div class="row g-3">
                                    <div class="col-md-6 mb-3">
                                        <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                        <div class="input-group has-validation">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($formData['first_name']); ?>" placeholder="Enter your first name" required>
                                            <div class="invalid-feedback">
                                                Please enter your first name.
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                        <div class="input-group has-validation">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($formData['last_name']); ?>" placeholder="Enter your last name" required>
                                            <div class="invalid-feedback">
                                                Please enter your last name.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                    <div class="input-group has-validation">
                                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                        <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($formData['email']); ?>" placeholder="Enter your email address" required>
                                        <div class="invalid-feedback">
                                            Please enter a valid email address.
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                    <div class="input-group has-validation">
                                        <span class="input-group-text"><i class="fas fa-user-tag"></i></span>
                                        <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($formData['username']); ?>" placeholder="Choose a username" required>
                                        <div class="invalid-feedback">
                                            Please choose a username.
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row g-3">
                                    <div class="col-md-6 mb-3">
                                        <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                        <div class="input-group has-validation">
                                            <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                            <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($formData['phone']); ?>" placeholder="Enter your phone number" required>
                                            <div class="invalid-feedback">
                                                Please enter your phone number.
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="location" class="form-label">Location <span class="text-danger">*</span></label>
                                        <div class="input-group has-validation">
                                            <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                                            <select class="form-select" id="location" name="location" required>
                                                <option value="">Select your city</option>
                                                <?php foreach ($locations as $location): ?>
                                                    <option value="<?php echo $location; ?>" <?php echo ($formData['location'] === $location) ? 'selected' : ''; ?>>
                                                        <?php echo $location; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="invalid-feedback">
                                                Please select your location.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <h5 class="mb-3 mt-4"><i class="fas fa-lock text-primary me-2"></i> Security Information</h5>
                                
                                <div class="row g-3">
                                    <div class="col-md-6 mb-3">
                                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                        <div class="input-group has-validation">
                                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                            <input type="password" class="form-control" id="password" name="password" placeholder="At least 8 characters" required minlength="8">
                                            <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <div class="invalid-feedback">
                                                Password must be at least 8 characters.
                                            </div>
                                        </div>
                                        <div class="password-strength mt-2">
                                            <div class="password-strength-meter" id="passwordStrengthMeter"></div>
                                        </div>
                                        <small class="password-strength-text" id="passwordStrengthText"></small>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                        <div class="input-group has-validation">
                                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                                            <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <div class="invalid-feedback">
                                                Passwords do not match.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Technician Specific Information (Hidden for Customer) -->
                                <div id="technicianSection" class="mt-4" style="<?php echo $formData['role'] === 'provider' ? '' : 'display: none;'; ?>">
                                    <div class="technician-card">
                                        <div class="technician-card-header">
                                            <div class="technician-icon">
                                                <i class="fas fa-tools"></i>
                                            </div>
                                            <h5 class="mb-0">Technician Information</h5>
                                        </div>
                                        <div class="technician-card-body">
                                            <div class="mb-3">
                                                <label class="form-label">Your Specialties <span class="text-danger">*</span></label>
                                                <p class="form-text mb-2">Select the devices you can repair:</p>
                                                <div class="specialty-badges">
                                                    <label class="specialty-badge <?php echo in_array('smartphone', $formData['specialties']) ? 'active' : ''; ?>">
                                                        <input type="checkbox" name="specialties[]" value="smartphone" class="d-none" <?php echo in_array('smartphone', $formData['specialties']) ? 'checked' : ''; ?>>
                                                        <i class="fas fa-mobile-alt specialty-icon"></i> Smartphones
                                                    </label>
                                                    <label class="specialty-badge <?php echo in_array('laptop', $formData['specialties']) ? 'active' : ''; ?>">
                                                        <input type="checkbox" name="specialties[]" value="laptop" class="d-none" <?php echo in_array('laptop', $formData['specialties']) ? 'checked' : ''; ?>>
                                                        <i class="fas fa-laptop specialty-icon"></i> Laptops
                                                    </label>
                                                    <label class="specialty-badge <?php echo in_array('tablet', $formData['specialties']) ? 'active' : ''; ?>">
                                                        <input type="checkbox" name="specialties[]" value="tablet" class="d-none" <?php echo in_array('tablet', $formData['specialties']) ? 'checked' : ''; ?>>
                                                        <i class="fas fa-tablet-alt specialty-icon"></i> Tablets
                                                    </label>
                                                    <label class="specialty-badge <?php echo in_array('desktop', $formData['specialties']) ? 'active' : ''; ?>">
                                                        <input type="checkbox" name="specialties[]" value="desktop" class="d-none" <?php echo in_array('desktop', $formData['specialties']) ? 'checked' : ''; ?>>
                                                        <i class="fas fa-desktop specialty-icon"></i> Desktops
                                                    </label>
                                                    <label class="specialty-badge <?php echo in_array('gaming', $formData['specialties']) ? 'active' : ''; ?>">
                                                        <input type="checkbox" name="specialties[]" value="gaming" class="d-none" <?php echo in_array('gaming', $formData['specialties']) ? 'checked' : ''; ?>>
                                                        <i class="fas fa-gamepad specialty-icon"></i> Gaming
                                                    </label>
                                                    <label class="specialty-badge <?php echo in_array('tv', $formData['specialties']) ? 'active' : ''; ?>">
                                                        <input type="checkbox" name="specialties[]" value="tv" class="d-none" <?php echo in_array('tv', $formData['specialties']) ? 'checked' : ''; ?>>
                                                        <i class="fas fa-tv specialty-icon"></i> TVs
                                                    </label>
                                                </div>
                                                <div class="invalid-feedback" id="specialtiesErrorFeedback" style="display: none;">
                                                    Please select at least one specialty.
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Experience Level <span class="text-danger">*</span></label>
                                                <div class="experience-selector">
                                                    <label class="experience-option <?php echo $formData['experience'] === '0-1' ? 'active' : ''; ?>">
                                                        <input type="radio" name="experience" value="0-1" class="d-none" <?php echo $formData['experience'] === '0-1' ? 'checked' : ''; ?>>
                                                        <div class="experience-years">0-1 Years</div>
                                                        <div class="experience-level">Beginner</div>
                                                    </label>
                                                    <label class="experience-option <?php echo $formData['experience'] === '1-3' ? 'active' : ''; ?>">
                                                        <input type="radio" name="experience" value="1-3" class="d-none" <?php echo $formData['experience'] === '1-3' ? 'checked' : ''; ?>>
                                                        <div class="experience-years">1-3 Years</div>
                                                        <div class="experience-level">Intermediate</div>
                                                    </label>
                                                    <label class="experience-option <?php echo $formData['experience'] === '3-5' ? 'active' : ''; ?>">
                                                        <input type="radio" name="experience" value="3-5" class="d-none" <?php echo $formData['experience'] === '3-5' ? 'checked' : ''; ?>>
                                                        <div class="experience-years">3-5 Years</div>
                                                        <div class="experience-level">Experienced</div>
                                                    </label>
                                                    <label class="experience-option <?php echo $formData['experience'] === '5+' ? 'active' : ''; ?>">
                                                        <input type="radio" name="experience" value="5+" class="d-none" <?php echo $formData['experience'] === '5+' ? 'checked' : ''; ?>>
                                                        <div class="experience-years">5+ Years</div>
                                                        <div class="experience-level">Expert</div>
                                                    </label>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-0">
                                                <label for="bio" class="form-label">Bio</label>
                                                <textarea class="form-control" id="bio" name="bio" rows="3" placeholder="Tell customers about your skills and experience"><?php echo htmlspecialchars($formData['bio']); ?></textarea>
                                                <div class="form-text">A good bio helps customers choose you for repair jobs.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="terms" name="terms" required>
                                    <label class="form-check-label" for="terms">
                                        I agree to the <a href="terms.php" class="text-decoration-none">Terms of Service</a> and <a href="privacy.php" class="text-decoration-none">Privacy Policy</a>
                                    </label>
                                    <div class="invalid-feedback">
                                        You must agree to the terms and conditions.
                                    </div>
                                </div>
                                
                                <div class="d-grid mb-3">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-user-plus me-2"></i> Create Account
                                    </button>
                                </div>
                                
                                <div class="divider">or</div>
                                
                                <div class="text-center">
                                    Already have an account? <a href="login.php" class="text-decoration-none fw-bold">Log in</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Bootstrap & jQuery JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Form validation
            const form = document.querySelector('.needs-validation');
            
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                
                // Check password match
                const password = document.getElementById('password');
                const confirmPassword = document.getElementById('confirm_password');
                
                if (password.value !== confirmPassword.value) {
                    confirmPassword.setCustomValidity('Passwords do not match');
                } else {
                    confirmPassword.setCustomValidity('');
                }
                
                // Check specialties if provider
                if (document.getElementById('role').value === 'provider') {
                    const specialties = document.querySelectorAll('input[name="specialties[]"]:checked');
                    if (specialties.length === 0) {
                        document.getElementById('specialtiesErrorFeedback').style.display = 'block';
                        event.preventDefault();
                        event.stopPropagation();
                    } else {
                        document.getElementById('specialtiesErrorFeedback').style.display = 'none';
                    }
                }
                
                form.classList.add('was-validated');
            });
            
            // User Type Selection
            const customerRole = document.getElementById('customerRole');
            const providerRole = document.getElementById('providerRole');
            const roleInput = document.getElementById('role');
            const technicianSection = document.getElementById('technicianSection');
            
            customerRole.addEventListener('click', function() {
                customerRole.classList.add('active');
                providerRole.classList.remove('active');
                roleInput.value = 'customer';
                technicianSection.style.display = 'none';
            });
            
            providerRole.addEventListener('click', function() {
                providerRole.classList.add('active');
                customerRole.classList.remove('active');
                roleInput.value = 'provider';
                technicianSection.style.display = 'block';
            });
            
            // Password strength meter
            const passwordInput = document.getElementById('password');
            const strengthMeter = document.getElementById('passwordStrengthMeter');
            const strengthText = document.getElementById('passwordStrengthText');
            
            passwordInput.addEventListener('input', updatePasswordStrength);
            
            function updatePasswordStrength() {
                const password = passwordInput.value;
                let strength = 0;
                let tips = [];
                
                // Basic requirements
                if (password.length >= 8) {
                    strength += 25;
                } else {
                    tips.push("at least 8 characters");
                }
                
                // Has uppercase
                if (/[A-Z]/.test(password)) {
                    strength += 25;
                } else {
                    tips.push("uppercase letter");
                }
                
                // Has lowercase
                if (/[a-z]/.test(password)) {
                    strength += 25;
                } else {
                    tips.push("lowercase letter");
                }
                
                // Has number or special char
                if (/[0-9!@#$%^&*(),.?":{}|<>]/.test(password)) {
                    strength += 25;
                } else {
                    tips.push("number or special character");
                }
                
                // Update UI
                strengthMeter.style.width = strength + '%';
                
                if (strength <= 25) {
                    strengthMeter.style.backgroundColor = '#dc3545'; // red
                    strengthText.textContent = 'Weak password';
                } else if (strength <= 50) {
                    strengthMeter.style.backgroundColor = '#ffc107'; // yellow
                    strengthText.textContent = 'Fair password';
                } else if (strength <= 75) {
                    strengthMeter.style.backgroundColor = '#fd7e14'; // orange
                    strengthText.textContent = 'Good password';
                } else {
                    strengthMeter.style.backgroundColor = '#28a745'; // green
                    strengthText.textContent = 'Strong password';
                }
                
                if (tips.length > 0) {
                    strengthText.textContent += ': Add ' + tips.join(", ");
                }
            }
            
            // Toggle password visibility
            const togglePassword = document.getElementById('togglePassword');
            const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
            
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.querySelector('i').classList.toggle('fa-eye');
                this.querySelector('i').classList.toggle('fa-eye-slash');
            });
            
            const confirmPasswordInput = document.getElementById('confirm_password');
            toggleConfirmPassword.addEventListener('click', function() {
                const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPasswordInput.setAttribute('type', type);
                this.querySelector('i').classList.toggle('fa-eye');
                this.querySelector('i').classList.toggle('fa-eye-slash');
            });
            
            // Specialty badges
            const specialtyBadges = document.querySelectorAll('.specialty-badge');
            specialtyBadges.forEach(badge => {
                badge.addEventListener('click', function() {
                    const checkbox = this.querySelector('input[type="checkbox"]');
                    checkbox.checked = !checkbox.checked;
                    this.classList.toggle('active', checkbox.checked);
                    
                    // Hide error message if at least one is selected
                    const specialties = document.querySelectorAll('input[name="specialties[]"]:checked');
                    if (specialties.length > 0) {
                        document.getElementById('specialtiesErrorFeedback').style.display = 'none';
                    }
                });
            });
            
            // Experience options
            const experienceOptions = document.querySelectorAll('.experience-option');
            experienceOptions.forEach(option => {
                option.addEventListener('click', function() {
                    experienceOptions.forEach(o => o.classList.remove('active'));
                    const radio = this.querySelector('input[type="radio"]');
                    radio.checked = true;
                    this.classList.add('active');
                });
            });
            
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const mobileMenu = document.getElementById('mobileMenu');
            
            if (mobileMenuToggle && mobileMenu) {
                mobileMenuToggle.addEventListener('click', function() {
                    mobileMenu.classList.toggle('d-none');
                    
                    // Change icon
                    const icon = mobileMenuToggle.querySelector('i');
                    if (mobileMenu.classList.contains('d-none')) {
                        icon.classList.remove('fa-times');
                        icon.classList.add('fa-bars');
                    } else {
                        icon.classList.remove('fa-bars');
                        icon.classList.add('fa-times');
                    }
                });
            }
            
            // Theme Toggle Functionality
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const htmlElement = document.documentElement;
            
            // Check for saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                htmlElement.setAttribute('data-bs-theme', 'dark');
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
            }
            
            // Toggle theme when button is clicked
            themeToggle.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                
                if (currentTheme === 'dark') {
                    htmlElement.setAttribute('data-bs-theme', 'light');
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                    localStorage.setItem('theme', 'light');
                } else {
                    htmlElement.setAttribute('data-bs-theme', 'dark');
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                    localStorage.setItem('theme', 'dark');
                }
            });
            
            // Initialize password strength on page load
            updatePasswordStrength();
        });
                
    </script>
</body>
</html>