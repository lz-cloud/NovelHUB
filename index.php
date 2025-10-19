<?php
require_once __DIR__ . '/lib/helpers.php';
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s) { return strtolower($s); }
}
if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth($s, $start, $width, $trimmarker = '...', $encoding = 'UTF-8') {
        $s = (string)$s;
        if (strlen($s) <= $width) return $s;
        return substr($s, 0, $width) . $trimmarker;
    }
}
$novels = load_novels();
// sort by updated_at desc
usort($novels, function($a,$b){ return strtotime($b['updated_at'] ?? $b['created_at'] ?? 'now') <=> strtotime($a['updated_at'] ?? $a['created_at'] ?? 'now'); });
$query = trim($_GET['q'] ?? '');
if ($query !== '') {
    $q = mb_strtolower($query);
    $novels = array_values(array_filter($novels, function($n) use ($q){
        $authorName = get_user_display_name((int)($n['author_id'] ?? 0));
        return (strpos(mb_strtolower($n['title'] ?? ''), $q) !== false) || (strpos(mb_strtolower($authorName), $q) !== false);
    }));
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NovelHub</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="/">NovelHub</a>
    <div>
      <?php if (current_user()): ?>
        <a class="btn btn-sm btn-outline-light" href="/dashboard.php">仪表盘</a>
        <a class="btn btn-sm btn-outline-light" href="/logout.php">退出</a>
      <?php else: ?>
        <a class="btn btn-sm btn-outline-light" href="/login.php">登录</a>
        <a class="btn btn-sm btn-primary" href="/register.php">注册</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
<div class="container my-4">
  <form class="row g-2 mb-3" method="get">
    <div class="col-auto"><input type="text" class="form-control" name="q" placeholder="搜索标题或作者" value="<?php echo e($query); ?>"></div>
    <div class="col-auto"><button class="btn btn-primary">搜索</button></div>
  </form>

  <div class="row row-cols-1 row-cols-md-3 g-4">
    <?php foreach ($novels as $n): ?>
      <div class="col">
        <div class="card h-100">
          <?php if (!empty($n['cover_image'])): ?>
            <img src="/uploads/covers/<?php echo e($n['cover_image']); ?>" class="card-img-top" alt="cover">
          <?php endif; ?>
          <div class="card-body">
            <h5 class="card-title"><?php echo e($n['title'] ?? ''); ?></h5>
            <p class="card-text text-muted">作者：<?php echo e(get_user_display_name((int)($n['author_id'] ?? 0))); ?></p>
            <p class="card-text"><?php echo e(mb_strimwidth($n['description'] ?? '', 0, 120, '...','UTF-8')); ?></p>
            <?php $chapters = list_chapters((int)$n['id'],'published'); if ($chapters): $first = $chapters[0]; ?>
              <a class="btn btn-sm btn-primary" href="/reading.php?novel_id=<?php echo (int)$n['id']; ?>&chapter_id=<?php echo (int)$first['id']; ?>">开始阅读</a>
            <?php else: ?>
              <span class="badge text-bg-secondary">暂未发布章节</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
</body>
</html>
