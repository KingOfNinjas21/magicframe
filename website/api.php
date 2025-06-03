<?php
// Initialize session
session_start();

// Set headers for JSON API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Database connection
try {
    $db_path = __DIR__ . '/database/family_photos.db';
    $db = new SQLite3($db_path);
    $db->exec('PRAGMA foreign_keys = ON');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Get the request method and path
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$path = ltrim(substr($path, strpos($path, 'api.php')), 'api.php');
$pathParts = explode('/', trim($path, '/'));
$endpoint = $pathParts[0] ?? '';

// Authentication middleware - checks if user is logged in
function authenticate() {
    global $db;
    
    // Check for token in headers
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    
    if (empty($authHeader)) {
        http_response_code(401);
        echo json_encode(['error' => 'No authorization token provided']);
        exit();
    }
    
    // Extract the token
    $token = str_replace('Bearer ', '', $authHeader);
    
    // Check if token exists in database
    $stmt = $db->prepare('SELECT id, username FROM users WHERE session_token = :token');
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token']);
        exit();
    }
    
    return $user;
}

// Login endpoint
function login() {
    global $db;
    
    // Get JSON data from request body
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['username']) || !isset($data['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Username and password are required']);
        return;
    }
    
    $username = trim($data['username']);
    $password = $data['password'];
    
    // Check if user exists
    $stmt = $db->prepare('SELECT id, username, password FROM users WHERE username = :username OR email = :username');
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        // Generate and store a session token
        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare('UPDATE users SET session_token = :token WHERE id = :id');
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $stmt->bindValue(':id', $user['id'], SQLITE3_INTEGER);
        $stmt->execute();
        
        // Return success with token
        echo json_encode([
            'success' => true,
            'user_id' => $user['id'],
            'username' => $user['username'],
            'token' => $token
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid username or password']);
    }
}

