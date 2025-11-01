# NovelHub 故障排除指南

## 常见问题解决

### 1. HTTP 500 内部服务器错误

#### 症状
- 访问网站时显示 "HTTP ERROR 500"
- 页面显示 "当前无法使用此页面"

#### 可能的原因和解决方案

**A. PHP 版本过低**
```bash
# 检查 PHP 版本
php -v

# 需要 PHP 7.4 或更高版本
# 如版本过低，请升级 PHP
sudo apt-get update
sudo apt-get install php8.1 php8.1-fpm php8.1-cli
```

**B. 缺少 PHP 扩展**
```bash
# 检查已安装的扩展
php -m

# 安装必需的扩展
sudo apt-get install php-json php-mbstring php-fileinfo
```

**C. 文件和目录权限问题**
```bash
# 设置目录权限
cd /var/www/novelhub
chmod -R 775 data/ uploads/ chapters/
chmod 664 data/*.json

# 设置所有者为 Web 服务器用户
sudo chown -R www-data:www-data /var/www/novelhub
```

**D. Web 服务器配置问题**

**Apache:**
```bash
# 启用必需模块
sudo a2enmod rewrite
sudo a2enmod headers
sudo systemctl restart apache2

# 检查 .htaccess 文件存在
ls -la /var/www/novelhub/.htaccess

# 确保 AllowOverride All 在虚拟主机配置中设置
```

**Nginx:**
```bash
# 检查配置语法
sudo nginx -t

# 重启 Nginx
sudo systemctl restart nginx

# 检查 PHP-FPM 是否运行
sudo systemctl status php8.1-fpm
```

**E. 日志检查**

查看错误日志获取详细信息：
```bash
# Apache
sudo tail -f /var/log/apache2/error.log

# Nginx
sudo tail -f /var/log/nginx/error.log

# PHP 错误日志
sudo tail -f /var/log/php_errors.log

# 应用程序错误日志
cat /var/www/novelhub/error.log
```

### 2. 使用诊断页面

访问 `http://your-domain/diagnostic.php` 进行系统检查。

诊断页面会自动检测：
- PHP 版本兼容性
- 必需 PHP 扩展
- 目录和文件权限
- 核心文件加载状态

**重要：** 诊断完成后请删除此文件：
```bash
rm /var/www/novelhub/diagnostic.php
```

### 3. 快速启动测试

使用 PHP 内置服务器快速测试：
```bash
cd /var/www/novelhub
php -S 0.0.0.0:8000

# 或使用启动脚本
./start_server.sh
```

然后访问 `http://localhost:8000` 测试。

如果内置服务器正常工作，说明代码没有问题，是 Web 服务器配置的问题。

### 4. 白屏或空页面

**启用错误显示（仅调试用）：**
编辑 `config.php`，确保以下行存在：
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
```

**禁用 OPcache（临时）：**
```bash
sudo service php8.1-fpm restart
```

### 5. 文件上传失败

**检查 PHP 上传限制：**
编辑 `php.ini`：
```ini
upload_max_filesize = 10M
post_max_size = 10M
memory_limit = 256M
```

**检查目录权限：**
```bash
ls -ld /var/www/novelhub/uploads/
# 应该显示 drwxrwxr-x 或类似

# 如果不可写
chmod 775 /var/www/novelhub/uploads/
```

### 6. 数据文件损坏

**备份和重置：**
```bash
# 备份现有数据
cp -r /var/www/novelhub/data /var/www/novelhub/data.backup

# 如果 JSON 文件损坏，可以重新初始化
rm /var/www/novelhub/data/users.json
# 重新访问网站会自动创建文件
```

**验证 JSON 文件语法：**
```bash
# 检查 users.json 语法
php -r "json_decode(file_get_contents('/var/www/novelhub/data/users.json')); echo json_last_error() === JSON_ERROR_NONE ? 'OK' : 'ERROR';"
```

### 7. 数据库迁移问题

如果从文件存储迁移到数据库：
```bash
# 访问迁移页面
http://your-domain/admin_migration.php

