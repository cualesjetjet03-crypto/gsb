<?php
session_start();
include 'db_connect.php';

// Check if the user is logged in
$is_logged_in = isset($_SESSION['user_id']);

// Helper: mask a full name GCash-style — keep first letter, replace rest with *
// e.g. "Caryl Alfonso" → "C**** A******"
function maskName($name) {
    $words = explode(' ', trim($name));
    $masked = array_map(function($word) {
        if (strlen($word) <= 1) return $word;
        return substr($word, 0, 1) . str_repeat('*', strlen($word) - 1);
    }, $words);
    return implode(' ', $masked);
}

// Helper: fix review image path for use as a browser URL from the customer/ context
// DB stores: ../images/ratings/filename.jpg  → serve as: images/ratings/filename.jpg
function reviewImgUrl($rawPath) {
    if (empty($rawPath)) return '';
    // Strip leading ../ so it's relative to the site root from customer/
    $path = ltrim($rawPath, './');
    $path = ltrim($path, '/');
    // Remove any leading 'images/' duplication just in case
    return htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
}

// Fetch ALL published reviews (is_approved = 1) — no star rating filter, no arbitrary limit
$reviews_query = "SELECT customer_name, comment, tech_rating, gsb_rating, created_at, image_1, image_2, image_3, video_url 
                  FROM technician_ratings 
                  WHERE comment IS NOT NULL AND comment != '' AND is_approved = 1
                  ORDER BY created_at DESC";
