<?php
session_start();
require_once '../db.php';
require_once '../notifications.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['unread_count' => 0]);
    exit;
}

$unread_count = Notifications::getAdminUnreadCount();
echo json_encode(['unread_count' => $unread_count]);
?>