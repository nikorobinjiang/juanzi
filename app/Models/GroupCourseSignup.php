<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupCourseSignup extends Model
{
    public const STATUS_JOINED = 'joined';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_JOINED => '已报名',
        self::STATUS_CANCELLED => '已取消',
    ];

    protected $fillable = [
        'group_course_id',
        'organization_code',
        'customer_id',
        'name',
        'phone',
        'people_count',
        'remark',
        'status',
        'source',
        'cancelled_at',
    ];

    protected $hidden = [
        'phone',
    ];

    protected function casts(): array
    {
        return [
            'people_count' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(GroupCourse::class, 'group_course_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function getMaskedPhoneAttribute(): string
    {
        $phone = (string) $this->phone;

        return strlen($phone) >= 7
            ? substr($phone, 0, 3).'****'.substr($phone, -4)
            : $phone;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }
}
