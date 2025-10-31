<?php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/DataManager.php';

require_login();

$user = current_user();
$dm = new DataManager(DATA_DIR);

$message = null;
$messageType = null;
$importedNovel = null;

// Handle import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_content'])) {
    $content = $_POST['content'] ?? '';
    $result = importZLibraryBook($content, (int)$user['id'], $dm);
    
    if ($result['success']) {
        $importedNovel = $result['novel'];
        $message = sprintf('成功导入《%s》，共 %d 个章节', $result['novel']['title'], count($result['chapters']));
        $messageType = 'success';
    } else {
        $message = '导入失败：' . ($result['error'] ?? '未知错误');
        $messageType = 'danger';
    }
}

/**
 * Parse Z-Library book format and import
 */
function importZLibraryBook(string $content, int $userId, DataManager $dm): array {
    if (empty($content)) {
        return ['success' => false, 'error' => '内容为空'];
    }
    
    // Parse book metadata and content
    $lines = explode("\n", $content);
    $metadata = [];
    $chapters = [];
    $currentChapter = null;
    $inContent = false;
    $inMetadata = false;
    $tocEnd = false;
    
    // First pass: extract metadata and table of contents
    $tocLines = [];
    $i = 0;
    while ($i < count($lines)) {
        $line = trim($lines[$i]);
        
        // Detect "目录" section
        if ($line === '目录' && !$tocEnd) {
            $i++;
            while ($i < count($lines)) {
                $line = trim($lines[$i]);
                if (empty($line) && count($tocLines) > 0) {
                    // End of TOC
                    $tocEnd = true;
                    break;
                }
                if (!empty($line)) {
                    $tocLines[] = $line;
                }
                $i++;
            }
            continue;
        }
        
        // Detect metadata section (图书在版编目)
        if (strpos($line, '图书在版编目') !== false || strpos($line, 'ISBN') !== false) {
            $inMetadata = true;
        }
        
        if ($inMetadata) {
            // Extract ISBN
            if (preg_match('/ISBN\s+([\d\-]+)/', $line, $matches)) {
                $metadata['isbn'] = $matches[1];
            }
            // Extract publisher info
            if (preg_match('/(.+)著/', $line, $matches)) {
                $metadata['author'] = trim($matches[1]);
            }
            if (preg_match('/(.+)出版社/', $line, $matches)) {
                $metadata['publisher'] = trim($matches[1]);
            }
            if (preg_match('/(\d{4})年/', $line, $matches)) {
                $metadata['publish_year'] = $matches[1];
            }
        }
        
        $i++;
    }
    
    // Second pass: extract chapters based on TOC
    $contentText = implode("\n", $lines);
    $chapterContents = [];
    
    foreach ($tocLines as $idx => $chapterTitle) {
        // Find chapter content by searching for title
        $pattern = '/^' . preg_quote($chapterTitle, '/') . '\s*$/m';
        if (preg_match($pattern, $contentText, $matches, PREG_OFFSET_CAPTURE)) {
            $startPos = $matches[0][1] + strlen($matches[0][0]);
            
            // Find next chapter or end of content
            $endPos = strlen($contentText);
            if ($idx + 1 < count($tocLines)) {
                $nextTitle = $tocLines[$idx + 1];
                $nextPattern = '/^' . preg_quote($nextTitle, '/') . '\s*$/m';
                if (preg_match($nextPattern, $contentText, $nextMatches, PREG_OFFSET_CAPTURE, $startPos)) {
                    $endPos = $nextMatches[0][1];
                }
            }
            
            $chapterText = substr($contentText, $startPos, $endPos - $startPos);
            $chapterText = trim($chapterText);
            
            // Clean up chapter text (remove metadata sections)
            $chapterText = preg_replace('/图书在版编目.+?(?=\n\n|\z)/s', '', $chapterText);
            $chapterText = trim($chapterText);
            
            if (!empty($chapterText)) {
                $chapterContents[] = [
                    'title' => $chapterTitle,
                    'content' => $chapterText
                ];
            }
        }
    }
    
    // If no chapters found via TOC, try to detect by empty line patterns
    if (empty($chapterContents)) {
        // Fallback: split by double newlines and treat each section as a chapter
        $sections = preg_split('/\n\n\n+/', $contentText);
        foreach ($sections as $idx => $section) {
            $section = trim($section);
            if (strlen($section) > 100) { // Minimum chapter length
                $chapterContents[] = [
                    'title' => '第' . ($idx + 1) . '章',
                    'content' => $section
                ];
            }
        }
    }
    
    if (empty($chapterContents)) {
        return ['success' => false, 'error' => '未能解析章节内容'];
    }
    
    // Extract title from metadata or use first line
    $title = $metadata['author'] ?? null;
    if (!$title && !empty($lines)) {
        // Try to find title before "目录"
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && $line !== '目录' && !preg_match('/^第.+章/', $line)) {
                $title = $line;
                break;
            }
        }
    }
    
    if (!$title) {
        $title = '导入的作品 ' . date('Y-m-d H:i:s');
    }
    
    // Create novel
    $novel = [
        'title' => $title,
        'author_id' => $userId,
        'description' => '从 Z-Library 导入' . (isset($metadata['author']) ? '，作者：' . $metadata['author'] : ''),
        'status' => 'completed',
        'category_ids' => [],
        'tags' => ['导入'],
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'stats' => ['views' => 0, 'favorites' => 0],
        'metadata' => $metadata
    ];
    
    $novelId = $dm->appendWithId(NOVELS_FILE, $novel);
    $novel['id'] = $novelId;
    
    // Create chapters
    $chapterIds = [];
    $chapterDir = CHAPTERS_DIR . '/novel_' . $novelId;
    if (!is_dir($chapterDir)) {
        mkdir($chapterDir, 0775, true);
    }
    
    foreach ($chapterContents as $idx => $chap) {
        $chapterId = $idx + 1;
        $chapter = [
            'id' => $chapterId,
            'novel_id' => $novelId,
            'title' => $chap['title'],
            'content' => $chap['content'],
            'status' => 'published',
            'created_at' => date('c'),
            'updated_at' => date('c')
        ];
        
        $chapterFile = $chapterDir . '/' . $chapterId . '.json';
        file_put_contents($chapterFile, json_encode($chapter, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $chapterIds[] = $chapterId;
    }
    
    // Update novel with last chapter id
    $novel['last_chapter_id'] = end($chapterIds);
    $dm->updateById(NOVELS_FILE, $novelId, $novel);
    
    return [
        'success' => true,
        'novel' => $novel,
        'chapters' => $chapterContents
    ];
}

?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>导入 Z-Library 书籍 - NovelHub</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="/">NovelHub</a>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-light" href="/dashboard.php">仪表盘</a>
      <a class="btn btn-sm btn-outline-light" href="/">首页</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <div class="row">
    <div class="col-md-8 offset-md-2">
      <div class="card">
        <div class="card-body">
          <h2 class="card-title">导入 Z-Library 书籍</h2>
          <p class="text-muted">将从 Z-Library 下载的书籍内容粘贴到下方，系统将自动解析并创建作品。</p>
          
          <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
              <?php echo $message; ?>
              <?php if ($importedNovel): ?>
                <hr>
                <a href="/novel_detail.php?novel_id=<?php echo (int)$importedNovel['id']; ?>" class="btn btn-sm btn-primary">查看作品</a>
                <a href="/import_zlibrary.php" class="btn btn-sm btn-secondary">继续导入</a>
              <?php endif; ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
          <?php endif; ?>
          
          <form method="post">
            <div class="mb-3">
              <label class="form-label">书籍内容</label>
              <textarea class="form-control" name="content" rows="20" placeholder="粘贴书籍全文内容，包括目录和正文..." required></textarea>
              <div class="form-text">
                支持的格式：包含"目录"部分的完整文本，系统将自动识别章节结构。
              </div>
            </div>
            
            <div class="d-grid gap-2">
              <button type="submit" name="import_content" class="btn btn-primary">导入书籍</button>
              <a href="/dashboard.php" class="btn btn-outline-secondary">取消</a>
            </div>
          </form>
        </div>
      </div>
      
      <div class="card mt-3">
        <div class="card-body">
          <h5 class="card-title">使用说明</h5>
          <ol>
            <li>从 Z-Library 下载书籍（TXT 格式）</li>
            <li>打开文件，复制全部内容</li>
            <li>粘贴到上方文本框中</li>
            <li>点击"导入书籍"按钮</li>
            <li>系统将自动识别标题、作者、章节等信息</li>
          </ol>
          <p class="text-muted mb-0">
            <strong>注意：</strong>导入的内容将作为您的原创作品发布，请确保您有权发布该内容。
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
