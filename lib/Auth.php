<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/DataManager.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

class Auth
{
    public const ROLE_SUPER_ADMIN   = 'super_admin';
    public const ROLE_CONTENT_ADMIN = 'content_admin';
    public const ROLE_ADMIN         = 'admin'; // legacy
    public const ROLE_PLUS          = 'plus';
    public const ROLE_USER          = 'user';

    public static function user()
    {
        return $_SESSION['user'] ?? null;
    }

    public static function roleOf($user): string
    {
        if (!$user) return self::ROLE_USER;
        $r = $user['role'] ?? self::ROLE_USER;
        // Normalize legacy 'admin' to content_admin by default
        if ($r === self::ROLE_ADMIN) return self::ROLE_CONTENT_ADMIN;
        return $r;
    }

    public static function hasAnyRole($user, array $roles): bool
    {
        $role = self::roleOf($user);
        return in_array($role, $roles, true);
    }

    public static function requireRole(array $roles)
    {
        if (!self::hasAnyRole(self::user(), $roles)) {
            http_response_code(403);
            echo '权限不足';
            exit;
        }
    }
}

class OperationLog
{
    /** @var DataManager */
    private $dm;
    public function __construct()
    {
        $this->dm = new DataManager(DATA_DIR);
    }

    public function log(string $action, array $meta = []): void
    {
        $user = Auth::user();
        $row = [
            'id' => null,
            'user_id' => $user['id'] ?? null,
            'username' => $user['username'] ?? null,
            'role' => $user['role'] ?? null,
            'action' => $action,
            'meta' => $meta,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('c'),
        ];
        $this->dm->appendWithId(ADMIN_OPERATIONS_FILE, $row, 'id');
    }
}
