# 约刻 · 个人网站（juanzi.site）

手机端优先的聊天式个人网站。后端 Laravel 12 + MySQL，AI 能力由豆包（火山方舟 Ark）提供。
支持**账号体系与机构级数据隔离**：注册 / 登录后使用，同一个机构内的多个账号共享数据，不同机构之间数据完全隔离。

## 功能

- 🔐 **账号体系**：用户名+密码注册/登录/登出，开放注册；注册与登录时均从机构列表（`config/organizations.php`）选择所属机构
- 🏢 **机构隔离**：聊天记录 / 约课 / 生成图片均按机构自动写入与过滤，同机构共享、跨机构不可见；新增机构只需在配置文件加一条
- 💬 **聊天入口**：所有消息存入 MySQL，后端调用豆包返回结果推送到聊天框
- 📅 **约课 Excel**
  - 自然语言约课：`给小明约明天上午10点`、`给小明约 8月26日 14:00 1A 场地`
  - 发一张聊天截图即可自动识别其中约课信息
  - 4 个场地 `1A / 1B / 2A / 2B`，每节课 1 小时，自动检测场地冲突并返回冲突原因
  - 支持修改 / 删除 / 标记完成（`几点的课上完了`）
  - 询问：`小明什么时候上课？上了几次课？`（约课数据传给豆包回答）
  - Excel 按周分页签，随时可重新生成，防止文件过大；每次生成会提醒保存
- 🖼️ **生成图片**：上传一张照片 + 选择风格模板（图A / 图B），豆包生成固定风格成片，保存到本地并回推聊天框
  - 风格模板图在 `config/doubao.php` 与 `public/styles/` 中配置

## 目录结构

```
app/
├── Http/Controllers/
│   ├── AuthController.php     # 注册 / 登录 / 登出
│   ├── ChatController.php     # 聊天主入口（意图识别→执行→回复）
│   ├── BookingController.php  # 约课 REST 兜底接口
│   └── ExcelController.php    # Excel 生成/下载
├── Services/
│   ├── DoubaoService.php      # 豆包 API（对话/视觉/图生图）
│   ├── BookingService.php     # 约课业务（冲突检测/增删改/周分组）
│   └── ExcelService.php       # PhpSpreadsheet 按周生成 Excel
├── Models/
│   ├── User.php               # 用户（username / organization_code）
│   └── Scopes/OrganizationScope.php  # 机构隔离全局作用域（核心）
config/doubao.php              # 豆包模型、风格模板、场地规则配置
config/organizations.php       # 机构列表（新增机构=加一条）
routes/web.php                 # 登录/注册/业务页面路由
routes/api.php                 # API 路由（全部需要登录）
resources/views/               # login / register / chat / generate
public/css/chat.css            # 界面样式
public/css/auth.css            # 登录/注册页样式
public/js/chat.js              # 交互逻辑
```

## 快速开始

要求：PHP 8.2+、Composer、MySQL 5.7+/8.0

```bash
# 1. 安装依赖（若 composer 镜像不稳定可在 composer.json 里已内置官方源）
composer install

# 2. 配置 .env（已按 MySQL 写好，按需修改数据库账号密码）
#    DB_DATABASE=juanzi 等，并填入豆包配置：
#    DOUBAO_API_KEY=你的火山方舟API Key
#    可选调整：DOUBAO_CHAT_MODEL / DOUBAO_VISION_MODEL / DOUBAO_IMAGE_MODEL

# 3. 生成应用密钥 + 建库建表 + 静态软链
#    注意：升级到账号版后首次 migrate 会清空旧的聊天/约课/图片数据（存量清空重来）
php artisan key:generate
php artisan migrate
php artisan storage:link

# 4. 放入两张风格模板图
#    public/styles/style_a.jpg  (图A)
#    public/styles/style_b.jpg  (图B)

# 5. 启动
php artisan serve
# 浏览器打开 http://localhost:8000 （手机可访问局域网 IP:8000）
```

