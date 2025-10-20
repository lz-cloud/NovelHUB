<?php
require_once __DIR__ . '/lib/helpers.php';
require_login();

$user = current_user();
$myNovels = array_values(array_filter(load_novels(), function($n) use ($user){ return (int)$n['author_id'] === (int)$user['id']; }));
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>作者仪表盘 - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1>作者仪表盘</h1>
    <div>
      <a class="btn btn-secondary" href="/">返回首页</a>
      <a class="btn btn-outline-danger" href="/logout.php">退出登录</a>
    </div>
  </div>
  <div class="mb-4">
    <a class="btn btn-primary" href="/create_novel.php">+ 创建新小说</a>
    <?php if (is_admin()): ?>
      <a class="btn btn-warning" href="/admin.php">管理员后台</a>
    <?php endif; ?>
  </div>

  <?php if (!$myNovels): ?>
    <div class="alert alert-info">你还没有作品，点击“创建新小说”开始创作吧。</div>
  <?php endif; ?>

  <?php foreach ($myNovels as $n): ?>
    <?php $chapters = list_chapters((int)$n['id']); ?>
    <div class="card mb-4">
      <div class="card-body">
        <div class="d-flex align-items-start">
          <?php if (!empty($n['cover_image'])): ?>
            <img src="/uploads/covers/<?php echo e($n['cover_image']); ?>" class="me-3" alt="cover" style="width:96px;height:128px;object-fit:cover;">
          <?php endif; ?>
          <div class="flex-grow-1">
            <h4 class="mb-1"><?php echo e($n['title']); ?></h4>
            <div class="text-muted mb-2">创建时间：<?php echo e($n['created_at']); ?>，最近更新：<?php echo e($n['updated_at']); ?></div>
            <a class="btn btn-sm btn-success" href="/publish_chapter.php?novel_id=<?php echo (int)$n['id']; ?>">+ 发布新章节</a>
          </div>
        </div>
        <hr>
        <h5>章节列表</h5>
        <?php if (!$chapters): ?>
          <p class="text-muted">暂无章节</p>
        <?php else: ?>
          <div class="list-group">
            <?php foreach ($chapters as $c): ?>
              <div class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                  <span class="badge rounded-pill <?php echo $c['status']==='published'?'text-bg-success':'text-bg-secondary'; ?>"><?php echo $c['status']==='published'?'已发布':'草稿'; ?></span>
                  <strong class="ms-2"><?php echo e($c['title']); ?></strong>
                  <small class="text-muted ms-2"><?php echo e($c['updated_at']); ?></small>
                </div>
                <div class="btn-group">
                  <?php if ($c['status']==='published'): ?>
                    <a class="btn btn-sm btn-outline-primary" href="/reading.php?novel_id=<?php echo (int)$n['id']; ?>&chapter_id=<?php echo (int)$c['id']; ?>">阅读</a>
                  <?php endif; ?>
                  <a class="btn btn-sm btn-outline-secondary" href="/publish_chapter.php?novel_id=<?php echo (int)$n['id']; ?>&chapter_id=<?php echo (int)$c['id']; ?>">编辑</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
