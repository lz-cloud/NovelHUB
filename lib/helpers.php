<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/DataManager.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$dm = new DataManager(DATA_DIR);

// Ensure a default admin exists on first run
function ensure_default_admin() {
    global $dm;
    // Read current users; if none, create default admin
    $users = $dm->readJson(USERS_FILE, []);
    if (!$users || count($users) === 0) {
        $now = date('c');
        $admin = [
            'username' => defined('DEFAULT_ADMIN_USERNAME') ? DEFAULT_ADMIN_USERNAME : 'admin',
            'email' => defined('DEFAULT_ADMIN_EMAIL') ? DEFAULT_ADMIN_EMAIL : 'admin@example.com',
            'password_hash' => password_hash(defined('DEFAULT_ADMIN_PASSWORD') ? DEFAULT_ADMIN_PASSWORD : 'Admin@123', PASSWORD_DEFAULT),
            'role' => 'admin',
            'status' => 'active',
            'created_at' => $now,
            'profile' => [
                'nickname' => '管理员',
                'avatar' => null,
                'bio' => '系统初始管理员账号',
            ],
        ];
        $dm->appendWithId(USERS_FILE, $admin);
    }
}
ensure_default_admin();

function get_system_settings(): array
{
    $settings = json_decode(@file_get_contents(SYSTEM_SETTINGS_FILE), true);
    return is_array($settings) ? $settings : [];
}

function get_site_theme(): string
{
    $settings = get_system_settings();
    $theme = $settings['appearance']['site_theme'] ?? 'original';
    $allowed = ['original', 'zlibrary'];
    return in_array($theme, $allowed, true) ? $theme : 'original';
}

function get_default_reading_theme(): string
{
    $settings = get_system_settings();
    $theme = $settings['reading']['theme'] ?? 'original';
    $allowed = ['original', 'day', 'night', 'eye', 'zlibrary'];
    return in_array($theme, $allowed, true) ? $theme : 'original';
}

function site_theme_class(string $extraClasses = ''): string
{
    $themeClass = 'site-theme-' . get_site_theme();
    $extraClasses = trim($extraClasses);
    return trim($extraClasses . ' ' . $themeClass);
}

function current_user()
{
    return $_SESSION['user'] ?? null;
}

function require_login()
{
    if (!current_user()) {
        header('Location: /login.php');
        exit;
    }
}

function is_admin(): bool
{
    $u = current_user();
    $role = $u['role'] ?? 'user';
    return in_array($role, ['admin','content_admin','super_admin'], true);
}

