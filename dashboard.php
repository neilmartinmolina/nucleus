<?php
require_once __DIR__ . "/config.php";

// If not authenticated, redirect to landing page
if (!isAuthenticated()) {
    header("Location: index.php");
    exit;
}

// Get user info
$userId = $_SESSION["userId"];
$stmt = $pdo->prepare("
    SELECT u.*, r.role_name AS role
    FROM users u
    JOIN roles r ON r.role_id = u.role_id
    WHERE u.userId = ?
");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch();
$currentRole = $currentUser["role"] ?? "visitor";
generateCSRFToken();

$navFeatures = [
    "dashboard" => isFeatureEnabled("dashboard"),
    "folders" => isFeatureEnabled("subjects"),
    "websites" => isFeatureEnabled("projects"),
    "files" => isFeatureEnabled("files"),
    "requests" => isFeatureEnabled("requests"),
    "settings" => isFeatureEnabled("settings"),
    "alerts" => isFeatureEnabled("alerts"),
    "logs" => isFeatureEnabled("logs"),
];

$dashboardPayload = [
    "dataTables" => [
        ["selector" => "#recentActivityTable", "order" => [[2, "desc"]], "disabledTargets" => [], "placeholder" => "Search activity..."],
        ["selector" => "#dashboardProjectsTable", "order" => [[0, "asc"]], "disabledTargets" => hasPermission("update_project") ? [3] : [], "placeholder" => "Search projects..."],
        ["selector" => "#projectsTable", "order" => [[5, "desc"]], "disabledTargets" => [7], "placeholder" => "Search projects..."],
        ["selector" => "#usersTable", "order" => [[0, "asc"]], "disabledTargets" => [4], "placeholder" => "Search users..."],
        ["selector" => "#websiteRequestsTable", "order" => [], "disabledTargets" => [], "placeholder" => "Search website requests..."],
        ["selector" => "#subjectJoinRequestsTable", "order" => [[4, "desc"]], "disabledTargets" => [], "placeholder" => "Search subject join requests..."],
        ["selector" => "#subjectRequestsTable", "order" => [[4, "desc"]], "disabledTargets" => [], "placeholder" => "Search subject requests..."],
        ["selector" => "#activityLogsTable", "order" => [[5, "desc"]], "disabledTargets" => [], "placeholder" => "Search logs..."],
        ["selector" => "#projectChecksTable", "order" => [[0, "desc"]], "disabledTargets" => [], "placeholder" => "Search checks..."],
        ["selector" => "#alertsTable", "order" => [[6, "desc"]], "disabledTargets" => [0], "placeholder" => "Search alerts..."],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nucleus | Dashboard</title>
    <script src="tailwind.config.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/2.3.8/css/dataTables.tailwindcss.min.css">
    <style>
        .nav-item.active {
            background-color: rgba(4, 56, 115, 0.1);
            color: #043873;
            font-weight: 600;
        }
        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background-color: #043873;
            border-radius: 0 4px 4px 0;
        }
        .nav-icon {
            width: 1.25rem;
            height: 1.25rem;
            display: inline-block;
            flex: 0 0 auto;
            margin-right: 0.75rem;
            vertical-align: -0.25rem;
        }
        .nav-group.is-open > .nav-submenu {
            display: block;
        }
        .nav-group.is-open > .nav-item {
            background-color: rgba(4, 56, 115, 0.06);
            color: #043873;
            font-weight: 600;
        }
        .nav-submenu {
            display: none;
            margin: 0.25rem 0 0.25rem 1.75rem;
            padding-left: 0.75rem;
            border-left: 1px solid #e2e8f0;
        }
        .nav-subitem {
            display: block;
            margin-top: 0.25rem;
            padding: 0.55rem 0.75rem;
            border-radius: 0.5rem;
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 600;
            transition: background-color 150ms ease, color 150ms ease;
        }
        .nav-subitem:hover,
        .nav-subitem.active {
            background-color: rgba(4, 56, 115, 0.1);
            color: #043873;
        }
        .nav-subitem.active::before {
            display: none;
        }
        .dt-container {
            color: #334155;
            font-size: 0.875rem;
        }
        .dt-container .dt-layout-row {
            align-items: center;
            gap: 1rem;
            margin: 0;
            padding: 1.125rem 1.5rem;
        }
        .dt-container .dt-layout-row:first-child {
            border-bottom: 1px solid #f1f5f9;
        }
        .dt-container .dt-layout-row:last-child {
            border-top: 1px solid #f1f5f9;
            padding-top: 1rem;
            padding-bottom: 1.25rem;
        }
        .dt-container .dt-length,
        .dt-container .dt-search,
        .dt-container .dt-info {
            color: #64748b;
            font-size: 0.8125rem;
        }
        .dt-container .dt-search label,
        .dt-container .dt-length label {
            display: inline-flex;
            align-items: center;
            gap: 30px;
            font-weight: 500;
        }
        .dt-container .dt-search input,
        .dt-container .dt-length select {
            min-height: 2.25rem;
            border: 1px solid #dbe4ef;
            border-radius: 0.5rem;
            background: #f8fafc;
            color: #334155;
            font-size: 0.875rem;
            outline: none;
            transition: border-color 150ms ease, box-shadow 150ms ease, background-color 150ms ease;
        }
        .dt-container .dt-search input {
            min-width: 15rem;
            padding: 0.5rem 0.75rem;
        }
        .dt-container .dt-length select {
            padding: 0.375rem 2rem 0.375rem 0.75rem;
        }
        .dt-container .dt-search input:focus,
        .dt-container .dt-length select:focus {
            border-color: #4F9CF9;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(79, 156, 249, 0.16);
        }
        .dt-container table.dataTable {
            width: 100% !important;
            border-collapse: separate !important;
            border-spacing: 0;
        }
        .dt-container table.dataTable > thead > tr > th {
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #475569;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0;
            padding-top: 0.875rem;
            padding-bottom: 0.875rem;
            padding-left: 1rem;
            padding-right: 1rem;
            text-transform: uppercase;
            white-space: normal;
        }
        .dt-container table.dataTable > tbody > tr {
            background: #ffffff;
            transition: background-color 150ms ease;
        }
        .dt-container table.dataTable > tbody > tr:hover {
            background: #f8fafc;
        }
        .dt-container table.dataTable > tbody > tr > td {
            border-bottom: 1px solid #f1f5f9;
            padding-top: 0.875rem;
            padding-bottom: 0.875rem;
            padding-left: 1rem;
            padding-right: 1rem;
            vertical-align: middle;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: normal;
        }
        .dt-container table.dataTable > thead > tr > th:first-child,
        .dt-container table.dataTable > tbody > tr > td:first-child {
            padding-left: 1.5rem;
        }
        .dt-container table.dataTable > thead > tr > th:last-child,
        .dt-container table.dataTable > tbody > tr > td:last-child {
            padding-right: 1.5rem;
        }
        .dt-container table.dataTable > tbody > tr:last-child > td {
            border-bottom: 0;
        }
        .dt-container .dt-paging {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            padding-right: 0.125rem;
        }
        .dt-container .dt-paging .dt-paging-button {
            min-width: 2rem;
            border: 1px solid #bfdbfe !important;
            border-radius: 0.5rem !important;
            background: #ffffff !important;
            color: #043873 !important;
            font-size: 0.8125rem;
            line-height: 1.25rem;
            padding: 0.375rem 0.625rem !important;
            transition: background-color 150ms ease, border-color 150ms ease, color 150ms ease;
        }
        .dt-container .dt-paging .dt-paging-button.current {
            background: #e0f2fe !important;
            border-color: #4F9CF9 !important;
            color: #043873 !important;
            font-weight: 700;
        }
        .dt-container .dt-paging .dt-paging-button:not(.disabled):hover {
            background: #043873 !important;
            border-color: #043873 !important;
            color: #ffffff !important;
        }
        .dt-container .dt-paging .dt-paging-button.disabled {
            color: #cbd5e1 !important;
            cursor: not-allowed !important;
        }
        .dt-scroll-body {
            border-bottom: 0 !important;
            overflow-x: hidden !important;
        }
        .dt-container .data-table a,
        .dt-container .data-table button,
        .dt-container .data-table span {
            max-width: 100%;
        }
        .dt-container .data-table td:last-child .flex {
            flex-wrap: wrap;
        }
        .nucleus-table-inner .dt-layout-row {
            padding-left: 2rem;
            padding-right: 2rem;
        }
        .nucleus-table-inner .dt-layout-table {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }
        .dashboard-sidebar {
            transition: transform 180ms ease;
        }
        .mobile-nav-backdrop {
            display: none;
        }
        @media (max-width: 767px) {
            .dashboard-shell {
                min-width: 0;
            }
            .dashboard-sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                z-index: 50;
                width: min(18rem, 86vw);
                transform: translateX(-100%);
                box-shadow: 0 20px 45px rgba(15, 23, 42, 0.25);
            }
            body.sidebar-open .dashboard-sidebar {
                transform: translateX(0);
            }
            body.sidebar-open .mobile-nav-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                z-index: 40;
                background: rgba(15, 23, 42, 0.42);
            }
            .dt-container .dt-layout-row {
                align-items: stretch;
                flex-direction: column;
                padding: 0.875rem;
            }
            .dt-container .dt-layout-row:first-child,
            .dt-container .dt-layout-row:last-child {
                gap: 0.75rem;
            }
            .dt-container .dt-search label,
            .dt-container .dt-length label {
                align-items: stretch;
                flex-direction: column;
                gap: 0.375rem;
                width: 100%;
            }
            .dt-container .dt-search input,
            .dt-container .dt-length select {
                min-width: 0;
                width: 100%;
            }
            .dt-container .dt-paging {
                flex-wrap: wrap;
                justify-content: flex-start;
            }
            .dt-container table.dataTable {
                min-width: 48rem;
            }
            .dt-scroll-body {
                overflow-x: auto !important;
            }
            .nucleus-table-inner .dt-layout-row {
                padding-left: 0.75rem;
                padding-right: 0.75rem;
            }
            .nucleus-table-inner .dt-layout-table {
                overflow-x: auto;
                padding-left: 0;
                padding-right: 0;
            }
            #pageContent section,
            #pageContent form,
            #pageContent aside,
            #pageContent .rounded-xl {
                max-width: 100%;
            }
            #pageContent input,
            #pageContent select,
            #pageContent textarea,
            #pageContent button,
            #pageContent a {
                max-width: 100%;
            }
            #pageContent code {
                white-space: pre-wrap;
                word-break: break-word;
            }
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans">

    <div class="mobile-nav-backdrop" data-mobile-nav-close></div>

    <div class="dashboard-shell flex h-screen overflow-hidden">

        <!-- Sidebar -->
        <aside id="dashboardSidebar" class="dashboard-sidebar w-64 bg-white border-r border-slate-200 flex flex-col">
            <!-- Logo -->
            <div class="h-16 flex items-center justify-between px-6 border-b border-slate-200">
                <div class="text-xl font-bold tracking-tight text-navy">NUCLEUS</div>
                <button type="button" data-mobile-nav-close class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 md:hidden" aria-label="Close navigation">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <!-- Nav Links -->
            <nav class="flex-1 overflow-y-auto py-6 px-3 space-y-1">
                <?php if ($navFeatures["dashboard"] || isAdminLike()): ?>
                <a href="?page=dashboard" class="nav-item block px-4 py-3 rounded-lg text-slate-600 hover:bg-slate-50 hover:text-navy transition relative <?php echo $navFeatures["dashboard"] ? "" : "opacity-60"; ?>" data-page="dashboard">
                    <svg class="w-5 h-5 inline-block mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    Dashboard
                </a>
                <?php endif; ?>
                <?php if ($navFeatures["folders"] || isAdminLike()): ?>
                <div class="nav-group" data-nav-group-pages="folders,create-subject,view-folder">
                    <a href="?page=folders" class="nav-item block px-4 py-3 rounded-lg text-slate-600 hover:bg-slate-50 hover:text-navy transition relative <?php echo $navFeatures["folders"] ? "" : "opacity-60"; ?>" data-page="folders">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6.5A2.5 2.5 0 016.5 4H10l2 2h5.5A2.5 2.5 0 0120 8.5v8A2.5 2.5 0 0117.5 19h-11A2.5 2.5 0 014 16.5v-10z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 9h16"></path></svg>
                        Subjects
                    </a>
                    <?php if (hasPermission("manage_groups")): ?>
                    <div class="nav-submenu">
                        <a href="?page=create-subject" class="nav-item nav-subitem" data-page="create-subject">Add Subject</a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($navFeatures["websites"] || isAdminLike()): ?>
                <div class="nav-group" data-nav-group-pages="websites,create-project,project-form,project-details">
                    <a href="?page=websites" class="nav-item block px-4 py-3 rounded-lg text-slate-600 hover:bg-slate-50 hover:text-navy transition relative <?php echo $navFeatures["websites"] ? "" : "opacity-60"; ?>" data-page="websites">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>
                        Projects
                    </a>
                    <?php if (hasPermission("create_project")): ?>
                    <div class="nav-submenu">
                        <a href="?page=create-project" class="nav-item nav-subitem" data-page="create-project">Add Project</a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if (($navFeatures["files"] && canManageFiles()) || isAdminLike()): ?>
                <a href="?page=files" class="nav-item block px-4 py-3 rounded-lg text-slate-600 hover:bg-slate-50 hover:text-navy transition relative <?php echo $navFeatures["files"] ? "" : "opacity-60"; ?>" data-page="files">
                    <svg class="w-5 h-5 inline-block mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7a2 2 0 012-2h5l2 2h7a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"></path></svg>
                    Files
                </a>
                <?php endif; ?>
                <?php if (hasPermission("manage_users")): ?>
                <a href="?page=usermanagement" class="nav-item block px-4 py-3 rounded-lg text-slate-600 hover:bg-slate-50 hover:text-navy transition relative" data-page="usermanagement">
                    <svg class="w-5 h-5 inline-block mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    Users
                </a>
                <?php endif; ?>
                <?php if ($navFeatures["requests"] || isAdminLike()): ?>
                <a href="?page=requests" class="nav-item block px-4 py-3 rounded-lg text-slate-600 hover:bg-slate-50 hover:text-navy transition relative <?php echo $navFeatures["requests"] ? "" : "opacity-60"; ?>" data-page="requests">
                    <svg class="w-5 h-5 inline-block mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h8M8 14h5m8-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Requests
                </a>
                <?php endif; ?>
                <?php if (isAdminLike() && ($navFeatures["settings"] || isAdminLike())): ?>
                <a href="?page=settings" class="nav-item block px-4 py-3 rounded-lg text-slate-600 hover:bg-slate-50 hover:text-navy transition relative <?php echo $navFeatures["settings"] ? "" : "opacity-60"; ?>" data-page="settings">
                    <svg class="w-5 h-5 inline-block mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.607 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    Settings
                </a>
                <?php endif; ?>
                <?php if (($navFeatures["alerts"] && canManageFiles()) || isAdminLike()): ?>
                <a href="?page=alerts" class="nav-item block px-4 py-3 rounded-lg text-slate-600 hover:bg-slate-50 hover:text-navy transition relative <?php echo $navFeatures["alerts"] ? "" : "opacity-60"; ?>" data-page="alerts">
                    <svg class="w-5 h-5 inline-block mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path></svg>
                    Alerts
                </a>
                <?php endif; ?>
                <?php if (hasPermission("view_activity_logs") && ($navFeatures["logs"] || isAdminLike())): ?>
                <a href="?page=logs" class="nav-item block px-4 py-3 rounded-lg text-slate-600 hover:bg-slate-50 hover:text-navy transition relative <?php echo $navFeatures["logs"] ? "" : "opacity-60"; ?>" data-page="logs">
                    <svg class="w-5 h-5 inline-block mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Logs
                </a>
                <?php endif; ?>
            </nav>

            <!-- User mini profile -->
            <div class="p-4 border-t border-slate-200">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-navy text-white flex items-center justify-center text-sm font-semibold">
                        <?php echo strtoupper(substr($currentUser['fullName'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-slate-800 truncate"><?php echo htmlspecialchars($currentUser['fullName'] ?? 'User'); ?></p>
                        <p class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($currentUser['role'] ?? 'user'); ?></p>
                    </div>
                </div>
                <p class="mt-3 text-xs leading-5 text-slate-500">
                    Privacy notice: Nucleus uses account and project data only for academic project tracking. Public views limit personal information.
                </p>
            </div>
        </aside>

        <!-- Main Content Area -->
        <div class="min-w-0 flex-1 flex flex-col overflow-hidden">

            <!-- Top Navbar -->
            <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between gap-3 px-4 sm:px-6">
                <div class="flex min-w-0 items-center gap-3">
                    <button type="button" data-mobile-nav-open class="rounded-lg border border-slate-200 p-2 text-slate-600 hover:bg-slate-50 md:hidden" aria-label="Open navigation" aria-controls="dashboardSidebar">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h16"></path></svg>
                    </button>
                    <h1 class="truncate text-lg font-bold text-navy sm:text-xl" id="pageTitle">Dashboard</h1>
                </div>
                <div class="flex items-center gap-4">
                    <a href="logout.php" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-navy transition">Logout</a>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 overflow-auto p-4 sm:p-6 lg:p-8" id="pageContent">
                <!-- Content loaded via AJAX -->
            </main>

        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.8/js/dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.8/js/dataTables.tailwindcss.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        window.__NUCLEUS_DASHBOARD__ = <?php echo json_encode($dashboardPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>

    <!-- JavaScript for AJAX navigation -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const contentEl = document.getElementById('pageContent');
        const titleEl = document.getElementById('pageTitle');
        const navLinks = document.querySelectorAll('.nav-item');
        const navGroups = document.querySelectorAll('[data-nav-group-pages]');
        const openNavButton = document.querySelector('[data-mobile-nav-open]');
        const closeNavTriggers = document.querySelectorAll('[data-mobile-nav-close]');

        function closeMobileNav() {
            document.body.classList.remove('sidebar-open');
        }

        function openMobileNav() {
            document.body.classList.add('sidebar-open');
        }

        if (openNavButton) {
            openNavButton.addEventListener('click', openMobileNav);
        }
        closeNavTriggers.forEach(trigger => trigger.addEventListener('click', closeMobileNav));
        window.addEventListener('keydown', event => {
            if (event.key === 'Escape') closeMobileNav();
        });

        function initNucleusDataTables(scope = document) {
            if (!window.DataTable) return;

            const dashboardConfig = window.__NUCLEUS_DASHBOARD__ || {};
            const tableConfigs = Array.isArray(dashboardConfig.dataTables) ? dashboardConfig.dataTables : [];
            const tableConfigFor = table => tableConfigs.find(config => config.selector && table.matches(config.selector)) || {};

            scope.querySelectorAll('table.data-table').forEach(table => {
                if (DataTable.isDataTable(table)) return;

                const tableConfig = tableConfigFor(table);
                const dataOrder = table.dataset.orderColumn
                    ? [[Number(table.dataset.orderColumn), table.dataset.orderDirection || 'asc']]
                    : [];
                const disabledTargets = Array.isArray(tableConfig.disabledTargets) ? tableConfig.disabledTargets : [];
                const options = {
                    autoWidth: false,
                    deferRender: true,
                    pageLength: Number(table.dataset.pageLength || 10),
                    lengthMenu: [5, 10, 25, 50],
                    orderClasses: false,
                    order: Array.isArray(tableConfig.order) && tableConfig.order.length ? tableConfig.order : dataOrder,
                    scrollX: false,
                    language: {
                        search: '',
                        searchPlaceholder: tableConfig.placeholder || table.dataset.placeholder || 'Search records...',
                        lengthMenu: 'Show _MENU_',
                        info: 'Showing _START_ to _END_ of _TOTAL_',
                        infoEmpty: 'No records to show',
                        emptyTable: table.dataset.empty || 'No records found',
                        zeroRecords: 'No matching records found'
                    }
                };

                if (table.dataset.serverSide === 'true' && table.dataset.ajax) {
                    options.processing = true;
                    options.serverSide = true;
                    options.ajax = table.dataset.ajax;
                    options.searchDelay = 350;
                }

                if (table.dataset.scrollY) {
                    options.scrollY = table.dataset.scrollY;
                    options.scrollCollapse = true;
                }

                const columnDefs = [];
                if (table.querySelector('th.no-sort')) {
                    columnDefs.push({ targets: 'no-sort', orderable: false, searchable: false });
                }
                if (disabledTargets.length) {
                    columnDefs.push({ targets: disabledTargets, orderable: false, searchable: false });
                }
                if (columnDefs.length) {
                    options.columnDefs = columnDefs;
                }

                const externalSearch = table.id ? scope.querySelector(`[data-table-search="#${table.id}"]`) : null;
                if (externalSearch) {
                    options.layout = {
                        topStart: 'pageLength',
                        topEnd: null,
                        bottomStart: 'info',
                        bottomEnd: 'paging'
                    };
                } else {
                    options.layout = {
                        topStart: 'pageLength',
                        topEnd: 'search',
                        bottomStart: 'info',
                        bottomEnd: 'paging'
                    };
                }

                const dataTable = new DataTable(table, options);
                table.nucleusDataTable = dataTable;
                if (externalSearch) {
                    externalSearch.addEventListener('input', function() {
                        dataTable.search(this.value).draw();
                    });
                    if (externalSearch.value) {
                        dataTable.search(externalSearch.value).draw();
                    }
                }
            });
        }

        function runInlineScripts(scope = document) {
            scope.querySelectorAll('script').forEach(script => {
                const replacement = document.createElement('script');
                Array.from(script.attributes).forEach(attr => replacement.setAttribute(attr.name, attr.value));
                replacement.textContent = script.textContent;
                script.replaceWith(replacement);
            });
        }

        function pageTitles(page) {
            return { dashboard: 'Dashboard', folders: 'Subjects', 'view-folder': 'Subject Projects', websites: 'Projects', files: 'Files', 'create-subject': 'Create Subject', 'create-project': 'Project Setup', 'project-form': 'Project Setup', 'project-details': 'Project Details', usermanagement: 'Users', 'create-user': 'Create User', 'manage-user': 'Manage User', requests: 'Requests', settings: 'Settings', alerts: 'Alert Center', logs: 'Logs' }[page] || 'Nucleus';
        }

        function showFeedback(scope = document) {
            const feedback = scope.querySelector('[data-feedback]');
            if (!feedback || !window.Swal) return;

            Swal.fire({
                icon: feedback.dataset.feedback || 'info',
                title: feedback.dataset.feedbackTitle || (feedback.dataset.feedback === 'error' ? 'Something went wrong' : 'Saved'),
                text: feedback.dataset.feedbackMessage || feedback.textContent.trim(),
                confirmButtonColor: '#3085d6'
            });
        }

        function redirectToLoginTimeout() {
            stopLiveStatusUpdates();
            stopMonitoringBrowserScheduler();
            window.location.href = 'login.php?timeout=1';
        }

        function ensureAuthenticatedResponse(response) {
            const redirectedToLogin = response.redirected && response.url && response.url.includes('login.php');
            if (response.status === 401 || response.headers.get('X-Nucleus-Auth-Expired') === '1' || redirectedToLogin) {
                redirectToLoginTimeout();
                throw new Error('Session expired.');
            }
            return response;
        }

        let statusPollTimer = null;
        let statusPollFirstRun = null;
        let statusPollStartTimer = null;
        let monitoringSchedulerTimer = null;
        let monitoringSchedulerRunning = false;
        const monitoringSchedulerLastRunKey = 'nucleus.monitoring.browserDemo.lastRunMs';

        function statusBadgeClasses(status, compact = false) {
            if (compact) {
                const base = 'status-badge shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ';
                return {
                    initializing: base + 'bg-sky-50 text-sky-700 ring-sky-600/20',
                    building: base + 'bg-amber-50 text-amber-700 ring-amber-600/20',
                    deployed: base + 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
                    warning: base + 'bg-orange-50 text-orange-700 ring-orange-600/20',
                    error: base + 'bg-red-50 text-red-700 ring-red-600/20',
                }[status] || base + 'bg-red-50 text-red-700 ring-red-600/20';
            }

            return 'px-2 py-1 rounded text-sm font-medium badge-' + status;
        }

        function applyProjectStatus(badge, result) {
            const status = result.status || 'error';
            const isCardBadge = badge.classList.contains('status-badge');
            badge.className = statusBadgeClasses(status, isCardBadge);
            badge.textContent = result.displayStatus || status.charAt(0).toUpperCase() + status.slice(1);
            badge.title = result.message || '';
            const row = badge.closest('tr');
            const scope = row || badge.closest('.project-card') || badge.parentElement;
            if (scope) {
                const responseTime = scope.querySelector('[data-status-response-time]');
                if (responseTime) responseTime.textContent = result.responseTimeMs ? `${result.responseTimeMs} ms` : '—';
                const source = scope.querySelector('[data-status-source]');
                if (source) source.textContent = result.statusSource || '—';
                const lastSuccess = scope.querySelector('[data-last-successful-check]');
                if (lastSuccess) lastSuccess.textContent = result.displayLastSuccessfulCheck || 'Never';
                const failures = scope.querySelector('[data-consecutive-failures]');
                if (failures) failures.textContent = String(result.consecutiveFailures ?? 0);
                const uptime = scope.querySelector('[data-uptime-24h]');
                if (uptime) uptime.textContent = result.displayUptimePercent24h || 'No checks';
                const health = scope.querySelector('[data-health-state]');
                if (health) {
                    const state = result.healthState || 'unknown';
                    health.dataset.healthState = state;
                    health.textContent = result.healthLabel || 'Unknown';
                    health.title = result.healthMessage || '';
                    health.className = 'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ' + ({
                        fresh: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
                        stale: 'bg-amber-50 text-amber-700 ring-amber-600/20',
                        possibly_outdated: 'bg-orange-50 text-orange-700 ring-orange-600/20',
                        unknown: 'bg-slate-100 text-slate-600 ring-slate-500/20'
                    }[state] || 'bg-slate-100 text-slate-600 ring-slate-500/20');
                }
                const version = scope.querySelector('[data-latest-version]');
                if (version && result.version) version.textContent = result.version;
                const commit = scope.querySelector('[data-latest-commit]');
                if (commit && result.commitHash) commit.textContent = result.commitHash.substring(0, 12);
            }

            const card = badge.closest('.project-card');
            if (card) {
                card.dataset.status = badge.textContent;
                if (status === 'deployed') {
                    card.dataset.updated = Math.floor(Date.now() / 1000);
                    const time = card.querySelector('.project-time');
                    if (time && result.displayUpdatedAt) time.textContent = result.displayUpdatedAt;
                }
            }
        }

        function initStatusPolling(scope = document) {
            if (statusPollTimer) {
                clearInterval(statusPollTimer);
                statusPollTimer = null;
            }
            if (statusPollFirstRun) {
                if (statusPollFirstRun.type === 'idle' && window.cancelIdleCallback) {
                    window.cancelIdleCallback(statusPollFirstRun.id);
                } else {
                    clearTimeout(statusPollFirstRun.id);
                }
                statusPollFirstRun = null;
            }

            let nextProjectIndex = 0;

            function currentProjectIds() {
                return Array.from(new Set(
                    Array.from(scope.querySelectorAll('[data-project-status-id]'))
                        .map(badge => badge.dataset.projectStatusId)
                        .filter(Boolean)
                ));
            }

            async function runLimited(items, limit, worker) {
                const queue = [...items];
                const workers = Array.from({ length: Math.min(limit, queue.length) }, async () => {
                    while (queue.length) {
                        const item = queue.shift();
                        await worker(item);
                    }
                });
                await Promise.all(workers);
            }

            async function pollOnce() {
                const projectIds = currentProjectIds();
                if (!projectIds.length) return;
                const batchSize = 6;
                const batch = [];
                while (batch.length < batchSize && batch.length < projectIds.length) {
                    if (nextProjectIndex >= projectIds.length) nextProjectIndex = 0;
                    batch.push(projectIds[nextProjectIndex]);
                    nextProjectIndex = (nextProjectIndex + 1) % projectIds.length;
                }

                await runLimited(batch, 2, async projectId => {
                    try {
                        const response = await fetch('handlers/check_project_status.php?projectId=' + encodeURIComponent(projectId), {
                            headers: { 'Accept': 'application/json' }
                        });
                        ensureAuthenticatedResponse(response);
                        const result = await response.json();
                        if (!result.success) return;
                        scope.querySelectorAll(`[data-project-status-id="${CSS.escape(projectId)}"]`).forEach(badge => applyProjectStatus(badge, result));
                    } catch (err) {
                        console.debug('Status poll failed', err);
                    }
                });
            }

            statusPollTimer = setInterval(pollOnce, 30000);
            if (window.requestIdleCallback) {
                statusPollFirstRun = { type: 'idle', id: window.requestIdleCallback(pollOnce, { timeout: 8000 }) };
            } else {
                statusPollFirstRun = { type: 'timeout', id: setTimeout(pollOnce, 8000) };
            }
        }

        function scheduleLiveStatusUpdates(scope = contentEl) {
            if (statusPollStartTimer) {
                clearTimeout(statusPollStartTimer);
                statusPollStartTimer = null;
            }

            statusPollStartTimer = setTimeout(() => {
                statusPollStartTimer = null;
                initStatusPolling(scope);
            }, 2500);
        }

        function stopLiveStatusUpdates() {
            if (statusPollStartTimer) {
                clearTimeout(statusPollStartTimer);
                statusPollStartTimer = null;
            }
            if (statusPollTimer) {
                clearInterval(statusPollTimer);
                statusPollTimer = null;
            }
            if (statusPollFirstRun) {
                if (statusPollFirstRun.type === 'idle' && window.cancelIdleCallback) {
                    window.cancelIdleCallback(statusPollFirstRun.id);
                } else {
                    clearTimeout(statusPollFirstRun.id);
                }
                statusPollFirstRun = null;
            }
        }

        function stopMonitoringBrowserScheduler() {
            if (monitoringSchedulerTimer) {
                clearInterval(monitoringSchedulerTimer);
                monitoringSchedulerTimer = null;
            }
            monitoringSchedulerRunning = false;
        }

        async function runMonitoringNow(options = {}) {
            const silent = !!options.silent;
            const button = options.button || null;
            const originalText = button ? button.textContent : '';
            if (button) {
                button.disabled = true;
                button.textContent = 'Running...';
            }

            try {
                const response = await fetch('handlers/run_monitoring_now.php', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' }
                });
                ensureAuthenticatedResponse(response);
                const result = await response.json();
                if (result.success) {
                    await refreshProjectStatuses(contentEl);
                    if (!silent) {
                        await Swal.fire({
                            icon: 'success',
                            title: 'Monitoring complete',
                            text: result.message || `Checked ${result.checked_count || 0} projects.`,
                            confirmButtonColor: '#3085d6'
                        });
                        await reloadDashboardPage(new URLSearchParams(window.location.search).get('page') || 'dashboard');
                    }
                    return result;
                }

                if (!silent) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Monitoring failed',
                        text: result.message || 'The monitoring queue could not be started.',
                        confirmButtonColor: '#3085d6'
                    });
                } else {
                    console.debug('Browser demo monitoring run failed', result.message || result);
                }
                return result;
            } catch (err) {
                if (!silent) {
                    Swal.fire({ icon: 'error', title: 'Request failed', text: err.message, confirmButtonColor: '#3085d6' });
                } else {
                    console.debug('Browser demo monitoring request failed', err);
                }
                return { success: false, message: err.message };
            } finally {
                if (button) {
                    button.disabled = false;
                    button.textContent = originalText;
                }
            }
        }

        function initMonitoringBrowserScheduler(scope = contentEl) {
            stopMonitoringBrowserScheduler();
            const scheduler = scope.querySelector('[data-monitoring-scheduler]');
            if (!scheduler || scheduler.dataset.schedulerMode !== 'browser_demo' || scheduler.dataset.canRunMonitoring !== '1') {
                return;
            }

            const intervalMinutes = Math.max(1, parseInt(scheduler.dataset.monitoringIntervalMinutes || '5', 10));
            const intervalMs = intervalMinutes * 60 * 1000;
            const serverLastRunMs = parseInt(scheduler.dataset.monitoringLastRunMs || '0', 10) || 0;
            const storedLastRunMs = parseInt(localStorage.getItem(monitoringSchedulerLastRunKey) || '0', 10) || 0;
            if (serverLastRunMs > storedLastRunMs) {
                localStorage.setItem(monitoringSchedulerLastRunKey, String(serverLastRunMs));
            }

            async function tick() {
                if (monitoringSchedulerRunning) return;
                if ((scheduler.dataset.monitoringLockState || '') === 'running') return;

                const lastRunMs = parseInt(localStorage.getItem(monitoringSchedulerLastRunKey) || '0', 10) || 0;
                if (Date.now() - lastRunMs < intervalMs) return;

                monitoringSchedulerRunning = true;
                localStorage.setItem(monitoringSchedulerLastRunKey, String(Date.now()));
                try {
                    const result = await runMonitoringNow({ silent: true });
                    if (result && result.success) {
                        scheduler.dataset.monitoringLastRunMs = String(Date.now());
                    }
                } finally {
                    monitoringSchedulerRunning = false;
                }
            }

            monitoringSchedulerTimer = setInterval(tick, Math.min(intervalMs, 60000));
            setTimeout(tick, 5000);
        }

        async function refreshProjectStatuses(scope = contentEl) {
            const badges = Array.from(scope.querySelectorAll('[data-project-status-id]'));
            const projectIds = Array.from(new Set(badges.map(badge => badge.dataset.projectStatusId).filter(Boolean)));
            if (!projectIds.length) return;

            async function runLimited(items, limit, worker) {
                const queue = [...items];
                const workers = Array.from({ length: Math.min(limit, queue.length) }, async () => {
                    while (queue.length) {
                        const item = queue.shift();
                        await worker(item);
                    }
                });
                await Promise.all(workers);
            }

            await runLimited(projectIds, 2, async projectId => {
                try {
                    const response = await fetch('handlers/check_project_status.php?projectId=' + encodeURIComponent(projectId), {
                        headers: { 'Accept': 'application/json' }
                    });
                    ensureAuthenticatedResponse(response);
                    const result = await response.json();
                    if (!result.success) return;
                    scope.querySelectorAll(`[data-project-status-id="${CSS.escape(projectId)}"]`).forEach(badge => applyProjectStatus(badge, result));
                } catch (err) {
                    console.debug('Status refresh failed', err);
                }
            });
        }

        function showPageLoading(page) {
            stopLiveStatusUpdates();
            stopMonitoringBrowserScheduler();
            titleEl.textContent = pageTitles(page);
            contentEl.innerHTML = `
                <div class="space-y-6" aria-live="polite" aria-busy="true">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="h-7 w-44 animate-pulse rounded bg-slate-200"></div>
                            <div class="mt-3 h-4 w-64 animate-pulse rounded bg-slate-100"></div>
                        </div>
                        <div class="h-10 w-28 animate-pulse rounded-lg bg-slate-200"></div>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        ${Array.from({ length: 4 }).map(() => '<div class="h-28 animate-pulse rounded-xl border border-slate-200 bg-white"></div>').join('')}
                    </div>
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                        <div class="border-b border-slate-100 p-6">
                            <div class="h-5 w-40 animate-pulse rounded bg-slate-200"></div>
                            <div class="mt-3 h-4 w-72 animate-pulse rounded bg-slate-100"></div>
                        </div>
                        <div class="space-y-3 p-6">
                            ${Array.from({ length: 6 }).map(() => '<div class="h-10 animate-pulse rounded bg-slate-100"></div>').join('')}
                        </div>
                    </div>
                </div>
            `;
        }

        function renderContent(page, html) {
            contentEl.innerHTML = html;
            runInlineScripts(contentEl);
            initNucleusDataTables(contentEl);
            updateActiveNav(page);
            titleEl.textContent = pageTitles(page);
            showFeedback(contentEl);
            scheduleLiveStatusUpdates(contentEl);
            initMonitoringBrowserScheduler(contentEl);
        }

        async function reloadDashboardPage(page, params = new URLSearchParams()) {
            params.set('tab', page);
            showPageLoading(page);
            const response = await fetch('get_content.php?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            ensureAuthenticatedResponse(response);
            const html = await response.text();
            renderContent(page, html);

            const historyParams = new URLSearchParams(window.location.search);
            historyParams.set('page', page);
            params.forEach((value, key) => {
                if (key !== 'tab') historyParams.set(key, value);
            });
            history.pushState({ page }, '', '?' + historyParams.toString());
        }

        function runExternalTableSearch(input) {
            const selector = input.dataset.tableSearch;
            if (!selector) return;

            const table = contentEl.querySelector(selector);
            if (!table) return;

            const dataTable = table.nucleusDataTable;
            if (dataTable) {
                dataTable.search(input.value).draw();
                return;
            }

            const query = input.value.trim().toLowerCase();
            table.querySelectorAll('tbody tr').forEach(row => {
                row.classList.toggle('hidden', query !== '' && !row.textContent.toLowerCase().includes(query));
            });
        }

        function runSubjectSearch(input) {
            const cards = Array.from(contentEl.querySelectorAll('[data-subject-card]'));
            const empty = contentEl.querySelector('#subjectEmptyState');
            const query = input.value.trim().toLowerCase();
            let visible = 0;

            cards.forEach(card => {
                const matches = !query || (card.dataset.searchText || '').includes(query);
                card.classList.toggle('hidden', !matches);
                if (matches) visible++;
            });

            if (empty) empty.classList.toggle('hidden', visible !== 0);
        }

        function loadPage(page, pushState = true, params = new URLSearchParams()) {
            const fetchParams = new URLSearchParams(params);
            fetchParams.set('tab', page);
            updateActiveNav(page);
            showPageLoading(page);

            fetch('get_content.php?' + fetchParams.toString())
                .then(ensureAuthenticatedResponse)
                .then(res => res.text())
                .then(html => {
                    renderContent(page, html);
                    if (pushState) {
                        const historyParams = new URLSearchParams(params);
                        historyParams.set('page', page);
                        history.pushState({ page }, '', '?' + historyParams.toString());
                    }
                })
                .catch(err => {
                    contentEl.innerHTML = '<div class="p-8 text-red-600">Failed to load page.</div>';
                    console.error(err);
                });
        }

        function updateActiveNav(page) {
            const navPage = {
                'project-form': 'create-project',
                'project-details': 'websites',
                'manage-user': 'usermanagement',
                'create-user': 'usermanagement',
                'view-folder': 'folders'
            }[page] || page;
            navLinks.forEach(link => {
                const active = link.dataset.page === navPage;
                link.classList.toggle('active', active);
                if (active) {
                    link.setAttribute('aria-current', 'page');
                } else {
                    link.removeAttribute('aria-current');
                }
            });
            navGroups.forEach(group => {
                const pages = (group.dataset.navGroupPages || '').split(',');
                group.classList.toggle('is-open', pages.includes(page) || pages.includes(navPage));
            });
            document.body.dataset.currentDashboardPage = navPage;
            closeMobileNav();
        }

        // Initial load
        const urlParams = new URLSearchParams(window.location.search);
        const initialPage = urlParams.get('page') || 'dashboard';
        loadPage(initialPage, false, urlParams);

        // Nav clicks
        navLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                closeMobileNav();
                loadPage(this.dataset.page);
            });
        });

        // Back/forward
        window.addEventListener('popstate', function(e) {
            const params = new URLSearchParams(window.location.search);
            const page = params.get('page') || 'dashboard';
            loadPage(page, false, params);
        });

        // Global handler for status-select buttons (dashboard "Mark updated")
        contentEl.addEventListener('click', async function(e) {
            const btn = e.target.closest('.status-select');
            if (!btn) return;
            const websiteId = btn.dataset.websiteId;
            const confirmation = await Swal.fire({
                icon: 'question',
                title: 'Mark this project as updated?',
                text: 'The project update status will be recorded.',
                showCancelButton: true,
                confirmButtonText: 'Update',
                confirmButtonColor: '#3085d6'
            });
            if (!confirmation.isConfirmed) return;

            try {
                const response = await fetch('handlers/update_website.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: '<?php echo $_SESSION["csrf_token"]; ?>',
                        websiteId: websiteId,
                        status: 'updated'
                    })
                });
                ensureAuthenticatedResponse(response);
                const result = await response.json();
                if (result.success) {
                    await Swal.fire({ icon: 'success', title: 'Project marked as updated', confirmButtonColor: '#3085d6' });
                    window.location.reload();
                } else {
                    Swal.fire({ icon: 'error', title: 'Update failed', text: result.message, confirmButtonColor: '#3085d6' });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Request failed', text: err.message, confirmButtonColor: '#3085d6' });
            }
        });

        contentEl.addEventListener('submit', async function(e) {
            const form = e.target.closest('form');
            if (!form) return;

            if (form.dataset.confirm && !form.dataset.confirmed) {
                e.preventDefault();
                const confirmation = await Swal.fire({
                    icon: 'warning',
                    title: form.dataset.confirmTitle || 'Are you sure?',
                    text: form.dataset.confirm,
                    showCancelButton: true,
                    confirmButtonText: form.dataset.confirmButton || 'Continue',
                    confirmButtonColor: '#3085d6'
                });
                if (confirmation.isConfirmed) {
                    form.dataset.confirmed = '1';
                } else {
                    return;
                }
            }

            const action = new URL(form.action || window.location.href, window.location.href);
            if (!action.pathname.endsWith('/get_content.php') && !form.dataset.returnPage) return;

            e.preventDefault();
            const params = new URLSearchParams(action.search);
            const page = form.dataset.returnPage || params.get('tab') || new URLSearchParams(window.location.search).get('page') || 'dashboard';

            try {
                const response = await fetch(action.toString(), {
                    method: form.method || 'POST',
                    body: new FormData(form),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                ensureAuthenticatedResponse(response);

                if (!action.pathname.endsWith('/get_content.php')) {
                    const result = await response.json();
                    if (result.success) {
                        await Swal.fire({ icon: 'success', title: 'Done', text: result.message || 'Action completed.', confirmButtonColor: '#3085d6' });
                        await reloadDashboardPage(result.page || page);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Action failed', text: result.message || 'The action could not be completed.', confirmButtonColor: '#3085d6' });
                    }
                    delete form.dataset.confirmed;
                    return;
                }

                const html = await response.text();
                renderContent(page, html);

                const historyParams = new URLSearchParams(window.location.search);
                historyParams.set('page', page);
                params.forEach((value, key) => {
                    if (key !== 'tab') historyParams.set(key, value);
                });
                history.pushState({ page }, '', '?' + historyParams.toString());
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Request failed', text: err.message, confirmButtonColor: '#3085d6' });
            } finally {
                delete form.dataset.confirmed;
            }
        });

        contentEl.addEventListener('input', function(e) {
            const input = e.target.closest('[data-table-search]');
            if (input) {
                runExternalTableSearch(input);
                return;
            }

            const subjectSearch = e.target.closest('[data-subject-search]');
            if (subjectSearch) {
                runSubjectSearch(subjectSearch);
            }
        });

        contentEl.addEventListener('click', async function(e) {
            const runMonitoringButton = e.target.closest('[data-run-monitoring-now]');
            if (runMonitoringButton) {
                await runMonitoringNow({ button: runMonitoringButton });
                return;
            }

            const refreshButton = e.target.closest('[data-refresh-statuses]');
            if (refreshButton) {
                refreshButton.disabled = true;
                const originalText = refreshButton.textContent;
                refreshButton.textContent = 'Refreshing...';
                try {
                    await refreshProjectStatuses(contentEl);
                } finally {
                    refreshButton.disabled = false;
                    refreshButton.textContent = originalText;
                }
                return;
            }

            const link = e.target.closest('a[data-confirm]');
            if (!link) return;

            e.preventDefault();
            const confirmation = await Swal.fire({
                icon: 'warning',
                title: link.dataset.confirmTitle || 'Are you sure?',
                text: link.dataset.confirm,
                showCancelButton: true,
                confirmButtonText: link.dataset.confirmButton || 'Continue',
                confirmButtonColor: '#3085d6'
            });
            if (confirmation.isConfirmed) {
                try {
                    const action = new URL(link.href, window.location.href);
                    const page = link.dataset.returnPage || new URLSearchParams(action.search).get('tab') || new URLSearchParams(window.location.search).get('page') || 'dashboard';
                    const response = await fetch(action.toString(), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    ensureAuthenticatedResponse(response);

                    if (action.pathname.endsWith('/get_content.php')) {
                        const html = await response.text();
                        renderContent(page, html);
                        const historyParams = new URLSearchParams(window.location.search);
                        historyParams.set('page', page);
                        history.pushState({ page }, '', '?' + historyParams.toString());
                    } else {
                        const result = await response.json();
                        if (result.success) {
                            await Swal.fire({ icon: 'success', title: 'Done', text: result.message || 'Action completed.', confirmButtonColor: '#3085d6' });
                            await reloadDashboardPage(result.page || page);
                        } else {
                            Swal.fire({ icon: 'error', title: 'Action failed', text: result.message || 'The action could not be completed.', confirmButtonColor: '#3085d6' });
                        }
                    }
                } catch (err) {
                    Swal.fire({ icon: 'error', title: 'Request failed', text: err.message, confirmButtonColor: '#3085d6' });
                }
            }
        });

    });
    </script>

</body>
</html>
