# NovelHub（基于 PHP 与文件存储的小说阅读平台）

本项目是一个使用原生 PHP 和文件系统（JSON）作为数据存储的在线小说创作与阅读平台示例，实现了注册登录、作品与章节管理、阅读、书架与简单管理员后台等核心功能。

- 后端：原生 PHP >= 7.4
- 存储：JSON 文件 + 章节内容文件
- 前端：原生 HTML、Bootstrap 5
- 会话：PHP 内置 Session

演示入口：`index.php`

---

## 1. 文件存储结构设计

项目根目录结构（关键部分）：

```
.
├── admin.php                # 管理员后台（用户/小说/分类管理）
├── config.php               # 配置文件：数据与上传路径等
├── create_novel.php         # 创建小说
├── dashboard.php            # 作者仪表盘（作品管理）
├── index.php                # 首页/发现/搜索
├── lib/
│   ├── DataManager.php      # JSON 读写 + 加锁封装
│   └── helpers.php          # 会话、鉴权、工具函数
├── login.php                # 登录
├── logout.php               # 退出
├── profile.php              # 用户资料编辑（头像/昵称/简介）
├── publish_chapter.php      # 发布/编辑章节（支持草稿）
├── reading.php              # 阅读页面
├── register.php             # 注册
├── shelf.php                # 书架（收藏）
├── chapters/                # 章节目录（每部小说一个子目录）
│   └── novel_{novel_id}/
│       └── {chapter_id}.json
├── data/                    # JSON 数据文件目录
│   ├── categories.json      # 分类列表
│   ├── novels.json          # 小说元数据
│   ├── user_bookshelves.json# 用户书架（收藏关系列表）
│   └── users.json           # 用户信息
└── uploads/                 # 上传目录（头像、封面）
    ├── avatars/
    └── covers/
```

核心数据文件 JSON 结构示例：

- users.json（数组）
```json
[
  {
    "id": 1,
    "username": "alice",
    "email": "alice@example.com",
    "password_hash": "$2y$...",
    "role": "user",         // 或 admin
    "status": "active",     // 或 disabled
    "created_at": "2025-01-01T12:00:00+08:00",
    "profile": {
      "nickname": "Alice",
      "avatar": "a1b2c3.png",
      "bio": "作家简介..."
    }
  }
]
```

- novels.json（数组）
```json
[
  {
    "id": 10,
    "title": "星际远行",
    "author_id": 1,
    "cover_image": "cover_xyz.jpg",
    "category_ids": [1,2],
    "tags": ["太空", "冒险"],
    "status": "ongoing",      // ongoing/completed
    "description": "简介...",
    "created_at": "2025-01-05T10:00:00+08:00",
    "updated_at": "2025-01-06T09:00:00+08:00",
    "stats": {"views":0, "favorites":0},
    "last_chapter_id": 3
  }
]
```

- chapters/{novel_id}/{chapter_id}.json
```json
{
  "id": 1,
  "novel_id": 10,
  "title": "第一章",
  "content": "章节正文（纯文本/可视为 Markdown）",
  "status": "published",     // published/draft
  "created_at": "2025-01-05T10:15:00+08:00",
  "updated_at": "2025-01-05T10:15:00+08:00"
}
```

- categories.json（数组）
```json
[
  {"id":1, "name":"Fantasy", "slug":"fantasy", "created_at":""},
  {"id":2, "name":"Sci-Fi",  "slug":"sci-fi",  "created_at":""}
]
```

- user_bookshelves.json（数组，收藏关系）
```json
[
  {"user_id":1, "novel_id":10, "added_at":"2025-01-06T12:00:00+08:00"}
]
```

章节存储：
- 路径：`/chapters/novel_{novel_id}/{chapter_id}.json`
- 每部小说一个子目录，按章 ID 存一文件。

---

## 2. 核心逻辑流程

注册：
1. 表单提交用户名/邮箱/密码等
2. 读取 `users.json`（加锁共享读）验证唯一性
3. 使用 `password_hash()` 生成密码哈希
4. 通过 DataManager::appendWithId()（排它锁，原子递增 id）将新用户写入 `users.json`
5. 设置会话，跳转仪表盘

