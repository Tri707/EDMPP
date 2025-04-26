<?php
session_start();

// Include database connection
include 'conn.php';

// Process booking form submission via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_action']) && $_POST['booking_action'] === 'process_booking') {
    // Set content type to JSON for AJAX response
    header('Content-Type: application/json');
    
    // Check if user is logged in and is a customer
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
        echo json_encode([
            'success' => false,
            'message' => 'You must be logged in as a customer to make a booking.'
        ]);
        exit;
    }
    
    // Validate required fields
    $required_fields = ['provider_id', 'booking_date', 'booking_time', 'price'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            echo json_encode([
                'success' => false,
                'message' => 'Please fill all required fields.'
            ]);
            exit;
        }
    }
    
    // Get form data
    $customer_id = $_SESSION['user_id'];
    $provider_id = (int)$_POST['provider_id'];
    $service_id = isset($_POST['service_id']) && !empty($_POST['service_id']) && !preg_match('/^custom/', $_POST['service_id']) ? (int)$_POST['service_id'] : null;
    $service_name = isset($_POST['service_name']) ? $conn->real_escape_string($_POST['service_name']) : '';
    $booking_date = $conn->real_escape_string($_POST['booking_date']);
    $booking_time = $conn->real_escape_string($_POST['booking_time']);
    $price = (float)$_POST['price'];
    $notes = isset($_POST['notes']) ? $conn->real_escape_string($_POST['notes']) : '';
    
    // Verify the provider exists
    $provider_check = $conn->query("SELECT id FROM providers WHERE id = $provider_id");
    if (!$provider_check || $provider_check->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid provider selected.'
        ]);
        exit;
    }
    
    // Verify the service if a service ID is provided (and not 'custom')
    if ($service_id) {
        $service_check = $conn->query("SELECT id FROM services WHERE id = $service_id AND provider_id = $provider_id");
        if (!$service_check || $service_check->num_rows === 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid service selected.'
            ]);
            exit;
        }
    }
    
    // Insert booking into database
    $service_id_value = $service_id ? $service_id : "NULL";
    $insert_query = "INSERT INTO bookings (customer_id, provider_id, service_id, booking_date, booking_time, 
                                         status, total_price, payment_status, notes) 
                   VALUES ($customer_id, $provider_id, " . $service_id_value . ", 
                           '$booking_date', '$booking_time', 'pending', $price, 'unpaid', '$notes')";
    
    if ($conn->query($insert_query)) {
        echo json_encode([
            'success' => true,
            'message' => 'Booking created successfully!'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred while creating your booking: ' . $conn->error
        ]);
    }
    
    exit;
}

// Process review submission via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_action']) && $_POST['review_action'] === 'submit_review') {
    // Set content type to JSON for AJAX response
    header('Content-Type: application/json');
    
    // Check if user is logged in and is a customer
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
        echo json_encode([
            'success' => false,
            'message' => 'You must be logged in as a customer to submit a review.'
        ]);
        exit;
    }
    
    // Validate required fields
    if (!isset($_POST['provider_id']) || !isset($_POST['rating']) || !isset($_POST['review_text'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Please fill all required fields.'
        ]);
        exit;
    }
    
    // Get form data
    $customer_id = $_SESSION['user_id'];
    $provider_id = (int)$_POST['provider_id'];
    $rating = (int)$_POST['rating'];
    $review_text = $conn->real_escape_string($_POST['review_text']);
    
    // Validate rating
    if ($rating < 1 || $rating > 5) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid rating value.'
        ]);
        exit;
    }
    
    // Insert review into database
    $insert_query = "INSERT INTO reviews (customer_id, provider_id, rating, comment, created_at) 
                    VALUES ($customer_id, $provider_id, $rating, '$review_text', NOW())";
    
    if ($conn->query($insert_query)) {
        echo json_encode([
            'success' => true,
            'message' => 'Review submitted successfully!'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred while submitting your review: ' . $conn->error
        ]);
    }
    
    exit;
}

// Process message submission via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_action']) && $_POST['message_action'] === 'send_message') {
    // Set content type to JSON for AJAX response
    header('Content-Type: application/json');
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'You must be logged in to send a message.'
        ]);
        exit;
    }
    
    // Validate required fields
    if (!isset($_POST['recipient_id']) || !isset($_POST['subject']) || !isset($_POST['message'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Please fill all required fields.'
        ]);
        exit;
    }
    
    // Get form data
    $sender_id = $_SESSION['user_id'];
    $recipient_id = (int)$_POST['recipient_id'];
    $subject = $conn->real_escape_string($_POST['subject']);
    $message = $conn->real_escape_string($_POST['message']);
    
    // Insert message into database
    $insert_query = "INSERT INTO messages (sender_id, recipient_id, subject, message, created_at, is_read) 
                    VALUES ($sender_id, $recipient_id, '$subject', '$message', NOW(), 0)";
    
    if ($conn->query($insert_query)) {
        echo json_encode([
            'success' => true,
            'message' => 'Message sent successfully!'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred while sending your message: ' . $conn->error
        ]);
    }
    
    exit;
}

// Get technician ID from URL parameter
$technicianId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($technicianId <= 0) {
    // Redirect to marketplace if no valid ID provided
    header("Location: marketplace.php");
    exit;
}

// Fetch technician data from database
// Updated query to include provider ID (p.id) which was missing before
$technicianQuery = "SELECT u.id, u.username, u.first_name, u.last_name, u.profile_image, u.email,
                          p.id as provider_id, p.specialties, p.experience, p.hourly_rate, p.bio, p.location, p.response_time, p.education,
                          AVG(r.rating) as avg_rating, COUNT(r.id) as review_count
                   FROM users u
                   JOIN providers p ON u.id = p.user_id
                   LEFT JOIN reviews r ON p.id = r.provider_id
                   WHERE u.id = ? AND u.role = 'provider'
                   GROUP BY u.id, p.id";

$stmt = $conn->prepare($technicianQuery);
$stmt->bind_param("i", $technicianId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    // Redirect to marketplace if technician not found
    header("Location: marketplace.php");
    exit;
}

$technician = $result->fetch_assoc();

// Store provider ID for use in forms
$providerId = $technician['provider_id'];

// Format technician data
$fullName = $technician['first_name'] . ' ' . $technician['last_name'];
$rating = round($technician['avg_rating'] * 2) / 2; // Round to nearest 0.5
$specialtiesArray = !empty($technician['specialties']) ? explode(',', $technician['specialties']) : [];

// Parse education if it exists in the database
$educationArray = !empty($technician['education']) ? json_decode($technician['education'], true) : [];
if (!is_array($educationArray)) {
    $educationArray = [];
}

// Certifications aren't in the database, so keep it as an empty array
$certificationsArray = [];

// Service categories mapping
$serviceCategories = [
    'smartphone' => 'Smartphone Repair',
    'laptop' => 'Laptop Repair',
    'tablet' => 'Tablet Repair',
    'computer' => 'Computer Repair',
    'console' => 'Game Console Repair',
    'tv' => 'TV/Monitor Repair',
    'audio' => 'Audio Repair'
];

// Fetch reviews for this technician
$reviewsQuery = "SELECT r.id, r.rating, r.comment as review_text, r.created_at, 
                       u.first_name, u.last_name, u.profile_image
                FROM reviews r
                JOIN users u ON r.customer_id = u.id
                JOIN providers p ON r.provider_id = p.id
                WHERE p.user_id = ?
                ORDER BY r.created_at DESC
                LIMIT 10";

$stmt = $conn->prepare($reviewsQuery);
$stmt->bind_param("i", $technicianId);
$stmt->execute();
$reviewsResult = $stmt->get_result();

// Fetch services offered by this technician
$servicesQuery = "SELECT s.id, s.name as service_name, s.description, s.price, s.duration
                 FROM services s
                 JOIN providers p ON s.provider_id = p.id
                 WHERE p.user_id = ?
                 ORDER BY s.price ASC";

$stmt = $conn->prepare($servicesQuery);
$stmt->bind_param("i", $technicianId);
$stmt->execute();
$servicesResult = $stmt->get_result();

// Calculate experience level
$experienceLabel = '';
switch($technician['experience']) {
    case '0-1':
        $experienceLabel = 'Beginner';
        break;
    case '1-3':
        $experienceLabel = 'Intermediate';
        break;
    case '3-5':
        $experienceLabel = 'Experienced';
        break;
    case '5-10':
        $experienceLabel = 'Advanced';
        break;
    case '10+':
        $experienceLabel = 'Expert';
        break;
    default:
        $experienceLabel = 'Not specified';
}

// Format profile image - simplified logic
$profileImage = 'images/default.png'; // Default image
if (!empty($technician['profile_image'])) {
    if (preg_match('/^https?:\/\//', $technician['profile_image'])) {
        // If it's a full URL, use it as is
        $profileImage = $technician['profile_image'];
    } else {
        // Make sure it includes the images path
        $profileImage = (strpos($technician['profile_image'], 'images/') === false) 
            ? 'images/' . ltrim($technician['profile_image'], '/') 
            : ltrim($technician['profile_image'], '/');
    }
}

// Check if user is logged in
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Get user information if logged in
$userData = null;
$userProfileImage = 'images/default.png';

