<?php
require_once 'includes/db_init.php';
// Initialize session
session_start();

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    // Redirect to dashboard or home page
    header("Location: dashboard.php");
    exit();
}

// Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Database connection
    try {
        $db_path = __DIR__ . '/database/family_photos.db';
        
        // Check if database exists
        if (!file_exists($db_path)) {
            $errors[] = "Database not found. Please register first.";
        } else {
            $db = new SQLite3($db_path);
            
            // Get form data
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            
            // Validate form data
            $errors = [];
            
            if (empty($username)) {
                $errors[] = "Username is required";
            }
            
            if (empty($password)) {
                $errors[] = "Password is required";
            }
            
            // If no validation errors, attempt login
            if (empty($errors)) {
                // Check if user exists
                $stmt = $db->prepare('SELECT id, username, password FROM users WHERE username = :username OR email = :username');
                $stmt->bindValue(':username', $username, SQLITE3_TEXT);
                $result = $stmt->execute();
                $user = $result->fetchArray(SQLITE3_ASSOC);
                
                if ($user && password_verify($password, $user['password'])) {
                    // Password is correct, create session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    
                    // Generate and store a session token
                    $session_token = bin2hex(random_bytes(32));
                    $stmt = $db->prepare('UPDATE users SET session_token = :token WHERE id = :id');
                    $stmt->bindValue(':token', $session_token, SQLITE3_TEXT);
                    $stmt->bindValue(':id', $user['id'], SQLITE3_INTEGER);
                    $stmt->execute();
                    
                    // Set session token cookie
                    setcookie('session_token', $session_token, time() + 86400 * 30, "/"); // 30 days
                    
                    // Redirect to dashboard
                    header("Location: dashboard.php");
                    exit();
                } else {
                    $errors[] = "Invalid username or password";
                }
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
    <title>Login Page</title>
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
       
        .login-button {
            background-color: #4CAF50;
            color: white;
        }
       
        .login-button:hover {
            background-color: #45a049;
        }
       
        .register-link {
            text-align: center;
            margin-top: 15px;
        }
       
        a {
            color: #2196F3;
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
        
        .success-message {
            background-color: rgba(0, 255, 0, 0.2);
            color: #4CAF50;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h1>Login</h1>
        
        <?php if (!empty($errors)): ?>
            <div class="error-message">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo $error; ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['message'])): ?>
            <div class="success-message">
                <p><?php echo $_SESSION['message']; ?></p>
            </div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>
        
        <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
            <div class="form-group">
                <label for="username">Username or Email</label>
                <input type="text" id="username" name="username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="button login-button">Login</button>
        </form>
        <div class="register-link">
            <p>Don't have an account? <a href="register.php">Register</a></p>
        </div>
    </div>
</body>
</html>