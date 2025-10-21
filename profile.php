<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Statistics.php';
require_once __DIR__ . '/lib/Notifier.php';
require_login();

global $dm;
$user = current_user();
$uid = (int)$user['id'];
$tab = $_GET['tab'] ?? 'overview';
$errors = [];

// Helpers for extra user storage (per-user JSON)
function user_extra_path(int $userId): string { return USERS_DIR . '/' . $userId . '.json'; }
function user_extra_load(int $userId): array { $f = user_extra_path($userId); if (!file_exists($f)) return ['shelf_categories'=>['默认']]; $d = json_decode(@file_get_contents($f), true); if (!is_array($d)) $d = ['shelf_categories'=>['默认']]; if (empty($d['shelf_categories'])) $d['shelf_categories']=['默认']; return $d; }
function user_extra_save(int $userId, array $data): bool { $f=user_extra_path($userId); @mkdir(dirname($f),0775,true); return (bool)file_put_contents($f, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)); }

$extra = user_extra_load($uid);

// Profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $nickname = trim($_POST['nickname'] ?? ($user['profile']['nickname'] ?? ''));
    $bio = trim($_POST['bio'] ?? ($user['profile']['bio'] ?? ''));
    $avatar = $user['profile']['avatar'] ?? null;
    if (!empty($_FILES['avatar']['name'])) {
        $tmp = handle_upload($_FILES['avatar'], AVATARS_DIR);
        if ($tmp) $avatar = $tmp; else $errors[] = '头像上传失败';
    }
    if (!$errors) {
        $dm->updateById(USERS_FILE, (int)$user['id'], ['profile'=>['nickname'=>$nickname,'bio'=>$bio,'avatar'=>$avatar]]);
        update_user_session((int)$user['id']);
        header('Location: /profile.php?tab=edit'); exit;
    }
}

// Shelf category ops
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = trim($_POST['category_name'] ?? ''); if ($name !== '') { $extra['shelf_categories'][] = $name; $extra['shelf_categories'] = array_values(array_unique($extra['shelf_categories'])); user_extra_save($uid, $extra); }
    header('Location: /profile.php?tab=bookshelf'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $name = trim($_POST['category'] ?? '');
    $extra['shelf_categories'] = array_values(array_filter($extra['shelf_categories'], fn($c)=> $c !== $name && $c !== '默认'));
    user_extra_save($uid, $extra);
    header('Location: /profile.php?tab=bookshelf'); exit;
}

// Batch manage shelf
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_action'])) {
    $ids = array_map('intval', $_POST['novel_ids'] ?? []);
    $list = $dm->readJson(BOOKSHELVES_FILE, []);
    $changed = false;
    if ($_POST['batch_action'] === 'delete') {
        $before = count($list);
        $list = array_values(array_filter($list, function($r) use ($ids, $uid){ return (int)($r['user_id']??0)!==$uid || !in_array((int)($r['novel_id']??0), $ids, true); }));
        $changed = count($list) !== $before;
    } else if ($_POST['batch_action'] === 'move') {
        $target = trim($_POST['target_category'] ?? '默认');
        foreach ($list as &$r) {
            if ((int)($r['user_id']??0) === $uid && in_array((int)($r['novel_id']??0), $ids, true)) { $r['category'] = $target; $changed = true; }
        }
        unset($r);
    }
    if ($changed) $dm->writeJson(BOOKSHELVES_FILE, $list);
    header('Location: /profile.php?tab=bookshelf'); exit;
}

// Notifications
$notifier = new Notifier();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $nid = (int)($_POST['nid'] ?? 0); if ($nid>0) $notifier->markRead($uid, $nid);
    header('Location: /profile.php?tab=notifications'); exit;
}

$stats = new Statistics();
$userStats = $stats->computeUserStats($uid);
$achievements = $stats->computeAchievements($uid);
$timeline = $stats->buildUserTimeline($uid, 50);

