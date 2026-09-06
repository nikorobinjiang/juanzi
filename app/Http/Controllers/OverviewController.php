<?php

namespace App\Http\Controllers;

use App\Services\OverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 数据概览：学员 / 会员 / 教练 模糊搜索与详情（只读）
 */
class OverviewController extends Controller
{
    public function __construct(private readonly OverviewService $overview) {}

    /**
     * 统一搜索：?q=关键词&scope=all|students|members|coaches
     */
    public function search(Request $request): JsonResponse
    {
        $q = mb_substr(trim((string) $request->input('q', '')), 0, 50);
        $scope = (string) $request->input('scope', OverviewService::SCOPE_ALL);

        if (! in_array($scope, OverviewService::SCOPES, true)) {
            $scope = OverviewService::SCOPE_ALL;
        }

        return response()->json($this->overview->search($q, $scope));
    }

    /**
     * 学员详情（档案 + 统计 + 最近约课）
     */
    public function student(int $id): JsonResponse
    {
        $detail = $this->overview->studentDetail($id);

        return $detail
            ? response()->json(['student' => $detail])
            : response()->json(['error' => '学员不存在'], 404);
    }

    /**
     * 会员详情（按姓名取名下全部卡）
     */
    public function member(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return response()->json(['error' => '缺少会员姓名'], 422);
        }

        $detail = $this->overview->memberDetail($name);

        return $detail
            ? response()->json(['member' => $detail])
            : response()->json(['error' => '会员不存在'], 404);
    }
}
