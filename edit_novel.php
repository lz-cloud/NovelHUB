<?php
require_once __DIR__ . '/lib/helpers.php';
require_login();

$novel_id = (int)($_GET['novel_id'] ?? 0);
if (!$novel_id) {
    header('Location: /dashboard.php');
    exit;
}

$novel = find_novel($novel_id);
if (!$novel) {
    die('小说不存在');
}

$user = current_user();
if ((int)$novel['author_id'] !== (int)$user['id'] && !is_admin()) {
    die('无权限编辑此小说');
}

$categories = json_decode(file_get_contents(CATEGORIES_FILE), true) ?: [];

$errors = [];
$success = false;

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (delete_novel($novel_id)) {
        header('Location: /dashboard.php?msg=deleted');
        exit;
    } else {
        $errors[] = '删除失败，请稍后重试';
    }
}

// Handle update action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'update')) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $tags = trim($_POST['tags'] ?? '');
    $status = $_POST['status'] ?? 'ongoing';
    $category_ids = $_POST['category_ids'] ?? [];

    if ($title === '') {
        $errors[] = '标题不能为空';
    }

    $cover = $novel['cover_image'];
    if (!empty($_FILES['cover']['name'])) {
        $new_cover = handle_upload($_FILES['cover'], COVERS_DIR);
        if ($new_cover) {
            // Delete old cover if exists
            if ($cover && file_exists(COVERS_DIR . '/' . $cover)) {
                @unlink(COVERS_DIR . '/' . $cover);
            }
            $cover = $new_cover;
        } else {
            $errors[] = '封面上传失败';
        }
    }

    if (!$errors) {
        $now = date('c');
        $update_data = [
            'title' => $title,
            'cover_image' => $cover,
            'category_ids' => array_map('intval', $category_ids),
            'tags' => array_values(array_filter(array_map('trim', explode(',', $tags)))),
            'status' => in_array($status, ['ongoing','completed']) ? $status : 'ongoing',
            'description' => $description,
            'updated_at' => $now,
        ];
        
        if (update_novel($novel_id, $update_data)) {
            $novel = find_novel($novel_id);
            $success = true;
        } else {
            $errors[] = '更新失败，请稍后重试';
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>编辑小说 - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container py-4" style="max-width:800px;">
  <h1 class="mb-3">编辑小说</h1>
  
  <?php if ($success): ?>
    <div class="alert alert-success">更新成功！</div>
  <?php endif; ?>
  
  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
          <li><?php echo e($e); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="update">
    
    <div class="mb-3">
      <label class="form-label">标题</label>
      <input class="form-control" name="title" value="<?php echo e($novel['title']); ?>" required>
    </div>

    <div class="mb-3">
      <label class="form-label">封面</label>
      <?php if (!empty($novel['cover_image'])): ?>
        <div class="mb-2">
          <img src="/uploads/covers/<?php echo e($novel['cover_image']); ?>" alt="当前封面" style="max-width:200px;height:auto;">
          <div class="text-muted small">当前封面（上传新封面将替换）</div>
        </div>
      <?php endif; ?>
      <input type="file" class="form-control" name="cover" accept="image/*">
    </div>

    <div class="mb-3">
      <label class="form-label">分类</label>
      <div class="row">
        <?php foreach ($categories as $c): ?>
          <div class="col-6 col-md-4">
            <label class="form-check">
              <input class="form-check-input" type="checkbox" name="category_ids[]" 
                     value="<?php echo (int)$c['id']; ?>"
                     <?php echo in_array((int)$c['id'], $novel['category_ids'] ?? []) ? 'checked' : ''; ?>>
              <span class="form-check-label"><?php echo e($c['name']); ?></span>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label">标签（用英文逗号分隔）</label>
      <input class="form-control" name="tags" value="<?php echo e(implode(',', $novel['tags'] ?? [])); ?>">
    </div>

    <div class="mb-3">
      <label class="form-label">简介</label>
      <textarea class="form-control" rows="5" name="description"><?php echo e($novel['description']); ?></textarea>
    </div>

    <div class="mb-3">
      <label class="form-label">状态</label>
      <select class="form-select" name="status">
        <option value="ongoing" <?php if(($novel['status'] ?? 'ongoing')==='ongoing') echo 'selected'; ?>>连载中</option>
        <option value="completed" <?php if(($novel['status'] ?? 'ongoing')==='completed') echo 'selected'; ?>>已完结</option>
      </select>
    </div>

    <div class="mb-3">
      <button type="submit" class="btn btn-primary">保存更改</button>
      <a href="/dashboard.php" class="btn btn-secondary">返回</a>
      <button type="button" class="btn btn-outline-danger float-end" data-bs-toggle="modal" data-bs-target="#deleteModal">删除小说</button>
    </div>
  </form>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteModalLabel">确认删除</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>确定要删除小说《<?php echo e($novel['title']); ?>》吗？</p>
        <p class="text-danger">此操作将删除该小说及其所有章节，且无法恢复！</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
        <form method="post" style="display:inline;">
          <input type="hidden" name="action" value="delete">
          <button type="submit" class="btn btn-danger">确认删除</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