if ($loggedIn) {
    $userQuery = "SELECT * FROM users WHERE id = ?";
    $stmt = $conn->prepare($userQuery);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $userResult = $stmt->get_result();
    
    if ($userResult->num_rows > 0) {
        $userData = $userResult->fetch_assoc();
        
        // Set profile image path - using the same logic as for technician
        if (!empty($userData['profile_image'])) {
            if (preg_match('/^https?:\/\//', $userData['profile_image'])) {
                $userProfileImage = $userData['profile_image'];
            } else {
                $userProfileImage = (strpos($userData['profile_image'], 'images/') === false) 
                    ? 'images/' . ltrim($userData['profile_image'], '/') 
                    : ltrim($userData['profile_image'], '/');
            }
        }
    }
}

// Check if the current user has booked this technician before
$hasBooked = false;
if ($loggedIn && $userRole === 'customer') {
    // Updated query to use provider_id directly instead of joining with user_id
    $bookingQuery = "SELECT COUNT(*) as count FROM bookings b
                    WHERE b.customer_id = ? AND b.provider_id = ?";
    $stmt = $conn->prepare($bookingQuery);
    $stmt->bind_param("ii", $userId, $providerId);
    $stmt->execute();
    $bookingResult = $stmt->get_result();
    $bookingData = $bookingResult->fetch_assoc();
    $hasBooked = $bookingData['count'] > 0;
}

// Fetch available schedule slots for this technician
$scheduleSlotsQuery = "SELECT s.id, s.date, s.start_time, s.end_time, s.max_appointments, 
                       (SELECT COUNT(*) FROM bookings b 
                        WHERE b.provider_id = ? 
                        AND DATE(b.booking_date) = s.date 
                        AND TIME(b.booking_time) BETWEEN s.start_time AND s.end_time) as booked_count
                FROM schedule_slots s
                WHERE s.provider_id = ? AND s.date >= CURDATE()
                ORDER BY s.date ASC, s.start_time ASC";

$stmt = $conn->prepare($scheduleSlotsQuery);
$stmt->bind_param("ii", $providerId, $providerId);
$stmt->execute();
$scheduleSlotsResult = $stmt->get_result();

