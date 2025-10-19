<?php
require_once __DIR__ . '/lib/helpers.php';

$novel_id = (int)($_GET['novel_id'] ?? 0);
$chapter_id = (int)($_GET['chapter_id'] ?? 0);
$novel = $novel_id ? find_novel($novel_id) : null;
if (!$novel) { http_response_code(404); echo '小说不存在'; exit; }
$chapters = list_chapters($novel_id, 'published');
$chapterMap = [];
foreach ($chapters as $c) { $chapterMap[(int)$c['id']] = $c; }
$chapter = $chapterMap[$chapter_id] ?? null;
if (!$chapter) {
    // default to first published
    if ($chapters) {
        $chapter = $chapters[0];
        $chapter_id = (int)$chapter['id'];
    } else {
        echo '暂无发布章节'; exit;
    }
}

// navigation
$chapterIds = array_map(function($c){ return (int)$c['id']; }, $chapters);
$currentIndex = array_search($chapter_id, $chapterIds);
$prevId = $currentIndex > 0 ? $chapterIds[$currentIndex - 1] : null;
$nextId = $currentIndex !== false && $currentIndex < count($chapterIds)-1 ? $chapterIds[$currentIndex + 1] : null;
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo e($novel['title']); ?> - <?php echo e($chapter['title']); ?> - NovelHub</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .reading-container { max-width: 840px; margin: 0 auto; }
    .chapter-content { white-space: pre-wrap; line-height: 1.85; font-size: 1.05rem; }
  </style>
</head>
<body>
<div class="container py-3 reading-container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <a href="/" class="btn btn-sm btn-outline-secondary">首页</a>
      <a href="/dashboard.php" class="btn btn-sm btn-outline-secondary">仪表盘</a>
    </div>
    <div>
      <?php if (current_user()): ?>
        <a class="btn btn-sm btn-outline-primary" href="/shelf.php?action=add&novel_id=<?php echo (int)$novel_id; ?>">加入书架</a>
      <?php else: ?>
        <a class="btn btn-sm btn-outline-primary" href="/login.php">登录以加入书架</a>
      <?php endif; ?>
    </div>
  </div>
  <h1 class="mb-1"><?php echo e($novel['title']); ?></h1>
  <div class="text-muted mb-3">作者：<?php echo e(get_user_display_name((int)$novel['author_id'])); ?> · 更新：<?php echo e($chapter['updated_at']); ?></div>
  <h3 class="mb-3"><?php echo e($chapter['title']); ?></h3>
  <div class="chapter-content border rounded p-3 bg-light">
    <?php echo nl2br(e($chapter['content'])); ?>
  </div>
  <div class="d-flex justify-content-between my-4">
    <div>
      <?php if ($prevId !== null): ?>
        <a class="btn btn-outline-secondary" href="/reading.php?novel_id=<?php echo (int)$novel_id; ?>&chapter_id=<?php echo (int)$prevId; ?>">上一章</a>
      <?php endif; ?>
    </div>
    <div>
      <?php if ($nextId !== null): ?>
        <a class="btn btn-primary" href="/reading.php?novel_id=<?php echo (int)$novel_id; ?>&chapter_id=<?php echo (int)$nextId; ?>">下一章</a>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
