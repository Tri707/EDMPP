<?php
/**
 * Error Page - FixItNow Platform
 * 
 * This file displays error messages to users in a friendly format.
 */

// Get error message from URL
$errorMessage = isset($_GET['message']) ? urldecode($_GET['message']) : 'An unknown error occurred.';

// Sanitize the error message to prevent XSS
$errorMessage = htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8');

// Set a default title based on the error
$errorTitle = 'Error';
if (strpos($errorMessage, 'Database') !== false) {
    $errorTitle = 'Database Error';
} elseif (strpos($errorMessage, 'Permission') !== false || strpos($errorMessage, 'access') !== false) {
    $errorTitle = 'Access Denied';
} elseif (strpos($errorMessage, 'not found') !== false) {
    $errorTitle = 'Not Found';
}

// Log the error
error_log("Error displayed to user: $errorMessage");
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - FixItNow</title>
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
            --border-color: #dee2e6;       /* Border color */
            --shadow-color: rgba(0, 0, 0, 0.1); /* Shadow color */
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
            --border-color: #3d4349;       /* More visible border */
            --shadow-color: rgba(0, 0, 0, 0.35); /* Slightly stronger shadow */
        }
        
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .header {
            background-color: var(--header-bg);
            padding: 1rem 0;
            color: var(--header-text);
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
        
        .error-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        
        .error-card {
            max-width: 600px;
            width: 100%;
            border-radius: 0.75rem;
            overflow: hidden;
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
            background-color: var(--card-bg);
        }
        
        .error-header {
            background-color: #dc3545;
            color: white;
            padding: 1.5rem;
        }
        
        .error-body {
            padding: 2rem;
        }
        
        .error-icon {
            font-size: 4rem;
            color: #dc3545;
            margin-bottom: 1.5rem;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <a href="index.php" class="text-decoration-none">
                    <div class="logo-text">
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                </a>
            </div>
        </div>
    </header>

    <!-- Error Content -->
    <div class="error-container">
        <div class="error-card">
            <div class="error-header">
                <h4 class="m-0"><i class="fas fa-exclamation-triangle me-2"></i><?php echo $errorTitle; ?></h4>
            </div>
            <div class="error-body text-center">
                <div class="error-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <h4 class="mb-4">Something went wrong!</h4>
                <p class="mb-4"><?php echo $errorMessage; ?></p>
                
                <?php if (strpos($errorMessage, 'Database') !== false): ?>
                <div class="alert alert-info">
                    <h5><i class="fas fa-info-circle me-2"></i>Troubleshooting Tips</h5>
                    <ul class="text-start mb-0">
                        <li>Check if the database server is running</li>
                        <li>Verify database credentials in the configuration file</li>
                        <li>Ensure the database exists and is accessible</li>
                        <li>Contact the administrator if the problem persists</li>
                    </ul>
                </div>
                <?php endif; ?>
                
                <div class="d-grid gap-2 d-md-flex justify-content-md-center mt-4">
                    <a href="javascript:history.back()" class="btn btn-outline-secondary me-md-2">
                        <i class="fas fa-arrow-left me-1"></i> Go Back
                    </a>
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-home me-1"></i> Go to Homepage
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Theme detection and handling
        document.addEventListener('DOMContentLoaded', function() {
            const htmlElement = document.documentElement;
            
            // Check for saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) {
                htmlElement.setAttribute('data-bs-theme', savedTheme);
            } else {
                // Default to dark theme
                htmlElement.setAttribute('data-bs-theme', 'dark');
                localStorage.setItem('theme', 'dark');
            }
        });
    </script>
</body>
</html>