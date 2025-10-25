<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Auth.php';
require_login();

Auth::requireRole([Auth::ROLE_SUPER_ADMIN, Auth::ROLE_CONTENT_ADMIN]);

$dm = new DataManager(DATA_DIR);
$logger = new OperationLog();

$message = null;
$messageType = null;
$tab = $_GET['tab'] ?? 'book';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_book'])) {
    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 1);
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'published';
    
    if (empty($title)) {
        $message = '书名不能为空';
        $messageType = 'danger';
    } else {
        $user = current_user();
        $novel = [
            'title' => $title,
            'author' => $author ?: get_user_display_name((int)$user['id']),
            'category_id' => $categoryId,
            'description' => $description,
            'user_id' => (int)$user['id'],
            'status' => $status,
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'view_count' => 0,
            'bookmark_count' => 0,
        ];
        
        if (isset($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
            $coverName = handle_upload($_FILES['cover'], COVERS_DIR);
            if ($coverName) {
                $novel['cover'] = $coverName;
            }
        }
        
        $novelId = save_novel($novel);
        
        if (isset($_FILES['chapters_file']) && $_FILES['chapters_file']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['chapters_file']['tmp_name'];
            $content = file_get_contents($tmpPath);
            $detectedEncoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'BIG5'], true);
            if ($detectedEncoding && strtoupper($detectedEncoding) !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $detectedEncoding);
            }
            
            $chapters = parseChaptersFromText($content);
            
            if ($chapters) {
                foreach ($chapters as $index => $chapterData) {
                    $chapterId = $index + 1;
                    $chapterRecord = [
                        'id' => $chapterId,
                        'novel_id' => $novelId,
                        'title' => $chapterData['title'],
                        'content' => $chapterData['content'],
                        'word_count' => mb_strlen($chapterData['content']),
                        'created_at' => date('c'),
                        'updated_at' => date('c'),
                        'status' => 'published',
                    ];
                    
                    $dir = ensure_novel_dir($novelId);
                    $chapterFile = $dir . '/' . $chapterId . '.json';
                    if (file_put_contents($chapterFile, json_encode($chapterRecord, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
                        $message = '写入章节文件失败，请检查权限';
                        $messageType = 'danger';
                        break;
                    }
                }
                
                if ($messageType !== 'danger') {
                    $message = "书籍《{$title}》上传成功，共导入 " . count($chapters) . " 章";
                    $messageType = 'success';
                    $logger->log('upload_book_with_chapters', ['novel_id' => $novelId, 'chapter_count' => count($chapters)]);
                }
            } else {
                $message = "书籍《{$title}》创建成功，但未能解析章节";
                $messageType = 'warning';
                $logger->log('upload_book', ['novel_id' => $novelId]);
            }
        } else {
            $message = "书籍《{$title}》创建成功";
            $messageType = 'success';
            $logger->log('upload_book', ['novel_id' => $novelId]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_upload_chapters'])) {
    $novelId = (int)($_POST['novel_id'] ?? 0);
    
    if ($novelId <= 0) {
        $message = '请选择小说';
        $messageType = 'danger';
    } elseif (!find_novel($novelId)) {
        $message = '小说不存在';
        $messageType = 'danger';
    } elseif (isset($_FILES['chapters_file']) && $_FILES['chapters_file']['error'] === UPLOAD_ERR_OK) {
        $tmpPath = $_FILES['chapters_file']['tmp_name'];
        $content = file_get_contents($tmpPath);
        $detectedEncoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'BIG5'], true);
        if ($detectedEncoding && strtoupper($detectedEncoding) !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $detectedEncoding);
        }
        
        $chapters = parseChaptersFromText($content);
        
        if ($chapters) {
            $startId = next_chapter_id($novelId);
            $writeFailed = false;
            
            foreach ($chapters as $index => $chapterData) {
                $chapterId = $startId + $index;
                $chapterRecord = [
                    'id' => $chapterId,
                    'novel_id' => $novelId,
                    'title' => $chapterData['title'],
                    'content' => $chapterData['content'],
                    'word_count' => mb_strlen($chapterData['content']),
                    'created_at' => date('c'),
                    'updated_at' => date('c'),
                    'status' => 'published',
                ];
                
                $dir = ensure_novel_dir($novelId);
                $chapterFile = $dir . '/' . $chapterId . '.json';
                if (file_put_contents($chapterFile, json_encode($chapterRecord, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
                    $writeFailed = true;
                    break;
                }
            }
            
            if ($writeFailed) {
                $message = '写入章节文件失败，请检查权限';
                $messageType = 'danger';
            } else {
                update_novel($novelId, ['updated_at' => date('c')]);
                
                $message = "成功批量导入 " . count($chapters) . " 章";
                $messageType = 'success';
                $logger->log('batch_upload_chapters', ['novel_id' => $novelId, 'chapter_count' => count($chapters)]);
            }
        } else {
            $message = '未能解析章节，请检查文件格式';
            $messageType = 'danger';
        }
    } else {
        $message = '请选择章节文件';
        $messageType = 'danger';
    }
}

function parseChaptersFromText(string $content): array
{
    $chapters = [];
    
    $patterns = [
        '/^第[零一二三四五六七八九十百千\d]+[章节卷]/um',
        '/^Chapter\s*\d+/im',
        '/^\d+[\s\.\-、]+/um',
    ];
    
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $currentChapter = null;
    $currentContent = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $isChapterTitle = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                $isChapterTitle = true;
                break;
            }
        }
        
        if ($isChapterTitle) {
            if ($currentChapter) {
                $currentChapter['content'] = trim(implode("\n", $currentContent));
                if (!empty($currentChapter['content'])) {
                    $chapters[] = $currentChapter;
                }
            }
            $currentChapter = ['title' => $line];
            $currentContent = [];
        } elseif ($currentChapter) {
            $currentContent[] = $line;
        }
    }
    
    if ($currentChapter) {
        $currentChapter['content'] = trim(implode("\n", $currentContent));
        if (!empty($currentChapter['content'])) {
            $chapters[] = $currentChapter;
        }
    }
    
    if (empty($chapters)) {
        $trimmed = trim($content);
        if ($trimmed !== '') {
            $chapters[] = [
                'title' => '第1章',
                'content' => $trimmed,
            ];
        }
    }
    
    return $chapters;
}

