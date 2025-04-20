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
        // Check if at least one person is selected to share with
        if (!isset($_POST['share_with']) || !is_array($_POST['share_with']) || count($_POST['share_with']) == 0) {
            $upload_error = "Bitte wähle mindestens eine Person aus, mit der du deine Bilder teilen möchtest.";
        } else {
            // Process multiple file uploads - first check if files were uploaded
            if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
                $upload_count = 0;
                $error_count = 0;
                
                // Count total files
                $total_files = count($_FILES['images']['name']);
                
                // Handle each file
                for ($i = 0; $i < $total_files; $i++) {
                    if ($_FILES['images']['error'][$i] == 0) {
                        $allowed = array('jpg', 'jpeg', 'png', 'gif');
                        $filename = $_FILES['images']['name'][$i];
                        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        
                        // Check if extension is allowed
                        if (in_array($file_ext, $allowed)) {
                            // Generate unique filename
                            $new_filename = uniqid() . '.' . $file_ext;
                            $destination = $upload_dir . '/' . $new_filename;
                            
                            // Move uploaded file
                            if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $destination)) {
                                // Store image info in database
                                $title = !empty($_POST['title']) ? trim($_POST['title']) : "";
                                $description = !empty($_POST['description']) ? trim($_POST['description']) : "";
                                
                                // If multiple files, append number to title if title is provided
                                if ($total_files > 1 && !empty($title)) {
                                    $title = $title . " (" . ($i + 1) . ")";
                                }
                                
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
                                    foreach ($_POST['share_with'] as $friend_id) {
                                        // Add permission for each selected friend
                                        $stmt = $db->prepare('INSERT INTO image_permissions (image_id, user_id) VALUES (:image_id, :user_id)');
                                        $stmt->bindValue(':image_id', $image_id, SQLITE3_INTEGER);
                                        $stmt->bindValue(':user_id', $friend_id, SQLITE3_INTEGER);
                                        $stmt->execute();
                                    }
                                    
                                    $upload_count++;
                                } else {
                                    $error_count++;
                                }
                            } else {
                                $error_count++;
                            }
                        } else {
                            $error_count++;
                        }
                    } else {
                        $error_count++;
                    }
                }
                
                if ($upload_count > 0) {
                    $upload_success = $upload_count . " Bild(er) erfolgreich hochgeladen!";
                    if ($error_count > 0) {
                        $upload_success .= " " . $error_count . " Bild(er) konnten nicht hochgeladen werden.";
                    }
                } elseif ($error_count > 0) {
                    $upload_error = "Fehler beim Hochladen der Bilder. Bitte überprüfe deine Dateien.";
                } else {
                    $upload_error = "Bitte wähle mindestens ein Bild zum Hochladen aus.";
                }
            } else {
                $upload_error = "Keine Dateien zum Hochladen ausgewählt.";
            }
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
                            $friend_success = "Freund erfolgreich hinzugefügt!";
                        } else {
                            $friend_error = "Fehler beim Hinzufügen des Freundes.";
                        }
                    } else {
                        $friend_error = "Diese Person ist bereits in deiner Freundesliste.";
                    }
                } else {
                    $friend_error = "Du kannst dich nicht selbst als Freund hinzufügen.";
                }
            } else {
                $friend_error = "Kein Benutzer mit dieser E-Mail-Adresse gefunden.";
            }
        } else {
            $friend_error = "Bitte gib eine E-Mail-Adresse ein.";
        }
    }
    
    // Get friend list
    $stmt = $db->prepare('
        SELECT u.id, u.username, u.email 
        FROM users u
        JOIN friendships f ON u.id = f.friend_id
        WHERE f.user_id = :user_id OR f.friend_id = :user_id
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
        /* Reset & Base Styles */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            background-color: black;
            color: white;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            font-size: 16px;
            line-height: 1.5;
        }
        
        /* Header Styles */
        .header {
            background-color: rgba(50, 50, 50, 0.8);
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header h1 {
            margin: 0;
            font-size: 1.5rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            margin-top: 5px;
        }
        
        .user-info span {
            margin-right: 15px;
            font-size: 0.9rem;
        }
        
        .logout-btn {
            background-color: #f44336;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9rem;
            display: inline-block;
        }
        
        /* Main Container */
        .container {
            display: flex;
            flex-direction: column;
            min-height: calc(100vh - 70px);
        }
        
        /* Sidebar & Main Content */
        .sidebar {
            width: 100%;
            background-color: rgba(40, 40, 40, 0.8);
            padding: 15px;
            order: 2; /* Change order for mobile */
        }
        
        .main-content {
            flex: 1;
            padding: 15px;
            order: 1; /* Change order for mobile */
        }
        
        /* Sections */
        .section {
            background-color: rgba(50, 50, 50, 0.8);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        h2 {
            margin-top: 0;
            margin-bottom: 15px;
            border-bottom: 1px solid #555;
            padding-bottom: 10px;
            font-size: 1.3rem;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-size: 0.9rem;
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
            font-size: 0.9rem;
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
            font-size: 0.9rem;
            transition: background-color 0.3s;
            width: 100%;
            max-width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
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
        
        /* Friend List */
        .friend-list {
            margin-bottom: 20px;
        }
        
        .friend-item {
            background-color: rgba(60, 60, 60, 0.8);
            padding: 10px;
            margin-bottom: 5px;
            border-radius: 4px;
            font-size: 0.9rem;
            word-break: break-word;
        }
        
        /* Messages */
        .error-message {
            background-color: rgba(255, 0, 0, 0.2);
            color: #ff6b6b;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }
        
        .success-message {
            background-color: rgba(0, 255, 0, 0.2);
            color: #4CAF50;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }
        
        /* Image Grid */
        .image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 10px;
        }
        
        .image-card {
            background-color: rgba(60, 60, 60, 0.8);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .image-card img {
            width: 100%;
            aspect-ratio: 1/1;
            object-fit: cover;
        }
        
        .image-info {
            padding: 8px;
        }
        
        .image-title {
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .image-meta {
            font-size: 0.75rem;
            color: #aaa;
        }
        
        /* Checkboxes & Selects */
        .friend-select {
            height: 120px;
            overflow-y: auto;
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
        
        /* File Input */
        .file-input-container {
            position: relative;
            padding: 10px;
            background-color: #333;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        
        .file-input-container input[type="file"] {
            width: 100%;
        }
        
        .file-count {
            margin-top: 5px;
            font-size: 0.75rem;
            color: #aaa;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Self Share Option */
        .self-share-option {
            margin-bottom: 10px;
            padding: 10px;
            background-color: rgba(70, 70, 70, 0.8);
            border-radius: 4px;
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            margin-bottom: 15px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .tab {
            padding: 8px 15px;
            background-color: rgba(60, 60, 60, 0.8);
            cursor: pointer;
            margin-right: 5px;
            border-radius: 4px 4px 0 0;
            white-space: nowrap;
            font-size: 0.9rem;
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
        
        /* Menu Toggle for Mobile */
        .menu-toggle {
            display: block;
            background-color: #2196F3;
            color: white;
            border: none;
            padding: 10px;
            width: 100%;
            text-align: center;
            margin-bottom: 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        /* Responsive Adjustments */
        @media (min-width: 768px) {
            .container {
                flex-direction: row;
            }
            
            .sidebar {
                width: 300px;
                order: 1; /* Reset order for desktop */
            }
            
            .main-content {
                order: 2; /* Reset order for desktop */
                padding: 20px;
            }
            
            .button {
                width: auto;
            }
            
            .menu-toggle {
                display: none;
            }
            
            .header h1 {
                font-size: 1.8rem;
            }
            
            .user-info span {
                font-size: 1rem;
            }
            
            .logout-btn {
                font-size: 1rem;
            }
            
            .section {
                padding: 20px;
            }
            
            .image-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 15px;
            }
            
            .image-title {
                font-size: 0.95rem;
            }
            
            .image-meta {
                font-size: 0.8rem;
            }
            
            h2 {
                font-size: 1.5rem;
            }
            
            .sidebar {
                display: block !important; /* Always visible on desktop */
            }
        }
        
        /* Initially hide sidebar on mobile */
        @media (max-width: 767px) {
            .sidebar {
                display: none;
            }
            
            .image-card img {
                height: 120px;
            }
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
        <!-- Mobile menu toggle -->
        <button class="menu-toggle" id="menuToggle">Show/Hide Friend Options</button>
        
        <div class="sidebar" id="sidebar">
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
                <h2>Upload New Photos</h2>
                <?php if (isset($upload_error)): ?>
                    <div class="error-message"><?php echo $upload_error; ?></div>
                <?php endif; ?>
                <?php if (isset($upload_success)): ?>
                    <div class="success-message"><?php echo $upload_success; ?></div>
                <?php endif; ?>
                <form method="POST" action="" enctype="multipart/form-data" id="uploadForm">
                    <div class="form-group">
                        <label for="images">Select Images:</label>
                        <div class="file-input-container">
                            <input type="file" id="images" name="images[]" accept="image/*" multiple onchange="updateFileCount()">
                            <div class="file-count" id="fileCount">No files selected</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="title">Title (optional):</label>
                        <input type="text" id="title" name="title">
                    </div>
                    <div class="form-group">
                        <label for="description">Description (optional):</label>
                        <textarea id="description" name="description"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Share with:</label>
                        <div class="self-share-option">
                            <label>
                                <input type="checkbox" id="share_self" name="share_with[]" value="<?php echo $user_id; ?>" checked>
                                Myself (<?php echo htmlspecialchars($username); ?>)
                            </label>
                        </div>
                        
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
                    <button type="submit" name="upload" class="button primary-btn" id="uploadButton">Upload Photos</button>
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
                                    <div class="image-title"><?php echo htmlspecialchars($image['title'] ? $image['title'] : 'Kein Titel'); ?></div>
                                    <div class="image-meta">
                                        Shared: <?php echo $image['share_count']; ?><br>
                                        <?php echo date('M j, Y', strtotime($image['upload_date'])); ?>
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
                                    <div class="image-title"><?php echo htmlspecialchars($image['title'] ? $image['title'] : 'Kein Titel'); ?></div>
                                    <div class="image-meta">
                                        By: <?php echo htmlspecialchars($image['uploaded_by']); ?><br>
                                        <?php echo date('M j, Y', strtotime($image['upload_date'])); ?>
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
    
    // Menu toggle for mobile - FIXED VERSION
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    
    // Set initial state explicitly based on viewport
    if (window.innerWidth < 768) {
        sidebar.style.display = 'none';
    } else {
        sidebar.style.display = 'block';
    }
    
    menuToggle.addEventListener('click', function() {
        // Toggle sidebar visibility with explicit setting
        if (sidebar.style.display === 'block') {
            sidebar.style.display = 'none';
        } else {
            sidebar.style.display = 'block';
        }
    });
    
    // Form validation
    const uploadForm = document.getElementById('uploadForm');
    const uploadButton = document.getElementById('uploadButton');
    
    uploadForm.addEventListener('submit', function(e) {
        const fileInput = document.getElementById('images');
        const shareCheckboxes = document.querySelectorAll('input[name="share_with[]"]:checked');
        
        if (fileInput.files.length === 0) {
            e.preventDefault();
            alert('Bitte wähle mindestens ein Bild zum Hochladen aus.');
            return false;
        }
        
        if (shareCheckboxes.length === 0) {
            e.preventDefault();
            alert('Bitte wähle mindestens eine Person aus, mit der du deine Bilder teilen möchtest.');
            return false;
        }
        
        return true;
    });
    
    // Adjust layout on window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 768) {
            sidebar.style.display = 'block';
        } else {
            // Keep current state on mobile or default to hidden
            if (sidebar.style.display === '') {
                sidebar.style.display = 'none';
            }
        }
    });
});

// Update file count display
function updateFileCount() {
    const fileInput = document.getElementById('images');
    const fileCount = document.getElementById('fileCount');
    
    if (fileInput.files.length === 0) {
        fileCount.textContent = 'Keine Dateien ausgewählt';
    } else if (fileInput.files.length === 1) {
        fileCount.textContent = '1 Datei ausgewählt: ' + fileInput.files[0].name;
    } else {
        fileCount.textContent = fileInput.files.length + ' Dateien ausgewählt';
    }
}
</script>
</body>
</html>
</antArtifact>