function e($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function validate_required($value, $fieldName = 'Field'): ?string
{
    if (empty(trim($value))) {
        return "{$fieldName} is required";
    }
    return null;
}

function validate_email($email): ?string
{
    if (empty(trim($email))) {
        return "Email is required";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Invalid email format";
    }
    return null;
}

function validate_length($value, $min, $max, $fieldName = 'Field'): ?string
{
    $len = mb_strlen($value);
    if ($len < $min) {
        return "{$fieldName} must be at least {$min} characters";
    }
    if ($len > $max) {
        return "{$fieldName} must not exceed {$max} characters";
    }
    return null;
}

function validate_numeric($value, $fieldName = 'Field'): ?string
{
    if (!is_numeric($value)) {
        return "{$fieldName} must be a number";
    }
    return null;
}

function validate_range($value, $min, $max, $fieldName = 'Field'): ?string
{
    if (!is_numeric($value)) {
        return "{$fieldName} must be a number";
    }
    $num = (float)$value;
    if ($num < $min || $num > $max) {
        return "{$fieldName} must be between {$min} and {$max}";
    }
    return null;
}

function load_users(): array
{
    global $dm;
    return $dm->readJson(USERS_FILE, []);
}

function save_user(array $user): int
{
    global $dm;
    return $dm->appendWithId(USERS_FILE, $user);
}

function find_user_by_login(string $login)
{
    $login = trim($login);
    $users = load_users();
    foreach ($users as $u) {
        if (strcasecmp($u['username'] ?? '', $login) === 0 || strcasecmp($u['email'] ?? '', $login) === 0) {
            return $u;
        }
    }
    return null;
}

function update_user_session(int $userId)
{
    global $dm;
    $user = $dm->findById(USERS_FILE, $userId);
    if ($user) {
        $_SESSION['user'] = $user;
    }
}

function user_extra_path(int $userId): string
{
    return USERS_DIR . '/' . $userId . '.json';
}

function user_extra_load(int $userId): array
{
    $path = user_extra_path($userId);
    if (!file_exists($path)) {
        return ['shelf_categories' => ['默认']];
    }
    $data = json_decode(@file_get_contents($path), true);
    if (!is_array($data)) {
        $data = [];
    }
    if (empty($data['shelf_categories']) || !is_array($data['shelf_categories'])) {
        $data['shelf_categories'] = ['默认'];
    }
    return $data;
}

function user_extra_save(int $userId, array $data): bool
{
    $path = user_extra_path($userId);
    @mkdir(dirname($path), 0775, true);
    return (bool)file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function ensure_novel_dir(int $novelId)
{
    $dir = CHAPTERS_DIR . '/novel_' . $novelId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function load_novels(): array
{
    global $dm;
    return $dm->readJson(NOVELS_FILE, []);
}

function save_novel(array $novel): int
{
    global $dm;
    return $dm->appendWithId(NOVELS_FILE, $novel);
}

function update_novel(int $novelId, array $data): bool
{
    global $dm;
    return $dm->updateById(NOVELS_FILE, $novelId, $data);
}

function find_novel(int $id)
{
    global $dm;
    return $dm->findById(NOVELS_FILE, $id);
}

function get_user_display_name(int $userId): string
{
    global $dm;
    $u = $dm->findById(USERS_FILE, $userId);
    if (!$u) return 'Unknown';
    if (!empty($u['profile']['nickname'])) return $u['profile']['nickname'];
    return $u['username'] ?? ('user#' . $userId);
}

function list_chapters(int $novelId, string $status = null): array
{
    $dir = ensure_novel_dir($novelId);
    $files = glob($dir . '/*.json');
    $chapters = [];
    foreach ($files as $f) {
        $raw = file_get_contents($f);
        $c = json_decode($raw, true);
        if (!$c) continue;
        if ($status && ($c['status'] ?? 'published') !== $status) continue;
        $chapters[] = $c;
    }
    usort($chapters, function($a, $b){ return ($a['id'] ?? 0) <=> ($b['id'] ?? 0); });
    return $chapters;
}

function next_chapter_id(int $novelId): int
{
    $chapters = list_chapters($novelId);
    $max = 0;
    foreach ($chapters as $c) {
        $max = max($max, (int)($c['id'] ?? 0));
    }
    return $max + 1;
}

function delete_novel(int $novelId): bool
{
    global $dm;
    
    // First delete the novel from the JSON file
    if (!$dm->deleteById(NOVELS_FILE, $novelId)) {
        return false;
    }
    
    // Delete all chapters directory
    $chaptersDir = CHAPTERS_DIR . '/novel_' . $novelId;
    if (is_dir($chaptersDir)) {
        // Delete all chapter files
        $files = glob($chaptersDir . '/*.json');
        foreach ($files as $file) {
            @unlink($file);
        }
        // Remove the directory
        @rmdir($chaptersDir);
    }
    
    // Remove from bookshelves
    $bookshelves = $dm->readJson(BOOKSHELVES_FILE, []);
    $bookshelves = array_values(array_filter($bookshelves, function($item) use ($novelId) {
        return (int)($item['novel_id'] ?? 0) !== $novelId;
    }));
    $dm->writeJson(BOOKSHELVES_FILE, $bookshelves);
    
    return true;
}

function handle_upload(array $file, string $targetDir): ?string
{
    if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if (!is_uploaded_file($file['tmp_name'])) return null;

    // Limit size to 5MB
    $maxSize = 5 * 1024 * 1024; // 5 MiB
    if (!empty($file['size']) && (int)$file['size'] > $maxSize) return null;

    if (!is_dir($targetDir)) @mkdir($targetDir, 0775, true);

    // Detect MIME and validate type
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    $mime = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    }

    // Fallback to extension mapping if MIME unknown
    if (!$mime) {
        $extFromName = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        $mime = $extFromName === 'jpg' || $extFromName === 'jpeg' ? 'image/jpeg'
            : ($extFromName === 'png' ? 'image/png'
            : ($extFromName === 'gif' ? 'image/gif'
            : ($extFromName === 'webp' ? 'image/webp' : null)));
    }

    if (!$mime || !isset($allowed[$mime])) return null;

    // Basic image sanity check
    $imgInfo = @getimagesize($file['tmp_name']);
    if ($imgInfo === false) return null;
    $width  = $imgInfo[0] ?? 0;
    $height = $imgInfo[1] ?? 0;
    if ($width <= 0 || $height <= 0 || $width > 6000 || $height > 6000) return null;

    // Generate safe random filename with extension derived from MIME
    $ext = $allowed[$mime];
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = rtrim($targetDir, '/') . '/' . $name;

    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }
    @chmod($dest, 0664);
    return $name;
}

?>
