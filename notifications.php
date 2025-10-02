<?php
// notifications.php - Notification management system
require_once 'db.php';

class Notifications {
    
    // Create a new notification
    public static function create($type, $title, $message, $user_id = null, $related_id = null, $action_url = null) {
        try {
            $pdo = DB::pdo();
            
            $stmt = $pdo->prepare("
                INSERT INTO notifications (type, title, message, user_id, related_id, action_url, target_user_id) 
                VALUES (?, ?, ?, ?, ?, ?, NULL)
            ");
            
            $stmt->execute([$type, $title, $message, $user_id, $related_id, $action_url]);
            
            return $pdo->lastInsertId();
            
        } catch (Exception $e) {
            error_log("Notification creation error: " . $e->getMessage());
            return false;
        }
    }
    
    // Create admin notification - FIXED VERSION
    public static function notifyAdmin($type, $title, $message, $user_id = null, $related_id = null) {
        try {
            $pdo = DB::pdo();
            
            $action_urls = [
                'member_registration' => 'admin/members.php',
                'loan_application' => 'admin/loans.php?action=view&id=',
                'update_request' => 'admin/update_requests.php?action=view&id=',
                'payment_verification' => 'admin/payments.php?action=verify&id='
            ];
            
            $action_url = isset($action_urls[$type]) ? $action_urls[$type] . $related_id : null;
            
            // Insert notification for admin (target_user_id is NULL for admin notifications)
            $stmt = $pdo->prepare("
                INSERT INTO notifications (type, title, message, user_id, related_id, action_url, target_user_id, is_read, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NULL, 0, NOW())
            ");
            
            $stmt->execute([$type, $title, $message, $user_id, $related_id, $action_url]);
            
            return $pdo->lastInsertId();
            
        } catch (Exception $e) {
            error_log("Admin notification error: " . $e->getMessage());
            return false;
        }
    }
    
    // Create user notification (NEW METHOD)
    public static function notifyUser($user_member_id, $type, $title, $message, $loan_id = null, $related_id = null) {
        try {
            $pdo = DB::pdo();
            
            // First, get the user's ID from member_id
            $stmt = $pdo->prepare("SELECT id FROM users WHERE member_id = ?");
            $stmt->execute([$user_member_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                error_log("User not found with member_id: " . $user_member_id);
                return false;
            }
            
            $action_urls = [
                'loan_approval' => 'member/loans.php?action=view&id=',
				'loan_rejection' => 'member/loans.php?action=view&id=',
				'payment_receipt' => 'member/payments.php?action=view&id=',
				'account_created' => 'member/dashboard.php' // Add this line
			];
            
            $action_url = isset($action_urls[$type]) ? $action_urls[$type] . $related_id : null;
            
            // Insert notification for the specific user
            $stmt = $pdo->prepare("
                INSERT INTO notifications (type, title, message, user_id, related_id, action_url, target_user_id, is_read, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())
            ");
            
            $stmt->execute([$type, $title, $message, $user['id'], $related_id, $action_url, $user['id']]);
            
            return $pdo->lastInsertId();
            
        } catch (Exception $e) {
            error_log("User notification error: " . $e->getMessage());
            return false;
        }
    }
    
    // Get notifications for admin
    public static function getAdminNotifications($limit = 10, $unread_only = false) {
        try {
            $pdo = DB::pdo();
            
            $sql = "SELECT n.*, u.firstname, u.lastname, u.email 
                    FROM notifications n 
                    LEFT JOIN users u ON n.user_id = u.id 
                    WHERE n.target_user_id IS NULL";
            
            if ($unread_only) {
                $sql .= " AND n.is_read = FALSE";
            }
            
            $sql .= " ORDER BY n.created_at DESC";
            
            if ($limit) {
                $sql .= " LIMIT " . intval($limit);
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Notification fetch error: " . $e->getMessage());
            return [];
        }
    }
    
    // Get notifications for a specific user
    public static function getUserNotifications($user_id, $limit = 10, $unread_only = false) {
        try {
            $pdo = DB::pdo();
            
            $sql = "SELECT n.* FROM notifications n 
                    WHERE n.target_user_id = ?";
            
            if ($unread_only) {
                $sql .= " AND n.is_read = FALSE";
            }
            
            $sql .= " ORDER BY n.created_at DESC";
            
            if ($limit) {
                $sql .= " LIMIT " . intval($limit);
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("User notification fetch error: " . $e->getMessage());
            return [];
        }
    }
    
    // Mark notification as read
    public static function markAsRead($notification_id) {
        try {
            $pdo = DB::pdo();
            
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ?");
            $stmt->execute([$notification_id]);
            
            return true;
        } catch (Exception $e) {
            error_log("Notification mark as read error: " . $e->getMessage());
            return false;
        }
    }
    
    // Mark all admin notifications as read
    public static function markAllAsRead() {
        try {
            $pdo = DB::pdo();
            
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE target_user_id IS NULL");
            $stmt->execute();
            
            return true;
        } catch (Exception $e) {
            error_log("Mark all as read error: " . $e->getMessage());
            return false;
        }
    }
    
    // Mark all user notifications as read
    public static function markAllUserAsRead($user_id) {
        try {
            $pdo = DB::pdo();
            
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE target_user_id = ?");
            $stmt->execute([$user_id]);
            
            return true;
        } catch (Exception $e) {
            error_log("Mark all user as read error: " . $e->getMessage());
            return false;
        }
    }
    
    // Get notification counts for admin
    public static function getAdminNotificationCounts() {
        try {
            $pdo = DB::pdo();
            
            $stmt = $pdo->prepare("
                SELECT 
                    type,
                    COUNT(*) as total,
                    SUM(CASE WHEN is_read = FALSE THEN 1 ELSE 0 END) as unread
                FROM notifications 
                WHERE target_user_id IS NULL 
                GROUP BY type
            ");
            $stmt->execute();
            
            $counts = [];
            while ($row = $stmt->fetch()) {
                $counts[$row['type']] = $row;
            }
            
            return $counts;
            
        } catch (Exception $e) {
            error_log("Notification counts error: " . $e->getMessage());
            return [];
        }
    }
    
    // Get total unread count for admin
    public static function getAdminUnreadCount() {
        try {
            $pdo = DB::pdo();
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM notifications 
                WHERE target_user_id IS NULL AND is_read = FALSE
            ");
            $stmt->execute();
            
            return $stmt->fetchColumn();
            
        } catch (Exception $e) {
            error_log("Unread count error: " . $e->getMessage());
            return 0;
        }
    }
    
    // Get total unread count for user
    public static function getUserUnreadCount($user_id) {
        try {
            $pdo = DB::pdo();
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM notifications 
                WHERE target_user_id = ? AND is_read = FALSE
            ");
            $stmt->execute([$user_id]);
            
            return $stmt->fetchColumn();
            
        } catch (Exception $e) {
            error_log("User unread count error: " . $e->getMessage());
            return 0;
        }
    }
}
?>