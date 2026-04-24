<?php
function logActivity($mysqli, $username, $role, $action, $description){

    if (!$mysqli) return;

    $stmt = $mysqli->prepare("
        INSERT INTO activity_logs (username, role, action_type, description, log_time)
        VALUES (?, ?, ?, ?, NOW())
    ");

    if ($stmt) {
        $stmt->bind_param("ssss", $username, $role, $action, $description);
        $stmt->execute();
        $stmt->close();
    }
}
?>