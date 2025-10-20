<?php
require_once __DIR__ . '/lib/helpers.php';
require_login();
if (!is_admin()) { http_response_code(403); echo '需要管理员权限'; exit; }

global $dm;
$tab = $_GET['tab'] ?? 'users';
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if ($tab === 'users' && $action) {
    $userId = (int)($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    if ($userId) {
        if ($action === 'disable') $dm->updateById(USERS_FILE, $userId, ['status'=>'disabled']);
        if ($action === 'enable') $dm->updateById(USERS_FILE, $userId, ['status'=>'active']);
        if ($action === 'promote') $dm->updateById(USERS_FILE, $userId, ['role'=>'admin']);
        if ($userId === (int)current_user()['id']) update_user_session($userId);
        header('Location: /admin.php?tab=users'); exit;
    }
}

if ($tab === 'novels' && $action) {
    $novelId = (int)($_POST['novel_id'] ?? $_GET['novel_id'] ?? 0);
    if ($novelId) {
        if ($action === 'delete') {
            // delete novel entry
            $dm->deleteById(NOVELS_FILE, $novelId);
            // delete chapters files
            $dir = CHAPTERS_DIR . '/novel_' . $novelId;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*.json') as $f) @unlink($f);
                @rmdir($dir);
            }
        }
        header('Location: /admin.php?tab=novels'); exit;
    }
}

if ($tab === 'categories' && $action) {
    $categories = $dm->readJson(CATEGORIES_FILE, []);
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $max = 0; foreach($categories as $c) { $max = max($max, (int)($c['id']??0)); }
            $categories[] = ['id'=>$max+1,'name'=>$name,'slug'=>strtolower(preg_replace('/[^a-z0-9]+/','-',$name)),'created_at'=>date('c')];
        }
    } else if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $categories = array_values(array_filter($categories, function($c) use ($id){ return (int)$c['id'] !== $id; }));
    }
    $dm->writeJson(CATEGORIES_FILE, $categories);
    header('Location: /admin.php?tab=categories'); exit;
}

$users = load_users();
$novels = load_novels();
$categories = $dm->readJson(CATEGORIES_FILE, []);
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>管理员后台 - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
  <h1 class="mb-3">管理员后台</h1>
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?php if($tab==='users') echo 'active'; ?>" href="/admin.php?tab=users">用户管理</a></li>
    <li class="nav-item"><a class="nav-link <?php if($tab==='novels') echo 'active'; ?>" href="/admin.php?tab=novels">小说管理</a></li>
    <li class="nav-item"><a class="nav-link <?php if($tab==='categories') echo 'active'; ?>" href="/admin.php?tab=categories">分类管理</a></li>
  </ul>

  <?php if ($tab==='users'): ?>
    <div class="table-responsive">
      <table class="table table-striped">
        <thead><tr><th>ID</th><th>用户名</th><th>邮箱</th><th>角色</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?php echo (int)$u['id']; ?></td>
              <td><?php echo e($u['username']); ?></td>
              <td><?php echo e($u['email']); ?></td>
              <td><?php echo e($u['role']); ?></td>
              <td><?php echo e($u['status']); ?></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <?php if (($u['status'] ?? 'active') === 'active'): ?>
                    <a class="btn btn-outline-warning" href="/admin.php?tab=users&action=disable&user_id=<?php echo (int)$u['id']; ?>">禁用</a>
                  <?php else: ?>
                    <a class="btn btn-outline-success" href="/admin.php?tab=users&action=enable&user_id=<?php echo (int)$u['id']; ?>">启用</a>
                  <?php endif; ?>
                  <?php if (($u['role'] ?? 'user') !== 'admin'): ?>
                    <a class="btn btn-outline-primary" href="/admin.php?tab=users&action=promote&user_id=<?php echo (int)$u['id']; ?>">设为管理员</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php elseif ($tab==='novels'): ?>
    <div class="list-group">
      <?php foreach ($novels as $n): ?>
        <div class="list-group-item d-flex justify-content-between align-items-center">
          <div>
            <strong><?php echo e($n['title']); ?></strong>
            <span class="text-muted ms-2">作者：<?php echo e(get_user_display_name((int)$n['author_id'])); ?></span>
          </div>
          <div>
            <a class="btn btn-sm btn-outline-danger" href="/admin.php?tab=novels&action=delete&novel_id=<?php echo (int)$n['id']; ?>" onclick="return confirm('确认删除该小说及所有章节？');">删除</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php elseif ($tab==='categories'): ?>
    <div class="row">
      <div class="col-md-6">
        <h5>现有分类</h5>
        <ul class="list-group mb-3">
          <?php foreach ($categories as $c): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <span><?php echo e($c['name']); ?></span>
              <form method="post" class="mb-0">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                <button class="btn btn-sm btn-outline-danger">删除</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="col-md-6">
        <h5>新增分类</h5>
        <form method="post">
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label class="form-label">名称</label>
            <input class="form-control" name="name" required>
          </div>
          <button class="btn btn-primary">添加</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
