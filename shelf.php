<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Notifier.php';
require_login();

global $dm;
$user = current_user();
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$userId = (int)($user['id'] ?? 0);

if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s) { return strtolower($s); }
}

$userExtra = user_extra_load($userId);
$categoryOptions = $userExtra['shelf_categories'] ?? ['默认'];
$categoryOptions = array_values(array_unique($categoryOptions));
if (!in_array('默认', $categoryOptions, true)) {
    array_unshift($categoryOptions, '默认');
}

function add_to_shelf(int $userId, int $novelId)
{
    global $dm;
    $list = $dm->readJson(BOOKSHELVES_FILE, []);
    foreach ($list as $row) {
        if ((int)($row['user_id'] ?? 0) === $userId && (int)($row['novel_id'] ?? 0) === $novelId) {
            return true;
        }
    }
    $list[] = [
        'user_id' => $userId,
        'novel_id' => $novelId,
        'category' => '默认',
        'added_at' => date('c')
    ];
    $ok = $dm->writeJson(BOOKSHELVES_FILE, $list);
    if ($ok) {
        $novel = find_novel($novelId);
        if ($novel) {
            (new Notifier())->notify(
                (int)($novel['author_id'] ?? 0),
                'interaction',
                '作品被收藏',
                '你的作品《' . ($novel['title'] ?? '') . '》被用户收藏。',
                '/novel_detail.php?novel_id=' . $novelId
            );
        }
    }
    return $ok;
}

function remove_from_shelf(int $userId, int $novelId)
{
    global $dm;
    $list = $dm->readJson(BOOKSHELVES_FILE, []);
    $before = count($list);
    $list = array_values(array_filter($list, function ($r) use ($userId, $novelId) {
        return (int)($r['user_id'] ?? 0) !== $userId || (int)($r['novel_id'] ?? 0) !== $novelId;
    }));
    if (count($list) !== $before) {
        return $dm->writeJson(BOOKSHELVES_FILE, $list);
    }
    return true;
}

if ($action === 'add') {
    $novel_id = (int)($_GET['novel_id'] ?? 0);
    if ($novel_id && find_novel($novel_id)) {
        add_to_shelf($userId, $novel_id);
    }
    header('Location: /reading.php?novel_id=' . $novel_id);
    exit;
}

if ($action === 'remove') {
    $novel_id = (int)($_GET['novel_id'] ?? 0);
    if ($novel_id) {
        remove_from_shelf($userId, $novel_id);
    }
    $redirectParams = [];
    if (isset($_GET['category']) && $_GET['category'] !== '') {
        $redirectParams['category'] = $_GET['category'];
    }
    if (isset($_GET['q']) && $_GET['q'] !== '') {
        $redirectParams['q'] = $_GET['q'];
    }
    $redirectUrl = '/shelf.php' . ($redirectParams ? ('?' . http_build_query($redirectParams)) : '');
    header('Location: ' . $redirectUrl);
    exit;
}

if ($action === 'update_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $novelId = (int)($_POST['novel_id'] ?? 0);
    $targetCategory = trim($_POST['category'] ?? '默认');
    if ($novelId > 0) {
        if (!in_array($targetCategory, $categoryOptions, true)) {
            $targetCategory = '默认';
        }
        $list = $dm->readJson(BOOKSHELVES_FILE, []);
        $changed = false;
        foreach ($list as &$row) {
            if ((int)($row['user_id'] ?? 0) === $userId && (int)($row['novel_id'] ?? 0) === $novelId) {
                if (($row['category'] ?? '默认') !== $targetCategory) {
                    $row['category'] = $targetCategory;
                    $row['updated_at'] = date('c');
                    $changed = true;
                }
                break;
            }
        }
        unset($row);
        if ($changed) {
            $dm->writeJson(BOOKSHELVES_FILE, $list);
        }
    }
    header('Location: /shelf.php?category=' . rawurlencode($targetCategory));
    exit;
}

$categoryFilter = $_GET['category'] ?? 'all';
$query = trim($_GET['q'] ?? '');

$allEntries = array_values(array_filter(
    $dm->readJson(BOOKSHELVES_FILE, []),
    function ($r) use ($userId) {
        return (int)($r['user_id'] ?? 0) === $userId;
    }
));

$categoryCounts = [];
foreach ($allEntries as $entry) {
    $cat = $entry['category'] ?? '默认';
    $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
}

