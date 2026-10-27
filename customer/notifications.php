<?php
session_start();

// Temporary: show errors to diagnose white page
ini_set('display_errors', '1');
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include 'db_connect.php';
$user_id = (int)$_SESSION['user_id'];

// ── Keep track of which were unread BEFORE marking them all read ──────────────
$unread_ids = [];
$unread_stmt = $conn->prepare("SELECT id FROM notifications WHERE user_id = ? AND is_read = 0");
if ($unread_stmt) {
    $unread_stmt->bind_param("i", $user_id);
    $unread_stmt->execute();
    $ur = $unread_stmt->get_result();
    while ($r = $ur->fetch_assoc()) $unread_ids[] = (int)$r['id'];
    $unread_stmt->close();
}

// ── Mark all as read ──────────────────────────────────────────────────────────
$mark = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
if ($mark) { $mark->bind_param("i", $user_id); $mark->execute(); $mark->close(); }

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page  = 10;
$page      = max(1, (int)($_GET['page'] ?? 1));

$cnt_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id = ?");
$cnt_stmt->bind_param("i", $user_id);
$cnt_stmt->execute();
$total_items = (int)$cnt_stmt->get_result()->fetch_assoc()['total'];
$cnt_stmt->close();

$total_pages = max(1, (int)ceil($total_items / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

// ── Fetch notifications ───────────────────────────────────────────────────────
$notifications = [];
$fetch = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
if ($fetch) {
    $fetch->bind_param("iii", $user_id, $per_page, $offset);
    $fetch->execute();
    $res = $fetch->get_result();
    while ($row = $res->fetch_assoc()) $notifications[] = $row;
    $fetch->close();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function notif_icon(string $title): string {
    $t = strtolower($title);
    if (str_contains($t, 'order') || str_contains($t, 'delivery') || str_contains($t, 'product'))
        return ['icon' => 'fa-box-open',      'color' => '#3b82f6', 'bg' => '#eff6ff', 'label' => 'Order'];
    if (str_contains($t, 'service') || str_contains($t, 'repair') || str_contains($t, 'install') || str_contains($t, 'clean'))
        return ['icon' => 'fa-wrench',         'color' => '#8b5cf6', 'bg' => '#f5f3ff', 'label' => 'Service'];
    if (str_contains($t, 'payment') || str_contains($t, 'paid') || str_contains($t, 'invoice'))
        return ['icon' => 'fa-credit-card',    'color' => '#16a34a', 'bg' => '#f0fdf4', 'label' => 'Payment'];
    if (str_contains($t, 'cancel') || str_contains($t, 'decline'))
        return ['icon' => 'fa-circle-xmark',   'color' => '#ef4444', 'bg' => '#fef2f2', 'label' => 'Cancelled'];
    if (str_contains($t, 'complet') || str_contains($t, 'done') || str_contains($t, 'success'))
        return ['icon' => 'fa-circle-check',   'color' => '#16a34a', 'bg' => '#f0fdf4', 'label' => 'Completed'];
    if (str_contains($t, 'approv') || str_contains($t, 'confirm'))
        return ['icon' => 'fa-thumbs-up',      'color' => '#0ea5e9', 'bg' => '#f0f9ff', 'label' => 'Approved'];
    if (str_contains($t, 'rate') || str_contains($t, 'review') || str_contains($t, 'feedback'))
        return ['icon' => 'fa-star',           'color' => '#f59e0b', 'bg' => '#fffbeb', 'label' => 'Feedback'];
    return          ['icon' => 'fa-bell',      'color' => '#64748b', 'bg' => '#f8fafc', 'label' => 'Update'];
}

function relative_time(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)       return 'Just now';
    if ($diff < 3600)     return (int)($diff/60)   . ' min ago';
    if ($diff < 86400)    return (int)($diff/3600)  . ' hr ago';
    if ($diff < 604800)   return (int)($diff/86400) . ' day' . ((int)($diff/86400)>1?'s':'') . ' ago';
    return date('M d, Y', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Notifications | GSB</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="css/navbar.css?v=2">
    <link rel="stylesheet" href="css/footer.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
    /* ── Base ── */
    *, *::before, *::after { box-sizing: border-box; }
    body {
        font-family: 'Poppins', sans-serif;
        background: #f4f7fc;
        margin: 0;
        padding-top: 100px;
        color: #111827;
    }

    /* ── Layout ── */
    .notif-wrapper {
        max-width: 860px;
        margin: 40px auto 60px;
        padding: 0 20px;
        min-height: 60vh;
    }

    /* ── Page header ── */
    .notif-page-header {
        display: flex;
        justify-content: center;
        align-items: center;
        position: relative;
        margin-bottom: 28px;
        min-height: 44px;
    }
    .notif-page-header h1 {
        margin: 0;
        font-size: 24px;
        font-weight: 700;
        color: #0f1f78;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .back-btn {
        position: absolute;
        left: 0;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 22px;
        border: 1.5px solid #0f1f78;
        border-radius: 50px;
        color: #0f1f78;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        background: transparent;
        transition: all 0.2s;
    }
    .back-btn:hover { background: #0f1f78; color: #fff; }

    /* ── Summary bar ── */
    .notif-summary {
        display: flex;
        align-items: center;
        gap: 18px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 14px 22px;
        margin-bottom: 20px;
        font-size: 13px;
        color: #64748b;
    }
    .notif-summary .sum-count {
        font-size: 22px;
        font-weight: 800;
        color: #0f1f78;
    }
    .notif-summary .sum-new {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #eff6ff;
        color: #2563eb;
        font-weight: 600;
        font-size: 12px;
        padding: 3px 10px;
        border-radius: 20px;
    }

    /* ── Card list ── */
    .notif-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-bottom: 28px;
    }

    /* ── Individual card ── */
    .notif-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 18px 20px;
        display: flex;
        align-items: flex-start;
        gap: 16px;
        transition: box-shadow 0.2s, border-color 0.2s;
        position: relative;
        border-left: 4px solid transparent;
    }
    .notif-card:hover {
        box-shadow: 0 4px 18px rgba(0,0,0,0.07);
        border-color: #d1d5db;
    }

    /* Unread card — left accent + subtle tint */
    .notif-card.is-unread {
        border-left-color: #2563eb;
        background: #fafbff;
    }

    /* Unread dot indicator (top-right corner) */
    .notif-card.is-unread::after {
        content: '';
        position: absolute;
        top: 14px;
        right: 16px;
        width: 9px;
        height: 9px;
        background: #2563eb;
        border-radius: 50%;
        box-shadow: 0 0 0 2px #fff;
    }

    /* ── Icon circle ── */
    .notif-icon-wrap {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 19px;
        flex-shrink: 0;
        margin-top: 1px;
    }

    /* ── Content ── */
    .notif-body { flex: 1; min-width: 0; }

    .notif-top-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 5px;
        flex-wrap: wrap;
    }
    .notif-title {
        font-size: 15px;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        line-height: 1.3;
    }
    .notif-card.is-unread .notif-title { color: #0f1f78; }

    /* Type pill badge */
    .notif-type-badge {
        font-size: 10px;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 20px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        flex-shrink: 0;
    }

    .notif-msg {
        font-size: 13.5px;
        color: #4b5563;
        line-height: 1.6;
        margin: 0 0 10px;
    }

    /* ── Meta row ── */
    .notif-meta {
        display: flex;
        align-items: center;
        gap: 14px;
        font-size: 12px;
        color: #94a3b8;
        flex-wrap: wrap;
    }
    .notif-meta .rel-time {
        display: flex; align-items: center; gap: 4px;
        font-weight: 500;
    }
    .notif-meta .abs-time { color: #cbd5e1; }
    .notif-meta .unread-label {
        display: inline-flex; align-items: center; gap: 4px;
        color: #2563eb; font-weight: 600;
    }

    /* ── Empty state ── */
    .empty-state {
        text-align: center;
        padding: 64px 24px;
        background: #fff;
        border: 1px dashed #d1d5db;
        border-radius: 14px;
        color: #64748b;
    }
    .empty-state i {
        font-size: 52px;
        color: #e2e8f0;
        display: block;
        margin-bottom: 18px;
    }
    .empty-state h3 { margin: 0 0 8px; font-size: 18px; color: #1e293b; }
    .empty-state p  { margin: 0; font-size: 13.5px; line-height: 1.6; }

    /* ── Pagination ── */
    .pagination-wrap {
        display: flex;
        justify-content: center;
        gap: 6px;
        flex-wrap: wrap;
    }
    .pg-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 38px;
        height: 38px;
        padding: 0 14px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        color: #0f1f78;
        font-size: 13.5px;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s;
        font-family: inherit;
        cursor: pointer;
    }
    .pg-btn:hover:not(.disabled) { border-color: #0f1f78; background: #f0f4ff; }
    .pg-btn.active { background: #0f1f78; color: #fff; border-color: #0f1f78; }
    .pg-btn.disabled { color: #94a3b8; pointer-events: none; background: #f8fafc; }
    .pg-dots { color: #94a3b8; font-weight: 700; padding: 0 4px; line-height: 38px; }

    /* ── Responsive ── */
    @media (max-width: 640px) {
        .notif-page-header { flex-direction: column; align-items: flex-start; gap: 14px; }
        .back-btn { position: relative; left: auto; }
        .notif-card { padding: 14px 16px; gap: 12px; }
        .notif-icon-wrap { width: 40px; height: 40px; font-size: 16px; border-radius: 10px; }
        .notif-title { font-size: 14px; }
        .notif-msg   { font-size: 13px; }
        .notif-summary { flex-wrap: wrap; gap: 10px; }
    }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="notif-wrapper">

    <!-- ── Page header ── -->
    <div class="notif-page-header">
        <a href="profile.php" class="back-btn">
            <i class="fa-solid fa-arrow-left"></i> Back to Profile
        </a>
        <h1>
            <i class="fa-solid fa-bell"></i>
            Notifications
        </h1>
    </div>

    <!-- ── Summary bar ── -->
    <div class="notif-summary">
        <div>
            <div class="sum-count"><?php echo $total_items; ?></div>
            <div>total notification<?php echo $total_items !== 1 ? 's' : ''; ?></div>
        </div>
        <?php $new_count = count($unread_ids); ?>
        <?php if ($new_count > 0): ?>
            <span class="sum-new">
                <i class="fa-solid fa-circle" style="font-size:7px;"></i>
                <?php echo $new_count; ?> new
            </span>
        <?php else: ?>
            <span style="font-size:12px; color:#94a3b8;">All caught up ✓</span>
        <?php endif; ?>
        <span style="margin-left:auto; font-size:12px;">
            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
        </span>
    </div>

    <?php if (count($notifications) > 0): ?>

    <!-- ── Notification cards ── -->
    <div class="notif-list">
        <?php foreach ($notifications as $notif):
            $meta    = notif_icon($notif['title']);
            $was_new = in_array((int)$notif['id'], $unread_ids);
            $rel     = relative_time($notif['created_at']);
            $abs     = date('M d, Y · h:i A', strtotime($notif['created_at']));
        ?>
        <div class="notif-card <?php echo $was_new ? 'is-unread' : ''; ?>">

            <!-- Icon circle -->
            <div class="notif-icon-wrap"
                 style="background:<?php echo $meta['bg']; ?>; color:<?php echo $meta['color']; ?>;">
                <i class="fa-solid <?php echo $meta['icon']; ?>"></i>
            </div>

            <!-- Content -->
            <div class="notif-body">

                <div class="notif-top-row">
                    <h3 class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></h3>
                    <span class="notif-type-badge"
                          style="background:<?php echo $meta['bg']; ?>; color:<?php echo $meta['color']; ?>;">
                        <?php echo $meta['label']; ?>
                    </span>
                </div>

                <p class="notif-msg"><?php echo htmlspecialchars($notif['message']); ?></p>

                <div class="notif-meta">
                    <span class="rel-time">
                        <i class="fa-regular fa-clock"></i>
                        <?php echo $rel; ?>
                    </span>
                    <span class="abs-time"><?php echo $abs; ?></span>
                    <?php if ($was_new): ?>
                        <span class="unread-label">
                            <i class="fa-solid fa-circle" style="font-size:7px;"></i> New
                        </span>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Pagination ── -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination-wrap">
        <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page-1; ?>" class="pg-btn">&laquo; Prev</a>
        <?php else: ?>
            <span class="pg-btn disabled">&laquo; Prev</span>
        <?php endif; ?>

        <?php
        $sp = max(1, $page - 1);
        $ep = min($total_pages, $page + 1);
        if ($sp > 1) {
            echo '<a href="?page=1" class="pg-btn">1</a>';
            if ($sp > 2) echo '<span class="pg-dots">...</span>';
        }
        for ($i = $sp; $i <= $ep; $i++) {
            $cls = $i == $page ? 'active' : '';
            echo "<a href='?page=$i' class='pg-btn $cls'>$i</a>";
        }
        if ($ep < $total_pages) {
            if ($ep < $total_pages - 1) echo '<span class="pg-dots">...</span>';
            echo "<a href='?page=$total_pages' class='pg-btn'>$total_pages</a>";
        }
        ?>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page+1; ?>" class="pg-btn">Next &raquo;</a>
        <?php else: ?>
            <span class="pg-btn disabled">Next &raquo;</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>

    <!-- ── Empty state ── -->
    <div class="empty-state">
        <i class="fa-regular fa-bell-slash"></i>
        <h3>No notifications yet</h3>
        <p>Updates about your orders and service bookings will appear here when available.</p>
    </div>

    <?php endif; ?>

</div>

<?php include 'footer.php'; ?>

</body>
</html>
