<?php
// Initialize session
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    // Redirect to login page
    header("Location: login.php");
    exit();
}

// Database connection
try {
    $db_path = __DIR__ . '/database/family_photos.db';
    $db = new SQLite3($db_path);
    $db->exec('PRAGMA foreign_keys = ON');
    
    // Get current user info
    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'];
    
    // Create uploads directory if it doesn't exist
    $upload_dir = __DIR__ . '/uploads/' . $user_id;
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Handle image upload
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['upload'])) {
        // Check if file was uploaded without errors
        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $allowed = array('jpg', 'jpeg', 'png', 'gif');
            $filename = $_FILES['image']['name'];
            $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            // Check if extension is allowed
            if (in_array($file_ext, $allowed)) {
                // Generate unique filename
                $new_filename = uniqid() . '.' . $file_ext;
                $destination = $upload_dir . '/' . $new_filename;
                
                // Move uploaded file
                if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                    // Store image info in database
                    $title = trim($_POST['title']);
                    $description = trim($_POST['description']);
                    
                    $stmt = $db->prepare('INSERT INTO images (user_id, filename, original_filename, title, description) VALUES (:user_id, :filename, :original_filename, :title, :description)');
                    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
                    $stmt->bindValue(':filename', $new_filename, SQLITE3_TEXT);
                    $stmt->bindValue(':original_filename', $filename, SQLITE3_TEXT);
                    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
                    $stmt->bindValue(':description', $description, SQLITE3_TEXT);
                    $result = $stmt->execute();
                    
                    if ($result) {
                        $image_id = $db->lastInsertRowID();
                        
                        // Get shared user list
                        if (isset($_POST['share_with']) && is_array($_POST['share_with'])) {
                            foreach ($_POST['share_with'] as $friend_id) {
                                // Add permission for each selected friend
                                $stmt = $db->prepare('INSERT INTO image_permissions (image_id, user_id) VALUES (:image_id, :user_id)');
                                $stmt->bindValue(':image_id', $image_id, SQLITE3_INTEGER);
                                $stmt->bindValue(':user_id', $friend_id, SQLITE3_INTEGER);
                                $stmt->execute();
                            }
                        }
                        
                        $upload_success = "Image uploaded successfully!";
                    } else {
                        $upload_error = "Error saving image to database.";
                    }
                } else {
                    $upload_error = "Error uploading file.";
                }
            } else {
                $upload_error = "Invalid file type. Allowed types: jpg, jpeg, png, gif";
            }
        } else {
            $upload_error = "Please select an image to upload.";
        }
    }
    
    // Add friend functionality
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_friend'])) {
        $friend_email = trim($_POST['friend_email']);
        
        if (!empty($friend_email)) {
            // Find user by email
            $stmt = $db->prepare('SELECT id FROM users WHERE email = :email');
            $stmt->bindValue(':email', $friend_email, SQLITE3_TEXT);
            $result = $stmt->execute();
            $friend = $result->fetchArray(SQLITE3_ASSOC);
            
            if ($friend) {
                $friend_id = $friend['id'];
                
                // Check if friend is not the current user
                if ($friend_id != $user_id) {
                    // Check if friendship already exists
                    $stmt = $db->prepare('SELECT COUNT(*) as count FROM friendships WHERE (user_id = :user_id AND friend_id = :friend_id)');
                    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
                    $stmt->bindValue(':friend_id', $friend_id, SQLITE3_INTEGER);
                    $result = $stmt->execute()->fetchArray();
                    
                    if ($result['count'] == 0) {
                        // Add friendship
                        $stmt = $db->prepare('INSERT INTO friendships (user_id, friend_id) VALUES (:user_id, :friend_id)');
                        $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
                        $stmt->bindValue(':friend_id', $friend_id, SQLITE3_INTEGER);
                        $result = $stmt->execute();
                        
                        if ($result) {
                            $friend_success = "Friend added successfully!";
                        } else {
                            $friend_error = "Error adding friend.";
                        }
                    } else {
                        $friend_error = "This person is already in your friend list.";
                    }
                } else {
                    $friend_error = "You cannot add yourself as a friend.";
                }
            } else {
                $friend_error = "No user found with that email.";
            }
        } else {
            $friend_error = "Please enter an email address.";
        }
    }
    
    // Get friend list
    $stmt = $db->prepare('
        SELECT u.id, u.username, u.email 
        FROM users u
        JOIN friendships f ON u.id = f.friend_id
        WHERE f.user_id = :user_id
    ');
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $friends = [];
    while ($friend = $result->fetchArray(SQLITE3_ASSOC)) {
        $friends[] = $friend;
    }
    
    // Get user's images
    $stmt = $db->prepare('
        SELECT i.*, COUNT(p.user_id) as share_count
        FROM images i
        LEFT JOIN image_permissions p ON i.id = p.image_id
        WHERE i.user_id = :user_id
        GROUP BY i.id
        ORDER BY i.upload_date DESC
    ');
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $images = [];
    while ($image = $result->fetchArray(SQLITE3_ASSOC)) {
        $images[] = $image;
    }
    
    // Get shared images
    $stmt = $db->prepare('
        SELECT i.*, u.username as uploaded_by
        FROM images i
        JOIN image_permissions p ON i.id = p.image_id
        JOIN users u ON i.user_id = u.id
        WHERE p.user_id = :user_id AND i.user_id != :user_id
        ORDER BY i.upload_date DESC
    ');
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $shared_images = [];
    while ($image = $result->fetchArray(SQLITE3_ASSOC)) {
        $shared_images[] = $image;
    }
    
} catch (Exception $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Family Photo Sharing</title>
    <style>
        body {
            background-color: black;
            color: white;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
        }
        
        .header {
            background-color: rgba(50, 50, 50, 0.8);
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
        }
        
        .user-info span {
            margin-right: 15px;
        }
        
        .logout-btn {
            background-color: #f44336;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
        }
        
        .container {
            display: flex;
            min-height: calc(100vh - 70px);
        }
        
        .sidebar {
            width: 300px;
            background-color: rgba(40, 40, 40, 0.8);
            padding: 20px;
        }
        
        .main-content {
            flex: 1;
            padding: 20px;
        }
        
        .section {
            background-color: rgba(50, 50, 50, 0.8);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        h2 {
            margin-top: 0;
            margin-bottom: 15px;
            border-bottom: 1px solid #555;
            padding-bottom: 10px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
        }
        
        input[type="text"],
        input[type="email"],
        textarea,
        select {
            width: 100%;
            padding: 10px;
            border: none;
            border-radius: 4px;
            background-color: #333;
            color: white;
            box-sizing: border-box;
        }
        
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .button {
            padding: 10px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
        }
        
        .primary-btn {
            background-color: #4CAF50;
            color: white;
        }
        
        .primary-btn:hover {
            background-color: #45a049;
        }
        
        .secondary-btn {
            background-color: #2196F3;
            color: white;
        }
        
        .secondary-btn:hover {
            background-color: #0b7dda;
        }
        
        .friend-list {
            margin-bottom: 20px;
        }
        
        .friend-item {
            background-color: rgba(60, 60, 60, 0.8);
            padding: 10px;
            margin-bottom: 5px;
            border-radius: 4px;
        }
        
        .error-message {
            background-color: rgba(255, 0, 0, 0.2);
            color: #ff6b6b;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        
        .success-message {
            background-color: rgba(0, 255, 0, 0.2);
            color: #4CAF50;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        
        .image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .image-card {
            background-color: rgba(60, 60, 60, 0.8);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .image-card img {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }
        
        .image-info {
            padding: 10px;
        }
        
        .image-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .image-meta {
            font-size: 12px;
            color: #aaa;
        }
        
        .friend-select {
            height: 120px;
            overflow-y: auto;
        }
        
        .tabs {
            display: flex;
            margin-bottom: 20px;
        }
        
        .tab {
            padding: 10px 20px;
            background-color: rgba(60, 60, 60, 0.8);
            cursor: pointer;
            margin-right: 5px;
            border-radius: 4px 4px 0 0;
        }
        
        .tab.active {
            background-color: rgba(50, 50, 50, 0.8);
            font-weight: bold;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .checkbox-group {
            padding: 10px;
            background-color: #333;
            border-radius: 4px;
            max-height: 150px;
            overflow-y: auto;
        }
        
        .checkbox-item {
            margin-bottom: 5px;
        }
        
        .checkbox-item label {
            display: flex;
            align-items: center;
        }
        
        .checkbox-item input {
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Family Photo Sharing</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($username); ?></span>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="sidebar">
            <div class="section">
                <h2>Add Friend</h2>
                <?php if (isset($friend_error)): ?>
                    <div class="error-message"><?php echo $friend_error; ?></div>
                <?php endif; ?>
                <?php if (isset($friend_success)): ?>
                    <div class="success-message"><?php echo $friend_success; ?></div>
                <?php endif; ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="friend_email">Friend's Email:</label>
                        <input type="email" id="friend_email" name="friend_email" required>
                    </div>
                    <button type="submit" name="add_friend" class="button secondary-btn">Add Friend</button>
                </form>
            </div>
            
            <div class="section">
                <h2>Friend List</h2>
                <?php if (empty($friends)): ?>
                    <p>You haven't added any friends yet.</p>
                <?php else: ?>
                    <div class="friend-list">
                        <?php foreach ($friends as $friend): ?>
                            <div class="friend-item">
                                <div><?php echo htmlspecialchars($friend['username']); ?></div>
                                <div class="image-meta"><?php echo htmlspecialchars($friend['email']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="main-content">
            <div class="section">
                <h2>Upload New Photo</h2>
                <?php if (isset($upload_error)): ?>
                    <div class="error-message"><?php echo $upload_error; ?></div>
                <?php endif; ?>
                <?php if (isset($upload_success)): ?>
                    <div class="success-message"><?php echo $upload_success; ?></div>
                <?php endif; ?>
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="image">Select Image:</label>
                        <input type="file" id="image" name="image" accept="image/*" required>
                    </div>
                    <div class="form-group">
                        <label for="title">Title:</label>
                        <input type="text" id="title" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="description">Description:</label>
                        <textarea id="description" name="description"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Share with:</label>
                        <?php if (empty($friends)): ?>
                            <p>You need to add friends to share photos with them.</p>
                        <?php else: ?>
                            <div class="checkbox-group">
                                <?php foreach ($friends as $friend): ?>
                                    <div class="checkbox-item">
                                        <label>
                                            <input type="checkbox" name="share_with[]" value="<?php echo $friend['id']; ?>">
                                            <?php echo htmlspecialchars($friend['username']); ?> (<?php echo htmlspecialchars($friend['email']); ?>)
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="submit" name="upload" class="button primary-btn">Upload Photo</button>
                </form>
            </div>
            
            <div class="tabs">
                <div class="tab active" data-tab="my-photos">My Photos</div>
                <div class="tab" data-tab="shared-photos">Shared With Me</div>
            </div>
            
            <div class="section tab-content active" id="my-photos">
                <h2>My Photos</h2>
                <?php if (empty($images)): ?>
                    <p>You haven't uploaded any photos yet.</p>
                <?php else: ?>
                    <div class="image-grid">
                        <?php foreach ($images as $image): ?>
                            <div class="image-card">
                                <img src="uploads/<?php echo $user_id; ?>/<?php echo htmlspecialchars($image['filename']); ?>" alt="<?php echo htmlspecialchars($image['title']); ?>">
                                <div class="image-info">
                                    <div class="image-title"><?php echo htmlspecialchars($image['title']); ?></div>
                                    <div class="image-meta">
                                        Shared with: <?php echo $image['share_count']; ?> people<br>
                                        Uploaded: <?php echo date('M j, Y', strtotime($image['upload_date'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="section tab-content" id="shared-photos">
                <h2>Photos Shared With Me</h2>
                <?php if (empty($shared_images)): ?>
                    <p>No photos have been shared with you yet.</p>
                <?php else: ?>
                    <div class="image-grid">
                        <?php foreach ($shared_images as $image): ?>
                            <div class="image-card">
                                <img src="uploads/<?php echo $image['user_id']; ?>/<?php echo htmlspecialchars($image['filename']); ?>" alt="<?php echo htmlspecialchars($image['title']); ?>">
                                <div class="image-info">
                                    <div class="image-title"><?php echo htmlspecialchars($image['title']); ?></div>
                                    <div class="image-meta">
                                        By: <?php echo htmlspecialchars($image['uploaded_by']); ?><br>
                                        Uploaded: <?php echo date('M j, Y', strtotime($image['upload_date'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Tab switching functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Remove active class from all tabs and contents
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));
                    
                    // Add active class to current tab and content
                    this.classList.add('active');
                    document.getElementById(tabId).classList.add('active');
                });
            });
        });
    </script>
</body>
</html>