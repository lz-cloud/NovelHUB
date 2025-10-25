<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Membership.php';
require_login();

// Require admin permissions
Auth::requireRole([Auth::ROLE_SUPER_ADMIN, Auth::ROLE_CONTENT_ADMIN]);

$dm = new DataManager(DATA_DIR);
$membership = new Membership();
$downloadMgr = new DownloadManager();
$logger = new OperationLog();

$tab = $_GET['tab'] ?? 'codes';
$message = null;
$messageType = null;

// Generate new code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_code'])) {
    $durationDays = (int)($_POST['duration_days'] ?? 30);
    $maxUses = (int)($_POST['max_uses'] ?? 1);
    $expiresAt = !empty($_POST['expires_at']) ? date('c', strtotime($_POST['expires_at'])) : null;
    
    if ($durationDays > 0 && $maxUses > 0) {
        $code = $membership->generateCode($durationDays, $maxUses, $expiresAt);
        $logger->log('generate_redemption_code', ['code' => $code, 'duration_days' => $durationDays, 'max_uses' => $maxUses]);
        $messageType = 'success';
        $message = "兑换码生成成功：<strong>$code</strong>";
    } else {
        $messageType = 'danger';
        $message = '参数无效';
    }
}

// Disable code
if (isset($_GET['action']) && $_GET['action'] === 'disable_code') {
    $codeId = (int)($_GET['code_id'] ?? 0);
    if ($codeId > 0) {
        $membership->disableCode($codeId);
        $logger->log('disable_redemption_code', ['code_id' => $codeId]);
        header('Location: /admin_membership.php?tab=codes');
        exit;
    }
}

// Extend membership manually
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extend_membership'])) {
    $userId = (int)($_POST['user_id'] ?? 0);
    $durationDays = (int)($_POST['duration_days'] ?? 30);
    
    if ($userId > 0 && $durationDays > 0) {
        $memberships = $dm->readJson(PLUS_MEMBERSHIPS_FILE, []);
        $found = false;
        
        foreach ($memberships as &$m) {
            if ((int)($m['user_id'] ?? 0) === $userId) {
                $currentExpires = $m['expires_at'] ?? date('c');
                $currentTimestamp = max(time(), strtotime($currentExpires));
                $newExpires = date('c', $currentTimestamp + ($durationDays * 86400));
                $m['expires_at'] = $newExpires;
                $m['updated_at'] = date('c');
                $found = true;
                break;
            }
        }
        unset($m);
        
        if (!$found) {
            $memberships[] = [
                'user_id' => $userId,
                'expires_at' => date('c', time() + ($durationDays * 86400)),
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ];
        }
        
        $dm->writeJson(PLUS_MEMBERSHIPS_FILE, $memberships);
        $logger->log('extend_membership_manual', ['user_id' => $userId, 'duration_days' => $durationDays]);
        $messageType = 'success';
        $message = "会员已延长 $durationDays 天";
    }
}

$codes = $membership->getAllCodes();
usort($codes, function($a, $b) {
    return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
});

$memberships = $dm->readJson(PLUS_MEMBERSHIPS_FILE, []);
$users = load_users();
$userMap = [];
foreach ($users as $u) {
    $userMap[(int)$u['id']] = $u;
}

$downloadStats = $downloadMgr->getDownloadStats();

