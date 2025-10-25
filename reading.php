<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/UserLimits.php';
require_once __DIR__ . '/lib/AdManager.php';

if (!function_exists('render_paragraphs')) {
    function render_paragraphs(string $text)
    {
        $text = str_replace("\r\n", "\n", $text);
        $blocks = preg_split("/\n{2,}/", trim($text));
        if (!$blocks) return;
        foreach ($blocks as $b) {
            $b = trim($b);
            if ($b === '') continue;
            echo '<p>' . nl2br(e($b)) . '</p>' . "\n";
        }
    }
}

function is_mobile_device(): bool {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    return (bool)preg_match('/(iphone|ipod|ipad|android|mobile|blackberry|nokia|opera mini|windows phone)/i', $ua);
}

$novel_id = (int)($_GET['novel_id'] ?? 0);
$chapter_id = (int)($_GET['chapter_id'] ?? 0);
$initial_page = (int)($_GET['page'] ?? 0);
$action = $_GET['action'] ?? '';

global $dm;

// JSON API endpoints for progress and bookmarks
if ($action) {
    header('Content-Type: application/json; charset=utf-8');
    $user = current_user();
    $needAuth = in_array($action, ['get_progress','save_progress','list_bookmarks','add_bookmark','delete_bookmark'], true);
    if ($needAuth && !$user) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'not_logged_in']);
        exit;
    }

    if ($action === 'get_progress') {
        $list = $dm->readJson(READING_PROGRESS_FILE, []);
        $prog = null;
        foreach ($list as $row) {
            if ((int)($row['user_id'] ?? 0) === (int)$user['id'] && (int)($row['novel_id'] ?? 0) === $novel_id) {
                $prog = $row; break;
            }
        }
        echo json_encode(['ok'=>true,'progress'=>$prog]);
        exit;
    }
    if ($action === 'save_progress') {
        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) $data = $_POST;
        $cid = (int)($data['chapter_id'] ?? 0);
        $page = max(0, (int)($data['page'] ?? 0));
        $list = $dm->readJson(READING_PROGRESS_FILE, []);
        $saved = false;
        foreach ($list as &$row) {
            if ((int)($row['user_id'] ?? 0) === (int)$user['id'] && (int)($row['novel_id'] ?? 0) === $novel_id) {
                $row['chapter_id'] = $cid;
                $row['page'] = $page;
                $row['updated_at'] = date('c');
                $saved = true;
                break;
            }
        }
        unset($row);
        if (!$saved) {
            $list[] = [
                'user_id' => (int)$user['id'],
                'novel_id' => $novel_id,
                'chapter_id' => $cid,
                'page' => $page,
                'updated_at' => date('c'),
            ];
        }
        $ok = $dm->writeJson(READING_PROGRESS_FILE, $list);
        echo json_encode(['ok'=>$ok]);
        exit;
    }
    if ($action === 'list_bookmarks') {
        $all = $dm->readJson(BOOKMARKS_FILE, []);
        $list = array_values(array_filter($all, function($r) use ($novel_id, $user) {
            return (int)($r['user_id'] ?? 0) === (int)$user['id'] && (int)($r['novel_id'] ?? 0) === $novel_id;
        }));
        echo json_encode(['ok'=>true,'bookmarks'=>$list]);
        exit;
    }
    if ($action === 'add_bookmark') {
        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) $data = $_POST;
        $cid = (int)($data['chapter_id'] ?? 0);
        $page = max(0, (int)($data['page'] ?? 0));
        $note = trim((string)($data['note'] ?? ''));
        $item = [
            'user_id' => (int)$user['id'],
            'novel_id' => $novel_id,
            'chapter_id' => $cid,
            'page' => $page,
            'note' => $note,
            'created_at' => date('c'),
        ];
        $id = $dm->appendWithId(BOOKMARKS_FILE, $item, 'id');
        $item['id'] = $id;
        echo json_encode(['ok'=>true,'bookmark'=>$item]);
        exit;
    }
    if ($action === 'delete_bookmark') {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'invalid_id']); exit; }
        // Ensure ownership
        $all = $dm->readJson(BOOKMARKS_FILE, []);
        $found = null;
        foreach ($all as $b) { if ((int)($b['id'] ?? 0) === $id) { $found = $b; break; } }
        if (!$found || (int)($found['user_id'] ?? 0) !== (int)$user['id']) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
        // Delete
        $ok = $dm->deleteById(BOOKMARKS_FILE, $id, 'id');
        echo json_encode(['ok'=>$ok]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'unknown_action']);
    exit;
}

