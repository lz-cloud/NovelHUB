<?php
require_once __DIR__ . '/lib/helpers.php';

$errors = [];
$login = trim($_POST['login'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $user = find_user_by_login($login);
    if (!$user) {
        $errors[] = '用户不存在';
    } else if (($user['status'] ?? 'active') === 'pending_verification') {
        $errors[] = '邮箱未验证，请先完成邮箱验证后再登录。';
    } else if (($user['status'] ?? 'active') !== 'active') {
        $errors[] = '账号已被禁用';
    } else if (!password_verify($password, $user['password_hash'])) {
        $errors[] = '密码错误';
    } else {
        $_SESSION['user'] = $user;
        header('Location: /dashboard.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>登录 - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-4" style="max-width:560px;">
  <h1 class="mb-3">登录</h1>
  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <form method="post">
    <div class="mb-3">
      <label class="form-label">用户名或邮箱</label>
      <input class="form-control" name="login" value="<?php echo e($login); ?>" required>
    </div>
    <div class="mb-3">
      <label class="form-label">密码</label>
      <input type="password" class="form-control" name="password" required>
    </div>
    <button class="btn btn-primary">登录</button>
    <a href="/register.php" class="btn btn-link">注册新账号</a>
    <a href="/forgot_password.php" class="btn btn-link">忘记密码？</a>
  </form>
</div>
</body>
</html>
