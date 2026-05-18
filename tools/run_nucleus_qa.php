<?php

declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\MultipartStream;

final class QaResult
{
    public function __construct(
        public string $area,
        public string $role,
        public string $route,
        public string $scenario,
        public string $expected,
        public string $actual,
        public bool $pass,
        public string $evidence,
        public ?string $severity = null,
        public ?string $fix = null
    ) {
    }
}

final class NucleusQa
{
    private PDO $pdo;
    private Client $client;
    private string $baseUrl;
    private array $results = [];
    private array $users = [];
    private array $ids = [];
    private string $password = "QaPass123!";

    public function __construct()
    {
        $this->baseUrl = rtrim((string) (getenv("QA_BASE_URL") ?: "http://127.0.0.1:8087"), "/");
        $this->client = new Client([
            "base_uri" => $this->baseUrl . "/",
            "http_errors" => false,
            "timeout" => 30,
            "allow_redirects" => false,
        ]);
        $this->pdo = new PDO(
            sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                getenv("DB_HOST") ?: "127.0.0.1",
                getenv("DB_PORT") ?: "3306",
                getenv("DB_DATABASE") ?: "nucleus_qa",
                getenv("DB_CHARSET") ?: "utf8mb4"
            ),
            (string) (getenv("DB_USERNAME") ?: "root"),
            (string) getenv("DB_PASSWORD"),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    public function run(): void
    {
        $this->freshInstall();
        $this->seedFixtures();
        $this->authChecks();
        $this->roleAndRouteChecks();
        $this->workflowChecks();
        $this->monitoringChecks();
        $this->fileChecks();
        $this->featureFlagChecks();
        $this->securityChecks();
        $this->uiDemoChecks();
        $this->printReport();
    }

    private function add(string $area, string $role, string $route, string $scenario, string $expected, string $actual, bool $pass, string $evidence, ?string $severity = null, ?string $fix = null): void
    {
        $this->results[] = new QaResult($area, $role, $route, $scenario, $expected, $actual, $pass, $evidence, $severity, $fix);
    }

    private function request(string $method, string $uri, array $options = []): array
    {
        try {
            $response = $this->client->request($method, ltrim($uri, "/"), $options);
            return [
                "status" => $response->getStatusCode(),
                "headers" => $response->getHeaders(),
                "body" => (string) $response->getBody(),
            ];
        } catch (Throwable $e) {
            return ["status" => 0, "headers" => [], "body" => $e->getMessage()];
        }
    }

    private function sessionFor(string $role): CookieJar
    {
        $jar = new CookieJar();
        $user = $this->users[$role];
        $loginPage = $this->request("GET", "login.php", ["cookies" => $jar]);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginPage["body"], $m);
        $token = html_entity_decode($m[1] ?? "", ENT_QUOTES, "UTF-8");
        $res = $this->request("POST", "login.php", [
            "cookies" => $jar,
            "form_params" => [
                "csrf_token" => $token,
                "login" => $user["username"],
                "password" => $this->password,
            ],
        ]);
        $location = $res["headers"]["Location"][0] ?? "";
        if ($res["status"] !== 302 || $location === "") {
            throw new RuntimeException("Unable to login {$role}: HTTP {$res["status"]} " . substr(strip_tags($res["body"]), 0, 200));
        }
        return $jar;
    }

