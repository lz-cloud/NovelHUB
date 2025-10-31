<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/InvitationManager.php';
require_once __DIR__ . '/lib/EmailManager.php';
require_login();

Auth::requireRole([Auth::ROLE_SUPER_ADMIN, Auth::ROLE_CONTENT_ADMIN]);

$dm = new DataManager(DATA_DIR);
$logger = new OperationLog();
$invitationMgr = new InvitationManager();
$emailMgr = new EmailManager();

$tab = $_GET['tab'] ?? 'general';
$message = null;
$messageType = null;

// Load settings
$settings = json_decode(@file_get_contents(SYSTEM_SETTINGS_FILE), true) ?: [];

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $saveTab = $_POST['tab'] ?? 'general';
    
    if ($saveTab === 'general') {
        $settings['site_name'] = trim($_POST['site_name'] ?? 'NovelHub');
        $settings['description'] = trim($_POST['description'] ?? '');
        if (!isset($settings['appearance']) || !is_array($settings['appearance'])) {
            $settings['appearance'] = [];
        }
        $siteTheme = $_POST['site_theme'] ?? 'original';
        $allowedSiteThemes = ['original', 'zlibrary'];
        if (!in_array($siteTheme, $allowedSiteThemes, true)) {
            $siteTheme = 'original';
        }
        $settings['appearance']['site_theme'] = $siteTheme;
        if (!isset($settings['reading']) || !is_array($settings['reading'])) {
            $settings['reading'] = [];
        }
        $settings['reading']['default_font'] = $_POST['default_font'] ?? 'system';
        $readingTheme = $_POST['theme'] ?? 'original';
        $allowedReadingThemes = ['original', 'day', 'night', 'eye', 'zlibrary'];
        if (!in_array($readingTheme, $allowedReadingThemes, true)) {
            $readingTheme = 'original';
        }
        $settings['reading']['theme'] = $readingTheme;
        if (!isset($settings['uploads']) || !is_array($settings['uploads'])) {
            $settings['uploads'] = [];
        }
        $settings['uploads']['max_file_size'] = (int)($_POST['max_file_size'] ?? (5*1024*1024));
    } elseif ($saveTab === 'membership') {
        if (!isset($settings['membership']) || !is_array($settings['membership'])) {
            $settings['membership'] = [];
        }
        $settings['membership']['code_length'] = max(4, min(32, (int)($_POST['code_length'] ?? 8)));
        $settings['membership']['plan_description'] = trim($_POST['plan_description'] ?? '');
        $freeFeatures = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $_POST['free_features'] ?? '')));
        $plusFeatures = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $_POST['plus_features'] ?? '')));
        $settings['membership']['free_features'] = array_values($freeFeatures);
        $settings['membership']['plus_features'] = array_values($plusFeatures);
    } elseif ($saveTab === 'invitation') {
        if (!isset($settings['invitation_system']) || !is_array($settings['invitation_system'])) {
            $settings['invitation_system'] = [];
        }
        $settings['invitation_system']['enabled'] = isset($_POST['invitation_enabled']);
        $settings['invitation_system']['code_length'] = max(4, min(32, (int)($_POST['invitation_code_length'] ?? 8)));
    } elseif ($saveTab === 'smtp') {
        if (!isset($settings['smtp_settings']) || !is_array($settings['smtp_settings'])) {
            $settings['smtp_settings'] = [];
        }
        $settings['smtp_settings']['enabled'] = isset($_POST['smtp_enabled']);
        $settings['smtp_settings']['host'] = trim($_POST['smtp_host'] ?? '');
        $settings['smtp_settings']['port'] = (int)($_POST['smtp_port'] ?? 587);
        $settings['smtp_settings']['username'] = trim($_POST['smtp_username'] ?? '');
        if (!empty($_POST['smtp_password'])) {
            $settings['smtp_settings']['password'] = trim($_POST['smtp_password']);
        }
        $settings['smtp_settings']['from_email'] = trim($_POST['smtp_from_email'] ?? '');
        $settings['smtp_settings']['from_name'] = trim($_POST['smtp_from_name'] ?? 'NovelHub');
        $settings['smtp_settings']['encryption'] = $_POST['smtp_encryption'] ?? 'tls';
    } elseif ($saveTab === 'storage') {
        if (!isset($settings['storage']) || !is_array($settings['storage'])) {
            $settings['storage'] = ['database' => []];
        }
        if (!isset($settings['storage']['database']) || !is_array($settings['storage']['database'])) {
            $settings['storage']['database'] = [];
        }
        $settings['storage']['mode'] = $_POST['storage_mode'] ?? 'files';
        $settings['storage']['database']['driver'] = $_POST['db_driver'] ?? 'sqlite';
        $settings['storage']['database']['dsn'] = trim($_POST['db_dsn'] ?? (DATA_DIR . '/novelhub.sqlite'));
        $settings['storage']['database']['host'] = trim($_POST['db_host'] ?? 'localhost');
        $settings['storage']['database']['port'] = (int)($_POST['db_port'] ?? 3306);
        $settings['storage']['database']['database'] = trim($_POST['db_database'] ?? '');
        $settings['storage']['database']['username'] = trim($_POST['db_username'] ?? '');
        if (!empty($_POST['db_password'])) {
            $settings['storage']['database']['password'] = trim($_POST['db_password']);
        }
        $settings['storage']['database']['prefix'] = trim($_POST['db_prefix'] ?? '');
    }
    
    if (file_put_contents(SYSTEM_SETTINGS_FILE, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
        $logger->log('update_system_settings', ['tab' => $saveTab]);
        $message = '设置已保存';
        $messageType = 'success';
    } else {
        $message = '保存失败，请检查文件权限';
        $messageType = 'danger';
    }
    
    // Reload settings
    $settings = json_decode(@file_get_contents(SYSTEM_SETTINGS_FILE), true) ?: [];
}