$categories = $dm->readJson(CATEGORIES_FILE, []);
$novels = load_novels();

?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>批量上传 - 管理后台</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1>批量上传</h1>
    <div>
      <a class="btn btn-secondary" href="/admin_dashboard.php">管理仪表盘</a>
      <a class="btn btn-secondary" href="/">首页</a>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
      <?php echo e($message); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <ul class="nav nav-tabs mb-4">
    <li class="nav-item">
      <a class="nav-link <?php echo $tab === 'book' ? 'active' : ''; ?>" href="?tab=book">上传书籍</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?php echo $tab === 'chapters' ? 'active' : ''; ?>" href="?tab=chapters">批量上传章节</a>
    </li>
  </ul>

  <?php if ($tab === 'book'): ?>
  <div class="row">
    <div class="col-md-8">
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">上传书籍（含章节）</h5>
          <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
              <label class="form-label">书名</label>
              <input type="text" class="form-control" name="title" required>
            </div>
            
            <div class="mb-3">
              <label class="form-label">作者</label>
              <input type="text" class="form-control" name="author" placeholder="留空则使用当前用户名">
            </div>
            
            <div class="mb-3">
              <label class="form-label">分类</label>
              <select class="form-select" name="category_id" required>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?php echo (int)$cat['id']; ?>"><?php echo e($cat['name'] ?? ''); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="mb-3">
              <label class="form-label">简介</label>
              <textarea class="form-control" name="description" rows="4"></textarea>
            </div>
            
            <div class="mb-3">
              <label class="form-label">封面图片（可选）</label>
              <input type="file" class="form-control" name="cover" accept="image/*">
            </div>
            
            <div class="mb-3">
              <label class="form-label">章节文件（TXT格式，可选）</label>
              <input type="file" class="form-control" name="chapters_file" accept=".txt">
              <div class="form-text">
                支持TXT格式，系统会自动识别章节标题（如"第一章 开始"、"Chapter 1"等）
              </div>
            </div>
            
            <div class="mb-3">
              <label class="form-label">状态</label>
              <select class="form-select" name="status">
                <option value="published">已发布</option>
                <option value="draft">草稿</option>
              </select>
            </div>
            
            <button type="submit" name="upload_book" class="btn btn-primary">上传书籍</button>
          </form>
        </div>
      </div>
    </div>
    
    <div class="col-md-4">
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">使用说明</h5>
          <ul class="small">
            <li>书籍信息为必填项</li>
            <li>章节文件为可选，可以先创建书籍，后续再批量上传章节</li>
            <li>章节文件需要TXT格式，系统会自动识别章节标题</li>
            <li>支持的章节标题格式：
              <ul>
                <li>第一章、第二章...</li>
                <li>第1章、第2章...</li>
                <li>Chapter 1, Chapter 2...</li>
                <li>1. 章节名、2. 章节名...</li>
              </ul>
            </li>
            <li>文件编码自动检测（支持UTF-8、GBK、GB2312、BIG5）</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'chapters'): ?>
  <div class="row">
    <div class="col-md-8">
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">批量上传章节</h5>
          <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
              <label class="form-label">选择小说</label>
              <select class="form-select" name="novel_id" required>
                <option value="">请选择...</option>
                <?php foreach ($novels as $novel): ?>
                  <option value="<?php echo (int)$novel['id']; ?>">
                    <?php echo e($novel['title'] ?? ''); ?> - <?php echo e($novel['author'] ?? ''); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="mb-3">
              <label class="form-label">章节文件（TXT格式）</label>
              <input type="file" class="form-control" name="chapters_file" accept=".txt" required>
              <div class="form-text">
                支持TXT格式，系统会自动识别章节标题并导入
              </div>
            </div>
            
            <button type="submit" name="batch_upload_chapters" class="btn btn-primary">批量上传章节</button>
          </form>
        </div>
      </div>
    </div>
    
    <div class="col-md-4">
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">使用说明</h5>
          <ul class="small">
            <li>选择需要添加章节的小说</li>
            <li>上传包含章节内容的TXT文件</li>
            <li>系统会自动识别章节标题并导入</li>
            <li>新章节会追加到现有章节之后</li>
            <li>支持的章节标题格式同上传书籍</li>
            <li>建议：大文件可能需要较长时间处理</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
