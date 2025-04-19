<?php
// Connect to SQLite Database
function getDbConnection() {
    return new SQLite3('path_to_your_database.db'); // Provide the correct path to your SQLite database
}

// SQL commands to create tables
$sql = "
    CREATE TABLE IF NOT EXISTS images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        path TEXT NOT NULL,
        sender_id INTEGER,
        receiver_id INTEGER,
        FOREIGN KEY(sender_id) REFERENCES users(id),
        FOREIGN KEY(receiver_id) REFERENCES users(id)
    );

    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        password TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE
    );

    CREATE TABLE IF NOT EXISTS friends (
        user_id INTEGER,
        friend_id INTEGER,
        FOREIGN KEY(user_id) REFERENCES users(id),
        FOREIGN KEY(friend_id) REFERENCES users(id),
        PRIMARY KEY(user_id, friend_id)
    );
";

// Connect to the database
$db = getDbConnection();

// Execute the SQL commands
if ($db->exec($sql)) {
    echo "Tables created successfully.";
} else {
    echo "Error: " . $db->lastErrorMsg();
}

$db->close();
?>