// Register endpoint
function register() {
    global $db;
    
    // Get JSON data from request body
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['username']) || !isset($data['email']) || !isset($data['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Username, email and password are required']);
        return;
    }
    
    $username = trim($data['username']);
    $email = trim($data['email']);
    $password = password_hash($data['password'], PASSWORD_DEFAULT);
    
    try {
        // Check if username or email already exists
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM users WHERE username = :username OR email = :email');
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray();
        
        if ($result['count'] > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Username or email already exists']);
            return;
        }
        
        // Insert new user
        $stmt = $db->prepare('INSERT INTO users (username, email, password) VALUES (:username, :email, :password)');
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':password', $password, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        if ($result) {
            $userId = $db->lastInsertRowID();
            echo json_encode([
                'success' => true,
                'user_id' => $userId,
                'message' => 'User registered successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to register user']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Registration error: ' . $e->getMessage()]);
    }
}

// Get all images for the current user
function getMyImages() {
    global $db;
    
    $user = authenticate();
    $userId = $user['id'];
    
    $stmt = $db->prepare('
        SELECT i.*, COUNT(p.user_id) as share_count
        FROM images i
        LEFT JOIN image_permissions p ON i.id = p.image_id
        WHERE i.user_id = :user_id
        GROUP BY i.id
        ORDER BY i.upload_date DESC
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $images = [];
    while ($image = $result->fetchArray(SQLITE3_ASSOC)) {
        // Add image URL
        $image['url'] = 'uploads/' . $userId . '/' . $image['filename'];
        $images[] = $image;
    }
    
    echo json_encode(['images' => $images]);
}

// Get images shared with the current user
function getSharedImages() {
    global $db;
    
    $user = authenticate();
    $userId = $user['id'];
    
    $stmt = $db->prepare('
        SELECT i.*, u.username as uploaded_by
        FROM images i
        JOIN image_permissions p ON i.id = p.image_id
        JOIN users u ON i.user_id = u.id
        WHERE p.user_id = :user_id AND i.user_id != :user_id
        ORDER BY i.upload_date DESC
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $images = [];
    while ($image = $result->fetchArray(SQLITE3_ASSOC)) {
        // Add image URL
        $image['url'] = 'uploads/' . $image['user_id'] . '/' . $image['filename'];
        $images[] = $image;
    }
    
    echo json_encode(['images' => $images]);
}

function getNotDownloadedImages() {
    global $db;
    
    $user = authenticate();
    $userId = $user['id'];
    
    // Query for not downloaded images
    $stmt = $db->prepare('
        SELECT i.*, u.username as uploaded_by, u.id as owner_id
        FROM images i
        JOIN image_permissions p ON i.id = p.image_id
        JOIN users u ON i.user_id = u.id
        WHERE p.user_id = :user_id
        AND p.downloaded = 0
        ORDER BY i.upload_date DESC
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    // Check if we should return metadata or download the files
    $downloadMode = isset($_GET['download']) && $_GET['download'] == 'true';
    
    $images = [];
    $imagesToZip = [];
    
    while ($image = $result->fetchArray(SQLITE3_ASSOC)) {
        // Add image URL for metadata mode
        $image['url'] = 'uploads/' . $image['user_id'] . '/' . $image['filename'];
        $images[] = $image;
        
        // Collect file info for download mode
        if ($downloadMode) {
            $filePath = __DIR__ . '/uploads/' . $image['user_id'] . '/' . $image['filename'];
            if (file_exists($filePath)) {
                $imagesToZip[] = [
                    'id' => $image['id'],
                    'path' => $filePath,
                    'name' => $image['original_filename'] ?? $image['filename']
                ];
            }
        }
        
        // Mark as downloaded regardless of mode
        $updateStmt = $db->prepare('
            UPDATE image_permissions
            SET downloaded = 1
            WHERE image_id = :image_id AND user_id = :user_id
        ');
        $updateStmt->bindValue(':image_id', $image['id'], SQLITE3_INTEGER);
        $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $updateStmt->execute();
    }
    
    // If no download request or no images, return metadata
    if (!$downloadMode || empty($imagesToZip)) {
        if ($downloadMode && empty($imagesToZip)) {
            echo json_encode(['message' => 'No new images to download']);
        } else {
            echo json_encode(['images' => $images]);
        }
        return;
    }
    
    // If only one image, download it directly
    if (count($imagesToZip) === 1) {
        $image = $imagesToZip[0];
        $filePath = $image['path'];
        
        // Set appropriate headers for file download
        header('Content-Type: application/json'); // Remove this line when testing
        header('Content-Type: ' . mime_content_type($filePath));
        header('Content-Disposition: attachment; filename="' . $image['name'] . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: public');
        
        // Output the file
        readfile($filePath);
        exit;
    }
    
    // For multiple images, create a ZIP archive
    $zipFilename = 'new_family_photos_' . date('Ymd_His') . '.zip';
    $zipPath = sys_get_temp_dir() . '/' . $zipFilename;
    
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not create ZIP archive']);
        return;
    }
    
    // Add images to ZIP
    $filenameMap = []; // To handle duplicate filenames
    foreach ($imagesToZip as $image) {
        $filename = $image['name'];
        
        // Handle duplicate filenames
        if (isset($filenameMap[$filename])) {
            $filenameMap[$filename]++;
            $parts = pathinfo($filename);
            $filename = $parts['filename'] . '_' . $filenameMap[$filename] . '.' . $parts['extension'];
        } else {
            $filenameMap[$filename] = 1;
        }
        
        $zip->addFile($image['path'], $filename);
    }
    
    $zip->close();
    
    // Send ZIP file
    header('Content-Type: application/json'); // Remove this line when testing
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Pragma: public');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    
    readfile($zipPath);
    
    // Clean up
    unlink($zipPath);
    exit;
}

// Get not downloaded images for the current user
function getNotDownloadedImagesMeta() {
    global $db;
    
    $user = authenticate();
    $userId = $user['id'];
    
    $stmt = $db->prepare('
        SELECT i.*, u.username as uploaded_by
        FROM images i
        JOIN image_permissions p ON i.id = p.image_id
        JOIN users u ON i.user_id = u.id
        WHERE p.user_id = :user_id 
        AND i.user_id != :user_id
        AND p.downloaded = 0
        ORDER BY i.upload_date DESC
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $images = [];
    while ($image = $result->fetchArray(SQLITE3_ASSOC)) {
        // Add image URL
        $image['url'] = 'uploads/' . $image['user_id'] . '/' . $image['filename'];
        $images[] = $image;
        
        // Mark as downloaded
        $updateStmt = $db->prepare('
            UPDATE image_permissions 
            SET downloaded = 1 
            WHERE image_id = :image_id AND user_id = :user_id
        ');
        $updateStmt->bindValue(':image_id', $image['id'], SQLITE3_INTEGER);
        $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $updateStmt->execute();
    }
    
    echo json_encode(['images' => $images]);
}

// Upload a new image
function uploadImage() {
    global $db;
    
    $user = authenticate();
    $userId = $user['id'];
    
    if (!isset($_FILES['image']) || $_FILES['image']['error'] != 0) {
        http_response_code(400);
        echo json_encode(['error' => 'No image file uploaded or upload error']);
        return;
    }
    
    // Create uploads directory if it doesn't exist
    $upload_dir = __DIR__ . '/uploads/' . $userId;
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $allowed = array('jpg', 'jpeg', 'png', 'gif');
    $filename = $_FILES['image']['name'];
    $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    // Check if extension is allowed
    if (!in_array($file_ext, $allowed)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid file type. Allowed types: jpg, jpeg, png, gif']);
        return;
    }
    
    // Generate unique filename
    $new_filename = uniqid() . '.' . $file_ext;
    $destination = $upload_dir . '/' . $new_filename;
    
    // Get other form data
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $share_with = isset($_POST['share_with']) ? json_decode($_POST['share_with'], true) : [];
    
    // Move uploaded file
    if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
        // Store image info in database
        $stmt = $db->prepare('INSERT INTO images (user_id, filename, original_filename, title, description) VALUES (:user_id, :filename, :original_filename, :title, :description)');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':filename', $new_filename, SQLITE3_TEXT);
        $stmt->bindValue(':original_filename', $filename, SQLITE3_TEXT);
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':description', $description, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        if ($result) {
            $imageId = $db->lastInsertRowID();
            
            // Add permissions for each shared user
            foreach ($share_with as $friendId) {
                $stmt = $db->prepare('INSERT INTO image_permissions (image_id, user_id) VALUES (:image_id, :user_id)');
                $stmt->bindValue(':image_id', $imageId, SQLITE3_INTEGER);
                $stmt->bindValue(':user_id', $friendId, SQLITE3_INTEGER);
                $stmt->execute();
            }
            
            echo json_encode([
                'success' => true,
                'image_id' => $imageId,
                'message' => 'Image uploaded successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Error saving image to database']);
        }
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error uploading file']);
    }
}

// Get friends list
function getFriends() {
    global $db;
    
    $user = authenticate();
    $userId = $user['id'];
    
    $stmt = $db->prepare('
        SELECT u.id, u.username, u.email 
        FROM users u
        JOIN friendships f ON u.id = f.friend_id
        WHERE f.user_id = :user_id
    ');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $friends = [];
    while ($friend = $result->fetchArray(SQLITE3_ASSOC)) {
        $friends[] = $friend;
    }
    
    echo json_encode(['friends' => $friends]);
}

// Add a friend
function addFriend() {
    global $db;
    
    $user = authenticate();
    $userId = $user['id'];
    
    // Get JSON data from request body
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['email'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Friend email is required']);
        return;
    }
    
    $friendEmail = trim($data['email']);
    
    // Find user by email
    $stmt = $db->prepare('SELECT id FROM users WHERE email = :email');
    $stmt->bindValue(':email', $friendEmail, SQLITE3_TEXT);
    $result = $stmt->execute();
    $friend = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$friend) {
        http_response_code(404);
        echo json_encode(['error' => 'No user found with that email']);
        return;
    }
    
    $friendId = $friend['id'];
    
    // Check if friend is not the current user
    if ($friendId == $userId) {
        http_response_code(400);
        echo json_encode(['error' => 'You cannot add yourself as a friend']);
        return;
    }
    
    // Check if friendship already exists
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM friendships WHERE user_id = :user_id AND friend_id = :friend_id');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':friend_id', $friendId, SQLITE3_INTEGER);
    $result = $stmt->execute()->fetchArray();
    
    if ($result['count'] > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'This person is already in your friend list']);
        return;
    }
    
    // Add friendship
    $stmt = $db->prepare('INSERT INTO friendships (user_id, friend_id) VALUES (:user_id, :friend_id)');
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':friend_id', $friendId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'friend_id' => $friendId,
            'message' => 'Friend added successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error adding friend']);
    }
}

