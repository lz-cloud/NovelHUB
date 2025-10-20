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

// Ensure required directories exist at runtime
$dirs = [DATA_DIR, UPLOADS_DIR, COVERS_DIR, AVATARS_DIR, CHAPTERS_DIR];
foreach ($dirs as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
}

?>
