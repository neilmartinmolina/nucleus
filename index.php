<?php
require_once __DIR__ . "/config.php";

$isAuthenticated = isset($_SESSION["userId"]) && !empty($_SESSION["userId"]);

// If already logged in, redirect to the subject home
if ($isAuthenticated) {
    header("Location: " . authenticatedHomeRedirect());
    exit;
}

$lookupQuery = trim((string) ($_GET["lookup"] ?? ""));
$lookupResults = [];
if ($lookupQuery !== "") {
    $stmt = $pdo->prepare("
        SELECT p.project_name, p.public_url, p.github_repo_name, s.subject_code, s.subject_name,
               ps.status, ps.checked_at, ps.status_note
        FROM projects p
        LEFT JOIN subjects s ON s.subject_id = p.subject_id
        LEFT JOIN project_status ps ON ps.project_id = p.project_id
        WHERE p.project_name LIKE ?
           OR p.public_url LIKE ?
           OR p.github_repo_name LIKE ?
           OR s.subject_code LIKE ?
        ORDER BY p.project_name ASC
        LIMIT 25
    ");
    $term = "%" . $lookupQuery . "%";
    $stmt->execute([$term, $term, $term, $term]);
    $lookupResults = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nucleus | Sync Your Workflow</title>
    <script src="tailwind.config.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --nucleus-primary: #0050D8;
            --nucleus-surface: #E8F5FF;
        }

        body {
            font-family: "Corpta", "Inter", ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        @keyframes meshDrift {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            33% { transform: translate3d(5%, -4%, 0) scale(1.08); }
            66% { transform: translate3d(-4%, 5%, 0) scale(0.96); }
        }

        @keyframes meshPulse {
            0%, 100% { opacity: 0.72; }
            50% { opacity: 0.95; }
        }

        .mesh-layer {
            background:
                radial-gradient(circle at 16% 20%, rgba(232, 245, 255, 0.95), transparent 28%),
                radial-gradient(circle at 80% 12%, rgba(0, 80, 216, 0.92), transparent 30%),
                radial-gradient(circle at 72% 80%, rgba(0, 54, 148, 0.92), transparent 34%),
                radial-gradient(circle at 24% 86%, rgba(101, 176, 255, 0.78), transparent 30%),
                linear-gradient(135deg, #0050D8 0%, #1F7BFF 46%, #E8F5FF 100%);
            animation: meshDrift 16s ease-in-out infinite, meshPulse 8s ease-in-out infinite;
            filter: blur(18px);
            inset: -3rem;
        }

        .mesh-grid {
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.10) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.10) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: linear-gradient(to bottom, black, transparent 82%);
        }
    </style>
</head>
<body class="bg-[#E8F5FF] text-slate-800">

    <nav class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-6 px-5 py-4 sm:px-8 lg:px-12">
            <a href="index.php" class="text-xl font-bold tracking-tight text-[#0050D8]">NUCLEUS</a>
            <div class="hidden items-center gap-8 text-sm font-medium text-slate-600 md:flex">
                <a href="#platform" class="transition hover:text-[#0050D8]">Platform</a>
                <a href="#status-lookup" class="transition hover:text-[#0050D8]">Status Lookup</a>
                <a href="#magazine" class="transition hover:text-[#0050D8]">Stories</a>
                <a href="#blog" class="transition hover:text-[#0050D8]">Blog</a>
                <a href="login.php" class="transition hover:text-[#0050D8]">Login</a>
            </div>
            <a href="signup.php" class="shrink-0 rounded-lg bg-[#0050D8] px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-[#003FA8]">Try Nucleus Free</a>
        </div>
    </nav>

    <header class="relative isolate min-h-[620px] overflow-hidden bg-[#0050D8] px-5 py-24 text-white sm:px-8 lg:px-12">
        <div class="mesh-layer absolute"></div>
        <div class="mesh-grid absolute inset-0"></div>
        <div class="absolute inset-0 bg-[#0050D8]/45"></div>
        <div class="relative mx-auto flex min-h-[430px] max-w-5xl flex-col items-center justify-center text-center">
            <span class="mb-5 rounded-full border border-white/25 bg-white/15 px-4 py-2 text-xs font-semibold uppercase text-[#E8F5FF]">Academic project tracking, synced</span>
            <h1 class="max-w-4xl text-4xl font-bold leading-tight sm:text-5xl lg:text-6xl">Sync your workflow with every commit.</h1>
            <p class="mt-6 max-w-2xl text-base leading-8 text-blue-50 sm:text-lg">Nucleus keeps subjects, projects, requests, and update status in one calm dashboard, so teams can ship changes and know what is current.</p>
            <div class="mt-9 flex flex-col items-center gap-3 sm:flex-row">
                <a href="signup.php" class="rounded-lg bg-white px-6 py-3 text-sm font-semibold text-[#0050D8] shadow-lg shadow-blue-950/20 transition hover:bg-[#E8F5FF]">Start Tracking</a>
                <a href="login.php" class="rounded-lg border border-white/25 bg-white/10 px-6 py-3 text-sm font-semibold text-white transition hover:bg-white/15">Sign In</a>
            </div>
        </div>
    </header>

    <main>
        <section id="status-lookup" class="bg-white px-5 py-16 sm:px-8 lg:px-12">
            <div class="mx-auto max-w-5xl">
                <div class="mb-6">
                    <h2 class="text-3xl font-bold text-slate-800">Website Status Lookup</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Visitors can search a project name, website URL, repository name, or subject code without signing in.</p>
                </div>
                <form method="GET" action="index.php#status-lookup" class="flex flex-col gap-3 sm:flex-row">
                    <input type="search" name="lookup" value="<?php echo htmlspecialchars($lookupQuery); ?>" placeholder="Search website status..." class="min-h-11 flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none transition focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
                    <button type="submit" class="rounded-lg bg-[#0050D8] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#003FA8]">Search</button>
                </form>
                <?php if ($lookupQuery !== ""): ?>
                <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <?php if (!$lookupResults): ?>
                    <div class="p-6 text-sm text-slate-500">No websites matched your search.</div>
                    <?php else: ?>
                    <div class="divide-y divide-slate-100">
                        <?php foreach ($lookupResults as $project): ?>
                        <article class="grid gap-3 p-5 md:grid-cols-[1fr_auto] md:items-center">
                            <div>
                                <h3 class="font-semibold text-slate-900"><?php echo htmlspecialchars($project["project_name"]); ?></h3>
                                <p class="mt-1 text-sm text-slate-500"><?php echo htmlspecialchars(($project["subject_code"] ?? "No subject") . " - " . ($project["subject_name"] ?? "Unassigned")); ?></p>
                                <?php if (!empty($project["public_url"])): ?>
                                <a href="<?php echo htmlspecialchars($project["public_url"]); ?>" target="_blank" rel="noopener noreferrer" class="mt-1 block max-w-xl truncate text-sm font-medium text-[#0050D8]"><?php echo htmlspecialchars($project["public_url"]); ?></a>
                                <?php endif; ?>
                            </div>
                            <div class="text-left md:text-right">
                                <span class="inline-flex rounded-full px-3 py-1 text-sm font-semibold status-<?php echo htmlspecialchars($project["status"] ?: "unknown"); ?>"><?php echo htmlspecialchars(ucfirst($project["status"] ?: "Unknown")); ?></span>
                                <p class="mt-2 text-xs text-slate-500">Checked <?php echo htmlspecialchars(formatNucleusDateTime($project["checked_at"])); ?></p>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <section id="platform" class="bg-white px-5 py-20 sm:px-8 lg:px-12">
            <div class="mx-auto max-w-7xl">
                <div class="max-w-3xl">
                    <h2 class="text-3xl font-bold text-slate-800 sm:text-4xl">A landing page shaped around the dashboard.</h2>
                    <p class="mt-4 text-base leading-7 text-slate-500">The same clean cards, soft borders, blue actions, and slate typography from the app carry through the public experience.</p>
                </div>
                <div class="mt-10 grid grid-cols-1 gap-5 md:grid-cols-3">
                    <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-[#0050D8] to-[#65B0FF] text-white">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h18M6 12h12M9 17h6"></path></svg>
                        </div>
                        <h3 class="text-lg font-semibold text-slate-800">Organized Subjects</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Group academic work into subjects with clear ownership and project counts.</p>
                    </article>
                    <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-[#0050D8] to-[#65B0FF] text-white">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                        </div>
                        <h3 class="text-lg font-semibold text-slate-800">Live Update Signals</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">See which projects were checked, updated, or need attention after each change.</p>
                    </article>
                    <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-[#0050D8] to-[#65B0FF] text-white">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-5-4M9 20H4v-2a4 4 0 015-4m4-8a4 4 0 11-8 0 4 4 0 018 0zm8 0a4 4 0 11-8 0"></path></svg>
                        </div>
                        <h3 class="text-lg font-semibold text-slate-800">Role-Aware Workflows</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Keep requests, handlers, and admins moving through the same focused system.</p>
                    </article>
                </div>
            </div>
        </section>

        <section id="magazine" class="bg-[#E8F5FF] px-5 py-20 sm:px-8 lg:px-12">
            <div class="mx-auto max-w-7xl">
                <div class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div>
                        <h2 class="text-3xl font-bold text-slate-800">From the Nucleus Journal</h2>
                        <p class="mt-2 text-sm text-slate-500">Magazine-style notes for teams keeping project work tidy.</p>
                    </div>
                    <a href="#blog" class="text-sm font-semibold text-[#0050D8] hover:text-[#003FA8]">Browse all posts</a>
                </div>
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
                        <div class="h-56 bg-gradient-to-br from-[#0050D8] via-[#65B0FF] to-[#E8F5FF]"></div>
                        <div class="p-6">
                            <span class="text-xs font-semibold uppercase text-[#0050D8]">Featured</span>
                            <h3 class="mt-3 text-2xl font-bold text-slate-800">How project status tracking keeps academic teams aligned</h3>
                            <p class="mt-3 text-sm leading-6 text-slate-500">A practical look at using update checks, request queues, and clear ownership to reduce handoff friction.</p>
                        </div>
                    </article>
                    <div class="grid grid-cols-1 gap-6">
                        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                            <span class="text-xs font-semibold uppercase text-slate-400">Workflow</span>
                            <h3 class="mt-3 text-lg font-semibold text-slate-800">Designing subject spaces for recurring work</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-500">Simple naming, access, and maintenance habits that scale.</p>
                        </article>
                        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                            <span class="text-xs font-semibold uppercase text-slate-400">Monitoring</span>
                            <h3 class="mt-3 text-lg font-semibold text-slate-800">What to check before marking a project updated</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-500">A quick read for handlers reviewing live changes.</p>
                        </article>
                    </div>
                </div>
            </div>
        </section>

        <section id="blog" class="bg-white px-5 py-20 sm:px-8 lg:px-12">
            <div class="mx-auto max-w-7xl">
                <div class="mb-6 flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
                    <div>
                        <h2 class="text-3xl font-bold text-slate-800">Project Notes</h2>
                        <p class="mt-2 text-sm text-slate-500">Search the visible card grid by topic, role, or workflow.</p>
                    </div>
                    <label class="w-full max-w-md">
                        <span class="mb-2 block text-sm font-medium text-slate-700">Search Posts</span>
                        <input id="postSearch" type="search" placeholder="Search posts..." class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-[#0050D8] focus:ring-2 focus:ring-[#0050D8]/20">
                    </label>
                </div>
                <div id="postGrid" class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                    <article class="post-card rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md" data-search="requests approvals workflow academic teams">
                        <span class="text-xs font-semibold uppercase text-[#0050D8]">Requests</span>
                        <h3 class="mt-3 text-lg font-semibold text-slate-800">Turning student requests into approved project spaces</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Keep the queue readable while giving each request the right reviewer and next step.</p>
                    </article>
                    <article class="post-card rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md" data-search="github webhook commits automation deployments">
                        <span class="text-xs font-semibold uppercase text-[#0050D8]">Automation</span>
                        <h3 class="mt-3 text-lg font-semibold text-slate-800">Using webhook updates without losing manual control</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Blend automated checks with human review when production confidence matters.</p>
                    </article>
                    <article class="post-card rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md" data-search="handlers roles permissions admin access">
                        <span class="text-xs font-semibold uppercase text-[#0050D8]">Roles</span>
                        <h3 class="mt-3 text-lg font-semibold text-slate-800">A cleaner handoff between admins and handlers</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Use permissions to keep repeated actions fast and sensitive actions guarded.</p>
                    </article>
                    <article class="post-card rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md" data-search="monitoring status checks reliability">
                        <span class="text-xs font-semibold uppercase text-[#0050D8]">Monitoring</span>
                        <h3 class="mt-3 text-lg font-semibold text-slate-800">Reading status checks at a glance</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Make project health easy to scan before a meeting or release window.</p>
                    </article>
                    <article class="post-card rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md" data-search="subjects folders organization project count">
                        <span class="text-xs font-semibold uppercase text-[#0050D8]">Subjects</span>
                        <h3 class="mt-3 text-lg font-semibold text-slate-800">When subject folders become operational hubs</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Use descriptions, project counts, and ownership cues to reduce searching.</p>
                    </article>
                    <article class="post-card rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md" data-search="activity logs audit history accountability">
                        <span class="text-xs font-semibold uppercase text-[#0050D8]">Logs</span>
                        <h3 class="mt-3 text-lg font-semibold text-slate-800">Why activity history matters after the update</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">A brief audit trail turns scattered status questions into quick answers.</p>
                    </article>
                </div>
                <div id="postEmptyState" class="mt-6 hidden rounded-xl border border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-500">No posts match your search.</div>
            </div>
        </section>
    </main>

    <footer class="border-t border-slate-200 bg-white px-5 py-6 sm:px-8 lg:px-12">
        <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-4 text-sm text-slate-500 md:flex-row">
            <a href="index.php" class="font-bold tracking-tight text-[#0050D8]">NUCLEUS</a>
            <p class="max-w-2xl text-center leading-6 md:text-left">Privacy notice: Nucleus uses account and project information only to manage access, requests, status checks, and workflow updates.</p>
            <p>&copy; <?php echo date("Y"); ?> Nucleus. All rights reserved.</p>
        </div>
    </footer>

    <script>
        (function() {
            const input = document.getElementById('postSearch');
            const cards = Array.from(document.querySelectorAll('.post-card'));
            const empty = document.getElementById('postEmptyState');
            if (!input || !cards.length) return;

            input.addEventListener('input', function() {
                const query = this.value.trim().toLowerCase();
                let visible = 0;

                cards.forEach(card => {
                    const haystack = `${card.dataset.search || ''} ${card.textContent}`.toLowerCase();
                    const matches = !query || haystack.includes(query);
                    card.classList.toggle('hidden', !matches);
                    if (matches) visible++;
                });

                if (empty) empty.classList.toggle('hidden', visible !== 0);
            });
        })();
    </script>
    <style>
        .status-initializing { background:#e0f2fe; color:#075985; }
        .status-building { background:#fef3c7; color:#92400e; }
        .status-deployed { background:#d1fae5; color:#065f46; }
        .status-warning { background:#ffedd5; color:#9a3412; }
        .status-error { background:#fee2e2; color:#991b1b; }
        .status-unknown { background:#f1f5f9; color:#475569; }
    </style>
</body>
</html>
