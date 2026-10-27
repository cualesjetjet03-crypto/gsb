<?php
include 'db_connect.php';
session_start();

function clean($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

$user = null;
$user_stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");

if ($user_stmt) {
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_result && $user_result->num_rows > 0) {
        $user = $user_result->fetch_assoc();
    }
    $user_stmt->close();
}

if (!$user) {
    $user = [
        'fullname'   => 'Guest User',
        'email'      => '',
        'phone'      => 'N/A',
        'gender'     => 'N/A',
        'birthdate'  => null,
        'address'    => 'N/A',
        'created_at' => null,
        'profile_picture' => ''
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>My Profile - GSB Airconditioning Services</title>
    <link rel="icon" type="image/png" href="favicon.png">

    <link rel="stylesheet" href="css/navbar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/GSB-homepage.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/profile.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/footer.css?v=<?php echo time(); ?>">
    
    <!-- Font Awesome for Professional Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="profile-container">

    <!-- PROFILE HEADER -->
    <div class="profile-header">
        
        <!-- LEFT COLUMN: Identity & Action -->
        <div class="profile-identity">
            <div class="profile-image">
                <?php 
                    $display_pic = !empty($user['profile_picture']) ? $user['profile_picture'] : 'images/users.png';
                ?>
                <img src="<?php echo clean($display_pic); ?>" alt="Profile">
            </div>
            <h2><?php echo clean($user['fullname'] ?? ''); ?></h2>
            <a href="edit_profile.php" class="btn edit-profile-btn">Edit Profile</a>
        </div>

        <!-- RIGHT COLUMN: Large Details Panel -->
        <div class="profile-details-panel">
            <div class="details-wrapper">
                <h3 class="details-heading">Details</h3>
                <p><i class="fa-solid fa-envelope"></i> <?php echo clean($user['email'] ?? ''); ?></p>
                <p><i class="fa-solid fa-phone"></i> <?php echo clean($user['phone'] ?? 'N/A'); ?></p>
                <p><i class="fa-solid fa-user"></i> <?php echo clean($user['gender'] ?? 'N/A'); ?></p>
                <p><i class="fa-solid fa-calendar-day"></i> <?php 
                    if (!empty($user['birthdate'])) {
                        echo date("F j, Y", strtotime($user['birthdate']));
                    } else {
                        echo "N/A";
                    }
                ?></p>
                <p><i class="fa-solid fa-location-dot"></i> <?php
                    $addr = trim($user['address'] ?? 'N/A');
                    // Clean up any extra spaces around commas
                    $addr = preg_replace('/\s*,\s*/', ', ', $addr);
                    $addr = $addr ?: 'N/A';
                    echo clean($addr);
                ?></p>
                <p><i class="fa-solid fa-clock"></i> Member Since: <?php 
                    if (!empty($user['created_at'])) {
                        echo date("F Y", strtotime($user['created_at']));
                    } else {
                        echo "June 2024";
                    }
                ?></p>
            </div>
        </div>
        
    </div>

</div><!-- /.profile-container -->

<?php include 'footer.php'; ?>

</body>
</html>