<?php
function logActivity($mysqli, $username, $role, $action, $description){

    $stmt = $mysqli->prepare("
        INSERT INTO activity_logs (username, role, action_type, description)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->bind_param("ssss", $username, $role, $action, $description);
    $stmt->execute();
}
?>