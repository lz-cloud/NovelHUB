<?php
require_once __DIR__ . '/lib/helpers.php';
require_login();

$errors = [];
$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$tags = trim($_POST['tags'] ?? '');
$status = $_POST['status'] ?? 'ongoing';
$category_ids = $_POST['category_ids'] ?? [];

$categories = json_decode(file_get_contents(CATEGORIES_FILE), true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($title === '') $errors[] = '标题不能为空';
    $cover = null;
    if (!empty($_FILES['cover']['name'])) {
        $cover = handle_upload($_FILES['cover'], COVERS_DIR);
        if (!$cover) $errors[] = '封面上传失败';
    }

    if (!$errors) {
        $now = date('c');
        $novel = [
            'title' => $title,
            'author_id' => (int)current_user()['id'],
            'cover_image' => $cover,
            'category_ids' => array_map('intval', $category_ids),
            'tags' => array_values(array_filter(array_map('trim', explode(',', $tags)))),
            'status' => in_array($status, ['ongoing','completed']) ? $status : 'ongoing',
            'description' => $description,
            'created_at' => $now,
            'updated_at' => $now,
            'stats' => ['views'=>0,'favorites'=>0],
            'last_chapter_id' => null,
        ];
        $id = save_novel($novel);
        if ($id) {
            ensure_novel_dir($id);
            header('Location: /dashboard.php');
            exit;
        } else {
            $errors[] = '创建失败，请稍后重试';
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>创建新小说 - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-4" style="max-width:800px;">
  <h1 class="mb-3">创建新小说</h1>
  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"> <?php foreach ($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?> </ul>
    </div>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <div class="mb-3">
      <label class="form-label">标题</label>
      <input class="form-control" name="title" value="<?php echo e($title); ?>" required>
    </div>
    <div class="mb-3">
      <label class="form-label">封面</label>
      <input type="file" class="form-control" name="cover" accept="image/*">
    </div>
    <div class="mb-3">
      <label class="form-label">分类</label>
      <div class="row">
        <?php foreach ($categories as $c): ?>
          <div class="col-6 col-md-4">
            <label class="form-check">
              <input class="form-check-input" type="checkbox" name="category_ids[]" value="<?php echo (int)$c['id']; ?>">
              <span class="form-check-label"><?php echo e($c['name']); ?></span>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label">标签（用英文逗号分隔）</label>
      <input class="form-control" name="tags" value="<?php echo e($tags); ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">简介</label>
      <textarea class="form-control" rows="5" name="description"><?php echo e($description); ?></textarea>
    </div>
    <div class="mb-3">
      <label class="form-label">状态</label>
      <select class="form-select" name="status">
        <option value="ongoing" <?php if($status==='ongoing') echo 'selected'; ?>>连载中</option>
        <option value="completed" <?php if($status==='completed') echo 'selected'; ?>>已完结</option>
      </select>
    </div>
    <button class="btn btn-primary">创建</button>
    <a href="/dashboard.php" class="btn btn-link">返回</a>
  </form>
</div>
</body>
</html>
