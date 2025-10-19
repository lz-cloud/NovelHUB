<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/DataManager.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$dm = new DataManager(DATA_DIR);

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
    return $u && isset($u['role']) && $u['role'] === 'admin';
}

function e($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

function handle_upload(array $file, string $targetDir): ?string
{
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    if (!is_dir($targetDir)) @mkdir($targetDir, 0775, true);
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = bin2hex(random_bytes(8)) . ($ext ? ('.' . strtolower($ext)) : '');
    $dest = rtrim($targetDir, '/') . '/' . $name;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return $name;
    }
    return null;
}

?>
