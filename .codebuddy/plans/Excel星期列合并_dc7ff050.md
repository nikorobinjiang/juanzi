---
name: Excel星期列合并
overview: 修复约课表 Excel 周报表排序 bug，并实现"星期"列按同一天合并单元格：改为按时间排序（同一天相邻），合并后星期只在当日首行显示、垂直跨行居中。
todos:
  - id: fix-weekly-sort
    content: 修复 BookingService::weekly() 排序，删除按场馆 sortBy，仅按日期时间排序
    status: completed
  - id: merge-weekday-cells
    content: 在 ExcelService::fillWeekSheet() 中按日期分组合并 B 列星期单元格，合并置于样式设置前
    status: completed
    dependencies:
      - fix-weekly-sort
  - id: verify-excel-output
    content: 验证：生成 Excel 检查星期列合并效果与排序结果，确认无样式/数据回归
    status: completed
    dependencies:
      - merge-weekday-cells
---

## 用户需求
约课表 Excel（按周分 Sheet）中"星期几"列（B 列）按同一天合并单元格：同一天的约课只在首行显示"周X"，其余行的星期单元格纵向合并为一块。

用户已确认排序方案（方案 A）：**改为按日期+时间排序**。当前 Excel 内记录按场馆分组显示（同一天记录被场馆隔开，直接合并会碎片化），改排序后同一天必然相邻，合并干净整洁；场馆列（D 列）仍保留，仅展示顺序变化。

## 功能要点
- Excel B 列（星期）对同一天的连续行执行合并单元格
- 合并后仅首行保留"周X"文字，其余行合并显示
- 仅合并 B 列；A 列日期、C-H 列内容每行照常保留
- 单日仅一条记录时不合并；空周 Sheet 不合并
- 周末日期标红、边框、列宽等现有样式与逻辑保持不变


## 技术栈
- Laravel（PHP）+ PhpSpreadsheet（项目现有依赖，无需新增）

## 实现方案

### 1. 修复排序（`app/Services/BookingService.php` 第 443-448 行）
当前 `weekly()` 内 `items` 排序存在 bug：第二个 `sortBy`（按场馆顺序）覆盖第一个 `sortBy`（按时间），导致同一天记录分散。修改为只按时间排序：

```php
$week['items'] = $week['items']
    ->sortBy(fn (BookingRecord $b) => $b->start_at->format('Y-m-d H:i'));
```

删除按场馆的 `sortBy` 即可（`venues` 顺序排序不再需要）。此修改会同步影响 `BookingController::index()` 返回的前端周报表展示顺序（用户已接受）；`ChatController::bookingSummary()` 只取 `label`，不受影响。

### 2. 合并星期列（`app/Services/ExcelService.php` `fillWeekSheet()` 第 97-137 行）
在逐行写入数据的 `each` 循环中，额外记录每个日期对应的行号（`$dateRows[$date][] = $row`）；循环结束后、**在设置全局边框样式（第 148 行）之前**，遍历 `$dateRows`：

- 同一天行数 >1 时执行 `$sheet->mergeCells('B'.$first.':B'.$last)`
- 合并后 B 列仅左上角单元格保留星期值（PhpSpreadsheet `mergeCells` 默认行为），其余行自动空白
- 合并时机放在边框/对齐样式设置之前，确保合并区域外边框完整显示（现有 `A2:H$lastRow` 的 allBorders 样式覆盖合并区域）

### 3. 边界与性能
- 空表（`$items->isEmpty()` 分支）不参与合并
- 数据量小（每 Sheet 一周约课，几十行内），按日期哈希分组 O(n)，无性能问题
- 不引入新依赖、不改数据结构、不动其他 Sheet 逻辑

