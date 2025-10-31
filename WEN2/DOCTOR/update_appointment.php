<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../db_connect.php';
session_start();

if(isset($_POST['update_status'], $_POST['appt_id'], $_POST['status'])) {
    $appt_id = intval($_POST['appt_id']);
    $status = $_POST['status'];
    $doctor_id = $_SESSION['user_id'];

    $stmt = $conn->prepare("UPDATE appointments SET status=? WHERE id=? AND doctor_id=?");
    $stmt->bind_param("sii", $status, $appt_id, $doctor_id);

    if($stmt->execute()) {
        echo json_encode(['status'=>'success', 'message'=>"Appointment $status"]);
    } else {
        echo json_encode(['status'=>'error', 'message'=>'Failed to update']);
    }
} else {
    echo json_encode(['status'=>'error', 'message'=>'Invalid request']);
}
?>
