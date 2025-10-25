<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/InvitationManager.php';
require_once __DIR__ . '/lib/EmailManager.php';

$invitationMgr = new InvitationManager();
$emailMgr = new EmailManager();

$errors = [];
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$nickname = trim($_POST['nickname'] ?? '');
$bio = trim($_POST['bio'] ?? '');
$invitationCode = trim($_POST['invitation_code'] ?? '');
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    if ($username === '' || !preg_match('/^[A-Za-z0-9_\-]{3,30}$/', $username)) {
        $errors[] = '用户名需为3-30位字母、数字或下划线/连字符';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = '请输入有效邮箱地址';
    }
    if ($password === '' || strlen($password) < 6) {
        $errors[] = '密码至少6位';
    }
    if ($password !== $password2) {
        $errors[] = '两次密码输入不一致';
    }
    
    // Check invitation code if enabled
    if ($invitationMgr->isEnabled()) {
        if (empty($invitationCode)) {
            $errors[] = '邀请码不能为空';
        } else {
            $validation = $invitationMgr->validateCode($invitationCode);
            if (!$validation['valid']) {
                $errors[] = $validation['error'] ?? '邀请码无效';
            }
        }
    }
    
    // avatar upload
    $avatar = null;
    if (!empty($_FILES['avatar']['name'])) {
        $avatar = handle_upload($_FILES['avatar'], AVATARS_DIR);
        if (!$avatar) {
            $errors[] = '头像上传失败';
        }
    }
    // uniqueness
    $existing = load_users();
    foreach ($existing as $u) {
        if (strcasecmp($u['username'], $username) === 0) $errors[] = '用户名已存在';
        if (strcasecmp($u['email'], $email) === 0) $errors[] = '邮箱已注册';
    }

    if (!$errors) {
        $now = date('c');
        
        // Determine initial status based on email verification requirement
        $initialStatus = 'active';
        if ($emailMgr->isEnabled()) {
            $initialStatus = 'pending_verification';
        }
        
        $user = [
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'user',
            'status' => $initialStatus,
            'created_at' => $now,
            'profile' => [
                'nickname' => $nickname ?: $username,
                'avatar' => $avatar,
                'bio' => $bio,
            ],
        ];
        $id = save_user($user);
        if ($id) {
            // Use invitation code if provided
            if ($invitationMgr->isEnabled() && !empty($invitationCode)) {
                $invitationMgr->useCode($invitationCode, $id);
            }
            
            // Send verification email if enabled
            if ($emailMgr->isEnabled()) {
                $token = $emailMgr->generateVerificationToken($id, $email);
                $emailMgr->sendVerificationEmail($id, $email, $token);
                $successMessage = '注册成功！请检查您的邮箱并点击验证链接以激活账号。';
                // 清空表单字段
                $username = $email = $nickname = $bio = $invitationCode = '';
            } else {
                $user['id'] = $id;
                $_SESSION['user'] = $user;
                header('Location: /dashboard.php');
                exit;
            }
        } else {
            $errors[] = '注册失败，请稍后再试';
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>注册 - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-4" style="max-width:720px;">
  <h1 class="mb-3">注册</h1>
  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <?php if ($successMessage): ?>
    <div class="alert alert-success">
      <?php echo e($successMessage); ?>
    </div>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <div class="mb-3">
      <label class="form-label">用户名</label>
      <input class="form-control" name="username" value="<?php echo e($username); ?>" required>
    </div>
    <div class="mb-3">
      <label class="form-label">邮箱</label>
      <input type="email" class="form-control" name="email" value="<?php echo e($email); ?>" required>
    </div>
    <div class="mb-3">
      <label class="form-label">昵称</label>
      <input class="form-control" name="nickname" value="<?php echo e($nickname); ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">个人简介</label>
      <textarea class="form-control" name="bio" rows="3"><?php echo e($bio); ?></textarea>
    </div>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">密码</label>
        <input type="password" class="form-control" name="password" required>
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label">确认密码</label>
        <input type="password" class="form-control" name="password2" required>
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label">头像（可选）</label>
      <input type="file" class="form-control" name="avatar" accept="image/*">
    </div>
    <?php if ($invitationMgr->isEnabled()): ?>
    <div class="mb-3">
      <label class="form-label">邀请码 <span class="text-danger">*</span></label>
      <input class="form-control" name="invitation_code" value="<?php echo e($invitationCode); ?>" required placeholder="请输入邀请码">
      <div class="form-text">注册需要有效的邀请码</div>
    </div>
    <?php endif; ?>
    <button class="btn btn-primary">注册</button>
    <a href="/login.php" class="btn btn-link">已有账号？登录</a>
  </form>
</div>
</body>
</html>
