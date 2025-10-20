<?php
require_once __DIR__ . '/lib/helpers.php';
require_login();

global $dm;
$action = $_GET['action'] ?? 'list';
$user = current_user();

function add_to_shelf(int $userId, int $novelId)
{
    global $dm;
    $list = $dm->readJson(BOOKSHELVES_FILE, []);
    foreach ($list as $row) {
        if ((int)$row['user_id'] === $userId && (int)$row['novel_id'] === $novelId) return true;
    }
    $list[] = ['user_id'=>$userId,'novel_id'=>$novelId,'added_at'=>date('c')];
    return $dm->writeJson(BOOKSHELVES_FILE, $list);
}

function remove_from_shelf(int $userId, int $novelId)
{
    global $dm;
    $list = $dm->readJson(BOOKSHELVES_FILE, []);
    $before = count($list);
    $list = array_values(array_filter($list, function($r) use ($userId, $novelId){
        return (int)$r['user_id'] !== $userId || (int)$r['novel_id'] !== $novelId;
    }));
    if (count($list) !== $before) return $dm->writeJson(BOOKSHELVES_FILE, $list);
    return true;
}

if ($action === 'add') {
    $novel_id = (int)($_GET['novel_id'] ?? 0);
    if ($novel_id && find_novel($novel_id)) add_to_shelf((int)$user['id'], $novel_id);
    header('Location: /reading.php?novel_id='.$novel_id);
    exit;
}
if ($action === 'remove') {
    $novel_id = (int)($_GET['novel_id'] ?? 0);
    if ($novel_id) remove_from_shelf((int)$user['id'], $novel_id);
    header('Location: /shelf.php');
    exit;
}

// list shelf
$entries = array_values(array_filter($dm->readJson(BOOKSHELVES_FILE, []), function($r) use ($user){ return (int)$r['user_id'] === (int)$user['id']; }));
$novels = load_novels();
$novelMap = [];
foreach ($novels as $n) { $novelMap[(int)$n['id']] = $n; }
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>我的书架 - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1>我的书架</h1>
    <div>
      <a class="btn btn-secondary" href="/">首页</a>
      <a class="btn btn-secondary" href="/dashboard.php">仪表盘</a>
    </div>
  </div>
  <?php if (!$entries): ?>
    <div class="alert alert-info">书架为空，去发现喜欢的作品吧。</div>
  <?php else: ?>
    <div class="list-group">
      <?php foreach ($entries as $e): $n = $novelMap[(int)$e['novel_id']] ?? null; if(!$n) continue; ?>
        <div class="list-group-item d-flex justify-content-between align-items-center">
          <div>
            <strong><?php echo e($n['title']); ?></strong>
            <span class="text-muted ms-2">作者：<?php echo e(get_user_display_name((int)$n['author_id'])); ?></span>
          </div>
          <div>
            <?php $chapters = list_chapters((int)$n['id'],'published'); if($chapters): $first=$chapters[0]; ?>
              <a class="btn btn-sm btn-primary" href="/reading.php?novel_id=<?php echo (int)$n['id']; ?>&chapter_id=<?php echo (int)$first['id']; ?>">阅读</a>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-danger" href="/shelf.php?action=remove&novel_id=<?php echo (int)$n['id']; ?>">移除</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
