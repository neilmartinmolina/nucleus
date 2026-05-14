<?php
require "includes/core.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = Security::sanitizeInput($_POST["email"]);
    
    if (!Security::validateEmail($email)) {
        echo SweetAlert::error("Validation Error", "Please enter a valid email address");
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT userId, username, fullName FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            echo SweetAlert::success("Password Reset", "If an account with this email exists, a reset link has been sent.");
            exit;
        }
        
        $resetToken = Security::generatePasswordResetToken();
        $expiry = date("Y-m-d H:i:s", strtotime("+1 hour"));
        
        $stmt = $pdo->prepare("INSERT INTO password_resets (userId, token, expiry, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$user["userId"], $resetToken, $expiry]);
        
        if (isLocal()) {
            echo SweetAlert::info("Password Reset", "Reset token: " . $resetToken . "<br>Token expires in 1 hour<br><br><a href=\"index.php?page=password-reset-complete&token=" . $resetToken . "\">Click here to reset</a>");
        } else {
            echo SweetAlert::success("Password Reset", "If an account with this email exists, a reset link has been sent.");
        }
        
        $stmt = $pdo->prepare("INSERT INTO password_reset_attempts (userId, email, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$user["userId"], $email]);
        
    } catch (Exception $e) {
        echo SweetAlert::error("Database Error", "An error occurred while processing your request");
        error_log("Password reset error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Password Reset</title>
</head>
<body>
    <div class="container d-flex justify-content-center align-items-center min-vh-100 px-3 py-4">
        <div class="card p-4 w-100" style="max-width:400px;">
            <h4 class="text-center mb-3">Password Reset</h4>
            <form method="POST">
                <div class="mb-3">
                    <label>Email Address</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Reset Password</button>
            </form>
            <div class="text-center mt-3">
                <a href="login.php">Back to Login</a>
            </div>
        </div>
    </div>
</body>
</html>
