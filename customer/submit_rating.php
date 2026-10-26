<?php
session_start();
include "db_connect.php";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['user_id'])) {
    $inquiry_id = (int)$_POST['inquiry_id'];
    $technician_id = (int)$_POST['technician_id'];
    
    // If technician_id is 0 (Not Specified), set it to NULL for the database
    $db_tech_id = ($technician_id > 0) ? $technician_id : null;
    
    // Capture both ratings
    $tech_rating = (float)$_POST['tech_rating'];
    $gsb_rating = (float)$_POST['gsb_rating'];
    
    /* =========================
       BAD WORD FILTER (Option A)
    ========================= */
    // 1. Capture the raw comment
    $raw_comment = trim($_POST['comment'] ?? '');
    
    // 2. Define the words you want to block
    $bad_words = array('fuck', 'shit', 'bitch', 'asshole', 'cunt', 'dick', 'pussy', 'bastard', 'slut', 'whore',
                       'motherfucker', 'putangina', 'tangina', 'gago', 'gaga', 'tarantado', 'bobo', 'puta', 'puki',
                       'burat', 'titi', 'kantot', 'ulol', 'punyeta', 'hindot', 'pucha', 'leche'); 
    
    // 3. Replace bad words with *** (case-insensitive)
    $filtered_comment = str_ireplace($bad_words, '***', $raw_comment);
    
    // 4. Secure the filtered comment before saving to the database
    $comment = htmlspecialchars($filtered_comment, ENT_QUOTES, 'UTF-8');
    /* ========================= */
    
    
    // Fetch the customer's name
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT fullname FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_res = $stmt->get_result();
    $customer_name = ($user_res && $user_res->num_rows > 0) ? $user_res->fetch_assoc()['fullname'] : 'Verified Customer';
    $stmt->close();
    
    // Convert 5-star scales to percentages
    $tech_percentage = ($tech_rating / 5) * 100;
    $gsb_percentage = ($gsb_rating / 5) * 100;

    /* =========================
       FILE UPLOAD HANDLING
    ========================= */
    // Saved inside root GSB-System/images/ratings/
    $upload_dir = '../images/ratings/';
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $image_1 = null;
    $image_2 = null;
    $image_3 = null;
    $video_url = null;

    // 1. Process Images
    if (isset($_FILES['rating_images']) && !empty($_FILES['rating_images']['name'][0])) {
        $total_images = count($_FILES['rating_images']['name']);
        $limit = min($total_images, 3); // Enforce max 3 limit
        
        $uploaded_images = [];
        $allowed_img_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        for ($i = 0; $i < $limit; $i++) {
            if ($_FILES['rating_images']['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['rating_images']['tmp_name'][$i];
                $file_name = $_FILES['rating_images']['name'][$i];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if (in_array($file_ext, $allowed_img_exts)) {
                    $new_filename = uniqid('img_') . '_' . time() . '.' . $file_ext;
                    $destination = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($tmp_name, $destination)) {
                        $uploaded_images[] = $destination;
                    }
                }
            }
        }
        
        if (isset($uploaded_images[0])) $image_1 = $uploaded_images[0];
        if (isset($uploaded_images[1])) $image_2 = $uploaded_images[1];
        if (isset($uploaded_images[2])) $image_3 = $uploaded_images[2];
    }

    // 2. Process Video
    if (isset($_FILES['rating_video']) && $_FILES['rating_video']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['rating_video']['tmp_name'];
        $file_name = $_FILES['rating_video']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $allowed_vid_exts = ['mp4', 'avi', 'mov', 'webm', 'mkv'];
        
        if (in_array($file_ext, $allowed_vid_exts)) {
            $new_filename = uniqid('vid_') . '_' . time() . '.' . $file_ext;
            $destination = $upload_dir . $new_filename;
            
            if (move_uploaded_file($tmp_name, $destination)) {
                $video_url = $destination;
            }
        }
    }

    // Insert into the database
    $insert = $conn->prepare("INSERT INTO technician_ratings 
        (inquiry_id, technician_id, customer_name, tech_rating, tech_percentage, gsb_rating, gsb_percentage, comment, image_1, image_2, image_3, video_url) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Notice the second parameter is now 'db_tech_id'
    $insert->bind_param("iisddddsssss", $inquiry_id, $db_tech_id, $customer_name, $tech_rating, $tech_percentage, $gsb_rating, $gsb_percentage, $comment, $image_1, $image_2, $image_3, $video_url);
    
    if ($insert->execute()) {
        header("Location: order_history.php?success=1");
    } else {
        // This will print the exact database error to your screen if it fails again
        die("Database Insert Failed: " . $insert->error);
    }
    
    $insert->close();
    $conn->close();
    exit();
    
} else {
    header("Location: order_history.php");
    exit();
}
?>