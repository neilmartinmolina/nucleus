<?php
require_once __DIR__ . "/handlers/signup_handler.php";

if (isAuthenticated()) {
    header("Location: " . authenticatedHomeRedirect());
    exit;
}

$error = null;
$success = null;
$form = [
    "username" => "",
    "fullName" => "",
    "email" => "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    validateCSRF($_POST["csrf_token"] ?? "");

    $form["username"] = trim((string) ($_POST["username"] ?? ""));
    $form["fullName"] = trim((string) ($_POST["fullName"] ?? ""));
    $form["email"] = trim((string) ($_POST["email"] ?? ""));

    $result = registerUser($_POST);
    if ($result["success"]) {
        $success = $result["message"];
        $form = ["username" => "", "fullName" => "", "email" => ""];
    } else {
        $error = $result["message"];
    }
}

generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Nucleus</title>
    <script src="tailwind.config.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: "Corpta", "Inter", ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .auth-visual {
            background:
                radial-gradient(circle at 24% 22%, rgba(232, 245, 255, 0.95), transparent 26%),
                radial-gradient(circle at 78% 18%, rgba(101, 176, 255, 0.76), transparent 30%),
                linear-gradient(135deg, #0050D8 0%, #1F7BFF 58%, #E8F5FF 100%);
        }
    </style>
</head>
<body class="min-h-screen bg-[#E8F5FF] text-slate-800">
    <main class="min-h-screen p-4 sm:p-6 lg:p-8">
        <div class="mx-auto grid min-h-[calc(100vh-2rem)] max-w-6xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm sm:min-h-[calc(100vh-3rem)] lg:min-h-[calc(100vh-4rem)] lg:grid-cols-2">
            <section class="auth-visual relative hidden overflow-hidden p-10 text-white lg:flex lg:items-center">
                <div class="absolute inset-0 bg-[#0050D8]/20"></div>
                <div class="relative w-full">
                    <div class="mb-8 max-w-md">
                        <p class="text-sm font-semibold uppercase text-[#E8F5FF]">Start organized</p>
                        <h2 class="mt-3 text-3xl font-bold leading-tight">Create a workspace identity for projects, subjects, and requests.</h2>
                    </div>
                    <div class="rounded-2xl border border-white/25 bg-white/15 p-4 shadow-2xl shadow-blue-950/20 backdrop-blur">
                        <div class="rounded-xl bg-white p-5 text-slate-800">
                            <div class="mb-5 flex items-center justify-between border-b border-slate-100 pb-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase text-[#0050D8]">Account Setup</p>
                                    <h3 class="mt-1 text-lg font-bold">Ready for review</h3>
                                </div>
                                <span class="rounded-full bg-[#E8F5FF] px-3 py-1 text-xs font-semibold text-[#0050D8]">Member</span>
                            </div>
                            <div class="space-y-3">
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-[#0050D8] text-sm font-bold text-white">JD</div>
                                        <div>
                                            <p class="text-sm font-semibold">Juan Dela Cruz</p>
                                            <p class="text-xs text-slate-500">Member account</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs font-semibold uppercase text-slate-400">Access</p>
                                        <p class="mt-2 text-sm font-semibold text-slate-800">Request projects</p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs font-semibold uppercase text-slate-400">Status</p>
                                        <p class="mt-2 text-sm font-semibold text-[#0050D8]">Pending approval</p>
                                    </div>
                                </div>
                                <div class="rounded-lg border border-slate-100 px-3 py-2 text-sm text-slate-500">
                                    Admins can promote roles and connect users to the right subject spaces.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="flex items-center px-6 py-10 sm:px-10 lg:px-14">
                <div class="w-full max-w-md">
                    <a href="index.php" class="text-xl font-bold tracking-tight text-[#0050D8]">NUCLEUS</a>
                    <div class="mt-10">
                        <p class="text-sm font-semibold uppercase text-[#0050D8]">Create account</p>
                        <h1 class="mt-3 text-3xl font-bold leading-tight text-slate-900 sm:text-4xl">Join your Nucleus workspace.</h1>
                        <p class="mt-4 text-sm leading-6 text-slate-500">Set up your account so your project requests and updates stay tied to one profile.</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>

                    <form method="POST" class="mt-8 space-y-4" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Full Name</label>
                            <input type="text" name="fullName" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20" value="<?= htmlspecialchars($form["fullName"]) ?>" placeholder="Juan Dela Cruz" required>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Username</label>
                                <input type="text" name="username" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20" value="<?= htmlspecialchars($form["username"]) ?>" placeholder="juandelacruz" required>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Email</label>
                                <input type="email" name="email" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20" value="<?= htmlspecialchars($form["email"]) ?>" placeholder="you@example.com" required>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Password</label>
                                <input type="password" name="password" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20" placeholder="At least 8 characters" required>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Confirm Password</label>
                                <input type="password" name="confirm_password" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20" placeholder="Re-enter password" required>
                            </div>
                        </div>

                        <button type="submit" class="w-full rounded-lg bg-[#0050D8] px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#003FA8]">Sign Up</button>
                    </form>

                    <p class="mt-6 text-center text-sm text-slate-500">
                        Already have an account?
                        <a href="login.php" class="font-semibold text-[#0050D8] hover:text-[#003FA8]">Log in</a>
                    </p>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