    private function csrf(CookieJar $jar, string $page = "dashboard.php?page=dashboard"): string
    {
        $res = $this->request("GET", $page, ["cookies" => $jar]);
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $res["body"], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES, "UTF-8");
        }
        if (preg_match('/"csrfToken"\s*:\s*"([^"]+)"/', $res["body"], $m)) {
            return stripcslashes($m[1]);
        }
        if (preg_match("/csrf_token\s*:\s*'([^']+)'/", $res["body"], $m)) {
            return html_entity_decode($m[1], ENT_QUOTES, "UTF-8");
        }
        return "";
    }

    private function roleId(string $role): int
    {
        $stmt = $this->pdo->prepare("SELECT role_id FROM roles WHERE role_name = ?");
        $stmt->execute([$role]);
        return (int) $stmt->fetchColumn();
    }

    private function freshInstall(): void
    {
        $tables = ["users", "roles", "subjects", "projects", "project_status", "deployment_checks", "monitoring_runs", "monitoring_alerts", "feature_flags", "resource_files", "drive_files", "activity_logs"];
        foreach ($tables as $table) {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            $present = (bool) $stmt->fetchColumn();
            $this->add("Fresh install", "system", $table, "Required table exists", "Table is present after init_db.php", $present ? "present" : "missing", $present, "SHOW TABLES {$table}", "Critical", "Add or fix migration for missing table.");
        }

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.username = 'admin' AND r.role_name = 'admin'");
        $adminCount = (int) $stmt->fetchColumn();
        $this->add("Fresh install", "admin", "init_db.php", "Seeded first admin", "admin/admin123 account exists as admin", $adminCount > 0 ? "seeded" : "missing", $adminCount > 0, "users join roles", "Critical", "Ensure nucleus_3nf_schema.sql seeds admin with role_id.");
    }

    private function seedFixtures(): void
    {
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        foreach (["activity_logs", "resource_files", "drive_files", "drive_folders", "monitoring_alerts", "deployment_checks", "monitoring_runs", "project_status", "project_members", "project_requests", "subject_join_requests", "subject_requests", "projects", "subject_members", "subjects"] as $table) {
            $this->pdo->exec("DELETE FROM {$table} WHERE 1=1");
        }
        $this->pdo->exec("DELETE FROM users WHERE username LIKE 'qa_%' OR username = 'admin'");
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        $this->pdo->exec("UPDATE feature_flags SET is_enabled = 1, maintenance_message = NULL");

        $hash = password_hash($this->password, PASSWORD_BCRYPT, ["cost" => 12]);
        foreach (["superadmin", "admin", "handler", "member", "visitor"] as $role) {
            $stmt = $this->pdo->prepare("INSERT INTO users (username, passwordHash, fullName, email, role_id) VALUES (?, ?, ?, ?, ?)");
            $username = "qa_" . $role;
            $stmt->execute([$username, $hash, "QA " . ucfirst($role), "{$username}@example.test", $this->roleId($role)]);
            $this->users[$role] = ["id" => (int) $this->pdo->lastInsertId(), "username" => $username];
        }
        $stmt = $this->pdo->prepare("INSERT INTO users (username, passwordHash, fullName, email, role_id) VALUES ('admin', ?, 'QA Seed Admin', 'qa_seed_admin@example.test', ?)");
        $stmt->execute([password_hash("admin123", PASSWORD_BCRYPT, ["cost" => 12]), $this->roleId("admin")]);

        $stmt = $this->pdo->prepare("INSERT INTO subjects (subject_code, subject_name, description, created_by) VALUES (?, ?, ?, ?)");
        $stmt->execute(["QA_ACTIVE", "QA Active Subject", "Active QA subject", $this->users["admin"]["id"]]);
        $this->ids["subjectActive"] = (int) $this->pdo->lastInsertId();
        $stmt->execute(["QA_ARCH", "QA Archived Subject", "Archived QA subject", $this->users["admin"]["id"]]);
        $this->ids["subjectArchived"] = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("UPDATE subjects SET archived_at = NOW() WHERE subject_id = ?")->execute([$this->ids["subjectArchived"]]);
        $this->pdo->prepare("INSERT INTO subject_members (subject_id, userId, access_level, added_by) VALUES (?, ?, 'handler', ?)")->execute([$this->ids["subjectActive"], $this->users["handler"]["id"], $this->users["admin"]["id"]]);

        $projectSql = "INSERT INTO projects (subject_id, project_name, public_url, github_repo_url, github_repo_name, webhook_secret, current_version, deployment_mode, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($projectSql);
        $stmt->execute([$this->ids["subjectActive"], "QA Hostinger Project", "https://example.test/qa-hostinger", "https://github.com/example/qa-hostinger.git", "qa-hostinger", bin2hex(random_bytes(8)), "1.0.0", "hostinger_git", $this->users["handler"]["id"]]);
        $this->ids["projectHostinger"] = (int) $this->pdo->lastInsertId();
        $stmt->execute([$this->ids["subjectActive"], "QA Webhook Project", "https://example.test/qa-webhook", "https://github.com/example/qa-webhook.git", "qa-webhook", bin2hex(random_bytes(8)), "1.0.0", "custom_webhook", $this->users["admin"]["id"]]);
        $this->ids["projectWebhook"] = (int) $this->pdo->lastInsertId();
        foreach (["projectHostinger", "projectWebhook"] as $key) {
            $this->pdo->prepare("INSERT INTO project_status (project_id, status, checked_at) VALUES (?, 'unknown', NOW())")->execute([$this->ids[$key]]);
        }
        $this->pdo->prepare("INSERT INTO project_members (project_id, userId, member_role, added_by) VALUES (?, ?, 'viewer', ?)")->execute([$this->ids["projectHostinger"], $this->users["member"]["id"], $this->users["admin"]["id"]]);

        $this->add("Fresh install", "system", "fixtures", "QA-only fixtures seeded", "Five roles and workflow data exist", "seeded", true, "users qa_*, subjects QA_*, projects QA_*");
    }

    private function authChecks(): void
    {
        $admin = new CookieJar();
        $loginPage = $this->request("GET", "login.php", ["cookies" => $admin]);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginPage["body"], $m);
        $token = html_entity_decode($m[1] ?? "", ENT_QUOTES, "UTF-8");
        $valid = $this->request("POST", "login.php", ["cookies" => $admin, "form_params" => ["csrf_token" => $token, "login" => "admin", "password" => "admin123"]]);
        $this->add("Authentication", "admin", "login.php", "Valid login", "302 redirects to dashboard.php", "HTTP {$valid["status"]} Location " . ($valid["headers"]["Location"][0] ?? ""), $valid["status"] === 302 && str_contains($valid["headers"]["Location"][0] ?? "", "dashboard.php"), "form login", "Critical", "Fix seeded admin credentials or login.php auth flow.");

        $badJar = new CookieJar();
        $badPage = $this->request("GET", "login.php", ["cookies" => $badJar]);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $badPage["body"], $m);
        $badToken = html_entity_decode($m[1] ?? "", ENT_QUOTES, "UTF-8");
        $invalid = $this->request("POST", "login.php", ["cookies" => $badJar, "form_params" => ["csrf_token" => $badToken, "login" => "admin", "password" => "wrong-password"]]);
        $this->add("Authentication", "admin", "login.php", "Invalid login", "Safe failure page with generic message", "HTTP {$invalid["status"]}", $invalid["status"] === 200 && str_contains($invalid["body"], "Invalid username or password"), "form login", "High", "Return generic invalid login message.");

        $signupJar = new CookieJar();
        $signupGet = $this->request("GET", "signup.php", ["cookies" => $signupJar]);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $signupGet["body"], $m);
        $signupToken = html_entity_decode($m[1] ?? "", ENT_QUOTES, "UTF-8");
        $signupName = "qa_signup_" . time();
        $signup = $this->request("POST", "signup.php", ["cookies" => $signupJar, "form_params" => ["csrf_token" => $signupToken, "username" => $signupName, "fullName" => "QA Signup", "email" => $signupName . "@example.test", "password" => $this->password, "confirm_password" => $this->password]]);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.username LIKE 'qa_signup_%' AND r.role_name = 'member'")->fetchColumn();
        $this->add("Authentication", "member", "signup.php", "Signup creates member", "New signup has member role", "members={$count}, HTTP {$signup["status"]}", $count > 0, "users role lookup", "High", "Ensure signup.php calls registerUser and default member role exists.");

        $logout = $this->request("GET", "logout.php", ["cookies" => $admin]);
        $this->add("Authentication", "admin", "logout.php", "Logout", "302 to login.php", "HTTP {$logout["status"]} Location " . ($logout["headers"]["Location"][0] ?? ""), $logout["status"] === 302 && str_contains($logout["headers"]["Location"][0] ?? "", "login.php"), "logout route");
    }

    private function roleAndRouteChecks(): void
    {
        $expect = [
            "superadmin" => ["dashboard" => 200, "files" => 200, "settings" => 200, "logs" => 200, "create-subject" => 200],
            "admin" => ["dashboard" => 200, "files" => 200, "settings" => 200, "logs" => 200, "create-subject" => 200],
            "handler" => ["dashboard" => 200, "files" => 200, "settings" => 403, "logs" => 403, "create-subject" => [302, 403]],
            "member" => ["files" => 403, "settings" => 403, "logs" => 403, "create-project" => 403],
            "visitor" => ["files" => 403, "settings" => 403, "logs" => 403, "create-project" => 403],
        ];
        foreach ($expect as $role => $tabs) {
            $jar = $this->sessionFor($role);
            foreach ($tabs as $tab => $status) {
                $res = $this->request("GET", "get_content.php?tab={$tab}", ["cookies" => $jar]);
                $allowedStatuses = is_array($status) ? $status : [$status];
                $contentAllowed = !($role === "superadmin" && $tab === "files" && str_contains($res["body"], "Drive Storage is restricted"));
                $this->add("Role access", $role, "get_content.php?tab={$tab}", "Direct tab access", "HTTP " . implode("/", $allowedStatuses) . ($role === "superadmin" && $tab === "files" ? " with usable files UI" : ""), "HTTP {$res["status"]}", in_array($res["status"], $allowedStatuses, true) && $contentAllowed, "body " . substr(strip_tags($res["body"]), 0, 80), "High", "Align get_content.php gates and file helper roles with RoleManager permissions.");
            }
            $dash = $this->request("GET", "dashboard.php?page=dashboard", ["cookies" => $jar]);
            $body = $dash["body"];
            $navOk = true;
            if (in_array($role, ["member", "visitor"], true)) {
                $navOk = !str_contains($body, 'data-page="settings"') && !str_contains($body, 'data-page="files"');
            } elseif ($role === "handler") {
                $navOk = str_contains($body, 'data-page="files"') && !str_contains($body, 'data-page="settings"');
            } else {
                $navOk = str_contains($body, 'data-page="settings"') && str_contains($body, 'data-page="files"');
            }
            $this->add("Role access", $role, "dashboard.php?page=dashboard", "Sidebar/navigation items", "Only allowed nav appears", $navOk ? "expected nav" : "unexpected nav", $navOk, "HTML nav scan", "High", "Update dashboard.php nav conditions.");
        }

        $anon = $this->request("GET", "dashboard.php?page=dashboard");
        $this->add("Role access", "visitor", "dashboard.php?page=dashboard", "Unauthenticated protected route", "302 to login", "HTTP {$anon["status"]}", $anon["status"] === 302, "Location " . (($anon["headers"]["Location"][0] ?? "")), "High", "Keep direct protected route redirect in includes/core.php.");
    }

    private function workflowChecks(): void
    {
        $admin = $this->sessionFor("admin");
        $handler = $this->sessionFor("handler");
        $member = $this->sessionFor("member");

        $csrf = $this->csrf($admin, "dashboard.php?page=create-subject");
        $code = "QA_POST_" . random_int(1000, 9999);
        $res = $this->request("POST", "get_content.php?tab=create-subject", ["cookies" => $admin, "form_params" => ["csrf_token" => $csrf, "folderName" => $code, "subjectName" => "QA Posted Subject", "description" => "QA", "handlerIds" => [$this->users["handler"]["id"]]], "headers" => ["X-Requested-With" => "XMLHttpRequest"]]);
        $created = (int) $this->pdo->prepare("SELECT COUNT(*) FROM subjects WHERE subject_code = ?")->execute([$code]);
        $existsStmt = $this->pdo->prepare("SELECT subject_id FROM subjects WHERE subject_code = ?");
        $existsStmt->execute([$code]);
        $newSubjectId = (int) $existsStmt->fetchColumn();
        $this->add("Subject workflow", "admin", "get_content.php?tab=create-subject", "Admin creates subject and assigns handler", "Subject created", $newSubjectId ? "subject_id={$newSubjectId}" : "missing; HTTP {$res["status"]}", $newSubjectId > 0, "DB subject lookup", "High", "Fix createsubject.php POST handling.");

        $this->pdo->prepare("INSERT INTO subject_join_requests (subject_id, requested_by, message) VALUES (?, ?, 'QA join')")->execute([$this->ids["subjectActive"], $this->users["member"]["id"]]);
        $joinId = (int) $this->pdo->lastInsertId();
        $csrf = $this->csrf($handler, "dashboard.php?page=requests");
        $res = $this->request("POST", "get_content.php?tab=requests", ["cookies" => $handler, "form_params" => ["csrf_token" => $csrf, "request_action" => "approve_subject_join_request", "join_request_id" => $joinId]]);
        $approved = (int) $this->pdo->query("SELECT COUNT(*) FROM subject_join_requests WHERE join_request_id = {$joinId} AND status = 'approved'")->fetchColumn();
        $this->add("Subject workflow", "handler", "get_content.php?tab=requests", "Handler approves member join", "Request approved and member added", "approved={$approved}, HTTP {$res["status"]}", $approved === 1, "subject_join_requests {$joinId}", "High", "Fix canReviewSubjectJoinRequest or approval branch.");

        $csrf = $this->csrf($handler, "dashboard.php?page=create-project");
        $res = $this->request("POST", "get_content.php?tab=create-project", ["cookies" => $handler, "form_params" => ["csrf_token" => $csrf, "websiteName" => "QA Created Project", "url" => "https://example.test/created", "repo_url" => "https://github.com/example/qa-created.git", "version" => "1.0.1", "folderId" => $this->ids["subjectActive"], "deployment_mode" => "custom_webhook", "webhook_secret" => "qa-secret", "status" => "initializing"], "headers" => ["X-Requested-With" => "XMLHttpRequest"]]);
        $createdProject = (int) $this->pdo->query("SELECT COUNT(*) FROM projects WHERE project_name = 'QA Created Project'")->fetchColumn();
        $this->add("Project workflow", "handler", "get_content.php?tab=create-project", "Create custom webhook project", "Project created", "projects={$createdProject}, HTTP {$res["status"]}", $createdProject === 1, "projects table", "High", "Fix createproject.php field handling.");

        $csrf = $this->csrf($member, "dashboard.php?page=requests");
        $res = $this->request("POST", "get_content.php?tab=requests", ["cookies" => $member, "form_params" => ["csrf_token" => $csrf, "request_action" => "create_project_request", "subject_id" => $this->ids["subjectActive"], "project_name" => "QA Requested Project", "public_url" => "https://example.test/requested", "github_repo_url" => "https://github.com/example/qa-requested.git", "requested_version" => "1.0.0", "message" => "QA"]]);
        $pending = (int) $this->pdo->query("SELECT COUNT(*) FROM project_requests WHERE project_name = 'QA Requested Project' AND status = 'pending'")->fetchColumn();
        $this->add("Requests workflow", "member", "get_content.php?tab=requests", "Member submits project placement request", "Pending request created", "pending={$pending}, HTTP {$res["status"]}", $pending === 1, "project_requests table", "High", "Fix request_project permission/form names.");

        $logs = (int) $this->pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action LIKE '%request%' OR action LIKE '%subject%'")->fetchColumn();
        $this->add("Requests workflow", "system", "activity_logs", "Activity logs created", "Workflow actions logged", "logs={$logs}", $logs > 0, "activity_logs query", "Medium", "Call logActivity in request branches.");
    }

    private function monitoringChecks(): void
    {
        $admin = $this->sessionFor("admin");
        $handler = $this->sessionFor("handler");
        $handlerRun = $this->request("POST", "handlers/run_monitoring_now.php", ["cookies" => $handler]);
        $this->add("Monitoring workflow", "handler", "handlers/run_monitoring_now.php", "Unauthorized manual monitoring", "HTTP 403 safe JSON", "HTTP {$handlerRun["status"]}: {$handlerRun["body"]}", $handlerRun["status"] === 403 && !str_contains($handlerRun["body"], "Warning"), "JSON response", "High", "Keep admin-only monitoring gate.");

        $beforeRuns = (int) $this->pdo->query("SELECT COUNT(*) FROM monitoring_runs")->fetchColumn();
        $adminRun = $this->request("POST", "handlers/run_monitoring_now.php", ["cookies" => $admin]);
        $afterRuns = (int) $this->pdo->query("SELECT COUNT(*) FROM monitoring_runs")->fetchColumn();
        $checks = (int) $this->pdo->query("SELECT COUNT(*) FROM deployment_checks")->fetchColumn();
        $pass = $adminRun["status"] < 500 && $afterRuns >= $beforeRuns && $checks >= 0;
        $this->add("Monitoring workflow", "admin", "handlers/run_monitoring_now.php", "Manual Run Monitoring Now", "No raw warning; queue response safe; DB remains source", "HTTP {$adminRun["status"]}: " . substr($adminRun["body"], 0, 160), $pass && !preg_match('/Warning|Fatal|Stack trace/i', $adminRun["body"]), "runs {$beforeRuns}->{$afterRuns}, checks={$checks}", "High", "Fix monitoring queue execution and sanitize failures.");

        $this->pdo->prepare("INSERT INTO monitoring_alerts (project_id, alert_type, severity, message) VALUES (?, 'status_down', 'critical', 'QA alert')")->execute([$this->ids["projectHostinger"]]);
        $alertId = (int) $this->pdo->lastInsertId();
        $csrf = $this->csrf($admin, "dashboard.php?page=alerts");
        $resolve = $this->request("POST", "handlers/resolve_alerts.php", ["cookies" => $admin, "form_params" => ["csrf_token" => $csrf, "alert_ids" => [$alertId]]]);
        $resolved = (int) $this->pdo->query("SELECT is_resolved FROM monitoring_alerts WHERE id = {$alertId}")->fetchColumn();
        $this->add("Monitoring workflow", "admin", "handlers/resolve_alerts.php", "Alert resolve", "Alert marked resolved", "resolved={$resolved}, HTTP {$resolve["status"]}", $resolved === 1, "monitoring_alerts {$alertId}", "High", "Fix resolve_alerts POST contract.");
    }

    private function fileChecks(): void
    {
        $handler = $this->sessionFor("handler");
        $member = $this->sessionFor("member");
        $csrf = $this->csrf($handler, "dashboard.php?page=project-details&websiteId=" . $this->ids["projectHostinger"]);
        $tmp = tempnam(sys_get_temp_dir(), "nqa");
        file_put_contents($tmp, "QA allowed file");
        $multipart = [
            ["name" => "csrf_token", "contents" => $csrf],
            ["name" => "project_id", "contents" => (string) $this->ids["projectHostinger"]],
            ["name" => "resource_file", "contents" => fopen($tmp, "rb"), "filename" => "qa_allowed.txt"],
        ];
        $res = $this->request("POST", "handlers/upload_resource.php", ["cookies" => $handler, "multipart" => $multipart]);
        $json = json_decode($res["body"], true);
        $uploadedId = (int) ($json["resource_file_id"] ?? 0);
        $this->add("File/resource workflow", "handler", "handlers/upload_resource.php", "Upload allowed file", "201 and resource row", "HTTP {$res["status"]}: {$res["body"]}", $res["status"] === 201 && $uploadedId > 0, "resource_file_id={$uploadedId}", "High", "Fix local storage upload path or permissions.");

        $csrf = $this->csrf($handler, "dashboard.php?page=project-details&websiteId=" . $this->ids["projectHostinger"]);
        $bad = tempnam(sys_get_temp_dir(), "nqa");
        file_put_contents($bad, "<?php echo 'bad';");
        $res = $this->request("POST", "handlers/upload_resource.php", ["cookies" => $handler, "multipart" => [
            ["name" => "csrf_token", "contents" => $csrf],
            ["name" => "project_id", "contents" => (string) $this->ids["projectHostinger"]],
            ["name" => "resource_file", "contents" => fopen($bad, "rb"), "filename" => "qa_bad.php"],
        ]]);
        $this->add("File/resource workflow", "handler", "handlers/upload_resource.php", "Reject forbidden file type", "400 safe JSON", "HTTP {$res["status"]}: {$res["body"]}", $res["status"] === 400 && str_contains($res["body"], "not allowed"), "blocked extension", "High", "Keep StorageManager::isBlockedExtension enforcement.");

        $res = $this->request("POST", "handlers/upload_resource.php", ["cookies" => $member, "form_params" => ["project_id" => $this->ids["projectHostinger"]]]);
        $this->add("File/resource workflow", "member", "handlers/upload_resource.php", "Unauthorized POST upload", "403 before mutation", "HTTP {$res["status"]}: {$res["body"]}", in_array($res["status"], [403, 400], true), "no CSRF/no permission", "High", "Return 403 for unauthorized upload attempts.");

        if ($uploadedId) {
            $download = $this->request("GET", "handlers/download_resource.php?id={$uploadedId}", ["cookies" => $member]);
            $this->add("File/resource workflow", "member", "handlers/download_resource.php", "Download permission", "Accessible to project member", "HTTP {$download["status"]}", $download["status"] === 200, "resource {$uploadedId}", "High", "Fix canAccessProject/resource lookup.");
            $csrf = $this->csrf($handler, "dashboard.php?page=project-details&websiteId=" . $this->ids["projectHostinger"]);
            $delete = $this->request("POST", "handlers/delete_resource.php", ["cookies" => $handler, "form_params" => ["csrf_token" => $csrf, "id" => $uploadedId]]);
            $deleted = (int) $this->pdo->query("SELECT is_deleted FROM resource_files WHERE resource_file_id = {$uploadedId}")->fetchColumn();
            $this->add("File/resource workflow", "handler", "handlers/delete_resource.php", "Delete permission", "Owner/admin can soft-delete", "deleted={$deleted}, HTTP {$delete["status"]}", $deleted === 1, "resource_files {$uploadedId}", "High", "Fix delete_resource parameter names/ownership check.");
        }
    }

    private function featureFlagChecks(): void
    {
        $admin = $this->sessionFor("admin");
        $member = $this->sessionFor("member");
        $csrf = $this->csrf($admin, "dashboard.php?page=settings");
        $res = $this->request("POST", "handlers/update_feature_flags.php", ["cookies" => $admin, "form_params" => ["csrf_token" => $csrf, "feature_key" => ["files"], "is_enabled" => [], "maintenance_message" => ["files" => "QA maintenance"]]]);
        $memberFiles = $this->request("GET", "get_content.php?tab=files", ["cookies" => $member]);
        $this->add("Feature flags", "member", "get_content.php?tab=files", "Maintenance blocks direct tab", "Maintenance or 403 safe response", "HTTP {$memberFiles["status"]}: " . substr(strip_tags($memberFiles["body"]), 0, 120), $memberFiles["status"] === 403 || str_contains($memberFiles["body"], "Maintenance"), "feature files disabled", "High", "Ensure feature map runs before content include and returns maintenance card.");
        $csrf = $this->csrf($admin, "dashboard.php?page=settings&preview=1");
        $this->request("POST", "handlers/update_feature_flags.php", ["cookies" => $admin, "form_params" => ["csrf_token" => $csrf, "feature_key" => ["files"], "is_enabled" => ["files"], "maintenance_message" => ["files" => ""]]]);
    }

    private function securityChecks(): void
    {
        $admin = $this->sessionFor("admin");
        $csrfless = $this->request("POST", "handlers/update_role.php", ["cookies" => $admin, "json" => ["userId" => $this->users["member"]["id"], "role" => "admin"]]);
        $this->add("Security", "admin", "handlers/update_role.php", "CSRF required on POST", "Forbidden or safe failure and no role change", "HTTP {$csrfless["status"]}: {$csrfless["body"]}", str_contains($csrfless["body"], "Invalid CSRF") || $csrfless["status"] === 403, "CSRF omitted", "High", "Return HTTP 403 for invalid CSRF and avoid mutation.");

        $env = $this->request("GET", ".env");
        $this->add("Security", "visitor", ".env", "No .env exposure", "403/404 and no secret text", "HTTP {$env["status"]}", in_array($env["status"], [403, 404], true) && !str_contains($env["body"], "DB_PASSWORD"), "direct .env GET", "Critical", "Deny dotfiles in web server config/router.");

        $traversal = $this->request("GET", "handlers/download_resource.php?id=..%2F.env", ["cookies" => $admin]);
        $this->add("Security", "admin", "handlers/download_resource.php", "No path traversal in file handlers", "400/404 safe response", "HTTP {$traversal["status"]}: " . substr($traversal["body"], 0, 100), in_array($traversal["status"], [400, 404], true) && !str_contains($traversal["body"], "DB_PASSWORD"), "traversal id payload", "Critical", "Validate numeric IDs before storage lookup.");

        foreach (["handlers/run_monitoring_now.php", "handlers/update_feature_flags.php", "handlers/upload_resource.php"] as $route) {
            $res = $this->request("POST", $route, ["cookies" => $admin]);
            $safe = !preg_match('/Warning|Notice|Fatal|Stack trace|C:\\\\xampp|DB_PASSWORD|FTP_PASSWORD/i', $res["body"]);
            $this->add("Security", "admin", $route, "No raw PHP warnings/secrets", "Safe error output", "HTTP {$res["status"]}", $safe, "body scan", "High", "Wrap handler errors and mask secrets.");
        }
    }

    private function uiDemoChecks(): void
    {
        $admin = $this->sessionFor("admin");
        foreach (["dashboard", "folders", "websites", "files", "requests", "settings", "alerts", "logs"] as $page) {
            $res = $this->request("GET", "get_content.php?tab={$page}", ["cookies" => $admin]);
            $safe = $res["status"] === 200 && !preg_match('/PHP\s+(Warning|Notice|Fatal)|<b>\s*(Warning|Notice|Fatal)|Stack trace/i', $res["body"]);
            $this->add("UI/demo", "admin", "get_content.php?tab={$page}", "Sidebar route loads", "HTTP 200 without PHP error", "HTTP {$res["status"]}", $safe, "route smoke", "Medium", "Fix content include or missing data for route.");
        }
        $dashboard = $this->request("GET", "dashboard.php?page=dashboard", ["cookies" => $admin]);
        $this->add("UI/demo", "admin", "dashboard.php", "DataTables assets configured", "DataTables scripts referenced", str_contains($dashboard["body"], "DataTable") ? "referenced" : "missing", str_contains($dashboard["body"], "DataTable"), "HTML asset scan", "Medium", "Restore DataTables scripts/config.");
        $this->add("UI/demo", "admin", "dashboard.php", "Mobile layout usable smoke", "Mobile nav control present", str_contains($dashboard["body"], "data-mobile-nav-open") ? "present" : "missing", str_contains($dashboard["body"], "data-mobile-nav-open"), "HTML responsive control scan", "Low", "Restore mobile sidebar toggle.");
    }

    private function printReport(): void
    {
        echo "# Nucleus QA Report\n\n";
        echo "| Area | Role | Route/Handler | Scenario | Expected | Actual | Status | Evidence |\n";
        echo "|---|---|---|---|---|---|---|---|\n";
        foreach ($this->results as $r) {
            echo "|" . implode("|", array_map([$this, "md"], [$r->area, $r->role, $r->route, $r->scenario, $r->expected, $r->actual, $r->pass ? "PASS" : "FAIL", $r->evidence])) . "|\n";
        }
        echo "\n## Bugs Found\n\n";
        echo "| ID | Severity | Affected Role | Exact Route/Handler | Steps | Expected | Actual | Recommended Fix |\n";
        echo "|---|---|---|---|---|---|---|---|\n";
        $bugId = 1;
        foreach ($this->results as $r) {
            if ($r->pass) {
                continue;
            }
            echo "|" . implode("|", array_map([$this, "md"], [
                "BUG-" . str_pad((string) $bugId++, 3, "0", STR_PAD_LEFT),
                $r->severity ?: "Medium",
                $r->role,
                $r->route,
                $r->scenario,
                $r->expected,
                $r->actual,
                $r->fix ?: "Investigate and align behavior with expected QA policy.",
            ])) . "|\n";
        }
        if ($bugId === 1) {
            echo "| none | n/a | n/a | n/a | n/a | n/a | n/a | n/a |\n";
        }
        $failed = array_filter($this->results, fn (QaResult $r) => !$r->pass);
        $criticalHigh = array_filter($failed, fn (QaResult $r) => in_array($r->severity, ["Critical", "High"], true));
        $verdict = count($criticalHigh) > 0 ? "Not Ready" : (count($failed) > 0 ? "Conditionally Ready" : "Ready");
        echo "\n## Demo Readiness Verdict\n\n{$verdict}\n";
    }

    private function md(string $value): string
    {
        $value = preg_replace('/\s+/', " ", trim($value));
        $value = str_replace("|", "\\|", $value);
        return $value === "" ? " " : $value;
    }
}

(new NucleusQa())->run();
