<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\GroupCourse;
use App\Models\GroupCourseSignup;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class GroupCourseService
{
    public function __construct(private readonly WechatService $wechat) {}

    /** 机构人员看到的活动（只限自己机构） */
    public function listForStaff(User $user): array
    {
        return GroupCourse::where('organization_code', $user->organization_code)
            ->latest()
            ->get()
            ->map(fn (GroupCourse $course): array => $this->formatCourse($course))
            ->all();
    }

    public function create(array $data, User $user): array
    {
        $course = new GroupCourse();
        $course->organization_code = $user->organization_code;
        $course->creator_user_id = $user->id;
        $course->status = GroupCourse::STATUS_DRAFT;
        $course->signed_up_count = 0;
        $this->applyData($course, $data);

        if (! empty($data['publish'])) {
            $course->status = GroupCourse::STATUS_PUBLISHED;
            $course->published_at = now();
        }

        $course->save();

        return $this->formatCourse($course);
    }

    public function update(GroupCourse $course, array $data, User $user): array
    {
        $this->assertOwned($course, $user);
        $this->applyData($course, $data);

        if (! empty($data['publish']) && $course->status === GroupCourse::STATUS_DRAFT) {
            $course->status = GroupCourse::STATUS_PUBLISHED;
            $course->published_at = now();
        }

        $course->save();
        $this->clearQrcode($course);

        return $this->formatCourse($course);
    }

    public function setStatus(GroupCourse $course, string $status, User $user): array
    {
        $this->assertOwned($course, $user);

        if (! in_array($status, GroupCourse::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => '不支持的活动状态']);
        }

        $course->status = $status;

        if ($status === GroupCourse::STATUS_PUBLISHED && ! $course->published_at) {
            $course->published_at = now();
        }

        $course->save();
        $this->clearQrcode($course);

        return $this->formatCourse($course);
    }

    /** 公开活动详情：只凭 share_token 读，不依赖登录态 */
    public function detailByToken(string $shareToken): array
    {
        $course = GroupCourse::where('share_token', $shareToken)->firstOrFail();

        return $this->formatCourse($course, true);
    }

    public function signupsForStaff(GroupCourse $course, User $user): array
    {
        $this->assertOwned($course, $user);

        return $course->joinedSignups()
            ->with('customer')
            ->latest()
            ->get()
            ->map(fn (GroupCourseSignup $signup): array => $this->formatSignup($signup))
            ->all();
    }

    /**
     * 报名：事务内锁活动行，校验状态/截止时间/剩余名额，避免并发超卖。
     */
    public function signup(GroupCourse $course, Customer $customer, array $data, string $source = 'miniapp'): array
    {
        $peopleCount = max(1, (int) $data['people_count']);

        $signup = DB::transaction(function () use ($course, $customer, $data, $peopleCount, $source): GroupCourseSignup {
            /** @var GroupCourse $locked */
            $locked = GroupCourse::whereKey($course->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== GroupCourse::STATUS_PUBLISHED) {
                throw ValidationException::withMessages(['activity' => '活动当前未开放报名']);
            }

            if ($locked->signup_deadline && $locked->signup_deadline->isPast()) {
                throw ValidationException::withMessages(['activity' => '报名已截止']);
            }

            /** @var GroupCourseSignup|null $signup */
            $signup = GroupCourseSignup::where('group_course_id', $locked->id)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if ($signup && $signup->status === GroupCourseSignup::STATUS_JOINED) {
                throw ValidationException::withMessages(['activity' => '您已报名本次活动']);
            }

            if ($locked->remaining < $peopleCount) {
                throw ValidationException::withMessages(['people_count' => '剩余名额不足，请减少人数']);
            }

            $attributes = [
                'organization_code' => $locked->organization_code,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'people_count' => $peopleCount,
                'remark' => $data['remark'] ?? null,
                'status' => GroupCourseSignup::STATUS_JOINED,
                'source' => $source,
                'cancelled_at' => null,
            ];

            if ($signup) {
                $signup->fill($attributes);
            } else {
                $signup = new GroupCourseSignup(array_merge([
                    'group_course_id' => $locked->id,
                    'customer_id' => $customer->id,
                ], $attributes));
            }

            $signup->save();
            $locked->increment('signed_up_count', $peopleCount);

            return $signup;
        });

        return $this->formatSignup($signup);
    }

    public function cancel(GroupCourseSignup $signup, Customer $customer): void
    {
        if ($signup->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['signup' => '只能取消自己的报名']);
        }

        DB::transaction(function () use ($signup): void {
            /** @var GroupCourseSignup $lockedSignup */
            $lockedSignup = GroupCourseSignup::whereKey($signup->id)->lockForUpdate()->firstOrFail();

            if ($lockedSignup->status === GroupCourseSignup::STATUS_CANCELLED) {
                return;
            }

            /** @var GroupCourse $course */
            $course = GroupCourse::whereKey($lockedSignup->group_course_id)->lockForUpdate()->firstOrFail();
            $lockedSignup->status = GroupCourseSignup::STATUS_CANCELLED;
            $lockedSignup->cancelled_at = now();
            $lockedSignup->save();

            $course->signed_up_count = max(0, $course->signed_up_count - $lockedSignup->people_count);
            $course->save();
        });
    }

    /** 客户“我的拼课”：包含已取消，前端自行分组 */
    public function mySignups(Customer $customer): array
    {
        return GroupCourseSignup::where('customer_id', $customer->id)
            ->with('course')
            ->latest()
            ->get()
            ->map(function (GroupCourseSignup $signup): array {
                $course = $signup->course;

                return [
                    'id' => $signup->id,
                    'status' => $signup->status,
                    'status_label' => $signup->status_label,
                    'people_count' => $signup->people_count,
                    'created_at' => $signup->created_at?->format('Y-m-d H:i'),
                    'course' => $course ? [
                        'id' => $course->id,
                        'share_token' => $course->share_token,
                        'title' => $course->title,
                        'cover_url' => $course->cover_url,
                        'venue' => $course->venue,
                        'address' => $course->address,
                        'starts_at' => $this->formatDate($course->starts_at),
                        'status' => $course->status,
                        'status_label' => $course->status_label,
                        'capacity' => $course->capacity,
                        'signed_up_count' => $course->signed_up_count,
                    ] : null,
                ];
            })
            ->all();
    }

    /** 小程序码：本地缓存，活动更新时主动失效 */
    public function qrcodeUrl(GroupCourse $course): string
    {
        $path = 'qrcodes/'.$course->share_token.'.png';

        if (Storage::disk('public')->exists($path)) {
            $fullPath = storage_path('app/public/'.str_replace('/', DIRECTORY_SEPARATOR, $path));
            $minutes = (int) config('wechat.miniapp.qrcode_cache_minutes', 60);

            if (is_file($fullPath) && filemtime($fullPath) > time() - $minutes * 60) {
                return Storage::disk('public')->url($path);
            }
        }

        $page = (string) config('wechat.miniapp.detail_page', 'pages/activity/detail/index');
        $png = $this->wechat->getMiniAppQrcode($course->share_token, $page);
        Storage::disk('public')->put($path, $png);

        return Storage::disk('public')->url($path);
    }

    public function schemeUrl(GroupCourse $course): ?string
    {
        $page = (string) config('wechat.miniapp.detail_page', 'pages/activity/detail/index');

        return $this->wechat->miniAppScheme($course->share_token, $page);
    }

    private function applyData(GroupCourse $course, array $data): void
    {
        $fields = [
            'title',
            'description',
            'cover_path',
            'venue',
            'contact_phone',
            'address',
            'starts_at',
            'ends_at',
            'signup_deadline',
            'capacity',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $course->{$field} = $data[$field];
            }
        }
    }

    private function assertOwned(GroupCourse $course, User $user): void
    {
        if ($course->organization_code !== $user->organization_code) {
            throw ValidationException::withMessages(['activity' => '无权操作其他机构的拼课活动']);
        }
    }

    private function clearQrcode(GroupCourse $course): void
    {
        Storage::disk('public')->delete('qrcodes/'.$course->share_token.'.png');
    }

    private function formatCourse(GroupCourse $course, bool $withSignups = false): array
    {
        $data = [
            'id' => $course->id,
            'share_token' => $course->share_token,
            'title' => $course->title,
            'description' => $course->description,
            'cover_url' => $course->cover_url,
            'venue' => $course->venue,
            'contact_phone' => $course->contact_phone,
            'address' => $course->address,
            'starts_at' => $this->formatDate($course->starts_at),
            'ends_at' => $this->formatDate($course->ends_at),
            'signup_deadline' => $this->formatDate($course->signup_deadline),
            'capacity' => $course->capacity,
            'signed_up_count' => $course->signed_up_count,
            'remaining' => $course->remaining,
            'status' => $course->status,
            'status_label' => $course->status_label,
            'organization_name' => Organization::where('code', $course->organization_code)->value('name'),
            'created_at' => $course->created_at?->format('Y-m-d H:i'),
        ];

        if ($withSignups) {
            $data['signups'] = $course->joinedSignups()
                ->with('customer')
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (GroupCourseSignup $signup): array => $this->formatSignup($signup))
                ->all();
        }

        return $data;
    }

    private function formatSignup(GroupCourseSignup $signup): array
    {
        $recentCount = $signup->customer
            ? $signup->customer->signups()->where('status', GroupCourseSignup::STATUS_JOINED)->count()
            : 0;

        return [
            'id' => $signup->id,
            'name' => $signup->name,
            'masked_phone' => $signup->masked_phone,
            'people_count' => $signup->people_count,
            'recent_count' => $recentCount,
            'status' => $signup->status,
            'status_label' => $signup->status_label,
            'created_at' => $signup->created_at?->format('Y-m-d H:i'),
        ];
    }

    private function formatDate($date): ?string
    {
        return $date ? $date->format('Y-m-d H:i') : null;
    }
}
