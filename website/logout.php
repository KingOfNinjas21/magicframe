<?php
// Initialize session
session_start();

// Database connection for cleaning up session tokens
try {
    $db_path = __DIR__ . '/database/family_photos.db';
    
    if (file_exists($db_path)) {
        $db = new SQLite3($db_path);
        
        // Clear session token if user is logged in
        if (isset($_SESSION['user_id'])) {
            $stmt = $db->prepare('UPDATE users SET session_token = NULL WHERE id = :id');
            $stmt->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
            $stmt->execute();
        }
    }
} catch (Exception $e) {
    // Just proceed with logout even if database update fails
}

// Clear all session variables
$_SESSION = array();

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Delete the session token cookie
setcookie('session_token', '', time() - 42000, '/');

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();
?>