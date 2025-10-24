# NovelHub 功能更新说明

本次更新完善了书架、书签、作者简介以及书籍下载功能，并新增了 Plus 会员系统。

## 主要功能更新

### 1. 书架功能完善 ✅
- **现有功能**：用户可以添加/移除小说到书架
- **位置**：`/shelf.php` 和 `/profile.php?tab=bookshelf`
- **分类管理**：在个人中心的书架标签中，可以创建自定义分类并批量管理书籍
- **功能**：
  - 收藏/取消收藏小说
  - 创建自定义书架分类
  - 批量移动/删除书架中的小说
  - 查看收藏作品的阅读进度

### 2. 书签功能 ✅
- **现有功能**：在阅读页面已完全实现书签功能
- **位置**：`/reading.php` 阅读页面
- **功能**：
  - 在任意章节位置添加书签
  - 书签包含章节ID、页码和笔记
  - 查看和管理所有书签
  - 快速跳转到书签位置
- **API 端点**：
  - `GET /reading.php?action=list_bookmarks` - 获取书签列表
  - `POST /reading.php?action=add_bookmark` - 添加书签
  - `POST /reading.php?action=delete_bookmark` - 删除书签

### 3. 作者简介 ✅
- **位置**：
  - 个人资料编辑：`/profile.php?tab=edit`
  - 作品详情页展示：`/novel_detail.php`
- **功能**：
  - 作者可以在个人资料中编辑简介
  - 简介会显示在该作者的所有作品详情页
  - 包含作者头像、昵称和简介文字
- **数据存储**：保存在 `users.json` 的 `profile.bio` 字段

### 4. 书籍下载功能完善 ✅

#### 4.1 下载限制系统
- **普通用户**：每天最多下载 3 次（任何格式）
- **Plus 会员**：无限次数下载
- **限制周期**：每天 00:00 重置

#### 4.2 支持格式
- TXT - 纯文本格式
- EPUB - 电子书标准格式
- PDF - 便携文档格式

#### 4.3 下载追踪
- 记录每次下载的用户、作品、格式和时间
- 管理员可查看下载统计数据
- 数据存储在 `data/downloads.json`

#### 4.4 用户体验优化
- 下载前检查权限
- 达到限制时显示友好提示
- 引导用户升级 Plus 会员
- 实时显示今日下载次数

### 5. Plus 会员系统 ✅

#### 5.1 会员特权
- ✨ 无限次数下载书籍
- 📚 支持所有导出格式（TXT/EPUB/PDF）
- 🎯 无广告体验（预留功能）
- 🌟 专属会员标识
- 🚀 优先获得新功能

#### 5.2 兑换码系统
- 管理员可生成兑换码
- 支持设置会员时长（天数）
- 支持设置最大使用次数
- 支持设置兑换码有效期
- 兑换码不区分大小写
- 会员时长可叠加

#### 5.3 会员管理
- **用户端**：`/plans.php`
  - 查看当前会员状态
  - 输入兑换码激活会员
  - 查看会员到期时间
  - 了解会员权益
- **管理端**：`/admin_membership.php`
  - 生成兑换码
  - 查看所有兑换码状态
  - 禁用兑换码
  - 手动延长用户会员
  - 查看会员列表
  - 查看下载统计

### 6. 管理后台增强 ✅
- 新增"会员管理"标签页
- 兑换码生成与管理
- Plus 会员列表查看
- 下载统计数据：
  - 总下载次数
  - 今日下载次数
  - 按格式统计
  - 热门下载作品 TOP 10

## 数据文件说明

### 新增数据文件
```
data/
├── downloads.json              # 下载记录
├── plus_memberships.json       # Plus会员数据
└── redemption_codes.json       # 兑换码数据
```

### 数据结构

#### downloads.json
```json
[
  {
    "id": 1,
    "user_id": 2,
    "novel_id": 10,
    "format": "epub",
    "downloaded_at": "2025-01-10T14:30:00+08:00"
  }
]
```

#### plus_memberships.json
```json
[
  {
    "user_id": 2,
    "expires_at": "2025-02-10T12:00:00+08:00",
    "created_at": "2025-01-10T12:00:00+08:00",
    "updated_at": "2025-01-10T12:00:00+08:00"
  }
]
```

