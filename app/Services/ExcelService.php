<?php

namespace App\Services;

use App\Models\BookingRecord;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 约课 Excel 生成：按周分页签，每周一个 Sheet
 */
class ExcelService
{
    private const HEADERS = ['日期', '星期', '时间', '场地', '学员', '教练', '状态', '备注'];

    private const WEEKDAY_CN = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];

    /**
     * 生成 Excel 文件
     *
     * @return array ['path' => 绝对路径, 'filename' => 下载文件名, 'url' => 下载URL]
     */
    public function generate(): array
    {
        $weekly = app(BookingService::class)->weekly();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0); // 去掉默认空表

        if ($weekly->isEmpty()) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('约课记录');
            $sheet->setCellValue('A1', '暂无约课记录');
            $sheet->getColumnDimension('A')->setWidth(30);
        } else {
            $weekly->each(function (array $week, int $index) use ($spreadsheet) {
                $sheet = $spreadsheet->createSheet();
                $this->fillWeekSheet($sheet, $week, $index);
            });
        }

        // 默认显示第一张表
        $spreadsheet->setActiveSheetIndex(0);

        $dir = storage_path('app/excel');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // 文件名带机构前缀，下载时按前缀校验归属，防止跨机构访问
        $orgCode = auth('web')->user()?->organization_code ?? 'guest';
        $filename = $orgCode.'_约课表_'.now()->format('Ymd_His').'.xlsx';
        $path = $dir.DIRECTORY_SEPARATOR.$filename;

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return [
            'path' => $path,
            'filename' => $filename,
            'url' => '/api/excel/download/'.$filename,
        ];
    }

    /**
     * 填充一周的 Sheet
     */
    private function fillWeekSheet($sheet, array $week, int $index): void
    {
        $sheet->setTitle($this->sheetTitle($week['label'], $index));

        // 标题行
        $sheet->setCellValue('A1', '好运爆棚 · 约课表（'.$week['label'].'）');
        $sheet->mergeCells('A1:H1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '1A1A2E']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // 表头行
        foreach (self::HEADERS as $i => $header) {
            $cell = $this->colName($i + 1).'2';
            $sheet->setCellValue($cell, $header);
        }

        $sheet->getStyle('A2:H2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4C6EF5']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(24);

        $row = 3;
        $items = $week['items'];

        if ($items->isEmpty()) {
            $sheet->setCellValue('A'.$row, '本周暂无约课');
            $sheet->mergeCells('A'.$row.':H'.$row);
            $sheet->getStyle('A'.$row)->getFont()->setItalic(true)->getColor()->setARGB('888888');
        }

        $statusFontColors = [
            BookingRecord::STATUS_COMPLETED => '2F9E44', // 上完：绿色字体
            BookingRecord::STATUS_BOOKED => 'E8590C',    // 未上完：橙色字体
            BookingRecord::STATUS_CANCELLED => '868E96', // 取消：灰色字体
        ];

        $dateRows = []; // 记录每个日期对应的行号，用于星期列合并
        $items->each(function (BookingRecord $b) use ($sheet, &$row, &$dateRows, $statusFontColors) {
            $sheet->setCellValue('A'.$row, $b->start_at->format('Y-m-d'));
            $sheet->setCellValue('B'.$row, self::WEEKDAY_CN[$b->start_at->dayOfWeek] ?? '');
            $sheet->setCellValue('C'.$row, $b->start_at->format('H:i').'-'.$b->end_at->format('H:i'));
            $sheet->setCellValue('D'.$row, $b->venue);
            $sheet->setCellValue('E'.$row, $b->student_name);
            $sheet->setCellValue('F'.$row, $b->coach_name);
            $sheet->setCellValue('G'.$row, $b->status_label);
            $sheet->setCellValue('H'.$row, $b->remark);

            // 状态列字体颜色：上完绿色 / 未上完橙色 / 取消灰色
            if (isset($statusFontColors[$b->status])) {
                $sheet->getStyle('G'.$row)->getFont()
                    ->getColor()->setARGB($statusFontColors[$b->status]);
            }

            // 周末日期标红
            if (in_array($b->start_at->dayOfWeek, [0, 6], true)) {
                $sheet->getStyle('A'.$row)->getFont()->getColor()->setARGB('E03131');
            }

            // 行距：数据行统一加高，阅读更舒适
            $sheet->getRowDimension($row)->setRowHeight(28);

            $dateRows[$b->start_at->format('Y-m-d')][] = $row;
            $row++;
        });

        // 星期列按同一天合并单元格（仅合并 B 列，其余列逐行保留）
        $mergedWeekdayRanges = [];
        foreach ($dateRows as $rows) {
            if (count($rows) > 1) {
                $first = min($rows);
                $last = max($rows);
                $sheet->mergeCells('B'.$first.':B'.$last);
                $mergedWeekdayRanges[] = [$first, $last];
            }
        }

        $lastRow = max($row - 1, 3);

        // 列宽
        $widths = [12, 8, 15, 8, 12, 12, 10, 30];
        foreach ($widths as $i => $w) {
            $sheet->getColumnDimension($this->colName($i + 1))->setWidth($w);
        }

        // 边框 + 数据对齐
        $sheet->getStyle('A2:H'.$lastRow)->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9D9E3']],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // 合并区域内部清除上下边框，避免合并后的星期列残留内部横线
        foreach ($mergedWeekdayRanges as [$first, $last]) {
            for ($r = $first + 1; $r < $last; $r++) {
                $sheet->getStyle('B'.$r)->applyFromArray([
                    'borders' => [
                        'top' => ['borderStyle' => Border::BORDER_NONE],
                        'bottom' => ['borderStyle' => Border::BORDER_NONE],
                    ],
                ]);
            }
        }

        // 备注列左对齐
        $sheet->getStyle('H3:H'.$lastRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle('A2:H'.$lastRow)->getAlignment()->setWrapText(true);

        $sheet->freezePane('A3');
    }

    private function sheetTitle(string $label, int $index): string
    {
        // Excel 表名最多 31 字符，且不能含 \ / ? * : [ ]
        $title = preg_replace('/[\\\\\/\?\*\[\]:]/', '-', $label);

        return mb_substr($title, 0, 31);
    }

    private function colName(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }
}
