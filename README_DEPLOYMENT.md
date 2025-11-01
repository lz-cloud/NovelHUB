# NovelHub 部署指南

本文档提供 NovelHub 的详细部署说明，包括开发环境和生产环境的配置。

## 快速开始（开发环境）

### 使用 PHP 内置服务器

最简单的方式是使用 PHP 内置的开发服务器：

```bash
# 方法 1: 使用启动脚本
chmod +x start_server.sh
./start_server.sh

# 方法 2: 直接运行 PHP 命令
php -S 0.0.0.0:8000
```

然后在浏览器中访问：`http://localhost:8000`

**注意：** PHP 内置服务器仅适用于开发环境，不要在生产环境中使用。

### 系统诊断

访问 `http://your-domain/diagnostic.php` 检查系统配置是否正确。

诊断页面会检查：
- PHP 版本（需要 7.4+）
- 必需的 PHP 扩展
- 目录权限
- 文件可写性
- 核心文件加载

**安全提示：** 部署到生产环境后，请删除 `diagnostic.php`、`test.php`、`phpinfo.php` 文件。

---

## 系统要求

### 必需组件

- **PHP 7.4 或更高版本**（推荐 PHP 8.0+）
- **Web 服务器**：Apache 2.4+ 或 Nginx 1.18+
- **PHP 扩展**：
  - `json` - JSON 处理
  - `fileinfo` - 文件类型检测
  - `mbstring` - 多字节字符串处理

### 推荐组件

- `opcache` - PHP 代码缓存，提升性能
- `zip` - ZIP 文件支持（用于 EPUB 导出）

### 目录权限

以下目录需要 Web 服务器可写（通常是 `www-data` 用户）：

```bash
chmod -R 775 data/
chmod -R 775 uploads/
chmod -R 775 chapters/
chmod 664 data/*.json
```

---

## Apache 部署

### 1. 启用必需模块

```bash
sudo a2enmod rewrite
sudo a2enmod headers
sudo systemctl restart apache2
```

### 2. 配置虚拟主机

创建 `/etc/apache2/sites-available/novelhub.conf`:

```apache
<VirtualHost *:80>
    ServerName novelhub.example.com
    DocumentRoot /var/www/novelhub
    
    <Directory /var/www/novelhub>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        # PHP 配置
        php_flag display_errors off
        php_value error_reporting 0
        php_value upload_max_filesize 10M
        php_value post_max_size 10M
    </Directory>
    
    # 保护敏感文件和目录
    <Directory /var/www/novelhub/data>
        Require all denied
    </Directory>
    
    <FilesMatch "\.(json|log|md)$">
        Require all denied
    </FilesMatch>
    
    ErrorLog ${APACHE_LOG_DIR}/novelhub_error.log
    CustomLog ${APACHE_LOG_DIR}/novelhub_access.log combined
</VirtualHost>
```

### 3. 启用站点

```bash
sudo a2ensite novelhub
sudo systemctl reload apache2
```

### 4. 使用 .htaccess

项目已包含 `.htaccess` 文件，提供以下功能：
- URL 重写
- 敏感文件保护
- GZIP 压缩
- 安全头设置

确保 `AllowOverride All` 已启用。

---

## Nginx 部署

### 1. 配置服务器块

创建 `/etc/nginx/sites-available/novelhub`:

```nginx
server {
    listen 80;
    server_name novelhub.example.com;
    root /var/www/novelhub;
    index index.php index.html;
    
    # 字符集
    charset utf-8;
    
    # 日志
    access_log /var/log/nginx/novelhub_access.log;
    error_log /var/log/nginx/novelhub_error.log;
    
    # 文件上传大小
    client_max_body_size 10M;
    
    # 主要位置块
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    # PHP 处理
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;  # 根据 PHP 版本调整
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # 保护 data 目录
    location ^~ /data/ {
        deny all;
        return 404;
    }
    
    # 保护敏感文件
    location ~* \.(json|log|md)$ {
        deny all;
        return 404;
    }
    
    # 静态资源缓存
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
    
    # 禁止访问隐藏文件
    location ~ /\. {
        deny all;
        return 404;
    }
    
    # GZIP 压缩
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml text/javascript application/json application/javascript application/xml+rss;
    
    # 安全头
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
}
```

### 2. 启用站点

```bash
sudo ln -s /etc/nginx/sites-available/novelhub /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## HTTPS 配置（使用 Let's Encrypt）

### Apache

```bash
sudo apt-get install certbot python3-certbot-apache
sudo certbot --apache -d novelhub.example.com
```

### Nginx

```bash
sudo apt-get install certbot python3-certbot-nginx
sudo certbot --nginx -d novelhub.example.com
```

Certbot 会自动配置 HTTPS 并设置自动续期。

---

## 文件权限配置

### 推荐的权限设置

```bash
# 设置目录所有者
sudo chown -R www-data:www-data /var/www/novelhub

# 设置基本权限
sudo find /var/www/novelhub -type f -exec chmod 644 {} \;
sudo find /var/www/novelhub -type d -exec chmod 755 {} \;

# 设置可写目录
sudo chmod -R 775 /var/www/novelhub/data
sudo chmod -R 775 /var/www/novelhub/uploads
sudo chmod -R 775 /var/www/novelhub/chapters