#### redemption_codes.json
```json
[
  {
    "id": 1,
    "code": "A1B2C3D4",
    "duration_days": 30,
    "max_uses": 1,
    "used_count": 0,
    "status": "active",
    "expires_at": null,
    "created_at": "2025-01-10T10:00:00+08:00",
    "last_used_at": null,
    "last_used_by": null
  }
]
```

## 新增库文件

### lib/Membership.php
提供会员管理和下载管理的核心功能：

**Membership 类**：
- `isPlusUser(userId)` - 检查用户是否为 Plus 会员
- `getUserMembership(userId)` - 获取用户会员信息
- `redeemCode(userId, code)` - 兑换会员码
- `generateCode(durationDays, maxUses, expiresAt)` - 生成兑换码
- `getAllCodes()` - 获取所有兑换码
- `disableCode(codeId)` - 禁用兑换码

**DownloadManager 类**：
- `canDownload(userId)` - 检查用户是否可以下载
- `recordDownload(userId, novelId, format)` - 记录下载
- `getUserDownloads(userId, limit)` - 获取用户下载历史
- `getDownloadStats()` - 获取下载统计数据

## 使用说明

### 管理员操作流程

1. **生成兑换码**
   - 访问 `/admin_membership.php`
   - 点击"兑换码管理"标签
   - 填写会员时长和使用次数
   - 点击"生成"按钮
   - 复制生成的兑换码分发给用户

2. **查看下载统计**
   - 访问 `/admin_membership.php?tab=downloads`
   - 查看总下载次数、今日下载
   - 查看各格式下载分布
   - 查看热门下载作品

3. **手动延长会员**
   - 访问 `/admin_membership.php?tab=memberships`
   - 输入用户 ID 和延长天数
   - 点击"延长会员"

### 用户操作流程

1. **兑换 Plus 会员**
   - 访问 `/plans.php`
   - 在"兑换 Plus 会员"区域输入兑换码
   - 点击"立即兑换"

2. **下载书籍**
   - 访问任意作品详情页
   - 点击下载选项（TXT/EPUB/PDF）
   - 系统自动检查下载权限
   - 如果超限，引导升级会员

3. **查看会员状态**
   - 首页导航栏显示"PLUS"徽章（如果是会员）
   - 访问 `/plans.php` 查看详细状态
   - 查看到期时间和当日下载次数

## 技术实现要点

- **并发安全**：所有文件操作使用 DataManager 的锁机制
- **性能优化**：下载检查只读取必要数据，避免全量扫描
- **用户体验**：友好的错误提示和引导
- **扩展性**：会员系统设计支持未来添加更多特权

## 文件修改列表

### 新增文件
- `/lib/Membership.php` - 会员和下载管理类
- `/plans.php` - 会员计划页面
- `/admin_membership.php` - 会员管理后台

### 修改文件
- `/config.php` - 添加新数据文件常量
- `/lib/helpers.php` - 添加用户扩展数据函数
- `/novel_detail.php` - 集成下载限制和作者简介
- `/index.php` - 添加 Plus 徽章显示
- `/profile.php` - 移除重复函数定义
- `/admin_dashboard.php` - 添加会员管理入口

## 测试建议

1. 创建测试用户账号
2. 以管理员身份生成兑换码
3. 测试兑换码兑换流程
4. 测试普通用户下载限制（3次/天）
5. 测试 Plus 会员无限下载
6. 测试会员到期后权限恢复
7. 测试兑换码叠加功能
8. 测试管理员统计数据准确性

## 注意事项

- 兑换码一旦生成无法修改，只能禁用
- 会员时长可以叠加，在当前到期时间基础上增加
- 下载次数每天 00:00 重置（基于服务器时区）
- 管理员角色可以访问所有管理功能
- 确保 `data/` 目录有写入权限

## 后续优化建议

1. 添加会员购买支付接口
2. 实现批量生成兑换码功能
3. 添加会员续费提醒
4. 优化下载统计图表展示
5. 添加用户下载历史查询
6. 实现会员等级制度
7. 添加会员专属内容功能
