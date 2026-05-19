<?php
require_once __DIR__ . "/includes/core.php";

// Redirect if already logged in
if (isAuthenticated()) {
    header("Location: " . authenticatedHomeRedirect());
    exit;
}

// Initialize session security
Security::secureSession();

$error = null;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate CSRF token
    validateCSRF($_POST["csrf_token"] ?? "");
    
    // Rate limiting
    if (!Security::checkRateLimit($_SERVER["REMOTE_ADDR"])) {
        $error = "Too many login attempts. Please wait before trying again.";
    } else {
        // Sanitize input
        $login = trim((string) ($_POST["login"] ?? ($_POST["username"] ?? "")));
        $password = $_POST["password"];
        
        // Validate input
        $errors = [];
        if ($login === "" || strlen($login) > 255) {
            $errors[] = "Enter your username or email address";
        }
        if (empty($password)) {
            $errors[] = "Password is required";
        }
        
        if (empty($errors)) {
            // Use prepared statement for login
            try {
                $stmt = $pdo->prepare("
                    SELECT u.*, r.role_name AS role
                    FROM users u
                    JOIN roles r ON r.role_id = u.role_id
                    WHERE u.username = ? OR u.email = ?
                ");
                $stmt->execute([$login, $login]);
                $user = $stmt->fetch();
                
                if ($user && Security::verifyPassword($password, $user["passwordHash"])) {
                    if ((int) ($user["isVerified"] ?? 0) !== 1) {
                        $error = "Please verify your email address before logging in.";
                    } else {
                    // Set session data
                    $_SESSION["userId"] = $user["userId"];
                    $_SESSION["fullName"] = $user["fullName"];
                    $_SESSION["role"] = $user["role"];
                    $_SESSION["user_logged_in"] = true;
                    $_SESSION["last_activity"] = time();
                    
                    // Log successful login
                    $stmt = $pdo->prepare("INSERT INTO login_attempts (username, ip_address, success, created_at) VALUES (?, ?, 1, NOW())");
                    $stmt->execute([$user["username"], $_SERVER["REMOTE_ADDR"]]);
                    
                    // Regenerate session ID
                    session_regenerate_id(true);
                    
                    header("Location: " . homeRedirectForRole($user["role"]));
                    exit;
                    }
                } else {
                    // Log failed login
                    $stmt = $pdo->prepare("INSERT INTO login_attempts (username, ip_address, success, created_at) VALUES (?, ?, 0, NOW())");
                    $stmt->execute([$login, $_SERVER["REMOTE_ADDR"]]);
                    
                    $error = "Invalid username or password";
                }
            } catch (Exception $e) {
                $error = "An error occurred during login";
                error_log("Login error: " . $e->getMessage());
            }
        } else {
            $error = reset($errors);
        }
    }
}

generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Nucleus</title>
    <link rel="stylesheet" href="assets/css/nucleus.css">
    <style>
        body {
            font-family: "Corpta", "Inter", ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .auth-visual {
            background:
                radial-gradient(circle at 20% 18%, rgba(232, 245, 255, 0.95), transparent 26%),
                radial-gradient(circle at 82% 22%, rgba(101, 176, 255, 0.78), transparent 30%),
                linear-gradient(135deg, #0050D8 0%, #1F7BFF 58%, #E8F5FF 100%);
        }
    </style>
</head>
<body class="min-h-screen bg-[#E8F5FF] text-slate-800">
    <main class="min-h-screen p-4 sm:p-6 lg:p-8">
        <div class="mx-auto grid min-h-[calc(100vh-2rem)] max-w-6xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm sm:min-h-[calc(100vh-3rem)] lg:min-h-[calc(100vh-4rem)] lg:grid-cols-2">
            <section class="flex items-center px-6 py-10 sm:px-10 lg:px-14">
                <div class="w-full max-w-md">
                    <a href="index.php" class="text-xl font-bold tracking-tight text-[#0050D8]">NUCLEUS</a>
                    <div class="mt-10">
                        <p class="text-sm font-semibold uppercase text-[#0050D8]">Welcome back</p>
                        <h1 class="mt-3 text-3xl font-bold leading-tight text-slate-900 sm:text-4xl">Log in to your workspace.</h1>
                        <p class="mt-4 text-sm leading-6 text-slate-500">Access your dashboard, review project status, and keep every subject moving.</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" class="mt-8 space-y-5">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Email or Username</label>
                            <input type="text" name="login" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20" placeholder="you@example.com or username" required autofocus autocomplete="username">
                        </div>

                        <div>
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <label class="block text-sm font-medium text-slate-700">Password</label>
                                <a href="password_reset.php" class="text-sm font-medium text-[#0050D8] hover:text-[#003FA8]">Forgot?</a>
                            </div>
                            <input type="password" name="password" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20" placeholder="Enter your password" required>
                        </div>

                        <button type="submit" class="w-full rounded-lg bg-[#0050D8] px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#003FA8]">Log In</button>
                    </form>

                    <p class="mt-6 text-center text-sm text-slate-500">
                        New to Nucleus?
                        <a href="signup.php" class="font-semibold text-[#0050D8] hover:text-[#003FA8]">Create account</a>
                    </p>
                </div>
            </section>

            <section class="auth-visual relative hidden overflow-hidden p-10 text-white lg:flex lg:items-center">
                <div class="absolute inset-0 bg-[#0050D8]/20"></div>
                <div class="relative w-full">
                    <div class="mb-8 max-w-md">
                        <p class="text-sm font-semibold uppercase text-[#E8F5FF]">Live project clarity</p>
                        <h2 class="mt-3 text-3xl font-bold leading-tight">See updates, requests, and subjects in one focused dashboard.</h2>
                    </div>
                    <div class="rounded-2xl border border-white/25 bg-white/15 p-4 shadow-2xl shadow-blue-950/20 backdrop-blur">
                        <div class="rounded-xl bg-white p-5 text-slate-800">
                            <div class="mb-5 flex items-center justify-between border-b border-slate-100 pb-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase text-[#0050D8]">Dashboard</p>
                                    <h3 class="mt-1 text-lg font-bold">Project Health</h3>
                                </div>
                                <span class="rounded-full bg-[#E8F5FF] px-3 py-1 text-xs font-semibold text-[#0050D8]">Synced</span>
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                    <p class="text-2xl font-bold text-[#0050D8]">24</p>
                                    <p class="mt-1 text-xs text-slate-500">Projects</p>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                    <p class="text-2xl font-bold text-[#0050D8]">8</p>
                                    <p class="mt-1 text-xs text-slate-500">Subjects</p>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                    <p class="text-2xl font-bold text-[#0050D8]">3</p>
                                    <p class="mt-1 text-xs text-slate-500">Requests</p>
                                </div>
                            </div>
                            <div class="mt-5 space-y-3">
                                <div class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2">
                                    <span class="text-sm font-medium">Capstone Portal</span>
                                    <span class="text-xs font-semibold text-emerald-600">Updated</span>
                                </div>
                                <div class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2">
                                    <span class="text-sm font-medium">Research Archive</span>
                                    <span class="text-xs font-semibold text-[#0050D8]">Checking</span>
                                </div>
                                <div class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2">
                                    <span class="text-sm font-medium">Lab Tracker</span>
                                    <span class="text-xs font-semibold text-amber-600">Review</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