# 或使用命令行
php /var/www/novelhub/admin_migration.php
```

### 8. 性能问题

**启用 OPcache：**
编辑 `php.ini`:
```ini
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 4000
```

**清理缓存：**
```bash
rm -rf /var/www/novelhub/data/cache/*
```

**使用 CDN：**
修改模板文件，使用 CDN 链接加载 Bootstrap 等资源。

### 9. 安全问题

**默认管理员密码：**
```
用户名：admin
默认密码：Admin@123
```
**首次登录后立即修改！**

**移除调试文件：**
```bash
rm /var/www/novelhub/diagnostic.php
rm /var/www/novelhub/test.php
rm /var/www/novelhub/phpinfo.php
```

**保护敏感目录：**
确保 `.htaccess` 或 Nginx 配置阻止访问 `data/` 目录。

**启用 HTTPS：**
```bash
# 使用 Let's Encrypt
sudo certbot --apache -d novelhub.example.com
# 或
sudo certbot --nginx -d novelhub.example.com
```

### 10. 会话问题（无法登录）

**检查会话目录：**
```bash
# 查看 PHP 会话配置
php -i | grep session.save_path

# 确保会话目录可写
ls -ld /var/lib/php/sessions
```

**清理旧会话：**
```bash
sudo rm -rf /var/lib/php/sessions/*
```

## 错误代码参考

### PHP 错误类型

- `E_ERROR` - 致命错误，脚本停止
- `E_WARNING` - 警告，脚本继续
- `E_PARSE` - 语法错误
- `E_NOTICE` - 通知信息

### HTTP 状态码

- `500` - 内部服务器错误（PHP 错误、权限问题等）
- `503` - 服务不可用（服务器过载或维护）
- `403` - 禁止访问（权限问题）
- `404` - 页面不找到

## 获取帮助

### 诊断信息收集

运行以下命令收集诊断信息：
```bash
#!/bin/bash
echo "=== PHP Version ===" > diagnostics.txt
php -v >> diagnostics.txt
echo "" >> diagnostics.txt

echo "=== PHP Extensions ===" >> diagnostics.txt
php -m >> diagnostics.txt
echo "" >> diagnostics.txt

echo "=== Directory Permissions ===" >> diagnostics.txt
ls -la /var/www/novelhub/ | grep -E "(data|uploads|chapters)" >> diagnostics.txt
echo "" >> diagnostics.txt

echo "=== PHP Errors (last 50 lines) ===" >> diagnostics.txt
tail -50 /var/log/php_errors.log >> diagnostics.txt 2>&1
echo "" >> diagnostics.txt

echo "=== Web Server Errors (last 50 lines) ===" >> diagnostics.txt
tail -50 /var/log/apache2/error.log >> diagnostics.txt 2>&1
tail -50 /var/log/nginx/error.log >> diagnostics.txt 2>&1
```

### 联系支持

提供以下信息：
1. PHP 版本 (`php -v`)
2. Web 服务器类型和版本
3. 错误日志内容
4. 诊断页面截图
5. 问题复现步骤

## 预防措施

### 定期备份

```bash
#!/bin/bash
# 备份脚本示例
BACKUP_DIR="/backups/novelhub"
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p $BACKUP_DIR
tar -czf $BACKUP_DIR/novelhub_$DATE.tar.gz \
    /var/www/novelhub/data \
    /var/www/novelhub/uploads \
    /var/www/novelhub/chapters

# 保留最近 7 天的备份
find $BACKUP_DIR -name "novelhub_*.tar.gz" -mtime +7 -delete
```

### 监控

设置监控脚本检查应用程序健康状态：
```bash
#!/bin/bash
# health_check.sh
STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/)
if [ $STATUS -ne 200 ]; then
    echo "Website down! Status: $STATUS" | mail -s "Alert: NovelHub Down" admin@example.com
fi
```

### 更新

定期更新系统和 PHP：
```bash
sudo apt-get update
sudo apt-get upgrade
```

---

**最后更新**: 2025-10-31