// Bookshelf data
$rawShelf = array_values(array_filter($dm->readJson(BOOKSHELVES_FILE, []), fn($r)=> (int)($r['user_id']??0) === $uid));
$novels = load_novels(); $novelMap=[]; foreach ($novels as $n) $novelMap[(int)$n['id']]=$n;
$progress = $dm->readJson(READING_PROGRESS_FILE, []);
$progMap = [];
foreach ($progress as $p) if ((int)($p['user_id']??0) === $uid) $progMap[(int)($p['novel_id'])] = $p;

?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>个人中心 - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
  <style>
    .stats-grid{ display:grid; grid-template-columns: repeat(3,1fr); gap:12px; }
    .stats-card{ border:none; box-shadow:0 2px 10px rgba(0,0,0,.06); border-left:4px solid var(--nh-primary); }
    .timeline{ list-style:none; padding-left:0; }
    .timeline li{ position:relative; padding-left:20px; margin-bottom:8px; }
    .timeline li::before{ content:''; position:absolute; left:6px; top:6px; width:6px; height:6px; background:var(--nh-primary); border-radius:50%; }
    @media (max-width: 768px){ .stats-grid{ grid-template-columns: repeat(2,1fr);} }
  </style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1>个人中心</h1>
    <div>
      <a class="btn btn-secondary" href="/">首页</a>
      <a class="btn btn-secondary" href="/dashboard.php">仪表盘</a>
    </div>
  </div>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?php if($tab==='overview') echo 'active'; ?>" href="/profile.php?tab=overview">总览</a></li>
    <li class="nav-item"><a class="nav-link <?php if($tab==='achievements') echo 'active'; ?>" href="/profile.php?tab=achievements">成就</a></li>
    <li class="nav-item"><a class="nav-link <?php if($tab==='timeline') echo 'active'; ?>" href="/profile.php?tab=timeline">动态</a></li>
    <li class="nav-item"><a class="nav-link <?php if($tab==='bookshelf') echo 'active'; ?>" href="/profile.php?tab=bookshelf">书架</a></li>
    <li class="nav-item"><a class="nav-link <?php if($tab==='notifications') echo 'active'; ?>" href="/profile.php?tab=notifications">通知</a></li>
    <li class="nav-item"><a class="nav-link <?php if($tab==='edit') echo 'active'; ?>" href="/profile.php?tab=edit">编辑资料</a></li>
  </ul>

  <?php if ($tab==='overview'): ?>
    <div class="row g-3 mb-3">
      <div class="col-12 col-md-4">
        <div class="card stats-card"><div class="card-body"><div class="text-muted">创作：作品数</div><div class="h3 mb-0"><?php echo (int)$userStats['works_count']; ?></div></div></div>
      </div>
      <div class="col-6 col-md-4">
        <div class="card stats-card"><div class="card-body"><div class="text-muted">创作：总字数</div><div class="h3 mb-0"><?php echo number_format((int)$userStats['total_words']); ?></div></div></div>
      </div>
      <div class="col-6 col-md-4">
        <div class="card stats-card"><div class="card-body"><div class="text-muted">创作：总章节数</div><div class="h3 mb-0"><?php echo (int)$userStats['total_chapters']; ?></div></div></div>
      </div>
      <div class="col-6 col-md-4">
        <div class="card stats-card"><div class="card-body"><div class="text-muted">阅读：时长(估)</div><div class="h3 mb-0"><?php echo (int)$userStats['reading_minutes']; ?> 分钟</div></div></div>
      </div>
      <div class="col-6 col-md-4">
        <div class="card stats-card"><div class="card-body"><div class="text-muted">阅读：读完书籍</div><div class="h3 mb-0"><?php echo (int)$userStats['finished_books']; ?></div></div></div>
      </div>
      <div class="col-6 col-md-4">
        <div class="card stats-card"><div class="card-body"><div class="text-muted">阅读：书架数量</div><div class="h3 mb-0"><?php echo (int)$userStats['shelf_books']; ?></div></div></div>
      </div>
      <div class="col-6 col-md-4">
        <div class="card stats-card"><div class="card-body"><div class="text-muted">互动：获得收藏</div><div class="h3 mb-0"><?php echo (int)$userStats['favorites_received']; ?></div></div></div>
      </div>
      <div class="col-6 col-md-4">
        <div class="card stats-card"><div class="card-body"><div class="text-muted">互动：评论数</div><div class="h3 mb-0"><?php echo (int)$userStats['comments_received']; ?></div></div></div>
      </div>
    </div>
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">最近动态</h5>
        <ul class="timeline mb-0">
          <?php foreach ($timeline as $ev): ?>
          <li><span class="badge text-bg-light me-2"><?php echo e($ev['type']); ?></span> <?php echo e($ev['desc']); ?> <span class="text-muted ms-2 small"><?php echo e($ev['created_at']); ?></span></li>
          <?php endforeach; if (!$timeline) echo '<li class="text-muted">暂无动态</li>'; ?>
        </ul>
      </div>
    </div>
  <?php elseif ($tab==='achievements'): ?>
    <div class="row g-3">
      <?php foreach ($achievements as $a): ?>
        <div class="col-6 col-md-4 col-lg-3">
          <div class="card <?php echo $a['achieved']? 'border-success' : 'border-secondary'; ?>">
            <div class="card-body text-center">
              <div class="h1">🏆</div>
              <div class="fw-bold"><?php echo e($a['name']); ?></div>
              <div class="text-muted small"><?php echo $a['achieved']? '已达成' : '未达成'; ?></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php elseif ($tab==='timeline'): ?>
    <div class="card"><div class="card-body"><h5 class="card-title">动态时间轴</h5>
      <ul class="timeline mb-0">
        <?php foreach ($timeline as $ev): ?>
        <li><span class="badge text-bg-light me-2"><?php echo e($ev['type']); ?></span> <?php echo e($ev['desc']); ?> <span class="text-muted ms-2 small"><?php echo e($ev['created_at']); ?></span></li>
        <?php endforeach; if (!$timeline) echo '<li class="text-muted">暂无动态</li>'; ?>
      </ul>
    </div></div>
  <?php elseif ($tab==='bookshelf'): ?>
    <div class="row g-3">
      <div class="col-12 col-lg-3">
        <div class="card"><div class="card-body">
          <h6>分类管理</h6>
          <ul class="list-group mb-2">
            <?php foreach ($extra['shelf_categories'] as $c): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><?php echo e($c); ?></span>
                <?php if ($c!=='默认'): ?>
                <form method="post" class="mb-0">
                  <input type="hidden" name="delete_category" value="1">
                  <input type="hidden" name="category" value="<?php echo e($c); ?>">
                  <button class="btn btn-sm btn-outline-danger">删除</button>
                </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
          <form method="post">
            <div class="input-group">
              <input class="form-control" name="category_name" placeholder="新分类名">
              <button class="btn btn-primary" name="add_category" value="1">添加</button>
            </div>
          </form>
        </div></div>
      </div>
      <div class="col-12 col-lg-9">
        <form method="post">
          <div class="card mb-2"><div class="card-body d-flex align-items-center gap-2">
            <select class="form-select form-select-sm" name="batch_action" style="max-width:140px">
              <option value="move">移动到</option>
              <option value="delete">批量删除</option>
            </select>
            <select class="form-select form-select-sm" name="target_category" style="max-width:180px">
              <?php foreach ($extra['shelf_categories'] as $c): ?><option value="<?php echo e($c); ?>"><?php echo e($c); ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary">应用</button>
          </div></div>
          <div class="list-group">
          <?php foreach ($rawShelf as $e): $n = $novelMap[(int)$e['novel_id']] ?? null; if(!$n) continue; $chs=list_chapters((int)$n['id'],'published'); $progressRow=$progMap[(int)$n['id']]??null; $pct=0; if($chs){ $idx=0; foreach($chs as $i=>$c){ if((int)$c['id']===(int)($progressRow['chapter_id']??0)){ $idx=$i+1; break; } } $pct = (int)round(($idx/max(1,count($chs)))*100); } ?>
            <label class="list-group-item">
              <div class="d-flex justify-content-between align-items-center">
                <div class="form-check">
                  <input class="form-check-input me-2" type="checkbox" name="novel_ids[]" value="<?php echo (int)$n['id']; ?>">
                  <span class="fw-bold"><?php echo e($n['title']); ?></span>
                  <span class="text-muted ms-2">分类：<?php echo e($e['category'] ?? '默认'); ?></span>
                </div>
                <div class="text-end" style="min-width:160px;">
                  <div class="progress" style="height:8px;">
                    <div class="progress-bar" role="progressbar" style="width: <?php echo $pct; ?>%;"></div>
                  </div>
                  <small class="text-muted">阅读进度：<?php echo $pct; ?>%</small>
                </div>
                <div class="ms-2">
                  <?php if ($chs): $first=$chs[0]; ?><a class="btn btn-sm btn-outline-primary" href="/reading.php?novel_id=<?php echo (int)$n['id']; ?>&chapter_id=<?php echo (int)$first['id']; ?>">继续阅读</a><?php endif; ?>
                </div>
              </div>
            </label>
          <?php endforeach; if (!$rawShelf) echo '<div class="text-muted p-3">书架为空</div>'; ?>
          </div>
        </form>
      </div>
    </div>
  <?php elseif ($tab==='notifications'): ?>
    <div class="card"><div class="card-body">
      <h5 class="card-title">消息通知</h5>
      <?php $notes = $notifier->fetch($uid, false, 100); ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($notes as $n): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
              <span class="badge rounded-pill <?php echo $n['type']==='system'?'text-bg-secondary':($n['type']==='interaction'?'text-bg-primary':'text-bg-success'); ?>"><?php echo e($n['type']); ?></span>
              <strong class="ms-2"><?php echo e($n['title']); ?></strong>
              <div class="text-muted small"><?php echo e($n['message']); ?> <?php if (!empty($n['link'])): ?><a href="<?php echo e($n['link']); ?>">查看</a><?php endif; ?></div>
            </div>
            <div class="text-end">
              <div class="text-muted small"><?php echo e($n['created_at']); ?></div>
              <?php if (empty($n['read'])): ?><form method="post" class="mt-1"><input type="hidden" name="mark_read" value="1"><input type="hidden" name="nid" value="<?php echo (int)$n['id']; ?>"><button class="btn btn-sm btn-outline-secondary">标为已读</button></form><?php else: ?><span class="badge text-bg-light">已读</span><?php endif; ?>
            </div>
          </li>
        <?php endforeach; if (!$notes) echo '<li class="list-group-item text-muted">暂无通知</li>'; ?>
      </ul>
    </div></div>
  <?php elseif ($tab==='edit'): ?>
    <div class="row">
      <div class="col-12 col-md-8 col-lg-6">
        <div class="card"><div class="card-body">
          <h5 class="card-title">编辑个人资料</h5>
          <?php if ($errors): ?><div class="alert alert-danger"><?php echo e(implode('；',$errors)); ?></div><?php endif; ?>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="update_profile" value="1">
            <div class="mb-3">
              <label class="form-label">头像</label><br>
              <?php if (!empty($user['profile']['avatar'])): ?>
                <img src="/uploads/avatars/<?php echo e($user['profile']['avatar']); ?>" style="width:96px;height:96px;object-fit:cover;" class="rounded mb-2" alt="avatar">
              <?php endif; ?>
              <input type="file" class="form-control" name="avatar" accept="image/*">
            </div>
            <div class="mb-3">
              <label class="form-label">昵称</label>
              <input class="form-control" name="nickname" value="<?php echo e($user['profile']['nickname'] ?? ''); ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">个人简介</label>
              <textarea class="form-control" name="bio" rows="4"><?php echo e($user['profile']['bio'] ?? ''); ?></textarea>
            </div>
            <button class="btn btn-primary">保存</button>
          </form>
        </div></div>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