// Page title
$page_title = "Technician Profile - " . $fullName;
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | FixItNow</title>
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
        }
        
        /* Dark Theme Variables */
        [data-bs-theme="dark"] {
            --primary-color: #9775fa;      /* Lighter Purple for dark mode */
            --primary-hover: #845ef7;      /* Purple hover for dark mode */
            --primary-light: #382d52;      /* Darker purple light for dark mode */
            --accent-color: #40c057;       /* Brighter Green for dark mode */
            --accent-light: #215c2e;       /* Darker green light for dark mode */
            --text-color: #e9ecef;         /* Light text for dark mode */
            --text-muted: #adb5bd;         /* Muted text for dark mode */
            --bg-color: #121212;           /* Dark background */
            --card-bg: #1e1e1e;            /* Card background */
            --header-bg: #0f0f0f;          /* Header background */
            --header-text: #ffffff;        /* Header text */
            --footer-bg: #0f0f0f;          /* Footer background */
            --footer-text: #adb5bd;        /* Footer text */
            --border-color: #343a40;       /* Border color */
            --input-bg: #2b2b2b;           /* Input background */
            --input-border: #444;          /* Input border */
            --modal-bg: #1e1e1e;           /* Modal background */
            --shadow-color: rgba(0, 0, 0, 0.3); /* Shadow color */
        }
        
        /* General Styles */
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.3s ease;
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
        
        .header-nav a.nav-link {
            color: var(--header-text);
            opacity: 0.85;
            font-weight: 500;
            padding: 0.5rem 1rem;
            transition: all 0.2s ease;
            border-radius: 0.5rem;
        }
        
        .header-nav a.nav-link:hover {
            opacity: 1;
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .header-nav a.nav-link.active {
            opacity: 1;
            color: var(--header-text);
            background-color: var(--primary-color);
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
        
        /* Technician Profile Styles */
        .profile-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .profile-header {
            background-color: var(--primary-color);
            border-radius: 1rem 1rem 0 0;
            color: var(--header-text);
            padding: 2.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
        }
        
        .back-link {
            position: absolute;
            top: 1.5rem;
            left: 1.5rem;
            color: var(--header-text);
            opacity: 0.8;
            transition: opacity 0.2s ease;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            text-decoration: none;
        }
        
        .back-link:hover {
            opacity: 1;
            color: var(--header-text);
        }
        
        .back-link i {
            margin-right: 0.5rem;
        }
        
        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 5px solid var(--header-text);
            overflow: hidden;
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
            margin-bottom: 1.5rem;
            background-color: var(--header-text);
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-name {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .profile-location {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .profile-location i {
            margin-right: 0.5rem;
        }
        
        .profile-quick-info {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-top: 1rem;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            background-color: rgba(0, 0, 0, 0.1);
            padding: 0.75rem 1.25rem;
            border-radius: 0.75rem;
        }
        
        .info-label {
            font-size: 0.8rem;
            opacity: 0.9;
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .rating-stars {
            color: #ffc107;
            font-size: 1.2rem;
            margin: 0.5rem 0;
        }
        
        .star-count {
            font-size: 0.9rem;
            margin-left: 0.5rem;
            opacity: 0.9;
        }
        
        /* Profile Content */
        .profile-content {
            background-color: var(--card-bg);
            border-radius: 0 0 1rem 1rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            padding: 0;
            overflow: hidden;
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        .profile-nav {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            overflow-x: auto;
            scrollbar-width: none;
            transition: border-color 0.3s ease;
        }
        
        .profile-nav::-webkit-scrollbar {
            display: none;
        }
        
        .profile-nav-item {
            padding: 1.25rem 2rem;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            white-space: nowrap;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }
        
        .profile-nav-item:hover {
            color: var(--primary-color);
        }
        
        .profile-nav-item.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .profile-nav-item i {
            margin-right: 0.5rem;
        }
        
        .profile-tab {
            display: none;
            padding: 2rem;
        }
        
        .profile-tab.active {
            display: block;
        }
        
        .tab-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
        }
        
        .tab-title i {
            margin-right: 0.75rem;
            color: var(--primary-color);
        }
        
        /* About Tab */
        .bio-text {
            color: var(--text-color);
            margin-bottom: 2rem;
            line-height: 1.8;
        }
        
        .specialties-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 2rem;
        }
        
        .specialty-badge {
            background-color: var(--primary-light);
            color: var(--primary-color);
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        .specialty-badge i {
            margin-right: 0.5rem;
        }
        
        .info-box {
            background-color: rgba(128, 128, 128, 0.05);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            transition: background-color 0.3s ease;
        }
        
        .info-box-title {
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
        }
        
        .info-box-title i {
            margin-right: 0.75rem;
            color: var(--primary-color);
        }
        
        .info-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .info-list li {
            margin-bottom: 0.75rem;
            padding-left: 1.5rem;
            position: relative;
            color: var(--text-color);
        }
        
        .info-list li:before {
            content: '\f058';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            color: var(--primary-color);
            position: absolute;
            left: 0;
            top: 2px;
        }
        
        /* Services Tab */
        .services-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .service-card {
            background-color: rgba(128, 128, 128, 0.05);
            border-radius: 0.75rem;
            padding: 1.5rem;
            height: 100%;
            transition: background-color 0.3s ease, transform 0.3s ease;
        }
        
        .service-card:hover {
            transform: translateY(-5px);
        }
        
        .service-title {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 0.75rem;
            color: var(--text-color);
        }
        
        .service-description {
            color: var(--text-muted);
            margin-bottom: 1rem;
            flex-grow: 1;
        }
        
        .service-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .service-price {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
        }
        
        .service-price img {
            height: 16px;
            margin-right: 2px;
        }
        
        .service-duration {
            color: var(--text-muted);
            display: flex;
            align-items: center;
            font-size: 0.9rem;
        }
        
        .service-duration i {
            margin-right: 0.5rem;
        }
        
        /* Reviews Tab */
        .reviews-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .rating-large {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .rating-number {
            font-size: 3rem;
            font-weight: 700;
            color: var(--text-color);
            line-height: 1;
        }
        
        .rating-stars-large {
            color: #ffc107;
            font-size: 1.5rem;
            margin: 0.5rem 0;
        }
        
        .rating-count {
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        .review-card {
            background-color: rgba(128, 128, 128, 0.05);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: background-color 0.3s ease;
        }
        
        .review-header {
            display: flex;
            margin-bottom: 1rem;
        }
        
        .reviewer-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 1rem;
        }
        
        .reviewer-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .reviewer-info {
            flex-grow: 1;
        }
        
        .reviewer-name {
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.25rem;
        }
        
        .review-date {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        .review-rating {
            display: flex;
            align-items: center;
        }
        
        .review-stars {
            color: #ffc107;
            font-size: 0.9rem;
        }
        
        .review-text {
            color: var(--text-color);
            line-height: 1.7;
        }
        
        /* Write Review Form */
        .write-review-btn {
            padding: 0.75rem 1.5rem;
            background-color: var(--primary-color);
            color: var(--header-text);
            border-radius: 0.5rem;
            font-weight: 600;
            text-decoration: none;
            transition: background-color 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
        }
        
        .write-review-btn:hover {
            background-color: var(--primary-hover);
            color: var(--header-text);
        }
        
        .write-review-btn i {
            margin-right: 0.5rem;
        }
        
        .review-form {
            background-color: rgba(128, 128, 128, 0.05);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .rating-select {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .rating-option {
            font-size: 1.5rem;
            color: #e0e0e0;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        
        .rating-option:hover, .rating-option.selected {
            color: #ffc107;
        }
        
        /* Book Now Tab */
        .booking-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            overflow-x: auto;
            padding-bottom: 1rem;
            scrollbar-width: none;
        }
        
        .booking-steps::-webkit-scrollbar {
            display: none;
        }
        
        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 100px;
            position: relative;
        }
        
        .step-item:not(:last-child):after {
            content: '';
            position: absolute;
            top: 16px;
            right: -50%;
            width: 100%;
            height: 2px;
            background-color: var(--border-color);
            z-index: 1;
        }
        
        .step-circle {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background-color: var(--card-bg);
            border: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 2;
            transition: all 0.3s ease;
        }
        
        .step-number {
            font-weight: 600;
            color: var(--text-muted);
            transition: color 0.3s ease;
        }
        
        .step-name {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-align: center;
            transition: color 0.3s ease;
        }
        
        .step-item.active .step-circle {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .step-item.active .step-number {
            color: var(--header-text);
        }
        
        .step-item.active .step-name {
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .step-item.completed .step-circle {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
        }
        
        .step-item.completed .step-number {
            color: var(--header-text);
        }
        
        .step-content {
            display: none;
        }
        
        .step-content.active {
            display: block;
        }
        
        .booking-services {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .booking-service-card {
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            padding: 1.25rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .booking-service-card:hover {
            border-color: var(--primary-color);
            background-color: rgba(128, 128, 128, 0.05);
        }
        
        .booking-service-card.selected {
            border-color: var(--primary-color);
            background-color: var(--primary-light);
        }
        
        .booking-service-name {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }
        
        .booking-service-price {
            font-weight: 700;
            color: var(--primary-color);
            margin-top: 0.5rem;
        }
        
        .booking-service-duration {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        .booking-calendar {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .calendar-day {
            text-align: center;
            padding: 0.5rem;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .calendar-day:hover:not(.disabled) {
            background-color: var(--primary-light);
            color: var(--primary-color);
        }
        
        .calendar-day.selected {
            background-color: var(--primary-color);
            color: var(--header-text);
        }
        
        .calendar-day.disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        .calendar-weekday {
            text-align: center;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-muted);
        }
        
        .time-slots {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        
        .time-slot {
            text-align: center;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .time-slot:hover:not(.disabled) {
            border-color: var(--primary-color);
            background-color: var(--primary-light);
            color: var(--primary-color);
        }
        
        .time-slot.selected {
            background-color: var(--primary-color);
            color: var(--header-text);
            border-color: var(--primary-color);
        }
        
        .time-slot.disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        .nav-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 2rem;
        }
        
        .btn-back {
            padding: 0.75rem 1.5rem;
            background-color: var(--card-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
        }
        
        .btn-back:hover {
            background-color: rgba(128, 128, 128, 0.1);
        }
        
        .btn-back i {
            margin-right: 0.5rem;
        }
        
        .btn-next {
            padding: 0.75rem 1.5rem;
            background-color: var(--primary-color);
            color: var(--header-text);
            border: none;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: background-color 0.3s ease;
            display: inline-flex;
            align-items: center;
        }
        
        .btn-next:hover {
            background-color: var(--primary-hover);
        }
        
        .btn-next i {
            margin-left: 0.5rem;
        }
        
        .btn-book {
            padding: 0.75rem 1.5rem;
            background-color: var(--accent-color);
            color: var(--header-text);
            border: none;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: background-color 0.3s ease;
            display: inline-flex;
            align-items: center;
        }
        
        .btn-book:hover {
            background-color: #2ea043;
        }
        
        .btn-book i {
            margin-right: 0.5rem;
        }
        
        .booking-summary {
            background-color: rgba(128, 128, 128, 0.05);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .summary-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
            margin-bottom: 0;
        }
        
        .summary-label {
            font-weight: 600;
            color: var(--text-color);
        }
        
        .summary-value {
            color: var(--text-color);
        }
        
        .summary-total {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 1.1rem;
        }
        
        /* Contact Tab */
        .contact-methods {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .contact-method {
            background-color: rgba(128, 128, 128, 0.05);
            border-radius: 0.75rem;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            transition: background-color 0.3s ease, transform 0.3s ease;
        }
        
        .contact-method:hover {
            transform: translateY(-5px);
        }
        
        .contact-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        .contact-method:hover .contact-icon {
            background-color: var(--primary-color);
            color: var(--header-text);
        }
        
        .contact-title {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }
        
        .contact-value {
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .contact-link {
            color: var(--primary-color);
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: color 0.3s ease;
        }
        
        .contact-link:hover {
            color: var(--primary-hover);
        }
        
        .contact-link i {
            margin-left: 0.5rem;
        }
        
        /* Message Form */
        .message-form {
            background-color: rgba(128, 128, 128, 0.05);
            border-radius: 0.75rem;
            padding: 1.5rem;
        }
        
        .form-control {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--input-border);
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(121, 82, 179, 0.25);
        }
        
        .btn-send {
            padding: 0.75rem 1.5rem;
            background-color: var(--primary-color);
            color: var(--header-text);
            border: none;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: background-color 0.3s ease;
            display: inline-flex;
            align-items: center;
        }
        
        .btn-send:hover {
            background-color: var(--primary-hover);
        }
        
        .btn-send i {
            margin-left: 0.5rem;
        }
        
        /* Spinner for loading states */
        .spinner-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            visibility: hidden;
            opacity: 0;
            transition: visibility 0s, opacity 0.3s;
        }
        
        .spinner-overlay.show {
            visibility: visible;
            opacity: 1;
        }
        
        .spinner-container {
            background-color: var(--card-bg);
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
            text-align: center;
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
            margin-bottom: 1rem;
        }
        
        /* Footer */
        .site-footer {
            background-color: var(--footer-bg);
            color: var(--footer-text);
            padding: 4rem 0 2rem;
            margin-top: 4rem;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        .footer-logo {
            font-size: 1.75rem;
            font-weight: 900;
            margin-bottom: 1.5rem;
            color: var(--header-text);
        }
        
        .footer-logo .highlight {
            color: var(--accent-color);
        }
        
        .footer-nav {
            margin-bottom: 2rem;
        }
        
        .footer-heading {
            color: var(--header-text);
            font-weight: 700;
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
        }
        
        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .footer-links li {
            margin-bottom: 0.75rem;
        }
        
        .footer-links a {
            color: var(--footer-text);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .footer-links a:hover {
            color: var(--header-text);
        }
        
        .footer-bottom {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 2rem;
            margin-top: 2rem;
        }
        
        .footer-bottom p {
            margin-bottom: 0;
        }
        
        /* Toast notifications */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1100;
        }
        
        .custom-toast {
            background-color: var(--card-bg);
            color: var(--text-color);
            border-left: 4px solid var(--primary-color);
            box-shadow: 0 0.25rem 1rem var(--shadow-color);
            min-width: 300px;
            max-width: 400px;
        }
        
        .custom-toast.success {
            border-left-color: var(--accent-color);
        }
        
        .custom-toast.error {
            border-left-color: #dc3545;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .profile-header {
                padding: 1.5rem;
            }
            
            .profile-avatar {
                width: 120px;
                height: 120px;
            }
            
            .profile-name {
                font-size: 1.5rem;
            }
            
            .profile-quick-info {
                flex-wrap: wrap;
            }
            
            .profile-nav-item {
                padding: 1rem;
            }
            
            .profile-tab {
                padding: 1.5rem;
            }
            
            .services-list {
                grid-template-columns: 1fr;
            }
            
            .contact-methods {
                grid-template-columns: 1fr;
            }
            
            .booking-steps {
                justify-content: flex-start;
            }
            
            .step-item {
                margin-right: 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Spinner Overlay -->
    <div class="spinner-overlay" id="spinnerOverlay">
        <div class="spinner-container">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div id="spinnerMessage">Processing your request...</div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container"></div>

    <!-- Header -->
    <header class="site-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="index.php" class="text-decoration-none">
                    <div class="logo-text">
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                </a>
                
                <!-- Navigation -->
                <nav class="d-none d-md-flex header-nav">
                    <a class="nav-link" href="index.php">
                        <i class="fas fa-home me-1"></i> Home
                    </a>
                    <a class="nav-link" href="marketplace.php">
                        <i class="fas fa-search me-1"></i> Find Technician
                    </a>
                    <a class="nav-link" href="index.php#services">
                        <i class="fas fa-cog me-1"></i> Services
                    </a>
                    <a class="nav-link" href="index.php#how-it-works">
                        <i class="fas fa-info-circle me-1"></i> How It Works
                    </a>
                </nav>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-sun" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Action -->
                    <?php if($loggedIn): ?>
                    <div class="dropdown">
                        <button class="btn btn-dark dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($userProfileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($userData['username']); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <?php if($userRole == 'admin'): ?>
                                <li><a class="dropdown-item" href="admin/dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Admin Dashboard</a></li>
                            <?php elseif($userRole == 'provider'): ?>
                                <li><a class="dropdown-item" href="provider/dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Provider Dashboard</a></li>
                            <?php else: ?>
                                <li><a class="dropdown-item" href="customer/dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> My Account</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                    <?php else: ?>
                    <div>
                        <a href="login.php" class="btn btn-outline-light me-2">Login</a>
                        <a href="signup.php" class="btn btn-success">Sign Up</a>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Mobile Toggle -->
                <button class="navbar-toggler d-md-none ms-2" type="button" data-bs-toggle="collapse" data-bs-target="#mobileNav">
                    <i class="fas fa-bars text-white"></i>
                </button>
            </div>
            
            <!-- Mobile Navigation -->
            <div class="collapse navbar-collapse mt-3 d-md-none" id="mobileNav">
                <nav class="header-nav d-flex flex-column">
                    <a class="nav-link" href="index.php">
                        <i class="fas fa-home me-1"></i> Home
                    </a>
                    <a class="nav-link" href="marketplace.php">
                        <i class="fas fa-search me-1"></i> Find Technician
                    </a>
                    <a class="nav-link" href="index.php#services">
                        <i class="fas fa-cog me-1"></i> Services
                    </a>
                    <a class="nav-link" href="index.php#how-it-works">
                        <i class="fas fa-info-circle me-1"></i> How It Works
                    </a>
                </nav>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="profile-container">
        <!-- Profile Header -->
        <div class="profile-header">
            <a href="marketplace.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Marketplace
            </a>
            
            <div class="profile-avatar">
                <img src="<?php echo htmlspecialchars($profileImage); ?>" 
                     alt="<?php echo htmlspecialchars($fullName); ?>"
                     onerror="this.src='images/default.png'; this.onerror=null;">
            </div>
            
            <h1 class="profile-name"><?php echo htmlspecialchars($fullName); ?></h1>
            
            <div class="profile-location">
                <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($technician['location'] ?? 'Remote'); ?>
            </div>
            
            <div class="rating-stars">
                <?php for($i=1; $i<=5; $i++): ?>
                    <?php if($i <= floor($rating)): ?>
                        <i class="fas fa-star"></i>
                    <?php elseif($i - $rating > 0 && $i - $rating < 1): ?>
                        <i class="fas fa-star-half-alt"></i>
                    <?php else: ?>
                        <i class="far fa-star"></i>
                    <?php endif; ?>
                <?php endfor; ?>
                <span class="star-count"><?php echo number_format($rating, 1); ?> (<?php echo $technician['review_count']; ?> reviews)</span>
            </div>
            
            <div class="profile-quick-info">
                <div class="info-item">
                    <div class="info-label">Experience</div>
                    <div class="info-value"><?php echo htmlspecialchars($experienceLabel); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Hourly Rate</div>
                    <div class="info-value">
                        <img src="sar/sar.png" alt="SAR" style="height: 16px; margin-right: 2px;"><?php echo number_format($technician['hourly_rate'] ?? 0, 2); ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Specialties</div>
                    <div class="info-value"><?php echo count($specialtiesArray); ?> Areas</div>
                </div>
            </div>
            
            <?php if($loggedIn && $userRole === 'customer'): ?>
            <a href="#" class="btn-book mt-3" id="bookNowBtn">
                <i class="fas fa-calendar-check"></i> Book Now
            </a>
            <?php elseif(!$loggedIn): ?>
            <a href="login.php?redirect=technician.php?id=<?php echo $technicianId; ?>" class="btn-book mt-3">
                <i class="fas fa-lock"></i> Login to Book
            </a>
            <?php endif; ?>
        </div>
        
        <!-- Profile Content -->
        <div class="profile-content">
            <!-- Profile Navigation -->
            <div class="profile-nav">
                <a href="#about" class="profile-nav-item active" data-tab="about">
                    <i class="fas fa-user"></i> About
                </a>
                <a href="#services" class="profile-nav-item" data-tab="services">
                    <i class="fas fa-cog"></i> Services
                </a>
                <a href="#reviews" class="profile-nav-item" data-tab="reviews">
                    <i class="fas fa-star"></i> Reviews
                </a>
                <?php if($loggedIn && $userRole === 'customer'): ?>
                <a href="#book" class="profile-nav-item" data-tab="book">
                    <i class="fas fa-calendar-check"></i> Book Now
                </a>
                <?php endif; ?>
                <a href="#contact" class="profile-nav-item" data-tab="contact">
                    <i class="fas fa-envelope"></i> Contact
                </a>
            </div>
            
            <!-- Tab Content -->
            <div class="profile-tab active" id="aboutTab">
                <h2 class="tab-title">
                    <i class="fas fa-user-circle"></i> About <?php echo explode(' ', $fullName)[0]; ?>
                </h2>
                
                <p class="bio-text">
                    <?php echo nl2br(htmlspecialchars($technician['bio'] ?? 'No bio provided.')); ?>
                </p>
                
                <h3 class="tab-title">
                    <i class="fas fa-tools"></i> Specialties
                </h3>
                
                <div class="specialties-grid">
                    <?php 
                    foreach($specialtiesArray as $specialty) {
                        $specialty = trim($specialty);
                        $icon = 'fas fa-tools';
                        $label = $specialty;
                        
                        // Map specialty to icon and label
                        if (isset($serviceCategories[$specialty])) {
                            $label = $serviceCategories[$specialty];
                            
                            // Set appropriate icon for each category
                            if ($specialty === 'smartphone') $icon = 'fas fa-mobile-alt';
                            elseif ($specialty === 'laptop') $icon = 'fas fa-laptop';
                            elseif ($specialty === 'tablet') $icon = 'fas fa-tablet-alt';
                            elseif ($specialty === 'computer') $icon = 'fas fa-desktop';
                            elseif ($specialty === 'console') $icon = 'fas fa-gamepad';
                            elseif ($specialty === 'tv') $icon = 'fas fa-tv';
                            elseif ($specialty === 'audio') $icon = 'fas fa-headphones';
                        }
                    ?>
                    <div class="specialty-badge">
                        <i class="<?php echo $icon; ?>"></i> <?php echo htmlspecialchars($label); ?>
                    </div>
                    <?php } ?>
                </div>

                <?php if (!empty($educationArray)): ?>
                <div class="info-box">
                    <h4 class="info-box-title">
                        <i class="fas fa-graduation-cap"></i> Education
                    </h4>
                    <ul class="info-list">
                        <?php foreach($educationArray as $education): ?>
                            <li><?php echo htmlspecialchars($education); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if ($technician['response_time']): ?>
                <div class="info-box">
                    <h4 class="info-box-title">
                        <i class="fas fa-clock"></i> Response Time
                    </h4>
                    <p><?php echo htmlspecialchars($technician['response_time']); ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="profile-tab" id="servicesTab">
                <h2 class="tab-title">
                    <i class="fas fa-tools"></i> Services Offered
                </h2>
                
                <?php if ($servicesResult && $servicesResult->num_rows > 0): 
                    // Reset pointer to first row
                    $servicesResult->data_seek(0);
                ?>
                <div class="services-list">
                    <?php while($service = $servicesResult->fetch_assoc()): ?>
                    <div class="service-card">
                        <h3 class="service-title"><?php echo htmlspecialchars($service['service_name']); ?></h3>
                        <p class="service-description">
                            <?php echo htmlspecialchars($service['description']); ?>
                        </p>
                        <div class="service-meta">
                            <div class="service-price">
                                <img src="sar/sar.png" alt="SAR" style="height: 16px; margin-right: 2px;"><?php echo number_format($service['price'], 2); ?>
                            </div>
                            <div class="service-duration">
                                <i class="far fa-clock"></i> <?php echo htmlspecialchars($service['duration']); ?> min
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i> No specific services listed. Please contact the technician for details.
                </div>
                
                <div class="info-box">
                    <h4 class="info-box-title">
                        <i class="fas fa-tools"></i> General Services
                    </h4>
                    <p>This technician offers repair and maintenance services in the following areas:</p>
                    
                    <div class="specialties-grid">
                        <?php 
                        foreach($specialtiesArray as $specialty) {
                            $specialty = trim($specialty);
                            $icon = 'fas fa-tools';
                            $label = $specialty;
                            
                            if (isset($serviceCategories[$specialty])) {
                                $label = $serviceCategories[$specialty];
                                
                                if ($specialty === 'smartphone') $icon = 'fas fa-mobile-alt';
                                elseif ($specialty === 'laptop') $icon = 'fas fa-laptop';
                                elseif ($specialty === 'tablet') $icon = 'fas fa-tablet-alt';
                                elseif ($specialty === 'computer') $icon = 'fas fa-desktop';
                                elseif ($specialty === 'console') $icon = 'fas fa-gamepad';
                                elseif ($specialty === 'tv') $icon = 'fas fa-tv';
                                elseif ($specialty === 'audio') $icon = 'fas fa-headphones';
                            }
                        ?>
                        <div class="specialty-badge">
                            <i class="<?php echo $icon; ?>"></i> <?php echo htmlspecialchars($label); ?>
                        </div>
                        <?php } ?>
                    </div>
                    
                    <div class="mt-4 d-flex justify-content-between align-items-center">
                        <div>
                            <p class="mb-0">Hourly Rate:</p>
                            <div class="service-price">
                                <img src="sar/sar.png" alt="SAR" style="height: 16px; margin-right: 2px;"><?php echo number_format($technician['hourly_rate'] ?? 0, 2); ?>/hr
                            </div>
                        </div>
                        
                        <?php if($loggedIn && $userRole === 'customer'): ?>
                        <button class="btn-book" id="bookNowBtnServices">
                            <i class="fas fa-calendar-check"></i> Book Now
                        </button>
                        <?php elseif(!$loggedIn): ?>
                        <a href="login.php?redirect=technician.php?id=<?php echo $technicianId; ?>" class="btn-book">
                            <i class="fas fa-lock"></i> Login to Book
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="profile-tab" id="reviewsTab">
                <div class="reviews-header">
                    <div>
                        <h2 class="tab-title">
                            <i class="fas fa-star"></i> Customer Reviews
                        </h2>
                    </div>
                    
                    <div class="rating-large">
                        <div class="rating-number"><?php echo number_format($rating, 1); ?></div>
                        <div class="rating-stars-large">
                            <?php for($i=1; $i<=5; $i++): ?>
                                <?php if($i <= floor($rating)): ?>
                                    <i class="fas fa-star"></i>
                                <?php elseif($i - $rating > 0 && $i - $rating < 1): ?>
                                    <i class="fas fa-star-half-alt"></i>
                                <?php else: ?>
                                    <i class="far fa-star"></i>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                        <div class="rating-count"><?php echo $technician['review_count']; ?> reviews</div>
                    </div>
                </div>
                
                <?php if($loggedIn && $userRole === 'customer' && $hasBooked): ?>
                <div id="writeReviewContainer" class="mb-4">
                 
                    
                    <div class="review-form mt-3" id="reviewForm" style="display: none;">
                        <h4 class="mb-3">Your Review</h4>
                        <form id="reviewSubmitForm">
                            <input type="hidden" name="provider_id" value="<?php echo $providerId; ?>">
                            <input type="hidden" name="review_action" value="submit_review">
                            
                            <div class="mb-3">
                                <label for="rating" class="form-label">Rating</label>
                                <div class="rating-select" id="ratingSelect">
                                    <span class="rating-option" data-rating="1"><i class="fas fa-star"></i></span>
                                    <span class="rating-option" data-rating="2"><i class="fas fa-star"></i></span>
                                    <span class="rating-option" data-rating="3"><i class="fas fa-star"></i></span>
                                    <span class="rating-option" data-rating="4"><i class="fas fa-star"></i></span>
                                    <span class="rating-option" data-rating="5"><i class="fas fa-star"></i></span>
                                </div>
                                <input type="hidden" name="rating" id="ratingInput" value="5">
                            </div>
                            
                            <div class="mb-3">
                                <label for="reviewText" class="form-label">Your Review</label>
                                <textarea class="form-control" id="reviewText" name="review_text" rows="4" placeholder="Share your experience with this technician..." required></textarea>
                            </div>
                            
                            <div class="d-flex justify-content-end">
                                <button type="button" class="btn btn-outline-secondary me-2" id="cancelReviewBtn">Cancel</button>
                                <button type="submit" class="btn-send">Submit Review <i class="fas fa-paper-plane"></i></button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Reviews List -->
                <?php if ($reviewsResult && $reviewsResult->num_rows > 0): 
                    // Reset pointer to first row
                    $reviewsResult->data_seek(0);
                ?>
                <div class="reviews-list">
                    <?php while($review = $reviewsResult->fetch_assoc()): 
                        // Format the review date
                        $reviewDate = new DateTime($review['created_at']);
                        $formattedDate = $reviewDate->format('M d, Y');
                        
                        // Handle reviewer profile image
                        $reviewerImage = 'images/default.png';
                        if (!empty($review['profile_image'])) {
                            if (preg_match('/^https?:\/\//', $review['profile_image'])) {
                                $reviewerImage = $review['profile_image'];
                            } else {
                                $reviewerImage = (strpos($review['profile_image'], 'images/') === false) 
                                    ? 'images/' . ltrim($review['profile_image'], '/') 
                                    : ltrim($review['profile_image'], '/');
                            }
                        }
                        
                        // Format reviewer name
                        $reviewerName = $review['first_name'] . ' ' . substr($review['last_name'], 0, 1) . '.';
                    ?>
                    <div class="review-card">
                        <div class="review-header">
                            <div class="reviewer-avatar">
                                <img src="<?php echo htmlspecialchars($reviewerImage); ?>" 
                                     alt="<?php echo htmlspecialchars($reviewerName); ?>"
                                     onerror="this.src='images/default.png'; this.onerror=null;">
                            </div>
                            
                            <div class="reviewer-info">
                                <div class="reviewer-name"><?php echo htmlspecialchars($reviewerName); ?></div>
                                <div class="review-date"><?php echo $formattedDate; ?></div>
                                <div class="review-rating">
                                    <div class="review-stars">
                                        <?php for($i=1; $i<=5; $i++): ?>
                                            <?php if($i <= $review['rating']): ?>
                                                <i class="fas fa-star"></i>
                                            <?php else: ?>
                                                <i class="far fa-star"></i>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="review-text">
                            <?php echo nl2br(htmlspecialchars($review['review_text'])); ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                
                <?php if ($technician['review_count'] > 10): ?>
                <div class="d-flex justify-content-center mt-4">
                    <a href="reviews.php?id=<?php echo $technicianId; ?>" class="btn btn-outline-primary">
                        View All <?php echo $technician['review_count']; ?> Reviews <i class="fas fa-arrow-right ms-2"></i>
                    </a>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i> No reviews yet. Be the first to review this technician!
                </div>
                <?php endif; ?>
            </div>
            
            <?php if($loggedIn && $userRole === 'customer'): ?>
            <div class="profile-tab" id="bookTab">
                <h2 class="tab-title">
                    <i class="fas fa-calendar-check"></i> Book an Appointment
                </h2>
                
                <!-- Booking Steps -->
                <div class="booking-steps">
                    <div class="step-item active" data-step="1">
                        <div class="step-circle">
                            <span class="step-number">1</span>
                        </div>
                        <div class="step-name">Select Service</div>
                    </div>
                    
                    <div class="step-item" data-step="2">
                        <div class="step-circle">
                            <span class="step-number">2</span>
                        </div>
                        <div class="step-name">Choose Date</div>
                    </div>
                    
                    <div class="step-item" data-step="3">
                        <div class="step-circle">
                            <span class="step-number">3</span>
                        </div>
                        <div class="step-name">Select Time</div>
                    </div>
                    
                    <div class="step-item" data-step="4">
                        <div class="step-circle">
                            <span class="step-number">4</span>
                        </div>
                        <div class="step-name">Confirm</div>
                    </div>
                </div>
                
                <!-- Step 1: Select Service -->
                <div class="step-content active" id="step1Content">
                    <h3 class="mb-4">Select a Service</h3>
                    
                    <?php 
                    // Reset services result pointer if needed
                    if ($servicesResult) {
                        $servicesResult->data_seek(0);
                    }
                    
                    if ($servicesResult && $servicesResult->num_rows > 0): 
                    ?>
                    <div class="booking-services">
                        <?php while($service = $servicesResult->fetch_assoc()): ?>
                        <div class="booking-service-card" data-service-id="<?php echo $service['id']; ?>" data-service-name="<?php echo htmlspecialchars($service['service_name']); ?>" data-service-price="<?php echo $service['price']; ?>" data-service-duration="<?php echo htmlspecialchars($service['duration']); ?>">
                            <div class="booking-service-name"><?php echo htmlspecialchars($service['service_name']); ?></div>
                            <div class="booking-service-description"><?php echo htmlspecialchars(substr($service['description'], 0, 80) . (strlen($service['description']) > 80 ? '...' : '')); ?></div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="booking-service-price">
                                    <img src="sar/sar.png" alt="SAR" style="height: 16px; margin-right: 2px;"><?php echo number_format($service['price'], 2); ?>
                                </div>
                                <div class="booking-service-duration">
                                    <i class="far fa-clock"></i> <?php echo htmlspecialchars($service['duration']); ?> min
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php else: ?>
                    <div class="booking-services">
                        <div class="booking-service-card" data-service-id="custom" data-service-name="Hourly Service" data-service-price="<?php echo $technician['hourly_rate']; ?>" data-service-duration="1 hour">
                            <div class="booking-service-name">Hourly Service</div>
                            <div class="booking-service-description">General repair and troubleshooting service charged at the technician's hourly rate.</div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="booking-service-price">
                                    <img src="sar/sar.png" alt="SAR" style="height: 16px; margin-right: 2px;"><?php echo number_format($technician['hourly_rate'], 2); ?>
                                </div>
                                <div class="booking-service-duration">
                                    <i class="far fa-clock"></i> 1 hour
                                </div>
                            </div>
                        </div>
                        
                        <?php foreach($specialtiesArray as $key => $specialty): 
                            $specialty = trim($specialty);
                            $label = isset($serviceCategories[$specialty]) ? $serviceCategories[$specialty] : ucfirst($specialty);
                        ?>
                        <div class="booking-service-card" data-service-id="custom_<?php echo $key; ?>" data-service-name="<?php echo htmlspecialchars($label); ?>" data-service-price="<?php echo $technician['hourly_rate']; ?>" data-service-duration="1 hour">
                            <div class="booking-service-name"><?php echo htmlspecialchars($label); ?></div>
                            <div class="booking-service-description">Specialized repair and support for <?php echo htmlspecialchars($label); ?>.</div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="booking-service-price">
                                    <img src="sar/sar.png" alt="SAR" style="height: 16px; margin-right: 2px;"><?php echo number_format($technician['hourly_rate'], 2); ?>
                                </div>
                                <div class="booking-service-duration">
                                    <i class="far fa-clock"></i> 1 hour
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="alert alert-info mt-4">
                        <i class="fas fa-info-circle me-2"></i> Select a service to proceed to the next step.
                    </div>
                    
                    <div class="nav-buttons">
                        <a href="marketplace.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i> Back to Marketplace
                        </a>
                        <button class="btn-next" id="nextStep1" disabled>
                            Choose Date <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Step 2: Choose Date -->
                <div class="step-content" id="step2Content">
                    <h3 class="mb-4">Select a Date</h3>
                    
                    <div class="booking-calendar-container">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <button class="btn btn-sm btn-outline-secondary" id="prevMonth">
                                <i class="fas fa-chevron-left"></i> Prev
                            </button>
                            <h4 class="mb-0" id="currentMonth">March 2025</h4>
                            <button class="btn btn-sm btn-outline-secondary" id="nextMonth">
                                Next <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                        
                        <div class="booking-calendar">
                            <div class="calendar-weekday">Sun</div>
                            <div class="calendar-weekday">Mon</div>
                            <div class="calendar-weekday">Tue</div>
                            <div class="calendar-weekday">Wed</div>
                            <div class="calendar-weekday">Thu</div>
                            <div class="calendar-weekday">Fri</div>
                            <div class="calendar-weekday">Sat</div>
                            
                            <!-- Calendar days will be generated via JavaScript -->
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-4">
                        <i class="fas fa-info-circle me-2"></i> Select a date to proceed to the next step.
                    </div>
                    
                    <div class="nav-buttons">
                        <button class="btn-back" id="backStep2">
                            <i class="fas fa-arrow-left"></i> Back to Services
                        </button>
                        <button class="btn-next" id="nextStep2" disabled>
                            Select Time <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Step 3: Select Time -->
                <div class="step-content" id="step3Content">
                    <h3 class="mb-4">Select Time Slot</h3>
                    
                    <p>Available time slots for <span id="selectedDateDisplay">March 12, 2025</span>:</p>
                    
                    <div class="time-slots" id="timeSlotsContainer">
                        <!-- Time slots will be generated dynamically based on the selected date -->
                        <div class="alert alert-info w-100">Please select a date to view available time slots.</div>
                    </div>
                    
                    <div class="alert alert-info mt-4">
                        <i class="fas fa-info-circle me-2"></i> Select a time slot to proceed to the final step.
                    </div>
                    
                    <div class="nav-buttons">
                        <button class="btn-back" id="backStep3">
                            <i class="fas fa-arrow-left"></i> Back to Calendar
                        </button>
                        <button class="btn-next" id="nextStep3" disabled>
                            Review & Confirm <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Step 4: Confirm Booking -->
                <div class="step-content" id="step4Content">
                    <h3 class="mb-4">Review and Confirm Booking</h3>
                    
                    <div class="booking-summary">
                        <div class="summary-item">
                            <div class="summary-label">Technician:</div>
                            <div class="summary-value"><?php echo htmlspecialchars($fullName); ?></div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Service:</div>
                            <div class="summary-value" id="summaryService">Smartphone Screen Repair</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Date:</div>
                            <div class="summary-value" id="summaryDate">March 12, 2025</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Time:</div>
                            <div class="summary-value" id="summaryTime">10:00 AM</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Duration:</div>
                            <div class="summary-value" id="summaryDuration">1 hour</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">Price:</div>
                            <div class="summary-value summary-total" id="summaryPrice">
                                <img src="sar/sar.png" alt="SAR" style="height: 16px; margin-right: 2px;">120.00
                            </div>
                        </div>
                    </div>
                    
                    <form id="bookingForm">
                        <input type="hidden" name="booking_action" value="process_booking">
                        <input type="hidden" name="provider_id" value="<?php echo $providerId; ?>">
                        <input type="hidden" name="service_id" id="serviceIdInput">
                        <input type="hidden" name="service_name" id="serviceNameInput">
                        <input type="hidden" name="booking_date" id="bookingDateInput">
                        <input type="hidden" name="booking_time" id="bookingTimeInput">
                        <input type="hidden" name="price" id="priceInput">
                        
                        <div class="mb-3">
                            <label for="bookingNotes" class="form-label">Additional Notes (optional)</label>
                            <textarea class="form-control" id="bookingNotes" name="notes" rows="3" placeholder="Add any special requests or details about your repair needs..."></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i> By confirming this booking, you agree to our <a href="terms.php" target="_blank">Terms of Service</a> and <a href="cancellation-policy.php" target="_blank">Cancellation Policy</a>.
                        </div>
                        
                        <div class="nav-buttons">
                            <button type="button" class="btn-back" id="backStep4">
                                <i class="fas fa-arrow-left"></i> Back to Time Selection
                            </button>
                            <button type="submit" class="btn-book">
                                <i class="fas fa-calendar-check"></i> Confirm Booking
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="profile-tab" id="contactTab">
                <h2 class="tab-title">
                    <i class="fas fa-envelope"></i> Contact <?php echo explode(' ', $fullName)[0]; ?>
                </h2>
                
                <div class="contact-methods">
                    <div class="contact-method">
                        <div class="contact-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <h3 class="contact-title">Email</h3>
                        <p class="contact-value"><?php echo htmlspecialchars($technician['email']); ?></p>
                        <a href="mailto:<?php echo htmlspecialchars($technician['email']); ?>" class="contact-link">
                            Send Email <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    
                    <div class="contact-method">
                        <div class="contact-icon">
                            <i class="fas fa-comment-alt"></i>
                        </div>
                        <h3 class="contact-title">Message</h3>
                        <p class="contact-value">Send a direct message through our platform</p>
                        <a href="#messageForm" class="contact-link" id="showMessageFormBtn">
                            Write Message <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    
                    <div class="contact-method">
                        <div class="contact-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <h3 class="contact-title">Book Now</h3>
                        <p class="contact-value">Schedule a repair appointment</p>
                        <?php if($loggedIn && $userRole === 'customer'): ?>
                        <a href="#" class="contact-link" id="contactBookNowBtn">
                            Book Appointment <i class="fas fa-arrow-right"></i>
                        </a>
                        <?php elseif(!$loggedIn): ?>
                        <a href="login.php?redirect=technician.php?id=<?php echo $technicianId; ?>" class="contact-link">
                            Login to Book <i class="fas fa-arrow-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if($loggedIn): ?>
                <div class="message-form mt-4" id="messageForm" style="display: none;">
                    <h3 class="mb-3">Send Message</h3>
                    <form id="messageSubmitForm">
                        <input type="hidden" name="recipient_id" value="<?php echo $technicianId; ?>">
                        <input type="hidden" name="message_action" value="send_message">
                        
                        <div class="mb-3">
                            <label for="messageSubject" class="form-label">Subject</label>
                            <input type="text" class="form-control" id="messageSubject" name="subject" placeholder="Enter message subject..." required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="messageContent" class="form-label">Message</label>
                            <textarea class="form-control" id="messageContent" name="message" rows="5" placeholder="Type your message here..." required></textarea>
                        </div>
                        
                        <div class="d-flex justify-content-end">
                            <button type="button" class="btn btn-outline-secondary me-2" id="cancelMessageBtn">Cancel</button>
                            <button type="submit" class="btn-send">Send Message <i class="fas fa-paper-plane"></i></button>
                        </div>
                    </form>
                </div>
                <?php else: ?>
                <div class="alert alert-info mt-4">
                    <i class="fas fa-info-circle me-2"></i> 
                    Please <a href="login.php?redirect=technician.php?id=<?php echo $technicianId; ?>" class="alert-link">log in</a> to send a message to this technician.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="site-footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <div class="footer-logo">
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                    <p>Connecting you with skilled technicians for all your device repair needs. Fast, reliable, and affordable electronic repairs.</p>
                    <div class="social-links mt-4">
                        <a href="#" class="me-3 text-white"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="me-3 text-white"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="me-3 text-white"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="text-white"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-4 col-6 mt-4 mt-lg-0">
                    <h5 class="footer-heading">Navigation</h5>
                    <ul class="footer-links">
                        <li><a href="index.php">Home</a></li>
                        <li><a href="marketplace.php">Find Technicians</a></li>
                        <li><a href="signup.php?type=provider">Become a Provider</a></li>
                        <li><a href="about.php">About Us</a></li>
                        <li><a href="contact.php">Contact Us</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-4 col-6 mt-4 mt-lg-0">
                    <h5 class="footer-heading">Services</h5>
                    <ul class="footer-links">
                        <li><a href="marketplace.php?category=smartphone">Smartphone Repair</a></li>
                        <li><a href="marketplace.php?category=laptop">Laptop Repair</a></li>
                        <li><a href="marketplace.php?category=tablet">Tablet Repair</a></li>
                        <li><a href="marketplace.php?category=computer">Computer Repair</a></li>
                        <li><a href="marketplace.php?category=console">Game Console Repair</a></li>
                        <li><a href="marketplace.php?category=tv">TV/Monitor Repair</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-4 col-6 mt-4 mt-lg-0">
                    <h5 class="footer-heading">Support</h5>
                    <ul class="footer-links">
                        <li><a href="faq.php">FAQs</a></li>
                        <li><a href="terms.php">Terms of Service</a></li>
                        <li><a href="privacy.php">Privacy Policy</a></li>
                        <li><a href="cancellation-policy.php">Cancellation Policy</a></li>
                        <li><a href="help.php">Help Center</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-4 col-6 mt-4 mt-lg-0">
                    <h5 class="footer-heading">Contact</h5>
                    <ul class="footer-links">
                        <li><i class="fas fa-envelope me-2"></i> support@fixitnow.com</li>
                        <li><i class="fas fa-phone me-2"></i> +966 12 345 6789</li>
                        <li><i class="fas fa-map-marker-alt me-2"></i> Riyadh, Saudi Arabia</li>
                    </ul>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; 2025 FixItNow. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS and jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Theme toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const htmlElement = document.documentElement;
            
            // Check user preference from localStorage
            const currentTheme = localStorage.getItem('theme') || 'dark';
            htmlElement.setAttribute('data-bs-theme', currentTheme);
            updateThemeIcon(currentTheme);
            
            themeToggle.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                
                htmlElement.setAttribute('data-bs-theme', newTheme);
                localStorage.setItem('theme', newTheme);
                updateThemeIcon(newTheme);
            });
            
            function updateThemeIcon(theme) {
                if (theme === 'dark') {
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                } else {
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                }
            }
        });
        
        // Tab navigation functionality
        document.addEventListener('DOMContentLoaded', function() {
            const navItems = document.querySelectorAll('.profile-nav-item');
            const tabContents = document.querySelectorAll('.profile-tab');
            
            navItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Remove active class from all nav items
                    navItems.forEach(navItem => {
                        navItem.classList.remove('active');
                    });
                    
                    // Hide all tab contents
                    tabContents.forEach(content => {
                        content.classList.remove('active');
                    });
                    
                    // Add active class to clicked nav item
                    this.classList.add('active');
                    
                    // Show corresponding tab content
                    const tabId = this.getAttribute('data-tab') + 'Tab';
                    document.getElementById(tabId).classList.add('active');
                    
                    // Update URL hash
                    window.location.hash = this.getAttribute('data-tab');
                });
            });
            
            // Check URL hash on load
            if (window.location.hash) {
                const hash = window.location.hash.substring(1);
                const navItem = document.querySelector(`.profile-nav-item[data-tab="${hash}"]`);
                
                if (navItem) {
                    navItem.click();
                }
            }
            
            // Special handling for Book Now buttons
            const bookNowBtn = document.getElementById('bookNowBtn');
            const bookNowBtnServices = document.getElementById('bookNowBtnServices');
            const contactBookNowBtn = document.getElementById('contactBookNowBtn');
            
            if (bookNowBtn) {
                bookNowBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    document.querySelector('.profile-nav-item[data-tab="book"]').click();
                });
            }
            
            if (bookNowBtnServices) {
                bookNowBtnServices.addEventListener('click', function(e) {
                    e.preventDefault();
                    document.querySelector('.profile-nav-item[data-tab="book"]').click();
                });
            }
            
            if (contactBookNowBtn) {
                contactBookNowBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    document.querySelector('.profile-nav-item[data-tab="book"]').click();
                });
            }
        });
        
        // Review form functionality
        document.addEventListener('DOMContentLoaded', function() {
            const writeReviewBtn = document.getElementById('writeReviewBtn');
            const reviewForm = document.getElementById('reviewForm');
            const cancelReviewBtn = document.getElementById('cancelReviewBtn');
            const ratingOptions = document.querySelectorAll('.rating-option');
            const ratingInput = document.getElementById('ratingInput');
            const reviewSubmitForm = document.getElementById('reviewSubmitForm');
            
            if (writeReviewBtn && reviewForm) {
                writeReviewBtn.addEventListener('click', function() {
                    reviewForm.style.display = 'block';
                    writeReviewBtn.style.display = 'none';
                });
            }
            
            if (cancelReviewBtn) {
                cancelReviewBtn.addEventListener('click', function() {
                    reviewForm.style.display = 'none';
                    writeReviewBtn.style.display = 'inline-flex';
                });
            }
            
            if (ratingOptions) {
                ratingOptions.forEach(option => {
                    option.addEventListener('click', function() {
                        const rating = this.getAttribute('data-rating');
                        
                        // Remove selected class from all options
                        ratingOptions.forEach(opt => {
                            opt.classList.remove('selected');
                        });
                        
                        // Add selected class to options up to the clicked one
                        ratingOptions.forEach(opt => {
                            if (opt.getAttribute('data-rating') <= rating) {
                                opt.classList.add('selected');
                            }
                        });
                        
                        // Update hidden input value
                        ratingInput.value = rating;
                    });
                });
                
                // Preselect 5 stars
                ratingOptions.forEach(opt => {
                    opt.classList.add('selected');
                });
            }
            
            if (reviewSubmitForm) {
                reviewSubmitForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    // Show spinner
                    document.getElementById('spinnerOverlay').classList.add('show');
                    document.getElementById('spinnerMessage').innerText = 'Submitting your review...';
                    
                    // Submit form via AJAX
                    const formData = new FormData(this);
                    
                    fetch('technician.php?id=<?php echo $technicianId; ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        // Hide spinner
                        document.getElementById('spinnerOverlay').classList.remove('show');
                        
                        if (data.success) {
                            showToast('Review submitted successfully!', 'success');
                            
                            // Reset form and hide it
                            reviewSubmitForm.reset();
                            reviewForm.style.display = 'none';
                            writeReviewBtn.style.display = 'inline-flex';
                            
                            // Reload page after a short delay
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                        } else {
                            showToast(data.message || 'Error submitting review. Please try again.', 'error');
                        }
                    })
                    .catch(error => {
                        // Hide spinner
                        document.getElementById('spinnerOverlay').classList.remove('show');
                        showToast('An error occurred. Please try again.', 'error');
                        console.error('Error:', error);
                    });
                });
            }
        });
        
        // Contact form functionality
        document.addEventListener('DOMContentLoaded', function() {
            const showMessageFormBtn = document.getElementById('showMessageFormBtn');
            const messageForm = document.getElementById('messageForm');
            const cancelMessageBtn = document.getElementById('cancelMessageBtn');
            const messageSubmitForm = document.getElementById('messageSubmitForm');
            
            if (showMessageFormBtn && messageForm) {
                showMessageFormBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    messageForm.style.display = 'block';
                    showMessageFormBtn.parentElement.style.display = 'none';
                });
            }
            
            if (cancelMessageBtn) {
                cancelMessageBtn.addEventListener('click', function() {
                    messageForm.style.display = 'none';
                    showMessageFormBtn.parentElement.style.display = 'block';
                });
            }
            
            if (messageSubmitForm) {
                messageSubmitForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    // Show spinner
                    document.getElementById('spinnerOverlay').classList.add('show');
                    document.getElementById('spinnerMessage').innerText = 'Sending message...';
                    
                    // Submit form via AJAX
                    const formData = new FormData(this);
                    
                    fetch('technician.php?id=<?php echo $technicianId; ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        // Hide spinner
                        document.getElementById('spinnerOverlay').classList.remove('show');
                        
                        if (data.success) {
                            showToast('Message sent successfully!', 'success');
                            
                            // Reset form and hide it
                            messageSubmitForm.reset();
                            messageForm.style.display = 'none';
                            showMessageFormBtn.parentElement.style.display = 'block';
                        } else {
                            showToast(data.message || 'Error sending message. Please try again.', 'error');
                        }
                    })
                    .catch(error => {
                        // Hide spinner
                        document.getElementById('spinnerOverlay').classList.remove('show');
                        showToast('An error occurred. Please try again.', 'error');
                        console.error('Error:', error);
                    });
                });
            }
        });
        
        // Booking functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Step 1: Service selection
            const serviceCards = document.querySelectorAll('.booking-service-card');
            const nextStep1 = document.getElementById('nextStep1');
            
            if (serviceCards) {
                serviceCards.forEach(card => {
                    card.addEventListener('click', function() {
                        // Remove selected class from all cards
                        serviceCards.forEach(c => {
                            c.classList.remove('selected');
                        });
                        
                        // Add selected class to clicked card
                        this.classList.add('selected');
                        
                        // Enable next button
                        if (nextStep1) {
                            nextStep1.disabled = false;
                        }
                    });
                });
            }
            
            // Step navigation
            const stepItems = document.querySelectorAll('.step-item');
            const stepContents = document.querySelectorAll('.step-content');
            
            function goToStep(stepNumber) {
                // Update step indicators
                stepItems.forEach(item => {
                    const itemStep = parseInt(item.getAttribute('data-step'));
                    
                    item.classList.remove('active', 'completed');
                    
                    if (itemStep === stepNumber) {
                        item.classList.add('active');
                    } else if (itemStep < stepNumber) {
                        item.classList.add('completed');
                    }
                });
                
                // Show corresponding step content
                stepContents.forEach(content => {
                    content.classList.remove('active');
                });
                
                document.getElementById(`step${stepNumber}Content`).classList.add('active');
            }
            
            // Next and back buttons
            if (nextStep1) {
                nextStep1.addEventListener('click', function() {
                    goToStep(2);
                    generateCalendar(new Date());
                });
            }
            
            const backStep2 = document.getElementById('backStep2');
            if (backStep2) {
                backStep2.addEventListener('click', function() {
                    goToStep(1);
                });
            }
            
            const nextStep2 = document.getElementById('nextStep2');
            if (nextStep2) {
                nextStep2.addEventListener('click', function() {
                    goToStep(3);
                    generateTimeSlots();
                });
            }
            
            const backStep3 = document.getElementById('backStep3');
            if (backStep3) {
                backStep3.addEventListener('click', function() {
                    goToStep(2);
                });
            }
            
            const nextStep3 = document.getElementById('nextStep3');
            if (nextStep3) {
                nextStep3.addEventListener('click', function() {
                    goToStep(4);
                    updateSummary();
                });
            }
            
            const backStep4 = document.getElementById('backStep4');
            if (backStep4) {
                backStep4.addEventListener('click', function() {
                    goToStep(3);
                });
            }
            
            // Calendar functionality
            let currentDate = new Date();
            let selectedDate = null;
            
            const prevMonth = document.getElementById('prevMonth');
            const nextMonth = document.getElementById('nextMonth');
            const currentMonthElement = document.getElementById('currentMonth');
            
            if (prevMonth) {
                prevMonth.addEventListener('click', function() {
                    currentDate.setMonth(currentDate.getMonth() - 1);
                    generateCalendar(currentDate);
                });
            }
            
            if (nextMonth) {
                nextMonth.addEventListener('click', function() {
                    currentDate.setMonth(currentDate.getMonth() + 1);
                    generateCalendar(currentDate);
                });
            }
            
            function generateCalendar(date) {
                if (!currentMonthElement) return;
                
                const year = date.getFullYear();
                const month = date.getMonth();
                
                const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                currentMonthElement.innerText = `${monthNames[month]} ${year}`;
                
                const calendarContainer = document.querySelector('.booking-calendar');
                if (!calendarContainer) return;
                
                // Clear weekday headers
                while (calendarContainer.children.length > 7) {
                    calendarContainer.removeChild(calendarContainer.lastChild);
                }
                
                // Get first day of month and total days in month
                const firstDay = new Date(year, month, 1).getDay();
                const daysInMonth = new Date(year, month + 1, 0).getDate();
                
                // Create empty slots for days before first day of month
                for (let i = 0; i < firstDay; i++) {
                    const emptyDay = document.createElement('div');
                    emptyDay.className = 'calendar-day disabled';
                    calendarContainer.appendChild(emptyDay);
                }
                
                // Create day elements
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                
                for (let i = 1; i <= daysInMonth; i++) {
                    const dayElement = document.createElement('div');
                    dayElement.className = 'calendar-day';
                    dayElement.innerText = i;
                    
                    const currentDateObj = new Date(year, month, i);
                    
                    // Disable past dates
                    if (currentDateObj < today) {
                        dayElement.classList.add('disabled');
                    } else {
                        // Check if this date is selected
                        if (selectedDate && 
                            selectedDate.getDate() === i && 
                            selectedDate.getMonth() === month && 
                            selectedDate.getFullYear() === year) {
                            dayElement.classList.add('selected');
                        }
                        
                        dayElement.addEventListener('click', function() {
                            if (!this.classList.contains('disabled')) {
                                // Remove selected class from all days
                                document.querySelectorAll('.calendar-day').forEach(day => {
                                    day.classList.remove('selected');
                                });
                                
                                // Add selected class to clicked day
                                this.classList.add('selected');
                                
                                // Update selected date
                                selectedDate = new Date(year, month, i);
                                
                                // Enable next button
                                if (nextStep2) {
                                    nextStep2.disabled = false;
                                }
                                
                                // Update displayed date
                                const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                                const dateString = selectedDate.toLocaleDateString('en-US', options);
                                
                                const selectedDateDisplay = document.getElementById('selectedDateDisplay');
                                if (selectedDateDisplay) {
                                    selectedDateDisplay.innerText = dateString;
                                }
                                
                                // Generate time slots for this date
                                generateTimeSlots();
                            }
                        });
                    }
                    
                    calendarContainer.appendChild(dayElement);
                }
            }
            
            function generateTimeSlots() {
                if (!selectedDate) return;
                
                const timeSlotsContainer = document.getElementById('timeSlotsContainer');
                if (!timeSlotsContainer) return;
                
                // Clear existing time slots
                timeSlotsContainer.innerHTML = '';
                
                // Demo time slots (in a real app, these would come from the database)
                const availableSlots = [
                    { start: '09:00:00', end: '10:00:00', available: true },
                    { start: '10:00:00', end: '11:00:00', available: true },
                    { start: '11:00:00', end: '12:00:00', available: false },
                    { start: '12:00:00', end: '13:00:00', available: true },
                    { start: '13:00:00', end: '14:00:00', available: true },
                    { start: '14:00:00', end: '15:00:00', available: true },
                    { start: '15:00:00', end: '16:00:00', available: false },
                    { start: '16:00:00', end: '17:00:00', available: true }
                ];
                
                let selectedTimeSlot = null;
                
                availableSlots.forEach(slot => {
                    const timeSlot = document.createElement('div');
                    timeSlot.className = 'time-slot';
                    if (!slot.available) {
                        timeSlot.classList.add('disabled');
                    }
                    
                    // Format time for display (24h to 12h)
                    const displayTime = formatTime(slot.start);
                    timeSlot.innerText = displayTime;
                    
                    if (slot.available) {
                        timeSlot.addEventListener('click', function() {
                            // Remove selected class from all time slots
                            document.querySelectorAll('.time-slot').forEach(ts => {
                                ts.classList.remove('selected');
                            });
                            
                            // Add selected class to clicked time slot
                            this.classList.add('selected');
                            
                            // Update selected time slot
                            selectedTimeSlot = slot;
                            
                            // Enable next button
                            if (nextStep3) {
                                nextStep3.disabled = false;
                            }
                        });
                    }
                    
                    timeSlotsContainer.appendChild(timeSlot);
                });
            }
            
            // Format time from 24-hour to 12-hour format
            function formatTime(time24) {
                const [hours, minutes] = time24.split(':');
                const hour = parseInt(hours, 10);
                const ampm = hour >= 12 ? 'PM' : 'AM';
                const hour12 = hour % 12 || 12;
                return `${hour12}:${minutes} ${ampm}`;
            }
            
            // Update booking summary
            function updateSummary() {
                // Get selected service
                const selectedService = document.querySelector('.booking-service-card.selected');
                if (!selectedService) return;
                
                const serviceName = selectedService.getAttribute('data-service-name');
                const servicePrice = parseFloat(selectedService.getAttribute('data-service-price'));
                const serviceDuration = selectedService.getAttribute('data-service-duration');
                const serviceId = selectedService.getAttribute('data-service-id');
                
                // Get selected date
                if (!selectedDate) return;
                const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                const dateString = selectedDate.toLocaleDateString('en-US', options);
                
                // Get selected time
                const selectedTimeSlot = document.querySelector('.time-slot.selected');
                if (!selectedTimeSlot) return;
                const timeString = selectedTimeSlot.innerText;
                
                // Update summary elements
                document.getElementById('summaryService').innerText = serviceName;
                document.getElementById('summaryDate').innerText = dateString;
                document.getElementById('summaryTime').innerText = timeString;
                document.getElementById('summaryDuration').innerText = serviceDuration;
                document.getElementById('summaryPrice').innerHTML = `<img src="sar/sar.png" alt="SAR" style="height: 16px; margin-right: 2px;">${servicePrice.toFixed(2)}`;
                
                // Update hidden form inputs
                document.getElementById('serviceIdInput').value = serviceId;
                document.getElementById('serviceNameInput').value = serviceName;
                document.getElementById('bookingDateInput').value = selectedDate.toISOString().split('T')[0];
                
                // Format time for database (12h to 24h)
                const timeForDB = convertTo24Hour(timeString);
                document.getElementById('bookingTimeInput').value = timeForDB;
                document.getElementById('priceInput').value = servicePrice.toFixed(2);
            }
            
            // Convert 12-hour time to 24-hour time
            function convertTo24Hour(time12h) {
                const [time, modifier] = time12h.split(' ');
                let [hours, minutes] = time.split(':');
                
                if (hours === '12') {
                    hours = '00';
                }
                
                if (modifier === 'PM') {
                    hours = parseInt(hours, 10) + 12;
                }
                
                return `${hours}:${minutes}:00`;
            }
            
            // Form submission
            const bookingForm = document.getElementById('bookingForm');
            if (bookingForm) {
                bookingForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    // Show spinner
                    document.getElementById('spinnerOverlay').classList.add('show');
                    document.getElementById('spinnerMessage').innerText = 'Processing your booking...';
                    
                    // Submit form via AJAX
                    const formData = new FormData(this);
                    
                    fetch('technician.php?id=<?php echo $technicianId; ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        // Hide spinner
                        document.getElementById('spinnerOverlay').classList.remove('show');
                        
                        if (data.success) {
                            showToast('Booking submitted successfully!', 'success');
                            
                            // Redirect to bookings page after a short delay
                            setTimeout(() => {
                                window.location.href = 'customer/dashboard.php';
                            }, 2000);
                        } else {
                            showToast(data.message || 'Error processing booking. Please try again.', 'error');
                        }
                    })
                    .catch(error => {
                        // Hide spinner
                        document.getElementById('spinnerOverlay').classList.remove('show');
                        showToast('An error occurred. Please try again.', 'error');
                        console.error('Error:', error);
                    });
                });
            }
        });
        
        // Toast notification function
        function showToast(message, type = 'info') {
            const toastContainer = document.querySelector('.toast-container');
            const toast = document.createElement('div');
            
            toast.className = `custom-toast ${type} toast show`;
            toast.setAttribute('role', 'alert');
            toast.setAttribute('aria-live', 'assertive');
            toast.setAttribute('aria-atomic', 'true');
            
            toast.innerHTML = `
                <div class="toast-header">
                    <strong class="me-auto">${type === 'success' ? 'Success' : type === 'error' ? 'Error' : 'Notification'}</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">
                    ${message}
                </div>
            `;
            
            toastContainer.appendChild(toast);
            
            // Initialize Bootstrap toast
            const bsToast = new bootstrap.Toast(toast, {
                autohide: true,
                delay: 5000
            });
            
            bsToast.show();
            
            // Remove toast from DOM after it's hidden
            toast.addEventListener('hidden.bs.toast', function() {
                this.remove();
            });
        }
    </script>
</body>
</html>