<?php
// Role-Based Access Control Helper

class RoleManager {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // Get user role
    public function getUserRole($userId) {
        $stmt = $this->pdo->prepare("
            SELECT r.role_name
            FROM users u
            JOIN roles r ON r.role_id = u.role_id
            WHERE u.userId = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user ? $user["role_name"] : null;
    }
    
    // Check if user has specific permission
    public function hasPermission($userId, $permission) {
        $role = $this->getUserRole($userId);
        return in_array($permission, $this->permissionsForRole($role), true);
    }
    
    // Check if user has any of the specified permissions
    public function hasAnyPermission($userId, $permissions) {
        if (empty($permissions)) return false;
        
        return count(array_intersect($permissions, $this->getUserPermissions($userId))) > 0;
    }
    
    // Check if user has all specified permissions
    public function hasAllPermissions($userId, $permissions) {
        if (empty($permissions)) return false;
        
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($userId, $permission)) {
                return false;
            }
        }
        return true;
    }
    
    // Get user permissions
    public function getUserPermissions($userId) {
        return $this->permissionsForRole($this->getUserRole($userId));
    }
    
    // Check if user is admin
    public function isAdmin($userId) {
        return in_array($this->getUserRole($userId), ["admin", "superadmin"], true);
    }

    public function canManageFiles($userId) {
        return in_array($this->getUserRole($userId), ["superadmin", "admin", "handler"], true);
    }
    
    // Require specific permission
    public function requirePermission($userId, $permission) {
        if (!$this->hasPermission($userId, $permission)) {
            throw new Exception("Permission denied: You need $permission permission");
        }
    }
    
    private function permissionsForRole($role) {
        $permissions = [
            "superadmin" => ["create_project", "update_project", "delete_project", "manage_users", "manage_groups", "manage_requests", "view_projects", "view_activity_logs", "request_subject", "request_project", "request_subject_join"],
            "admin" => ["create_project", "update_project", "delete_project", "manage_users", "manage_groups", "manage_requests", "view_projects", "view_activity_logs", "request_subject", "request_project", "request_subject_join"],
            "handler" => ["create_project", "update_project", "view_projects", "request_subject", "request_project", "request_subject_join"],
            "member" => ["view_projects", "request_project", "request_subject_join"],
            "visitor" => [],
        ];
        return $permissions[$role] ?? [];
    }

    public function canAccessProject($userId, $projectId) {
        $role = $this->getUserRole($userId);
        if (in_array($role, ["admin", "superadmin"], true)) {
            return true;
        }

        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM projects p
            LEFT JOIN project_members pm ON pm.project_id = p.project_id AND pm.userId = ?
            LEFT JOIN subject_members sm ON sm.subject_id = p.subject_id AND sm.userId = ?
            WHERE p.project_id = ? AND (p.owner_id = ? OR pm.userId IS NOT NULL OR sm.userId IS NOT NULL)
        ");
        $stmt->execute([$userId, $userId, $projectId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    public function projectAccessSql($alias = "p") {
        if (!isset($_SESSION["userId"])) {
            return ["", []];
        }

        $role = $this->getUserRole($_SESSION["userId"]);
        if (in_array($role, ["admin", "superadmin"], true)) {
            return ["", []];
        }

        $userId = $_SESSION["userId"];
        return [
            " WHERE ({$alias}.owner_id = ?
                OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = {$alias}.project_id AND pm.userId = ?)
                OR EXISTS (SELECT 1 FROM subject_members sm WHERE sm.subject_id = {$alias}.subject_id AND sm.userId = ?)) ",
            [$userId, $userId, $userId],
        ];
    }

    // Get all subjects for a user (admin sees all, handlers see assigned subjects or subjects with assigned projects)
    public function getUserSubjects($userId) {
        $userRole = $this->getUserRole($userId);
        
        if (in_array($userRole, ["admin", "superadmin"], true)) {
            $stmt = $this->pdo->query("SELECT * FROM subjects ORDER BY subject_code ASC");
            return $stmt->fetchAll();
        }

        $stmt = $this->pdo->prepare("
            SELECT DISTINCT s.*
            FROM subjects s
            LEFT JOIN subject_members sm ON sm.subject_id = s.subject_id AND sm.userId = ?
            LEFT JOIN projects p ON p.subject_id = s.subject_id
            LEFT JOIN project_members pm ON pm.project_id = p.project_id AND pm.userId = ?
            WHERE sm.userId IS NOT NULL OR p.owner_id = ? OR pm.userId IS NOT NULL
            ORDER BY s.subject_code ASC
        ");
        $stmt->execute([$userId, $userId, $userId]);
        return $stmt->fetchAll();
    }

    public function canAccessSubject($userId, $subjectId) {
        $role = $this->getUserRole($userId);
        if (in_array($role, ["admin", "superadmin"], true)) {
            return true;
        }

        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM subjects s
            LEFT JOIN subject_members sm ON sm.subject_id = s.subject_id AND sm.userId = ?
            LEFT JOIN projects p ON p.subject_id = s.subject_id
            LEFT JOIN project_members pm ON pm.project_id = p.project_id AND pm.userId = ?
            WHERE s.subject_id = ?
              AND (sm.userId IS NOT NULL OR p.owner_id = ? OR pm.userId IS NOT NULL)
            LIMIT 1
        ");
        $stmt->execute([$userId, $userId, $subjectId, $userId]);
        return (bool) $stmt->fetchColumn();
    }
    
    // Get projects in a specific subject
    public function getProjectsInSubject($subjectId) {
        $stmt = $this->pdo->prepare("
            SELECT p.* FROM projects p
            WHERE p.subject_id = ?
            ORDER BY p.project_name ASC
        ");
        $stmt->execute([$subjectId]);
        return $stmt->fetchAll();
    }
}
?>
