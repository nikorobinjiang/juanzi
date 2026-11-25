<?php

namespace App\Http\Controllers;

use App\Models\GroupCourse;
use App\Models\User;
use App\Services\GroupCourseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MiniAppActivityController extends Controller
{
    /** 机构人员的活动列表 */
    public function index(Request $request, GroupCourseService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $service->listForStaff($user)]);
    }

    /** 公开活动详情：小程序卡片、海报扫码、H5 共用 */
    public function show(string $shareToken, GroupCourseService $service): JsonResponse
    {
        return response()->json(['data' => $service->detailByToken($shareToken)]);
    }

    /** 公开小程序码：客户生成海报时用，不需要登录 */
    public function publicQrcode(string $shareToken, GroupCourseService $service): JsonResponse
    {
        $course = GroupCourse::where('share_token', $shareToken)->firstOrFail();

        try {
            $url = $service->qrcodeUrl($course);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['data' => ['url' => $url]]);
    }

    /** 公开小程序 Scheme：H5 在微信外打开时用 */
    public function publicScheme(string $shareToken, GroupCourseService $service): JsonResponse
    {
        $course = GroupCourse::where('share_token', $shareToken)->firstOrFail();
        $scheme = $service->schemeUrl($course);

        if (! $scheme) {
            return response()->json(['message' => '暂时无法生成小程序链接，请稍后重试'], 503);
        }

        return response()->json(['data' => ['scheme' => $scheme]]);
    }

    public function store(Request $request, GroupCourseService $service): JsonResponse
    {
        $data = $this->validateActivity($request);

        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $service->create($data, $user),
        ], 201);
    }

    public function update(Request $request, GroupCourse $activity, GroupCourseService $service): JsonResponse
    {
        $data = $this->validateActivity($request);

        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $service->update($activity, $data, $user),
        ]);
    }

    public function publish(Request $request, GroupCourse $activity, GroupCourseService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $service->setStatus($activity, GroupCourse::STATUS_PUBLISHED, $user),
        ]);
    }

    public function signups(Request $request, GroupCourse $activity, GroupCourseService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $service->signupsForStaff($activity, $user)]);
    }

    public function qrcode(GroupCourse $activity, GroupCourseService $service): JsonResponse
    {
        try {
            $url = $service->qrcodeUrl($activity);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['data' => ['url' => $url]]);
    }

    public function scheme(GroupCourse $activity, GroupCourseService $service): JsonResponse
    {
        $scheme = $service->schemeUrl($activity);

        if (! $scheme) {
            return response()->json(['message' => '暂时无法生成小程序链接，请稍后重试'], 503);
        }

        return response()->json(['data' => ['scheme' => $scheme]]);
    }

    /** 活动封面上传（机构人员） */
    public function uploadCover(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'max:2048'],
        ], [
            'file.image' => '封面必须是图片',
            'file.max' => '封面不能超过 2MB',
        ]);

        $path = $request->file('file')->store('miniapp-covers', 'public');

        return response()->json([
            'data' => [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
            ],
        ], 201);
    }

    /** @return array<string, mixed> */
    private function validateActivity(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cover_path' => ['nullable', 'string', 'max:500'],
            'venue' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'signup_deadline' => ['nullable', 'date'],
            'capacity' => ['required', 'integer', 'min:1', 'max:9999'],
            'publish' => ['sometimes', 'boolean'],
        ], [
            'title.required' => '请输入拼课标题',
            'capacity.required' => '请输入报名人数上限',
            'capacity.min' => '报名人数上限至少为 1',
        ]);
    }
}
