<?php
/**
 * manage_addresses.php — AJAX endpoint for customer address CRUD
 * Actions: add | delete | set_default | list
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Not authenticated.']);
    exit();
}

include 'db_connect.php';

$user_id = (int)$_SESSION['user_id'];
$action  = trim($_POST['action'] ?? $_GET['action'] ?? '');

function bad(string $msg): void {
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit();
}
function ok(array $data = []): void {
    echo json_encode(array_merge(['ok' => true], $data));
    exit();
}

// ── list ───────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $stmt = $conn->prepare(
        "SELECT id, label, address, province, city, barangay, street_address, is_default
         FROM user_addresses WHERE user_id = ?
         ORDER BY is_default DESC, id ASC"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    ok(['addresses' => $rows]);
}

// ── add ────────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $label          = mb_substr(trim($_POST['label']          ?? 'Home'), 0, 60);
    $province       = mb_substr(trim($_POST['province']       ?? ''),     0, 100);
    $city           = mb_substr(trim($_POST['city']           ?? ''),     0, 100);
    $barangay       = mb_substr(trim($_POST['barangay']       ?? ''),     0, 100);
    $street_address = mb_substr(trim($_POST['street_address'] ?? ''),     0, 200);

    if (empty($province))       bad('Please select a Province / Region.');
    if (empty($city))           bad('Please select a City / Municipality.');
    if (empty($barangay))       bad('Please select a Barangay.');
    if (strlen($street_address) < 3) bad('Please enter a Street Address.');
    if (empty($label))          $label = 'Home';

    // Build combined address string
    $full_address = trim("$street_address, $barangay, $city, $province");

    // Count existing
    $cnt_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM user_addresses WHERE user_id = ?");
    $cnt_stmt->bind_param('i', $user_id);
    $cnt_stmt->execute();
    $cnt = (int)$cnt_stmt->get_result()->fetch_assoc()['c'];
    $cnt_stmt->close();

    if ($cnt >= 5) bad('You can save a maximum of 5 addresses.');

    $is_default = ($cnt === 0) ? 1 : 0;

    $ins = $conn->prepare(
        "INSERT INTO user_addresses
         (user_id, label, address, province, city, barangay, street_address, is_default)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    $ins->bind_param('issssssi',
        $user_id, $label, $full_address,
        $province, $city, $barangay, $street_address,
        $is_default
    );
    if (!$ins->execute()) bad('Could not save address.');
    $new_id = $ins->insert_id;
    $ins->close();

    if ($is_default) {
        $sync = $conn->prepare("UPDATE users SET address = ? WHERE id = ?");
        $sync->bind_param('si', $full_address, $user_id);
        $sync->execute();
        $sync->close();
    }

    ok(['id' => $new_id, 'is_default' => $is_default, 'full_address' => $full_address]);
}

// ── set_default ────────────────────────────────────────────────────────────
if ($action === 'set_default') {
    $addr_id = (int)($_POST['addr_id'] ?? 0);
    if ($addr_id < 1) bad('Invalid address ID.');

    $own = $conn->prepare("SELECT address FROM user_addresses WHERE id = ? AND user_id = ? LIMIT 1");
    $own->bind_param('ii', $addr_id, $user_id);
    $own->execute();
    $row = $own->get_result()->fetch_assoc();
    $own->close();
    if (!$row) bad('Address not found.');

    $clr = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
    $clr->bind_param('i', $user_id); $clr->execute(); $clr->close();

    $upd = $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
    $upd->bind_param('ii', $addr_id, $user_id);
    $upd->execute(); $upd->close();

    $sync = $conn->prepare("UPDATE users SET address = ? WHERE id = ?");
    $sync->bind_param('si', $row['address'], $user_id);
    $sync->execute(); $sync->close();

    ok(['address' => $row['address']]);
}

// ── delete ─────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $addr_id = (int)($_POST['addr_id'] ?? 0);
    if ($addr_id < 1) bad('Invalid address ID.');

    $own = $conn->prepare(
        "SELECT is_default FROM user_addresses WHERE id = ? AND user_id = ? LIMIT 1"
    );
    $own->bind_param('ii', $addr_id, $user_id);
    $own->execute();
    $row = $own->get_result()->fetch_assoc();
    $own->close();
    if (!$row) bad('Address not found.');

    $del = $conn->prepare("DELETE FROM user_addresses WHERE id = ? AND user_id = ?");
    $del->bind_param('ii', $addr_id, $user_id);
    $del->execute(); $del->close();

    if ($row['is_default']) {
        $nxt = $conn->prepare(
            "SELECT id, address FROM user_addresses WHERE user_id = ? ORDER BY id ASC LIMIT 1"
        );
        $nxt->bind_param('i', $user_id);
        $nxt->execute();
        $next = $nxt->get_result()->fetch_assoc();
        $nxt->close();

        if ($next) {
            $prom = $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ?");
            $prom->bind_param('i', $next['id']); $prom->execute(); $prom->close();
            $sync = $conn->prepare("UPDATE users SET address = ? WHERE id = ?");
            $sync->bind_param('si', $next['address'], $user_id);
            $sync->execute(); $sync->close();
        } else {
            $clr = $conn->prepare("UPDATE users SET address = '' WHERE id = ?");
            $clr->bind_param('i', $user_id); $clr->execute(); $clr->close();
        }
    }
    ok();
}

bad('Unknown action.');
