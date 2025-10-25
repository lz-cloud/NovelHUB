<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Statistics.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Notifier.php';
require_login();

// Require at least content admin (legacy admin is normalized)
Auth::requireRole([Auth::ROLE_SUPER_ADMIN, Auth::ROLE_CONTENT_ADMIN]);

$tab = $_GET['tab'] ?? 'overview';
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$dm = new DataManager(DATA_DIR);
$logger = new OperationLog();
$statsSvc = new Statistics();
$notifier = new Notifier();

// System settings save
if ($tab==='system' && $action==='save_settings') {
    $site_name = trim($_POST['site_name'] ?? 'NovelHub');
    $description = trim($_POST['description'] ?? '');
    $default_font = $_POST['default_font'] ?? 'system';
    $theme = $_POST['theme'] ?? 'day';
    $max_file_size = (int)($_POST['max_file_size'] ?? (5*1024*1024));
    $settings = [
        'site_name'=>$site_name,
        'logo'=>null,
        'description'=>$description,
        'reading'=>['default_font'=>$default_font,'theme'=>$theme],
        'uploads'=>['max_file_size'=>$max_file_size,'image_formats'=>['jpg','png','gif','webp']],
    ];
    file_put_contents(SYSTEM_SETTINGS_FILE, json_encode($settings, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    $logger->log('update_settings', ['site_name'=>$site_name]);
    header('Location: /admin_dashboard.php?tab=system'); exit;
}

// Content batch operations
if ($tab==='content' && $action) {
    $ids = array_map('intval', $_POST['ids'] ?? []);
    if ($action === 'batch_delete') {
        foreach ($ids as $id) { delete_novel($id); }
        $logger->log('batch_delete_novels', ['count'=>count($ids)]);
    }
    if ($action === 'batch_set_category') {
        $cat = (int)($_POST['category'] ?? 0);
        $novels = load_novels();
        foreach ($novels as &$n) { if (in_array((int)$n['id'],$ids,true)) { $cats = $n['category_ids'] ?? []; if ($cat>0 && !in_array($cat,$cats,true)) $cats[]=$cat; $n['category_ids']=array_values(array_unique(array_map('intval',$cats))); } }
        unset($n);
        $dm->writeJson(NOVELS_FILE, $novels);
        $logger->log('batch_set_category', ['count'=>count($ids),'category'=>$cat]);
    }
    header('Location: /admin_dashboard.php?tab=content'); exit;
}

// Role management
if ($tab==='users' && $action) {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId>0) {
        if ($action==='set_role') {
            $role = $_POST['role'] ?? 'user';
            $dm->updateById(USERS_FILE, $userId, ['role'=>$role]);
            $logger->log('set_role', ['user_id'=>$userId,'role'=>$role]);
            if ($userId === (int)(current_user()['id'] ?? 0)) update_user_session($userId);
        }
    }
    header('Location: /admin_dashboard.php?tab=users'); exit;
}

// Maintenance
if ($tab==='system' && $action) {
    if ($action==='clear_cache') { @array_map('unlink', glob(CACHE_DIR.'/*.cache')); $logger->log('clear_cache'); header('Location: /admin_dashboard.php?tab=system'); exit; }
    if ($action==='backup_data') {
        $backupDir = DATA_DIR.'/backups/'.date('Ymd_His'); @mkdir($backupDir,0775,true);
        // naive copy
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DATA_DIR, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $dest = $backupDir . substr($file->getPathname(), strlen(DATA_DIR));
            @mkdir(dirname($dest),0775,true);
            @copy($file->getPathname(), $dest);
        }
        $logger->log('backup_data', ['dir'=>$backupDir]);
        header('Location: /admin_dashboard.php?tab=system'); exit;
    }
}

$users = load_users();
$novels = load_novels();
$categories = json_decode(file_get_contents(CATEGORIES_FILE), true) ?: [];

