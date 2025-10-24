<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Statistics.php';
require_once __DIR__ . '/lib/Review.php';
require_once __DIR__ . '/lib/Export.php';
require_once __DIR__ . '/lib/Notifier.php';
require_once __DIR__ . '/lib/Membership.php';

global $dm;

$novel_id = (int)($_GET['novel_id'] ?? 0);
$action = $_GET['action'] ?? '';
$novel = $novel_id ? find_novel($novel_id) : null;
if (!$novel) { http_response_code(404); echo '作品不存在'; exit; }

$stats = new Statistics();
$reviewSvc = new ReviewService();
$exporter = new Exporter();
$notifier = new Notifier();
$downloadMgr = new DownloadManager();

// Handle downloads
if ($action === 'download') {
    require_login();
    $user = current_user();
    $userId = (int)$user['id'];
    
    // Check download permission
    $downloadCheck = $downloadMgr->canDownload($userId);
    if (!$downloadCheck['allowed']) {
        http_response_code(403);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>下载限制</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body>';
        echo '<div class="container py-5"><div class="alert alert-warning">';
        echo '<h4 class="alert-heading">下载次数已达上限</h4>';
        echo '<p>你今天的下载次数已达到上限（' . ($downloadCheck['limit'] ?? 3) . '次）。</p>';
        echo '<hr><p class="mb-0">升级为 Plus 会员即可享受无限下载！<a href="/plans.php" class="alert-link">立即查看会员计划</a></p>';
        echo '</div><a href="/novel_detail.php?novel_id=' . $novel_id . '" class="btn btn-primary">返回作品详情</a></div></body></html>';
        exit;
    }
    
    $format = strtolower(trim($_GET['format'] ?? ''));
    $file = null;
    if ($format === 'txt') $file = $exporter->exportTXT($novel_id);
    if ($format === 'epub') $file = $exporter->exportEPUB($novel_id);
    if ($format === 'pdf') $file = $exporter->exportPDF($novel_id);
    if ($file && file_exists($file)) {
        // Record download
        $downloadMgr->recordDownload($userId, $novel_id, $format);
        
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($file).'"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    } else {
        http_response_code(500); echo '文件导出失败（可能服务器没有必要的扩展）'; exit;
    }
}

$errors = [];
// Handle review post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    require_login();
    $rating = (int)($_POST['rating'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    if ($rating <= 0 || $rating > 5) $errors[] = '请选择有效的评分（1-5星）';
    if ($content === '') $errors[] = '请填写评论内容';
    if (!$errors) {
        $rid = $reviewSvc->add($novel_id, (int)current_user()['id'], $rating, nl2br(e($content)));
        // notify author
        $notifier->notify((int)$novel['author_id'], 'interaction', '收到新的评分与评论', '你的作品《'.($novel['title'] ?? '').'》收到一条新评论。', '/novel_detail.php?novel_id='.$novel_id.'#reviews');
        header('Location: /novel_detail.php?novel_id='.$novel_id.'#reviews');
        exit;
    }
}

// like review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['like_review'])) {
    $rid = (int)($_POST['review_id'] ?? 0);
    if ($rid > 0) { $reviewSvc->like($novel_id, $rid); }
    header('Location: /novel_detail.php?novel_id='.$novel_id.'#reviews');
    exit;
}

$novelStats = $stats->computeNovelStats($novel_id);
$reviews = $reviewSvc->list($novel_id);
$chapters = list_chapters($novel_id, 'published');
$categories = json_decode(file_get_contents(CATEGORIES_FILE), true) ?: [];
$catMap = []; foreach ($categories as $c) { $catMap[(int)$c['id']] = $c['name']; }

// Recommendations
$allNovels = load_novels();
$alsoLike = array_values(array_filter($allNovels, function($n) use ($novel){
    if ((int)($n['id']??0) === (int)$novel['id']) return false;
    return array_intersect(array_map('intval',$novel['category_ids']??[]), array_map('intval',$n['category_ids']??[]));
}));
usort($alsoLike, function($a,$b){ return strtotime($b['updated_at']??'now') <=> strtotime($a['updated_at']??'now'); });
$alsoLike = array_slice($alsoLike, 0, 6);
$sameAuthor = array_values(array_filter($allNovels, fn($n)=> (int)($n['author_id']??0) === (int)$novel['author_id'] && (int)($n['id']??0)!==(int)$novel['id']));
$hotInCategory = array_values(array_filter($allNovels, function($n) use ($novel){ return array_intersect(array_map('intval',$novel['category_ids']??[]), array_map('intval',$n['category_ids']??[])); }));
usort($hotInCategory, function($a,$b){ $av = (int)($a['stats']['views']??0); $bv = (int)($b['stats']['views']??0); return $bv <=> $av; });
$hotInCategory = array_slice($hotInCategory, 0, 6);

