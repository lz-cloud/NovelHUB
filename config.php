<?php
// NovelHub basic configuration
// Adjust paths as needed for your environment.

// Base directories (absolute recommended). For simplicity we derive from this file's directory.
$BASE_DIR = __DIR__;

define('DATA_DIR', $BASE_DIR . '/data');
define('UPLOADS_DIR', $BASE_DIR . '/uploads');
define('COVERS_DIR', UPLOADS_DIR . '/covers');
define('AVATARS_DIR', UPLOADS_DIR . '/avatars');
define('CHAPTERS_DIR', $BASE_DIR . '/chapters');

// Files
define('USERS_FILE', DATA_DIR . '/users.json');
define('NOVELS_FILE', DATA_DIR . '/novels.json');
define('CATEGORIES_FILE', DATA_DIR . '/categories.json');
define('BOOKSHELVES_FILE', DATA_DIR . '/user_bookshelves.json');

// General settings
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');

// Ensure required directories exist at runtime and are writable
$dirs = [DATA_DIR, UPLOADS_DIR, COVERS_DIR, AVATARS_DIR, CHAPTERS_DIR];
foreach ($dirs as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    if (!is_writable($d)) {
        @chmod($d, 0775);
        if (!is_writable($d)) {
            @chmod($d, 0777);
        }
    }
}

// Ensure core data files exist and are writable
$ensureFiles = [
    USERS_FILE => '[]',
    NOVELS_FILE => '[]',
    BOOKSHELVES_FILE => '[]',
];
foreach ($ensureFiles as $file => $defaultContent) {
    if (!file_exists($file)) {
        @file_put_contents($file, $defaultContent);
    }
    if (!is_writable($file)) {
        @chmod($file, 0664);
        if (!is_writable($file)) {
            @chmod($file, 0666);
        }
    }
}
// Categories file with basic defaults if missing
if (!file_exists(CATEGORIES_FILE)) {
    $defaultCats = [
        ['id'=>1,'name'=>'玄幻','slug'=>'xuanhuan','created_at'=>date('c')],
        ['id'=>2,'name'=>'科幻','slug'=>'kehuan','created_at'=>date('c')],
        ['id'=>3,'name'=>'都市','slug'=>'dushi','created_at'=>date('c')],
    ];
    @file_put_contents(CATEGORIES_FILE, json_encode($defaultCats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @chmod(CATEGORIES_FILE, 0664);
} else {
    if (!is_writable(CATEGORIES_FILE)) {
        @chmod(CATEGORIES_FILE, 0664);
        if (!is_writable(CATEGORIES_FILE)) {
            @chmod(CATEGORIES_FILE, 0666);
        }
    }
}

// Default admin bootstrap (username/password used on first run; can be changed later)
define('DEFAULT_ADMIN_USERNAME', 'admin');
define('DEFAULT_ADMIN_EMAIL', 'admin@example.com');
define('DEFAULT_ADMIN_PASSWORD', 'Admin@123');

?>
