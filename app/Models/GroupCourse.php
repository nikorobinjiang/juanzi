<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GroupCourse extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ENDED = 'ended';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_ENDED,
        self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_PUBLISHED => '报名中',
        self::STATUS_ENDED => '已结束',
        self::STATUS_CANCELLED => '已取消',
    ];

    protected $fillable = [
        'organization_code',
        'creator_user_id',
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
        'signed_up_count',
        'status',
        'share_token',
        'published_at',
    ];

    protected $hidden = [
        'creator_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'signup_deadline' => 'datetime',
            'published_at' => 'datetime',
            'capacity' => 'integer',
            'signed_up_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $course): void {
            if (empty($course->share_token)) {
                $course->share_token = Str::random(24);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_code', 'code');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function signups(): HasMany
    {
        return $this->hasMany(GroupCourseSignup::class);
    }

    public function joinedSignups(): HasMany
    {
        return $this->signups()->where('status', GroupCourseSignup::STATUS_JOINED);
    }

    public function getRemainingAttribute(): int
    {
        return max(0, $this->capacity - $this->signed_up_count);
    }

    public function getIsFullAttribute(): bool
    {
        return $this->remaining <= 0;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function getCoverUrlAttribute(): ?string
    {
        if (empty($this->cover_path)) {
            return null;
        }

        return str_starts_with($this->cover_path, 'http')
            ? $this->cover_path
            : asset('storage/'.$this->cover_path);
    }
}
