<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/AdManager.php';
require_login();

Auth::requireRole([Auth::ROLE_SUPER_ADMIN, Auth::ROLE_CONTENT_ADMIN]);

$dm = new DataManager(DATA_DIR);
$logger = new OperationLog();
$adManager = new AdManager();

$message = null;
$messageType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ad_settings'])) {
    $settings = [
        'enabled' => isset($_POST['enabled']),
        'platform' => $_POST['platform'] ?? 'none',
        'google_adsense' => [
            'enabled' => isset($_POST['google_adsense_enabled']),
            'client_id' => trim($_POST['google_adsense_client_id'] ?? ''),
            'slots' => [
                'header_banner' => trim($_POST['slot_header_banner'] ?? ''),
                'sidebar' => trim($_POST['slot_sidebar'] ?? ''),
                'in_content' => trim($_POST['slot_in_content'] ?? ''),
                'footer_banner' => trim($_POST['slot_footer_banner'] ?? ''),
            ],
        ],
        'custom_code' => [
            'enabled' => isset($_POST['custom_code_enabled']),
            'header_code' => $_POST['custom_header_code'] ?? '',
            'body_code' => $_POST['custom_body_code'] ?? '',
            'footer_code' => $_POST['custom_footer_code'] ?? '',
        ],
        'excluded_user_groups' => $_POST['excluded_user_groups'] ?? [],
        'excluded_user_ids' => array_map('intval', array_filter(explode(',', $_POST['excluded_user_ids'] ?? ''), 'strlen')),
        'display_positions' => [
            'reading_page' => isset($_POST['position_reading_page']),
            'novel_detail' => isset($_POST['position_novel_detail']),
            'home_page' => isset($_POST['position_home_page']),
            'dashboard' => isset($_POST['position_dashboard']),
        ],
    ];
    
    if ($adManager->updateSettings($settings)) {
        $logger->log('update_ad_settings', ['platform' => $settings['platform']]);
        $message = '广告设置已保存';
        $messageType = 'success';
    } else {
        $message = '保存失败';
        $messageType = 'danger';
    }
}

$settings = $adManager->getSettings();
$users = load_users();

