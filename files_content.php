<?php
require_once __DIR__ . "/includes/core.php";

ensureDriveStorageSchema($pdo);

$role = $_SESSION["role"] ?? (new RoleManager($pdo))->getUserRole($_SESSION["userId"] ?? null);
$userId = (int) ($_SESSION["userId"] ?? 0);
if (!in_array($role, ["admin", "handler"], true)) {
    echo "<div class=\"rounded-lg border border-red-200 bg-red-50 p-6 text-sm text-red-700\">Drive Storage is restricted to administrators and handlers.</div>";
    return;
}
$folderId = isset($_GET["folder_id"]) && is_numeric($_GET["folder_id"]) ? (int) $_GET["folder_id"] : null;
$search = trim((string) ($_GET["search"] ?? ""));
$currentFolder = getCurrentFolder($folderId);

if ($folderId && !driveCanAccessFolder($userId, $role, $currentFolder)) {
    echo "<div class=\"rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700\">Folder not found or access denied.</div>";
    return;
}

$items = listDriveItems($userId, $role, $folderId, $search);
$usedBytes = getUsedStorageBytes($userId);
$quotaBytes = getQuotaBytesForRole($role);
$usedPercent = $quotaBytes > 0 ? min(100, round(($usedBytes / $quotaBytes) * 100, 1)) : 0;
$warningClass = $usedPercent >= 80 ? "bg-amber-500" : "bg-[#043873]";
$breadcrumbs = driveBreadcrumbs($folderId);
$folderParam = $folderId ? "&folder_id=" . urlencode((string) $folderId) : "";

function driveItemIcon(array $item): string
{
    if ($item["item_type"] === "folder") {
        return "M3 7a2 2 0 012-2h5l2 2h7a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z";
    }
    $mime = (string) ($item["mime_type"] ?? "");
    if (str_starts_with($mime, "image/")) {
        return "M4 5a2 2 0 012-2h12a2 2 0 012 2v14H4V5zm3 10l3-3 2 2 3-4 4 5";
    }
    return "M7 3h7l5 5v13H7V3zm7 0v5h5";
}
?>

