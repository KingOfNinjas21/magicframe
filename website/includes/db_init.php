<?php
// Set the database file path
// For security, try to place this outside the web root if possible
$db_path = __DIR__ . '/../database/family_photos.db';
$db_dir = dirname($db_path);


// Create database directory if it doesn't exist
if (!file_exists($db_dir)) {
    mkdir($db_dir, 0755, true);
}

// Connect to the database
try {
    $db = new SQLite3($db_path);
    
    // Enable foreign keys
    $db->exec('PRAGMA foreign_keys = ON');
    
    // Create users table
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        session_token TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )');
    
    // Create friendships table
    $db->exec('CREATE TABLE IF NOT EXISTS friendships (
        user_id INTEGER,
        friend_id INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, friend_id),
        FOREIGN KEY (user_id) REFERENCES users (id),
        FOREIGN KEY (friend_id) REFERENCES users (id)
    )');
    
    // Create images table
    $db->exec('CREATE TABLE IF NOT EXISTS images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        filename TEXT NOT NULL,
        original_filename TEXT NOT NULL,
        title TEXT,
        description TEXT,
        upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users (id)
    )');
    
    // Create image_permissions table
    $db->exec('CREATE TABLE IF NOT EXISTS image_permissions (
        image_id INTEGER,
        user_id INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        downloaded BOOLEAN DEFAULT 0,
        PRIMARY KEY (image_id, user_id),
        FOREIGN KEY (image_id) REFERENCES images (id),
        FOREIGN KEY (user_id) REFERENCES users (id)
    )');
    
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>