// Generate invitation code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_invitation'])) {
    $maxUses = (int)($_POST['max_uses'] ?? 1);
    $expiresAt = !empty($_POST['expires_at']) ? date('c', strtotime($_POST['expires_at'])) : null;
    $note = trim($_POST['note'] ?? '');
    
    $user = current_user();
    $code = $invitationMgr->generateCode($maxUses, $expiresAt, (int)$user['id'], $note);
    $logger->log('generate_invitation_code', ['code' => $code]);
    $message = "邀请码生成成功：<strong>$code</strong>";
    $messageType = 'success';
}

// Disable invitation code
if (isset($_GET['action']) && $_GET['action'] === 'disable_invitation') {
    $codeId = (int)($_GET['code_id'] ?? 0);
    if ($codeId > 0) {
        $invitationMgr->disableCode($codeId);
        $logger->log('disable_invitation_code', ['code_id' => $codeId]);
        header('Location: /admin_settings.php?tab=invitation');
        exit;
    }
}

// Test database connection
if (isset($_GET['action']) && $_GET['action'] === 'test_db') {
    $testResult = testDatabaseConnection($settings['storage']['database'] ?? []);
    $message = $testResult['success'] ? '数据库连接成功！' : '数据库连接失败：' . ($testResult['error'] ?? '未知错误');
    $messageType = $testResult['success'] ? 'success' : 'danger';
}

// Get invitation codes
$invitationCodes = $invitationMgr->getAllCodes();
usort($invitationCodes, function($a, $b) {
    return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
});

