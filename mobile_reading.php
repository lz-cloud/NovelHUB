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

function is_mobile_device(): bool {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    return (bool)preg_match('/(iphone|ipod|ipad|android|mobile|blackberry|nokia|opera mini|windows phone)/i', $ua);
}

$novel_id = (int)($_GET['novel_id'] ?? 0);
$chapter_id = (int)($_GET['chapter_id'] ?? 0);
$initial_page = (int)($_GET['page'] ?? 0);

$novel = $novel_id ? find_novel($novel_id) : null;
if (!$novel) { http_response_code(404); echo '小说不存在'; exit; }

$chapters = list_chapters($novel_id, 'published');
$chapterMap = [];
foreach ($chapters as $c) { $chapterMap[(int)$c['id']] = $c; }
$chapter = $chapterMap[$chapter_id] ?? null;
if (!$chapter) {
    if ($chapters) { $chapter = $chapters[0]; $chapter_id = (int)$chapter['id']; }
    else { echo '暂无发布章节'; exit; }
}

// navigation
$chapterIds = array_map(function($c){ return (int)$c['id']; }, $chapters);
$currentIndex = array_search($chapter_id, $chapterIds, true);
$prevId = $currentIndex !== false && $currentIndex > 0 ? $chapterIds[$currentIndex - 1] : null;
$nextId = $currentIndex !== false && $currentIndex < count($chapterIds)-1 ? $chapterIds[$currentIndex + 1] : null;

$deviceClass = is_mobile_device() ? 'device-mobile' : 'device-desktop';

?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#f6f6f6">
  <title><?php echo e($novel['title']); ?> - <?php echo e($chapter['title']); ?> - 移动阅读</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="preload" href="/assets/js/reading-gesture.js" as="script">
  <link rel="preload" href="/assets/js/mobile_reader.js" as="script">
  <link href="/assets/css/mobile_reader.css" rel="stylesheet">
  <style>
    /* Small inline tweaks for status icons */
    .battery-icon{ width: 18px; height: 18px; display:inline-block; vertical-align:middle; margin-right:4px; border:1px solid currentColor; border-radius:3px; position:relative; }
    .battery-icon::after{ content:''; position:absolute; right:-3px; top:5px; width:3px; height:8px; border:1px solid currentColor; border-left:none; border-radius:0 2px 2px 0; }
  </style>
</head>
<body class="theme-day <?php echo $deviceClass; ?>">
<div class="reading-app controls-visible" data-novel-id="<?php echo (int)$novel_id; ?>" data-chapter-id="<?php echo (int)$chapter_id; ?>" data-initial-page="<?php echo (int)$initial_page; ?>" data-prev-url="<?php echo $prevId!==null? '/mobile_reading.php?novel_id='.(int)$novel_id.'&chapter_id='.(int)$prevId : ''; ?>" data-next-url="<?php echo $nextId!==null? '/mobile_reading.php?novel_id='.(int)$novel_id.'&chapter_id='.(int)$nextId : ''; ?>" data-chapters='<?php echo json_encode(array_map(function($c){ return ['id'=>(int)$c['id'],'title'=>$c['title']]; }, $chapters), JSON_UNESCAPED_UNICODE); ?>'>
  <!-- 顶部状态栏 -->
  <header class="reading-header">
    <div class="status-bar">
      <div><span class="time" id="current-time">--:--</span></div>
      <div class="status-right">
        <span class="battery"><span class="battery-icon"></span><span id="battery">--%</span></span>
      </div>
    </div>
    <div class="nav-bar">
      <button class="back-btn" onclick="history.length>1?history.back():window.location.assign('/')" aria-label="返回">‹</button>
      <div>
        <h1 class="chapter-title"><?php echo e($chapter['title']); ?></h1>
        <div class="progress" id="progress-percent">0%</div>
      </div>
      <button class="menu-btn" onclick="document.body.classList.toggle('controls-visible')" aria-label="菜单">⋯</button>
    </div>
  </header>

  <!-- 阅读内容区域 -->
  <main class="reading-content" id="content-area">
    <div class="page-wrapper">
      <div class="pages" id="pages">
        <div class="pages-inner page-turn" id="pagesInner">
          <div class="page" id="current-page">
            <div class="page-content">
              <div class="page-body" id="pageBody">
                <h2><?php echo e($chapter['title']); ?></h2>
