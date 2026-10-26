<?php
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: admin_login.php");
    exit();
}

include "includes/db_connect.php";

// Define the `e()` function to sanitize output
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================================
   0. AJAX HANDLERS (ADD, EDIT, DELETE AVAILABILITY SLOT)
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? 'add_slot';

    if ($action === 'add_slot') {
        $date       = trim($_POST['date'] ?? '');
        $start_time = trim($_POST['start_time'] ?? '08:00');
        $end_time   = trim($_POST['end_time'] ?? '17:00');
        $status     = trim($_POST['status'] ?? 'Available');
        $notes      = trim($_POST['notes'] ?? '');

        if (empty($date) || empty($start_time) || empty($end_time)) {
            echo json_encode(["success" => false, "message" => "Please fill in the date, start time, and end time."]);
            exit();
        }

        if (strtotime($end_time) <= strtotime($start_time)) {
            echo json_encode(["success" => false, "message" => "End time must be later than start time."]);
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO availability_schedule (date, start_time, end_time, status, notes) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sssss", $date, $start_time, $end_time, $status, $notes);
            if ($stmt->execute()) {
                echo json_encode(["success" => true, "message" => "Availability slot added successfully."]);
            } else {
                echo json_encode(["success" => false, "message" => "Database error: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(["success" => false, "message" => "Failed to prepare query."]);
        }
        exit();
    }

    if ($action === 'edit_slot') {
        $id         = (int)($_POST['slot_id'] ?? 0);
        $date       = trim($_POST['date'] ?? '');
        $start_time = trim($_POST['start_time'] ?? '08:00');
        $end_time   = trim($_POST['end_time'] ?? '17:00');
        $status     = trim($_POST['status'] ?? 'Available');
        $notes      = trim($_POST['notes'] ?? '');

        if ($id <= 0 || empty($date) || empty($start_time) || empty($end_time)) {
            echo json_encode(["success" => false, "message" => "Invalid slot details provided."]);
            exit();
        }

        if (strtotime($end_time) <= strtotime($start_time)) {
            echo json_encode(["success" => false, "message" => "End time must be later than start time."]);
            exit();
        }

        $stmt = $conn->prepare("UPDATE availability_schedule SET date = ?, start_time = ?, end_time = ?, status = ?, notes = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("sssssi", $date, $start_time, $end_time, $status, $notes, $id);
            if ($stmt->execute()) {
                echo json_encode(["success" => true, "message" => "Availability slot updated successfully."]);
            } else {
                echo json_encode(["success" => false, "message" => "Failed to update slot."]);
            }
            $stmt->close();
        }
        exit();
    }

    if ($action === 'delete_slot') {
        $id = (int)($_POST['slot_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM availability_schedule WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
                echo json_encode(["success" => true, "message" => "Availability slot deleted successfully."]);
                exit();
            }
        }
        echo json_encode(["success" => false, "message" => "Could not delete slot."]);
        exit();
    }
}

/* =========================================================
   1. SELF-HEALING CALENDAR SYNC (AUTO-BLOCKS ONLY)
   Automatically removes ONLY system auto-blocked slots on a
   date if active bookings drop below 3. Preserves manual admin slots.
========================================================= */
$check_blocks = $conn->query("
    SELECT id, date 
    FROM availability_schedule 
    WHERE LOWER(status) != 'available'
      AND notes LIKE 'Auto-blocked:%'
");

if ($check_blocks && $check_blocks->num_rows > 0) {
    while ($block = $check_blocks->fetch_assoc()) {
        $blocked_date = $conn->real_escape_string($block['date']);
        $blocked_id = (int)$block['id'];
        
        // Count active services 
        $active_services_res = $conn->query("
            SELECT COUNT(*) AS total 
            FROM services_schedule 
            WHERE service_date = '$blocked_date' 
            AND (inquiry_id IS NULL OR inquiry_id = 0)
            AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')
        ");
        $service_count = $active_services_res ? (int)$active_services_res->fetch_assoc()['total'] : 0;
        
        // Count active inquiries/deliveries
        $active_inquiries_res = $conn->query("
            SELECT COUNT(*) AS total 
            FROM inquiries 
            WHERE DATE(delivery_date) = '$blocked_date' 
            AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')
        ");
        $inquiry_count = $active_inquiries_res ? (int)$active_inquiries_res->fetch_assoc()['total'] : 0;
        
        // Combine both to get true active total
        $active_count = $service_count + $inquiry_count;
        
        // If active bookings are less than 3, remove the auto-block
        if ($active_count < 3) {
            $conn->query("DELETE FROM availability_schedule WHERE id = $blocked_id");
        }
    }
}

$slots = [];
$calendarEvents = [];
$dayStatusMap = []; // Tracks cell background highlights for each date

/* =========================================================
   2. COMBINED BOOKING LIMIT CHECK (Services + Products)
   Calculates total bookings per day and auto-blocks at 3
========================================================= */
$dailyBookings = [];

// Fetch active Service bookings per date 
$serviceBookingsQuery = "
    SELECT service_date AS b_date, COUNT(*) AS total
    FROM services_schedule
    WHERE (inquiry_id IS NULL OR inquiry_id = 0)
    AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')
    GROUP BY service_date
";
$serviceRes = $conn->query($serviceBookingsQuery);
if ($serviceRes && $serviceRes->num_rows > 0) {
    while ($row = $serviceRes->fetch_assoc()) {
        if (!empty($row['b_date'])) {
            $dailyBookings[$row['b_date']] = (int)$row['total'];
        }
    }
}

// Fetch active Product inquiries/deliveries per date
$inquiryBookingsQuery = "
    SELECT DATE(delivery_date) AS b_date, COUNT(*) AS total
    FROM inquiries
    WHERE delivery_date IS NOT NULL 
    AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')
    GROUP BY DATE(delivery_date)
";
$inquiryRes = $conn->query($inquiryBookingsQuery);
if ($inquiryRes && $inquiryRes->num_rows > 0) {
    while ($row = $inquiryRes->fetch_assoc()) {
        $date = $row['b_date'];
        if (!empty($date)) {
            $dailyBookings[$date] = ($dailyBookings[$date] ?? 0) + (int)$row['total'];
        }
    }
}

// Build Day Cell Status Map & Auto-Block at 3 Bookings
foreach ($dailyBookings as $date => $total) {
    $safeDate = $conn->real_escape_string($date);
    if ($total >= 3) {
        $dayStatusMap[$date] = [
            "state" => "full",
            "label" => "FULL ($total/3)"
        ];

        // Auto-insert an 'Unavailable' block into the database if not already blocked
        $checkBlock = $conn->query("SELECT id FROM availability_schedule WHERE date = '$safeDate' AND LOWER(status) != 'available'");
        if ($checkBlock && $checkBlock->num_rows == 0) {
            $autoNote = "Auto-blocked: Reached maximum limit of 3 bookings.";
            $conn->query("INSERT INTO availability_schedule (date, start_time, end_time, status, notes) 
                          VALUES ('$safeDate', '08:00:00', '17:00:00', 'Unavailable', '$autoNote')");
        }
    } elseif ($total === 2) {
        $dayStatusMap[$date] = [
            "state" => "warning",
            "label" => "2/3 BOOKED"
        ];
    } elseif ($total === 1) {
        $dayStatusMap[$date] = [
            "state" => "partial",
            "label" => "1/3 BOOKED"
        ];
    }
}

/* =========================================================
   3. FETCH SCHEDULE, SERVICES, AND INQUIRIES FOR DISPLAY
========================================================= */

// Fetch availability schedule (includes manual and auto-inserted slots)
$availability = $conn->query("SELECT * FROM availability_schedule ORDER BY date DESC, start_time ASC");
if ($availability && $availability->num_rows > 0) {
    while ($row = $availability->fetch_assoc()) {
        $slots[] = $row;
        $date = $row['date'];
        $startLabel = date("g:i A", strtotime($row['start_time']));
        $endLabel   = date("g:i A", strtotime($row['end_time']));
        $isAvailable = strtolower($row['status']) === 'available';
        $isAutoBlock = strpos($row['notes'] ?? '', 'Auto-blocked:') === 0;

        // If an admin manually blocked this date, mark the whole day cell as blocked
        if (!$isAvailable && !isset($dayStatusMap[$date])) {
            $dayStatusMap[$date] = [
                "state" => "blocked",
                "label" => "BLOCKED"
            ];
        }

        // Skip adding a redundant badge on the calendar for system auto-blocks
        // because the cell itself already displays the red FULL (3/3) banner
        if ($isAutoBlock && isset($dayStatusMap[$date]) && $dayStatusMap[$date]['state'] === 'full') {
            continue;
        }

        $calendarEvents[] = [
            "id"              => (int)$row['id'],
            "title"           => ($isAvailable ? "🟢 Open" : "⛔ Blocked") . " ($startLabel - $endLabel)",
            "start"           => $row['date'] . "T" . $row['start_time'],
            "end"             => $row['date'] . "T" . $row['end_time'],
            "backgroundColor" => $isAvailable ? "#10b981" : "#dc2626",
            "borderColor"     => $isAvailable ? "#059669" : "#991b1b",
            "textColor"       => "#ffffff",
            "type"            => "slot",
            "slotId"          => (int)$row['id'],
            "slotDate"        => $row['date'],
            "startTime"       => substr($row['start_time'], 0, 5),
            "endTime"         => substr($row['end_time'], 0, 5),
            "slotStatus"      => $row['status'],
            "notes"           => $row['notes'] ?? ''
        ];
    }
}

// Fetch active direct service requests so the admin sees all bookings contributing to the daily limit
$directServices =$conn->query("
    SELECT * FROM services_schedule
    WHERE (inquiry_id IS NULL OR inquiry_id = 0)
    AND service_date IS NOT NULL
    AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')
    ORDER BY service_date ASC
");
if ($directServices &&$directServices->num_rows > 0) {
    while ($srv =$directServices->fetch_assoc()) {
        $customerName =$srv['customer_name'] ?? 'Customer';
        $serviceType  =$srv['service_type'] ?? 'Service';

        $calendarEvents[] = [
            "id"              => "service_" . $srv['id'],
            "title"           => "🔧 " . $customerName . " (" . $serviceType . ")",
            "start"           => $srv['service_date'],
            "allDay"          => true,
            "backgroundColor" => "#7c3aed",
            "borderColor"     => "#6d28d9",
            "textColor"       => "#ffffff",
            "type"            => "service",
            "serviceId"       => $srv['id'],
            "customerName"    => $customerName,
            "serviceType"     => $serviceType
        ];
    }
}

// Fetch customer selected delivery schedules from inquiries
$inquiries =$conn->query("
    SELECT * FROM inquiries 
    WHERE delivery_date IS NOT NULL 
    AND status NOT IN ('Completed', 'Sold', 'Cancelled', 'Declined', 'Rejected', 'Deleted')
    ORDER BY delivery_date ASC
");
if ($inquiries &&$inquiries->num_rows > 0) {
    while ($inq =$inquiries->fetch_assoc()) {
        $customerName =$inq['customer_name'] ?? 'Customer';
        $productName  =$inq['product_name'] ?? 'Product'; 

        $calendarEvents[] = [
            "id"              => "inquiry_" . $inq['id'],
            "title"           => "🚚 " . $customerName . " (" . $productName . ")",
            "start"           => $inq['delivery_date'],
            "allDay"          => true,
            "backgroundColor" => "#2563eb",
            "borderColor"     => "#1d4ed8",
            "textColor"       => "#ffffff",
            "type"            => "inquiry",
            "inquiryId"       => $inq['id'],
            "customerName"    => $customerName,
            "product"         => $productName
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Availability Schedule | GSB Admin</title>
    <link rel="stylesheet" href="includes/css/admin_dashboard.css">
    <link rel="stylesheet" href="includes/css/availability_schedule.css">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Include FullCalendar -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@3.2.0/dist/fullcalendar.min.css" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment@2.29.1/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@3.2.0/dist/fullcalendar.min.js"></script>
</head>

<body>
    <div class="admin-wrapper">

        <?php include "includes/admin_sidebar.php"; ?>

        <main class="main-content">
            
            <!-- CENTRALIZED TOPBAR -->
            <?php 
            $pending_inquiries_result =$conn->query("SELECT COUNT(*) AS total FROM inquiries WHERE status='Pending'");
            $pending_count = $pending_inquiries_result ? (int)$pending_inquiries_result->fetch_assoc()['total'] : 0;
            include 'includes/topbar.php'; 
            ?>

            <div class="schedule-page-container">

                <!-- Page Header & Controls Bar -->
                <div class="schedule-top-bar">
                    <div class="page-header-info">
                        <h1>Availability Schedule</h1>
                        <p>Manage available time slots, blocked dates, and daily booking capacity.</p>
                    </div>

                    <div class="schedule-controls">
                        <div class="view-toggle">
                            <button id="calendarViewBtn" class="view-btn active">
                                <i class="fa-regular fa-calendar"></i> Calendar View
                            </button>
                            <button id="listViewBtn" class="view-btn">
                                <i class="fa-solid fa-list-ul"></i> List View
                            </button>
                        </div>

                        <button type="button" class="primary-add-btn openAddSlotModal">
                            <i class="fa-solid fa-plus"></i> Add New Slot
                        </button>
                    </div>
                </div>

                <!-- Legend Bar -->
                <div class="calendar-legend">
                    <span class="legend-item"><span class="legend-dot green"></span> 1/3 Booked / Open</span>
                    <span class="legend-item"><span class="legend-dot amber"></span> 2/3 Booked (Almost Full)</span>
                    <span class="legend-item"><span class="legend-dot red"></span> Fully Booked (3/3) / Blocked</span>
                    <span class="legend-item"><span class="legend-dot blue"></span> Product Delivery</span>
                    <span class="legend-item"><span class="legend-dot purple"></span> Service Request</span>
                    <span class="legend-tip"><i class="fa-regular fa-lightbulb"></i> Tip: Red highlighted squares are full or blocked. Click any date to add a slot.</span>
                </div>

                <!-- Calendar View Section -->
                <section id="calendarView" class="view-section active-section">
                    <div class="panel calendar-panel">
                        <div id="calendar"></div>
                    </div>
                </section>

                <!-- Availability Slots List View (Table) -->
                <section id="listView" class="view-section" style="display: none;">
                    <div class="panel schedule-panel">
                        <div class="panel-header">
                            <div>
                                <h2><i class="fa-regular fa-calendar-check"></i> Configured Availability Slots</h2>
                                <p class="panel-subtitle">View, edit, or remove custom and auto-blocked time slots.</p>
                            </div>
                        </div>

                        <div class="schedule-table-wrapper">
                            <table class="schedule-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time Window</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                        <th style="text-align: right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($slots)) { ?>
                                        <?php foreach ($slots as $row) {$formattedDate = date("M d, Y (D)", strtotime($row['date']));$formattedTime = date("h:i A", strtotime($row['start_time'])) . ' – ' . date("h:i A", strtotime($row['end_time']));
                                            $statusClass = strtolower($row['status']) === 'available' ? 'available' : 'unavailable';
                                        ?>
                                            <tr>
                                                <td class="col-date">
                                                    <i class="fa-regular fa-calendar"></i> <?php echo e($formattedDate); ?>
                                                </td>
                                                <td class="col-time">
                                                    <i class="fa-regular fa-clock"></i> <?php echo e($formattedTime); ?>
                                                </td>
                                                <td>
                                                    <span class="status-pill <?php echo $statusClass; ?>">
                                                        <span class="status-dot"></span>
                                                        <?php echo e($row['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="col-notes">
                                                    <?php echo !empty($row['notes']) ? e($row['notes']) : '<span class="muted-text">No notes provided</span>'; ?>
                                                </td>
                                                <td class="col-actions">
                                                    <button type="button" class="table-action-btn edit-btn" 
                                                        title="Edit Slot"
                                                        onclick="openEditSlotModal(
                                                            <?php echo (int)$row['id']; ?>,
                                                            '<?php echo e($row['date']); ?>',
                                                            '<?php echo e(substr($row['start_time'], 0, 5)); ?>',
                                                            '<?php echo e(substr($row['end_time'], 0, 5)); ?>',
                                                            '<?php echo e($row['status']); ?>',
                                                            '<?php echo addslashes(e($row['notes'] ?? '')); ?>'
                                                        )">
                                                        <i class="fa-regular fa-pen-to-square"></i>
                                                    </button>
                                                    <button type="button" class="table-action-btn delete-btn" 
                                                        title="Delete Slot"
                                                        onclick="deleteSlot(<?php echo (int)$row['id']; ?>)">
                                                        <i class="fa-regular fa-trash-can"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    <?php } else { ?>
                                        <tr>
                                            <td colspan="5" class="empty-row">
                                                <i class="fa-regular fa-calendar-xmark"></i>
                                                <p>No custom or blocked availability slots found.</p>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

            </div>

            <!-- Add / Edit Availability Slot Modal -->
            <div id="slotModal" class="slot-modal-overlay">
                <div class="slot-modal-card">
                    <div class="slot-modal-header">
                        <div class="slot-modal-title-group">
                            <div class="slot-modal-icon">
                                <i class="fa-regular fa-calendar-plus" id="modalHeaderIcon"></i>
                            </div>
                            <div>
                                <h2 id="modalTitle">Add New Availability Slot</h2>
                                <p id="modalSubtitle">Set open hours or block out unavailable dates on the calendar.</p>
                            </div>
                        </div>
                        <button type="button" class="slot-modal-close closeSlotModal">&times;</button>
                    </div>

                    <form id="slotForm" method="POST">
                        <input type="hidden" id="formAction" name="action" value="add_slot">
                        <input type="hidden" id="slot_id" name="slot_id" value="">

                        <div class="slot-modal-body">
                            <div class="form-group">
                                <label for="date">Date <span class="req">*</span></label>
                                <input type="date" id="date" name="date" required>
                            </div>

                            <div class="form-row-2col">
                                <div class="form-group">
                                    <label for="start_time">Start Time <span class="req">*</span></label>
                                    <input type="time" id="start_time" name="start_time" value="08:00" required>
                                </div>

                                <div class="form-group">
                                    <label for="end_time">End Time <span class="req">*</span></label>
                                    <input type="time" id="end_time" name="end_time" value="17:00" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="status">Availability Status <span class="req">*</span></label>
                                <select id="status" name="status" required>
                                    <option value="Available">🟢 Available (Open for Bookings)</option>
                                    <option value="Unavailable">🔴 Unavailable (Block Date / Time)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="notes">Notes / Reason <span class="opt">(Optional)</span></label>
                                <textarea id="notes" name="notes" rows="3" placeholder="e.g., Holiday closure, team maintenance, or special operating hours..."></textarea>
                            </div>
                        </div>

                        <div class="slot-modal-footer">
                            <button type="button" id="modalDeleteBtn" class="btn-danger-outline" style="display: none;">
                                <i class="fa-regular fa-trash-can"></i> Delete
                            </button>
                            <div class="footer-right-btns">
                                <button type="button" class="btn-secondary closeSlotModal">Cancel</button>
                                <button type="submit" class="btn-primary-submit" id="modalSubmitBtn">
                                    <i class="fa-solid fa-check"></i> Save Slot
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

        </main>
    </div>

    <script>
    function openAddSlotModal(defaultDate) {
        $("#formAction").val("add_slot");
        $("#slot_id").val("");
        $("#modalTitle").text("Add New Availability Slot");
        $("#modalSubtitle").text("Set open hours or block out unavailable dates on the calendar.");
        $("#modalHeaderIcon").attr("class", "fa-regular fa-calendar-plus");
        $("#modalSubmitBtn").html('<i class="fa-solid fa-check"></i> Add Slot');
        $("#modalDeleteBtn").hide();

        $("#date").val(defaultDate || moment().format("YYYY-MM-DD"));
        $("#start_time").val("08:00");
        $("#end_time").val("17:00");
        $("#status").val("Available");
        $("#notes").val("");

        $("#slotModal").css("display", "flex").hide().fadeIn(180);
    }

    function openEditSlotModal(id, date, startTime, endTime, status, notes) {
        $("#formAction").val("edit_slot");
        $("#slot_id").val(id);
        $("#modalTitle").text("Edit Availability Slot");
        $("#modalSubtitle").text("Modify time window, status, or remove this slot.");
        $("#modalHeaderIcon").attr("class", "fa-regular fa-pen-to-square");
        $("#modalSubmitBtn").html('<i class="fa-solid fa-check"></i> Save Changes');

        $("#date").val(date);
        $("#start_time").val(startTime);
        $("#end_time").val(endTime);
        $("#status").val(status);
        $("#notes").val(notes);

        $("#modalDeleteBtn").show().off("click").on("click", function() {
            deleteSlot(id);
        });

        $("#slotModal").css("display", "flex").hide().fadeIn(180);
    }

    function deleteSlot(id) {
        if (!confirm("Are you sure you want to delete this availability slot?")) return;

        $.ajax({
            url: "availability_schedule.php",
            method: "POST",
            dataType: "json",
            data: { action: "delete_slot", slot_id: id },
            success: function(res) {
                if (res.success) {
                    location.reload();
                } else {
                    alert(res.message || "Failed to delete slot.");
                }
            },
            error: function() {
                alert("An error occurred while deleting the slot.");
            }
        });
    }

    $(document).ready(function() {
        var calendarEvents = <?php echo json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        var dayStatusMap = <?php echo json_encode($dayStatusMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        var calendarLoaded = false;

        function decorateCalendarDays() {
            // Add top-left capacity badges inside each day header cell
            $(".fc-day-top").each(function() {
                var dateStr = $(this).attr("data-date");
                $(this).find(".day-capacity-badge").remove();

                if (dateStr && dayStatusMap[dateStr]) {
                    var info = dayStatusMap[dateStr];
                    var icon = "";
                    if (info.state === "full" || info.state === "blocked") {
                        icon = '<i class="fa-solid fa-ban"></i> ';
                    } else if (info.state === "warning") {
                        icon = '<i class="fa-solid fa-triangle-exclamation"></i> ';
                    } else {
                        icon = '<i class="fa-solid fa-circle-check"></i> ';
                    }
                    $(this).prepend(
                        '<span class="day-capacity-badge badge-' + info.state + '">' + icon + info.label + '</span>'
                    );
                }
            });
        }

        function loadCalendar() {
            if (!calendarLoaded) {
                $("#calendar").fullCalendar({
                    header: {
                        left: "prev,next today",
                        center: "title",
                        right: "month,agendaWeek,agendaDay"
                    },
                    defaultView: "month",
                    height: "auto",
                    contentHeight: "auto",
                    aspectRatio: 1.6,
                    editable: false,
                    eventLimit: false, // Never hide events behind "+4 more"
                    events: calendarEvents,
                    eventOverlap: true,

                    // Color the entire day cell background based on capacity
                    dayRender: function(date, cell) {
                        var dateStr = date.format("YYYY-MM-DD");
                        if (dayStatusMap[dateStr]) {
                            var state = dayStatusMap[dateStr].state;
                            cell.addClass("day-status-" + state);
                        }
                    },

                    viewRender: function() {
                        setTimeout(decorateCalendarDays, 10);
                    },

                    dayClick: function(date) {
                        openAddSlotModal(date.format("YYYY-MM-DD"));
                    },

                    eventClick: function(event) {
                        if (event.type === "inquiry") {
                            window.location.href = "admin_inquiries.php?id=" + event.inquiryId;
                        } else if (event.type === "service") {
                            window.location.href = "services_schedule.php";
                        } else if (event.type === "slot") {
                            openEditSlotModal(
                                event.slotId,
                                event.slotDate,
                                event.startTime,
                                event.endTime,
                                event.slotStatus,
                                event.notes || ""
                            );
                        }
                        return false;
                    },

                    eventRender: function(event, element) {
                        if (event.notes) {
                            element.attr("title", event.notes);
                        }
                        if (event.type === "inquiry") {
                            element.attr(
                                "title",
                                "Customer: " + (event.customerName || "N/A") +
                                " | Product: " + (event.product || "N/A")
                            );
                        }
                        if (event.type === "service") {
                            element.attr(
                                "title",
                                "Customer: " + (event.customerName || "N/A") +
                                " | Service: " + (event.serviceType || "N/A")
                            );
                        }
                    }
                });

                calendarLoaded = true;
            } else {
                $("#calendar").fullCalendar("render");
                decorateCalendarDays();
            }
        }

        loadCalendar();

        // Toggle Calendar View
        $("#calendarViewBtn").click(function() {
            $("#listView").hide();
            $("#calendarView").fadeIn(150);
            $(this).addClass("active");
            $("#listViewBtn").removeClass("active");

            setTimeout(function() {
                loadCalendar();
            }, 80);
        });

        // Toggle List View
        $("#listViewBtn").click(function() {
            $("#calendarView").hide();
            $("#listView").fadeIn(150);
            $(this).addClass("active");
            $("#calendarViewBtn").removeClass("active");
        });

        // Open Add Slot Modal from top button
        $(".openAddSlotModal").click(function() {
            openAddSlotModal(moment().format("YYYY-MM-DD"));
        });

        // Close Modal
        $(".closeSlotModal").click(function() {
            $("#slotModal").fadeOut(150);
        });

        $(window).click(function(e) {
            if ($(e.target).is("#slotModal")) {
                $("#slotModal").fadeOut(150);
            }
        });

        // Submit Add / Edit Slot Form via AJAX
        $("#slotForm").submit(function(e) {
            e.preventDefault();
            var submitBtn = $("#modalSubmitBtn");
            submitBtn.prop("disabled", true);

            $.ajax({
                url: "availability_schedule.php",
                method: "POST",
                dataType: "json",
                data: $(this).serialize(),
                success: function(res) {
                    if (res.success) {
                        $("#slotModal").fadeOut(150);
                        location.reload();
                    } else {
                        alert(res.message || "Could not save slot.");
                        submitBtn.prop("disabled", false);
                    }
                },
                error: function() {
                    alert("Something went wrong while saving the slot.");
                    submitBtn.prop("disabled", false);
                }
            });
        });
    });
    </script>
</body>
</html>