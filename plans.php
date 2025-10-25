<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Membership.php';
require_login();

$user = current_user();
$membership = new Membership();
$downloadMgr = new DownloadManager();

$message = null;
$messageType = null;

// Get membership settings
$membershipSettings = $membership->getSettings();

// Handle code redemption
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_code'])) {
    $code = trim($_POST['code'] ?? '');
    $result = $membership->redeemCode((int)$user['id'], $code);
    
    if ($result['success']) {
        $messageType = 'success';
        $message = "兑换成功！你的 Plus 会员已延长 {$result['duration_days']} 天，到期时间：" . date('Y-m-d H:i', strtotime($result['expires_at']));
    } else {
        $messageType = 'danger';
        $message = $result['error'] ?? '兑换失败';
    }
}

$userMembership = $membership->getUserMembership((int)$user['id']);
$isPlus = $membership->isPlusUser((int)$user['id']);
$downloadStatus = $downloadMgr->canDownload((int)$user['id']);

?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>会员计划 - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
  <style>
    .plan-card {
      border: 2px solid #e0e0e0;
      border-radius: 12px;
      transition: all 0.3s ease;
      height: 100%;
    }
    .plan-card.active {
      border-color: #0d6efd;
      box-shadow: 0 4px 20px rgba(13, 110, 253, 0.15);
    }
    .plan-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }
    .plan-badge {
      position: absolute;
      top: -12px;
      right: 20px;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 600;
    }
    .feature-list {
      list-style: none;
      padding: 0;
    }
    .feature-list li {
      padding: 8px 0;
      display: flex;
      align-items: center;
    }
    .feature-list li::before {
      content: "✓";
      color: #28a745;
      font-weight: bold;
      margin-right: 10px;
      font-size: 1.2rem;
    }
    .stats-card {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      border-radius: 12px;
      padding: 20px;
    }
  </style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1>会员计划</h1>
    <div>
      <a class="btn btn-secondary" href="/">首页</a>
      <a class="btn btn-secondary" href="/dashboard.php">仪表盘</a>
      <a class="btn btn-secondary" href="/profile.php">个人中心</a>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
      <?php echo e($message); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Current Status -->
  <div class="row mb-4">
    <div class="col-12">
      <div class="stats-card">
        <h4 class="mb-3">当前状态</h4>
        <div class="row">
          <div class="col-md-4 mb-3 mb-md-0">
            <h6 class="text-white-50">会员类型</h6>
            <h3><?php echo $isPlus ? 'Plus 会员' : '普通用户'; ?></h3>
          </div>
          <div class="col-md-4 mb-3 mb-md-0">
            <h6 class="text-white-50">到期时间</h6>
            <h5><?php echo $isPlus ? date('Y-m-d H:i', strtotime($userMembership['expires_at'])) : '-'; ?></h5>
          </div>
          <div class="col-md-4">
            <h6 class="text-white-50">今日下载次数</h6>
            <h5><?php 
              if ($isPlus) {
                echo '无限制';
              } else {
                echo ($downloadStatus['count'] ?? 0) . ' / ' . ($downloadStatus['limit'] ?? 3);
              }
            ?></h5>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Plans -->
  <h3 class="mb-4">选择你的计划</h3>
  <?php if (!empty($membershipSettings['plan_description'])): ?>
  <div class="alert alert-info mb-4">
    <?php echo nl2br(e($membershipSettings['plan_description'])); ?>
  </div>
  <?php endif; ?>
  <div class="row g-4 mb-5">
    <!-- Free Plan -->
    <div class="col-md-6">
      <div class="card plan-card <?php echo !$isPlus ? 'active' : ''; ?>">
        <?php if (!$isPlus): ?>
          <span class="plan-badge">当前计划</span>
        <?php endif; ?>
        <div class="card-body p-4">
          <h3 class="card-title mb-3">免费版</h3>
          <div class="mb-4">
            <span class="h2">¥0</span>
            <span class="text-muted">/永久</span>
          </div>
          <ul class="feature-list mb-4">
            <li>无限阅读所有作品</li>
            <li>创建和发布作品</li>
            <li>每日下载 3 次</li>
            <li>书架收藏功能</li>
            <li>阅读进度同步</li>
            <li>书签功能</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Plus Plan -->
    <div class="col-md-6">
      <div class="card plan-card <?php echo $isPlus ? 'active' : ''; ?>" style="border-color: #667eea;">
        <?php if ($isPlus): ?>
          <span class="plan-badge">当前计划</span>
        <?php endif; ?>
        <div class="card-body p-4">
          <h3 class="card-title mb-3" style="color: #667eea;">Plus 会员</h3>
          <div class="mb-4">
            <span class="h2">兑换码</span>
            <span class="text-muted">/周期制</span>
          </div>
          <ul class="feature-list mb-4">
            <li>包含免费版所有功能</li>
            <li><strong>无限次数下载</strong></li>
            <li>支持 TXT、EPUB、PDF 格式</li>
            <li>优先获得新功能</li>
            <li>专属会员标识</li>
            <li>无广告体验</li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- Redeem Code -->
  <div class="row">
    <div class="col-md-8 mx-auto">
      <div class="card">
        <div class="card-body p-4">
          <h4 class="card-title mb-3">兑换 Plus 会员</h4>
          <p class="text-muted mb-4">输入兑换码以激活或延长你的 Plus 会员</p>
          <form method="post">
            <div class="mb-3">
              <label for="code" class="form-label">兑换码</label>
              <input type="text" class="form-control form-control-lg" id="code" name="code" placeholder="请输入 <?php echo (int)($membershipSettings['code_length'] ?? 8); ?> 位兑换码" required>
              <div class="form-text">兑换码不区分大小写，请联系管理员获取</div>
            </div>
            <button type="submit" name="redeem_code" class="btn btn-primary btn-lg w-100">立即兑换</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- FAQ -->
  <div class="row mt-5">
    <div class="col-12">
      <h4 class="mb-4">常见问题</h4>
      <div class="accordion" id="faqAccordion">
        <div class="accordion-item">
          <h2 class="accordion-header">
            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
              如何获取兑换码？
            </button>
          </h2>
          <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
            <div class="accordion-body">
              请联系网站管理员或通过官方渠道获取 Plus 会员兑换码。管理员可以在后台生成兑换码并分发给用户。
            </div>
          </div>
        </div>
        <div class="accordion-item">
          <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
              Plus 会员可以叠加吗？
            </button>
          </h2>
          <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
            <div class="accordion-body">
              可以。如果你已经是 Plus 会员，使用新的兑换码会在当前到期时间基础上延长相应天数。
            </div>
          </div>
        </div>
        <div class="accordion-item">
          <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
              下载次数限制如何计算？
            </button>
          </h2>
          <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
            <div class="accordion-body">
              普通用户每天最多可以下载 3 次书籍（任何格式）。下载次数在每天 00:00 重置。Plus 会员不受此限制。
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
