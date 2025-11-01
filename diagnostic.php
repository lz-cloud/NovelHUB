<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NovelHub 系统诊断</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .section { margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #007bff; }
        .success { border-left-color: #28a745; }
        .warning { border-left-color: #ffc107; }
        .error { border-left-color: #dc3545; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f0f0f0; }
        .status { font-weight: bold; }
        .status.ok { color: #28a745; }
        .status.fail { color: #dc3545; }
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; border-radius: 3px; }
        .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 3px; margin: 5px; }
        .btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <div class="container">
        <h1>NovelHub 系统诊断</h1>
        
        <?php
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        
        $checks = [];
        $errors = [];
        
        // PHP Version Check
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '7.4.0', '>=');
        $checks['PHP Version'] = [
            'status' => $phpOk,
            'value' => $phpVersion,
            'required' => 'PHP 7.4+',
            'message' => $phpOk ? '版本兼容' : 'PHP 版本过低，需要 7.4 或更高版本'
        ];
        if (!$phpOk) $errors[] = 'PHP 版本过低';
        
        // Extension Checks
        $requiredExtensions = ['json', 'fileinfo', 'mbstring'];
        foreach ($requiredExtensions as $ext) {
            $loaded = extension_loaded($ext);
            $checks["Extension: $ext"] = [
                'status' => $loaded,
                'value' => $loaded ? '已安装' : '未安装',
                'required' => '必需',
                'message' => $loaded ? '' : "缺少 PHP 扩展: $ext"
            ];
            if (!$loaded) $errors[] = "缺少扩展: $ext";
        }
        
        // Directory Checks
        define('BASE_DIR', __DIR__);
        $dirs = [
            'data' => BASE_DIR . '/data',
            'uploads' => BASE_DIR . '/uploads',
            'chapters' => BASE_DIR . '/chapters',
            'uploads/covers' => BASE_DIR . '/uploads/covers',
            'uploads/avatars' => BASE_DIR . '/uploads/avatars',
        ];
        
        foreach ($dirs as $name => $path) {
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            $checks["Directory: $name"] = [
                'status' => $writable,
                'value' => $exists ? ($writable ? '可写' : '不可写') : '不存在',
                'required' => '必须存在且可写',
                'message' => $writable ? '' : ($exists ? "目录 $name 不可写" : "目录 $name 不存在")
            ];
            if (!$writable) $errors[] = "目录 $name 问题";
        }
        
        // File Checks
        try {
            require_once __DIR__ . '/config.php';
            $checks['config.php'] = ['status' => true, 'value' => '加载成功', 'required' => '必需', 'message' => ''];
            
            require_once __DIR__ . '/lib/DataManager.php';
            $checks['DataManager.php'] = ['status' => true, 'value' => '加载成功', 'required' => '必需', 'message' => ''];
            
            require_once __DIR__ . '/lib/helpers.php';
            $checks['helpers.php'] = ['status' => true, 'value' => '加载成功', 'required' => '必需', 'message' => ''];
            
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $checks['Core Files'] = ['status' => false, 'value' => '加载失败', 'required' => '必需', 'message' => $error];
            $errors[] = "核心文件加载失败: $error";
        }
        
        // Database File Checks
        if (defined('USERS_FILE')) {
            $files = [
                'users.json' => USERS_FILE,
                'novels.json' => NOVELS_FILE,
                'categories.json' => CATEGORIES_FILE,
            ];
            
            foreach ($files as $name => $file) {
                $exists = file_exists($file);
                $writable = $exists && is_writable($file);
                $checks["File: $name"] = [
                    'status' => $writable,
                    'value' => $exists ? ($writable ? '可写' : '只读') : '不存在',
                    'required' => '必须可写',
                    'message' => $writable ? '' : ''
                ];
            }
        }
        
        // Overall Status
        $allOk = empty($errors);
        $sectionClass = $allOk ? 'success' : 'error';
        ?>
        
        <div class="section <?php echo $sectionClass; ?>">
            <h2>总体状态</h2>
            <?php if ($allOk): ?>
                <p class="status ok">✓ 所有检查通过！系统可以正常运行。</p>
            <?php else: ?>
                <p class="status fail">✗ 发现 <?php echo count($errors); ?> 个问题：</p>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <h2>系统信息</h2>
            <table>
                <tr>
                    <th>项目</th>
                    <th>当前值</th>
                    <th>要求</th>
                    <th>状态</th>
                </tr>
                <?php foreach ($checks as $name => $check): ?>
                <tr>
                    <td><?php echo htmlspecialchars($name); ?></td>
                    <td><?php echo htmlspecialchars($check['value']); ?></td>
                    <td><?php echo htmlspecialchars($check['required']); ?></td>
                    <td class="status <?php echo $check['status'] ? 'ok' : 'fail'; ?>">
                        <?php echo $check['status'] ? '✓' : '✗'; ?>
                        <?php if ($check['message']): ?>
                            <br><small><?php echo htmlspecialchars($check['message']); ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        
        <div class="section">
            <h2>服务器信息</h2>
            <table>
                <tr><th>项目</th><th>值</th></tr>
                <tr><td>服务器软件</td><td><?php echo htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'); ?></td></tr>
                <tr><td>PHP SAPI</td><td><?php echo htmlspecialchars(php_sapi_name()); ?></td></tr>
                <tr><td>Document Root</td><td><?php echo htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? 'N/A'); ?></td></tr>
                <tr><td>当前目录</td><td><?php echo htmlspecialchars(__DIR__); ?></td></tr>
            </table>
        </div>
        
        <?php if ($allOk): ?>
        <div class="section success">
            <h2>下一步</h2>
            <p>系统已就绪。您可以：</p>
            <a href="/" class="btn">访问首页</a>
            <a href="/register.php" class="btn">注册账号</a>
            <a href="/login.php" class="btn">登录系统</a>
            <p><small>默认管理员账号：admin / Admin@123</small></p>
        </div>
        <?php endif; ?>
        
        <div class="section warning">
            <h2>安全提示</h2>
            <p><strong>注意：</strong>请在完成诊断后删除此文件（diagnostic.php）和 test.php、phpinfo.php，以避免泄露系统信息。</p>
            <pre>rm diagnostic.php test.php phpinfo.php</pre>
        </div>
    </div>
</body>
</html>