$reviews_result = $conn->query($reviews_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us | GSB Airconditioning Services</title>
    <link rel="icon" type="image/png" href="favicon.png">
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="css/about.css">
    <link rel="stylesheet" href="css/navbar.css?v=2">
    <link rel="stylesheet" href="css/footer.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

    <!-- =========================
         NAVBAR
    ========================= -->
    <?php include 'navbar.php'; ?>

    <!-- =========================
         ABOUT US INTRO
    ========================= -->
    <section class="about-intro">
        <div class="about-intro-text">
            <span class="section-label">ABOUT US</span>
            <h1>Practical cooling support<br>for homes and businesses.</h1>
            <p>
                GSB Airconditioning Services brings together reliable air-conditioning
                products, professional installation, and dependable service support
                to help keep your home or business comfortable.
            </p>
        </div>

        <div class="about-feature">
            <div class="feature-icon">✓</div>
            <div>
                <h3>Focused on dependable service</h3>
                <p>
                    Whether you need a new installation, replacement, or repair,
                    our team provides practical solutions designed to keep your
                    cooling system working reliably.
                </p>
            </div>
        </div>
    </section>

    <!-- =========================
         PRODUCTS SECTION
    ========================= -->
    <section class="about-section products-section">
        <div class="section-content">
            <span class="section-label">PRODUCTS</span>
            <h2>Quality AC units for everyday comfort.</h2>
            <p>
                Explore a focused selection of air-conditioning systems designed
                to support comfortable residential and business spaces.
            </p>
        </div>

        <div class="section-image">
            <img src="/customer/images/Cleaning.png" alt="Air conditioning unit installed indoors" loading="lazy">
        </div>
    </section>

    <!-- =========================
         SERVICES SECTION
    ========================= -->
    <section class="about-section services-section">
        <div class="section-image service-image">
            <img src="/customer/images/pic1.png" alt="Air conditioning technician providing service" loading="lazy">
        </div>

        <div class="section-content service-content">
            <span class="section-label">SERVICES</span>
            <h2>Air-conditioning care from installation to maintenance.</h2>
            <p>
                Our service offering covers installation, cleaning, repair,
                and maintenance to help keep your air-conditioning system
                operating reliably and efficiently.
            </p>
        </div>
    </section>

    <!-- =========================
         CUSTOMER REVIEWS SECTION
    ========================= -->
    <section class="reviews-section">
        <div class="reviews-container">
            <div class="reviews-header">
                <span class="section-label">CUSTOMER FEEDBACK</span>
                <h2>What our clients say about us.</h2>
                <p class="reviews-subtitle">Real experiences from the homes and businesses we've served.</p>
            </div>

            <?php
            // Collect into array so we can count, calculate stats, and output JSON for JS
            $reviews_all = [];
            if ($reviews_result && $reviews_result->num_rows > 0) {
                while ($rev = $reviews_result->fetch_assoc()) $reviews_all[] = $rev;
            }
            $rev_total = count($reviews_all);

            // Calculate per-star counts and average
            $star_counts = [5=>0, 4=>0, 3=>0, 2=>0, 1=>0];
            $rating_sum  = 0;
            foreach ($reviews_all as $r) {
                $s = (int)round((float)$r['tech_rating']);
                $s = max(1, min(5, $s));
                $star_counts[$s]++;
                $rating_sum += (float)$r['tech_rating'];
            }
            $avg_rating = $rev_total > 0 ? round($rating_sum / $rev_total, 1) : 0;
            ?>

            <?php if ($rev_total > 0): ?>

            <!-- ── Overall rating summary row ── -->
            <div class="rev-summary">
                <div class="rev-avg-block">
                    <span class="rev-avg-num"><?php echo $avg_rating; ?></span>
                    <div class="rev-avg-stars">
                        <?php
                        $full = floor($avg_rating);
                        $half = ($avg_rating - $full) >= 0.5 ? 1 : 0;
                        for ($i = 1; $i <= 5; $i++) {
                            if ($i <= $full)          echo '<span class="rs full">★</span>';
                            elseif ($half && $i == $full+1) echo '<span class="rs half">★</span>';
                            else                       echo '<span class="rs empty">☆</span>';
                        }
                        ?>
                    </div>
                    <span class="rev-avg-label"><?php echo $rev_total; ?> review<?php echo $rev_total !== 1 ? 's' : ''; ?></span>
                </div>

                <div class="rev-bars">
                    <?php foreach ([5,4,3,2,1] as $s): ?>
                    <div class="rev-bar-row">
                        <span class="rev-bar-label"><?php echo $s; ?> ★</span>
                        <div class="rev-bar-track">
                            <div class="rev-bar-fill" style="width:<?php echo $rev_total > 0 ? round(($star_counts[$s]/$rev_total)*100) : 0; ?>%"></div>
                        </div>
                        <span class="rev-bar-count"><?php echo $star_counts[$s]; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── Star filter tabs (Shopee-style) ── -->
            <div class="rev-filter-tabs" id="revFilterTabs">
                <button class="rev-tab active" data-filter="all">
                    All <span class="rev-tab-count">(<?php echo $rev_total; ?>)</span>
                </button>
                <?php foreach ([5,4,3,2,1] as $s): ?>
                    <?php if ($star_counts[$s] > 0): ?>
                    <button class="rev-tab" data-filter="<?php echo $s; ?>">
                        <?php echo $s; ?> Star <span class="rev-tab-count">(<?php echo $star_counts[$s]; ?>)</span>
                    </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- ── Review cards grid (filtered by JS) ── -->
            <div class="rev-cards-grid" id="revCardsGrid">
                <?php
                // Sort: show top 3 highest-rated first by default (JS will re-filter)
                usort($reviews_all, fn($a,$b) => $b['tech_rating'] <=> $a['tech_rating']);
                foreach ($reviews_all as $idx => $rev):
                    $star_val = (int)round((float)$rev['tech_rating']);
                ?>
                <div class="review-card" data-stars="<?php echo $star_val; ?>"
                     data-topthree="<?php echo $idx < 3 ? '1' : '0'; ?>">

                    <div class="review-stars">
                        <?php for ($i = 1; $i <= 5; $i++) echo $i <= $star_val ? '★' : '☆'; ?>
                        <span class="review-rating-num"><?php echo number_format((float)$rev['tech_rating'],1); ?>/5</span>
                    </div>

                    <p class="review-text">"<?php echo htmlspecialchars($rev['comment']); ?>"</p>

                    <?php if (!empty($rev['image_1']) || !empty($rev['image_2']) || !empty($rev['image_3'])): ?>
                    <div class="review-media"><div class="review-images">
                        <?php foreach (['image_1','image_2','image_3'] as $imgKey): ?>
                            <?php if (!empty($rev[$imgKey])): ?>
                                <img src="<?php echo reviewImgUrl($rev[$imgKey]); ?>" alt="Review photo" class="review-img" loading="lazy" onerror="this.style.display='none'">
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div></div>
                    <?php endif; ?>
                    <?php if (!empty($rev['video_url'])): ?>
                    <div class="review-media">
                        <video src="<?php echo reviewImgUrl($rev['video_url']); ?>" controls class="review-video"></video>
                    </div>
                    <?php endif; ?>

                    <div class="review-footer">
                        <div class="review-author-avatar">
                            <?php echo strtoupper(substr(htmlspecialchars($rev['customer_name']),0,1)); ?>
                        </div>
                        <div class="review-author-info">
                            <h4><?php echo htmlspecialchars(maskName($rev['customer_name'])); ?></h4>
                            <span class="review-date"><?php echo date('F d, Y', strtotime($rev['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div><!-- /.rev-cards-grid -->

            <!-- No-results message (shown by JS when filter has 0 matches) -->
            <p class="rev-empty-msg" id="revEmptyMsg" style="display:none;">
                No reviews for this rating yet.
            </p>

            <?php else: ?>
                <p style="color:#6b7280;font-size:14px;text-align:center;padding:30px 0;">
                    No reviews available at the moment.
                </p>
            <?php endif; ?>

        </div><!-- /.reviews-container -->
    </section>

    <!-- =========================
         MISSION / VISION
    ========================= -->
    <section class="values-section">
        <div class="value-box">
            <span class="section-label">OUR MISSION</span>
            <h2>Reliable cooling solutions for every space.</h2>
            <p>
                We aim to provide dependable air-conditioning products and
                services that improve comfort, efficiency, and everyday
                convenience for our customers.
            </p>
        </div>

        <div class="value-box">
            <span class="section-label">OUR VISION</span>
            <h2>Building trust through quality service.</h2>
            <p>
                We strive to continuously improve our products, services,
                and customer experience while becoming a trusted provider
                of air-conditioning solutions.
            </p>
        </div>
    </section>

    <!-- =========================
         FOOTER
    ========================= -->
    <?php include 'footer.php'; ?>

<script>
/* ── Reviews: star filter tabs ── */
(function () {
    const grid     = document.getElementById('revCardsGrid');
    const tabs     = document.getElementById('revFilterTabs');
    const emptyMsg = document.getElementById('revEmptyMsg');
    if (!grid || !tabs) return;

    const allCards = Array.from(grid.querySelectorAll('.review-card'));

    function applyFilter(filter) {
        let visible = 0;

        allCards.forEach(card => {
            const stars      = card.dataset.stars;
            const isTopThree = card.dataset.topthree === '1';

            let show = false;
            if (filter === 'all') {
                show = isTopThree; // default: only top 3
            } else {
                show = (stars === filter); // exact star match — show ALL of that rating
            }

            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        emptyMsg.style.display = visible === 0 ? 'block' : 'none';
    }

    // Tab click
    tabs.addEventListener('click', function (e) {
        const btn = e.target.closest('.rev-tab');
        if (!btn) return;

        tabs.querySelectorAll('.rev-tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');

        applyFilter(btn.dataset.filter);
    });

    // Init: show top 3 only (All tab active)
    applyFilter('all');
})();
</script>

</body>
</html>