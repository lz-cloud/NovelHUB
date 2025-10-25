<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/EmailManager.php';

$emailMgr = new EmailManager();
$token = trim($_GET['token'] ?? '');
$message = null;
$messageType = 'info';

if ($token) {
    $result = $emailMgr->verifyToken($token);
    
    if ($result['success']) {
        $userId = (int)$result['user_id'];
        
        // Update user status to active
        $dm = new DataManager(DATA_DIR);
        $dm->updateById(USERS_FILE, $userId, ['status' => 'active']);
        
        $message = '邮箱验证成功！您现在可以登录了。';
        $messageType = 'success';
    } else {
        $message = $result['error'] ?? '验证失败';
        $messageType = 'danger';
    }
} else {
    $message = '无效的验证链接';
    $messageType = 'danger';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>邮箱验证 - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-4" style="max-width:600px;">
  <div class="text-center mb-4">
    <h1>邮箱验证</h1>
  </div>
  
  <div class="alert alert-<?php echo $messageType; ?>">
    <?php echo e($message); ?>
  </div>
  
  <?php if ($messageType === 'success'): ?>
    <div class="text-center">
      <a href="/login.php" class="btn btn-primary">前往登录</a>
    </div>
  <?php else: ?>
    <div class="text-center">
      <a href="/register.php" class="btn btn-secondary">返回注册</a>
      <a href="/login.php" class="btn btn-link">前往登录</a>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