# 设置可写文件
sudo chmod 664 /var/www/novelhub/data/*.json
```

### 使用 ACL（推荐）

如果系统支持 ACL，可以更精细地控制权限：

```bash
# 安装 ACL 工具
sudo apt-get install acl

# 设置 ACL
sudo setfacl -R -m u:www-data:rwx /var/www/novelhub/data
sudo setfacl -R -m u:www-data:rwx /var/www/novelhub/uploads
sudo setfacl -R -m u:www-data:rwx /var/www/novelhub/chapters

# 设置默认 ACL（新创建的文件继承权限）
sudo setfacl -R -d -m u:www-data:rwx /var/www/novelhub/data
sudo setfacl -R -d -m u:www-data:rwx /var/www/novelhub/uploads
sudo setfacl -R -d -m u:www-data:rwx /var/www/novelhub/chapters
```

---

## PHP 配置优化

### php.ini 推荐设置

```ini
# 生产环境安全设置
display_errors = Off
display_startup_errors = Off
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
log_errors = On
error_log = /var/log/php_errors.log

# 上传限制
upload_max_filesize = 10M
post_max_size = 10M
max_execution_time = 60
memory_limit = 256M

# OPcache 优化（生产环境）
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 4000
opcache.revalidate_freq = 60
opcache.fast_shutdown = 1

# 会话设置
session.cookie_httponly = 1
session.cookie_secure = 1  # 仅 HTTPS 时启用
session.use_strict_mode = 1
```

重启 PHP-FPM 应用配置：

```bash
sudo systemctl restart php8.1-fpm  # 根据版本调整
```

---

## 性能优化

### 1. 启用 OPcache

确保 `opcache` 扩展已启用并正确配置（见上文 php.ini）。

### 2. 文件缓存

NovelHub 内置文件缓存系统，缓存目录：`data/cache/`

定期清理缓存：
```bash
# 手动清理
rm -rf /var/www/novelhub/data/cache/*

# 或访问管理后台的缓存清理功能
```

### 3. 静态资源优化

- 使用 CDN 托管 Bootstrap、jQuery 等静态资源
- 启用浏览器缓存（已在 Nginx 配置中包含）
- 压缩图片文件

---

## 安全建议

### 1. 移除调试文件

```bash
rm /var/www/novelhub/diagnostic.php
rm /var/www/novelhub/test.php
rm /var/www/novelhub/phpinfo.php
```

### 2. 保护敏感目录

确保 `data/`、`.git/` 等目录无法通过 Web 访问。

### 3. 修改默认管理员密码

首次登录后，立即修改默认管理员账号密码：
- 用户名：`admin`
- 默认密码：`Admin@123`

### 4. 配置 HTTPS

生产环境必须使用 HTTPS 保护用户数据。

### 5. 定期备份

```bash
# 备份数据
tar -czf novelhub_backup_$(date +%Y%m%d).tar.gz data/ uploads/ chapters/

# 自动备份脚本（cron）
0 2 * * * /usr/local/bin/backup_novelhub.sh
```

### 6. 更新系统和 PHP

定期更新服务器软件和 PHP 版本以获取安全补丁。

---

## 故障排除

### HTTP 500 错误

1. **检查 PHP 错误日志**：
   ```bash
   tail -f /var/log/php_errors.log
   tail -f /var/log/apache2/error.log  # Apache
   tail -f /var/log/nginx/error.log    # Nginx
   ```

2. **检查文件权限**：
   确保 `data/`、`uploads/`、`chapters/` 可写。

3. **检查 PHP 版本**：
   ```bash
   php -v
   # 必须是 7.4 或更高版本
   ```

4. **检查 PHP 扩展**：
   ```bash
   php -m | grep -E 'json|fileinfo|mbstring'
   ```

5. **访问诊断页面**：
   `http://your-domain/diagnostic.php`

### 文件上传失败

1. 检查 `php.ini` 中的 `upload_max_filesize` 和 `post_max_size`
2. 检查 `uploads/` 目录权限
3. 检查 Web 服务器配置的客户端上传大小限制

### 页面空白或无响应

1. 启用 PHP 错误显示（仅调试时）：
   ```php
   // 在 config.php 顶部添加
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   ```

2. 检查 PHP-FPM 是否运行：
   ```bash
   sudo systemctl status php8.1-fpm
   ```

3. 检查日志文件查找具体错误

---

## 监控和维护

### 日志管理

定期清理和归档日志文件：

```bash
# 日志轮转配置 /etc/logrotate.d/novelhub
/var/log/nginx/novelhub*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data adm
    sharedscripts
    postrotate
        systemctl reload nginx > /dev/null
    endscript
}
```

### 性能监控

使用工具监控应用性能：
- **New Relic** - 应用性能监控
- **Prometheus + Grafana** - 系统指标监控
- **Monit** - 服务监控和自动重启

---

## 扩展和高可用

### 负载均衡

对于高流量站点，可以使用负载均衡器（如 Nginx）分发请求到多个应用服务器。

### 文件存储

默认使用本地文件存储。对于分布式部署，考虑：
- **NFS** - 网络文件系统
- **对象存储** - AWS S3、阿里云 OSS 等
- **迁移到数据库** - 使用项目内置的数据库迁移功能

### 缓存优化

- **Redis** - 用于会话和数据缓存
- **Memcached** - 内存缓存
- **CDN** - 静态资源加速

---

## 联系支持

如有问题，请：
1. 查阅 [README.md](README.md) 了解项目详情
2. 检查 [GitHub Issues](https://github.com/your-repo/issues)
3. 访问诊断页面获取系统状态

---

**最后更新**: 2025-10-31