?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>会员管理 - 管理后台</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1>会员与下载管理</h1>
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
      <a class="nav-link <?php echo $tab === 'codes' ? 'active' : ''; ?>" href="?tab=codes">兑换码管理</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?php echo $tab === 'memberships' ? 'active' : ''; ?>" href="?tab=memberships">会员列表</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?php echo $tab === 'downloads' ? 'active' : ''; ?>" href="?tab=downloads">下载统计</a>
    </li>
  </ul>

  <?php if ($tab === 'codes'): ?>
    <div class="row">
      <div class="col-md-4">
        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title">生成兑换码</h5>
            <form method="post">
              <div class="mb-3">
                <label for="duration_days" class="form-label">会员时长（天）</label>
                <input type="number" class="form-control" id="duration_days" name="duration_days" value="30" min="1" required>
              </div>
              <div class="mb-3">
                <label for="max_uses" class="form-label">最大使用次数</label>
                <input type="number" class="form-control" id="max_uses" name="max_uses" value="1" min="1" required>
              </div>
              <div class="form-text mb-2">
                <?php 
                  $settings = json_decode(@file_get_contents(SYSTEM_SETTINGS_FILE), true) ?: [];
                  $codeLength = (int)($settings['membership']['code_length'] ?? 8);
                ?>
                当前兑换码长度：<?php echo $codeLength; ?> 位
                <a href="/admin_settings.php?tab=membership" class="small">修改设置</a>
              </div>
              <div class="mb-3">
                <label for="expires_at" class="form-label">兑换码有效期（可选）</label>
                <input type="datetime-local" class="form-control" id="expires_at" name="expires_at">
                <div class="form-text">留空表示永不过期</div>
              </div>
              <button type="submit" name="generate_code" class="btn btn-primary w-100">生成</button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-md-8">
        <div class="card">
          <div class="card-body">
            <h5 class="card-title">兑换码列表</h5>
            <div class="table-responsive">
              <table class="table table-hover">
                <thead>
                  <tr>
                    <th>兑换码</th>
                    <th>时长</th>
                    <th>使用次数</th>
                    <th>状态</th>
                    <th>有效期</th>
                    <th>创建时间</th>
                    <th>操作</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($codes as $c): ?>
                    <tr>
                      <td><code><?php echo e($c['code'] ?? ''); ?></code></td>
                      <td><?php echo (int)($c['duration_days'] ?? 0); ?> 天</td>
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
                      <td><?php echo date('Y-m-d H:i', strtotime($c['created_at'])); ?></td>
                      <td>
                        <?php if ($status === 'active'): ?>
                          <a href="?action=disable_code&code_id=<?php echo (int)$c['id']; ?>&tab=codes" class="btn btn-sm btn-outline-danger" onclick="return confirm('确定要禁用此兑换码吗？')">禁用</a>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$codes): ?>
                    <tr><td colspan="7" class="text-center text-muted">暂无兑换码</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

  <?php elseif ($tab === 'memberships'): ?>
    <div class="row">
      <div class="col-md-4">
        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title">手动延长会员</h5>
            <form method="post">
              <div class="mb-3">
                <label for="user_id" class="form-label">用户 ID</label>
                <input type="number" class="form-control" id="user_id" name="user_id" min="1" required>
                <div class="form-text">输入要延长会员的用户 ID</div>
              </div>
              <div class="mb-3">
                <label for="duration_days2" class="form-label">延长时长（天）</label>
                <input type="number" class="form-control" id="duration_days2" name="duration_days" value="30" min="1" required>
              </div>
              <button type="submit" name="extend_membership" class="btn btn-primary w-100">延长会员</button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-md-8">
        <div class="card">
          <div class="card-body">
            <h5 class="card-title">Plus 会员列表</h5>
            <div class="table-responsive">
              <table class="table table-hover">
                <thead>
                  <tr>
                    <th>用户 ID</th>
                    <th>用户名</th>
                    <th>到期时间</th>
                    <th>状态</th>
                    <th>创建时间</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($memberships as $m): 
                    $userId = (int)($m['user_id'] ?? 0);
                    $user = $userMap[$userId] ?? null;
                    $expiresAt = $m['expires_at'] ?? '';
                    $isActive = strtotime($expiresAt) > time();
                  ?>
                    <tr class="<?php echo $isActive ? '' : 'table-secondary'; ?>">
                      <td><?php echo $userId; ?></td>
                      <td><?php echo $user ? e($user['username']) : '未知'; ?></td>
                      <td><?php echo date('Y-m-d H:i', strtotime($expiresAt)); ?></td>
                      <td>
                        <span class="badge bg-<?php echo $isActive ? 'success' : 'secondary'; ?>">
                          <?php echo $isActive ? '有效' : '已过期'; ?>
                        </span>
                      </td>
                      <td><?php echo date('Y-m-d H:i', strtotime($m['created_at'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$memberships): ?>
                    <tr><td colspan="5" class="text-center text-muted">暂无 Plus 会员</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

  <?php elseif ($tab === 'downloads'): ?>
    <div class="row">
      <div class="col-md-3">
        <div class="card">
          <div class="card-body">
            <h6 class="text-muted">总下载次数</h6>
            <h2><?php echo number_format($downloadStats['total']); ?></h2>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card">
          <div class="card-body">
            <h6 class="text-muted">今日下载次数</h6>
            <h2><?php echo number_format($downloadStats['today']); ?></h2>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card">
          <div class="card-body">
            <h6 class="text-muted">按格式统计</h6>
            <?php foreach ($downloadStats['by_format'] as $format => $count): ?>
              <div class="d-flex justify-content-between mb-2">
                <span><?php echo strtoupper(e($format)); ?></span>
                <strong><?php echo number_format($count); ?> 次</strong>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="row mt-4">
      <div class="col-12">
        <div class="card">
          <div class="card-body">
            <h5 class="card-title">热门下载作品 TOP 10</h5>
            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr>
                    <th>排名</th>
                    <th>作品 ID</th>
                    <th>作品名称</th>
                    <th>下载次数</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                    $topNovels = array_slice($downloadStats['by_novel'], 0, 10, true);
                    $rank = 1;
                    $novels = load_novels();
                    $novelMap = [];
                    foreach ($novels as $n) {
                      $novelMap[(int)$n['id']] = $n;
                    }
                    foreach ($topNovels as $novelId => $count): 
                      $novel = $novelMap[$novelId] ?? null;
                  ?>
                    <tr>
                      <td><?php echo $rank++; ?></td>
                      <td><?php echo $novelId; ?></td>
                      <td>
                        <?php if ($novel): ?>
                          <a href="/novel_detail.php?novel_id=<?php echo $novelId; ?>"><?php echo e($novel['title']); ?></a>
                        <?php else: ?>
                          未知作品
                        <?php endif; ?>
                      </td>
                      <td><?php echo number_format($count); ?> 次</td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$topNovels): ?>
                    <tr><td colspan="4" class="text-center text-muted">暂无下载记录</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
