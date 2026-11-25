<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\GroupCourse;
use App\Models\GroupCourseSignup;
use App\Services\GroupCourseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MiniAppSignupController extends Controller
{
    /** 客户报名 */
    public function store(Request $request, string $shareToken, GroupCourseService $service): JsonResponse
    {
        $course = GroupCourse::where('share_token', $shareToken)->firstOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'regex:/^1[3-9]\d{9}$/'],
            'people_count' => ['required', 'integer', 'min:1', 'max:9999'],
            'remark' => ['nullable', 'string', 'max:500'],
        ], [
            'name.required' => '请输入联系人姓名',
            'phone.regex' => '请输入有效的 11 位手机号',
            'people_count.required' => '请输入拼课人数',
            'people_count.min' => '拼课人数至少为 1',
        ]);

        /** @var Customer $customer */
        $customer = $request->user();

        // 已登录客户可直接使用登录手机号，避免重复输入；需要换号时前端传 phone 覆盖
        if (empty($data['phone'])) {
            $data['phone'] = $customer->phone;
        }

        try {
            $signup = $service->signup($course, $customer, $data, 'miniapp');
        } catch (ValidationException $e) {
            return response()->json([
                'message' => '报名失败',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json(['data' => $signup], 201);
    }

    /** 我的拼课 */
    public function mine(Request $request, GroupCourseService $service): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return response()->json(['data' => $service->mySignups($customer)]);
    }

    public function cancel(Request $request, GroupCourseSignup $signup, GroupCourseService $service): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        try {
            $service->cancel($signup, $customer);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => '取消失败',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json(['data' => ['message' => '已取消报名']]);
    }
}
