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
- 通过遍历 `novels.json` 实现按标题和作者名的搜索
- 可扩展：根据分类、标签或状态进行筛选

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

1) 目录写权限
- 确保 Web 进程对以下目录有读写权限：
  - `data/`
  - `uploads/`（含子目录 `avatars/`、`covers/`）
  - `chapters/`

2) 配置文件
- `config.php` 中定义了数据与存储路径常量，按需调整为绝对路径：
  - `DATA_DIR`、`UPLOADS_DIR`、`COVERS_DIR`、`AVATARS_DIR`、`CHAPTERS_DIR`
- 首次运行时会自动创建必要目录

3) Web 服务器
- Apache：将此项目目录设为站点根目录（或将 DocumentRoot 指向该目录）
- Nginx：FastCGI 转发到 PHP-FPM，根目录指向本项目目录

4) 初始数据
- 项目已包含空的 `users.json`、`novels.json`、`user_bookshelves.json`，以及示例 `categories.json`

5) 安全建议（可选增强）
- 结合 CSRF Token
- 上传文件类型/大小校验与图像处理
- 更细粒度的权限/日志

---

## 5. 页面速览

- 首页：`/index.php`
- 注册：`/register.php`，登录：`/login.php`，退出：`/logout.php`
- 仪表盘：`/dashboard.php`
- 创建作品：`/create_novel.php`
- 发布/编辑章节：`/publish_chapter.php?novel_id=... [&chapter_id=...]`
- 阅读：`/reading.php?novel_id=...&chapter_id=...`
- 书架：`/shelf.php`
- 个人资料：`/profile.php`
- 管理后台：`/admin.php`（管理员可见）

---

如需扩展为 OAuth 登录、富文本编辑器、全文搜索、统计报表等功能，可在现有文件存储基础上逐步演进，或在后续阶段迁移到数据库。
