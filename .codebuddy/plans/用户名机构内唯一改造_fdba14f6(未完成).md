---
name: 用户名机构内唯一改造
overview: 将用户名唯一性从全局改为机构内唯一：数据库唯一索引改为 (organization_code, username) 复合索引，注册校验与登录匹配均按机构+用户名进行，允许不同机构使用相同用户名。
todos:
  - id: create-index-migration
    content: 新建迁移 000002：删除全局唯一索引，加 (org,username) 联合唯一索引，含存量重复检查与 down 回滚
    status: pending
  - id: update-register-validation
    content: 改造 AuthController::register 用户名校验为所选机构内唯一（Rule::unique + where）
    status: pending
    dependencies:
      - create-index-migration
  - id: update-login-matching
    content: 改造 AuthController::login 按机构+用户名定位用户，attempt 附加机构条件防跨机构同名误匹配
    status: pending
    dependencies:
      - update-register-validation
  - id: add-cross-org-tests
    content: 新增跨机构同名注册成功与登录隔离测试用例，Lint 校验并确认现有用例无回归
    status: pending
    dependencies:
      - update-login-matching
---

## 用户需求
修改项目的账号密码登录逻辑：**同一个机构下用户名唯一，不同机构可以重复**。

当前实现是"用户名全局唯一"（数据库唯一索引 + 注册校验 + 登录匹配均按全局处理），需改为"机构 + 用户名"组合唯一：

- 不同机构允许使用相同用户名（如网球馆A 与 棋院A 都可以有 `xiaoming`）
- 同一机构内用户名仍唯一（重复注册被拒绝）
- 登录时按"机构 + 用户名 + 密码"精确匹配，跨机构同名互不干扰，各自进入各自机构的工作区

## 核心功能点
- 数据库：删除 `users_username_unique` 全局唯一索引，新增 `(organization_code, username)` 联合唯一索引
- 注册：用户名唯一性校验从全局改为"当前所选机构内唯一"
- 登录：按机构 + 用户名定位用户，密码校验限定在该机构内，防止跨机构同名误匹配
- 保持现有体验：登录/注册页表单、认证码机制、错误提示风格均不变


## 技术栈
- Laravel（PHP）数据库迁移 + Eloquent，无新增依赖；沿用项目现有迁移命名规范

## 实现方案

### 1. 新建迁移 `database/migrations/2026_08_29_000002_make_username_unique_per_organization.php`
- `up()`：
  1. **存量重复检查**：统计 `(organization_code, username)` 分组计数 >1 的记录数，若存在则抛 `RuntimeException` 中止迁移（列出冲突机构/用户名），防止联合唯一索引创建失败、避免静默处理历史脏数据
  2. `Schema::table('users')`：`dropUnique('users_username_unique')` 删除全局唯一索引
  3. 新增联合唯一索引：`$table->unique(['organization_code', 'username'], 'users_org_username_unique')`
- `down()`：删除联合唯一索引，恢复全局唯一索引 `users_username_unique`（注释说明：若存在跨机构同名数据，恢复全局唯一会失败，需先清理）
- 与机构迁移 `2026_08_29_000001`（增3机构、删 tennis_b）互不冲突，独立部署

### 2. 注册校验改造（`app/Http/Controllers/AuthController.php` register）
- 第 68 行 `'unique:users,username'` 改为：
  ```php
  'username' => ['required', 'string', 'min:2', 'max:50',
      Rule::unique('users', 'username')->where('organization_code', $request->input('organization_code'))],
  ```
- 报错文案 `'该用户名已被注册，请换一个'` 保留；数据库联合唯一索引兜底并发重复注册（与现状全局唯一时行为一致，不扩大处理范围）

### 3. 登录匹配改造（login）
- 定位用户：`User::where('username', ...)->where('organization_code', ...)->first()`（第 132 行加机构条件），用户不存在或机构不匹配时提示"用户名、密码或机构选择不正确"
- `Auth::attempt` 附加机构条件，杜绝跨机构同名误匹配：
  ```php
  Auth::attempt([
      'organization_code' => $validated['organization_code'],
      'username' => $validated['username'],
      'password' => $validated['password'],
  ], (bool) $request->boolean('remember'))
  ```
- 方法注释同步更新（"用户名全局唯一" → "机构内用户名唯一"）

### 4. 测试补充（`tests/Feature/AuthOrganizationTest.php`）
- 新增：跨机构同名注册成功（tennis_a 已有 xiaoming，注册 xiaoming@tennis_b 成功并落库）
- 新增：跨机构同名登录隔离（两机构各有 xiaoming，用各自机构+密码登录各自成功；用错机构登录被拒）
- 现有用例验证：`test_register_validates_input`（同机构同名报错）、`test_login_validates_organization`（选错机构报错）在新逻辑下语义不变，应继续通过；RefreshDatabase 自动应用新迁移

### 边界与可靠性
- 存量数据检查 + 事务保证迁移安全；数据量小（机构/用户行数有限），检查为 O(n) 分组聚合，无性能问题
- 登录失败提示保持模糊（不区分"机构错/密码错"），安全性不变
- 视图、路由、认证码机制零改动

## 目录结构
```
database/migrations/
└── 2026_08_29_000002_make_username_unique_per_organization.php  # [NEW] 索引迁移：全局唯一 → (org, username) 联合唯一，含存量重复检查与回滚
app/Http/Controllers/AuthController.php                           # [MODIFY] register 校验 + login 匹配/attempt 改造
tests/Feature/AuthOrganizationTest.php                            # [MODIFY] 新增跨机构同名注册/登录用例
```

