<?php
session_start();
include "db_connect.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

/* =========================
   GET CUSTOMER DETAILS
========================= */
$user_stmt = $conn->prepare("SELECT email, fullname FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_res = $user_stmt->get_result();
$user_data = $user_res->fetch_assoc();

$user_email = trim($user_data['email'] ?? '');
$user_fullname = trim($user_data['fullname'] ?? '');
$user_stmt->close();

$history_items = [];

/* =========================
   1. FETCH COMPLETED AND CANCELLED INQUIRIES (PRODUCT ORDERS)
========================= */
$stmt1 = $conn->prepare("
    SELECT i.id, 
           COALESCE(i.product_name, 'Product Order') AS title, 
           i.date_submitted AS item_date, 
           i.status, 
           t.id AS technician_id, 
           t.technician_name,
           (SELECT COUNT(*) FROM technician_ratings tr WHERE tr.inquiry_id = i.id) AS is_rated
    FROM inquiries i
    LEFT JOIN services_schedule ss ON ss.inquiry_id = i.id
    LEFT JOIN technicians t ON t.id = COALESCE(ss.technician_id, i.schedule_technician_id, i.technician_id)
    WHERE (i.user_id = ? OR (i.email IS NOT NULL AND i.email != '' AND i.email = ?))
      AND LOWER(i.status) IN ('completed', 'cancelled', 'sold')
");
if ($stmt1) {
    $stmt1->bind_param("is", $user_id, $user_email);
    $stmt1->execute();
    $res1 = $stmt1->get_result();
    while ($row = $res1->fetch_assoc()) {
        $history_items[] = [
            'id'              => $row['id'],
            'title'           => $row['title'],
            'date'            => $row['item_date'],
            'status'          => $row['status'],
            'technician_id'   => $row['technician_id'],
            'technician_name' => $row['technician_name'],
            'is_rated'        => $row['is_rated'],
            'timestamp'       => !empty($row['item_date']) ? strtotime($row['item_date']) : 0
        ];
    }
    $stmt1->close();
}

/* =========================
   2. FETCH COMPLETED AND CANCELLED DIRECT SERVICES
========================= */
$stmt2 = $conn->prepare("
    SELECT ss.id, 
           COALESCE(ss.service_type, 'Direct Service') AS title, 
           ss.service_date AS item_date, 
           ss.status, 
           t.id AS technician_id, 
           t.technician_name,
           (SELECT COUNT(*) FROM technician_ratings tr WHERE tr.inquiry_id = ss.id) AS is_rated
    FROM services_schedule ss
    LEFT JOIN technicians t ON t.id = ss.technician_id
    WHERE (ss.user_id = ? OR (ss.customer_name IS NOT NULL AND ss.customer_name != '' AND ss.customer_name = ?))
      AND (ss.inquiry_id IS NULL OR ss.inquiry_id = 0)
      AND LOWER(ss.status) IN ('completed', 'cancelled', 'sold')
");
if ($stmt2) {
    $stmt2->bind_param("is", $user_id, $user_fullname);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($row = $res2->fetch_assoc()) {
        $history_items[] = [
            'id'              => $row['id'],
            'title'           => $row['title'],
            'date'            => $row['item_date'],
            'status'          => $row['status'],
            'technician_id'   => $row['technician_id'],
            'technician_name' => $row['technician_name'],
            'is_rated'        => $row['is_rated'],
            'timestamp'       => !empty($row['item_date']) ? strtotime($row['item_date']) : 0
        ];
    }
    $stmt2->close();
}

/* =========================
   3. SORT BY DATE (NEWEST FIRST)
========================= */
usort($history_items, function($a, $b) {
    return $b['timestamp'] <=> $a['timestamp'];
});

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order History | GSB Airconditioning</title>
    <link rel="icon" type="image/png" href="favicon.png">
    
    <!-- External CSS Links -->
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/footer.css">
    <link rel="stylesheet" href="css/order_history.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>

    <?php include 'navbar.php'; ?>

    <div class="history-container">
        
        <?php if(isset($_GET['success'])): ?>
            <div class="alert-success">Thank you! Your feedback has been submitted successfully.</div>
        <?php endif; ?>

        <div class="history-header">
            <a href="profile.php" class="back-btn">&larr; Back to Profile</a>
            <h2>Order & Service History</h2>
        </div>

        <?php if (count($history_items) > 0): ?>
            <?php foreach ($history_items as $row): ?>
                <?php 
                    $status_lower = strtolower(trim($row['status']));
                    $is_completed = in_array($status_lower, ['completed', 'sold']);
                    $badge_class = $is_completed ? 'badge-completed' : 'badge-cancelled';
                    $status_display = ucfirst($status_lower);
                ?>
                <div class="history-card">
                    <div class="history-details">
                        <h3><?php echo htmlspecialchars($row['title']); ?></h3>
                        <p>Request #<?php echo $row['id']; ?> &bull; <?php echo htmlspecialchars($status_display); ?> on <?php echo !empty($row['date']) ? date("F d, Y", strtotime($row['date'])) : 'N/A'; ?></p>
                        <p><strong>Technician:</strong> <?php echo htmlspecialchars($row['technician_name'] ?? 'Not Specified'); ?></p>
                    </div>
                    <div class="history-actions">
                        <div class="status-badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($status_display); ?></div>
                        
                        <?php if ($is_completed): ?>
                            <?php if ($row['is_rated'] > 0): ?>
                                <span class="rated-text"><i class="fa-solid fa-star"></i> Reviewed</span>
                            <?php else: ?>
                                <button class="rate-btn" onclick="openRatingModal(<?php echo $row['id']; ?>, <?php echo $row['technician_id'] ?? 0; ?>, '<?php echo addslashes($row['technician_name'] ?? 'GSB Technician'); ?>')">Rate Service</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fa-solid fa-box-open empty-state-icon"></i>
                <p>You have no completed, sold, or cancelled orders yet.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Rating Modal -->
    <div id="ratingModal" class="modal-overlay">
        <div class="modal-content">
            <span class="close-btn" onclick="closeRatingModal()">&times;</span>
            <h3>Rate Your Experience</h3>
            
            <form action="submit_rating.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="inquiry_id" id="modalInquiryId">
                <input type="hidden" name="technician_id" id="modalTechnicianId">
                
                <!-- Technician Rating -->
                <div class="rating-section">
                    <div class="rating-label" id="modalTechName">Technician</div>
                    <div class="star-rating tech-stars">
                        <input type="radio" id="t_star5" name="tech_rating" value="5" required />
                        <label for="t_star5" title="5 stars"><i class="fa-solid fa-star"></i></label>
                        <input type="radio" id="t_star4" name="tech_rating" value="4" />
                        <label for="t_star4" title="4 stars"><i class="fa-solid fa-star"></i></label>
                        <input type="radio" id="t_star3" name="tech_rating" value="3" />
                        <label for="t_star3" title="3 stars"><i class="fa-solid fa-star"></i></label>
                        <input type="radio" id="t_star2" name="tech_rating" value="2" />
                        <label for="t_star2" title="2 stars"><i class="fa-solid fa-star"></i></label>
                        <input type="radio" id="t_star1" name="tech_rating" value="1" />
                        <label for="t_star1" title="1 star"><i class="fa-solid fa-star"></i></label>
                    </div>
                </div>

                <!-- GSB Rating -->
                <div class="rating-section">
                    <div class="rating-label">Overall GSB Service</div>
                    <div class="star-rating gsb-stars">
                        <input type="radio" id="g_star5" name="gsb_rating" value="5" required />
                        <label for="g_star5" title="5 stars"><i class="fa-solid fa-star"></i></label>
                        <input type="radio" id="g_star4" name="gsb_rating" value="4" />
                        <label for="g_star4" title="4 stars"><i class="fa-solid fa-star"></i></label>
                        <input type="radio" id="g_star3" name="gsb_rating" value="3" />
                        <label for="g_star3" title="3 stars"><i class="fa-solid fa-star"></i></label>
                        <input type="radio" id="g_star2" name="gsb_rating" value="2" />
                        <label for="g_star2" title="2 stars"><i class="fa-solid fa-star"></i></label>
                        <input type="radio" id="g_star1" name="gsb_rating" value="1" />
                        <label for="g_star1" title="1 star"><i class="fa-solid fa-star"></i></label>
                    </div>
                </div>

                <!-- Media Upload Section -->
                <div class="rating-section upload-section">
                    <label for="rating_images" class="upload-label">Upload Pictures (Max 3, Optional)</label>
                    <input type="file" id="rating_images" name="rating_images[]" accept="image/*" multiple onchange="validateImageCount(this)" class="upload-input">
                    <small id="imageErrorMsg" class="error-msg">You can only select up to 3 images.</small>
                </div>

                <div class="rating-section upload-section">
                    <label for="rating_video" class="upload-label">Upload Video (Max 1, Optional)</label>
                    <input type="file" id="rating_video" name="rating_video" accept="video/*" class="upload-input">
                </div>

                <textarea name="comment" rows="3" placeholder="Leave a comment about the service, technician, or GSB overall..."></textarea>
                
                <button type="submit" class="submit-rating-btn" id="submitBtn">Submit Feedback</button>
            </form>
        </div>
    </div>

    <!-- Include the new global footer -->
    <?php include 'footer.php'; ?>

    <script>
        function openRatingModal(inquiryId, technicianId, technicianName) {
            document.getElementById('modalInquiryId').value = inquiryId;
            document.getElementById('modalTechnicianId').value = technicianId;
            
            document.getElementById('modalTechName').textContent = "Technician: " + technicianName;
            
            document.getElementById('ratingModal').style.display = 'flex';
        }

        function closeRatingModal() {
            document.getElementById('ratingModal').style.display = 'none';
            // Clear inputs and reset errors upon close
            document.getElementById('rating_images').value = '';
            document.getElementById('rating_video').value = '';
            document.getElementById('imageErrorMsg').style.display = 'none';
            document.getElementById('submitBtn').disabled = false;
        }

        window.onclick = function(event) {
            var modal = document.getElementById('ratingModal');
            if (event.target == modal) {
                closeRatingModal();
            }
        }

        function validateImageCount(input) {
            const errorMsg = document.getElementById('imageErrorMsg');
            const submitBtn = document.getElementById('submitBtn');
            
            if (input.files.length > 3) {
                errorMsg.style.display = 'block';
                submitBtn.disabled = true;
            } else {
                errorMsg.style.display = 'none';
                submitBtn.disabled = false;
            }
        }

        /* =========================================
           FRONTEND BAD WORD REPLACEMENT (JAVASCRIPT)
        ========================================= */
        document.querySelector('form[action="submit_rating.php"]').addEventListener('submit', function(e) {
            const commentField = document.querySelector('textarea[name="comment"]');
            let comment = commentField.value;
            
            // English & Tagalog Profanity List
            const badWords = ['fuck', 'shit', 'bitch', 'asshole', 'cunt', 'dick', 'pussy', 'bastard', 'slut', 'whore', 'motherfucker', 'putangina', 'tangina', 'gago', 'gaga', 'tarantado', 'bobo', 'puta', 'puki', 'burat', 'titi', 'kantot', 'ulol', 'punyeta', 'hindot', 'pucha', 'leche'];
            
            // Replace any matched bad words with *** instantly on the frontend
            badWords.forEach(word => {
                // 'gi' means global (replace all occurrences) and case-insensitive
                const regex = new RegExp('\\b' + word + '\\b', 'gi');
                comment = comment.replace(regex, '***');
            });

            // Update the textarea with the censored text just before it submits
            commentField.value = comment;
        });
    </script>
</body>
</html>