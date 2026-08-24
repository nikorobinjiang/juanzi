<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingRecord extends Model
{
    public const STATUS_BOOKED = 'booked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_BOOKED => '已约',
        self::STATUS_COMPLETED => '已完成',
        self::STATUS_CANCELLED => '已取消',
    ];

    protected $fillable = [
        'student_name', 'coach_name', 'start_at', 'end_at',
        'venue', 'status', 'remark',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
