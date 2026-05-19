<?php
require_once __DIR__ . "/includes/core.php";

$status = "error";
$title = "Verification link invalid";
$message = "This verification link is invalid or has already been used.";
$redirectUrl = "login.php";

$userId = isset($_GET["user"]) && is_numeric($_GET["user"]) ? (int) $_GET["user"] : 0;
$token = (string) ($_GET["token"] ?? "");

if ($userId > 0 && $token !== "") {
    try {
        $stmt = $pdo->prepare("
            SELECT u.userId, u.isVerified, u.email_verified_at, u.email_verification_token, u.email_verification_expires_at, r.role_name AS role
            FROM users u
            JOIN roles r ON r.role_id = u.role_id
            WHERE u.userId = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user && (int) ($user["isVerified"] ?? 0) === 1) {
            $status = "success";
            $title = "Email already verified";
            $message = "Your account is already verified. Redirecting you now.";
            $redirectUrl = homeRedirectForRole($user["role"] ?? null);
        } elseif (
            $user
            && !empty($user["email_verification_token"])
            && hash_equals((string) $user["email_verification_token"], hash("sha256", $token))
            && strtotime((string) $user["email_verification_expires_at"]) >= time()
        ) {
            $stmt = $pdo->prepare("
                UPDATE users
                SET isVerified = 1,
                    email_verified_at = NOW(),
                    email_verification_token = NULL,
                    email_verification_expires_at = NULL
                WHERE userId = ?
            ");
            $stmt->execute([$userId]);
            $_SESSION["userId"] = (int) $user["userId"];
            $_SESSION["role"] = $user["role"];
            $_SESSION["user_logged_in"] = true;
            $_SESSION["last_activity"] = time();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            logActivity("email_verified", "User verified email address", null, null, $userId);

            $status = "success";
            $title = "Your account is now verified";
            $message = "Your account is now verified. Redirecting you to your Nucleus workspace.";
            $redirectUrl = homeRedirectForRole($user["role"] ?? null);
        } elseif ($user) {
            $title = "Verification link expired";
            $message = "This verification link has expired. Please ask an administrator to resend verification or create a new account request.";
        }
    } catch (Throwable $e) {
        error_log("Email verification failed: " . $e->getMessage());
        $title = "Verification unavailable";
        $message = "We could not verify your email right now. Please try again later.";
    }
}

$isSuccess = $status === "success";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - Nucleus</title>
    <?php if ($isSuccess): ?>
    <meta http-equiv="refresh" content="3;url=<?= htmlspecialchars($redirectUrl, ENT_QUOTES, "UTF-8") ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="assets/css/nucleus.css">
    <?php if ($isSuccess): ?>
    <script>
        window.setTimeout(function () {
            window.location.href = <?= json_encode($redirectUrl) ?>;
        }, 2500);
    </script>
    <?php endif; ?>
</head>
<body class="min-h-screen bg-[#E8F5FF] text-slate-800">
    <main class="flex min-h-screen items-center justify-center p-6">
        <section class="w-full max-w-lg overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="bg-[#0050D8] px-8 py-7 text-white">
                <p class="text-sm font-bold uppercase tracking-widest text-[#E8F5FF]">NUCLEUS</p>
                <h1 class="mt-3 text-3xl font-bold"><?= htmlspecialchars($title) ?></h1>
            </div>
            <div class="p-8">
                <div class="rounded-xl border <?= $isSuccess ? "border-emerald-200 bg-emerald-50 text-emerald-800" : "border-amber-200 bg-amber-50 text-amber-800" ?> p-4 text-sm leading-6">
                    <?= htmlspecialchars($message) ?>
                </div>
                <?php if ($isSuccess): ?>
                    <p class="mt-4 text-center text-sm text-slate-500">Redirecting in a moment...</p>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($isSuccess ? $redirectUrl : "login.php", ENT_QUOTES, "UTF-8") ?>" class="mt-6 inline-flex w-full justify-center rounded-lg bg-[#0050D8] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#003FA8]"><?= $isSuccess ? "Continue" : "Go to Login" ?></a>
            </div>
        </section>
    </main>
</body>
</html>