?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>广告管理 - 管理后台</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1>广告管理</h1>
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
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-body">
          <form method="post">
            <h5 class="card-title">基本设置</h5>
            
            <div class="mb-3 form-check form-switch">
              <input class="form-check-input" type="checkbox" id="enabled" name="enabled" <?php if($settings['enabled']) echo 'checked'; ?>>
              <label class="form-check-label" for="enabled">启用广告系统</label>
              <div class="form-text">关闭后，所有用户都不会看到广告</div>
            </div>

            <div class="mb-3">
              <label class="form-label">广告平台</label>
              <select class="form-select" name="platform" id="platform_select">
                <option value="none" <?php if($settings['platform'] === 'none') echo 'selected'; ?>>未配置</option>
                <option value="google_adsense" <?php if($settings['platform'] === 'google_adsense') echo 'selected'; ?>>Google AdSense</option>
                <option value="custom_code" <?php if($settings['platform'] === 'custom_code') echo 'selected'; ?>>自定义代码</option>
              </select>
            </div>

            <hr class="my-4">

            <div id="google_adsense_section" style="display: none;">
              <h5 class="card-title">Google AdSense 设置</h5>
              
              <div class="mb-3 form-check form-switch">
                <input class="form-check-input" type="checkbox" id="google_adsense_enabled" name="google_adsense_enabled" <?php if($settings['google_adsense']['enabled']) echo 'checked'; ?>>
                <label class="form-check-label" for="google_adsense_enabled">启用 Google AdSense</label>
              </div>

              <div class="mb-3">
                <label class="form-label">AdSense 客户端 ID</label>
                <input type="text" class="form-control" name="google_adsense_client_id" value="<?php echo e($settings['google_adsense']['client_id']); ?>" placeholder="ca-pub-XXXXXXXXXXXXXXXX">
                <div class="form-text">从 AdSense 后台获取</div>
              </div>

              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label">头部横幅广告位 ID</label>
                  <input type="text" class="form-control" name="slot_header_banner" value="<?php echo e($settings['google_adsense']['slots']['header_banner']); ?>" placeholder="1234567890">
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">侧边栏广告位 ID</label>
                  <input type="text" class="form-control" name="slot_sidebar" value="<?php echo e($settings['google_adsense']['slots']['sidebar']); ?>" placeholder="1234567890">
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">内容中广告位 ID</label>
                  <input type="text" class="form-control" name="slot_in_content" value="<?php echo e($settings['google_adsense']['slots']['in_content']); ?>" placeholder="1234567890">
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">底部横幅广告位 ID</label>
                  <input type="text" class="form-control" name="slot_footer_banner" value="<?php echo e($settings['google_adsense']['slots']['footer_banner']); ?>" placeholder="1234567890">
                </div>
              </div>
            </div>

            <div id="custom_code_section" style="display: none;">
              <h5 class="card-title">自定义广告代码</h5>
              
              <div class="mb-3 form-check form-switch">
                <input class="form-check-input" type="checkbox" id="custom_code_enabled" name="custom_code_enabled" <?php if($settings['custom_code']['enabled']) echo 'checked'; ?>>
                <label class="form-check-label" for="custom_code_enabled">启用自定义代码</label>
              </div>

              <div class="mb-3">
                <label class="form-label">头部广告代码</label>
                <textarea class="form-control font-monospace" name="custom_header_code" rows="4" placeholder="<script>...</script>"><?php echo e($settings['custom_code']['header_code']); ?></textarea>
                <div class="form-text">将在页面头部显示</div>
              </div>

              <div class="mb-3">
                <label class="form-label">内容区广告代码</label>
                <textarea class="form-control font-monospace" name="custom_body_code" rows="4" placeholder="<div>...</div>"><?php echo e($settings['custom_code']['body_code']); ?></textarea>
                <div class="form-text">将在内容区域显示</div>
              </div>

              <div class="mb-3">
                <label class="form-label">底部广告代码</label>
                <textarea class="form-control font-monospace" name="custom_footer_code" rows="4" placeholder="<div>...</div>"><?php echo e($settings['custom_code']['footer_code']); ?></textarea>
                <div class="form-text">将在页面底部显示</div>
              </div>
            </div>

            <hr class="my-4">

            <h5 class="card-title">显示位置</h5>
            <div class="row">
              <div class="col-md-3 mb-3">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="position_reading_page" name="position_reading_page" <?php if($settings['display_positions']['reading_page']) echo 'checked'; ?>>
                  <label class="form-check-label" for="position_reading_page">阅读页面</label>
                </div>
              </div>
              <div class="col-md-3 mb-3">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="position_novel_detail" name="position_novel_detail" <?php if($settings['display_positions']['novel_detail']) echo 'checked'; ?>>
                  <label class="form-check-label" for="position_novel_detail">小说详情页</label>
                </div>
              </div>
              <div class="col-md-3 mb-3">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="position_home_page" name="position_home_page" <?php if($settings['display_positions']['home_page']) echo 'checked'; ?>>
                  <label class="form-check-label" for="position_home_page">首页</label>
                </div>
              </div>
              <div class="col-md-3 mb-3">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="position_dashboard" name="position_dashboard" <?php if($settings['display_positions']['dashboard']) echo 'checked'; ?>>
                  <label class="form-check-label" for="position_dashboard">用户仪表盘</label>
                </div>
              </div>
            </div>

            <hr class="my-4">

            <h5 class="card-title">广告豁免设置</h5>
            <p class="text-muted small">选择的用户组和用户将不会看到广告</p>

            <div class="mb-3">
              <label class="form-label">豁免用户组</label>
              <div class="row">
                <div class="col-md-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="excluded_user_groups[]" value="plus" id="exclude_plus" <?php if(in_array('plus', $settings['excluded_user_groups'])) echo 'checked'; ?>>
                    <label class="form-check-label" for="exclude_plus">PLUS 会员</label>
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="excluded_user_groups[]" value="vip" id="exclude_vip" <?php if(in_array('vip', $settings['excluded_user_groups'])) echo 'checked'; ?>>
                    <label class="form-check-label" for="exclude_vip">VIP 用户</label>
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="excluded_user_groups[]" value="super_admin" id="exclude_super_admin" <?php if(in_array('super_admin', $settings['excluded_user_groups'])) echo 'checked'; ?>>
                    <label class="form-check-label" for="exclude_super_admin">超级管理员</label>
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="excluded_user_groups[]" value="content_admin" id="exclude_content_admin" <?php if(in_array('content_admin', $settings['excluded_user_groups'])) echo 'checked'; ?>>
                    <label class="form-check-label" for="exclude_content_admin">内容管理员</label>
                  </div>
                </div>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">豁免用户 ID（逗号分隔）</label>
              <input type="text" class="form-control" name="excluded_user_ids" value="<?php echo implode(',', $settings['excluded_user_ids']); ?>" placeholder="1,2,3">
              <div class="form-text">输入特定用户的 ID，用逗号分隔</div>
            </div>

            <div class="alert alert-info">
              <strong>提示：</strong>
              <ul class="mb-0">
                <li>如果未启用广告系统，所有用户都不会看到广告</li>
                <li>PLUS 会员默认不显示广告，可根据需要调整</li>
                <li>建议为付费会员提供无广告体验</li>
                <li>自定义代码支持任何第三方广告平台</li>
              </ul>
            </div>

            <button type="submit" name="save_ad_settings" class="btn btn-primary">保存设置</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <h5 class="card-title">当前广告状态</h5>
          <div class="row">
            <div class="col-md-4">
              <div class="border rounded p-3">
                <div class="text-muted small">广告系统</div>
                <div class="h5 mb-0">
                  <?php if ($settings['enabled']): ?>
                    <span class="badge bg-success">已启用</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">未启用</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="border rounded p-3">
                <div class="text-muted small">广告平台</div>
                <div class="h6 mb-0">
                  <?php 
                    $platformName = $settings['platform'] === 'google_adsense' ? 'Google AdSense' : 
                                   ($settings['platform'] === 'custom_code' ? '自定义代码' : '未配置');
                  ?>
                  <?php echo e($platformName); ?>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="border rounded p-3">
                <div class="text-muted small">豁免用户组</div>
                <div class="h6 mb-0"><?php echo count($settings['excluded_user_groups']); ?> 个</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const platformSelect = document.getElementById('platform_select');
  const googleSection = document.getElementById('google_adsense_section');
  const customSection = document.getElementById('custom_code_section');

  function updateSections() {
    const platform = platformSelect.value;
    googleSection.style.display = platform === 'google_adsense' ? 'block' : 'none';
    customSection.style.display = platform === 'custom_code' ? 'block' : 'none';
  }

  platformSelect.addEventListener('change', updateSections);
  updateSections();
});
</script>
</body>
</html>
