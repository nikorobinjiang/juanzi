<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'role', 'type', 'content', 'image_path', 'extra',
    ];

    protected $casts = [
        'extra' => 'array',
    ];
}
