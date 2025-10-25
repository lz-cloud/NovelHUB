<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/UserLimits.php';
require_login();

Auth::requireRole([Auth::ROLE_SUPER_ADMIN, Auth::ROLE_CONTENT_ADMIN]);

$dm = new DataManager(DATA_DIR);
$logger = new OperationLog();
$limitsManager = new UserLimits();

$message = null;
$messageType = null;
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_default_limits') {
    $limits = [
        'enabled' => isset($_POST['enabled']),
        'daily_chapter_limit' => max(0, (int)($_POST['daily_chapter_limit'] ?? 0)),
        'daily_reading_time_limit' => max(0, (int)($_POST['daily_reading_time_limit'] ?? 0)),
        'concurrent_novels_limit' => max(0, (int)($_POST['concurrent_novels_limit'] ?? 0)),
        'download_limit_per_day' => max(0, (int)($_POST['download_limit_per_day'] ?? 0)),
    ];
    
    if ($limitsManager->setDefaultLimits($limits)) {
        $logger->log('update_default_user_limits', $limits);
        $message = '默认限制设置已更新';
        $messageType = 'success';
    } else {
        $message = '更新失败';
        $messageType = 'danger';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_user_limit') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId > 0) {
        $limits = [
            'enabled' => isset($_POST['enabled']),
            'daily_chapter_limit' => max(0, (int)($_POST['daily_chapter_limit'] ?? 0)),
            'daily_reading_time_limit' => max(0, (int)($_POST['daily_reading_time_limit'] ?? 0)),
            'concurrent_novels_limit' => max(0, (int)($_POST['concurrent_novels_limit'] ?? 0)),
            'download_limit_per_day' => max(0, (int)($_POST['download_limit_per_day'] ?? 0)),
        ];
        
        if ($limitsManager->setUserLimit($userId, $limits)) {
            $logger->log('set_user_limit', ['user_id' => $userId, 'limits' => $limits]);
            $message = '用户限制已设置';
            $messageType = 'success';
        } else {
            $message = '设置失败';
            $messageType = 'danger';
        }
    }
}

if ($action === 'remove_user_limit') {
    $userId = (int)($_GET['user_id'] ?? 0);
    if ($userId > 0) {
        if ($limitsManager->removeUserLimit($userId)) {
            $logger->log('remove_user_limit', ['user_id' => $userId]);
            $message = '用户限制已移除，将使用默认设置';
            $messageType = 'success';
        }
    }
}

$defaultLimits = $limitsManager->getUserLimit(0);
$users = load_users();
$userLimits = $limitsManager->getAllUserLimits();

