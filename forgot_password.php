<?php
require_once __DIR__ . '/lib/helpers.php';

global $dm;
$errors = [];
$success = false;
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newpass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if ($username === '' || $email === '') $errors[] = '请输入用户名和邮箱';
    if ($newpass === '' || strlen($newpass) < 6) $errors[] = '新密码至少6位';
    if ($newpass !== $confirm) $errors[] = '两次输入的密码不一致';

    $user = find_user_by_login($username);
    if (!$user || strcasecmp($user['email'] ?? '', $email) !== 0) {
        $errors[] = '用户名或邮箱不匹配';
    }

    if (!$errors) {
        $ok = $dm->updateById(USERS_FILE, (int)$user['id'], ['password_hash'=>password_hash($newpass, PASSWORD_DEFAULT)]);
        if ($ok) { $success = true; } else { $errors[] = '重置失败，请稍后再试'; }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>找回密码 - NovelHub</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4" style="max-width:560px;">
  <h1 class="mb-3">找回密码</h1>
  <?php if ($success): ?>
    <div class="alert alert-success">密码已重置，请使用新密码登录。</div>
  <?php elseif ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form method="post">
    <div class="mb-3">
      <label class="form-label">用户名</label>
      <input class="form-control" name="username" value="<?php echo e($username); ?>" required>
    </div>
    <div class="mb-3">
      <label class="form-label">注册邮箱</label>
      <input type="email" class="form-control" name="email" value="<?php echo e($email); ?>" required>
    </div>
    <div class="mb-3">
      <label class="form-label">新密码</label>
      <input type="password" class="form-control" name="new_password" required>
    </div>
    <div class="mb-3">
      <label class="form-label">确认新密码</label>
      <input type="password" class="form-control" name="confirm_password" required>
    </div>
    <button class="btn btn-primary">重置密码</button>
    <a class="btn btn-link" href="/login.php">返回登录</a>
  </form>
</div>
</body>
</html>