$entries = $allEntries;
if ($categoryFilter !== 'all') {
    $entries = array_values(array_filter($entries, function ($e) use ($categoryFilter) {
        return ($e['category'] ?? '默认') === $categoryFilter;
    }));
}
if ($query !== '') {
    $lower = mb_strtolower($query);
    $entries = array_values(array_filter($entries, function ($e) use ($lower) {
        $novel = find_novel((int)($e['novel_id'] ?? 0));
        if (!$novel) {
            return false;
        }
        $title = mb_strtolower($novel['title'] ?? '');
        $author = mb_strtolower(get_user_display_name((int)($novel['author_id'] ?? 0)));
        return strpos($title, $lower) !== false || strpos($author, $lower) !== false;
    }));
}

usort($entries, function ($a, $b) {
    $timeA = strtotime($a['added_at'] ?? '') ?: 0;
    $timeB = strtotime($b['added_at'] ?? '') ?: 0;
    return $timeB <=> $timeA;
});

$novels = load_novels();
$novelMap = [];
foreach ($novels as $n) {
    $novelMap[(int)($n['id'] ?? 0)] = $n;
}

$progressList = $dm->readJson(READING_PROGRESS_FILE, []);
$progressMap = [];
foreach ($progressList as $p) {
    if ((int)($p['user_id'] ?? 0) === $userId) {
        $progressMap[(int)($p['novel_id'] ?? 0)] = $p;
    }
}

$bookmarkList = $dm->readJson(BOOKMARKS_FILE, []);
$bookmarkCounts = [];
foreach ($bookmarkList as $b) {
    if ((int)($b['user_id'] ?? 0) === $userId) {
        $nid = (int)($b['novel_id'] ?? 0);
        $bookmarkCounts[$nid] = ($bookmarkCounts[$nid] ?? 0) + 1;
    }
}

$chapterCache = [];
$chapterMapCache = [];

function shelf_get_chapters(int $novelId): array
{
    global $chapterCache;
    if (!array_key_exists($novelId, $chapterCache)) {
        $chapterCache[$novelId] = list_chapters($novelId, 'published');
    }
    return $chapterCache[$novelId];
}

