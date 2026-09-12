<?php

namespace App\Services;

use App\Models\Scopes\OrganizationScope;
use App\Models\Student;
use Illuminate\Support\Facades\Log;

/**
 * 学员档案建档（从 BookingService 抽出，支持 CLI / 导入命令显式指定机构）
 *
 * 建档口径与聊天约课保持一致：
 * - 课时：新建档案固定 0 节（约课不等于买课）
 * - 教练：档案里还没有教练时回填；已有教练不覆盖
 */
class StudentProfileService
{
    /**
     * 确保学员档案存在（只建档，不累加课时）
     *
     * @param  string  $name  学员姓名
     * @param  string  $coach  教练（档案里没有教练时回填）
     * @param  string  $orgCode  机构 code；为空时取当前登录态（CLI 场景需显式传入）
     * @return bool 本次是否新建了档案
     */
    public function ensure(string $name, string $coach = '', string $orgCode = ''): bool
    {
        $name = trim($name);

        if ($name === '') {
            return false;
        }

        if ($orgCode === '') {
            $orgCode = (string) (auth('web')->user()?->organization_code ?? '');
        }

        // 拿不到机构直接跳过，避免跨机构误匹配同名学员
        if ($orgCode === '') {
            return false;
        }

        try {
            // 显式按机构 + 姓名查找，脱离全局作用域（CLI / 批处理下也能稳定建档）
            /** @var Student|null $student */
            $student = Student::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_code', $orgCode)
                ->where('name', $name)
                ->first();

            $created = ! $student;

            if ($created) {
                $student = new Student([
                    'name' => $name,
                    'organization_code' => $orgCode,
                    'lessons_total' => 0,
                    'remark' => '约课自动建档',
                ]);
            }

            if ($coach !== '' && (string) $student->coach_name === '') {
                $student->coach_name = $coach;
            }

            // 已有档案且无需回填教练时不写库
            if ($created || $student->isDirty()) {
                $student->save();
            }

            return $created;
        } catch (\Throwable $e) {
            Log::warning('学员自动建档失败', [
                'student_name' => $name,
                'organization_code' => $orgCode,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
