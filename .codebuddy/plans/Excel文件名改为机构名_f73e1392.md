---
name: Excel文件名改为机构名
overview: 将约课表 Excel 下载文件名的机构前缀由 code 改为机构 name，同步调整下载时的机构隔离校验逻辑，并兼容旧文件名，保证历史消息中的旧文件仍可下载。
todos:
  - id: update-excel-filename
    content: 修改 ExcelService::generate()，文件名前缀改用机构 name 并做特殊字符 sanitize 与 code 回退
    status: completed
  - id: update-download-check
    content: 修改 ExcelController::download() 前缀校验，兼容 name 新前缀与 code 旧前缀
    status: completed
    dependencies:
      - update-excel-filename
  - id: verify-and-docs
    content: 检查 lint 无错误，整理服务器部署与验证步骤说明
    status: completed
    dependencies:
      - update-download-check
---

## 产品概述
将约课表 Excel 下载文件名中的机构标识从 code（如 `tennis_a_约课表_20260831_120000.xlsx`）改为机构名称（如 `网球馆A_约课表_20260831_120000.xlsx`），并保证改动后新文件生成、下载校验、历史消息旧文件下载均正常。

## 核心功能
- Excel 生成时按当前登录用户的机构中文名拼接文件名，机构名含路径特殊字符时自动替换为 `_`
- 下载接口的机构隔离校验同时兼容新格式（name 前缀）与旧格式（code 前缀），历史消息中的旧 Excel 卡片仍可下载
- 机构名查询不到时回退使用 code，保证生成不失败


## 技术栈
沿用现有 Laravel 项目技术栈，复用 `App\Models\Organization` 模型（无全局 scope，可直接按 code 查 name），前端零改动。

## 实现方案
### 改动 1：`app/Services/ExcelService.php`（生成侧）
`generate()` 第 53-56 行，文件名前缀由 `organization_code` 改为机构 `name`：
- 引入 `use App\Models\Organization;`
- 通过 `Organization::where('code', $orgCode)->value('name')` 取机构名，查不到回退 `$orgCode`
- 对名称做 sanitize：`preg_replace('/[\/\\\\:*?"<>|]/', '_', $orgName)`，防止 Windows/macOS 文件系统非法字符导致保存失败（当前机构名均为中文安全字符，此为防御性处理）
- 最终文件名：`{safeName}_约课表_{Ymd_His}.xlsx`，存储路径与返回结构 `['path','filename','url']` 不变

### 改动 2：`app/Http/Controllers/ExcelController.php`（下载侧）
`download()` 第 39-43 行的机构隔离校验改为"前缀集合匹配"：
- 引入 `use App\Models\Organization;`
- 构造允许前缀集合：新格式 `{safeName}_约课表_`（name 存在时）+ 旧格式 `{orgCode}_约课表_`（兼容历史文件）
- 只要文件名以集合中任一前缀开头即通过校验；两者都为空或均不匹配则 404
- 兼容原因：`storage/app/excel/` 中历史文件仍为 code 前缀命名，且历史聊天消息卡片通过 `excel_url.split('/').pop()` 还原文件名后仍走该下载接口，若不兼容旧前缀会导致历史卡片 404

### 前端
`public/js/chat.js` 的 `excelCardHTML()` / `genExcel()` 均直接使用后端返回的 `filename` 与 `url`，无需任何改动。

## 性能与可靠性
- 每次生成/下载增加一次 `organizations` 表主键索引查询，开销可忽略，无需缓存
- 校验逻辑保持"严格匹配前缀"思路，不扩大越权面：新用户依然只能下载自己机构前缀的文件

## 目录结构
```
app/
├── Services/
│   └── ExcelService.php        # [MODIFY] 文件名前缀由 code 改为机构 name（Organization 查询 + sanitize + code 回退）
└── Http/Controllers/
    └── ExcelController.php     # [MODIFY] 下载校验改为同时接受 name 前缀（新）与 code 前缀（旧），并引入 Organization 模型
```

## 关键实现示意
下载校验逻辑（`download()` 内替换原第 40-43 行）：

```php
$orgCode = auth('web')->user()?->organization_code ?? '';
$orgName = $orgCode === '' ? '' : (Organization::where('code', $orgCode)->value('name') ?? '');
$safeName = $orgName === '' ? '' : preg_replace('/[\/\\\\:*?"<>|]/', '_', $orgName);

$prefixes = array_values(array_filter([
    $safeName !== '' ? $safeName.'_约课表_' : null,
    $orgCode !== '' ? $orgCode.'_约课表_' : null,
]));

if ($orgCode === '' || $prefixes === [] || ! array_filter($prefixes, fn ($p) => str_starts_with($filename, $p))) {
    abort(404, '文件不存在或已过期，请重新生成');
}
```

## 验证
本地无 PHP 运行时，无法跑测试。改动后由用户在服务器验证：
1. 登录 → 生成 Excel → 确认文件名为机构中文名开头（如 `杭州阿蓝网球_约课表_...xlsx`）
2. 点击历史消息中的旧 Excel 卡片 → 确认仍可正常下载（旧 code 前缀兼容）
3. 尝试用另一机构账号访问该文件 URL → 应 404（隔离未失效）