## 豆包模型配置（火山方舟 Ark）

- `DOUBAO_CHAT_MODEL`：对话/意图识别模型，默认 `doubao-seed-1-6-250615`；也可以填你在方舟控制台创建的**推理接入点** `ep-xxxxxxxx`
- `DOUBAO_VISION_MODEL`：截图识别模型，建议用带视觉能力的模型
- `DOUBAO_IMAGE_MODEL`：图生图模型，默认 `doubao-seedream-3-0-i2i-250528`（按当前模型能力可在 `.env` 中替换，如 seededit 系列）
- 图片生成请求为 `POST /images/generations`，`image` 传 `[风格模板图, 用户照片]`（本地文件自动转 base64）；若所选模型只支持单图，可在 `config/doubao.php` 的 `image` 数组里调整为只传用户照片，并把风格说明写进 prompt

## 机构配置

新增一个机构就在 `config/organizations.php` 里加一条（`code` 唯一、`name` 为展示名），注册下拉、登录下拉与顶栏展示会自动生效：

```php
return [
    'list' => [
        ['code' => 'swim', 'name' => '游泳培训班'],
        ['code' => 'ball', 'name' => '球类培训班'],
        // ['code' => 'new', 'name' => '新机构'],  // 新增机构=加一条
    ],
];
```

**登录时需要先选择所属机构**（下拉默认第一项，必选）。用户名全局唯一，登录会校验所选机构与该用户注册时所属机构是否一致，选错机构会提示「用户名、密码或机构选择不正确」，避免登录后进错工作区。

## API 一览

> 以下接口全部需要登录（未登录返回 401，前端自动跳转登录页）。

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| POST | /api/chat | 聊天（message/image/style/feature） |
| GET | /api/messages | 历史消息 |
| GET | /api/booking | 约课（按周分组） |
| POST | /api/booking | 新建约课 |
| PUT | /api/booking/{id} | 修改约课 |
| DELETE | /api/booking/{id} | 删除约课 |
| POST | /api/booking/{id}/complete | 标记完成 |
| GET | /api/excel/generate | 生成最新 Excel |
| GET | /api/excel/download/{filename} | 下载 Excel |

## 部署到 juanzi.site 提醒

- 站点根目录指向 `public/`（Nginx `root /path/juanzi/public;`），并做 `/storage` 软链
- `php artisan storage:link` 后在 Nginx 里对应 `/storage` 静态目录
- 生产环境 `APP_ENV=production`、`APP_DEBUG=false`
- 图片上传/生成文件默认存 `storage/app/public/`，定期清理 `generated/`、`uploads/` 防止磁盘膨胀
- 生成的 Excel 文件名带机构前缀（如 `swim_约课表_xxx.xlsx`），下载时校验归属，防止跨机构访问
- 建议后续版本增加：消息分页、豆包流式输出（SSE）、WebSocket 推送、机构管理员与成员管理

### 升级到账号版后页面 404（如 /login 打不开）

账号系统改动需要先部署到服务器才会生效，按以下顺序排查：

1. 确认服务器代码是最新的：本地 `git add` + `git commit` + `git push` 后到服务器拉取，或整站上传覆盖（`routes/web.php`、`bootstrap/app.php`、`app/Http/Controllers/AuthController.php`、`resources/views/login.blade.php` 等）
2. `php artisan route:list | grep login` —— 能看到 `GET|HEAD  /login` 说明路由已注册；看不到说明代码没部署对
3. `php artisan migrate` —— 新迁移会为 users 表加 `username` / `organization_code` 列，并清空存量业务数据
4. `php artisan optimize:clear` —— 清掉路由/配置缓存
5. 宝塔/nginx：伪静态选择 Laravel 模板（`try_files $uri $uri/ /index.php?$query_string;`）、站点运行目录指向 `public/`，最后重启 PHP-FPM 与 nginx
