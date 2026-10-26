<?php
session_start();
require_once 'includes/db_connect.php';
require_once __DIR__ . '/../includes/auth_helpers.php';

require_admin_session('admin_login.php');

function e($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

$result = $conn->query("SELECT * FROM deliveries ORDER BY delivery_date DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deliveries — GSB Admin</title>
    <link rel="stylesheet" href="includes/css/admin_dashboard.css">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include 'includes/admin_sidebar.php'; ?>
    <main class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <section class="page-header">
            <h1>Product Deliveries</h1>
            <p>All scheduled and completed deliveries.</p>
        </section>

        <div style="padding: 0 50px 50px;">
            <div class="panel">
                <div class="panel-header">
                    <h2>Deliveries</h2>
                    <a href="add_delivery.php" class="primary-btn">
                        <i class="fa-solid fa-plus"></i> Add Delivery
                    </a>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer</th>
                                <th>Product</th>
                                <th>Date</th>
                                <th>Address</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= e($row['id']) ?></td>
                                <td><?= e($row['customer_name']) ?></td>
                                <td><?= e($row['product_name']) ?></td>
                                <td><?= e($row['delivery_date']) ?></td>
                                <td><?= e($row['address']) ?></td>
                                <td><span class="badge <?= e(strtolower(str_replace(' ', '-', $row['status'] ?? ''))) ?>"><?= e($row['status'] ?? '—') ?></span></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="empty-row">No deliveries found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
<?php include 'includes/global_modals.php'; ?>
</body>
</html>