?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo e($novel['title']); ?> - 书籍详情 - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
  <style>
    .zlib-layout{display:grid; grid-template-columns: 240px 1fr; gap:20px;}
    .zlib-cover{width:100%; height:auto; border-radius:8px; box-shadow:0 6px 18px rgba(0,0,0,.1);} 
    .zlib-meta .title{font-size:1.6rem; font-weight:700;}
    .zlib-badges .badge{margin-right:6px;}
    .rating-stars input{ display:none; }
    .rating-stars label{ font-size:24px; color:#ccc; cursor:pointer; }
    .rating-stars input:checked ~ label, .rating-stars label:hover, .rating-stars label:hover ~ label{ color:#ffb400; }
    @media (max-width: 768px){ .zlib-layout{ grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<div class="container py-4">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="/">首页</a></li>
      <li class="breadcrumb-item active" aria-current="page">书籍详情</li>
    </ol>
  </nav>
  <div class="zlib-layout">
    <div>
      <?php if (!empty($novel['cover_image'])): ?>
        <img src="/uploads/covers/<?php echo e($novel['cover_image']); ?>" class="zlib-cover" alt="cover" loading="lazy">
      <?php else: ?>
        <div class="bg-secondary bg-opacity-25 rounded" style="width:100%;height:320px;"></div>
      <?php endif; ?>
      <div class="mt-3 d-grid gap-2">
        <?php if ($chapters): $first=$chapters[0]; ?>
          <a class="btn btn-primary" href="/reading.php?novel_id=<?php echo (int)$novel_id; ?>&chapter_id=<?php echo (int)$first['id']; ?>">立即阅读</a>
        <?php else: ?>
          <button class="btn btn-secondary" disabled>尚无可读章节</button>
        <?php endif; ?>
        <a class="btn btn-outline-primary" href="/shelf.php?action=add&novel_id=<?php echo (int)$novel_id; ?>">加入书架</a>
      </div>
      <div class="card mt-3">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">下载选项</h6>
            <a class="btn btn-sm btn-outline-primary" href="/plans.php">会员计划</a>
          </div>
          <?php if (!current_user()): ?>
            <div class="alert alert-light border small text-muted mb-3">登录后可下载并记录次数。升级 Plus 会员可享无限下载。</div>
          <?php else: ?>
            <?php $viewer = current_user(); $membershipSvc = new Membership(); $isPlusViewer = $membershipSvc->isPlusUser((int)$viewer['id']); ?>
            <?php if ($isPlusViewer): $viewerMembership = $membershipSvc->getUserMembership((int)$viewer['id']); ?>
              <div class="alert alert-success small mb-3">Plus 会员 · 无限下载<?php if ($viewerMembership) echo ' · 到期：' . e(date('Y-m-d H:i', strtotime($viewerMembership['expires_at']))); ?></div>
            <?php else: $downloadStatus = $downloadMgr->canDownload((int)$viewer['id']); ?>
              <div class="alert alert-light border small text-muted mb-3">今日已下载 <?php echo (int)($downloadStatus['count'] ?? 0); ?> / <?php echo (int)($downloadStatus['limit'] ?? DownloadManager::DAILY_LIMIT); ?> 次。升级 Plus 即享无限下载。</div>
            <?php endif; ?>
          <?php endif; ?>
          <div class="btn-group">
            <a class="btn btn-sm btn-outline-secondary" href="/novel_detail.php?action=download&format=txt&novel_id=<?php echo (int)$novel_id; ?>">TXT</a>
            <a class="btn btn-sm btn-outline-secondary" href="/novel_detail.php?action=download&format=epub&novel_id=<?php echo (int)$novel_id; ?>">EPUB</a>
            <a class="btn btn-sm btn-outline-secondary" href="/novel_detail.php?action=download&format=pdf&novel_id=<?php echo (int)$novel_id; ?>">PDF</a>
          </div>
        </div>
      </div>
    </div>
    <div class="zlib-meta">
      <div class="title mb-2"><?php echo e($novel['title']); ?></div>
      <div class="text-muted mb-1">作者：<?php echo e(get_user_display_name((int)$novel['author_id'])); ?></div>
      <div class="text-muted mb-2">评分：<?php echo number_format((float)$novelStats['rating_avg'], 1); ?>/5 · 共<?php echo (int)$novelStats['rating_count']; ?>人评分</div>
      <div class="zlib-badges mb-2">
        <?php foreach (($novel['category_ids'] ?? []) as $cid): ?><span class="badge text-bg-light"><?php echo e($catMap[(int)$cid] ?? '分类'); ?></span><?php endforeach; ?>
        <?php foreach (($novel['tags'] ?? []) as $t): ?><span class="badge text-bg-secondary"><?php echo e($t); ?></span><?php endforeach; ?>
      </div>
      <div class="row text-muted mb-2">
        <div class="col-6 col-md-3">字数：<?php echo number_format((new Statistics())->computeNovelStats($novel_id)['chapters_count']); ?>章</div>
        <div class="col-6 col-md-3">状态：<?php echo $novel['status']==='completed'?'已完结':'连载中'; ?></div>
        <div class="col-6 col-md-3">阅读：<?php echo (int)$novelStats['views']; ?></div>
        <div class="col-6 col-md-3">收藏：<?php echo (int)$novelStats['favorites']; ?></div>
      </div>
      <div class="card mb-3">
        <div class="card-body">
          <h5 class="card-title">作品简介</h5>
          <div class="card-text"><?php echo nl2br(e($novel['description'] ?? '暂无简介')); ?></div>
        </div>
      </div>
      <?php 
        $author = $dm->findById(USERS_FILE, (int)$novel['author_id']);
        $authorBio = $author['profile']['bio'] ?? '';
        if ($authorBio):
      ?>
      <div class="card mb-3">
        <div class="card-body">
          <h5 class="card-title">关于作者</h5>
          <div class="d-flex align-items-start mb-2">
            <?php if (!empty($author['profile']['avatar'])): ?>
              <img src="/uploads/avatars/<?php echo e($author['profile']['avatar']); ?>" class="rounded-circle me-3" style="width:48px;height:48px;object-fit:cover;">
            <?php endif; ?>
            <div>
              <div class="fw-bold"><?php echo e(get_user_display_name((int)$novel['author_id'])); ?></div>
              <div class="text-muted small">作者</div>
            </div>
          </div>
          <div class="card-text"><?php echo nl2br(e($authorBio)); ?></div>
        </div>
      </div>
      <?php endif; ?>
      <div class="row g-3">
        <div class="col-12 col-lg-7">
          <div class="card mb-3" id="chapters">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="card-title mb-0">章节目录</h5>
                <small class="text-muted">最后更新：<?php echo e($novelStats['last_updated'] ?? ''); ?></small>
              </div>
              <?php if (!$chapters): ?>
                <div class="text-muted">暂无章节</div>
              <?php else: ?>
                <div class="accordion" id="chapterAccordion">
                  <?php foreach ($chapters as $i=>$c): ?>
                    <div class="accordion-item">
                      <h2 class="accordion-header" id="h<?php echo (int)$c['id']; ?>">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#c<?php echo (int)$c['id']; ?>">
                          第<?php echo (int)$c['id']; ?>章 · <?php echo e($c['title']); ?> <span class="ms-2 text-muted small"><?php echo e($c['updated_at'] ?? ''); ?></span>
                        </button>
                      </h2>
                      <div id="c<?php echo (int)$c['id']; ?>" class="accordion-collapse collapse" data-bs-parent="#chapterAccordion">
                        <div class="accordion-body">
                          <a class="btn btn-sm btn-primary" href="/reading.php?novel_id=<?php echo (int)$novel_id; ?>&chapter_id=<?php echo (int)$c['id']; ?>">阅读本章</a>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="card" id="reviews">
            <div class="card-body">
              <h5 class="card-title">评分与评论</h5>
              <?php if ($errors): ?><div class="alert alert-danger"><?php echo e(implode('；',$errors)); ?></div><?php endif; ?>
              <form method="post" class="mb-3">
                <input type="hidden" name="submit_review" value="1">
                <div class="mb-2">
                  <div class="rating-stars d-inline-block">
                    <?php for($i=5;$i>=1;$i--): ?>
                      <input type="radio" name="rating" id="r<?php echo $i; ?>" value="<?php echo $i; ?>">
                      <label for="r<?php echo $i; ?>">★</label>
                    <?php endfor; ?>
                  </div>
                </div>
                <div class="mb-2">
                  <textarea class="form-control" name="content" rows="4" placeholder="写下你的看法，支持简单富文本（回车换行）"></textarea>
                </div>
                <?php if (current_user()): ?>
                  <button class="btn btn-primary">提交</button>
                <?php else: ?>
                  <a href="/login.php" class="btn btn-outline-primary">登录后评论</a>
                <?php endif; ?>
              </form>
              <div class="list-group">
                <?php if (!$reviews): ?>
                  <div class="text-muted">还没有评论。来做第一个评论的人吧！</div>
                <?php else: foreach ($reviews as $r): ?>
                  <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                      <div>
                        <strong><?php echo e(get_user_display_name((int)$r['user_id'])); ?></strong>
                        <span class="ms-2 text-warning"><?php echo str_repeat('★', (int)$r['rating']); ?><span class="text-muted"><?php echo str_repeat('★', 5-(int)$r['rating']); ?></span></span>
                      </div>
                      <small class="text-muted"><?php echo e($r['created_at']); ?></small>
                    </div>
                    <div class="mt-2"><?php echo $r['content']; ?></div>
                    <div class="mt-2">
                      <form method="post" action="/novel_detail.php?novel_id=<?php echo (int)$novel_id; ?>#reviews" class="d-inline">
                        <input type="hidden" name="like_review" value="1">
                        <input type="hidden" name="review_id" value="<?php echo (int)$r['id']; ?>">
                        <button class="btn btn-sm btn-outline-secondary">有用（<?php echo (int)($r['likes'] ?? 0); ?>）</button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; endif; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="col-12 col-lg-5">
          <div class="card mb-3">
            <div class="card-body">
              <h5 class="card-title">详细信息</h5>
              <ul class="list-unstyled mb-0">
                <li>章节数量：<?php echo (int)$novelStats['chapters_count']; ?></li>
                <li>最后更新：<?php echo e($novelStats['last_updated'] ?? ''); ?></li>
                <li>推荐指数：<?php echo (int)$novelStats['recommend_score']; ?></li>
                <li>出版信息：<?php echo e($novel['publish_info']['publisher'] ?? '——'); ?></li>
                <li>文件信息：TXT/EPUB/PDF</li>
              </ul>
            </div>
          </div>
          <div class="card mb-3">
            <div class="card-body">
              <h5 class="card-title">读过这本书的人也喜欢</h5>
              <?php foreach ($alsoLike as $n): ?>
                <div class="d-flex align-items-center mb-2">
                  <?php if (!empty($n['cover_image'])): ?><img src="/uploads/covers/<?php echo e($n['cover_image']); ?>" style="width:40px;height:56px;object-fit:cover;border-radius:4px;" class="me-2" loading="lazy"><?php endif; ?>
                  <a href="/novel_detail.php?novel_id=<?php echo (int)$n['id']; ?>"><?php echo e($n['title']); ?></a>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="card mb-3">
            <div class="card-body">
              <h5 class="card-title">同作者的其他作品</h5>
              <?php foreach ($sameAuthor as $n): ?>
                <div><a href="/novel_detail.php?novel_id=<?php echo (int)$n['id']; ?>"><?php echo e($n['title']); ?></a></div>
              <?php endforeach; if (!$sameAuthor) echo '<div class="text-muted">无</div>'; ?>
            </div>
          </div>
          <div class="card mb-3">
            <div class="card-body">
              <h5 class="card-title">同分类热门</h5>
              <?php foreach ($hotInCategory as $n): ?>
                <div><a href="/novel_detail.php?novel_id=<?php echo (int)$n['id']; ?>"><?php echo e($n['title']); ?></a></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
