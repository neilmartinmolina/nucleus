<?php
require_once __DIR__ . "/../includes/core.php";
require_once __DIR__ . "/../includes/Mailer.php";

function registerUser(array $input): array {
    global $pdo;

    $username = trim((string) ($input["username"] ?? ""));
    $fullName = trim((string) ($input["fullName"] ?? ""));
    $email = trim((string) ($input["email"] ?? ""));
    $password = (string) ($input["password"] ?? "");
    $confirmPassword = (string) ($input["confirm_password"] ?? "");

    if (!Security::validateUsername($username)) {
        return ["success" => false, "message" => "Username must be 3-50 characters and use only letters, numbers, and underscores."];
    }

    if ($fullName === "" || strlen($fullName) > 255) {
        return ["success" => false, "message" => "Full name is required and must be 255 characters or less."];
    }

    if (!Security::validateEmail($email)) {
        return ["success" => false, "message" => "Please enter a valid email address."];
    }

    if (strlen($password) < 8) {
        return ["success" => false, "message" => "Password must be at least 8 characters."];
    }

    if ($password !== $confirmPassword) {
        return ["success" => false, "message" => "Passwords do not match."];
    }

    try {
        $stmt = $pdo->prepare("SELECT userId FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            return ["success" => false, "message" => "That username or email is already registered."];
        }

        [$verificationToken, $verificationHash] = nucleusVerificationToken();
        $stmt = $pdo->prepare("
            INSERT INTO users
                (username, passwordHash, fullName, email, role_id, isVerified, email_verification_token, email_verification_expires_at, email_verification_sent_at)
            SELECT ?, ?, ?, ?, role_id, 0, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW()
            FROM roles
            WHERE role_name = 'member'
        ");
        $stmt->execute([
            $username,
            Security::hashPassword($password),
            $fullName,
            $email,
            $verificationHash,
        ]);

        if ($stmt->rowCount() < 1) {
            return ["success" => false, "message" => "Default member role is missing. Please ask an administrator to check roles."];
        }

        $userId = (int) $pdo->lastInsertId();
        logActivity("user_signed_up", "New signup: {$username}", null, null, $userId);

        $emailSent = sendNucleusVerificationEmail(
            $email,
            $fullName,
            $userId,
            $verificationToken
        );

        if (!$emailSent) {
            return ["success" => true, "message" => "Account created, but the verification email could not be sent. Please ask an administrator to check SMTP settings."];
        }

        return ["success" => true, "message" => "Account created. Check your email to verify your account before logging in."];
    } catch (Throwable $e) {
        error_log("Signup error: " . $e->getMessage());
        return ["success" => false, "message" => "An error occurred while creating your account."];
    }
}