<?php render_paragraphs((string)($chapter['content'] ?? '')); ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <!-- 底部控制栏 -->
  <footer class="reading-footer">
    <div class="control-bar">
      <button class="control-btn" data-action="toc" title="目录"><i>📖</i><span>目录</span></button>
      <button class="control-btn" data-action="font" title="字体"><i>🔤</i><span>字体</span></button>
      <button class="control-btn" data-action="theme" title="主题"><i>🌙</i><span>主题</span></button>
      <button class="control-btn" data-action="progress" title="进度"><i>📊</i><span>进度</span></button>
      <button class="control-btn" data-action="bookmark" title="书签"><i>🔖</i><span>书签</span></button>
    </div>
  </footer>

  <!-- 目录抽屉 -->
  <aside class="drawer" id="drawer">
    <div class="drawer-header">目录 · <?php echo e($novel['title']); ?></div>
    <div class="drawer-content" id="toc">
      <!-- filled by JS -->
    </div>
  </aside>

  <!-- 设置面板：字体 -->
  <div class="settings-panel" id="settings-panel">
    <div class="setting-section">
      <h3 style="margin:6px 6px 8px; font-size:14px; color:var(--text-secondary);">字体设置</h3>
      <div class="font-size-control" style="display:flex; gap:8px; padding: 0 6px 8px;">
        <button class="size-btn" data-size="small">小</button>
        <button class="size-btn" data-size="medium">中</button>
        <button class="size-btn" data-size="large">大</button>
        <button class="size-btn" data-size="xlarge">特大</button>
      </div>
    </div>
  </div>

  <!-- 主题面板 -->
  <div class="sheet" id="theme-panel">
    <div style="padding:6px 6px 8px; display:grid; gap:10px;">
      <div>
        <div style="font-size:14px; color:var(--text-secondary); margin-bottom:8px;">主题</div>
        <div class="theme-grid">
          <button class="theme-swatch" data-theme="day" title="日间" style="background:#fffdf7;"></button>
          <button class="theme-swatch" data-theme="night" title="夜间" style="background:#0f1115;"></button>
          <button class="theme-swatch" data-theme="eye" title="护眼" style="background:#faf4d3;"></button>
        </div>
      </div>
      <div>
        <div style="font-size:14px; color:var(--text-secondary); margin-bottom:6px;">亮度</div>
        <input id="brightness-slider" type="range" min="0" max="100" value="100" style="width:100%" />
      </div>
    </div>
  </div>

  <!-- 进度/统计面板 -->
  <div class="sheet" id="progress-panel">
    <div style="padding: 6px 8px; display:grid; gap:10px;">
      <div class="stat" id="stats-text">进度 0% · 估算速度 0字/分 · 预计剩余 0 分钟</div>
      <div>
        <div style="font-size:14px; color:var(--text-secondary); margin-bottom:6px;">跳转页面</div>
        <input id="page-slider" type="range" min="0" max="0" value="0" style="width:100%" />
      </div>
      <div>
        <div style="font-size:14px; color:var(--text-secondary); margin-bottom:6px;">自动翻页速度</div>
        <input id="speed-slider" type="range" min="0" max="5" value="0" style="width:100%" />
      </div>
      <div id="timer-buttons" style="display:flex; gap:8px;">
        <button data-min="0">不计时</button>
        <button data-min="15">15 分钟</button>
        <button data-min="30">30 分钟</button>
        <button data-min="60">60 分钟</button>
      </div>
    </div>
  </div>

  <!-- 书签面板 -->
  <div class="sheet" id="bookmarks-panel">
    <div style="padding: 6px 8px;">
      <div style="font-size:14px; color:var(--text-secondary); margin-bottom:8px;">书签</div>
      <ul id="bookmarks-list" style="list-style:none; padding:0; margin:0; display:grid; gap:10px;"></ul>
    </div>
  </div>

  <!-- 亮度遮罩 -->
  <div id="brightness-overlay" class="brightness-overlay"></div>

  <!-- 手势区域：可视化关闭，仅用于占位 -->
  <div class="tap-zones" aria-hidden="true">
    <div class="zone left"></div>
    <div class="zone center"></div>
    <div class="zone right"></div>
  </div>
</div>

<script src="/assets/js/reading-gesture.js"></script>
<script src="/assets/js/mobile_reader.js"></script>
</body>
</html>
