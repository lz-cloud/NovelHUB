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
// Reading-related files
define('READING_PROGRESS_FILE', DATA_DIR . '/reading_progress.json');
define('BOOKMARKS_FILE', DATA_DIR . '/bookmarks.json');
// Download & membership files
define('DOWNLOADS_FILE', DATA_DIR . '/downloads.json');
define('PLUS_MEMBERSHIPS_FILE', DATA_DIR . '/plus_memberships.json');
define('REDEMPTION_CODES_FILE', DATA_DIR . '/redemption_codes.json');
define('INVITATION_CODES_FILE', DATA_DIR . '/invitation_codes.json');
define('EMAIL_VERIFICATIONS_FILE', DATA_DIR . '/email_verifications.json');
define('USER_LIMITS_FILE', DATA_DIR . '/user_limits.json');
define('USER_USAGE_FILE', DATA_DIR . '/user_usage.json');
define('AD_SETTINGS_FILE', DATA_DIR . '/ad_settings.json');

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
    READING_PROGRESS_FILE => '[]',
    BOOKMARKS_FILE => '[]',
    DOWNLOADS_FILE => '[]',
    PLUS_MEMBERSHIPS_FILE => '[]',
    REDEMPTION_CODES_FILE => '[]',
    INVITATION_CODES_FILE => '[]',
    EMAIL_VERIFICATIONS_FILE => '[]',
    USER_LIMITS_FILE => json_encode(['default_limits' => ['enabled' => false, 'daily_chapter_limit' => 0, 'daily_reading_time_limit' => 0, 'concurrent_novels_limit' => 0, 'download_limit_per_day' => 0]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    USER_USAGE_FILE => '[]',
    AD_SETTINGS_FILE => json_encode(['enabled' => false, 'platform' => 'none'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
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

// Extended storage layout (for enhanced features)
// Users
define('USERS_DIR', DATA_DIR . '/users');
define('USER_ACHIEVEMENTS_DIR', USERS_DIR . '/achievements');
// Novels
define('NOVELS_DIR', DATA_DIR . '/novels');
define('NOVEL_METADATA_DIR', NOVELS_DIR . '/metadata');
// covers are stored under uploads/covers
define('NOVEL_REVIEWS_DIR', NOVELS_DIR . '/reviews');
// System-wide
define('SYSTEM_DIR', DATA_DIR . '/system');
define('SYSTEM_CATEGORIES_FILE', DATA_DIR . '/categories.json'); // alias
define('SYSTEM_SETTINGS_FILE', SYSTEM_DIR . '/settings.json');
define('NOTIFICATIONS_FILE', SYSTEM_DIR . '/notifications.json');
define('SYSTEM_STATISTICS_FILE', SYSTEM_DIR . '/statistics.json');
// Admin
define('ADMIN_DIR', DATA_DIR . '/admin');
define('ADMIN_AUDIT_FILE', ADMIN_DIR . '/audit_log.json');
define('ADMIN_OPERATIONS_FILE', ADMIN_DIR . '/operations.json');
// Cache
define('CACHE_DIR', DATA_DIR . '/cache');

// Ensure extended directories/files
$moreDirs = [USERS_DIR, USER_ACHIEVEMENTS_DIR, NOVELS_DIR, NOVEL_METADATA_DIR, NOVEL_REVIEWS_DIR, SYSTEM_DIR, ADMIN_DIR, CACHE_DIR, UPLOADS_DIR . '/exports'];
foreach ($moreDirs as $d) {
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    if (!is_writable($d)) { @chmod($d, 0775); if (!is_writable($d)) @chmod($d, 0777); }
}
$ensureMoreFiles = [
    SYSTEM_SETTINGS_FILE => json_encode(['site_name'=>'NovelHub','logo'=>null,'description'=>'开源小说阅读与创作平台','reading'=>['default_font'=>'system','theme'=>'day'],
        'uploads'=>['max_file_size'=>5*1024*1024,'image_formats'=>['jpg','png','gif','webp']],
        'membership'=>[
            'code_length'=>8,
            'plan_description'=>'兑换码激活 Plus 会员，享受无限下载等权益',
            'free_features'=>[
                '无限阅读所有作品',
                '创建和发布作品',
                '每日下载 3 次',
                '书架收藏功能',
                '阅读进度同步',
                '书签功能'
            ],
            'plus_features'=>[
                '包含免费版所有功能',
                '无限次数下载',
                '支持 TXT、EPUB、PDF 格式',
                '优先获得新功能',
                '专属会员标识',
                '无广告体验'
            ]
        ],
        'invitation_system'=>['enabled'=>false,'code_length'=>8],
        'smtp_settings'=>[
            'enabled'=>false,
            'host'=>'',
            'port'=>587,
            'username'=>'',
            'password'=>'',
            'from_email'=>'',
            'from_name'=>'NovelHub',
            'encryption'=>'tls'
        ],
        'storage'=>[
            'mode'=>'files', // files or database
            'database'=>[
                'driver'=>'sqlite',
                'dsn'=>DATA_DIR . '/novelhub.sqlite',
                'host'=>'localhost',
                'port'=>3306,
                'database'=>'',
                'username'=>'',
                'password'=>'',
                'prefix'=>''
            ]
        ]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    NOTIFICATIONS_FILE => '[]',
    SYSTEM_STATISTICS_FILE => json_encode(['generated_at'=>date('c')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    ADMIN_AUDIT_FILE => '[]',
    ADMIN_OPERATIONS_FILE => '[]',
];
foreach ($ensureMoreFiles as $file => $content) {
    if (!file_exists($file)) { @file_put_contents($file, $content); }
    if (!is_writable($file)) { @chmod($file, 0664); if (!is_writable($file)) @chmod($file, 0666); }
}

?>