$novel = $novel_id ? find_novel($novel_id) : null;
if (!$novel) { http_response_code(404); echo '小说不存在'; exit; }

// Check user limits
$limitsManager = new UserLimits();
$adManager = new AdManager();
$user = current_user();
$limitWarning = null;

if ($user && $chapter_id > 0) {
    $userId = (int)$user['id'];
    $limitCheck = $limitsManager->checkLimit($userId, 'chapter');
    
    if (!$limitCheck['allowed']) {
        $limitWarning = sprintf(
            '您今日的阅读章节已达到限制（%d/%d章）。明天再来阅读更多内容吧！',
            $limitCheck['used'] ?? 0,
            $limitCheck['limit'] ?? 0
        );
        http_response_code(403);
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>阅读限制</title>';
        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
        echo '</head><body><div class="container py-5"><div class="alert alert-warning">';
        echo '<h4>阅读限制</h4><p>' . e($limitWarning) . '</p>';
        echo '<a href="/plans.php" class="btn btn-primary">升级会员获得无限阅读</a> ';
        echo '<a href="/" class="btn btn-secondary">返回首页</a>';
        echo '</div></div></body></html>';
        exit;
    }
    
    $limitsManager->recordChapterRead($userId, $novel_id, $chapter_id);
}

// Auto-resume if no chapter specified and user has saved progress
if ($chapter_id <= 0) {
    $u = current_user();
    if ($u) {
        $list = $dm->readJson(READING_PROGRESS_FILE, []);
        foreach ($list as $row) {
            if ((int)($row['user_id'] ?? 0) === (int)$u['id'] && (int)($row['novel_id'] ?? 0) === $novel_id) {
                $rid = (int)($row['chapter_id'] ?? 0);
                $rpage = (int)($row['page'] ?? 0);
                if ($rid > 0) {
                    header('Location: /reading.php?novel_id=' . (int)$novel_id . '&chapter_id=' . $rid . '&page=' . $rpage);
                    exit;
                }
            }
        }
    }
}

$chapters = list_chapters($novel_id, 'published');
$chapterMap = [];
foreach ($chapters as $c) { $chapterMap[(int)$c['id']] = $c; }
$chapter = $chapterMap[$chapter_id] ?? null;
if (!$chapter) {
    if ($chapters) {
        $chapter = $chapters[0];
        $chapter_id = (int)$chapter['id'];
    } else {
        echo '暂无发布章节'; exit;
    }
}

// navigation
$chapterIds = array_map(function($c){ return (int)$c['id']; }, $chapters);
$currentIndex = array_search($chapter_id, $chapterIds, true);
$prevId = $currentIndex !== false && $currentIndex > 0 ? $chapterIds[$currentIndex - 1] : null;
$nextId = $currentIndex !== false && $currentIndex < count($chapterIds)-1 ? $chapterIds[$currentIndex + 1] : null;
$deviceClass = is_mobile_device() ? 'device-mobile' : 'device-desktop';
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#f6f6f6">
  <title><?php echo e($novel['title']); ?> - <?php echo e($chapter['title']); ?> - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
  <link href="/assets/reader.css" rel="stylesheet">
  <?php echo $adManager->getAdScripts(); ?>
