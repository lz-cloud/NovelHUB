<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Membership.php';
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
$categories = json_decode(file_get_contents(CATEGORIES_FILE), true) ?: [];
$currentUser = current_user();
$isPlusUser = false;
if ($currentUser) {
    $membershipSvc = new Membership();
    $isPlusUser = $membershipSvc->isPlusUser((int)$currentUser['id']);
}

$query = trim($_GET['q'] ?? '');
$category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$statusFilter = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'updated';
$validStatuses = ['ongoing','completed'];

if ($query !== '') {
    $q = mb_strtolower($query);
    $novels = array_values(array_filter($novels, function($n) use ($q){
        $authorName = get_user_display_name((int)($n['author_id'] ?? 0));
        return (strpos(mb_strtolower($n['title'] ?? ''), $q) !== false) || (strpos(mb_strtolower($authorName), $q) !== false);
    }));
}

if ($category) {
    $novels = array_values(array_filter($novels, function($n) use ($category){
        $cats = array_map('intval', $n['category_ids'] ?? []);
        return in_array($category, $cats, true);
    }));
}

if (in_array($statusFilter, $validStatuses, true)) {
    $novels = array_values(array_filter($novels, function($n) use ($statusFilter){
        return ($n['status'] ?? 'ongoing') === $statusFilter;
    }));
}

// sort
usort($novels, function($a,$b) use ($sort) {
    if ($sort === 'created') {
        return strtotime($b['created_at'] ?? 'now') <=> strtotime($a['created_at'] ?? 'now');
    }
    // default: updated
    $bu = strtotime($b['updated_at'] ?? $b['created_at'] ?? 'now');
    $au = strtotime($a['updated_at'] ?? $a['created_at'] ?? 'now');
    return $bu <=> $au;
});
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="/">NovelHub</a>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-info" href="/plans.php">会员计划</a>
      <?php if ($currentUser): ?>
        <?php if ($isPlusUser): ?>
          <span class="badge text-bg-warning text-dark">PLUS</span>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-light" href="/dashboard.php">仪表盘</a>
        <a class="btn btn-sm btn-outline-light" href="/logout.php">退出</a>
      <?php else: ?>
        <a class="btn btn-sm btn-outline-light" href="/login.php">登录</a>
        <a class="btn btn-sm btn-primary" href="/register.php">注册</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
<div class="container my-4 nh-list">
  <form class="row g-2 mb-3" method="get">
    <div class="col-12 col-md-auto">
      <input type="text" class="form-control" name="q" placeholder="搜索标题或作者" value="<?php echo e($query); ?>">
    </div>
    <div class="col-6 col-md-auto">
      <select class="form-select" name="category">
        <option value="0">全部分类</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php if ((int)$category === (int)$c['id']) echo 'selected'; ?>><?php echo e($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-auto">
      <select class="form-select" name="status">
        <option value="">全部状态</option>
        <option value="ongoing" <?php if ($statusFilter==='ongoing') echo 'selected'; ?>>连载中</option>
        <option value="completed" <?php if ($statusFilter==='completed') echo 'selected'; ?>>已完结</option>
      </select>
    </div>
    <div class="col-6 col-md-auto">
      <select class="form-select" name="sort">
        <option value="updated" <?php if ($sort==='updated') echo 'selected'; ?>>按最近更新</option>
        <option value="created" <?php if ($sort==='created') echo 'selected'; ?>>按创建时间</option>
      </select>
    </div>
    <div class="col-6 col-md-auto">
      <button class="btn btn-primary">搜索</button>
      <a class="btn btn-outline-secondary" href="/">重置</a>
    </div>
  </form>

  <div class="row row-cols-1 row-cols-md-3 g-4">
    <?php foreach ($novels as $n): ?>
      <div class="col">
        <div class="card h-100">
          <?php if (!empty($n['cover_image'])): ?>
            <img src="/uploads/covers/<?php echo e($n['cover_image']); ?>" class="card-img-top" alt="cover" loading="lazy">
          <?php endif; ?>
          <div class="card-body">
            <h5 class="card-title"><?php echo e($n['title'] ?? ''); ?></h5>
            <p class="card-text text-muted">作者：<?php echo e(get_user_display_name((int)($n['author_id'] ?? 0))); ?></p>
            <p class="card-text"><?php echo e(mb_strimwidth($n['description'] ?? '', 0, 120, '...','UTF-8')); ?></p>
            <?php $chapters = list_chapters((int)$n['id'],'published'); if ($chapters): $first = $chapters[0]; ?>
              <a class="btn btn-sm btn-primary me-2" href="/reading.php?novel_id=<?php echo (int)$n['id']; ?>&chapter_id=<?php echo (int)$first['id']; ?>">开始阅读</a>
            <?php else: ?>
              <span class="badge text-bg-secondary me-2">暂未发布章节</span>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary" href="/novel_detail.php?novel_id=<?php echo (int)$n['id']; ?>">详情</a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
</body>
</html>
