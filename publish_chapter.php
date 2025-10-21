<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Notifier.php';
require_login();

$novel_id = (int)($_GET['novel_id'] ?? $_POST['novel_id'] ?? 0);
$novel = $novel_id ? find_novel($novel_id) : null;
if (!$novel) {
    http_response_code(404);
    echo '小说不存在';
    exit;
}
if ((int)$novel['author_id'] !== (int)current_user()['id'] && !is_admin()) {
    http_response_code(403);
    echo '无权操作';
    exit;
}

$errors = [];
$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');
$status = $_POST['status'] ?? 'published';
$chapter_id = isset($_GET['chapter_id']) ? (int)$_GET['chapter_id'] : null;
$mode = $chapter_id ? 'edit' : 'create';

if ($mode === 'edit') {
    $file = CHAPTERS_DIR . '/novel_' . $novel_id . '/' . $chapter_id . '.json';
    if (!file_exists($file)) {
        $errors[] = '章节不存在';
    } else if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $data = json_decode(file_get_contents($file), true) ?: [];
        $title = $data['title'] ?? '';
        $content = $data['content'] ?? '';
        $status = $data['status'] ?? 'draft';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($title === '') $errors[] = '标题不能为空';
    if ($content === '') $errors[] = '内容不能为空';

    if (!$errors) {
        $dir = ensure_novel_dir($novel_id);
        $now = date('c');
        if ($mode === 'create') {
            $chapter_id = next_chapter_id($novel_id);
            $data = [
                'id' => $chapter_id,
                'novel_id' => $novel_id,
                'title' => $title,
                'content' => $content,
                'status' => in_array($status, ['published','draft']) ? $status : 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        } else {
            $data = json_decode(file_get_contents($dir . '/' . $chapter_id . '.json'), true) ?: [];
            $data['title'] = $title;
            $data['content'] = $content;
            $data['status'] = in_array($status, ['published','draft']) ? $status : 'published';
            $data['updated_at'] = $now;
        }
        // Simple sensitive words detection (demo)
        $sensitive = ['暴恐','涉政','违法'];
        $flags = [];
        foreach ($sensitive as $sw) { if (mb_strpos($content, $sw) !== false) { $flags[] = 'sensitive:'.$sw; } }
        if ($flags) {
            $audit = json_decode(@file_get_contents(ADMIN_AUDIT_FILE), true) ?: [];
            $max = 0; foreach ($audit as $a) { $max = max($max, (int)($a['id'] ?? 0)); }
            $audit[] = ['id'=>$max+1,'type'=>'chapter','novel_id'=>$novel_id,'chapter_id'=>$chapter_id,'title'=>$title,'status'=>'pending','flags'=>$flags,'created_at'=>$now];
            @file_put_contents(ADMIN_AUDIT_FILE, json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        file_put_contents($dir . '/' . $chapter_id . '.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        update_novel($novel_id, ['updated_at' => $now, 'last_chapter_id' => $chapter_id]);
        // Notify followers if published
        if (($data['status'] ?? 'published') === 'published') {
            $list = $dm->readJson(BOOKSHELVES_FILE, []);
            $sentTo = [];
            foreach ($list as $r) {
                if ((int)($r['novel_id'] ?? 0) === $novel_id) $sentTo[(int)($r['user_id'] ?? 0)] = true;
            }
            $notifier = new Notifier();
            foreach (array_keys($sentTo) as $uid) {
                if ($uid <= 0) continue;
                $notifier->notify($uid, 'update', '收藏作品有新章节', '你收藏的作品《'.($novel['title'] ?? '').'》更新：'.$title, '/reading.php?novel_id='.$novel_id.'&chapter_id='.$chapter_id);
            }
        }
        header('Location: /dashboard.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $mode==='edit'?'编辑章节':'发布新章节'; ?> - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-4" style="max-width:900px;">
  <h1 class="mb-3"><?php echo $mode==='edit'?'编辑章节':'发布新章节'; ?></h1>
  <p class="text-muted">作品：<?php echo e($novel['title'] ?? ''); ?></p>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="novel_id" value="<?php echo (int)$novel_id; ?>">
    <div class="mb-3">
      <label class="form-label">章节标题</label>
      <input class="form-control" name="title" value="<?php echo e($title); ?>" required>
    </div>
    <div class="mb-3">
      <label class="form-label">内容</label>
      <textarea class="form-control" name="content" rows="16" required><?php echo e($content); ?></textarea>
    </div>
    <div class="mb-3">
      <label class="form-label">状态</label>
      <select class="form-select" name="status">
        <option value="published" <?php if($status==='published') echo 'selected'; ?>>发布</option>
        <option value="draft" <?php if($status==='draft') echo 'selected'; ?>>草稿</option>
      </select>
    </div>
    <button class="btn btn-primary"><?php echo $mode==='edit'?'保存修改':'发布'; ?></button>
    <a class="btn btn-link" href="/dashboard.php">返回</a>
  </form>
</div>
</body>
</html>