// Build series data for charts
$usersByDay = [];
foreach ($users as $u) { $d = substr($u['created_at'] ?? '',0,10); if ($d) { $usersByDay[$d] = ($usersByDay[$d] ?? 0) + 1; } }
ksort($usersByDay);
$progress = $dm->readJson(READING_PROGRESS_FILE, []);
$readsByDay = [];
foreach ($progress as $p) { $d = substr($p['updated_at'] ?? '',0,10); if ($d) { $readsByDay[$d] = ($readsByDay[$d] ?? 0) + 1; } }
ksort($readsByDay);
$overview = $statsSvc->computePlatformOverview();

?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>管理仪表盘 - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1>管理仪表盘</h1>
    <div>
      <a class="btn btn-secondary" href="/">首页</a>
      <a class="btn btn-outline-danger" href="/logout.php">退出</a>
    </div>
  </div>
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?php if($tab==='overview') echo 'active'; ?>" href="/admin_dashboard.php?tab=overview">数据概览</a></li>
    <li class="nav-item"><a class="nav-link <?php if($tab==='content') echo 'active'; ?>" href="/admin_dashboard.php?tab=content">内容管理</a></li>
    <li class="nav-item"><a class="nav-link <?php if($tab==='users') echo 'active'; ?>" href="/admin_dashboard.php?tab=users">用户管理</a></li>
    <li class="nav-item"><a class="nav-link <?php if($tab==='system') echo 'active'; ?>" href="/admin_dashboard.php?tab=system">系统管理</a></li>
    <li class="nav-item"><a class="nav-link" href="/admin_membership.php">会员管理</a></li>
    <li class="nav-item"><a class="nav-link" href="/admin_settings.php">系统设置</a></li>
  </ul>

  <?php if ($tab==='overview'): ?>
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3"><div class="card"><div class="card-body"><div class="text-muted">用户数</div><div class="h3 mb-0"><?php echo (int)$overview['users_total']; ?></div></div></div></div>
      <div class="col-6 col-md-3"><div class="card"><div class="card-body"><div class="text-muted">作品数</div><div class="h3 mb-0"><?php echo (int)$overview['novels_total']; ?></div></div></div></div>
      <div class="col-6 col-md-3"><div class="card"><div class="card-body"><div class="text-muted">章节数</div><div class="h3 mb-0"><?php echo (int)$overview['chapters_total']; ?></div></div></div></div>
      <div class="col-6 col-md-3"><div class="card"><div class="card-body"><div class="text-muted">DAU</div><div class="h3 mb-0"><?php echo (int)$overview['daily_active']; ?></div></div></div></div>
    </div>
    <div class="row g-3">
      <div class="col-12 col-lg-6">
        <div class="card"><div class="card-body">
          <h5 class="card-title">用户增长</h5>
          <canvas id="userChart"></canvas>
        </div></div>
      </div>
      <div class="col-12 col-lg-6">
        <div class="card"><div class="card-body">
          <h5 class="card-title">阅读量</h5>
          <canvas id="readChart"></canvas>
        </div></div>
      </div>
    </div>
    <div class="row g-3 mt-2">
      <div class="col-12 col-lg-6"><div class="card"><div class="card-body"><h5>最新注册用户</h5>
        <ul class="list-group list-group-flush">
          <?php usort($users, fn($a,$b)=> strcmp($b['created_at']??'', $a['created_at']??'')); foreach (array_slice($users,0,10) as $u): ?>
            <li class="list-group-item d-flex justify-content-between"><span><?php echo e($u['username']); ?></span><small class="text-muted"><?php echo e($u['created_at'] ?? ''); ?></small></li>
          <?php endforeach; ?>
        </ul>
      </div></div></div>
      <div class="col-12 col-lg-6"><div class="card"><div class="card-body"><h5>最新发布作品</h5>
        <?php usort($novels, fn($a,$b)=> strcmp($b['created_at']??'', $a['created_at']??'')); ?>
        <ul class="list-group list-group-flush">
          <?php foreach (array_slice($novels,0,10) as $n): ?>
            <li class="list-group-item d-flex justify-content-between"><span><?php echo e($n['title']); ?></span><small class="text-muted"><?php echo e($n['created_at'] ?? ''); ?></small></li>
          <?php endforeach; ?>
        </ul>
      </div></div></div>
    </div>
  <?php elseif ($tab==='content'): ?>
    <?php 
      $q = trim($_GET['q'] ?? '');
      $category = (int)($_GET['category'] ?? 0);
      $status = $_GET['status'] ?? '';
      $from = $_GET['from'] ?? '';
      $to = $_GET['to'] ?? '';
      $list = $novels;
      if ($q!=='') $list = array_values(array_filter($list, fn($n)=> stripos($n['title'] ?? '', $q) !== false));
      if ($category>0) $list = array_values(array_filter($list, fn($n)=> in_array($category, array_map('intval',$n['category_ids']??[]), true)));
      if ($status==='ongoing' || $status==='completed') $list = array_values(array_filter($list, fn($n)=> ($n['status']??'') === $status));
      if ($from!=='') $list = array_values(array_filter($list, fn($n)=> strcmp(substr($n['created_at']??'',0,10), $from) >= 0));
      if ($to!=='') $list = array_values(array_filter($list, fn($n)=> strcmp(substr($n['created_at']??'',0,10), $to) <= 0));
    ?>
    <form class="row g-2 mb-2" method="get">
      <input type="hidden" name="tab" value="content">
      <div class="col-12 col-md-3"><input class="form-control" name="q" placeholder="关键词" value="<?php echo e($q); ?>"></div>
      <div class="col-6 col-md-2"><select class="form-select" name="category"><option value="0">全部分类</option><?php foreach($categories as $c):?><option value="<?php echo (int)$c['id']; ?>" <?php if($category===(int)$c['id']) echo 'selected'; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-2"><select class="form-select" name="status"><option value="">全部状态</option><option value="ongoing" <?php if($status==='ongoing') echo 'selected'; ?>>连载中</option><option value="completed" <?php if($status==='completed') echo 'selected'; ?>>已完结</option></select></div>
      <div class="col-6 col-md-2"><input type="date" class="form-control" name="from" value="<?php echo e($from); ?>"/></div>
      <div class="col-6 col-md-2"><input type="date" class="form-control" name="to" value="<?php echo e($to); ?>"/></div>
      <div class="col-12 col-md-1"><button class="btn btn-primary w-100">筛选</button></div>
    </form>
    <form method="post">
      <div class="d-flex align-items-center gap-2 mb-2">
        <select class="form-select form-select-sm" name="action" style="max-width:180px">
          <option value="batch_set_category">批量修改分类</option>
          <option value="batch_delete">批量删除作品</option>
        </select>
        <select class="form-select form-select-sm" name="category" style="max-width:200px">
          <?php foreach($categories as $c):?><option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-primary">应用</button>
      </div>
      <div class="list-group">
        <?php foreach ($list as $n): ?>
          <label class="list-group-item d-flex justify-content-between align-items-center">
            <div>
              <input class="form-check-input me-2" type="checkbox" name="ids[]" value="<?php echo (int)$n['id']; ?>">
              <strong><?php echo e($n['title']); ?></strong>
              <span class="text-muted ms-2">作者：<?php echo e(get_user_display_name((int)$n['author_id'])); ?></span>
            </div>
            <div class="d-flex align-items-center gap-2">
              <small class="text-muted">创建：<?php echo e($n['created_at']); ?></small>
              <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-primary" href="/novel_detail.php?novel_id=<?php echo (int)$n['id']; ?>">查看</a>
                <a class="btn btn-outline-success" href="/edit_novel.php?novel_id=<?php echo (int)$n['id']; ?>">编辑</a>
              </div>
            </div>
          </label>
        <?php endforeach; if (!$list) echo '<div class="text-muted p-3">无结果</div>'; ?>
      </div>
    </form>

    <?php $audit = json_decode(@file_get_contents(ADMIN_AUDIT_FILE), true) ?: []; ?>
    <div class="row g-3 mt-2">
      <div class="col-12 col-lg-6">
        <div class="card"><div class="card-body">
          <h5 class="card-title">待审核内容</h5>
          <ul class="list-group list-group-flush">
            <?php $pending = array_values(array_filter($audit, fn($a)=> ($a['status'] ?? 'pending') === 'pending')); foreach (array_slice($pending,0,10) as $a): ?>
              <li class="list-group-item small d-flex justify-content-between"><span><?php echo e($a['title'] ?? ''); ?></span><span class="text-muted"><?php echo e($a['created_at'] ?? ''); ?></span></li>
            <?php endforeach; if (!$pending) echo '<li class="list-group-item text-muted">暂无待审核</li>'; ?>
          </ul>
        </div></div>
      </div>
      <div class="col-12 col-lg-6">
        <div class="card"><div class="card-body">
          <h5 class="card-title">审核历史</h5>
          <ul class="list-group list-group-flush" style="max-height:240px;overflow:auto;">
            <?php $history = array_values(array_filter($audit, fn($a)=> ($a['status'] ?? '') !== 'pending')); foreach (array_slice($history,0,20) as $a): ?>
              <li class="list-group-item small d-flex justify-content-between"><span><?php echo e(($a['title'] ?? '').' · '.($a['status'] ?? '')); ?></span><span class="text-muted"><?php echo e($a['updated_at'] ?? $a['created_at'] ?? ''); ?></span></li>
            <?php endforeach; if (!$history) echo '<li class="list-group-item text-muted">暂无历史记录</li>'; ?>
          </ul>
        </div></div>
      </div>
    </div>
  <?php elseif ($tab==='users'): ?>
    <div class="row g-3">
      <div class="col-12 col-lg-6"><div class="card"><div class="card-body">
        <h5 class="card-title">用户行为分析（示例）</h5>
        <p class="text-muted">统计用户活跃度与偏好（基于阅读进度与作品分类分布的简要示意）。</p>
        <?php 
          $catCounts = [];
          $bookshelves = $dm->readJson(BOOKSHELVES_FILE, []);
          foreach ($bookshelves as $r) { $nid=(int)($r['novel_id']??0); $nv=$nid?find_novel($nid):null; if($nv) { foreach (($nv['category_ids']??[]) as $cid) { $catCounts[(int)$cid]=($catCounts[(int)$cid]??0)+1; } } }
        ?>
        <ul class="list-group">
          <?php foreach ($categories as $c): $cnt = (int)($catCounts[(int)$c['id']] ?? 0); ?>
            <li class="list-group-item d-flex justify-content-between align-items-center"><span><?php echo e($c['name']); ?></span><span class="badge text-bg-light"><?php echo $cnt; ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div></div></div>
      <div class="col-12 col-lg-6"><div class="card"><div class="card-body">
        <h5 class="card-title">权限管理</h5>
        <div class="table-responsive">
          <table class="table table-striped">
            <thead><tr><th>ID</th><th>用户名</th><th>角色</th><th>操作</th></tr></thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <tr>
                  <td><?php echo (int)$u['id']; ?></td>
                  <td><?php echo e($u['username']); ?></td>
                  <td><?php echo e($u['role'] ?? 'user'); ?></td>
                  <td>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="set_role">
                      <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                      <select class="form-select form-select-sm" name="role" style="width:auto;display:inline-block;">
                        <option value="user" <?php if(($u['role']??'')==='user') echo 'selected'; ?>>普通用户</option>
                        <option value="content_admin" <?php if(($u['role']??'')==='content_admin' || ($u['role']??'')==='admin') echo 'selected'; ?>>内容管理员</option>
                        <option value="super_admin" <?php if(($u['role']??'')==='super_admin') echo 'selected'; ?>>超级管理员</option>
                      </select>
                      <button class="btn btn-sm btn-primary">保存</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div></div></div>
    </div>
  <?php elseif ($tab==='system'): ?>
    <?php $settings = json_decode(@file_get_contents(SYSTEM_SETTINGS_FILE), true) ?: []; ?>
    <div class="row g-3">
      <div class="col-12 col-lg-6"><div class="card"><div class="card-body">
        <h5 class="card-title">站点配置</h5>
        <form method="post">
          <input type="hidden" name="action" value="save_settings">
          <div class="mb-2"><label class="form-label">站点名称</label><input class="form-control" name="site_name" value="<?php echo e($settings['site_name'] ?? 'NovelHub'); ?>"></div>
          <div class="mb-2"><label class="form-label">描述</label><textarea class="form-control" name="description" rows="3"><?php echo e($settings['description'] ?? ''); ?></textarea></div>
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label">默认字体</label><select class="form-select" name="default_font"><option value="system">系统</option><option value="serif">衬线</option><option value="kaiti">楷体</option><option value="heiti">黑体</option></select></div>
            <div class="col-6"><label class="form-label">默认主题</label><select class="form-select" name="theme"><option value="day">日间</option><option value="night">夜间</option><option value="eye">护眼</option></select></div>
          </div>
          <div class="mb-2"><label class="form-label">上传大小限制（字节）</label><input class="form-control" type="number" name="max_file_size" value="<?php echo (int)($settings['uploads']['max_file_size'] ?? (5*1024*1024)); ?>"></div>
          <button class="btn btn-primary">保存</button>
        </form>
      </div></div></div>
      <div class="col-12 col-lg-6"><div class="card"><div class="card-body">
        <h5 class="card-title">数据维护</h5>
        <form method="post" class="d-inline"><input type="hidden" name="action" value="backup_data"><button class="btn btn-outline-primary">数据备份</button></form>
        <form method="post" class="d-inline ms-2"><input type="hidden" name="action" value="clear_cache"><button class="btn btn-outline-secondary">清理缓存</button></form>
        <hr/>
        <h6>系统日志</h6>
        <?php $ops = json_decode(@file_get_contents(ADMIN_OPERATIONS_FILE), true) ?: []; usort($ops, fn($a,$b)=> strcmp($b['created_at']??'', $a['created_at']??'')); ?>
        <ul class="list-group list-group-flush" style="max-height:280px;overflow:auto;">
          <?php foreach (array_slice($ops,0,50) as $o): ?>
            <li class="list-group-item small"><span class="text-muted"><?php echo e($o['created_at'] ?? ''); ?></span> · <strong><?php echo e($o['action'] ?? ''); ?></strong> · 用户：<?php echo e($o['username'] ?? ''); ?></li>
          <?php endforeach; if (!$ops) echo '<li class="list-group-item text-muted">暂无日志</li>'; ?>
        </ul>
      </div></div></div>
    </div>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($tab==='overview'): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  const userLabels = <?php echo json_encode(array_keys($usersByDay)); ?>;
  const userData = <?php echo json_encode(array_values($usersByDay)); ?>;
  const readLabels = <?php echo json_encode(array_keys($readsByDay)); ?>;
  const readData = <?php echo json_encode(array_values($readsByDay)); ?>;
  new Chart(document.getElementById('userChart'), { type:'line', data:{ labels:userLabels, datasets:[{label:'新增用户', data:userData, borderColor:'#2d8cf0', fill:false}]}, options:{responsive:true, scales:{y:{beginAtZero:true}}} });
  new Chart(document.getElementById('readChart'), { type:'line', data:{ labels:readLabels, datasets:[{label:'阅读量', data:readData, borderColor:'#28a745', fill:false}]}, options:{responsive:true, scales:{y:{beginAtZero:true}}} });
</script>
<?php endif; ?>
</body>
</html>