?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>用户限制管理 - 管理后台</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
  <style>
    .limit-badge { font-size: 0.875em; }
    .usage-info { font-size: 0.875em; color: #666; }
  </style>
</head>
<body>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1>用户限制管理</h1>
    <div>
      <a class="btn btn-secondary" href="/admin_dashboard.php">管理仪表盘</a>
      <a class="btn btn-secondary" href="/">首页</a>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
      <?php echo e($message); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="row">
    <div class="col-md-4">
      <div class="card mb-4">
        <div class="card-body">
          <h5 class="card-title">默认限制设置</h5>
          <p class="text-muted small">对所有未单独设置的用户生效</p>
          <form method="post">
            <input type="hidden" name="action" value="set_default_limits">
            
            <div class="mb-3 form-check form-switch">
              <input class="form-check-input" type="checkbox" id="default_enabled" name="enabled" <?php if($defaultLimits['enabled']) echo 'checked'; ?>>
              <label class="form-check-label" for="default_enabled">启用默认限制</label>
            </div>
            
            <div class="mb-3">
              <label class="form-label">每日章节限制</label>
              <input type="number" class="form-control" name="daily_chapter_limit" value="<?php echo (int)$defaultLimits['daily_chapter_limit']; ?>" min="0">
              <div class="form-text">0 = 无限制</div>
            </div>
            
            <div class="mb-3">
              <label class="form-label">每日阅读时长限制（分钟）</label>
              <input type="number" class="form-control" name="daily_reading_time_limit" value="<?php echo (int)$defaultLimits['daily_reading_time_limit']; ?>" min="0">
              <div class="form-text">0 = 无限制</div>
            </div>
            
            <div class="mb-3">
              <label class="form-label">同时阅读小说数限制</label>
              <input type="number" class="form-control" name="concurrent_novels_limit" value="<?php echo (int)$defaultLimits['concurrent_novels_limit']; ?>" min="0">
              <div class="form-text">0 = 无限制</div>
            </div>
            
            <div class="mb-3">
              <label class="form-label">每日下载次数限制</label>
              <input type="number" class="form-control" name="download_limit_per_day" value="<?php echo (int)$defaultLimits['download_limit_per_day']; ?>" min="0">
              <div class="form-text">0 = 无限制</div>
            </div>
            
            <button type="submit" class="btn btn-primary w-100">保存默认设置</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-md-8">
      <div class="card mb-4">
        <div class="card-body">
          <h5 class="card-title">用户列表与限制设置</h5>
          <div class="table-responsive">
            <table class="table table-hover">
              <thead>
                <tr>
                  <th>用户ID</th>
                  <th>用户名</th>
                  <th>角色</th>
                  <th>当前限制</th>
                  <th>今日使用情况</th>
                  <th>操作</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $user): 
                  $userId = (int)$user['id'];
                  $userLimit = $limitsManager->getUserLimit($userId);
                  $usage = $limitsManager->getUserUsage($userId);
                  $hasCustomLimit = isset($userLimits[$userId]);
                ?>
                  <tr>
                    <td><?php echo $userId; ?></td>
                    <td>
                      <?php echo e($user['username'] ?? ''); ?>
                      <?php if ($hasCustomLimit): ?>
                        <span class="badge bg-warning text-dark">自定义</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php 
                        $role = $user['role'] ?? 'user';
                        $roleBadge = $role === 'super_admin' ? 'danger' : ($role === 'content_admin' ? 'warning' : 'secondary');
                        $roleText = $role === 'super_admin' ? '超级管理员' : ($role === 'content_admin' ? '内容管理员' : '普通用户');
                      ?>
                      <span class="badge bg-<?php echo $roleBadge; ?>"><?php echo $roleText; ?></span>
                    </td>
                    <td>
                      <?php if ($userLimit['enabled']): ?>
                        <div class="limit-badge">
                          <?php if ($userLimit['daily_chapter_limit'] > 0): ?>
                            <span class="badge bg-info">章节: <?php echo $userLimit['daily_chapter_limit']; ?>/天</span>
                          <?php endif; ?>
                          <?php if ($userLimit['daily_reading_time_limit'] > 0): ?>
                            <span class="badge bg-info">时长: <?php echo $userLimit['daily_reading_time_limit']; ?>分/天</span>
                          <?php endif; ?>
                          <?php if ($userLimit['concurrent_novels_limit'] > 0): ?>
                            <span class="badge bg-info">小说: <?php echo $userLimit['concurrent_novels_limit']; ?>本</span>
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <span class="text-muted">未启用</span>
                      <?php endif; ?>
                    </td>
                    <td class="usage-info">
                      已读: <?php echo (int)($usage['chapters_read'] ?? 0); ?>章<br>
                      时长: <?php echo (int)($usage['reading_time_minutes'] ?? 0); ?>分钟
                    </td>
                    <td>
                      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editLimitModal" 
                        data-user-id="<?php echo $userId; ?>"
                        data-username="<?php echo e($user['username'] ?? ''); ?>"
                        data-enabled="<?php echo $userLimit['enabled'] ? '1' : '0'; ?>"
                        data-chapter-limit="<?php echo (int)$userLimit['daily_chapter_limit']; ?>"
                        data-time-limit="<?php echo (int)$userLimit['daily_reading_time_limit']; ?>"
                        data-novel-limit="<?php echo (int)$userLimit['concurrent_novels_limit']; ?>"
                        data-download-limit="<?php echo (int)$userLimit['download_limit_per_day']; ?>">
                        设置
                      </button>
                      <?php if ($hasCustomLimit): ?>
                        <a href="?action=remove_user_limit&user_id=<?php echo $userId; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('确定移除此用户的自定义限制吗？将使用默认设置。')">移除</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Edit Limit Modal -->
<div class="modal fade" id="editLimitModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="set_user_limit">
        <input type="hidden" name="user_id" id="modal_user_id">
        <div class="modal-header">
          <h5 class="modal-title">设置用户限制 - <span id="modal_username"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3 form-check form-switch">
            <input class="form-check-input" type="checkbox" id="modal_enabled" name="enabled">
            <label class="form-check-label" for="modal_enabled">启用限制</label>
          </div>
          
          <div class="mb-3">
            <label class="form-label">每日章节限制</label>
            <input type="number" class="form-control" name="daily_chapter_limit" id="modal_chapter_limit" min="0">
            <div class="form-text">0 = 无限制，例如设置为 3 表示每天只能阅读 3 章</div>
          </div>
          
          <div class="mb-3">
            <label class="form-label">每日阅读时长限制（分钟）</label>
            <input type="number" class="form-control" name="daily_reading_time_limit" id="modal_time_limit" min="0">
            <div class="form-text">0 = 无限制</div>
          </div>
          
          <div class="mb-3">
            <label class="form-label">同时阅读小说数限制</label>
            <input type="number" class="form-control" name="concurrent_novels_limit" id="modal_novel_limit" min="0">
            <div class="form-text">0 = 无限制</div>
          </div>
          
          <div class="mb-3">
            <label class="form-label">每日下载次数限制</label>
            <input type="number" class="form-control" name="download_limit_per_day" id="modal_download_limit" min="0">
            <div class="form-text">0 = 无限制</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
          <button type="submit" class="btn btn-primary">保存设置</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const modal = document.getElementById('editLimitModal');
  modal.addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const userId = button.getAttribute('data-user-id');
    const username = button.getAttribute('data-username');
    const enabled = button.getAttribute('data-enabled') === '1';
    const chapterLimit = button.getAttribute('data-chapter-limit');
    const timeLimit = button.getAttribute('data-time-limit');
    const novelLimit = button.getAttribute('data-novel-limit');
    const downloadLimit = button.getAttribute('data-download-limit');
    
    document.getElementById('modal_user_id').value = userId;
    document.getElementById('modal_username').textContent = username;
    document.getElementById('modal_enabled').checked = enabled;
    document.getElementById('modal_chapter_limit').value = chapterLimit;
    document.getElementById('modal_time_limit').value = timeLimit;
    document.getElementById('modal_novel_limit').value = novelLimit;
    document.getElementById('modal_download_limit').value = downloadLimit;
  });
});
</script>
</body>
</html>