发布新章节：
1. 作者进入 `publish_chapter.php?novel_id=...`
2. 校验作者身份（作者本人或管理员）
3. 计算 `next_chapter_id(novel_id)`，生成章节 JSON，写入 `chapters/novel_{id}/{chapter_id}.json`
4. 更新 `novels.json` 中对应作品的 `updated_at` 与 `last_chapter_id`

搜索与筛选：
- 首页支持按标题与作者名关键字搜索
- 支持分类筛选与状态筛选（连载中/已完结）
- 支持排序：按最近更新、按创建时间

书架：
- `shelf.php?action=add&novel_id=...` 将关系行追加到 `user_bookshelves.json`
- `shelf.php` 展示当前用户的收藏列表

管理员：
- 用户管理：禁用/启用账号、提升为管理员（直接更新 `users.json` 记录）
- 小说管理：删除小说（包含对应章节目录）
- 分类管理：新增/删除分类（`categories.json`）

---

## 3. 核心代码实现（节选）

- DataManager（`lib/DataManager.php`）
  - `readJson($file)`：共享锁读取 JSON
  - `writeJson($file, $data)`：写入临时文件后原子替换
  - `appendWithId($file, $item)`：排它锁下读取-自增-写回（避免并发冲突）
  - `updateById($file, $id, $data)`：按 ID 更新
  - `deleteById($file, $id)`：按 ID 删除

- 注册/登录（`register.php` / `login.php`）
  - `password_hash()` / `password_verify()`
  - `$_SESSION` 管理登录状态

- 作品与章节（`create_novel.php` / `publish_chapter.php`）
  - 上传封面：`handle_upload($_FILES['cover'], COVERS_DIR)`
  - 章节存储：见上文 chapters 结构

- 权限判断（`lib/helpers.php`）
  - `require_login()`、`is_admin()`

- 阅读页（`reading.php`）
  - 清爽排版，显示上一章/下一章导航

- 作者仪表盘（`dashboard.php`）
  - 罗列当前用户的作品与章节，入口：发布/编辑章节

---

## 4. 部署与配置指南

### 快速开始（开发环境）

使用 PHP 内置服务器：
```bash
chmod +x start_server.sh
./start_server.sh
# 或直接运行 PHP 内置服务器
php -S 0.0.0.0:8000
```

### 系统要求

- PHP 7.4 或更高版本（推荐 PHP 8.0+）
- PHP 扩展：json、fileinfo、mbstring
- Web 服务器：Apache 2.4+ 或 Nginx 1.18+

### 系统诊断

访问 `http://your-domain/diagnostic.php` 检查系统状态（生产环境请删除此文件）。

### 部署步骤

1) 目录写权限
```bash
chmod -R 775 data/ uploads/ chapters/
chmod 664 data/*.json
```

2) 配置文件
- `config.php` 会自动创建必要目录
- 生产环境请关闭错误显示

3) Web 服务器
- Apache：已包含 `.htaccess`，需启用 mod_rewrite
- Nginx：参考 `README_DEPLOYMENT.md` 配置示例

4) 初始管理员
- 用户名：`admin`
- 密码：`Admin@123`
- 登录后请立即修改密码

5) 故障排除
- 遇到 HTTP 500 错误？参考 `TROUBLESHOOTING.md`
- 检查 PHP 版本：`php -v`

6) 安全建议
- 启用 HTTPS 并更新证书
- 删除 `diagnostic.php`、`test.php`、`phpinfo.php`
- 定期备份 `data/`、`uploads/`、`chapters/`

### 相关文档

- `README_DEPLOYMENT.md` - 详细部署指南
- `TROUBLESHOOTING.md` - 故障排除
- `FEATURES_UPDATE.md` - 功能更新记录

---

## 5. 页面速览

- 首页：`/index.php`
- 注册：`/register.php`，登录：`/login.php`，退出：`/logout.php`
- 仪表盘：`/dashboard.php`
- 创建作品：`/create_novel.php`
- 发布/编辑章节：`/publish_chapter.php?novel_id=... [&chapter_id=...]`
- 阅读：`/reading.php?novel_id=...&chapter_id=...`
- 书架：`/shelf.php`
- 个人中心：`/profile.php`（统计、成就、时间轴、通知、书架增强）
- 书籍详情：`/novel_detail.php`（Z-Library 风格布局、评分评论、推荐、下载导出）
- 管理仪表盘：`/admin_dashboard.php`（数据概览、内容管理、用户权限、系统管理）
- 传统管理后台：`/admin.php`