<div class="space-y-6">
    <?php if (!empty($_GET["drive_status"])): ?>
        <div data-feedback="<?php echo htmlspecialchars($_GET["drive_status"] === "success" ? "success" : "error"); ?>" data-feedback-title="<?php echo htmlspecialchars($_GET["drive_status"] === "success" ? "Done" : "Action failed"); ?>" data-feedback-message="<?php echo htmlspecialchars((string) ($_GET["drive_message"] ?? "")); ?>" class="hidden"></div>
    <?php endif; ?>

    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase text-[#043873]">Files</p>
            <h2 class="mt-1 text-2xl font-bold text-slate-900">Drive Storage</h2>
            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <?php foreach ($breadcrumbs as $index => $crumb): ?>
                    <?php if ($index > 0): ?><span>/</span><?php endif; ?>
                    <a class="font-medium text-[#043873] hover:underline" href="dashboard.php?page=files<?php echo $crumb["folder_id"] ? "&folder_id=" . (int) $crumb["folder_id"] : ""; ?>">
                        <?php echo htmlspecialchars($crumb["name"]); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="w-full rounded-xl border border-slate-200 bg-white p-4 lg:w-80">
            <div class="flex items-center justify-between text-sm">
                <span class="font-medium text-slate-700">Storage usage</span>
                <span class="text-slate-500"><?php echo $usedPercent; ?>%</span>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full <?php echo $warningClass; ?>" style="width: <?php echo $usedPercent; ?>%"></div>
            </div>
            <p class="mt-2 text-xs text-slate-500"><?php echo formatDriveBytes($usedBytes); ?> of <?php echo formatDriveBytes($quotaBytes); ?> used</p>
            <?php if ($usedPercent >= 80): ?>
                <p class="mt-2 text-xs font-medium text-amber-700">Storage is above 80%.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-[1fr_20rem]">
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center lg:justify-between">
                <form method="GET" action="dashboard.php" class="flex w-full gap-2 lg:max-w-md">
                    <input type="hidden" name="page" value="files">
                    <?php if ($folderId): ?><input type="hidden" name="folder_id" value="<?php echo (int) $folderId; ?>"><?php endif; ?>
                    <input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search files and folders..." class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm outline-none focus:border-[#043873] focus:ring-2 focus:ring-[#043873]/10">
                    <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">Search</button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-left">Name</th>
                            <th class="px-4 py-3 text-left">Type</th>
                            <th class="px-4 py-3 text-left">Size</th>
                            <th class="px-4 py-3 text-left">Owner</th>
                            <th class="px-4 py-3 text-left">Date</th>
                            <th class="px-4 py-3 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($items)): ?>
                            <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No files uploaded yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($items as $item): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <svg class="h-5 w-5 text-[#043873]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?php echo driveItemIcon($item); ?>"></path></svg>
                                        <span class="font-medium text-slate-800"><?php echo htmlspecialchars($item["name"]); ?></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-slate-500"><?php echo $item["item_type"] === "folder" ? "Folder" : htmlspecialchars($item["mime_type"] ?: "File"); ?></td>
                                <td class="px-4 py-3 text-slate-500"><?php echo $item["item_type"] === "folder" ? "-" : formatDriveBytes((int) $item["file_size"]); ?></td>
                                <td class="px-4 py-3 text-slate-500"><?php echo htmlspecialchars($item["owner_name"] ?: "Unknown"); ?></td>
                                <td class="px-4 py-3 text-slate-500"><?php echo htmlspecialchars(formatNucleusDateTime($item["item_date"])); ?></td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <?php if ($item["item_type"] === "folder"): ?>
                                            <a href="dashboard.php?page=files&folder_id=<?php echo (int) $item["item_id"]; ?>" class="rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100">Open</a>
                                        <?php else: ?>
                                            <a href="handlers/files/download.php?id=<?php echo (int) $item["item_id"]; ?>" class="rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100">Download</a>
                                        <?php endif; ?>
                                        <form method="POST" action="handlers/files/rename.php" class="flex flex-wrap gap-1">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                                            <input type="hidden" name="type" value="<?php echo htmlspecialchars($item["item_type"]); ?>">
                                            <input type="hidden" name="id" value="<?php echo (int) $item["item_id"]; ?>">
                                            <input type="hidden" name="folder_id" value="<?php echo htmlspecialchars((string) $folderId); ?>">
                                            <input name="name" value="<?php echo htmlspecialchars($item["name"]); ?>" class="w-32 max-w-full rounded-lg border border-slate-200 px-2 py-1 text-xs">
                                            <button class="rounded-lg bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">Rename</button>
                                        </form>
                                        <form method="POST" action="handlers/files/delete.php" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                                            <input type="hidden" name="type" value="<?php echo htmlspecialchars($item["item_type"]); ?>">
                                            <input type="hidden" name="id" value="<?php echo (int) $item["item_id"]; ?>">
                                            <input type="hidden" name="folder_id" value="<?php echo htmlspecialchars((string) $folderId); ?>">
                                            <button class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-100">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4">
            <form method="POST" action="handlers/files/upload.php" enctype="multipart/form-data" class="rounded-xl border border-slate-200 bg-white p-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                <input type="hidden" name="folder_id" value="<?php echo htmlspecialchars((string) $folderId); ?>">
                <h3 class="font-semibold text-slate-900">Upload file</h3>
                <input type="file" name="drive_file" required class="mt-4 block w-full text-sm text-slate-600">
                <p class="mt-2 text-xs text-slate-500">Max file size: <?php echo formatDriveBytes(UPLOAD_MAX_BYTES); ?></p>
                <button class="mt-4 w-full rounded-lg bg-[#043873] px-4 py-2 text-sm font-semibold text-white hover:bg-[#032a56]">Upload</button>
            </form>

            <form method="POST" action="handlers/files/create_folder.php" class="rounded-xl border border-slate-200 bg-white p-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                <input type="hidden" name="folder_id" value="<?php echo htmlspecialchars((string) $folderId); ?>">
                <h3 class="font-semibold text-slate-900">Create folder</h3>
                <input name="name" required maxlength="180" placeholder="Folder name" class="mt-4 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#043873] focus:ring-2 focus:ring-[#043873]/10">
                <button class="mt-4 w-full rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Create Folder</button>
            </form>
        </div>
    </div>
</div>
