<?php

namespace App\Http\Controllers;

use App\Models\BookingRecord;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 约课 REST 接口（供前端直接管理，也是聊天接口的兜底）
 */
class BookingController extends Controller
{
    public function __construct(private readonly BookingService $booking) {}

    /**
     * 按周分组返回全部约课
     */
    public function index(): JsonResponse
    {
        return response()->json(['weeks' => $this->booking->weeklyForApi()]);
    }

    /**
     * 新建约课
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $result = $this->booking->create($data);

        if (! $result['success']) {
            return response()->json(['error' => $result['message']], 422);
        }

        return response()->json(['message' => $result['message'], 'booking' => $result['booking']], 201);
    }

    /**
     * 修改约课
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $this->validateData($request, false);

        $result = $this->booking->update($id, $data);

        if (! $result['success']) {
            return response()->json(['error' => $result['message']], 422);
        }

        return response()->json(['message' => $result['message'], 'booking' => $result['booking']]);
    }

    /**
     * 删除约课
     */
    public function destroy(int $id): JsonResponse
    {
        $result = $this->booking->delete($id);

        if (! $result['success']) {
            return response()->json(['error' => $result['message']], 404);
        }

        return response()->json(['message' => $result['message']]);
    }

    /**
     * 标记完成
     */
    public function complete(int $id): JsonResponse
    {
        $result = $this->booking->complete($id);

        if (! $result['success']) {
            return response()->json(['error' => $result['message']], 404);
        }

        return response()->json(['message' => $result['message'], 'booking' => $result['booking']]);
    }

    private function validateData(Request $request, bool $required = true): array
    {
        return $request->validate([
            'student_name' => $required ? 'required|string|max:50' : 'nullable|string|max:50',
            'coach_name' => $required ? 'required|string|max:50' : 'nullable|string|max:50',
            'start_at' => $required ? 'required|date_format:Y-m-d H:i' : 'nullable|date_format:Y-m-d H:i',
            'venue' => ['nullable', Rule::in((array) config('doubao.booking.venues'))],
            'remark' => 'nullable|string|max:255',
        ]);
    }
}