</head>
<body class="reading theme-day <?php echo $deviceClass; ?>" data-logged-in="<?php echo current_user() ? '1' : '0'; ?>">
<div id="readerApp" class="nh-reader">
  <div class="nh-reader__topbar">
    <div class="nh-reader__row container-fluid">
      <div class="d-flex align-items-center gap-2">
        <a href="javascript:history.length>1?history.back():window.location.assign('/')" class="btn btn-sm btn-outline-light" title="返回">返回</a>
        <a href="/" class="btn btn-sm btn-outline-light" title="首页">首页</a>
        <a href="/dashboard.php" class="btn btn-sm btn-outline-light" title="仪表盘">仪表盘</a>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span class="nh-reader__title d-none d-md-inline"><?php echo e($novel['title']); ?> · <?php echo e($chapter['title']); ?></span>
        <select id="chapterSelect" class="form-select form-select-sm nh-reader__chapter-select" title="跳转章节">
          <?php foreach ($chapters as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php if ((int)$c['id']===(int)$chapter_id) echo 'selected'; ?>><?php echo e($c['title']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="d-flex align-items-center gap-2">
        <?php if (current_user()): ?>
          <a class="btn btn-sm btn-outline-light" href="/shelf.php?action=add&novel_id=<?php echo (int)$novel_id; ?>" title="加入书架">加入书架</a>
        <?php else: ?>
          <a class="btn btn-sm btn-outline-light" href="/login.php" title="登录">登录</a>
        <?php endif; ?>
        <button id="btnBookmarks" class="btn btn-sm btn-outline-light" title="书签">书签</button>
        <button id="btnSettings" class="btn btn-sm btn-outline-light" title="设置">设置</button>
      </div>
    </div>
  </div>

  <div class="container py-3">
    <h1 class="h4 mb-2 d-md-none"><?php echo e($novel['title']); ?></h1>
    <div class="text-muted mb-2 d-md-none">作者：<?php echo e(get_user_display_name((int)$novel['author_id'])); ?> · 更新：<?php echo e($chapter['updated_at']); ?></div>
  </div>

  <?php echo $adManager->renderAd('header_banner', $user); ?>

  <div class="nh-reader__viewport width-standard" id="readerViewport"
       data-novel-id="<?php echo (int)$novel_id; ?>"
       data-chapter-id="<?php echo (int)$chapter_id; ?>"
       data-initial-page="<?php echo (int)$initial_page; ?>"
       data-prev-url="<?php echo $prevId!==null? '/reading.php?novel_id='.(int)$novel_id.'&chapter_id='.(int)$prevId : ''; ?>"
       data-next-url="<?php echo $nextId!==null? '/reading.php?novel_id='.(int)$novel_id.'&chapter_id='.(int)$nextId : ''; ?>">
    <div class="nh-reader__content" id="readerContent">
<?php render_paragraphs((string)($chapter['content'] ?? '')); ?>
    </div>
  </div>

  <div class="nh-reader__tap-zones">
    <div class="zone left" id="zoneLeft"></div>
    <div class="zone center" id="zoneCenter"></div>
    <div class="zone right" id="zoneRight"></div>
  </div>

  <div class="nh-reader__bottombar">
    <div class="nh-reader__row container-fluid">
      <div class="d-flex align-items-center gap-2">
        <a id="prevChapterBtn" href="<?php echo $prevId!==null? '/reading.php?novel_id='.(int)$novel_id.'&chapter_id='.(int)$prevId : 'javascript:void(0)'; ?>" class="btn btn-sm btn-outline-light <?php echo $prevId===null? 'disabled':''; ?>" title="上一章">上一章</a>
      </div>
      <div class="nh-reader__progress">
        <input type="range" id="progressRange" min="0" max="0" value="0" aria-label="阅读进度">
        <span id="pageIndicator" class="text-white-50 small">1/1</span>
      </div>
      <div class="d-flex align-items-center gap-2">
        <a id="nextChapterBtn" href="<?php echo $nextId!==null? '/reading.php?novel_id='.(int)$novel_id.'&chapter_id='.(int)$nextId : 'javascript:void(0)'; ?>" class="btn btn-sm btn-outline-light <?php echo $nextId===null? 'disabled':''; ?>" title="下一章">下一章</a>
      </div>
    </div>
  </div>

  <!-- Mobile bottom bar -->
  <nav class="nh-reader__mobilebar d-md-none">
    <div class="mobilebar__grid container-fluid">
      <button id="mbPrev" class="mobilebar__btn" title="上一页" aria-label="上一页">
        <svg class="mobilebar__icon" viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
        <span class="mobilebar__label">上页</span>
      </button>
      <button id="mbBookmarks" class="mobilebar__btn" title="书签" aria-label="书签">
        <svg class="mobilebar__icon" viewBox="0 0 24 24"><path d="M6 4h12a2 2 0 0 1 2 2v16l-8-3-8 3V6a2 2 0 0 1 2-2z"/></svg>
        <span class="mobilebar__label">书签</span>
      </button>
      <button id="mbSettings" class="mobilebar__btn" title="设置" aria-label="设置">
        <svg class="mobilebar__icon" viewBox="0 0 24 24"><path d="M19.14 12.94a7.43 7.43 0 0 0 .05-.94 7.43 7.43 0 0 0-.05-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.3 7.3 0 0 0-1.63-.94l-.36-2.54a.5.5 0 0 0-.5-.42h-3.84a.5.5 0 0 0-.5.42l-.36 2.54c-.58.22-1.12.52-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.66 8.84a.5.5 0 0 0 .12.64l2.03 1.58c-.03.31-.05.63-.05.94s.02.63.05.94L2.78 14.52a.5.5 0 0 0-.12.64l1.92 3.32c.14.24.43.34.69.22l2.39-.96c.51.42 1.05.72 1.63.94l.36 2.54c.05.25.26.42.5.42h3.84c.24 0 .45-.17.5-.42l.36-2.54c.58-.22 1.12-.52 1.63-.94l2.39.96c.26.12.55.02.69-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58zM12 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7z"/></svg>
        <span class="mobilebar__label">设置</span>
      </button>
      <button id="mbFocus" class="mobilebar__btn" title="专注模式" aria-label="专注模式">
        <svg class="mobilebar__icon" viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm0-4h3V7h2v5H7V10zm10 9h-5v-2h3v-3h2v5zM12 7V5h5v5h-2V7h-3z"/></svg>
        <span class="mobilebar__label">专注</span>
      </button>
      <button id="mbNext" class="mobilebar__btn" title="下一页" aria-label="下一页">
        <svg class="mobilebar__icon" viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>
        <span class="mobilebar__label">下页</span>
      </button>
    </div>
  </nav>

  <div id="settingsPanel" class="nh-reader__panel">
    <div class="container">
      <div class="row">
        <div class="col-6 col-md-3">
          <label class="form-label text-white-50">字体大小</label>
          <select id="fontSizeSelect" class="form-select form-select-sm">
            <option value="16">小</option>
            <option value="18" selected>中</option>
            <option value="20">大</option>
            <option value="22">超大</option>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label text-white-50">字体</label>
          <select id="fontFamilySelect" class="form-select form-select-sm">
            <option value="system">系统默认</option>
            <option value="serif">思源宋体/宋体</option>
            <option value="kaiti">楷体</option>
            <option value="heiti">黑体</option>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label text-white-50">行间距</label>
          <select id="lineHeightSelect" class="form-select form-select-sm">
            <option value="1.2">1.2</option>
            <option value="1.5" selected>1.5</option>
            <option value="1.8">1.8</option>
            <option value="2.0">2.0</option>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label text-white-50">段落间距</label>
          <select id="paraSpaceSelect" class="form-select form-select-sm">
            <option value="0.6">0.6em</option>
            <option value="0.9" selected>0.9em</option>
            <option value="1.2">1.2em</option>
            <option value="1.6">1.6em</option>
          </select>
        </div>
      </div>
      <div class="row mt-2">
        <div class="col-6 col-md-3">
          <label class="form-label text-white-50">主题</label>
          <div class="d-flex gap-2">
            <button data-theme="day" class="btn btn-sm btn-light">日间</button>
            <button data-theme="night" class="btn btn-sm btn-dark">夜间</button>
            <button data-theme="eye" class="btn btn-sm btn-warning">护眼</button>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label text-white-50">宽度</label>
          <select id="widthModeSelect" class="form-select form-select-sm">
            <option value="narrow">窄版</option>
            <option value="standard" selected>标准</option>
            <option value="wide">宽版</option>
          </select>
        </div>
        <div class="col-12 col-md-6 mt-2 mt-md-0">
          <div class="row g-2 align-items-center">
            <div class="col-8 col-md-8">
              <label for="brightnessRange" class="form-label text-white-50 mb-1">亮度</label>
              <input type="range" id="brightnessRange" min="0" max="100" value="100" class="form-range" />
            </div>
            <div class="col-4 col-md-4 d-flex align-items-end justify-content-end gap-2">
              <div class="form-check form-switch text-white-50">
                <input class="form-check-input" type="checkbox" id="toggleWakeLock">
                <label class="form-check-label" for="toggleWakeLock">常亮</label>
              </div>
              <button id="btnAddBookmark" class="btn btn-sm btn-outline-light">添加书签</button>
              <button id="btnCloseSettings" class="btn btn-sm btn-light text-dark">关闭</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div id="bookmarksPanel" class="nh-reader__panel">
    <div class="container">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong>书签</strong>
        <button class="btn btn-sm btn-light text-dark" id="btnCloseBookmarks">关闭</button>
      </div>
      <ul id="bookmarksList" class="list-group list-group-flush">
        <!-- bookmarks will be loaded here -->
      </ul>
    </div>
  </div>

  <div id="dimOverlay" class="nh-reader__dim"></div>
  <div id="pullIndicator" class="nh-reader__pull-indicator">下拉刷新章节</div>
</div>

<!-- Inline minimal icon symbols for potential <use> expansion -->
<svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0;overflow:hidden">
  <symbol id="ic-prev" viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></symbol>
  <symbol id="ic-next" viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></symbol>
  <symbol id="ic-bookmark" viewBox="0 0 24 24"><path d="M6 4h12a2 2 0 0 1 2 2v16l-8-3-8 3V6a2 2 0 0 1 2-2z"/></symbol>
  <symbol id="ic-settings" viewBox="0 0 24 24"><path d="M19.14 12.94a7.43 7.43 0 0 0 .05-.94 7.43 7.43 0 0 0-.05-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.3 7.3 0 0 0-1.63-.94l-.36-2.54a.5.5 0 0 0-.5-.42h-3.84a.5.5 0 0 0-.5.42l-.36 2.54c-.58.22-1.12.52-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.66 8.84a.5.5 0 0 0 .12.64l2.03 1.58c-.03.31-.05.63-.05.94s.02.63.05.94L2.78 14.52a.5.5 0 0 0-.12.64l1.92 3.32c.14.24.43.34.69.22l2.39-.96c.51.42 1.05.72 1.63.94l.36 2.54c.05.25.26.42.5.42h3.84c.24 0 .45-.17.5-.42l.36-2.54c.58-.22 1.12-.52 1.63-.94l2.39.96c.26.12.55.02.69-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58zM12 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7z"/></symbol>
  <symbol id="ic-focus" viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm0-4h3V7h2v5H7V10zm10 9h-5v-2h3v-3h2v5zM12 7V5h5v5h-2V7h-3z"/></symbol>
</svg>

<script src="/assets/reader.js"></script>
</body>
</html>
