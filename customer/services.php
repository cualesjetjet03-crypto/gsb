<?php
session_start();
include "db_connect.php";

$is_logged_in = isset($_SESSION['user_id']);

// Define the 4 services with their details
$services = [
    [
        'key'         => 'AC Installation',
        'title'       => 'AC Installation',
        'description' => 'Professional mounting & setup of your new air conditioning unit — done right the first time.',
        'details'     => [
            'Ceiling or wall bracket mounting',
            'Refrigerant line installation',
            'Electrical wiring & safety check',
            'Test run & handover',
        ],
        'image'       => './GSB homepage Image/services/AC Installation.png',
    ],
    [
        'key'         => 'AC Cleaning',
        'title'       => 'AC Cleaning',
        'description' => 'Deep clean of filters, coils & drain lines for peak efficiency and better air quality.',
        'details'     => [
            'Filter wash & dry',
            'Evaporator & condenser coil cleaning',
            'Drain pan & pipe flushing',
            'Performance check after cleaning',
        ],
        'image'       => './GSB homepage Image/services/AC Cleaning.png',
    ],
    [
        'key'         => 'AC Repair',
        'title'       => 'AC Repair',
        'description' => 'Expert diagnosis & repair for any aircon issue — from weak cooling to refrigerant leaks.',
        'details'     => [
            'Fault diagnosis & assessment',
            'Compressor & capacitor repair',
            'Refrigerant recharge',
            'PCB & thermostat repair',
        ],
        'image'       => './GSB homepage Image/services/AC Repair.png',
    ],
    [
        'key'         => 'AC Check Up',
        'title'       => 'AC Check Up',
        'description' => 'Full system inspection to catch problems early and keep your unit running smoothly all year.',
        'details'     => [
            'Refrigerant level check',
            'Electrical connections check',
            'Thermostat calibration',
            'Written inspection report',
        ],
        'image'       => './GSB homepage Image/services/AC Check Up.png',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Our Services — GSB Airconditioning Services</title>
    <link rel="stylesheet" href="css/GSB-Homepage.css">
    <link rel="stylesheet" href="css/navbar.css?v=2">
    <link rel="stylesheet" href="css/footer.css">
    <link rel="icon" type="image/png" href="favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
    /* ── Page shell ── */
    body { background: #f5f7fb; font-family: 'Poppins', sans-serif; }

    .services-page {
        max-width: 1200px;
        margin: 0 auto;
        padding: 60px 24px 80px;
    }

    .services-header {
        text-align: center;
        margin-bottom: 48px;
    }
    .services-header .eyebrow {
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 2px;
        text-transform: uppercase;
        color: #0ea5e9;
        margin-bottom: 10px;
        display: block;
    }
    .services-header h1 {
        font-size: 36px;
        font-weight: 800;
        color: #0f1f78;
        margin: 0 0 12px;
    }
    .services-header p {
        font-size: 16px;
        color: #64748b;
        max-width: 540px;
        margin: 0 auto;
        line-height: 1.6;
    }

    /* ── Accordion cards container ── */
    .svc-accordion {
        display: flex;
        flex-direction: row;
        gap: 12px;
        height: 480px;
    }

    /* ── Each panel ── */
    .svc-panel {
        position: relative;
        flex: 1;
        min-width: 0;
        border-radius: 22px;
        overflow: hidden;
        cursor: pointer;
        border: 3px solid transparent;
        box-shadow: 0 6px 28px rgba(10,20,80,0.15);
        transition: flex 0.65s cubic-bezier(0.4,0,0.2,1),
                    border-color 0.3s, box-shadow 0.3s;
    }
    .svc-panel:hover,
    .svc-panel.open {
        flex: 5;
        box-shadow: 0 22px 60px rgba(10,20,80,0.28);
    }
    .svc-panel.open { border-color: #0ea5e9; }

    /* ── Background gradient + image ── */
    .svc-bg {
        position: absolute;
        inset: 0;
        background: linear-gradient(160deg, #001b94 0%, #0047cc 100%);
        overflow: hidden;
        border-radius: inherit;
    }
    .svc-bg img {
        position: absolute;
        top: -12%; left: -12%;
        width: 124%; height: 124%;
        object-fit: contain;
        opacity: 0.18;
        animation: svcKenBurns 14s ease-in-out infinite alternate;
        will-change: transform;
        transition: opacity 0.5s;
    }
    .svc-panel:hover .svc-bg img,
    .svc-panel.open .svc-bg img {
        opacity: 0.32;
        animation-play-state: paused;
    }
    @keyframes svcKenBurns {
        0%   { transform: scale(1)    translate(0%, 0%); }
        50%  { transform: scale(1.10) translate(-3%, -3%); }
        100% { transform: scale(1)    translate(0%, 0%); }
    }
    .svc-panel:nth-child(1) .svc-bg img { animation-delay:  0s; animation-duration: 13s; }
    .svc-panel:nth-child(2) .svc-bg img { animation-delay: -4s; animation-duration: 15s; }
    .svc-panel:nth-child(3) .svc-bg img { animation-delay: -8s; animation-duration: 12s; }
    .svc-panel:nth-child(4) .svc-bg img { animation-delay:-11s; animation-duration: 16s; }

    /* ── Overlay ── */
    .svc-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(to bottom,
            rgba(0,15,90,0.20) 0%,
            rgba(0,15,90,0.62) 50%,
            rgba(0, 5,50,0.93) 100%);
        border-radius: inherit;
    }

    /* ── Separator line ── */
    .svc-panel::before {
        content: '';
        position: absolute;
        top: 10%; right: 0;
        width: 1px; height: 80%;
        background: rgba(255,255,255,0.14);
        z-index: 2;
        transition: opacity 0.3s;
    }
    .svc-panel:last-child::before,
    .svc-panel:hover::before,
    .svc-panel.open::before { opacity: 0; }

    /* ── Rotated collapsed label ── */
    .svc-label {
        position: absolute;
        top: 50%;
        left: 50%;
        bottom: auto;
        transform: translate(-50%, -50%) rotate(-90deg);
        white-space: nowrap;
        font-size: 15px;
        font-weight: 800;
        color: rgba(255,255,255,0.92);
        letter-spacing: 2px;
        text-transform: uppercase;
        pointer-events: none;
        z-index: 4;
        transition: opacity 0.25s;
        text-shadow: 0 1px 4px rgba(0,0,0,0.4);
    }
    .svc-panel:hover .svc-label,
    .svc-panel.open .svc-label { opacity: 0; }

    /* ── Expanded content ── */
    .svc-content {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 0 28px 32px;
        text-align: center;
        color: #fff;
        opacity: 0;
        transition: opacity 0.35s ease 0.18s;
        z-index: 3;
    }
    .svc-panel:hover .svc-content,
    .svc-panel.open .svc-content { opacity: 1; }

    /* icon circle */
    .svc-icon {
        width: 86px; height: 86px;
        border-radius: 50%;
        background: rgba(255,255,255,0.12);
        border: 2px solid rgba(255,255,255,0.28);
        backdrop-filter: blur(8px);
        display: flex; align-items: center; justify-content: center;
        margin-bottom: 18px;
        flex-shrink: 0;
        transition: background 0.35s, border-color 0.35s, transform 0.45s;
    }
    .svc-icon img {
        width: 50px; height: 50px;
        object-fit: contain;
    }
    .svc-panel:hover .svc-icon,
    .svc-panel.open .svc-icon {
        background: rgba(14,165,233,0.38);
        border-color: #38bdf8;
        transform: scale(1.08);
    }

    /* title */
    .svc-content h2 {
        margin: 0 0 8px;
        font-size: 20px; font-weight: 800;
        color: #fff;
    }

    /* description */
    .svc-content .svc-desc {
        font-size: 13px;
        color: rgba(255,255,255,0.78);
        line-height: 1.55;
        max-width: 240px;
        margin-bottom: 12px;
        /* reveal */
        max-height: 0; opacity: 0; overflow: hidden;
        transition: max-height 0.5s cubic-bezier(0.4,0,0.2,1),
                    opacity 0.4s ease 0.15s, margin 0.4s;
    }
    .svc-panel:hover .svc-desc,
    .svc-panel.open .svc-desc {
        max-height: 80px; opacity: 1; margin-bottom: 12px;
    }

    /* detail bullets — only visible on .open (clicked) */
    .svc-details {
        list-style: none; padding: 0; margin: 0 0 18px;
        text-align: left;
        max-height: 0; opacity: 0; overflow: hidden;
        transition: max-height 0.5s cubic-bezier(0.4,0,0.2,1),
                    opacity 0.4s ease 0.2s, margin 0.4s;
    }
    .svc-panel.open .svc-details {
        max-height: 140px; opacity: 1; margin-bottom: 18px;
    }
    .svc-details li {
        font-size: 12px;
        color: rgba(255,255,255,0.75);
        padding: 3px 0;
        display: flex; align-items: center; gap: 6px;
    }
    .svc-details li::before {
        content: '✓';
        color: #38bdf8;
        font-weight: 700;
        flex-shrink: 0;
    }

    /* ── Click hint — shown on hover, hidden when card is open ── */
    .svc-click-hint {
        display: flex;
        align-items: center;
        gap: 7px;
        font-size: 12px;
        font-weight: 600;
        color: rgba(255,255,255,0.70);
        letter-spacing: 0.5px;
        margin-top: 8px;
        opacity: 0;
        max-height: 0;
        overflow: hidden;
        transition: opacity 0.3s ease 0.25s, max-height 0.3s ease 0.25s;
        pointer-events: none;
    }
    .svc-click-hint .hint-dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        background: #38bdf8;
        flex-shrink: 0;
        animation: hintPulse 1.4s ease-in-out infinite;
    }
    @keyframes hintPulse {
        0%, 100% { transform: scale(1);   opacity: 1; }
        50%       { transform: scale(1.5); opacity: 0.5; }
    }
    /* Show hint when hovered but NOT yet open */
    .svc-panel:hover:not(.open) .svc-click-hint {
        opacity: 1;
        max-height: 30px;
    }

    /* Book button */
    .svc-book-btn {
        display: inline-block;
        background: #0ea5e9;
        color: #fff;
        font-size: 13px; font-weight: 700;
        padding: 10px 26px;
        border-radius: 30px;
        text-decoration: none;
        border: none; cursor: pointer;
        transition: background 0.2s, transform 0.15s;
        box-shadow: 0 4px 14px rgba(14,165,233,0.4);
        max-height: 0; opacity: 0; overflow: hidden;
        transition: max-height 0.5s, opacity 0.4s ease 0.25s,
                    background 0.2s, transform 0.15s;
        pointer-events: none;
    }
    .svc-panel.open .svc-book-btn {
        max-height: 60px; opacity: 1;
        pointer-events: auto;
    }
    .svc-book-btn:hover {
        background: #0284c7;
        transform: translateY(-2px);
    }

    /* checkmark when open */
    .svc-panel.open::after {
        content: '\2713';
        position: absolute;
        top: 14px; right: 14px;
        width: 28px; height: 28px;
        background: #0ea5e9; color: #fff;
        border-radius: 50%;
        font-size: 14px; font-weight: 700;
        line-height: 28px; text-align: center;
        box-shadow: 0 2px 8px rgba(14,165,233,0.55);
        z-index: 5;
    }

    /* ── Guest notice ── */
    .guest-notice {
        text-align: center;
        margin-top: 36px;
        padding: 18px 24px;
        background: #fff;
        border: 1px solid #e0eaff;
        border-radius: 14px;
        font-size: 14px;
        color: #475569;
        box-shadow: 0 2px 12px rgba(15,31,120,0.06);
    }
    .guest-notice strong { color: #0f1f78; }
    .guest-notice a {
        color: #0ea5e9;
        font-weight: 700;
        text-decoration: none;
    }
    .guest-notice a:hover { text-decoration: underline; }

    /* ── Responsive ── */
    @media (max-width: 820px) {
        .svc-accordion {
            flex-direction: column;
            height: auto; gap: 10px;
        }
        .svc-panel {
            flex: none !important;
            height: 80px;
            border-radius: 14px;
        }
        .svc-panel:hover,
        .svc-panel.open {
            height: 300px;
            flex: none !important;
        }
        .svc-label { display: none; }
        .svc-content {
            opacity: 1;
            flex-direction: row;
            justify-content: center;
            gap: 14px;
            padding: 0 20px;
        }
        .svc-panel:hover .svc-content,
        .svc-panel.open .svc-content {
            flex-direction: column;
            justify-content: center;
            padding: 0 24px 28px;
        }
        .svc-icon { width: 44px; height: 44px; margin-bottom: 0; }
        .svc-icon img { width: 26px; height: 26px; }
        .svc-content h2 { font-size: 15px; margin: 0; }
        .svc-desc, .svc-details, .svc-book-btn, .svc-click-hint { display: none; }
        .svc-panel:hover .svc-desc,
        .svc-panel.open .svc-desc { display: block; max-height: 80px; opacity: 1; }
        .svc-panel.open .svc-book-btn { display: inline-block; max-height: 60px; opacity: 1; pointer-events: auto; }
        .svc-panel:hover .svc-icon,
        .svc-panel.open .svc-icon { width: 72px; height: 72px; margin-bottom: 12px; }
        .svc-panel:hover .svc-icon img,
        .svc-panel.open .svc-icon img { width: 40px; height: 40px; }
        .services-header h1 { font-size: 26px; }
    }

    @media (max-width: 480px) {
        .svc-panel { height: 70px; }
        .svc-panel:hover, .svc-panel.open { height: 260px; }
        .services-page { padding: 40px 16px 60px; }
    }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="services-page">

    <div class="services-header">
        <span class="eyebrow">What we offer</span>
        <h1>Our Services</h1>
        <p>Professional air conditioning services by trained GSB technicians. Hover a card to learn more — click to book.</p>
    </div>

    <!-- ── Accordion cards ── -->
    <div class="svc-accordion" id="svcAccordion">
        <?php foreach ($services as $idx => $svc): ?>
        <div class="svc-panel" data-service="<?= htmlspecialchars($svc['key']) ?>">

            <!-- Background -->
            <div class="svc-bg">
                <img src="<?= htmlspecialchars($svc['image']) ?>" alt="<?= htmlspecialchars($svc['title']) ?>">
            </div>
            <div class="svc-overlay"></div>

            <!-- Collapsed label -->
            <span class="svc-label"><?= htmlspecialchars($svc['title']) ?></span>

            <!-- Expanded content -->
            <div class="svc-content">
                <div class="svc-icon">
                    <img src="./GSB homepage Image/GSB Logo.png" alt="<?= htmlspecialchars($svc['title']) ?>">
                </div>

                <h2><?= htmlspecialchars($svc['title']) ?></h2>

                <p class="svc-desc"><?= htmlspecialchars($svc['description']) ?></p>

                <!-- Click-to-reveal hint — fades in on hover, gone once card is open -->
                <span class="svc-click-hint">
                    <span class="hint-dot"></span>
                    Click to see details &amp; book
                </span>

                <ul class="svc-details">
                    <?php foreach ($svc['details'] as $detail): ?>
                    <li><?= htmlspecialchars($detail) ?></li>
                    <?php endforeach; ?>
                </ul>

                <?php
                // If logged in → go directly to book_service with service pre-selected
                // If guest → open auth modal (JS handles it)
                if ($is_logged_in): ?>
                <a href="book_service.php?service=<?= urlencode($svc['key']) ?>" class="svc-book-btn">
                    Book Now →
                </a>
                <?php else: ?>
                <button class="svc-book-btn svc-book-guest"
                        data-service="<?= htmlspecialchars($svc['key']) ?>"
                        onclick="openSvcAuthModal(this)">
                    Login to Book →
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Guest notice ── -->
    <?php if (!$is_logged_in): ?>
    <div class="guest-notice">
        <strong>Want to book a service?</strong>
        <a href="login.php?redirect=<?= urlencode('book_service.php') ?>">Sign in</a> or <a href="register.php?redirect=<?= urlencode('book_service.php') ?>">create a free account</a> to schedule a visit from our technicians.
    </div>
    <?php endif; ?>

</div>

<?php include 'footer.php'; ?>

<!-- AUTHENTICATION REQUIRED MODAL -->
<?php if (!$is_logged_in): ?>
<div id="svcAuthModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:99999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:16px; padding:40px 32px; max-width:420px; width:90%; text-align:center; position:relative; box-shadow:0 20px 60px rgba(0,0,0,0.25);">
        <span onclick="closeSvcAuthModal()" style="position:absolute; top:14px; right:20px; font-size:22px; cursor:pointer; color:#94a3b8; line-height:1;">×</span>
        <h3 style="color:#0020C2; font-size:22px; font-weight:800; margin:0 0 12px;">Authentication Required</h3>
        <p style="color:#475569; font-size:15px; margin:0 0 28px;">Please log in or register to continue with your request.</p>
        <div style="display:flex; gap:12px; justify-content:center;">
            <button id="svcAuthLoginBtn" style="flex:1; padding:14px 0; background:#0020C2; color:#fff; border:none; border-radius:8px; font-weight:700; font-size:15px; cursor:pointer;">Log In</button>
            <button id="svcAuthRegisterBtn" style="flex:1; padding:14px 0; background:#f1f5f9; color:#111827; border:1px solid #e2e8f0; border-radius:8px; font-weight:700; font-size:15px; cursor:pointer;">Register</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Click a panel to lock it open (shows bullet details + Book button)
document.querySelectorAll('.svc-panel').forEach(function(panel) {
    panel.addEventListener('click', function(e) {
        // Don't toggle if clicking the book button itself
        if (e.target.closest('.svc-book-btn')) return;
        var isOpen = this.classList.contains('open');
        // Close all
        document.querySelectorAll('.svc-panel').forEach(function(p) {
            p.classList.remove('open');
        });
        // Toggle clicked one
        if (!isOpen) this.classList.add('open');
    });
});

<?php if (!$is_logged_in): ?>
function openSvcAuthModal(btn) {
    var service = btn.getAttribute('data-service');
    var redirectUrl = 'book_service.php?service=' + encodeURIComponent(service);
    var encoded = encodeURIComponent(redirectUrl);
    document.getElementById('svcAuthLoginBtn').onclick    = function() { window.location.href = 'login.php?redirect='    + encoded; };
    document.getElementById('svcAuthRegisterBtn').onclick = function() { window.location.href = 'register.php?redirect=' + encoded; };
    document.getElementById('svcAuthModal').style.display = 'flex';
}
function closeSvcAuthModal() {
    document.getElementById('svcAuthModal').style.display = 'none';
}
// Close on backdrop click
document.getElementById('svcAuthModal').addEventListener('click', function(e) {
    if (e.target === this) closeSvcAuthModal();
});
<?php endif; ?>
</script>

</body>
</html>