// Logout
function logout() {
    global $db;
    
    $user = authenticate();
    $userId = $user['id'];
    
    // Remove session token
    $stmt = $db->prepare('UPDATE users SET session_token = NULL WHERE id = :id');
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully'
    ]);
}

function downloadImages() {
    global $db;
    
    $user = authenticate();
    $userId = $user['id'];
    
    // Get image IDs from the request (supports both GET and POST)
    $imageIds = [];
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ids'])) {
        // Handle GET request with comma-separated IDs
        $imageIds = array_map('intval', explode(',', $_GET['ids']));
    } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle POST request with JSON body
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['ids']) && is_array($data['ids'])) {
            $imageIds = array_map('intval', $data['ids']);
        }
    }
    
    // Check if we have any image IDs
    if (empty($imageIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'No image IDs provided. Use "ids" parameter with comma-separated values or POST a JSON object with an "ids" array']);
        return;
    }
    
    // For single image, use direct download
    if (count($imageIds) === 1) {
        downloadSingleImage($imageIds[0], $userId, $db);
        return;
    }
    
    // For multiple images, create a ZIP archive
    $imagesToZip = [];
    $validImageCount = 0;
    
    // Check permissions and collect valid images
    foreach ($imageIds as $imageId) {
        $stmt = $db->prepare('
            SELECT i.*, u.id as owner_id, i.original_filename as orig_name
            FROM images i
            JOIN users u ON i.user_id = u.id
            LEFT JOIN image_permissions p ON i.id = p.image_id AND p.user_id = :user_id
            WHERE i.id = :image_id
            AND (i.user_id = :user_id OR p.user_id = :user_id)
        ');
        $stmt->bindValue(':image_id', $imageId, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $image = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($image) {
            $validImageCount++;
            $filePath = __DIR__ . '/uploads/' . $image['user_id'] . '/' . $image['filename'];
            
            if (file_exists($filePath)) {
                $imagesToZip[] = [
                    'id' => $image['id'],
                    'owner_id' => $image['owner_id'],
                    'path' => $filePath,
                    'name' => $image['orig_name'] // Use original filename for the ZIP
                ];
                
                // Update download status if it's a shared image
                if ($image['owner_id'] != $userId) {
                    $updateStmt = $db->prepare('
                        UPDATE image_permissions 
                        SET downloaded = 1 
                        WHERE image_id = :image_id AND user_id = :user_id
                    ');
                    $updateStmt->bindValue(':image_id', $imageId, SQLITE3_INTEGER);
                    $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                    $updateStmt->execute();
                }
            }
        }
    }
    
    // If no valid images were found
    if (empty($imagesToZip)) {
        http_response_code(404);
        echo json_encode(['error' => 'No valid images found or you do not have permission to access them']);
        return;
    }
    
    // Create ZIP file
    $zipFilename = 'family_photos_' . date('Ymd_His') . '.zip';
    $zipPath = sys_get_temp_dir() . '/' . $zipFilename;
    
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not create ZIP archive']);
        return;
    }
    
    // Add images to ZIP
    $filenameMap = []; // To handle duplicate filenames
    foreach ($imagesToZip as $image) {
        $filename = $image['name'];
        
        // Handle duplicate filenames
        if (isset($filenameMap[$filename])) {
            $filenameMap[$filename]++;
            $parts = pathinfo($filename);
            $filename = $parts['filename'] . '_' . $filenameMap[$filename] . '.' . $parts['extension'];
        } else {
            $filenameMap[$filename] = 1;
        }
        
        $zip->addFile($image['path'], $filename);
    }
    
    $zip->close();
    
    // Send ZIP file
    header('Content-Type: application/json'); // Remove this line
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Pragma: public');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    
    readfile($zipPath);
    
    // Clean up
    unlink($zipPath);
    exit;
}

// Helper function for downloading a single image
function downloadSingleImage($imageId, $userId, $db) {
    // Check if user owns this image or has permission to access it
    $stmt = $db->prepare('
        SELECT i.*, u.id as owner_id
        FROM images i
        JOIN users u ON i.user_id = u.id
        LEFT JOIN image_permissions p ON i.id = p.image_id AND p.user_id = :user_id
        WHERE i.id = :image_id
        AND (i.user_id = :user_id OR p.user_id IS NOT NULL)
    ');
    $stmt->bindValue(':image_id', $imageId, SQLITE3_INTEGER);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $image = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$image) {
        http_response_code(404);
        echo json_encode(['error' => 'Image not found or you do not have permission to access it']);
        return;
    }
    
    // Update download status if it's a shared image
    if ($image['owner_id'] != $userId) {
        $updateStmt = $db->prepare('
            UPDATE image_permissions 
            SET downloaded = 1 
            WHERE image_id = :image_id AND user_id = :user_id
        ');
        $updateStmt->bindValue(':image_id', $imageId, SQLITE3_INTEGER);
        $updateStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $updateStmt->execute();
    }
    
    // Get the file path
    $filePath = __DIR__ . '/uploads/' . $image['user_id'] . '/' . $image['filename'];
    
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Image file not found']);
        return;
    }
    
    // Set appropriate headers for file download
    header('Content-Type: application/json'); // Remove this line
    header('Content-Type: ' . mime_content_type($filePath));
    header('Content-Disposition: attachment; filename="' . $image['original_filename'] . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: public');
    
    // Output the file
    readfile($filePath);
    exit;
}

// Route the request to the appropriate function
switch ($endpoint) {
    case 'login':
        if ($requestMethod === 'POST') {
            login();
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;
        
    case 'register':
        if ($requestMethod === 'POST') {
            register();
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;
        
    case 'images':
        if ($requestMethod === 'GET') {
            getMyImages();
        } else if ($requestMethod === 'POST') {
            uploadImage();
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;
        
    case 'sharedImages':
        if ($requestMethod === 'GET') {
            getSharedImages();
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;
        
    case 'notDownloadedImages':
        if ($requestMethod === 'GET') {
            getNotDownloadedImages();
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;
    case 'notDownloadedImagesMeta':
        if ($requestMethod === 'GET') {
            getNotDownloadedImagesMeta();
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;
    
    case 'friends':
        if ($requestMethod === 'GET') {
            getFriends();
        } else if ($requestMethod === 'POST') {
            addFriend();
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;
        
    case 'logout':
        if ($requestMethod === 'POST') {
            logout();
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;
    case 'download':
        if ($requestMethod === 'GET' || $requestMethod === 'POST') {
            downloadImages();
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;
        
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
        break;
}