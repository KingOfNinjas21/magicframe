<?php
require_once 'includes/db_init.php';
// Initialize session
session_start();

// Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Database connection
    try {
        $db_path = __DIR__ . '/database/family_photos.db';
        $db_dir = dirname($db_path);
        
        // Create database directory if it doesn't exist
        if (!file_exists($db_dir)) {
            mkdir($db_dir, 0755, true);
        }
        
        $db = new SQLite3($db_path);
        $db->exec('PRAGMA foreign_keys = ON');
        
        // Create users table if it doesn't exist
        $db->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            session_token TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )');
        
        // Get form data
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm-password'];
        
        // Validate form data
        $errors = [];
        
        if (empty($username)) {
            $errors[] = "Username is required";
        }
        
        if (empty($email)) {
            $errors[] = "Email is required";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format";
        }
        
        if (empty($password)) {
            $errors[] = "Password is required";
        }
        
        if ($password !== $confirm_password) {
            $errors[] = "Passwords do not match";
        }
        
        // Check if username or email already exists
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM users WHERE username = :username OR email = :email');
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray();
        
        if ($result['count'] > 0) {
            $errors[] = "Username or email already exists";
        }
        
        // If no errors, insert user into database
        if (empty($errors)) {
            // Hash password for security
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare('INSERT INTO users (username, email, password) VALUES (:username, :email, :password)');
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            $stmt->bindValue(':email', $email, SQLITE3_TEXT);
            $stmt->bindValue(':password', $hashed_password, SQLITE3_TEXT);
            
            $result = $stmt->execute();
            
            if ($result) {
                // Redirect to login page with success message
                $_SESSION['message'] = "Registration successful! Please log in.";
                header("Location: login.php");
                exit();
            } else {
                $errors[] = "Registration failed. Please try again.";
            }
        }
    } catch (Exception $e) {
        $errors[] = "Database error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Page</title>
    <style>
        body {
            background-color: black;
            color: white;
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
       
        .form-container {
            background-color: rgba(50, 50, 50, 0.8);
            padding: 30px;
            border-radius: 8px;
            width: 300px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        }
       
        h1 {
            text-align: center;
            margin-bottom: 20px;
        }
       
        .form-group {
            margin-bottom: 15px;
        }
       
        label {
            display: block;
            margin-bottom: 5px;
        }
       
        input {
            width: 100%;
            padding: 10px;
            border: none;
            border-radius: 4px;
            background-color: #333;
            color: white;
            box-sizing: border-box;
        }
       
        .button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }
       
        .register-button {
            background-color: #2196F3;
            color: white;
        }
       
        .register-button:hover {
            background-color: #0b7dda;
        }
       
        .login-link {
            text-align: center;
            margin-top: 15px;
        }
       
        a {
            color: #4CAF50;
            text-decoration: none;
        }
       
        a:hover {
            text-decoration: underline;
        }

        .error-message {
            background-color: rgba(255, 0, 0, 0.2);
            color: #ff6b6b;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h1>Register</h1>
        
        <?php if (!empty($errors)): ?>
            <div class="error-message">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo $error; ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-group">
                <label for="confirm-password">Confirm Password</label>
                <input type="password" id="confirm-password" name="confirm-password" required>
            </div>
            <button type="submit" class="button register-button">Register</button>
        </form>
        <div class="login-link">
            <p>Already have an account? <a href="login.php">Login</a></p>
        </div>
    </div>
</body>
</html>