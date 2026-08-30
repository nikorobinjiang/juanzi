<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\ExcelService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExcelController extends Controller
{
    public function __construct(private readonly ExcelService $excel) {}

    /**
     * 立即生成最新约课 Excel，返回下载链接
     */
    public function generate(): JsonResponse
    {
        try {
            $result = $this->excel->generate();

            return response()->json([
                'message' => 'Excel 已生成，请尽快下载保存（可随时重新生成）。',
                'excel' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Excel 生成失败：'.$e->getMessage()], 500);
        }
    }

    /**
     * 下载已生成的 Excel
     */
    public function download(string $filename): BinaryFileResponse
    {
        // 防路径穿越
        $filename = basename($filename);
        $path = storage_path('app/excel/'.$filename);

        // 机构隔离：文件名必须以当前机构的 name 或 code 前缀开头，防止跨机构下载
        // 新文件为 name 前缀；旧文件为 code 前缀，两者均兼容
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

        if (! is_file($path)) {
            abort(404, '文件不存在或已过期，请重新生成');
        }

        return response()->download($path, $filename);
    }
}
