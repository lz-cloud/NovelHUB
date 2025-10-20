<?php
require_once __DIR__ . '/lib/helpers.php';
require_login();

global $dm;
$user = current_user();
$errors = [];
$nickname = trim($_POST['nickname'] ?? ($user['profile']['nickname'] ?? ''));
$bio = trim($_POST['bio'] ?? ($user['profile']['bio'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $avatar = $user['profile']['avatar'] ?? null;
    if (!empty($_FILES['avatar']['name'])) {
        $tmp = handle_upload($_FILES['avatar'], AVATARS_DIR);
        if ($tmp) $avatar = $tmp; else $errors[] = '头像上传失败';
    }
    if (!$errors) {
        $dm->updateById(USERS_FILE, (int)$user['id'], ['profile'=>['nickname'=>$nickname,'bio'=>$bio,'avatar'=>$avatar]]);
        update_user_session((int)$user['id']);
        header('Location: /profile.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>编辑资料 - NovelHub</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4" style="max-width:720px;">
  <h1 class="mb-3">编辑个人资料</h1>
  <?php if ($errors): ?><div class="alert alert-danger"><?php echo e(implode('；',$errors)); ?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <div class="mb-3">
      <label class="form-label">头像</label><br>
      <?php if (!empty($user['profile']['avatar'])): ?>
        <img src="/uploads/avatars/<?php echo e($user['profile']['avatar']); ?>" style="width:96px;height:96px;object-fit:cover;" class="rounded mb-2" alt="avatar">
      <?php endif; ?>
      <input type="file" class="form-control" name="avatar" accept="image/*">
    </div>
    <div class="mb-3">
      <label class="form-label">昵称</label>
      <input class="form-control" name="nickname" value="<?php echo e($nickname); ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">个人简介</label>
      <textarea class="form-control" name="bio" rows="4"><?php echo e($bio); ?></textarea>
    </div>
    <button class="btn btn-primary">保存</button>
    <a class="btn btn-link" href="/dashboard.php">返回</a>
  </form>
</div>
</body>
</html>