---

## 6. 数据结构（新增/扩展）

增强后的文件存储结构：

```
data/
├── users/
│   ├── {user_id}.json           # 用户扩展数据（如书架分类）
│   └── achievements/            # 预留：用户成就持久化
├── novels/
│   ├── metadata/                # 预留：每本书独立元数据
│   └── reviews/                 # 书评数据（每本一本文件）
├── system/
│   ├── categories.json          # 分类
│   ├── settings.json            # 系统设置
│   ├── notifications.json       # 通知中心（全量）
│   └── statistics.json          # 平台统计缓存
├── admin/
│   ├── audit_log.json           # 审核日志（预留）
│   └── operations.json          # 管理操作日志
└── cache/                       # 统计缓存
```

关键 JSON 结构定义：

- system/notifications.json（数组）
```json
[
  {
    "id": 1,
    "user_id": 2,
    "type": "system|interaction|update",
    "title": "收到新的评分与评论",
    "message": "你的作品《xxx》收到一条新评论。",
    "link": "/novel_detail.php?novel_id=10#reviews",
    "read": false,
    "created_at": "2025-01-06T12:00:00+08:00"
  }
]
```

- novels/reviews/{novel_id}.json（数组）
```json
[
  {
    "id": 1,
    "novel_id": 10,
    "user_id": 2,
    "rating": 5,
    "content": "<p>很好看！</p>",
    "likes": 0,
    "parent_id": null,
    "created_at": "2025-01-06T12:00:00+08:00"
  }
]
```

- users/{id}.json（对象，扩展字段）
```json
{
  "shelf_categories": ["默认", "正在阅读", "想读", "已读完"]
}
```

- admin/operations.json（数组）
```json
[
  {"id":1, "user_id":1, "username":"admin", "role":"super_admin", "action":"update_settings", "meta":{}, "ip":"127.0.0.1", "ua":"...", "created_at":"2025-01-06T12:00:00+08:00"}
]
```

- data/user_bookshelves.json（数组，扩展）
```json
[
  {"user_id": 2, "novel_id": 10, "category": "默认", "added_at": "2025-01-06T12:00:00+08:00"}
]
```

数据关联关系：
- 用户（users.json.id）1—N 作品（novels.json.author_id）
- 作品（novels.json.id）1—N 章节（/chapters/novel_{id}/{chapter_id}.json）
- 作品（id）1—N 评论（/data/novels/reviews/{id}.json）
- 用户（id）N—N 收藏作品（data/user_bookshelves.json）
- 用户（id）1—N 阅读进度（data/reading_progress.json）

---

## 7. 关键实现说明

- 数据统计（lib/Statistics.php）
  - computeUserStats：统计创作（作品/字数/章节）、阅读（估算时长、读完数量、书架）、互动（获得收藏/评论）
  - computeNovelStats：评分、评论、章节数、最后更新、收藏、推荐指数
  - computePlatformOverview：用户/作品/章节/收藏/阅读等全局统计
  - buildUserTimeline：聚合阅读进度、发布新章、收藏等动态

- 权限系统（lib/Auth.php）
  - 角色：super_admin、content_admin（兼容 legacy admin）、user
  - requireRole：用于后台入口拦截
  - OperationLog：管理操作写入 data/admin/operations.json

- 文件导出（lib/Export.php）
  - exportTXT/exportEPUB/exportPDF：生成至 uploads/exports/
  - EPUB 使用 ZipArchive 构建标准目录
  - PDF 使用极简文本 PDF 生成器（单页、Helvetica，适合纯文本导出）

- 缓存机制（lib/Cache.php）
  - 文件缓存（data/cache），提供 get/set/remember/clear

- 响应式样式（assets/style.css + 各页内联）
  - 使用 Bootstrap 5 的栅格系统
  - 自定义 zlib-layout（详情页）、timeline（时间轴）等组件样式

---

如需扩展为 OAuth 登录、富文本编辑器、全文搜索、更多维度的统计报表等功能，可在现有文件存储基础上逐步演进，或在后续阶段迁移到数据库。
