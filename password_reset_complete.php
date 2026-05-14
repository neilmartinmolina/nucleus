<?php
require "includes/core.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    validateCSRF($_POST["csrf_token"] ?? "");
    
    $token = Security::sanitizeInput($_POST["token"]);
    $password = $_POST["password"];
    $confirmPassword = $_POST["confirmPassword"];
    
    if (empty($token) || empty($password) || empty($confirmPassword)) {
        echo SweetAlert::error("Validation Error", "All fields are required");
        exit;
    }
    
    if ($password !== $confirmPassword) {
        echo SweetAlert::error("Validation Error", "Passwords do not match");
        exit;
    }
    
    if (strlen($password) < 8) {
        echo SweetAlert::error("Validation Error", "Password must be at least 8 characters long");
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT pr.*, u.userId, u.username 
            FROM password_resets pr
            JOIN users u ON pr.userId = u.userId
            WHERE pr.token = ? AND pr.expiry > NOW()
            ORDER BY pr.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $reset = $stmt->fetch();
        
        if (!$reset) {
            echo SweetAlert::error("Invalid Token", "Reset token is invalid or has expired");
            exit;
        }
        
        $hashedPassword = Security::hashPassword($password);
        $stmt = $pdo->prepare("UPDATE users SET passwordHash = ? WHERE userId = ?");
        $stmt->execute([$hashedPassword, $reset["userId"]]);
        
        $stmt = $pdo->prepare("DELETE FROM password_resets WHERE token = ?");
        $stmt->execute([$token]);
        
        echo SweetAlert::success("Password Updated", "Your password has been reset successfully", "login.php");
        exit;
        
    } catch (Exception $e) {
        echo SweetAlert::error("Database Error", "An error occurred while resetting your password");
        error_log("Password reset complete error: " . $e->getMessage());
    }
}

generateCSRFToken();
?>
<!DOCTYPE html>
<html>
<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Complete Password Reset</title>
</head>
<body>
    <div class="container d-flex justify-content-center align-items-center min-vh-100 px-3 py-4">
        <div class="card p-4 w-100" style="max-width:400px;">
            <h4 class="text-center mb-3">Complete Password Reset</h4>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION["csrf_token"] ?>">
                
                <div class="mb-3">
                    <label>Reset Token</label>
                    <input type="text" name="token" class="form-control" value="<?= htmlspecialchars($_GET["token"] ?? "") ?>" required>
                </div>
                <div class="mb-3">
                    <label>New Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label>Confirm Password</label>
                    <input type="password" name="confirmPassword" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Reset Password</button>
            </form>
            
            <div class="text-center mt-3">
                <a href="password_reset.php">Back to Password Reset</a>
            </div>
        </div>
    </div>
</body>
</html>
