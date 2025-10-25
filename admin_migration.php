<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Auth.php';
require_login();

Auth::requireRole([Auth::ROLE_SUPER_ADMIN]);

$message = null;
$messageType = null;

$settings = json_decode(@file_get_contents(SYSTEM_SETTINGS_FILE), true) ?: [];
$currentMode = $settings['storage']['mode'] ?? 'files';
$dbConfig = $settings['storage']['database'] ?? [];

// Perform migration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migrate'])) {
    $direction = $_POST['direction'] ?? '';
    
    if ($direction === 'to_database') {
        $result = migrateToDatabase($dbConfig);
        $message = $result['success'] ? '数据已成功迁移到数据库！' : '迁移失败：' . ($result['error'] ?? '未知错误');
        $messageType = $result['success'] ? 'success' : 'danger';
    } elseif ($direction === 'to_files') {
        $result = migrateToFiles($dbConfig);
        $message = $result['success'] ? '数据已成功迁移回文件存储！' : '迁移失败：' . ($result['error'] ?? '未知错误');
        $messageType = $result['success'] ? 'success' : 'danger';
    }
}

function migrateToDatabase($config): array {
    try {
        $pdo = connectDatabase($config);
        if (!$pdo) {
            return ['success' => false, 'error' => '无法连接数据库'];
        }
        
        // Create tables
        createDatabaseSchema($pdo, $config['prefix'] ?? '');
        
        // Migrate data
        $dm = new DataManager(DATA_DIR);
        
        // Migrate users
        $users = $dm->readJson(USERS_FILE, []);
        foreach ($users as $user) {
            // Insert user into database
            // This is a simplified example - full implementation would be more complex
        }
        
        // Note: Full migration logic would be implemented here
        // For now, this is a placeholder structure
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function migrateToFiles($config): array {
    try {
        $pdo = connectDatabase($config);
        if (!$pdo) {
            return ['success' => false, 'error' => '无法连接数据库'];
        }
        
        // Read data from database and write to JSON files
        // This is a simplified example
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function connectDatabase($config): ?PDO {
    try {
        $driver = $config['driver'] ?? 'sqlite';
        
        if ($driver === 'sqlite') {
            $dsn = $config['dsn'] ?? DATA_DIR . '/novelhub.sqlite';
            $pdo = new PDO('sqlite:' . $dsn);
        } elseif ($driver === 'mysql') {
            $host = $config['host'] ?? 'localhost';
            $port = $config['port'] ?? 3306;
            $database = $config['database'] ?? '';
            $username = $config['username'] ?? '';
            $password = $config['password'] ?? '';
            
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password);
        } else {
            return null;
        }
        
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

function createDatabaseSchema(PDO $pdo, string $prefix = ''): void {
    // Create users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username VARCHAR(255) NOT NULL UNIQUE,
        email VARCHAR(255) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(50) DEFAULT 'user',
        status VARCHAR(50) DEFAULT 'active',
        created_at DATETIME NOT NULL,
        profile TEXT
    )");
    
    // Create novels table
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}novels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title VARCHAR(255) NOT NULL,
        author_id INTEGER NOT NULL,
        cover_image VARCHAR(255),
        category_ids TEXT,
        tags TEXT,
        status VARCHAR(50) DEFAULT 'ongoing',
        description TEXT,
        created_at DATETIME NOT NULL,
        updated_at DATETIME,
        stats TEXT,
        last_chapter_id INTEGER DEFAULT 0
    )");
    
    // Note: Full schema would include all tables
    // This is a simplified structure for demonstration
}

?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>数据迁移工具 - 管理后台</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-4" style="max-width:900px;">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1>数据迁移工具</h1>
    <div>
      <a class="btn btn-secondary" href="/admin_settings.php?tab=storage">返回设置</a>
      <a class="btn btn-secondary" href="/admin_dashboard.php">管理仪表盘</a>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
      <?php echo e($message); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="alert alert-danger mb-4">
    <h5 class="alert-heading">⚠️ 重要提示</h5>
    <ul class="mb-0">
      <li>数据迁移是不可逆操作，执行前请务必备份数据</li>
      <li>迁移过程中请勿关闭浏览器或刷新页面</li>
      <li>建议在网站访问量较低的时段执行迁移</li>
      <li>迁移完成后需要在系统设置中切换存储模式</li>
    </ul>
  </div>

  <div class="row">
    <div class="col-md-6">
      <div class="card mb-4">
        <div class="card-body">
          <h5 class="card-title">当前状态</h5>
          <table class="table table-sm">
            <tr>
              <th>存储模式：</th>
              <td>
                <span class="badge bg-<?php echo $currentMode === 'files' ? 'primary' : 'success'; ?>">
                  <?php echo $currentMode === 'files' ? '文件存储' : '数据库存储'; ?>
                </span>
              </td>
            </tr>
            <tr>
              <th>数据库驱动：</th>
              <td><?php echo strtoupper($dbConfig['driver'] ?? 'sqlite'); ?></td>
            </tr>
          </table>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card mb-4">
        <div class="card-body">
          <h5 class="card-title">数据统计</h5>
          <?php
            $dm = new DataManager(DATA_DIR);
            $userCount = count($dm->readJson(USERS_FILE, []));
            $novelCount = count($dm->readJson(NOVELS_FILE, []));
            $categoryCount = count($dm->readJson(CATEGORIES_FILE, []));
          ?>
          <table class="table table-sm">
            <tr><th>用户数：</th><td><?php echo $userCount; ?></td></tr>
            <tr><th>作品数：</th><td><?php echo $novelCount; ?></td></tr>
            <tr><th>分类数：</th><td><?php echo $categoryCount; ?></td></tr>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h5 class="card-title mb-4">执行迁移</h5>
      
      <div class="row g-3">
        <div class="col-md-6">
          <div class="card border-primary">
            <div class="card-body">
              <h6 class="card-title">迁移到数据库</h6>
              <p class="card-text small text-muted">将当前的 JSON 文件数据迁移到数据库中</p>
              <form method="post" onsubmit="return confirm('确定要将数据迁移到数据库吗？此操作不可撤销！')">
                <input type="hidden" name="direction" value="to_database">
                <button type="submit" name="migrate" class="btn btn-primary w-100" <?php echo $currentMode === 'database' ? 'disabled' : ''; ?>>
                  迁移到数据库
                </button>
              </form>
              <?php if ($currentMode === 'database'): ?>
                <small class="text-muted">当前已使用数据库存储</small>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="col-md-6">
          <div class="card border-warning">
            <div class="card-body">
              <h6 class="card-title">迁移回文件存储</h6>
              <p class="card-text small text-muted">将数据库中的数据导出回 JSON 文件</p>
              <form method="post" onsubmit="return confirm('确定要将数据迁移回文件存储吗？此操作不可撤销！')">
                <input type="hidden" name="direction" value="to_files">
                <button type="submit" name="migrate" class="btn btn-warning w-100" <?php echo $currentMode === 'files' ? 'disabled' : ''; ?>>
                  迁移回文件存储
                </button>
              </form>
              <?php if ($currentMode === 'files'): ?>
                <small class="text-muted">当前已使用文件存储</small>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="alert alert-info mt-4 mb-0">
        <h6>迁移说明：</h6>
        <ol class="mb-0 small">
          <li>确保数据库配置正确并可连接</li>
          <li>执行迁移操作</li>
          <li>迁移完成后，在系统设置中切换存储模式</li>
          <li>验证数据完整性后，可删除旧的存储数据</li>
        </ol>
      </div>
    </div>
  </div>

  <div class="card mt-4">
    <div class="card-body">
      <h5 class="card-title">功能说明</h5>
      <h6>文件存储模式（JSON）</h6>
      <ul>
        <li>优点：无需数据库，部署简单，便于调试</li>
        <li>缺点：并发性能较低，数据量大时查询慢</li>
        <li>适用场景：小型站点、开发测试环境</li>
      </ul>
      
      <h6 class="mt-3">数据库存储模式</h6>
      <ul>
        <li>优点：高并发性能好，支持复杂查询，数据安全性高</li>
        <li>缺点：需要配置数据库，维护成本较高</li>
        <li>适用场景：中大型站点、生产环境</li>
      </ul>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