function testDatabaseConnection($config): array {
    try {
        $driver = $config['driver'] ?? 'sqlite';
        
        if ($driver === 'sqlite') {
            $dsn = $config['dsn'] ?? DATA_DIR . '/novelhub.sqlite';
            $pdo = new PDO('sqlite:' . $dsn);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return ['success' => true];
        } elseif ($driver === 'mysql') {
            $host = $config['host'] ?? 'localhost';
            $port = $config['port'] ?? 3306;
            $database = $config['database'] ?? '';
            $username = $config['username'] ?? '';
            $password = $config['password'] ?? '';
            
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return ['success' => true];
        }
        
        return ['success' => false, 'error' => '不支持的数据库驱动'];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>系统设置 - 管理后台</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1>系统设置</h1>
    <div>
      <a class="btn btn-secondary" href="/admin_dashboard.php">管理仪表盘</a>
      <a class="btn btn-secondary" href="/">首页</a>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
      <?php echo $message; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <ul class="nav nav-tabs mb-4">
    <li class="nav-item">
      <a class="nav-link <?php echo $tab === 'general' ? 'active' : ''; ?>" href="?tab=general">基本设置</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?php echo $tab === 'membership' ? 'active' : ''; ?>" href="?tab=membership">会员设置</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?php echo $tab === 'invitation' ? 'active' : ''; ?>" href="?tab=invitation">邀请码系统</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?php echo $tab === 'smtp' ? 'active' : ''; ?>" href="?tab=smtp">邮件设置</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?php echo $tab === 'storage' ? 'active' : ''; ?>" href="?tab=storage">存储设置</a>
    </li>
  </ul>

  <?php if ($tab === 'general'): ?>
    <div class="row">
      <div class="col-md-8">
        <div class="card">
          <div class="card-body">
            <h5 class="card-title">基本设置</h5>
            <form method="post">
              <input type="hidden" name="tab" value="general">
              <div class="mb-3">
                <label class="form-label">站点名称</label>
                <input class="form-control" name="site_name" value="<?php echo e($settings['site_name'] ?? 'NovelHub'); ?>" required>
              </div>
              <div class="mb-3">
                <label class="form-label">站点描述</label>
                <textarea class="form-control" name="description" rows="3"><?php echo e($settings['description'] ?? ''); ?></textarea>
              </div>
              <div class="mb-3">
                <label class="form-label">站点主题</label>
                <select class="form-select" name="site_theme">
                  <option value="original" <?php if(($settings['appearance']['site_theme'] ?? 'original')==='original') echo 'selected'; ?>>原版</option>
                  <option value="zlibrary" <?php if(($settings['appearance']['site_theme'] ?? '')==='zlibrary') echo 'selected'; ?>>Z-Library</option>
                </select>
                <div class="form-text">设置站点首页和仪表盘等页面的主题风格</div>
              </div>
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label">默认字体</label>
                  <select class="form-select" name="default_font">
                    <option value="system" <?php if(($settings['reading']['default_font'] ?? 'system')==='system') echo 'selected'; ?>>系统</option>
                    <option value="serif" <?php if(($settings['reading']['default_font'] ?? '')==='serif') echo 'selected'; ?>>衬线</option>
                    <option value="kaiti" <?php if(($settings['reading']['default_font'] ?? '')==='kaiti') echo 'selected'; ?>>楷体</option>
                    <option value="heiti" <?php if(($settings['reading']['default_font'] ?? '')==='heiti') echo 'selected'; ?>>黑体</option>
                  </select>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">默认主题</label>
                  <select class="form-select" name="theme">
                    <option value="original" <?php if(($settings['reading']['theme'] ?? 'day')==='original') echo 'selected'; ?>>原版</option>
                    <option value="day" <?php if(($settings['reading']['theme'] ?? 'day')==='day') echo 'selected'; ?>>日间</option>
                    <option value="night" <?php if(($settings['reading']['theme'] ?? '')==='night') echo 'selected'; ?>>夜间</option>
                    <option value="eye" <?php if(($settings['reading']['theme'] ?? '')==='eye') echo 'selected'; ?>>护眼</option>
                    <option value="zlibrary" <?php if(($settings['reading']['theme'] ?? '')==='zlibrary') echo 'selected'; ?>>Z-Library</option>
                  </select>
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">上传大小限制（字节）</label>
                <input class="form-control" type="number" name="max_file_size" value="<?php echo (int)($settings['uploads']['max_file_size'] ?? (5*1024*1024)); ?>" required>
                <div class="form-text">默认：5242880（5MB）</div>
              </div>
              <button type="submit" name="save_settings" class="btn btn-primary">保存设置</button>
            </form>
          </div>
        </div>
      </div>
    </div>

  <?php elseif ($tab === 'membership'): ?>
    <div class="row">
      <div class="col-md-8">
        <div class="card">
          <div class="card-body">
            <h5 class="card-title">会员与兑换码设置</h5>
            <form method="post">
              <input type="hidden" name="tab" value="membership">
              <div class="mb-3">
                <label class="form-label">兑换码长度</label>
                <input class="form-control" type="number" name="code_length" value="<?php echo (int)($settings['membership']['code_length'] ?? 8); ?>" min="4" max="32" required>
                <div class="form-text">设置生成兑换码的字符长度（4-32位）</div>
              </div>
              <div class="mb-3">
                <label class="form-label">Plan 界面描述</label>
                <textarea class="form-control" name="plan_description" rows="3"><?php echo e($settings['membership']['plan_description'] ?? ''); ?></textarea>
                <div class="form-text">在会员计划页面显示的说明文字</div>
              </div>
              <div class="mb-3">
                <label class="form-label">免费版功能（每行一个）</label>
                <textarea class="form-control" name="free_features" rows="6"><?php 
                  $freeFeatures = $settings['membership']['free_features'] ?? [];
                  echo e(is_array($freeFeatures) ? implode("\n", $freeFeatures) : '');
                ?></textarea>
                <div class="form-text">每行输入一个功能特性，将显示在会员计划页面</div>
              </div>
              <div class="mb-3">
                <label class="form-label">Plus 会员功能（每行一个）</label>
                <textarea class="form-control" name="plus_features" rows="6"><?php 
                  $plusFeatures = $settings['membership']['plus_features'] ?? [];
                  echo e(is_array($plusFeatures) ? implode("\n", $plusFeatures) : '');
                ?></textarea>
                <div class="form-text">每行输入一个功能特性，将显示在会员计划页面</div>
              </div>
              <button type="submit" name="save_settings" class="btn btn-primary">保存设置</button>
            </form>
          </div>
        </div>
      </div>
    </div>

  <?php elseif ($tab === 'invitation'): ?>
    <div class="row">
      <div class="col-md-4">
        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title">邀请码系统设置</h5>
            <form method="post">
              <input type="hidden" name="tab" value="invitation">
              <div class="mb-3">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="invitation_enabled" name="invitation_enabled" <?php if($settings['invitation_system']['enabled'] ?? false) echo 'checked'; ?>>
                  <label class="form-check-label" for="invitation_enabled">启用邀请码注册</label>
                </div>
                <div class="form-text">开启后，新用户注册需要输入有效的邀请码</div>
              </div>
              <div class="mb-3">
                <label class="form-label">邀请码长度</label>
                <input class="form-control" type="number" name="invitation_code_length" value="<?php echo (int)($settings['invitation_system']['code_length'] ?? 8); ?>" min="4" max="32" required>
              </div>
              <button type="submit" name="save_settings" class="btn btn-primary">保存设置</button>
            </form>
          </div>
        </div>

        <div class="card">
          <div class="card-body">
            <h5 class="card-title">生成邀请码</h5>
            <form method="post">
              <div class="mb-3">
                <label class="form-label">最大使用次数</label>
                <input type="number" class="form-control" name="max_uses" value="1" min="1" required>
              </div>
              <div class="mb-3">
                <label class="form-label">有效期（可选）</label>
                <input type="datetime-local" class="form-control" name="expires_at">
                <div class="form-text">留空表示永不过期</div>
              </div>
              <div class="mb-3">
                <label class="form-label">备注（可选）</label>
                <input class="form-control" name="note" placeholder="例如：活动发放">
              </div>
              <button type="submit" name="generate_invitation" class="btn btn-success w-100">生成邀请码</button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-md-8">
        <div class="card">
          <div class="card-body">
            <h5 class="card-title">邀请码列表</h5>
            <div class="table-responsive">
              <table class="table table-hover">
                <thead>
                  <tr>
                    <th>邀请码</th>
                    <th>使用次数</th>
                    <th>状态</th>
                    <th>有效期</th>
                    <th>备注</th>
                    <th>创建时间</th>
                    <th>操作</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($invitationCodes as $c): ?>
                    <tr>
                      <td><code><?php echo e($c['code'] ?? ''); ?></code></td>
                      <td><?php echo (int)($c['used_count'] ?? 0); ?> / <?php echo (int)($c['max_uses'] ?? 1); ?></td>
                      <td>
                        <?php
                          $status = $c['status'] ?? 'active';
                          $badgeClass = $status === 'active' ? 'success' : ($status === 'used' ? 'secondary' : 'danger');
                          $statusText = $status === 'active' ? '有效' : ($status === 'used' ? '已用完' : '已禁用');
                        ?>
                        <span class="badge bg-<?php echo $badgeClass; ?>"><?php echo $statusText; ?></span>
                      </td>
                      <td><?php echo $c['expires_at'] ? date('Y-m-d H:i', strtotime($c['expires_at'])) : '永久'; ?></td>
                      <td><?php echo e($c['note'] ?? '-'); ?></td>
                      <td><?php echo date('Y-m-d H:i', strtotime($c['created_at'])); ?></td>
                      <td>
                        <?php if ($status === 'active'): ?>
                          <a href="?action=disable_invitation&code_id=<?php echo (int)$c['id']; ?>&tab=invitation" class="btn btn-sm btn-outline-danger" onclick="return confirm('确定要禁用此邀请码吗？')">禁用</a>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$invitationCodes): ?>
                    <tr><td colspan="7" class="text-center text-muted">暂无邀请码</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

  <?php elseif ($tab === 'smtp'): ?>
    <div class="row">
      <div class="col-md-8">
        <div class="card">
          <div class="card-body">
            <h5 class="card-title">SMTP 邮件设置</h5>
            <form method="post">
              <input type="hidden" name="tab" value="smtp">
              <div class="mb-3">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="smtp_enabled" name="smtp_enabled" <?php if($settings['smtp_settings']['enabled'] ?? false) echo 'checked'; ?>>
                  <label class="form-check-label" for="smtp_enabled">启用 SMTP 邮件验证</label>
                </div>
                <div class="form-text">开启后，新用户注册需要验证邮箱后才能登录</div>
              </div>
              <div class="row">
                <div class="col-md-8 mb-3">
                  <label class="form-label">SMTP 主机</label>
                  <input class="form-control" name="smtp_host" value="<?php echo e($settings['smtp_settings']['host'] ?? ''); ?>" placeholder="smtp.example.com">
                </div>
                <div class="col-md-4 mb-3">
                  <label class="form-label">端口</label>
                  <input class="form-control" type="number" name="smtp_port" value="<?php echo (int)($settings['smtp_settings']['port'] ?? 587); ?>">
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">加密方式</label>
                <select class="form-select" name="smtp_encryption">
                  <option value="tls" <?php if(($settings['smtp_settings']['encryption'] ?? 'tls')==='tls') echo 'selected'; ?>>TLS</option>
                  <option value="ssl" <?php if(($settings['smtp_settings']['encryption'] ?? '')==='ssl') echo 'selected'; ?>>SSL</option>
                  <option value="" <?php if(empty($settings['smtp_settings']['encryption'])) echo 'selected'; ?>>无</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">SMTP 用户名</label>
                <input class="form-control" name="smtp_username" value="<?php echo e($settings['smtp_settings']['username'] ?? ''); ?>">
              </div>
              <div class="mb-3">
                <label class="form-label">SMTP 密码</label>
                <input type="password" class="form-control" name="smtp_password" placeholder="留空则不修改">
                <div class="form-text">密码将被加密存储</div>
              </div>
              <div class="mb-3">
                <label class="form-label">发件人邮箱</label>
                <input type="email" class="form-control" name="smtp_from_email" value="<?php echo e($settings['smtp_settings']['from_email'] ?? ''); ?>" placeholder="noreply@example.com">
              </div>
              <div class="mb-3">
                <label class="form-label">发件人名称</label>
                <input class="form-control" name="smtp_from_name" value="<?php echo e($settings['smtp_settings']['from_name'] ?? 'NovelHub'); ?>">
              </div>
              <button type="submit" name="save_settings" class="btn btn-primary">保存设置</button>
            </form>
          </div>
        </div>
      </div>
    </div>

  <?php elseif ($tab === 'storage'): ?>
    <div class="row">
      <div class="col-md-8">
        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title">存储方式设置</h5>
            <form method="post">
              <input type="hidden" name="tab" value="storage">
              <div class="mb-3">
                <label class="form-label">存储模式</label>
                <select class="form-select" name="storage_mode" id="storage_mode">
                  <option value="files" <?php if(($settings['storage']['mode'] ?? 'files')==='files') echo 'selected'; ?>>文件存储（JSON）</option>
                  <option value="database" <?php if(($settings['storage']['mode'] ?? '')==='database') echo 'selected'; ?>>数据库存储</option>
                </select>
                <div class="form-text">切换存储方式需要进行数据迁移</div>
              </div>
              
              <div id="database_settings" style="<?php echo ($settings['storage']['mode'] ?? 'files') === 'files' ? 'display:none' : ''; ?>">
                <hr>
                <h6>数据库配置</h6>
                <div class="mb-3">
                  <label class="form-label">数据库驱动</label>
                  <select class="form-select" name="db_driver" id="db_driver">
                    <option value="sqlite" <?php if(($settings['storage']['database']['driver'] ?? 'sqlite')==='sqlite') echo 'selected'; ?>>SQLite</option>
                    <option value="mysql" <?php if(($settings['storage']['database']['driver'] ?? '')==='mysql') echo 'selected'; ?>>MySQL</option>
                  </select>
                </div>
                
                <div id="sqlite_settings" style="<?php echo ($settings['storage']['database']['driver'] ?? 'sqlite') === 'mysql' ? 'display:none' : ''; ?>">
                  <div class="mb-3">
                    <label class="form-label">SQLite 文件路径</label>
                    <input class="form-control" name="db_dsn" value="<?php echo e($settings['storage']['database']['dsn'] ?? DATA_DIR . '/novelhub.sqlite'); ?>">
                  </div>
                </div>
                
                <div id="mysql_settings" style="<?php echo ($settings['storage']['database']['driver'] ?? 'sqlite') === 'sqlite' ? 'display:none' : ''; ?>">
                  <div class="row">
                    <div class="col-md-8 mb-3">
                      <label class="form-label">主机</label>
                      <input class="form-control" name="db_host" value="<?php echo e($settings['storage']['database']['host'] ?? 'localhost'); ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                      <label class="form-label">端口</label>
                      <input class="form-control" type="number" name="db_port" value="<?php echo (int)($settings['storage']['database']['port'] ?? 3306); ?>">
                    </div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">数据库名</label>
                    <input class="form-control" name="db_database" value="<?php echo e($settings['storage']['database']['database'] ?? ''); ?>">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">用户名</label>
                    <input class="form-control" name="db_username" value="<?php echo e($settings['storage']['database']['username'] ?? ''); ?>">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">密码</label>
                    <input type="password" class="form-control" name="db_password" placeholder="留空则不修改">
                  </div>
                </div>
                
                <div class="mb-3">
                  <label class="form-label">表前缀</label>
                  <input class="form-control" name="db_prefix" value="<?php echo e($settings['storage']['database']['prefix'] ?? ''); ?>" placeholder="例如：nh_">
                </div>
              </div>
              
              <button type="submit" name="save_settings" class="btn btn-primary">保存配置</button>
              <a href="?action=test_db&tab=storage" class="btn btn-outline-secondary">测试连接</a>
            </form>
          </div>
        </div>

        <div class="card">
          <div class="card-body">
            <h5 class="card-title">数据迁移</h5>
            <p class="text-muted">在切换存储模式前，请先配置好目标存储的相关设置。</p>
            <div class="alert alert-warning">
              <strong>注意：</strong>数据迁移是不可逆操作，请在迁移前备份数据！
            </div>
            <div class="d-grid gap-2">
              <a href="/admin_migration.php" class="btn btn-warning">前往数据迁移工具</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Toggle database settings visibility
document.getElementById('storage_mode')?.addEventListener('change', function() {
  const dbSettings = document.getElementById('database_settings');
  if (this.value === 'database') {
    dbSettings.style.display = 'block';
  } else {
    dbSettings.style.display = 'none';
  }
});

document.getElementById('db_driver')?.addEventListener('change', function() {
  const sqliteSettings = document.getElementById('sqlite_settings');
  const mysqlSettings = document.getElementById('mysql_settings');
  if (this.value === 'sqlite') {
    sqliteSettings.style.display = 'block';
    mysqlSettings.style.display = 'none';
  } else {
    sqliteSettings.style.display = 'none';
    mysqlSettings.style.display = 'block';
  }
});
</script>
</body>
</html>
