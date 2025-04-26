<?php
session_start();

// Check if user is logged in and is a customer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: ../login.php');
    exit();
}

// Database connection
$dbConfig = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'fixitnow_db'
];

$conn = new mysqli($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['database']);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get user information
$userId = $_SESSION['user_id'];
$userQuery = "SELECT * FROM users WHERE id = $userId";
$userResult = $conn->query($userQuery);
$userData = $userResult->fetch_assoc();

// Get favorites count
$favoriteCountQuery = "SELECT COUNT(*) as count FROM favorites WHERE customer_id = $userId";
$favoriteCountResult = $conn->query($favoriteCountQuery);
$favoriteCount = $favoriteCountResult->fetch_assoc()['count'];

// Get favorite technicians with details
$favoritesQuery = "SELECT u.id, u.first_name, u.last_name, u.email, 
                          p.id as provider_id, p.specialties, p.hourly_rate, p.location,
                          f.created_at as favorited_at,
                          AVG(r.rating) as avg_rating,
                          COUNT(DISTINCT r.id) as review_count,
                          COUNT(DISTINCT b.id) as booking_count
                   FROM favorites f
                   JOIN providers p ON f.provider_id = p.id
                   JOIN users u ON p.user_id = u.id
                   LEFT JOIN reviews r ON p.id = r.provider_id
                   LEFT JOIN bookings b ON p.id = b.provider_id AND b.customer_id = f.customer_id
                   WHERE f.customer_id = $userId
                   GROUP BY u.id
                   ORDER BY f.created_at DESC";
$favoritesResult = $conn->query($favoritesQuery);

// Handle remove from favorites
if (isset($_POST['remove_favorite']) && isset($_POST['provider_id'])) {
    $providerId = (int)$_POST['provider_id'];
    $removeQuery = "DELETE FROM favorites WHERE customer_id = $userId AND provider_id = $providerId";
    
    if ($conn->query($removeQuery)) {
        header("Location: favorites.php?removed=success");
        exit();
    }
}

// Flash messages
$flashMessage = '';
if (isset($_GET['removed']) && $_GET['removed'] === 'success') {
    $flashMessage = '<div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>Technician removed from favorites.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>';
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Favorites | FixItNow</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="../css/styles.css" rel="stylesheet">
    <link href="../css/dashboard.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .favorite-card {
            transition: all 0.3s ease;
            border: none;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .favorite-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        .favorite-header {
            position: relative;
            height: 100px;
            background: linear-gradient(135deg, #e6f2ff 0%, #d0e5ff 100%);
        }
        
        .favorite-avatar {
            position: absolute;
            bottom: -30px;
            left: 20px;
            width: 80px;
            height: 80px;
            border: 4px solid white;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .favorite-body {
            padding-top: 40px;
        }
        
        .rating {
            color: #ffc107;
        }
        
        .specialty-badge {
            background-color: #f8f9fa;
            color: #6c757d;
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
            border-radius: 1rem;
            margin: 0.25rem;
            display: inline-block;
        }
        
        .favorite-stats {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-top: 1rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #dee2e6;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <!-- Include the same navbar as dashboard.php -->
    <?php include 'navbar.php'; ?>

    <div class="dashboard-wrapper">
        <!-- Include the same sidebar as dashboard.php -->
      
        
        <!-- Main Content -->
        <main class="dashboard-main">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-0 text-gray-800">My Favorites</h1>
                        <p class="mb-0 text-muted">Manage your favorite technicians</p>
                    </div>
                    <a href="../marketplace.php" class="btn btn-primary">
                        <i class="fas fa-search me-2"></i>Find More Technicians
                    </a>
                </div>

                <!-- Flash Messages -->
                <?php echo $flashMessage; ?>

                <!-- Favorites Grid -->
                <?php if($favoritesResult && $favoritesResult->num_rows > 0): ?>
                    <div class="row g-4">
                        <?php while($favorite = $favoritesResult->fetch_assoc()): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="favorite-card">
                                    <div class="favorite-header">
                                        <img src="../images/default.png" alt="<?php echo htmlspecialchars($favorite['first_name']); ?>" class="favorite-avatar">
                                    </div>
                                    <div class="card-body favorite-body">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h5 class="mb-1"><?php echo htmlspecialchars($favorite['first_name'] . ' ' . $favorite['last_name']); ?></h5>
                                                <p class="text-muted mb-2">
                                                    <i class="fas fa-map-marker-alt me-1"></i>
                                                    <?php echo htmlspecialchars($favorite['location']); ?>
                                                </p>
                                            </div>
                                            <form method="post" onsubmit="return confirm('Remove this technician from favorites?');">
                                                <input type="hidden" name="provider_id" value="<?php echo $favorite['provider_id']; ?>">
                                                <button type="submit" name="remove_favorite" class="btn btn-link text-danger p-0">
                                                    <i class="fas fa-heart fa-lg"></i>
                                                </button>
                                            </form>
                                        </div>

                                        <div class="mb-3">
                                            <div class="rating mb-1">
                                                <?php
                                                $rating = round($favorite['avg_rating'] * 2) / 2;
                                                for($i = 1; $i <= 5; $i++) {
                                                    if($i <= floor($rating)) {
                                                        echo '<i class="fas fa-star"></i>';
                                                    } elseif($i - $rating > 0 && $i - $rating < 1) {
                                                        echo '<i class="fas fa-star-half-alt"></i>';
                                                    } else {
                                                        echo '<i class="far fa-star"></i>';
                                                    }
                                                }
                                                ?>
                                                <span class="ms-1 text-muted">(<?php echo $favorite['review_count']; ?>)</span>
                                            </div>
                                            <h6 class="text-primary mb-3">$<?php echo number_format($favorite['hourly_rate'], 2); ?>/hr</h6>
                                        </div>

                                        <div class="mb-3">
                                            <?php
                                            $specialties = explode(',', $favorite['specialties']);
                                            foreach(array_slice($specialties, 0, 3) as $specialty):
                                            ?>
                                                <span class="specialty-badge"><?php echo htmlspecialchars(trim($specialty)); ?></span>
                                            <?php endforeach; ?>
                                            <?php if(count($specialties) > 3): ?>
                                                <span class="specialty-badge">+<?php echo count($specialties) - 3; ?> more</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="favorite-stats">
                                            <div class="row text-center g-0">
                                                <div class="col-6 border-end">
                                                    <h6 class="mb-1"><?php echo $favorite['booking_count']; ?></h6>
                                                    <small class="text-muted">Bookings</small>
                                                </div>
                                                <div class="col-6">
                                                    <h6 class="mb-1"><?php echo date('M Y', strtotime($favorite['favorited_at'])); ?></h6>
                                                    <small class="text-muted">Favorited</small>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mt-3">
                                            <a href="booking-create.php?provider=<?php echo $favorite['provider_id']; ?>" class="btn btn-primary w-100">
                                                <i class="fas fa-calendar-plus me-2"></i>Book Now
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="far fa-heart mb-3"></i>
                        <h4>No favorites yet</h4>
                        <p class="text-muted mb-4">Start adding technicians to your favorites list for quick access</p>
                        <a href="../marketplace.php" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Browse Technicians
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Include the same footer as dashboard.php -->
    <?php include 'footer.php'; ?>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
    </script>
</body>
</html>