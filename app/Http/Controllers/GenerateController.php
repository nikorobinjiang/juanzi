<?php

namespace App\Http\Controllers;

use App\Models\GeneratedImage;
use App\Services\DoubaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 独立图片生成接口（页面：/generate）
 */
class GenerateController extends Controller
{
    public function __construct(
        private readonly DoubaoService $doubao,
    ) {}

    /**
     * 接收照片 + 风格 -> 生成模板图
     *
     * 请求字段（multipart/form-data）：
     * - image: 用户照片文件（必填）
     * - style: 风格 a / b（必填）
     */
    public function generate(Request $request): JsonResponse
    {
        set_time_limit(0);

        $style = strtolower(trim((string) $request->input('style', '')));
        $hasImage = $request->hasFile('image');

        if (! $hasImage) {
            return response()->json(['error' => '请先上传一张照片'], 422);
        }

        if (! in_array($style, ['a', 'b'], true)) {
            return response()->json(['error' => '请选择图片风格（图A / 图B）'], 422);
        }

        try {
            $imagePath = $request->file('image')->store('uploads', 'public');
            $absolute = storage_path('app/public/'.$imagePath);

            $savedPath = $this->doubao->generateImage($style, $absolute);

            GeneratedImage::create([
                'style_key' => $style,
                'user_image' => $imagePath,
                'output_image' => $savedPath,
            ]);

            $styleName = config("doubao.styles.{$style}.name", '图'.$style);

            return response()->json([
                'success' => true,
                'reply' => '图片生成成功！已用【'.$styleName.'】风格生成并保存。',
                'image' => [
                    'url' => url('/storage/'.$savedPath),
                    'style' => $styleName,
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('独立图片生成失败', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json(['error' => '图片生成失败：'.$e->getMessage()], 500);
        }
    }

    /**
     * 最近生成的图片（供页面展示历史）
     */
    public function history(Request $request): JsonResponse
    {
        $items = GeneratedImage::orderBy('id', 'desc')
            ->limit(min((int) $request->input('limit', 12), 50))
            ->get()
            ->map(fn (GeneratedImage $g) => [
                'id' => $g->id,
                'style' => config("doubao.styles.{$g->style_key}.name", '图'.$g->style_key),
                'output_url' => url('/storage/'.$g->output_image),
                'created_at' => $g->created_at?->format('m-d H:i'),
            ]);

        return response()->json(['items' => $items]);
    }
}
