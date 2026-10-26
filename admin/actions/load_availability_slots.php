<?php
include "db_connect.php";

// Query to fetch availability schedule from the database
$query = "SELECT * FROM availability_schedule ORDER BY date, start_time";
$result = $conn->query($query);

// Create an array to hold the events
$events = array();

// Loop through the results and format them for FullCalendar
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $events[] = array(
            'title' => $row['status'], // Event title based on the status (Available or Unavailable)
            'start' => $row['date'] . 'T' . $row['start_time'], // Combine date and start time
            'end' => $row['date'] . 'T' . $row['end_time'], // Combine date and end time
            'backgroundColor' => $row['status'] == 'Available' ? '#4CAF50' : '#F44336', // Green for Available, Red for Unavailable
            'borderColor' => $row['status'] == 'Available' ? '#388E3C' : '#D32F2F', // Green or red border
            'description' => $row['notes'], // Event description from the notes
        );
    }
}

// Return the events array as JSON to be used by FullCalendar
echo json_encode($events);
?>