function shelf_get_chapter_map(int $novelId): array
{
    global $chapterMapCache;
    if (!array_key_exists($novelId, $chapterMapCache)) {
        $map = [];
        foreach (shelf_get_chapters($novelId) as $chapter) {
            $map[(int)($chapter['id'] ?? 0)] = $chapter;
        }
        $chapterMapCache[$novelId] = $map;
    }
    return $chapterMapCache[$novelId];
}
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
  <style>
    .shelf-card-cover{
      width:72px;
      height:100px;
      object-fit:cover;
      border-radius:6px;
      box-shadow:0 2px 12px rgba(0,0,0,.1);
    }
    @media (max-width: 576px){
      .shelf-card-cover{ width:60px; height:84px; }
    }
  </style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
      <h1 class="mb-0">我的书架</h1>
      <div class="text-muted small">共收藏 <?php echo count($allEntries); ?> 本作品</div>
    </div>
    <div class="btn-group">
      <a class="btn btn-outline-secondary" href="/">首页</a>
      <a class="btn btn-outline-secondary" href="/dashboard.php">仪表盘</a>
    </div>
  </div>

  <form class="row g-2 align-items-end mb-4" method="get">
    <div class="col-12 col-md-4">
      <label class="form-label">搜索</label>
      <input type="text" class="form-control" name="q" placeholder="搜索书名或作者" value="<?php echo e($query); ?>">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label">分类</label>
      <select class="form-select" name="category">
        <option value="all" <?php if ($categoryFilter === 'all') echo 'selected'; ?>>全部分类 (<?php echo count($allEntries); ?>)</option>
        <?php foreach ($categoryOptions as $cat): $count = $categoryCounts[$cat] ?? 0; ?>
          <option value="<?php echo e($cat); ?>" <?php if ($categoryFilter === $cat) echo 'selected'; ?>><?php echo e($cat); ?> (<?php echo $count; ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label">&nbsp;</label>
      <button class="btn btn-primary w-100">筛选</button>
    </div>
  </form>

  <?php if (!$entries): ?>
    <div class="alert alert-info">
      <?php if ($categoryFilter !== 'all' || $query !== ''): ?>
        未找到匹配的作品，试着调整筛选条件。
      <?php else: ?>
        书架为空，去发现喜欢的作品吧。
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="list-group">
      <?php foreach ($entries as $entry):
        $novelId = (int)($entry['novel_id'] ?? 0);
        $novel = $novelMap[$novelId] ?? null;
        if (!$novel) { continue; }
        $categoryName = $entry['category'] ?? '默认';
        $chapters = shelf_get_chapters($novelId);
        $chapterMap = shelf_get_chapter_map($novelId);
        $firstChapter = $chapters ? $chapters[0] : null;
        $progress = $progressMap[$novelId] ?? null;
        $progressChapter = null;
        if ($progress) {
          $cid = (int)($progress['chapter_id'] ?? 0);
          $progressChapter = $chapterMap[$cid] ?? null;
        }
        $bookmarkCount = $bookmarkCounts[$novelId] ?? 0;
        $addedAt = $entry['added_at'] ?? '';
        $addedAtText = $addedAt ? date('Y-m-d', strtotime($addedAt)) : '';
        $progressText = '尚未开始阅读';
        $continueUrl = null;
        if ($progressChapter) {
          $page = (int)($progress['page'] ?? 0) + 1;
          $progressText = '上次阅读：第' . (int)($progressChapter['id'] ?? 0) . '章 · ' . e($progressChapter['title'] ?? '') . ' · 第' . $page . '页';
          $continueUrl = '/reading.php?novel_id=' . $novelId . '&chapter_id=' . (int)($progressChapter['id'] ?? 0) . '&page=' . (int)($progress['page'] ?? 0);
        } elseif ($firstChapter) {
          $progressText = '从第' . (int)($firstChapter['id'] ?? 1) . '章开始阅读';
          $continueUrl = '/reading.php?novel_id=' . $novelId . '&chapter_id=' . (int)($firstChapter['id'] ?? 0);
        }
        $encodedCategory = urlencode($categoryFilter);
        $encodedQuery = urlencode($query);
      ?>
      <div class="list-group-item py-3">
        <div class="d-flex flex-column flex-md-row align-items-start gap-3">
          <div class="d-flex align-items-start gap-3 flex-fill">
            <?php if (!empty($novel['cover_image'])): ?>
              <img src="/uploads/covers/<?php echo e($novel['cover_image']); ?>" alt="cover" class="shelf-card-cover" loading="lazy">
            <?php else: ?>
              <div class="shelf-card-cover bg-secondary bg-opacity-25 d-flex align-items-center justify-content-center text-muted">无封面</div>
            <?php endif; ?>
            <div>
              <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                <strong class="fs-5"><?php echo e($novel['title'] ?? ''); ?></strong>
                <span class="badge text-bg-primary"><?php echo e($categoryName); ?></span>
              </div>
              <div class="text-muted small mb-2">作者：<?php echo e(get_user_display_name((int)($novel['author_id'] ?? 0))); ?><?php if ($addedAtText): ?> · 收藏于 <?php echo e($addedAtText); ?><?php endif; ?></div>
              <div class="small text-secondary mb-1"><?php echo e($progressText); ?></div>
              <div class="small text-secondary">书签：<?php echo $bookmarkCount; ?> 个</div>
            </div>
          </div>
          <div class="d-flex flex-column align-items-stretch gap-2 w-100" style="max-width:260px;">
            <form method="post" class="d-flex gap-2">
              <input type="hidden" name="action" value="update_category">
              <input type="hidden" name="novel_id" value="<?php echo $novelId; ?>">
              <select class="form-select form-select-sm" name="category">
                <?php foreach ($categoryOptions as $cat): ?>
                  <option value="<?php echo e($cat); ?>" <?php if ($cat === $categoryName) echo 'selected'; ?>><?php echo e($cat); ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-outline-primary btn-sm" type="submit">调整</button>
            </form>
            <div class="btn-group">
              <?php if ($continueUrl): ?>
                <a class="btn btn-sm btn-primary" href="<?php echo $continueUrl; ?>"><?php echo $progressChapter ? '继续阅读' : '开始阅读'; ?></a>
              <?php else: ?>
                <button class="btn btn-sm btn-secondary" disabled>暂无章节</button>
              <?php endif; ?>
              <a class="btn btn-sm btn-outline-secondary" href="/novel_detail.php?novel_id=<?php echo $novelId; ?>">详情</a>
              <a class="btn btn-sm btn-outline-danger" href="/shelf.php?action=remove&novel_id=<?php echo $novelId; ?><?php if($categoryFilter!=='all') echo '&category=' . $encodedCategory; ?><?php if($query!=='') echo '&q=' . $encodedQuery; ?>" onclick="return confirm('确定要将该作品从书架移除吗？');">移除</a>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
