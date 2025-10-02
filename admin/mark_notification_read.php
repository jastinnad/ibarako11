<?php
session_start();
require_once '../db.php';
require_once '../notifications.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

if (isset($_GET['id'])) {
    $success = Notifications::markAsRead($_GET['id']);
    echo json_encode(['success' => $success]);
} else {
    echo json_encode(['success' => false, 'error' => 'No ID provided']);
}
?>