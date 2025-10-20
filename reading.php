<?php
require_once __DIR__ . '/lib/helpers.php';

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
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo e($novel['title']); ?> - <?php echo e($chapter['title']); ?> - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
  <style>
    :root{
      --reader-font-size: 18px;
      --reader-line-height: 1.6;
      --reader-para-space: 0.9em;
      --reader-font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'Noto Sans SC', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif;
    }
    body.reading{margin:0;}
    body.reading.theme-day{ --paper-bg:#fffdf7; --paper-fg:#222; background:#f6f6f6; }
    body.reading.theme-night{ --paper-bg:#0f1115; --paper-fg:#d5d5d5; background:#050608; }
    body.reading.theme-eye{ --paper-bg:#faf4d3; --paper-fg:#202020; background:#efe9c8; }

    .reader-app{min-height:100vh; position:relative;}

    .reader-topbar,.reader-bottombar{ position:fixed; left:0; right:0; z-index:1000; color:#fff; transition:opacity .25s ease; opacity:0; pointer-events:none; }
    .reader-topbar{ top:0; background:rgba(0,0,0,.5); padding:8px 10px; }
    .reader-bottombar{ bottom:0; background:rgba(0,0,0,.5); padding:8px 10px; }
    .controls-visible .reader-topbar, .controls-visible .reader-bottombar{ opacity:1; pointer-events:all; }
    .reader-topbar .title{ font-weight:600; }
    .reader-topbar .chapter-select{ max-width: 50vw; }

    .reader-viewport{ margin: 0 auto; padding: 14px 12px; border-radius: 8px; background: var(--paper-bg); color: var(--paper-fg); box-shadow: 0 6px 18px rgba(0,0,0,.08); }
    .reader-viewport.width-narrow{ max-width: 640px; }
    .reader-viewport.width-standard{ max-width: 820px; }
    .reader-viewport.width-wide{ max-width: 980px; }

    .reader-viewport{ height: calc(var(--vh, 1vh) * 100 - 120px); overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
    .reader-viewport::-webkit-scrollbar{ display:none; }

    .reader-content{ font-size: var(--reader-font-size); line-height: var(--reader-line-height); font-family: var(--reader-font-family);
                      column-gap: 0; height: 100%;}
    .reader-content p{ margin: 0 0 var(--reader-para-space) 0; text-align: justify; }

    .tap-zones{ position: fixed; inset: 0; z-index: 900; display: grid; grid-template-columns: 30% 40% 30%; }
    .tap-zones .zone{ height: 100%; }
    .tap-zones .zone.center{ cursor: pointer; }

    .settings-panel, .bookmarks-panel{ position: fixed; left: 0; right: 0; bottom: 60px; z-index: 1200; max-width: 980px; margin: 0 auto; background: rgba(0,0,0,.75); color: #fff; border-radius: 10px; padding: 10px 12px; opacity: 0; pointer-events: none; transform: translateY(6px); transition: all .2s ease; }
    .settings-panel.show, .bookmarks-panel.show{ opacity:1; pointer-events:auto; transform: translateY(0); }
    .settings-panel .row > div{ margin-bottom: 8px; }
    .settings-panel select{ width: 100%; }

    .reader-actions .btn{ color:#fff; border-color: rgba(255,255,255,.6); }
    .reader-bottombar .progress-wrap{ display:flex; align-items:center; gap:10px; }
    .reader-bottombar input[type=range]{ width: 50vw; }
  </style>
</head>
<body class="reading theme-day" data-logged-in="<?php echo current_user() ? '1' : '0'; ?>">
<div id="readerApp" class="reader-app">
  <div class="reader-topbar">
    <div class="container-fluid d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-2">
        <a href="javascript:history.length>1?history.back():window.location.assign('/')" class="btn btn-sm btn-outline-light">返回</a>
        <a href="/" class="btn btn-sm btn-outline-light">首页</a>
        <a href="/dashboard.php" class="btn btn-sm btn-outline-light">仪表盘</a>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span class="title d-none d-md-inline"><?php echo e($novel['title']); ?> · <?php echo e($chapter['title']); ?></span>
        <select id="chapterSelect" class="form-select form-select-sm chapter-select" title="跳转章节">
          <?php foreach ($chapters as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php if ((int)$c['id']===(int)$chapter_id) echo 'selected'; ?>><?php echo e($c['title']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="reader-actions d-flex align-items-center gap-2">
        <?php if (current_user()): ?>
          <a class="btn btn-sm btn-outline-light" href="/shelf.php?action=add&novel_id=<?php echo (int)$novel_id; ?>">加入书架</a>
        <?php else: ?>
          <a class="btn btn-sm btn-outline-light" href="/login.php">登录</a>
        <?php endif; ?>
        <button id="btnBookmarks" class="btn btn-sm btn-outline-light">书签</button>
        <button id="btnSettings" class="btn btn-sm btn-outline-light">设置</button>
      </div>
    </div>
  </div>

  <div class="container py-3">
    <h1 class="h4 mb-2 d-md-none"><?php echo e($novel['title']); ?></h1>
    <div class="text-muted mb-2 d-md-none">作者：<?php echo e(get_user_display_name((int)$novel['author_id'])); ?> · 更新：<?php echo e($chapter['updated_at']); ?></div>
  </div>

  <div class="reader-viewport width-standard" id="readerViewport"
       data-novel-id="<?php echo (int)$novel_id; ?>"
       data-chapter-id="<?php echo (int)$chapter_id; ?>"
       data-initial-page="<?php echo (int)$initial_page; ?>"
       data-prev-url="<?php echo $prevId!==null? '/reading.php?novel_id='.(int)$novel_id.'&chapter_id='.(int)$prevId : ''; ?>"
       data-next-url="<?php echo $nextId!==null? '/reading.php?novel_id='.(int)$novel_id.'&chapter_id='.(int)$nextId : ''; ?>">
    <div class="reader-content" id="readerContent">
<?php render_paragraphs((string)($chapter['content'] ?? '')); ?>
    </div>
  </div>

  <div class="tap-zones">
    <div class="zone left" id="zoneLeft"></div>
    <div class="zone center" id="zoneCenter"></div>
    <div class="zone right" id="zoneRight"></div>
  </div>

  <div class="reader-bottombar">
    <div class="container-fluid d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-2">
        <a id="prevChapterBtn" href="<?php echo $prevId!==null? '/reading.php?novel_id='.(int)$novel_id.'&chapter_id='.(int)$prevId : 'javascript:void(0)'; ?>" class="btn btn-sm btn-outline-light <?php echo $prevId===null? 'disabled':''; ?>">上一章</a>
      </div>
      <div class="progress-wrap">
        <input type="range" id="progressRange" min="0" max="0" value="0">
        <span id="pageIndicator" class="text-white-50 small">1/1</span>
      </div>
      <div class="d-flex align-items-center gap-2">
        <a id="nextChapterBtn" href="<?php echo $nextId!==null? '/reading.php?novel_id='.(int)$novel_id.'&chapter_id='.(int)$nextId : 'javascript:void(0)'; ?>" class="btn btn-sm btn-outline-light <?php echo $nextId===null? 'disabled':''; ?>">下一章</a>
      </div>
    </div>
  </div>

  <div id="settingsPanel" class="settings-panel">
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
        <div class="col-12 col-md-6 d-flex align-items-end justify-content-end gap-2 mt-2 mt-md-0">
          <button id="btnAddBookmark" class="btn btn-sm btn-outline-light">添加书签</button>
          <button id="btnCloseSettings" class="btn btn-sm btn-light text-dark">关闭</button>
        </div>
      </div>
    </div>
  </div>

  <div id="bookmarksPanel" class="bookmarks-panel">
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
</div>

<script>
(function(){
  const appEl = document.getElementById('readerApp');
  const viewport = document.getElementById('readerViewport');
  const content = document.getElementById('readerContent');
  const progressRange = document.getElementById('progressRange');
  const pageIndicator = document.getElementById('pageIndicator');
  const chapterSelect = document.getElementById('chapterSelect');
  const btnSettings = document.getElementById('btnSettings');
  const btnBookmarks = document.getElementById('btnBookmarks');
  const settingsPanel = document.getElementById('settingsPanel');
  const bookmarksPanel = document.getElementById('bookmarksPanel');
  const fontSizeSelect = document.getElementById('fontSizeSelect');
  const fontFamilySelect = document.getElementById('fontFamilySelect');
  const lineHeightSelect = document.getElementById('lineHeightSelect');
  const paraSpaceSelect = document.getElementById('paraSpaceSelect');
  const widthModeSelect = document.getElementById('widthModeSelect');
  const btnAddBookmark = document.getElementById('btnAddBookmark');

  const zoneLeft = document.getElementById('zoneLeft');
  const zoneRight = document.getElementById('zoneRight');
  const zoneCenter = document.getElementById('zoneCenter');

  const novelId = Number(viewport.dataset.novelId || '0');
  let chapterId = Number(viewport.dataset.chapterId || '0');
  let currentPage = Number(viewport.dataset.initialPage || '0');
  let totalPages = 1;
  let hideTimer = null;

  // helpers
  function setVH() {
    const vh = window.innerHeight * 0.01;
    document.documentElement.style.setProperty('--vh', `${vh}px`);
  }
  setVH(); window.addEventListener('resize', setVH);

  function showControls(temp=true){
    document.body.classList.add('controls-visible');
    if (hideTimer) clearTimeout(hideTimer);
    if (temp) hideTimer = setTimeout(()=>document.body.classList.remove('controls-visible'), 2500);
  }

  function applySettings(s){
    if (s.fontSize) document.documentElement.style.setProperty('--reader-font-size', s.fontSize + 'px');
    if (s.lineHeight) document.documentElement.style.setProperty('--reader-line-height', s.lineHeight);
    if (s.paraSpace) document.documentElement.style.setProperty('--reader-para-space', s.paraSpace + 'em');
    if (s.fontFamily) {
      let ff = `system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'Noto Sans SC', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif`;
      if (s.fontFamily === 'serif') ff = `'Noto Serif SC','Source Han Serif SC','Source Han Serif CN','Songti SC','SimSun',serif`;
      if (s.fontFamily === 'kaiti') ff = `'STKaiti','KaiTi','Kaiti SC','DFKai-SB',serif`;
      if (s.fontFamily === 'heiti') ff = `'Heiti SC','SimHei','PingFang SC','Microsoft YaHei','Arial',sans-serif`;
      document.documentElement.style.setProperty('--reader-font-family', ff);
    }
    if (s.theme) {
      document.body.classList.remove('theme-day','theme-night','theme-eye');
      document.body.classList.add('theme-' + s.theme);
    }
    if (s.widthMode) {
      viewport.classList.remove('width-narrow','width-standard','width-wide');
      viewport.classList.add('width-' + s.widthMode);
    }
  }

  function saveSettings(s){
    localStorage.setItem('reader:settings', JSON.stringify(s));
  }
  function loadSettings(){
    try { return JSON.parse(localStorage.getItem('reader:settings')||'{}') } catch(e){ return {}; }
  }

  // init settings UI
  const settings = Object.assign({fontSize:18, fontFamily:'system', lineHeight:1.5, paraSpace:0.9, theme:'day', widthMode:'standard'}, loadSettings());
  applySettings(settings);
  fontSizeSelect.value = String(settings.fontSize);
  fontFamilySelect.value = settings.fontFamily;
  lineHeightSelect.value = String(settings.lineHeight);
  paraSpaceSelect.value = String(settings.paraSpace);
  widthModeSelect.value = settings.widthMode;

  fontSizeSelect.addEventListener('change', ()=>{ settings.fontSize=Number(fontSizeSelect.value); applySettings(settings); saveSettings(settings); reflow(); });
  fontFamilySelect.addEventListener('change', ()=>{ settings.fontFamily=fontFamilySelect.value; applySettings(settings); saveSettings(settings); reflow(); });
  lineHeightSelect.addEventListener('change', ()=>{ settings.lineHeight=Number(lineHeightSelect.value); applySettings(settings); saveSettings(settings); reflow(); });
  paraSpaceSelect.addEventListener('change', ()=>{ settings.paraSpace=Number(paraSpaceSelect.value); applySettings(settings); saveSettings(settings); reflow(); });
  widthModeSelect.addEventListener('change', ()=>{ settings.widthMode=widthModeSelect.value; applySettings(settings); saveSettings(settings); reflow(); });
  settingsPanel.querySelectorAll('button[data-theme]').forEach(btn=>{
    btn.addEventListener('click', ()=>{ settings.theme = btn.dataset.theme; applySettings(settings); saveSettings(settings); });
  });

  // pagination
  function reflow(){
    // Calculate columns based on viewport width and height
    const width = viewport.clientWidth;
    const height = viewport.clientHeight;
    content.style.height = height + 'px';
    content.style.columnWidth = width + 'px';
    content.style.columnGap = '0px';
    // total pages
    totalPages = Math.max(1, Math.ceil(content.scrollWidth / width));
    progressRange.max = String(totalPages - 1);
    goToPage(Math.min(currentPage, totalPages-1), false);
  }

  function goToPage(p, smooth=true){
    p = Math.max(0, Math.min(p, totalPages-1));
    currentPage = p;
    viewport.scrollTo({ left: p * viewport.clientWidth, top: 0, behavior: smooth ? 'smooth' : 'auto' });
    progressRange.value = String(p);
    pageIndicator.textContent = (p+1) + '/' + totalPages;
    debounceSaveProgress();
  }

  progressRange.addEventListener('input', ()=>{ goToPage(Number(progressRange.value)); });

  function nextPage(){
    if (currentPage < totalPages - 1) {
      goToPage(currentPage + 1);
    } else {
      const nextUrl = viewport.dataset.nextUrl;
      if (nextUrl) window.location.assign(nextUrl);
    }
  }
  function prevPage(){
    if (currentPage > 0) {
      goToPage(currentPage - 1);
    } else {
      const prevUrl = viewport.dataset.prevUrl;
      if (prevUrl) window.location.assign(prevUrl);
    }
  }

  // keyboard
  document.addEventListener('keydown', (e)=>{
    if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') { e.preventDefault(); nextPage(); }
    if (e.key === 'ArrowLeft' || e.key === 'PageUp') { e.preventDefault(); prevPage(); }
    if (e.key === 'Escape') { e.preventDefault(); if (document.body.classList.contains('controls-visible')) { document.body.classList.remove('controls-visible'); } else { history.length>1?history.back():window.location.assign('/'); } }
  });

  // touch gestures
  let touchX = 0, touchY = 0;
  viewport.addEventListener('touchstart', (e)=>{ if (!e.touches.length) return; touchX = e.touches[0].clientX; touchY = e.touches[0].clientY; }, {passive:true});
  viewport.addEventListener('touchend', (e)=>{
    const dx = (e.changedTouches[0]?.clientX || 0) - touchX;
    const dy = (e.changedTouches[0]?.clientY || 0) - touchY;
    if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
      if (dx < 0) nextPage(); else prevPage();
    } else {
      showControls();
    }
  });

  // tap zones (desktop + mobile)
  zoneLeft.addEventListener('click', prevPage);
  zoneRight.addEventListener('click', nextPage);
  zoneCenter.addEventListener('click', ()=>{ showControls(); });

  // mouse move shows controls
  document.addEventListener('mousemove', ()=> showControls());

  // settings / bookmarks panels
  btnSettings.addEventListener('click', ()=>{ settingsPanel.classList.toggle('show'); bookmarksPanel.classList.remove('show'); showControls(false); });
  document.getElementById('btnCloseSettings').addEventListener('click', ()=> settingsPanel.classList.remove('show'));
  btnBookmarks.addEventListener('click', ()=>{ bookmarksPanel.classList.toggle('show'); settingsPanel.classList.remove('show'); showControls(false); loadBookmarks(); });
  document.getElementById('btnCloseBookmarks').addEventListener('click', ()=> bookmarksPanel.classList.remove('show'));

  // chapter select
  chapterSelect.addEventListener('change', ()=>{
    const cid = chapterSelect.value;
    const url = `/reading.php?novel_id=${novelId}&chapter_id=${encodeURIComponent(cid)}`;
    window.location.assign(url);
  });

  // progress save
  let saveTimer = null;
  function debounceSaveProgress(){
    if (!document.body.dataset.loggedIn) return; // optional hint, not set for now
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(saveProgress, 400);
  }

  function saveProgress(){
    fetch(`/reading.php?action=save_progress&novel_id=${novelId}`, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ chapter_id: chapterId, page: currentPage })
    }).catch(()=>{});
    // also persist for guests/local
    localStorage.setItem(`reader:prog:${novelId}`, JSON.stringify({chapter_id: chapterId, page: currentPage}));
  }

  // bookmarks
  function loadBookmarks(){
    fetch(`/reading.php?action=list_bookmarks&novel_id=${novelId}`)
      .then(r=>r.json()).then(data=>{
        const ul = document.getElementById('bookmarksList');
        ul.innerHTML = '';
        if (!data || !data.bookmarks || !data.bookmarks.length) {
          ul.innerHTML = '<li class="list-group-item text-muted bg-transparent">暂无书签</li>';
          return;
        }
        data.bookmarks.sort((a,b)=> new Date(b.created_at) - new Date(a.created_at));
        for (const b of data.bookmarks) {
          const li = document.createElement('li');
          li.className = 'list-group-item bg-transparent d-flex justify-content-between align-items-center';
          const txt = document.createElement('div');
          txt.innerHTML = `<div class="small">第${b.chapter_id}章 · 第${(b.page||0)+1}页</div><div class="text-white-50 small">${b.note?b.note:''} <span class="ms-2">${b.created_at?new Date(b.created_at).toLocaleString():''}</span></div>`;
          const ops = document.createElement('div');
          const go = document.createElement('button'); go.className='btn btn-sm btn-outline-light me-2'; go.textContent='跳转';
          go.addEventListener('click', ()=>{ window.location.assign(`/reading.php?novel_id=${novelId}&chapter_id=${b.chapter_id}&page=${b.page||0}`); });
          const del = document.createElement('button'); del.className='btn btn-sm btn-outline-danger'; del.textContent='删除';
          del.addEventListener('click', ()=>{ fetch(`/reading.php?action=delete_bookmark&novel_id=${novelId}`, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`id=${encodeURIComponent(b.id)}`}).then(()=>loadBookmarks()); });
          ops.appendChild(go); ops.appendChild(del);
          li.appendChild(txt); li.appendChild(ops); ul.appendChild(li);
        }
      }).catch(()=>{});
  }

  btnAddBookmark.addEventListener('click', ()=>{
    const note = '';
    fetch(`/reading.php?action=add_bookmark&novel_id=${novelId}`, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ chapter_id: chapterId, page: currentPage, note }) })
      .then(()=> loadBookmarks());
  });

  // init
  reflow();
  if (currentPage > 0) goToPage(currentPage, false);
  window.addEventListener('resize', reflow);

  // Auto-save before unload
  window.addEventListener('beforeunload', saveProgress);

  // Auto-resume for guests using localStorage if no server progress
  if (!<?php echo current_user() ? 'true' : 'false'; ?>) {
    try {
      const prog = JSON.parse(localStorage.getItem(`reader:prog:${novelId}`)||'null');
      if (prog && prog.chapter_id === chapterId) { currentPage = prog.page||0; goToPage(currentPage, false); }
    } catch(e){}
  }
})();
</script>
</body>
</html>
