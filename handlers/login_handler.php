<?php
// Login Handler - Contains all login logic
require_once __DIR__ . '/../includes/core.php';
require_once __DIR__ . '/../includes/ErrorHandler.php';

$errorHandler = new ErrorHandler($pdo);

function loginUser($username, $password) {
    global $pdo, $errorHandler;
    
    try {
        // Rate limiting check
        if (!Security::checkRateLimit($_SERVER['REMOTE_ADDR'])) {
            return ['success' => false, 'message' => 'Too many login attempts. Please wait before trying again.'];
        }
        
        // Sanitize input
        $username = trim((string) $username);
        $password = $password;
        
        // Validate input
        $errors = $errorHandler->validateInput([
            'username' => $username
        ], [
            'username' => ['required' => true, 'min_length' => 3, 'max_length' => 255]
        ]);
        
        if (!empty($errors)) {
            return ['success' => false, 'message' => reset($errors)];
        }
        
        // Check user credentials
        $stmt = $pdo->prepare("
            SELECT u.*, r.role_name AS role
            FROM users u
            JOIN roles r ON r.role_id = u.role_id
            WHERE u.username = ? OR u.email = ?
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user && Security::verifyPassword($password, $user["passwordHash"])) {
            if ((int) ($user["isVerified"] ?? 0) !== 1) {
                return ['success' => false, 'message' => 'Please verify your email address before logging in.'];
            }

            // Log successful login
            $stmt = $pdo->prepare("
                INSERT INTO login_attempts (username, ip_address, created_at, success)
                VALUES (?, ?, 1, NOW())
            ");
            $stmt->execute([$user["username"], $_SERVER['REMOTE_ADDR']]);
            
            // Set session data
            $_SESSION["userId"] = $user["userId"];
            $_SESSION["fullName"] = $user["fullName"];
            $_SESSION["role"] = $user["role"];
            $_SESSION["user_logged_in"] = true;
            $_SESSION["login_time"] = time();
            $_SESSION["last_activity"] = time();
            
            // Regenerate session ID
            session_regenerate_id(true);
            
            return ['success' => true, 'message' => 'Login successful', 'redirect' => homeRedirectForRole($user["role"])];
        } else {
            // Log failed login attempt
            $stmt = $pdo->prepare("
                INSERT INTO login_attempts (username, ip_address, created_at, success)
                VALUES (?, ?, 0, NOW())
            ");
            $stmt->execute([$username, $_SERVER['REMOTE_ADDR']]);
            
            return ['success' => false, 'message' => 'Invalid username or password'];
        }
    } catch (Exception $e) {
        $errorMessage = $errorHandler->handleDatabaseError($e);
        return ['success' => false, 'message' => $errorMessage];
    }
}

function logoutUser() {
    // Clear session data
    $_SESSION = array();
    
    // Destroy the session
    session_destroy();
    
    // Clear security headers
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    
    return ['success' => true, 'message' => 'Logged out successfully'];
}

// Handle login form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'];
        
        switch ($action) {
            case 'login':
                $result = loginUser($_POST['username'], $_POST['password']);
                echo json_encode($result);
                break;
                
            case 'logout':
                $result = logoutUser();
                echo json_encode($result);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'An error occurred']);
    }
    
    exit;
}
?>
